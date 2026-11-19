<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use App\Enum\IssueStatusEnum;
use App\Enum\ReviewStatusEnum;

/**
 * Builds the search requests for the issues and Gerrit changes of one person.
 *
 * A person is matched by email address or by name: Forge hides the email address
 * of many authors, and people often use different addresses in Forge and Gerrit.
 */
final readonly class UserWorkQuery
{
    public function __construct(
        private string $email,
        private string $name,
        private bool $includeClosed = false,
        private int $size = 100,
    ) {
    }

    /**
     * Issues the person reported or is assigned to.
     */
    public function issues(): array
    {
        return $this->request(
            [
                ['author.email' => $this->emails()],
                ['author.name' => $this->names()],
                ['assigned_to.name.keyword' => $this->names()],
            ],
            'status.name',
            IssueStatusEnum::closedLabels(),
        );
    }

    /**
     * Gerrit changes the person is the commit author of.
     */
    public function reviews(): array
    {
        return $this->request(
            [
                ['ownerEmail' => $this->emails()],
                ['owner' => $this->names()],
            ],
            'reviewstatus',
            ReviewStatusEnum::closedValues(),
        );
    }

    /**
     * @param list<array<string, list<string>>> $identityTerms field => values, one of them has to match
     * @param list<string>                      $closedStates
     */
    private function request(array $identityTerms, string $statusField, array $closedStates): array
    {
        $should = [];
        foreach ($identityTerms as $term) {
            $values = current($term);
            if ([] !== $values) {
                $should[] = ['terms' => $term];
            }
        }
        $bool = [
            'should' => $should,
            'minimum_should_match' => 1,
        ];
        if ([] === $should) {
            // Without email and name nothing can belong to the person
            $bool = ['must_not' => [['match_all' => new \stdClass()]]];
        } elseif (!$this->includeClosed) {
            $bool['must_not'] = [['terms' => [$statusField => $closedStates]]];
        }

        return [
            'query' => ['bool' => $bool],
            'sort' => ['updated_on' => 'desc'],
            'size' => $this->size,
            'track_total_hits' => true,
        ];
    }

    /**
     * Keyword fields are case-sensitive, so the address is also tried in lower case.
     *
     * @return list<string>
     */
    private function emails(): array
    {
        $email = trim($this->email);
        if ('' === $email) {
            return [];
        }

        return array_values(array_unique([$email, mb_strtolower($email)]));
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        $name = trim($this->name);

        return '' === $name ? [] : [$name];
    }
}
