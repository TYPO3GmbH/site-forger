<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Twig;

use App\Api\ActivityFilters;
use App\Enum\ReviewTypeEnum;
use App\Enum\StreamKindEnum;
use App\Enum\StreamNewcomerStateEnum;
use App\Enum\StreamSourceEnum;
use Twig\Attribute\AsTwigFunction;

/**
 * What the activity elements need to know of the channels and kinds, from their enums, so the labels, verbs and
 * icons live in one place. And the types of changes for the tags of their subjects, and the first year with
 * activity for the calendars. See
 * templates/stream/_definitions.html.twig and assets/ts/activity/definitions.ts.
 */
final class StreamTwigExtension
{
    /**
     * @return array{
     *     sources: list<array{value: string, label: string, colorKey: string}>,
     *     kinds: list<array{
     *         value: string,
     *         label: string,
     *         icon: string,
     *         colorKey: string,
     *         verbs: array<string, string>
     *     }>,
     *     newcomerStates: list<array{value: string, label: string, severity: string}>,
     *     reviewTypes: list<array{value: string, severity: string}>,
     *     firstYear: int,
     *     profileUrl: string
     * }
     */
    #[AsTwigFunction(name: 'stream_definitions')]
    public function definitions(): array
    {
        return [
            'sources' => array_map(
                static fn (StreamSourceEnum $source): array => [
                    'value' => $source->value,
                    'label' => $source->label(),
                    'colorKey' => $source->colorKey(),
                ],
                StreamSourceEnum::cases(),
            ),
            'kinds' => array_map(
                static fn (StreamKindEnum $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'icon' => $kind->icon(),
                    'colorKey' => $kind->colorKey(),
                    'verbs' => [
                        StreamSourceEnum::FORGE->value => $kind->verb(StreamSourceEnum::FORGE),
                        StreamSourceEnum::GERRIT->value => $kind->verb(StreamSourceEnum::GERRIT),
                    ],
                ],
                StreamKindEnum::cases(),
            ),
            'newcomerStates' => array_map(
                static fn (StreamNewcomerStateEnum $state): array => [
                    'value' => $state->value,
                    'label' => $state->label(),
                    'severity' => $state->severity(),
                ],
                StreamNewcomerStateEnum::cases(),
            ),
            'reviewTypes' => array_map(
                static fn (ReviewTypeEnum $type): array => ['value' => $type->value, 'severity' => $type->severity()],
                ReviewTypeEnum::cases(),
            ),
            'firstYear' => ActivityFilters::FIRST_YEAR,
            // "%s" stands for the username, see LinkTwigExtension::typo3ProfileUrl()
            'profileUrl' => LinkTwigExtension::TYPO3_PROFILE,
        ];
    }
}
