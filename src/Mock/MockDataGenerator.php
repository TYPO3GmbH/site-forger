<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Mock;

use App\Enum\IssueStatusEnum;
use App\Enum\PriorityEnum;
use App\Enum\TrackerEnum;

/**
 * Builds Forge issues, Gerrit changes and interactions for local testing, shaped like the documents
 * the import writes.
 *
 * The values are the real ones of forge.typo3.org (project 27, "TYPO3 Core") and review.typo3.org
 * (Packages/TYPO3.CMS), as found in November 2026. The documents are not random: every state is
 * combined with every person, and the other properties rotate, so each value appears and every
 * person gets every state. The same input gives the same documents.
 */
final readonly class MockDataGenerator
{
    /**
     * Mock documents use the ids from here on, real Forge issues and Gerrit changes stay below.
     */
    public const int ID_OFFSET = 900000;
    public const int ID_LIMIT = 999999;
    public const string INTERACTION_PREFIX = 'mock-';

    /**
     * A selection of the 55 categories, null for issues without one.
     */
    private const array CATEGORIES = [
        'Backend User Interface', 'Backend JavaScript', 'DataHandler aka TCEmain', 'Extbase', 'Fluid',
        'Form Framework', 'FormEngine aka TCEforms', 'Frontend', 'Install Tool', 'Localization',
        'Performance', 'Security', 'Site Handling, Site Sets & Routing', 'Workspaces', null,
    ];

    /**
     * The open target versions and one of the closed ones, null for issues without one.
     */
    private const array FIXED_VERSIONS = [
        null, '15.0', 'next-patchlevel', 'Candidate for patchlevel', 'Candidate for Major Version', '15 LTS', '9 LTS',
    ];

    private const array TYPO3_VERSIONS = ['14', '13', '15', '12', '11', '', null, '10', '9', '8', '7', '6.2', '4.5'];
    private const array PHP_VERSIONS = ['', '8.2', '8.3', '8.4', '8.5', '8.1', '7.4', '7.2', ''];
    private const array COMPLEXITIES = ['', 'easy', 'medium', 'hard', 'no-brainer', 'trivial', 'nightmare', null];
    private const array SPRINT_FOCUSES = ['', null, 'On Location Sprint', 'Remote Sprint', 'Needs Decision', 'Stabilization Sprint'];
    private const array REGRESSIONS = ['', '0', '1', null];
    private const array TAGS = ['', 'accessibility', 'dev-companion', '', 'ux-decision, preview', 'workspaces', 'Accessibility, backend', ''];

    /**
     * The states a Gerrit change is generated in: status and the work in progress flag.
     * Abandoned changes can be work in progress, too.
     */
    private const array CHANGE_STATES = [
        ['NEW', false],
        ['NEW', true],
        ['MERGED', false],
        ['ABANDONED', false],
        ['ABANDONED', true],
    ];

    private const array SUBJECT_PREFIXES = [
        '[BUGFIX]', '[TASK]', '[FEATURE]', '[!!!][TASK]', '[SECURITY][BUGFIX]', '[WIP][BUGFIX]',
        '[DOCS]', '[!!!][FEATURE]', '[POC]', '[TEST]', '[RELEASE]', '',
    ];

    private const array BRANCHES = ['main', 'main', '14.3', 'main', '13.4'];

    /**
     * The "Releases:" line of the commit message, null if it has none.
     */
    private const array RELEASES = [
        'main', 'main, 14.3', 'main, 14.3, 13.4', 'main, 13.4', null, 'main, 13.4, 12.4', '13.4', '14.3, 13.4', '14.3',
    ];

    /**
     * Code-Review and Verified votes as positive reviews, negative reviews, positive and negative verifications.
     */
    private const array VOTES = [
        [0, 0, 0, 0],
        [1, 0, 1, 0],
        [2, 0, 2, 0],
        [1, 1, 1, 1],
        [0, 1, 0, 1],
        [3, 0, 3, 0],
        [0, 2, 1, 0],
    ];

    private const array FILES = [
        'typo3/sysext/backend/Classes/Controller/PageLayoutController.php',
        'typo3/sysext/core/Classes/DataHandling/DataHandler.php',
        'Build/Sources/TypeScript/backend/form-engine.ts',
        'typo3/sysext/fluid/Classes/ViewHelpers/Link/PageViewHelper.php',
        'typo3/sysext/core/Documentation/Changelog/15.0/Feature-100000-MockedFeature.rst',
        'typo3/sysext/core/Tests/Functional/DataHandling/DataHandlerTest.php',
    ];

    private const array SUBJECTS = [
        'Prevent a TypeError in the page module',
        'Respect the language of the parent record',
        'Show the workspace state in the record list',
        'Streamline the site configuration module',
        'Allow a custom sorting for file references',
        'Remove the deprecated constants of the cache framework',
        'Make the form editor keyboard accessible',
    ];

    /**
     * @param list<array{name: string, email: string, account: string}> $people the authors and owners, each gets every state
     */
    public function __construct(
        private array $people,
        private \DateTimeImmutable $now,
    ) {
        if ([] === $people) {
            throw new \InvalidArgumentException('Mock data needs at least one person.');
        }
    }

    /**
     * Issues in every Forge state for every person.
     *
     * @return list<array<string, mixed>>
     */
    public function issues(): array
    {
        $issues = [];
        $index = 0;
        foreach (IssueStatusEnum::cases() as $state) {
            foreach ($this->people as $personIndex => $person) {
                $issues[] = $this->issue($index, $state, $person, $personIndex);
                ++$index;
            }
        }

        return $issues;
    }

    /**
     * Gerrit changes in every state for every person.
     *
     * @return list<array<string, mixed>>
     */
    public function reviews(): array
    {
        $reviews = [];
        $index = 0;
        foreach (self::CHANGE_STATES as [$status, $workInProgress]) {
            foreach ($this->people as $person) {
                $reviews[] = $this->review($index, $status, $workInProgress, $person);
                ++$index;
            }
        }

        return $reviews;
    }

    /**
     * The interactions with the Gerrit changes: patch sets, comments, reviews, verifications, merges and abandons.
     *
     * @return list<array<string, mixed>>
     */
    public function interactions(): array
    {
        $interactions = [];
        foreach ($this->reviews() as $index => $review) {
            $owner = $this->personByName($review['owner']);
            $reviewer = $this->people[($index + 1) % count($this->people)];
            $created = $this->parseDate($review['created_on']);
            $add = function (string $type, array $person, int $hours) use (&$interactions, $review, $created): void {
                $interactions[] = [
                    'interaction_id' => self::INTERACTION_PREFIX . $review['id'] . '-' . count($interactions),
                    'interaction_type' => $type,
                    'interaction_source' => 'Gerrit',
                    'interaction_source_id' => $review['id'],
                    'interacted_on' => $this->formatDate($created->modify('+' . $hours . ' hours')),
                    'account' => $person['account'],
                    'fullname' => $person['name'],
                    'email' => $person['email'],
                ];
            };

            for ($patchSet = 1; $patchSet <= min($review['patchsets'], 3); ++$patchSet) {
                $add('core:review:patchset', $owner, $patchSet);
            }
            $add('core:review:comment', $reviewer, 4);
            if ($review['positive_reviews'] + $review['negative_reviews'] > 0) {
                $add('core:review:codereview', $reviewer, 5);
            }
            if ($review['positive_verifications'] + $review['negative_verifications'] > 0) {
                $add('core:review:testing', $reviewer, 6);
            }
            if ('MERGED' === $review['reviewstatus']) {
                $add('core:review:merge', $reviewer, 7);
                if ($review['patchsets'] > 1) {
                    $add('core:review:merge-author', $owner, 7);
                }
            }
            if ('ABANDONED' === $review['reviewstatus']) {
                $add('core:review:abandon', $owner, 8);
            }
        }

        return $interactions;
    }

    /**
     * @param array{name: string, email: string, account: string} $person
     *
     * @return array<string, mixed>
     */
    private function issue(int $index, IssueStatusEnum $state, array $person, int $personIndex): array
    {
        $id = self::ID_OFFSET + 1 + $index;
        $tracker = $this->pick(TrackerEnum::cases(), $index);
        $priority = $this->pick(PriorityEnum::cases(), $index);
        $category = $this->pick(self::CATEGORIES, $index);
        $fixedVersion = $this->pick(self::FIXED_VERSIONS, $index);
        $typo3Version = $this->pick(self::TYPO3_VERSIONS, $index);
        $phpVersion = $this->pick(self::PHP_VERSIONS, $index);
        $complexity = $this->pick(self::COMPLEXITIES, $index);
        $focus = $this->pick(self::SPRINT_FOCUSES, $index);
        $regression = $this->pick(self::REGRESSIONS, $index);
        $tags = $this->pick(self::TAGS, $index);
        $updated = $this->age($index);
        $created = $updated->modify('-' . (3 + $index % 200) . ' days');
        $statusName = $state->label();
        $closed = $state->isClosed();

        $issue = [
            'id' => $id,
            'project' => ['id' => 27, 'name' => 'TYPO3 Core'],
            'tracker' => ['id' => $tracker->value, 'name' => $tracker->label()],
            'status' => ['id' => $state->value, 'name' => $statusName, 'is_closed' => $closed],
            'priority' => ['id' => $priority->value, 'name' => $priority->label()],
            // Forge hides the address of many authors
            'author' => ['id' => 50000 + $personIndex, 'name' => $person['name'], 'email' => 0 === $index % 4 ? '' : $person['email']],
            'subject' => sprintf('[Mock] %s: %s', $statusName, $this->pick(self::SUBJECTS, $index)),
            'description' => "h2. Steps to reproduce\n\n# Open the page module\n# Select a *translated* page\n\nh2. Expected\n\nThe record is shown in @default@ language.\n\n<pre>\n\$GLOBALS['TCA']['pages']['ctrl']['languageField'];\n</pre>",
            'done_ratio' => $closed ? 100 : ($index % 5) * 20,
            'is_private' => false,
            'estimated_hours' => null,
            'total_estimated_hours' => null,
            'custom_fields' => [
                ['id' => 4, 'name' => 'TYPO3 Version', 'value' => $typo3Version],
                ['id' => 5, 'name' => 'PHP Version', 'value' => $phpVersion],
                ['id' => 3, 'name' => 'Tags', 'value' => $tags],
                ['id' => 8, 'name' => 'Complexity', 'value' => $complexity],
                ['id' => 15, 'name' => 'Is Regression', 'value' => $regression],
                ['id' => 18, 'name' => 'Sprint Focus', 'value' => $focus],
            ],
            'created_on' => $this->formatDate($created),
            'updated_on' => $this->formatDate($updated),
            'closed_on' => $closed ? $updated->format('Y-m-d\TH:i:s\Z') : null,
            // Derived from the custom fields, as the import does
            'typo3_version' => $typo3Version ?? '-',
            'php_version' => $phpVersion ?? '-',
            'complexity' => $complexity ?? '-',
            'isregression' => '1' === ($regression ?? '0'),
            'focus' => ['name' => $focus ?? '-'],
        ];
        if ('' !== $tags) {
            $issue['tags'] = array_map(trim(...), explode(',', $tags));
        }
        if (null !== $category) {
            $issue['category'] = ['id' => 1000 + array_search($category, self::CATEGORIES, true), 'name' => $category];
        }
        if (null !== $fixedVersion) {
            $issue['fixed_version'] = ['id' => 3600 + array_search($fixedVersion, self::FIXED_VERSIONS, true), 'name' => $fixedVersion];
        }
        // Two out of three issues are assigned, to the person itself or someone else.
        // Rotated by state, a rotation by index would give a person always the same choice.
        $stateIndex = intdiv($index, count($this->people));
        if (0 !== $stateIndex % 3) {
            $assignee = $this->people[($personIndex + $stateIndex % 2) % count($this->people)];
            $issue['assigned_to'] = ['id' => 50000 + array_search($assignee, $this->people, true), 'name' => $assignee['name']];
        }
        if (0 === $index % 7) {
            $issue['parent'] = ['id' => self::ID_OFFSET + 1];
        }
        // The import keeps the journals of issues under review: they link the Gerrit changes
        if (IssueStatusEnum::UNDER_REVIEW === $state) {
            $issue['journals'] = $this->journals($index, $updated);
        }

        return $issue;
    }

    /**
     * Journal entries with the Gerrit bot note, linking a mock change in a rotating state.
     * Issues whose changes are all merged or abandoned show up as stale.
     *
     * @return list<array<string, mixed>>
     */
    private function journals(int $index, \DateTimeImmutable $updated): array
    {
        // A change of the same person, its state rotating: open and work in progress ones keep the issue from being stale
        $people = count($this->people);
        $reviewId = self::ID_OFFSET + 1 + $index % $people + $people * ($index % count(self::CHANGE_STATES));
        $date = $updated->format('Y-m-d\TH:i:s\Z');

        return [
            [
                'id' => self::ID_OFFSET + $index * 10,
                'user' => ['id' => 36798, 'name' => 'Gerrit Code Review'],
                'notes' => sprintf("Patch set 1 for branch *main* of project *Packages/TYPO3.CMS* has been pushed to the review server.\nIt is available at https://review.typo3.org/c/Packages/TYPO3.CMS/+/%d", $reviewId),
                'created_on' => $date,
                'updated_on' => $date,
                'private_notes' => false,
                'details' => [['property' => 'attr', 'name' => 'status_id', 'old_value' => '1', 'new_value' => '8']],
            ],
        ];
    }

    /**
     * @param array{name: string, email: string, account: string} $person
     *
     * @return array<string, mixed>
     */
    private function review(int $index, string $status, bool $workInProgress, array $person): array
    {
        $id = self::ID_OFFSET + 1 + $index;
        $prefix = $this->pick(self::SUBJECT_PREFIXES, $index);
        $subject = trim($prefix . ' ' . $this->pick(self::SUBJECTS, $index + 3));
        // The import reads the tags with this pattern, "[!!!]" is not one of them
        preg_match_all('/\[([a-zA-Z0-9]*)\]/', $subject, $matches);
        $reviewTags = $matches[1];
        $releases = $this->pick(self::RELEASES, $index);
        [$positiveReviews, $negativeReviews, $positiveVerifications, $negativeVerifications] = $this->pick(self::VOTES, $index);
        if ('MERGED' === $status) {
            // Merging needs a +2 review and a +2 verification
            [$positiveReviews, $negativeReviews, $positiveVerifications, $negativeVerifications] = [2, 0, 2, 0];
        }
        $updated = $this->age($index);
        $patchSets = 1 + $index % 12;
        $files = array_slice(self::FILES, 0, 1 + $index % count(self::FILES));

        return [
            'id' => $id,
            'change_id' => 'I' . substr(hash('sha1', 'mock-change-' . $id), 0, 40),
            'reviewstatus' => $status,
            'subject' => $subject,
            'branch' => $this->pick(self::BRANCHES, $index),
            'topic' => 0 === $index % 6 ? 'mock-topic-' . ($index % 3) : 'no topic',
            // Gerrit only knows the state for open changes, the import treats a missing one as "no"
            'mergeable' => 'NEW' === $status && 0 !== $index % 3 ? 'yes' : 'no',
            'affected_files' => count($files),
            'insertions' => 3 + ($index * 37) % 900,
            'deletions' => ($index * 23) % 400,
            'reviewtags' => $reviewTags,
            'positive_reviews' => $positiveReviews,
            'negative_reviews' => $negativeReviews,
            'positive_verifications' => $positiveVerifications,
            'negative_verifications' => $negativeVerifications,
            'patchsets' => $patchSets,
            'created_on' => $this->formatDate($updated->modify('-' . (1 + $index % 90) . ' days')),
            'updated_on' => $this->formatDate($updated),
            'releases' => null === $releases ? ['omitted'] : array_map(trim(...), explode(',', $releases)),
            'owner' => $person['name'],
            'ownerEmail' => $person['email'],
            'files' => $files,
            'work_in_progress' => $workInProgress || in_array('WIP', $reviewTags, true),
        ];
    }

    /**
     * Spreads the updates from an hour to about two years ago, so recent, stale and old documents exist.
     */
    private function age(int $index): \DateTimeImmutable
    {
        $hours = [1, 5, 30, 24 * 4, 24 * 12, 24 * 35, 24 * 70, 24 * 150, 24 * 400, 24 * 700];

        return $this->now->modify('-' . $hours[$index % count($hours)] . ' hours')->modify('-' . $index . ' minutes');
    }

    /**
     * @template T
     *
     * @param list<T> $values
     *
     * @return T
     */
    private function pick(array $values, int $index): mixed
    {
        return $values[$index % count($values)];
    }

    /**
     * @return array{name: string, email: string, account: string}
     */
    private function personByName(string $name): array
    {
        foreach ($this->people as $person) {
            if ($person['name'] === $name) {
                return $person;
            }
        }

        return $this->people[0];
    }

    private function formatDate(\DateTimeImmutable $date): string
    {
        return $date->format('Y/m/d H:i:s');
    }

    private function parseDate(string $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('Y/m/d H:i:s', $date) ?: $this->now;
    }
}
