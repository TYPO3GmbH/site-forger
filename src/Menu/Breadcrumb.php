<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Menu;

use Knp\Menu\ItemInterface;
use Knp\Menu\Twig\Helper;
use Twig\Attribute\AsTwigFunction;

/**
 * The breadcrumb of a page in the menu, from its item: "Reviews › Needs rebase".
 *
 * A page with a block "breadcrumb" of its own, like the detail pages, does not ask for it. Without a trail are the
 * pages a click on the first level opens (the search of the reviews and the issues, the dashboard), pages the menu does
 * not have, and the items with the extra "breadcrumb" set to false, as the tabs of My Work say where the page is.
 */
final readonly class Breadcrumb
{
    private const array MENUS = ['main-default', 'main-profile'];

    public function __construct(private Helper $helper)
    {
    }

    /**
     * @return list<array{label: string, url?: string}> the items for _macros/breadcrumb.html.twig, empty for no trail
     */
    #[AsTwigFunction(name: 'menu_breadcrumb')]
    public function forCurrentPage(): array
    {
        foreach (self::MENUS as $menu) {
            $current = $this->helper->getCurrentItem($menu);
            if (null !== $current) {
                return self::trail($current);
            }
        }

        return [];
    }

    /**
     * @return list<array{label: string, url?: string}>
     */
    public static function trail(ItemInterface $current): array
    {
        if (false === $current->getExtra('breadcrumb', true)) {
            return [];
        }
        $items = [];
        for ($item = $current; null !== $item->getParent(); $item = $item->getParent()) {
            $uri = $item->getUri();
            // A dropdown without a page of its own, like "Tools", is named without a link
            array_unshift($items, null === $uri || '#' === $uri || $item === $current
                ? ['label' => $item->getLabel()]
                : ['label' => $item->getLabel(), 'url' => $uri]);
        }

        // An item of the first level is a page of its own, it needs no trail
        return count($items) > 1 ? $items : [];
    }
}
