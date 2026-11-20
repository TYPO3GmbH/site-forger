<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Import;

use App\Import\ForgeIssueImporter;
use App\Import\ImportSchema;
use PHPUnit\Framework\TestCase;

final class ImportSchemaTest extends TestCase
{
    public function testDocumentsOfAnOlderImportAreLoadedAgain(): void
    {
        self::assertTrue(ImportSchema::isCurrent('issue', ImportSchema::ISSUE));
        self::assertFalse(ImportSchema::isCurrent('issue', ImportSchema::ISSUE - 1));
        self::assertTrue(ImportSchema::isCurrent('review', ImportSchema::REVIEW));
    }

    public function testDocumentsWithoutTheFieldAreOfTheFirstVersion(): void
    {
        self::assertFalse(ImportSchema::isCurrent('issue', null));
        self::assertFalse(ImportSchema::isCurrent('review', null));
        self::assertTrue(ImportSchema::isCurrent('review', ImportSchema::REVIEW + 1));
    }

    public function testTheOutdatedDocumentsAreThoseOfAnOlderVersionOrWithoutOne(): void
    {
        // A range on the field does not match documents without it, must_not of it does
        self::assertSame(['bool' => ['must_not' => [['range' => [ImportSchema::FIELD => ['gte' => ImportSchema::ISSUE]]]]]], ImportSchema::outdated('issue'));
    }

    public function testEachIndexHasItsVersion(): void
    {
        self::assertSame(ImportSchema::ISSUE, ImportSchema::current('issue'));
        self::assertSame(ImportSchema::REVIEW, ImportSchema::current('review'));
    }

    public function testTheIssueImportStampsItsVersion(): void
    {
        $document = ForgeIssueImporter::document(['id' => 1, 'created_on' => '2026-11-01T10:00:00Z', 'updated_on' => '2026-11-01T10:00:00Z']);

        self::assertSame(ImportSchema::ISSUE, $document[ImportSchema::FIELD]);
    }

    /**
     * Fails on purpose when the fields the issue import derives change: raise ImportSchema::ISSUE with them, so
     * the next import loads the issues written before again, and update the list here.
     */
    public function testTheVersionOfTheIssueImportFollowsItsFields(): void
    {
        $document = ForgeIssueImporter::document([
            'id' => 1,
            'created_on' => '2026-11-01T10:00:00Z',
            'updated_on' => '2026-11-01T10:00:00Z',
            'custom_fields' => [['id' => 3, 'value' => 'a'], ['id' => 4, 'value' => '13'], ['id' => 5, 'value' => '8.3'], ['id' => 8, 'value' => 'easy'], ['id' => 15, 'value' => '1'], ['id' => 18, 'value' => 'Backend']],
        ]);
        $fields = array_keys($document);
        sort($fields);

        self::assertSame([2, ['complexity', 'created_on', 'custom_fields', 'duplicated_by', 'duplicates', 'focus', 'id', ImportSchema::FIELD, 'isregression', 'php_version', 'tags', 'typo3_version', 'updated_on']], [ImportSchema::ISSUE, $fields]);
    }
}
