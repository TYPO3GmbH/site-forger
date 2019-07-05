<?php
declare(strict_types=1);

/*
 * This file is part of the package t3g/elts-satis.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Menu;

use Knp\Menu\FactoryInterface;
//use Knp\Menu\Matcher\MatcherInterface;
use Knp\Menu\MenuFactory;
use Knp\Menu\MenuItem;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

//use Symfony\Component\DependencyInjection\ContainerInterface;
//use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
//use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * MenuBuilder
 */
class MenuBuilder
{
//    /**
//     * @var ContainerInterface
//     */
//    private $container;

    /**
     * @var MenuFactory
     */
    private $factory;

//    /**
//     * @var MatcherInterface
//     */
//    private $matcher;
//
    /**
     * @var AuthorizationCheckerInterface
     */
    private $authorizationChecker;
//
//    /**
//     * @var TokenStorageInterface
//     */
//    private $tokenStorage;

    /**
     * @param FactoryInterface $factory
     * @param AuthorizationCheckerInterface $authorizationChecker
     */
    public function __construct(
//        ContainerInterface $container,
        FactoryInterface $factory,
//        MatcherInterface $matcher,
        AuthorizationCheckerInterface $authorizationChecker
//        TokenStorageInterface $tokenStorage
    ) {
//        $this->container = $container;
        $this->factory = $factory;
//        $this->matcher = $matcher;
        $this->authorizationChecker = $authorizationChecker;
//        $this->tokenStorage = $tokenStorage;
    }

    /**
     * @return MenuItem
     */
    public function mainDefault(): MenuItem
    {
        $menu = $this->factory->createItem('root');
        $menu->addChild(
            'index',
            [
                'label' => 'Home',
                'route' => 'page_index',
                'extras' => [
                    'icon' => 'home',
                ],
            ]
        );
//        if ($this->authorizationChecker->isGranted('ROLE_OAUTH_USER')) {
//            $menu->addChild(
//                'tokens',
//                [
//                    'label' => 'Access tokens',
//                    'route' => 'productlist',
//                    'extras' => [
//                        'icon' => 'key',
//                    ],
//                ]
//            );
//        }
//        $menu->addChild(
//            'manual',
//            [
//                'label' => 'Manual',
//                'route' => 'page_manual',
//                'extras' => [
//                    'icon' => 'info-square',
//                ],
//            ]
//        );
        if ($this->authorizationChecker->isGranted('ROLE_USER')) {
            $menu->addChild(
                'select',
                [
                    'label' => 'Select File to edit',
                    'route' => 'select_translation',
                    'extras' => [
                        'icon' => 'file-excel',
                    ],
                ]
            );
            $menu->addChild(
                'git',
                [
                    'label' => 'Commit to git',
                    'route' => 'git_list',
                    'extras' => [
                        'icon' => 'code-merge',
                    ],
                ]
            );
            $menu->addChild(
                'logout',
                [
                    'label' => 'Logout',
                    'route' => 'logout',
                    'extras' => [
                        'icon' => 'sign-out',
                    ],
                ]
            );
        } else {
            $menu->addChild(
                'logout',
                [
                    'label' => 'Login',
                    'route' => 'login',
                    'extras' => [
                        'icon' => 'sign-in',
                    ],
                ]
            );
        }
        return $menu;
    }

    /**
     * @return MenuItem
     */
    public function mainProfile(): MenuItem
    {
        return $this->factory->createItem('root');
    }

    /**
     * @return MenuItem
     */
    public function mainFooter(): MenuItem
    {
        $menu = $this->factory->createItem('root');
        $menu->addChild(
            'privacy',
            [
                'label' => 'Privacy Policy',
                'route' => 'page_privacy',
                'extras' => [
                    'icon' => 'shield-alt',
                ],
            ]
        );
        $menu->addChild(
            'legal',
            [
                'label' => 'Imprint / Legal Information',
                'route' => 'page_legal',
                'extras' => [
                    'icon' => 'balance-scale',
                ],
            ]
        );
        return $menu;
    }
}
