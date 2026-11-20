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
 * A label of a Gerrit change that people vote on, the value is the name in Gerrit. The votes are ReviewVoteEnum.
 */
enum ReviewLabelEnum: string
{
    case CODE_REVIEW = 'Code-Review';
    case VERIFIED = 'Verified';

    public function label(): string
    {
        return match ($this) {
            self::CODE_REVIEW => 'Code review',
            self::VERIFIED => 'Verified',
        };
    }

    public function abbreviation(): string
    {
        return match ($this) {
            self::CODE_REVIEW => 'CR',
            self::VERIFIED => 'V',
        };
    }

    /**
     * The field of the review index with the sum of the positive votes.
     */
    public function positiveField(): string
    {
        return 'positive_' . $this->fieldName() . 's';
    }

    public function negativeField(): string
    {
        return 'negative_' . $this->fieldName() . 's';
    }

    /**
     * The field of the review index with the lowest single vote.
     */
    public function lowestField(): string
    {
        return 'lowest_' . $this->fieldName() . '_vote';
    }

    public function highestField(): string
    {
        return 'highest_' . $this->fieldName() . '_vote';
    }

    /**
     * @return list<string> all fields of the review index with the votes of the label
     */
    public function fields(): array
    {
        return [$this->positiveField(), $this->negativeField(), $this->lowestField(), $this->highestField()];
    }

    private function fieldName(): string
    {
        return match ($this) {
            self::CODE_REVIEW => 'review',
            self::VERIFIED => 'verification',
        };
    }
}
