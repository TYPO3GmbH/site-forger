<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Documents imported before a field was added must still render: an index is filled again by a full import only.
 * The templates run with strict variables here, so a field they read without a default fails the test.
 */
final class CardsWithoutNewFieldsTest extends KernelTestCase
{
    /**
     * A change as the import stored it before "ownerEmail" and the tag "!!!".
     */
    private const array OLD_CHANGE = [
        'id' => 12345,
        'change_id' => 'I0123456789abcdef',
        'reviewstatus' => 'NEW',
        'subject' => '[!!!][FEATURE] Remove the old API',
        'branch' => 'main',
        'topic' => 'no topic',
        'releases' => ['main'],
        'mergeable' => 'yes',
        'affected_files' => 1,
        'patchsets' => 1,
        'positive_reviews' => 0,
        'negative_reviews' => 0,
        'positive_verifications' => 0,
        'negative_verifications' => 0,
        'created_on' => '2026/11/01 10:00:00',
        'updated_on' => '2026/11/01 10:00:00',
        'owner' => 'Benni Mack',
        'work_in_progress' => false,
    ];

    /**
     * An issue as the import stored it before the email of the assignee.
     */
    private const array OLD_ISSUE = [
        'id' => 91089,
        'subject' => 'Something is broken',
        'description' => 'Steps to reproduce',
        'tracker' => ['id' => 1, 'name' => 'Bug'],
        'status' => ['id' => 1, 'name' => 'New'],
        'author' => ['id' => 1, 'name' => 'Benni Mack'],
        'assigned_to' => ['id' => 2, 'name' => 'Benjamin Kott'],
        'custom_fields' => [],
        'created_on' => '2026/11/01 10:00:00',
        'updated_on' => '2026/11/01 10:00:00',
    ];

    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
        self::assertTrue($this->twig->isStrictVariables());
    }

    public function testChangeWithoutNewFields(): void
    {
        $change = self::OLD_CHANGE;
        unset($change['reviewtags']);

        $card = $this->twig->render('_partials/review_card.html.twig', ['card' => $change, 'mode' => 'default']);
        $full = $this->twig->render('_partials/review.html.twig', ['review' => ['_source' => $change]]);

        foreach ([$card, $full] as $html) {
            self::assertStringContainsString('Benni Mack', $html);
            self::assertStringNotContainsString('class="avatar"', $html);
            self::assertStringNotContainsString('review-breaking', $html);
        }
    }

    public function testChangeWithTagsAndEmail(): void
    {
        $change = self::OLD_CHANGE + ['reviewtags' => ['!!!', 'FEATURE'], 'ownerEmail' => 'benni@example.org'];

        $html = $this->twig->render('_partials/review_card.html.twig', ['card' => $change, 'mode' => 'default']);

        self::assertStringContainsString('review-type-feature', $html);
        self::assertStringContainsString('review-breaking', $html);
        self::assertStringContainsString('class="avatar"', $html);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function oldIssues(): iterable
    {
        yield 'assignee without email' => [self::OLD_ISSUE];
        $issue = self::OLD_ISSUE;
        unset($issue['assigned_to']);
        yield 'no assignee' => [$issue];
        $issue = self::OLD_ISSUE;
        unset($issue['author']['email']);
        yield 'author without email' => [$issue];
    }

    /**
     * @param array<string, mixed> $issue
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('oldIssues')]
    public function testIssueWithoutNewFields(array $issue): void
    {
        $card = $this->twig->render('_partials/issue_card.html.twig', ['card' => $issue]);
        $listed = $this->twig->render('_partials/issue.html.twig', ['issue' => ['_source' => $issue]]);

        foreach ([$card, $listed] as $html) {
            self::assertStringContainsString('Benni Mack', $html);
            self::assertSame(isset($issue['assigned_to']), str_contains($html, 'Benjamin Kott'));
        }
    }

    public function testIssueWithAssigneeEmailShowsTheAvatar(): void
    {
        $issue = self::OLD_ISSUE;
        $issue['assigned_to']['email'] = 'benjamin@example.org';

        $html = $this->twig->render('_partials/issue.html.twig', ['issue' => ['_source' => $issue]]);

        self::assertStringContainsString(md5('benjamin@example.org'), $html);
    }
}
