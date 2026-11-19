<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

/**
 * The result of a check on the system page, see App\Controller\SystemController.
 */
enum SystemCheckEnum: string
{
    case OK = 'ok';
    case ATTENTION = 'attention';
    case PROBLEM = 'problem';
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::OK => 'OK',
            self::ATTENTION => 'Needs attention',
            self::PROBLEM => 'Problem',
            self::UNKNOWN => 'Unknown',
        };
    }

    /**
     * The key of the result for styling: "status-pill-<severity>".
     */
    public function severity(): string
    {
        return match ($this) {
            self::OK => 'check-ok',
            self::ATTENTION => 'check-attention',
            self::PROBLEM => 'check-problem',
            self::UNKNOWN => 'check-unknown',
        };
    }

    /**
     * The worst of the results, OK without any.
     */
    public static function worst(self ...$results): self
    {
        foreach ([self::PROBLEM, self::ATTENTION, self::UNKNOWN] as $result) {
            if (in_array($result, $results, true)) {
                return $result;
            }
        }

        return self::OK;
    }
}
