<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Enum;

use App\Enum\ReviewTypeEnum;
use PHPUnit\Framework\TestCase;

final class ReviewTypeEnumTest extends TestCase
{
    public function testTheTypeComesFromTheTagsOfTheSubject(): void
    {
        self::assertSame(ReviewTypeEnum::BUGFIX, ReviewTypeEnum::fromTags(['BUGFIX']));
        self::assertSame(ReviewTypeEnum::FEATURE, ReviewTypeEnum::fromTags(['!!!', 'FEATURE']));
        self::assertNull(ReviewTypeEnum::fromTags(['WIP']));
        self::assertNull(ReviewTypeEnum::fromTags([]));
    }

    public function testSeveralTypesGiveTheFirstOfTheEnum(): void
    {
        self::assertSame(ReviewTypeEnum::TASK, ReviewTypeEnum::fromTags(['BUGFIX', 'TASK']));
    }

    public function testEverySeverityHasAStyle(): void
    {
        $bundle = implode('', array_map('file_get_contents', glob(__DIR__ . '/../../vendor/t3g/symfony-template-bundle/src/Resources/public/app.*.css') ?: []));
        $tags = (string) file_get_contents(__DIR__ . '/../../assets/css/module/_tags.scss');
        foreach (ReviewTypeEnum::cases() as $type) {
            self::assertStringContainsString('.review-type-' . $type->severity() . '{', $bundle, $type->name . ' has no card style');
            if ('default' !== $type->severity()) {
                self::assertMatchesRegularExpression('/\\.tag-label-' . preg_quote($type->severity(), '/') . '[\\s,{]/', $tags, $type->name . ' has no tag style');
            }
        }
    }
}
