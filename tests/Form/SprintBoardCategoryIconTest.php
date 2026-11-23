<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Form;

use App\Entity\SprintBoardCategory;
use App\Form\SprintBoardCategoryType;
use App\Service\IconCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The icon of a category: one of the icons "actions-*" of the template bundle, see IconCatalog.
 */
final class SprintBoardCategoryIconTest extends KernelTestCase
{
    public function testTheCatalogHasTheIconsOfTheGroupActions(): void
    {
        self::bootKernel();
        $identifiers = self::getContainer()->get(IconCatalog::class)->identifiers();

        self::assertContains('actions-rocket', $identifiers);
        self::assertContains('actions-brand-typo3', $identifiers);
        $others = array_filter($identifiers, static fn (string $id): bool => !str_starts_with($id, 'actions-'));
        self::assertSame([], $others);
        $sorted = $identifiers;
        sort($sorted);
        self::assertSame($sorted, $identifiers);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function icons(): iterable
    {
        yield 'an icon of the catalog' => ['actions-rocket', true];
        yield 'an icon of another group' => ['apps-pagetree-root', false];
        yield 'no icon at all' => ['nonsense', false];
    }

    #[DataProvider('icons')]
    public function testOnlyAnIconOfTheCatalogIsValid(string $icon, bool $valid): void
    {
        self::bootKernel();
        // The rule of the field alone: the whole form would ask the database whether the identifier is unique
        $constraints = self::getContainer()->get(FormFactoryInterface::class)
            ->create(SprintBoardCategoryType::class, new SprintBoardCategory())
            ->get('icon')->getConfig()->getOption('constraints');
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($icon, $constraints);

        self::assertSame($valid, 0 === count($violations));
    }
}
