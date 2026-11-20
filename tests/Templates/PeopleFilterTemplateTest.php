<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The filters of people show a person as everywhere, of issues and changes alike; no value of a filter is markup.
 */
final class PeopleFilterTemplateTest extends KernelTestCase
{
    private const string AVATARS_CACHE_KEY = 'user_avatars';

    protected function setUp(): void
    {
        self::bootKernel();
        $cache = self::getContainer()->get('cache.app');
        $cache->delete(self::AVATARS_CACHE_KEY);
        $cache->get(self::AVATARS_CACHE_KEY, static fn (): array => ['forge:320' => md5('benni@example.org')]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('cache.app')->delete(self::AVATARS_CACHE_KEY);
        parent::tearDown();
    }

    public function testPeopleShowWithTheirAvatarOtherValuesAsText(): void
    {
        $latest = static fn (array $source): array => ['user' => ['hits' => ['hits' => [['_source' => $source]]]]];
        $html = self::getContainer()->get('twig')->render('_partials/aggregations.html.twig', [
            'facetScope' => 'issues',
            'aggregations' => [
                'Author' => ['buckets' => [['key' => 'Benni Mack', 'doc_count' => 3] + $latest(['author' => ['id' => 320]])]],
                'Owner' => ['buckets' => [['key' => '<b>Ingo</b>', 'doc_count' => 2] + $latest(['ownerEmail' => 'ingo@example.org'])]],
                'Tags' => ['buckets' => [['key' => '<i>cache</i>', 'doc_count' => 1]]],
            ],
        ]);

        // Of Forge by the number, of Gerrit by the email, the same markup
        self::assertStringContainsString('<span class="user" title="Benni Mack"><span class="user-avatar" aria-hidden="true"><img src="https://www.gravatar.com/avatar/' . md5('benni@example.org') . '?s=32', $html);
        self::assertStringContainsString('<span class="user-avatar" aria-hidden="true"><img src="https://www.gravatar.com/avatar/' . md5('ingo@example.org') . '?s=32', $html);
        // Names and values are text
        self::assertStringContainsString('<span class="user-name">&lt;b&gt;Ingo&lt;/b&gt;</span>', $html);
        self::assertStringContainsString('<span class="form-check-label-title">&lt;i&gt;cache&lt;/i&gt;</span>', $html);
        self::assertStringNotContainsString('<b>Ingo</b>', $html);
        self::assertStringNotContainsString('<i>cache</i>', $html);
    }
}
