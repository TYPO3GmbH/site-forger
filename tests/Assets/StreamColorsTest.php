<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Assets;

use App\Enum\StreamFigureEnum;
use App\Enum\StreamKindEnum;
use App\Enum\StreamSourceEnum;
use PHPUnit\Framework\TestCase;

/**
 * The colors of the activity are custom properties of the stylesheet, the elements name them by key.
 */
final class StreamColorsTest extends TestCase
{
    public function testEveryChannelAndKindHasItsColor(): void
    {
        $stylesheet = (string) file_get_contents(__DIR__ . '/../../assets/css/module/_stream.scss');
        $keys = [
            'total',
            ...array_map(
                static fn (StreamSourceEnum $source): string => $source->colorKey(),
                StreamSourceEnum::cases(),
            ),
            ...array_map(static fn (StreamKindEnum $kind): string => $kind->colorKey(), StreamKindEnum::cases()),
            ...array_map(
                static fn (StreamFigureEnum $figure): string => $figure->colorKey(),
                StreamFigureEnum::cases(),
            ),
        ];

        foreach (array_unique($keys) as $key) {
            $property = '/--stream-' . preg_quote($key, '/') . ':\s*#[0-9a-f]{6};/';
            self::assertMatchesRegularExpression($property, $stylesheet, $key);
        }
    }

    public function testTheIconOfAnEventHasTheColorOfEachChannel(): void
    {
        $stylesheet = (string) file_get_contents(__DIR__ . '/../../assets/css/module/_stream.scss');

        foreach (StreamSourceEnum::cases() as $source) {
            $rule = sprintf('/&\.is-%1$s \{\s+background: var\(--stream-%1$s\);/', $source->value);
            self::assertMatchesRegularExpression($rule, $stylesheet, $source->value);
        }
    }
}
