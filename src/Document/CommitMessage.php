<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Search\ReviewSearch;

/**
 * Reads the commit message of a Gerrit change: its text, and what its trailers say, the Forge issues and the
 * releases. The import takes the fields of the review index from here, see fields(), the change page shows the
 * trailers apart from the text, see parts().
 *
 * TYPO3 Core commits end with trailers such as "Resolves: #12345", "Related: #12346", "Releases: main, 13.4"
 * and "Change-Id: I...", one value or several separated by commas, and a key may come several times. Only the
 * trailers count: "Resolves:" in the text names no issue. Authors write them by hand, trailers that do not follow
 * the rules never stop the import: they give what can be read of them.
 */
final class CommitMessage
{
    public const string RESOLVES = 'Resolves';
    public const string RELATED = 'Related';
    /**
     * The releases of a message without a "Releases" line, or with nothing readable in it.
     */
    public const string RELEASES_OMITTED = 'omitted';
    /**
     * Keys that only trailers have, see trailerParagraph().
     */
    private const array TRAILER_KEYS = ['resolves', 'related', 'releases', 'release', 'change-id'];
    /**
     * A trailer line, as hand written: indented, the key in any case, spaces before the colon, an empty value.
     * Not "Event::$element" of a text.
     */
    private const string TRAILER_LINE = '/^[ \t]*(?<key>[A-Za-z][A-Za-z0-9-]*)[ \t]*:(?!:)[ \t]*(?<value>.*)$/';
    /**
     * A trailer of ours with a typo instead of the colon, "Resolves. #105123", "Resolves #105123", "Releases main":
     * only with a value that starts as one.
     */
    private const string MISTYPED_TRAILER_LINE = '/^[ \t]*(?<key>resolves|related|releases?)[ \t]*[.;=]?[ \t]*(?<value>(?:#|\d|main\b|master\b).*)$/i';

    /**
     * The fields of the review index the message gives: its text without subject and trailers, which the search
     * ranks above the whole message (see ReviewSearch), and what the trailers say.
     *
     * @return array{commit_text: string, resolves: list<int>, related: list<int>, releases: list<string>}
     */
    public static function fields(string $message): array
    {
        $parsed = self::parse($message);

        return [
            'commit_text' => $parsed['text'],
            'resolves' => self::issueNumbers($parsed['trailers'], self::RESOLVES),
            'related' => self::issueNumbers($parsed['trailers'], self::RELATED),
            'releases' => self::releaseNames($parsed['trailers']),
        ];
    }

    /**
     * The parts of a message: the subject (its first line), the text, the trailer block at its end as written
     * (footer) and its trailers one by one, see trailers().
     *
     * @return array{subject: string, text: string, footer: string, trailers: list<array{key: string, value: string}>}
     */
    public static function parse(string $message): array
    {
        $lines = explode("\n", self::lines($message));

        return ['subject' => trim(array_shift($lines))] + self::split(implode("\n", $lines));
    }

    /**
     * The message without its first line, the subject, which the change shows as its title anyway.
     */
    public static function body(string $message): string
    {
        $lines = preg_split('/\R/', $message) ?: [];
        array_shift($lines);

        return trim(implode("\n", $lines));
    }

    /**
     * The body of a message apart from its trailers, which the change page shows below the text, also when the text
     * is collapsed, see trailers().
     *
     * @return array{text: string, footer: string}
     */
    public static function parts(string $body): array
    {
        $split = self::split($body);

        return ['text' => $split['text'], 'footer' => $split['footer']];
    }

    /**
     * The trailers of a message, in their order: the key as written, the value with its continuation lines.
     *
     * The trailers are the paragraphs at the end that start with a trailer, with a key such as "Resolves",
     * "Change-Id" or "Reviewed-on" (a hyphen). Each line is a trailer or, indented, goes on with the one before.
     * A blank line between them, as Gerrit adds "Reviewed-on" after the merge, keeps them together. Hand written
     * trailers break the rules: in the paragraph with the "Change-Id" a line that is no trailer goes on with the
     * one before, "Resolves. #1" with a typo is one. The subject has none.
     *
     * @return list<array{key: string, value: string}>
     */
    public static function trailers(string $message): array
    {
        return self::parse($message)['trailers'];
    }

    /**
     * The issue numbers of the trailers of a key, "Resolves" or "Related", of all of them, in their order and
     * each once.
     *
     * @return list<int>
     */
    public static function issues(string $message, string $key): array
    {
        return self::issueNumbers(self::trailers($message), $key);
    }

