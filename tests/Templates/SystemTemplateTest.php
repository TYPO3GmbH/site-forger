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

        self::assertStringContainsString('of 100 changes older than version 3', $html);
    }

    /**
     * @param list<array<string, mixed>> $pending
     */
    private function render(array $pending): string
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
            'indices' => [],
            'pending' => $pending,
            'users' => null,
            'application' => ['php' => PHP_VERSION, 'symfony' => '7.4', 'environment' => 'test', 'debug' => false],
            'overall' => SystemCheckEnum::ATTENTION,
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
