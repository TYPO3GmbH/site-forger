<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Document;

use App\Document\CommitMessage;
use App\Document\Issue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommitMessageTest extends TestCase
{
    private const string MESSAGE = "[BUGFIX] Show the translated record\n\nThe overlay is applied first now.\nSee #99999 in the text, which is no footer.\n\nResolves: #105123\nResolves: #105124, #105125\nRelated: #104000\nReleases: main, 13.4\nChange-Id: I0123456789abcdef0123456789abcdef01234567";

    /**
     * Whole messages and their parts, see CommitMessage::parse(): how the import splits a message.
     *
     * @return iterable<string, array{string, array{subject: string, text: string, footer: string, trailers: list<array{key: string, value: string}>}}>
     */
    public static function messages(): iterable
    {
        yield 'as the rules want it' => [
            "[BUGFIX] Show the translated record\n\nThe overlay is applied first now.\n\nThe second paragraph.\n\nResolves: #105123\nRelated: #104000\nReleases: main, 13.4\nChange-Id: I0123",
            [
                'subject' => '[BUGFIX] Show the translated record',
                'text' => "The overlay is applied first now.\n\nThe second paragraph.",
                'footer' => "Resolves: #105123\nRelated: #104000\nReleases: main, 13.4\nChange-Id: I0123",
                'trailers' => [
                    ['key' => 'Resolves', 'value' => '#105123'],
                    ['key' => 'Related', 'value' => '#104000'],
                    ['key' => 'Releases', 'value' => 'main, 13.4'],
                    ['key' => 'Change-Id', 'value' => 'I0123'],
                ],
            ],
        ];
        yield 'merged, with the trailers Gerrit adds' => [
            "[TASK] Raise the version\n\nResolves: #1\nReleases: main\nChange-Id: I0123\n\nReviewed-on: https://review.typo3.org/c/Packages/TYPO3.CMS/+/97787\nTested-by: core-ci <typo3@b13.com>\nReviewed-by: Jane Doe <jane@example.org>",
            [
                'subject' => '[TASK] Raise the version',
                'text' => '',
                'footer' => "Resolves: #1\nReleases: main\nChange-Id: I0123\n\nReviewed-on: https://review.typo3.org/c/Packages/TYPO3.CMS/+/97787\nTested-by: core-ci <typo3@b13.com>\nReviewed-by: Jane Doe <jane@example.org>",
                'trailers' => [
                    ['key' => 'Resolves', 'value' => '#1'],
                    ['key' => 'Releases', 'value' => 'main'],
                    ['key' => 'Change-Id', 'value' => 'I0123'],
                    ['key' => 'Reviewed-on', 'value' => 'https://review.typo3.org/c/Packages/TYPO3.CMS/+/97787'],
                    ['key' => 'Tested-by', 'value' => 'core-ci <typo3@b13.com>'],
                    ['key' => 'Reviewed-by', 'value' => 'Jane Doe <jane@example.org>'],
                ],
            ],
        ];
        // The message the import failed on
        yield 'hand written, free text in a trailer' => [
            "[WIP][BUGFIX] Generate timezone independent unix max timestamps\n\nThe returned value of `mktime` is dependent on the\ndate.timezone ini settings.\n\nReleases: main, 13.4(, 12.4? – mktime is wrong there as well)\nResolves: #\nChange-Id: Ice1fead5a364237a1a6c1d81623e0e6497fef8ec",
            [
                'subject' => '[WIP][BUGFIX] Generate timezone independent unix max timestamps',
                'text' => "The returned value of `mktime` is dependent on the\ndate.timezone ini settings.",
                'footer' => "Releases: main, 13.4(, 12.4? – mktime is wrong there as well)\nResolves: #\nChange-Id: Ice1fead5a364237a1a6c1d81623e0e6497fef8ec",
                'trailers' => [
                    ['key' => 'Releases', 'value' => 'main, 13.4(, 12.4? – mktime is wrong there as well)'],
                    ['key' => 'Resolves', 'value' => '#'],
                    ['key' => 'Change-Id', 'value' => 'Ice1fead5a364237a1a6c1d81623e0e6497fef8ec'],
                ],
            ],
        ];
        yield 'hand written, a typo and a wrapped line' => [
            "[BUGFIX] Fix\n\nText with Event::\$element.\n\nResolves. #105123\nReleases: main, 14.3,\n13.4\nChange-Id: I0123",
            [
                'subject' => '[BUGFIX] Fix',
                'text' => 'Text with Event::$element.',
                'footer' => "Resolves. #105123\nReleases: main, 14.3,\n13.4\nChange-Id: I0123",
                'trailers' => [
                    ['key' => 'Resolves', 'value' => '#105123'],
                    ['key' => 'Releases', 'value' => 'main, 14.3, 13.4'],
                    ['key' => 'Change-Id', 'value' => 'I0123'],
                ],
            ],
        ];
        // Lines that are no trailers only beside the Change-Id: without it the paragraph is text
        yield 'hand written, a wrapped line and a blank line before the Change-Id' => [
            "[BUGFIX] Fix\n\nText.\n\nResolves: #105123\nReleases: main, 14.3,\n13.4\n\nChange-Id: I0123",
            [
                'subject' => '[BUGFIX] Fix',
                'text' => "Text.\n\nResolves: #105123\nReleases: main, 14.3,\n13.4",
                'footer' => 'Change-Id: I0123',
                'trailers' => [['key' => 'Change-Id', 'value' => 'I0123']],
            ],
        ];
        yield 'an indented continuation and a blank line before the Change-Id' => [
            "[BUGFIX] Fix\n\nText.\n\nResolves: #105123\nReleases: main, 14.3,\n  13.4\n\nChange-Id: I0123",
            [
                'subject' => '[BUGFIX] Fix',
                'text' => 'Text.',
                'footer' => "Resolves: #105123\nReleases: main, 14.3,\n  13.4\n\nChange-Id: I0123",
                'trailers' => [
                    ['key' => 'Resolves', 'value' => '#105123'],
                    ['key' => 'Releases', 'value' => 'main, 14.3, 13.4'],
                    ['key' => 'Change-Id', 'value' => 'I0123'],
                ],
            ],
        ];
        yield 'a last paragraph of text' => [
            "[DOCS] Explain\n\nNote: this is\nthe end of the text.",
            ['subject' => '[DOCS] Explain', 'text' => "Note: this is\nthe end of the text.", 'footer' => '', 'trailers' => []],
        ];
        yield 'with the line breaks of Windows' => [
            "[TASK] X\r\n\r\nText.\r\n\r\nResolves: #1\r\nChange-Id: I0123\r\n",
            ['subject' => '[TASK] X', 'text' => 'Text.', 'footer' => "Resolves: #1\nChange-Id: I0123", 'trailers' => [['key' => 'Resolves', 'value' => '#1'], ['key' => 'Change-Id', 'value' => 'I0123']]],
        ];
        yield 'a subject only' => [
            '[TASK] Only a subject',
            ['subject' => '[TASK] Only a subject', 'text' => '', 'footer' => '', 'trailers' => []],
        ];
        yield 'empty' => ['', ['subject' => '', 'text' => '', 'footer' => '', 'trailers' => []]];
    }

    /**
     * @param array{subject: string, text: string, footer: string, trailers: list<array{key: string, value: string}>} $expected
     */
    #[DataProvider('messages')]
    public function testTheParts(string $message, array $expected): void
    {
        self::assertSame($expected, CommitMessage::parse($message));
    }

    public function testThePatchOfAnIssueStartsWithItsTypeSubjectAndTrailers(): void
    {
        $issue = static fn (int $tracker): Issue => Issue::fromDocument([
            'id' => 91089,
            'subject' => ' The cache is not cleared ',
            'tracker' => ['id' => $tracker],
        ]);

        self::assertSame(
            "[BUGFIX] The cache is not cleared\n\nResolves: #91089\nReleases: main",
            CommitMessage::forIssue($issue(1)),
        );
        self::assertStringStartsWith('[FEATURE] ', CommitMessage::forIssue($issue(2)));
        self::assertStringStartsWith('[TASK] ', CommitMessage::forIssue($issue(4)));
        self::assertStringStartsWith('[TASK] ', CommitMessage::forIssue(Issue::fromDocument(['id' => 1])));
    }

    public function testTheFieldsOfTheIndexOfTheMessageTheImportFailedOn(): void
    {
        $message = "[WIP][BUGFIX] Generate timezone independent unix max timestamps\n\nThe returned value of `mktime` is dependent on the\ndate.timezone ini settings.\n\nReleases: main, 13.4(, 12.4? – mktime is wrong there as well)\nResolves: #\nChange-Id: Ice1fead5a364237a1a6c1d81623e0e6497fef8ec";

        self::assertSame([
            'commit_text' => "The returned value of `mktime` is dependent on the\ndate.timezone ini settings.",
            'resolves' => [],
            'related' => [],
            'releases' => ['main', '13.4', '12.4'],
        ], CommitMessage::fields($message));
    }

    public function testTheBodyLeavesOutTheSubject(): void
    {
        self::assertStringStartsWith('The overlay is applied first now.', CommitMessage::body(self::MESSAGE));
        self::assertStringEndsWith('Change-Id: I0123456789abcdef0123456789abcdef01234567', CommitMessage::body(self::MESSAGE));
        self::assertSame('', CommitMessage::body('[TASK] Only a subject'));
        self::assertSame('', CommitMessage::body(''));
    }

    public function testTheIssuesOfTheFooters(): void
    {
        self::assertSame([105123, 105124, 105125], CommitMessage::issues(self::MESSAGE, CommitMessage::RESOLVES));
        self::assertSame([104000], CommitMessage::issues(self::MESSAGE, CommitMessage::RELATED));
    }

    public function testFootersInAnyCaseAndWithoutHash(): void
    {
        self::assertSame([12345, 12346], CommitMessage::issues("Subject\n\nresolves: 12345\nRESOLVES:#12346\nResolves: #12345", CommitMessage::RESOLVES));
        self::assertSame([], CommitMessage::issues('Subject without footers', CommitMessage::RESOLVES));
    }

    public function testThePartsKeepTheFooterApart(): void
    {
        $parts = CommitMessage::parts(CommitMessage::body(self::MESSAGE));

        self::assertSame("The overlay is applied first now.\nSee #99999 in the text, which is no footer.", $parts['text']);
        self::assertStringStartsWith('Resolves: #105123', $parts['footer']);
        self::assertStringEndsWith('Change-Id: I0123456789abcdef0123456789abcdef01234567', $parts['footer']);
    }

    public function testALastParagraphOfTextIsNoFooter(): void
    {
        self::assertSame(['text' => "First.\n\nThe last: no footer, as this line is text.\nAnd so is this one.", 'footer' => ''], CommitMessage::parts("First.\n\nThe last: no footer, as this line is text.\nAnd so is this one."));
        // Only a footer, with the line breaks of Windows
        self::assertSame(['text' => '', 'footer' => "Resolves: #1\nReleases: main"], CommitMessage::parts("Resolves: #1\r\nReleases: main"));
        self::assertSame(['text' => '', 'footer' => ''], CommitMessage::parts(''));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function releaseLines(): iterable
    {
        // Lines of changes on review.typo3.org
        yield 'one' => ['Releases: main', ['main']];
        yield 'several' => ['Releases: main, 14.3, 13.4, 12.4', ['main', '14.3', '13.4', '12.4']];
        yield 'in another order' => ['Releases: 14.3, main', ['14.3', 'main']];
        yield 'without space' => ['Releases: main,14.3', ['main', '14.3']];
        yield 'an ELTS branch' => ['Releases: 12.5, 11.5', ['12.5', '11.5']];
        yield 'a major only' => ['Releases: main, 13', ['main', '13']];
        yield 'master' => ['Releases: master, 11.3, 10.4, 9.5', ['main', '11.3', '10.4', '9.5']];
        yield 'unknown' => ['Releases: main, ???', ['main']];
        yield 'free text' => ['Releases: main, 13.4(, 12.4? – mktime is wrong there as well)', ['main', '13.4', '12.4']];
        // Lines of old commits
        yield 'singular' => ['Release: 7.6', ['7.6']];
        yield 'comma in the version' => ['Releases: master, 7,6', ['main', '7.6']];
        yield 'dots between' => ['Releases: master. 4.7. 4.6', ['main', '4.7', '4.6']];
        yield 'LTS' => ['Releases: 4.5 LTS', ['4.5']];
        yield 'a branch name' => ['Releases: 7.6.x', ['7.6.x']];
        // Broken lines
        yield 'twice in the line' => ['Releases: main, 13.4, main', ['main', '13.4']];
        yield 'empty entries' => ['Releases: main, , ', ['main']];
        yield 'empty' => ["Releases:\nResolves: #1", ['omitted']];
        yield 'nothing readable' => ['Releases: ???', ['omitted']];
        yield 'none' => ['Resolves: #1', ['omitted']];
        // Hand written
        yield 'lower case key, upper case value' => ['releases: Main, 13.4', ['main', '13.4']];
        yield 'no space after the colon' => ['Releases:main,13.4', ['main', '13.4']];
        yield 'space before the colon' => ['Releases : main, 13.4', ['main', '13.4']];
        yield 'indented' => ['  Releases: main, 13.4', ['main', '13.4']];
        yield 'tabs' => ["Releases:\tmain,\t13.4", ['main', '13.4']];
        yield 'non-breaking spaces' => ["Releases:\u{a0}main,\u{a0}13.4", ['main', '13.4']];
        yield 'semicolons' => ['Releases: main; 13.4', ['main', '13.4']];
        yield 'slash' => ['Releases: main / 13.4', ['main', '13.4']];
        yield 'and' => ['Releases: main and 13.4', ['main', '13.4']];
        yield 'spaces only' => ['Releases: main 13.4 12.4', ['main', '13.4', '12.4']];
        yield 'v prefix' => ['Releases: v13.4, TYPO3 12.4', ['13.4', '12.4']];
        yield 'LTS after the version' => ['Releases: main, 13.4 LTS', ['main', '13.4']];
        yield 'trailing dot' => ['Releases: main, 13.4.', ['main', '13.4']];
        yield 'patch version' => ['Releases: main, 13.4.1', ['main', '13.4.1']];
        yield 'patch version in text' => ['Releases: main, 13.4.1 maybe', ['main', '13.4']];
        yield 'markdown' => ['Releases: `main`, *13.4*', ['main', '13.4']];
        yield 'quoted' => ["Releases: \"main\", '13.4'", ['main', '13.4']];
        yield 'brackets' => ['Releases: [main, 13.4]', ['main', '13.4']];
        yield 'comment' => ['Releases: main, 13.4 # maybe 12.4', ['main', '13.4', '12.4']];
        yield 'wrapped after a comma' => ["Releases: main, 13.4,\n  12.4", ['main', '13.4', '12.4']];
        yield 'a comma before the next footer' => ["Releases: main,\nResolves: #1", ['main']];
        yield 'invalid UTF-8' => ["Releases: main, \xff\xfe13.4", ['main', '13.4']];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["[TASK] X\n\nReleases: main, 13.4\nResolves: #123\n"];
        yield 'CRLF' => ["[TASK] X\r\n\r\nReleases: main, 13.4\r\nResolves: #123\r\n"];
        yield 'CR' => ["[TASK] X\r\rReleases: main, 13.4\rResolves: #123\r"];
    }

    #[DataProvider('lineEndings')]
    public function testAnyLineEnding(string $message): void
    {
        self::assertSame(['commit_text' => '', 'resolves' => [123], 'related' => [], 'releases' => ['main', '13.4']], CommitMessage::fields($message));
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function resolvesLines(): iterable
    {
        yield 'several' => ['Resolves: #105123, #105124', [105123, 105124]];
        yield 'without hash' => ['Resolves: 105123', [105123]];
        yield 'semicolon' => ['Resolves: #105123; #105124', [105123, 105124]];
        yield 'spaces only' => ['Resolves: #9 #10', [9, 10]];
        yield 'link to Forge' => ['Resolves: https://forge.typo3.org/issues/105123', [105123]];
        yield 'word before' => ['Resolves: forge #105123', [105123]];
        yield 'in brackets' => ['Resolves: (#105123)', [105123]];
        yield 'end of a sentence' => ['Resolves: #105123.', [105123]];
        yield 'space before the colon' => ['Resolves : #105123', [105123]];
        yield 'indented' => ['  Resolves: #105123', [105123]];
        yield 'hash only' => ['Resolves: #', []];
        yield 'empty' => ['Resolves:', []];
        yield 'link to a change' => ['Resolves: https://review.typo3.org/c/Packages/TYPO3.CMS/+/92499', []];
        yield 'Change-Id' => ['Resolves: Ia68d47b99dafd287b60dbcbbc6df5465e02c7844', []];
        yield 'too long' => ['Resolves: #12345678', []];
        yield 'date' => ['Resolves: 2024-11-20', []];
        yield 'version' => ['Resolves: 13.4', []];
        yield 'without colon' => ['Resolves #105123', [105123]];
        yield 'dot instead of colon' => ['Resolves. #105123', [105123]];
        yield 'dot instead of colon, no number' => ["Resolves. #\nResolves: #105123", [105123]];
        yield 'other key' => ['Fixes: #105123', []];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('resolvesLines')]
    public function testIssuesOfHandWrittenLines(string $footer, array $expected): void
    {
        self::assertSame($expected, CommitMessage::issues("[BUGFIX] Repair the cache\n\nThe text.\n\n" . $footer . "\nChange-Id: I0123", CommitMessage::RESOLVES));
    }

    public function testNothingStopsTheReading(): void
    {
        foreach (['', '[TASK] Only a subject', "\n\n\n", 'Releases:', 'Resolves:', "\xff\xfe", str_repeat('Releases: main, ', 2000)] as $message) {
            $fields = CommitMessage::fields($message);
            self::assertNotSame([], $fields['releases']);
        }
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('releaseLines')]
    public function testReleases(string $footer, array $expected): void
    {
        self::assertSame($expected, CommitMessage::releases("[BUGFIX] Repair the cache\n\nThe text.\n\n" . $footer . "\nChange-Id: I0123"));
    }

    public function testEveryTrailerOfAKeyCounts(): void
    {
        $message = "[BUGFIX] Fix\n\nText.\n\nResolves: #1\nRelated: #10\nReleases: main\nResolves: #2, #3\nRelated: #11\nReleases: 13.4, main\nChange-Id: I0123";

        self::assertSame(['commit_text' => 'Text.', 'resolves' => [1, 2, 3], 'related' => [10, 11], 'releases' => ['main', '13.4']], CommitMessage::fields($message));
    }

    public function testOnlyTheTrailersCount(): void
    {
        $message = "[BUGFIX] Resolves: #7 in the subject\n\nResolves: #8 at the start of the text\nand Releases: 12.4 within it.\n\nReleases: 11.5\nas a line of the text, which goes on.\n\nResolves: #1\nReleases: main\nChange-Id: I0123";

        self::assertSame([
            'commit_text' => "Resolves: #8 at the start of the text\nand Releases: 12.4 within it.\n\nReleases: 11.5\nas a line of the text, which goes on.",
            'resolves' => [1],
            'related' => [],
            'releases' => ['main'],
        ], CommitMessage::fields($message));
    }

    public function testTrailersSplitByABlankLineStayTogether(): void
    {
        $message = "[BUGFIX] Fix\n\nText.\n\nResolves: #1\nReleases: main, 13.4\n\nChange-Id: I0123";

        self::assertSame(['commit_text' => 'Text.', 'resolves' => [1], 'related' => [], 'releases' => ['main', '13.4']], CommitMessage::fields($message));
        self::assertSame(['text' => 'Text.', 'footer' => "Resolves: #1\nReleases: main, 13.4\n\nChange-Id: I0123"], CommitMessage::parts(CommitMessage::body($message)));
    }

    public function testTheTrailersWithTheirContinuationLines(): void
    {
        $message = "[BUGFIX] Fix\n\nText.\n\nResolves: #1,\n  #2\nReleases: main, 13.4,\n12.4\nChange-Id: I0123";

        self::assertSame([
            ['key' => 'Resolves', 'value' => '#1, #2'],
            ['key' => 'Releases', 'value' => 'main, 13.4, 12.4'],
            ['key' => 'Change-Id', 'value' => 'I0123'],
        ], CommitMessage::trailers($message));
        // A last paragraph of "Key: value" text without a trailer key stays text
        self::assertSame([], CommitMessage::trailers("[TASK] Fix\n\nText.\n\nNote: this is\nthe end of the text."));
        self::assertSame([], CommitMessage::trailers('Resolves: #1 in the subject only'));
        // "::" of a text, not a trailer
        self::assertSame(['text' => "Text.\n\nEvent::\$element may now hold a page.", 'footer' => "Resolves: #1\nChange-Id: I0123"], CommitMessage::parts("Text.\n\nEvent::\$element may now hold a page.\n\nResolves: #1\nChange-Id: I0123"));
        // A typo keeps the other trailers
        self::assertSame([77375, 106386], CommitMessage::issues("[BUGFIX] Fix\n\nText.\n\nResolves. #\nRelated: #77375\nRelated: #106386\nChange-Id: I0123", CommitMessage::RELATED));
        // Gerrit's trailers of the merge after a blank line
        self::assertSame([1], CommitMessage::issues("[BUGFIX] Fix\n\nText.\n\nResolves: #1\nChange-Id: I0123\n\nReviewed-on: https://review.typo3.org/c/Packages/TYPO3.CMS/+/97787\nTested-by: core-ci <typo3@b13.com>", CommitMessage::RESOLVES));
    }

    public function testFieldsOfTheIndex(): void
    {
        $fields = CommitMessage::fields(self::MESSAGE);

        self::assertSame([105123, 105124, 105125], $fields['resolves']);
        self::assertSame([104000], $fields['related']);
        self::assertSame(CommitMessage::releases(self::MESSAGE), $fields['releases']);
    }
}
