<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the imports keep between their runs beside the index: the round of the comparison with Gerrit
 * (ChangeSweepRound) and the last comparison with Forge (IssueComparison). It lies in var/scheduler, which the
 * releases share. A lost state costs nothing: the next runs compare again.
 */
readonly class ImportState
{
    /**
     * Raise it when ChangeSweepRound or IssueComparison get other fields: a stored object of the old class would
     * lack them. The state then starts again.
     */
    private const int VERSION = 1;

    private const string CHANGE_SWEEP = 'gerrit_sweep';
    private const string ISSUE_COMPARISON = 'forge_comparison';

    public function __construct(
        #[Autowire(service: 'app.import.state')]
        private CacheItemPoolInterface $state,
    ) {
    }

    public function changeSweep(): ChangeSweepRound
    {
        $round = $this->get(self::CHANGE_SWEEP);

        return $round instanceof ChangeSweepRound ? $round : new ChangeSweepRound();
    }

    public function saveChangeSweep(ChangeSweepRound $round): void
    {
        $this->save(self::CHANGE_SWEEP, $round);
    }

    /**
     * @return IssueComparison|null null before the first comparison
     */
    public function issueComparison(): ?IssueComparison
    {
        $comparison = $this->get(self::ISSUE_COMPARISON);

        return $comparison instanceof IssueComparison ? $comparison : null;
    }

    public function saveIssueComparison(IssueComparison $comparison): void
    {
        $this->save(self::ISSUE_COMPARISON, $comparison);
    }

    private function get(string $key): mixed
    {
        return $this->state->getItem(self::key($key))->get();
    }

    private function save(string $key, object $value): void
    {
        $this->state->save($this->state->getItem(self::key($key))->set($value));
    }

    private static function key(string $key): string
    {
        return $key . '_' . self::VERSION;
    }
}
