<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Templates;

use App\Enum\SystemCheckEnum;
use App\People\User;
use App\Setup\SetupSetting;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * The content of the system page with every section filled, as SystemController passes them.
 */
final class SystemTemplateTest extends KernelTestCase
{
    public function testThePendingImportsCountTheChecksThatAreNotOk(): void
    {
        $html = $this->render([
            self::pending('issue', SystemCheckEnum::OK, 0),
            self::pending('change', SystemCheckEnum::ATTENTION, 12),
        ]);

        self::assertStringContainsString('come from an import before version 3', $html);
        self::assertStringContainsString('Look at: Setup, Outdated documents', $html);
        self::assertStringContainsString('<code>FORGE_ACCESS_TOKEN</code>', $html);
    }

    public function testThePeopleWithoutAnEmailAreListedInTheCardOfTheUsers(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 18:34:00', new \DateTimeZone('UTC'));
        $html = $this->render([], [
            'users' => [
                'result' => SystemCheckEnum::ATTENTION,
                'summary' => 'Forger has no email address for 2,200 of 5,632 people.',
                'reasons' => '1,375 not asked for yet · 54 unknown to Forge (54 refused (403))',
                'hint' => null,
            ],
            'unmapped' => 825,
            'unmappedUsers' => [
                new User(45758, 'Andreas Lindner', fetchedOn: $now, gone: true, goneStatus: 403),
                new User(45755, 'Adrian Widmer', 'awidmer', fetchedOn: $now),
                new User(41, 'Old Group', fetchedOn: $now, gone: true),
            ],
        ]);

        self::assertStringContainsString('825 people without an email address', $html);
        self::assertStringContainsString('<a href="https://forge.typo3.org/users/45758"', $html);
        self::assertStringContainsString('Refused (403)', $html);
        self::assertStringContainsString('Login: awidmer', $html);
        self::assertStringContainsString('No answer stored', $html);
        // By why, with how many each
        self::assertMatchesRegularExpression('/Unknown to Forge <span>2<\/span>.*No email on Forge <span>1<\/span>/s', $html);
    }

    /**
     * @param list<array<string, mixed>> $pending
     * @param array<string, mixed>       $users   the variables of the users, see SystemController
     */
    private function render(array $pending, array $users = []): string
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->get('request_stack')->push(Request::create('/management/system'));
        /** @var Environment $twig */
        $twig = $container->get('twig');

        // The content only: the layout around reads the menu from the database
        return $twig->load('management/system/index.html.twig')->renderBlock('body', [
            'elasticsearch' => [
                'reachable' => true,
                'error' => null,
                'version' => '8.19.0',
                'versionResult' => SystemCheckEnum::OK,
                'cluster' => 'forger',
                'health' => 'green',
                'healthResult' => SystemCheckEnum::OK,
                'nodes' => 1,
                'unassignedShards' => 0,
            ],
            'setup' => [
                new SetupSetting('FORGE_ACCESS_TOKEN', 'The users of Forge', SystemCheckEnum::PROBLEM, 'not set'),
            ],
            'indices' => [],
            'pending' => $pending,
            ...$users + ['users' => null, 'unmapped' => 0, 'unmappedUsers' => []],
            'application' => ['php' => PHP_VERSION, 'symfony' => '7.4', 'environment' => 'test', 'debug' => false],
            'overall' => SystemCheckEnum::ATTENTION,
            'notOk' => ['Setup', 'Outdated documents'],
            'minimumVersion' => '8.0',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function pending(string $index, SystemCheckEnum $result, int $matches): array
    {
        return [
            'label' => 'Version 3 of the ' . $index . ' index',
            'index' => $index,
            'version' => 3,
            'description' => 'Loaded again by the next delta.',
            'matches' => $matches,
            'documents' => 100,
            'result' => $result,
        ];
    }
}
