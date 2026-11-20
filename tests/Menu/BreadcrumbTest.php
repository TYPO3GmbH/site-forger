<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Menu;

use App\Menu\Breadcrumb;
use Knp\Menu\MenuFactory;
use PHPUnit\Framework\TestCase;

final class BreadcrumbTest extends TestCase
{
    public function testAPageOfADropdownLinksToTheFirstLevel(): void
    {
        $menu = (new MenuFactory())->createItem('root');
        $rebase = $menu->addChild('gerrit', ['label' => 'Reviews', 'uri' => '/search/changes'])->addChild('rebase', ['label' => 'Needs rebase', 'uri' => '/gerrit/rebase']);

        self::assertSame([['label' => 'Reviews', 'url' => '/search/changes'], ['label' => 'Needs rebase']], Breadcrumb::trail($rebase));
    }

    public function testADropdownWithoutAPageIsNotLinked(): void
    {
        $menu = (new MenuFactory())->createItem('root');
        $rst = $menu->addChild('utility', ['label' => 'Tools', 'uri' => '#'])->addChild('rst', ['label' => 'ReST helper', 'uri' => '/utilities/rst']);

        self::assertSame([['label' => 'Tools'], ['label' => 'ReST helper']], Breadcrumb::trail($rst));
    }

    public function testNoTrailForTheFirstLevelOrWhereTheItemSaysSo(): void
    {
        $menu = (new MenuFactory())->createItem('root');
        $reviews = $menu->addChild('gerrit', ['label' => 'Reviews', 'uri' => '/search/changes']);
        $myWork = $menu->addChild('my_work', ['label' => 'My Work', 'uri' => '/my/changes']);
        $myReviews = $myWork->addChild('my_changes', ['label' => 'My reviews', 'uri' => '/my/changes', 'extras' => ['breadcrumb' => false]]);

        self::assertSame([], Breadcrumb::trail($reviews));
        self::assertSame([], Breadcrumb::trail($myReviews));
    }
}
