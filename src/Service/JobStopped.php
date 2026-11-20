<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Service;

/**
 * The run was asked to stop, by a pause or an admin, see CommandRunRecorder. Ends the command, through every
 * loop that goes on after an error of a single item: those catch \RuntimeException, this is none, or let it pass.
 */
final class JobStopped extends \Exception
{
}
