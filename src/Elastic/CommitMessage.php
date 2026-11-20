<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

/**
 * Reads the commit message of a Gerrit change: its text, and the Forge issues its footer names.
 *
 * TYPO3 Core commits end with footer lines such as "Resolves: #12345", "Related: #12346", "Releases: main, 13.4"
 * and "Change-Id: I...", one value or several separated by commas.
 */
final class CommitMessage
{
    public const string RESOLVES = 'Resolves';
    public const string RELATED = 'Related';

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
     * The body of a message apart from its footer, which the change page shows below the text, also when the text
     * is collapsed. The footer is the last paragraph if each of its lines is a "Key: value" line, like
     * "Resolves: #12345" or "Change-Id: I...".
     *
     * @return array{text: string, footer: string}
     */
    public static function parts(string $body): array
    {
        $paragraphs = preg_split('/\n[ \t]*\n/', trim((string) preg_replace('/\R/', "\n", $body))) ?: [];
        $last = (string) end($paragraphs);
        if ('' !== $last && preg_match('/\A(?:[A-Za-z][A-Za-z0-9-]*:[ \t]*\S[^\n]*(?:\n|\z))+\z/', $last)) {
            array_pop($paragraphs);

            return ['text' => trim(implode("\n\n", $paragraphs)), 'footer' => trim($last)];
        }

        return ['text' => trim(implode("\n\n", $paragraphs)), 'footer' => ''];
    }

    /**
     * The issue numbers of a footer, "Resolves" or "Related", in their order and each once.
     *
     * @return list<int>
     */
    public static function issues(string $message, string $footer): array
    {
        preg_match_all('/^' . preg_quote($footer, '/') . ':[ \t]*(?<values>.+)$/mi', $message, $matches);
        $numbers = [];
        foreach ($matches['values'] as $values) {
            preg_match_all('/#?(\d{1,7})\b/', $values, $values);
            foreach ($values[1] as $number) {
                $numbers[] = (int) $number;
            }
        }

        return array_values(array_unique($numbers));
    }
}