    /**
     * @param list<array{key: string, value: string}> $trailers
     *
     * @return list<int>
     */
    private static function issueNumbers(array $trailers, string $key): array
    {
        $numbers = [];
        foreach (self::values($trailers, [$key]) as $value) {
            // A link elsewhere than Forge, like to a change on review.typo3.org, names no issue
            $value = (string) preg_replace('~\bhttps?://(?!forge\.typo3\.org/)\S*~i', ' ', $value);
            // A number on its own, "#105123", "105123" or ".../issues/105123": not the 3 of "typo3.org", not a part
            // of a longer number, a date or a version
            preg_match_all('~(?:^|[\s,;(/#])#?(\d{1,7})(?=$|[\s,;)]|\.(?!\d))~', $value, $matches);
            foreach ($matches[1] as $number) {
                $numbers[] = (int) $number;
            }
        }

        return array_values(array_unique($numbers));
    }

    /**
     * The releases the trailers "Releases" name, of all of them, lower case, in their order and each once;
     * ["omitted"] without one.
     *
     * "master" is "main", "Release:" counts as well. An entry that is not a plain name like "main", "13.4" or
     * "7.6.x" is free text, like "13.4(" or "12.4? – mktime is wrong there as well)": of it only the versions it
     * names count, "???" names none.
     *
     * @return list<string>
     */
    public static function releases(string $message): array
    {
        return self::releaseNames(self::trailers($message));
    }

    /**
     * @param list<array{key: string, value: string}> $trailers
     *
     * @return list<string>
     */
    private static function releaseNames(array $trailers): array
    {
        $releases = [];
        foreach (self::values($trailers, ['Releases', 'Release']) as $value) {
            $value = strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
            // Lines of old commits: "master. 4.7. 4.6", "7,6", "4.5 LTS"
            $value = str_replace(['master.', 'master', '4.7.', '7,6', '4.5 lts'], ['master,', 'main', '4.7,', '7.6', '4.5'], $value);
            foreach (array_map(trim(...), explode(',', $value)) as $release) {
                // "v13.4"
                $release = (string) preg_replace('/^v(?=\d)/', '', $release);
                if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $release)) {
                    $releases[] = rtrim($release, '.');
                    continue;
                }
                preg_match_all('/\bmain\b|(?<![\d.])\d+\.\d+/', $release, $versions);
                array_push($releases, ...$versions[0]);
            }
        }
        $releases = array_values(array_unique($releases));

        return [] === $releases ? [self::RELEASES_OMITTED] : $releases;
    }

    /**
     * The text and the trailers of a body, see trailers().
     *
     * @return array{text: string, footer: string, trailers: list<array{key: string, value: string}>}
     */
    private static function split(string $body): array
    {
        $paragraphs = preg_split('/\n[ \t]*\n/', trim(self::lines($body))) ?: [];
        $footer = [];
        $trailers = [];
        while ([] !== $paragraphs && '' !== ($paragraph = trim((string) end($paragraphs)))) {
            $parsed = self::trailerParagraph($paragraph);
            if (null === $parsed) {
                break;
            }
            array_pop($paragraphs);
            array_unshift($footer, $paragraph);
            $trailers = [...$parsed, ...$trailers];
        }

        return ['text' => trim(implode("\n\n", $paragraphs)), 'footer' => implode("\n\n", $footer), 'trailers' => $trailers];
    }

    /**
     * The trailers of a paragraph, null if it is none, see trailers().
     *
     * @return list<array{key: string, value: string}>|null
     */
    private static function trailerParagraph(string $paragraph): ?array
    {
        $trailers = [];
        $loose = false;
        foreach (explode("\n", $paragraph) as $line) {
            if (preg_match(self::TRAILER_LINE, $line, $match) || preg_match(self::MISTYPED_TRAILER_LINE, $line, $match)) {
                $trailers[] = ['key' => $match['key'], 'value' => trim($match['value'])];
                continue;
            }
            if ([] === $trailers) {
                return null;
            }
            $loose = $loose || !preg_match('/^[ \t]/', $line);
            $previous = array_pop($trailers);
            $trailers[] = ['key' => $previous['key'], 'value' => $previous['value'] . ' ' . trim($line)];
        }
        $keys = array_map(static fn (array $trailer): string => strtolower($trailer['key']), $trailers);
        // Lines that are no trailers only with the Change-Id, which Gerrit asks every author for
        if ($loose ? !in_array('change-id', $keys, true) : [] === array_intersect($keys, self::TRAILER_KEYS) && [] === array_filter($keys, static fn (string $key): bool => str_contains($key, '-'))) {
            return null;
        }

        return $trailers;
    }

    /**
     * The values of the trailers of some keys, in any case.
     *
     * @param list<array{key: string, value: string}> $trailers
     * @param list<string>                            $keys
     *
     * @return list<string>
     */
    private static function values(array $trailers, array $keys): array
    {
        $keys = array_map(strtolower(...), $keys);

        return array_values(array_map(
            static fn (array $trailer): string => $trailer['value'],
            array_filter($trailers, static fn (array $trailer): bool => in_array(strtolower($trailer['key']), $keys, true)),
        ));
    }

    /**
     * The message with "\n" between its lines, whatever the client used.
     */
    private static function lines(string $message): string
    {
        return (string) preg_replace('/\r\n?/', "\n", $message);
    }
}
