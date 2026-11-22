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
use App\Menu\MenuBuilder;
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

    public function testAPageOfTheFirstLevelIsNamedAlone(): void
    {
        $menu = (new MenuFactory())->createItem('root');
        $reviews = $menu->addChild('gerrit', ['label' => 'Reviews', 'uri' => '/search/changes']);

        self::assertSame([['label' => 'Reviews']], Breadcrumb::trail($reviews));
    }

    public function testTheDashboardHasNoTrail(): void
    {
        $menu = (new MenuFactory())->createItem('root');
        $home = $menu->addChild(MenuBuilder::HOME, ['label' => 'Dashboard', 'uri' => '/']);

        self::assertSame([], Breadcrumb::trail($home));
    }

    public function testTheViewsOfMyWorkHaveATrail(): void
    {
        $menu = (new MenuFactory())->createItem('root');
        $myWork = $menu->addChild('my_work', ['label' => 'My Work', 'uri' => '/my/overview']);
        $following = $myWork->addChild('my_following', ['label' => 'Following', 'uri' => '/my/following']);

        self::assertSame(
            [['label' => 'My Work', 'url' => '/my/overview'], ['label' => 'Following']],
            Breadcrumb::trail($following),
        );
    }
}
