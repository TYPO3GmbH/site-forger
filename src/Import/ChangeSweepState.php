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
 * Keeps the round of the comparison of Gerrit with the index between the runs, see ChangeSweepRound. It lies in
 * var/scheduler, which the releases share. A lost state costs nothing: a new round starts at the newest change.
 */
readonly class ChangeSweepState
{
    private const string KEY = 'gerrit_sweep';

    public function __construct(
        #[Autowire(service: 'app.import.state')]
        private CacheItemPoolInterface $state,
    ) {
    }

    public function round(): ChangeSweepRound
    {
        $round = $this->state->getItem(self::KEY)->get();

        return $round instanceof ChangeSweepRound ? $round : new ChangeSweepRound();
    }

    public function save(ChangeSweepRound $round): void
    {
        $this->state->save($this->state->getItem(self::KEY)->set($round));
    }
}
