<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\CommitMessage;
use PHPUnit\Framework\TestCase;

final class CommitMessageTest extends TestCase
{
    private const string MESSAGE = "[BUGFIX] Show the translated record\n\nThe overlay is applied first now.\nSee #99999 in the text, which is no footer.\n\nResolves: #105123\nResolves: #105124, #105125\nRelated: #104000\nReleases: main, 13.4\nChange-Id: I0123456789abcdef0123456789abcdef01234567";

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
}
