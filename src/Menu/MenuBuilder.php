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
        $menu->addChild(
            'gerrit',
            [
                'label' => 'Reviews',
                'route' => 'gerrit_index',
                'extras' => [
                    'icon' => 'code-merge',
                ],
            ]
        );
            $menu['gerrit']->addChild(
                'gerrit_status',
                [
                    'label' => 'All (excl. WIP)',
                    'route' => 'gerrit_index',
                    'extras' => [
                        'icon' => 'home',
                    ],
                ]
            );
            $menu['gerrit']->addChild(
                'gerrit_hasverifications',
                [
                    'label' => 'Has verifications',
                    'route' => 'gerrit_hasverifications',
                    'extras' => [
                        'icon' => 'check-square',
                    ],
                ]
            );
            $menu['gerrit']->addChild(
                'gerrit_missesverifications',
                [
                    'label' => 'Needs verification',
                    'route' => 'gerrit_missesverifications',
                    'extras' => [
                        'icon' => 'plus-square',
                    ],
                ]
            );
            $menu['gerrit']->addChild(
                'gerrit_missesreview',
                [
                    'label' => 'Needs review',
                    'route' => 'gerrit_missesreview',
                    'extras' => [
                        'icon' => 'plus-square',
                    ],
                ]
            );
            $menu['gerrit']->addChild(
                'gerrit_rebase',
                [
                    'label' => 'Rebase',
                    'route' => 'gerrit_rebase',
                    'extras' => [
                        'icon' => 'sync',
                    ],
                ]
            );
            $menu['gerrit']->addChild(
                'gerrit_files',
                [
                    'label' => 'Find Review by filename',
                    'route' => 'gerrit_files',
                    'extras' => [
                        'icon' => 'file',
                    ],
                ]
            );
            $menu['gerrit']->addChild(
                'gerrit_negative_vote_cleanup',
                [
                    'label' => 'Has negative votes',
                    'route' => 'gerrit_negative_vote_cleanup',
                    'extras' => [
                        'icon' => 'thumbs-down',
                    ],
                ]
            );
            $menu['gerrit']->addChild(
                'gerrit_wip',
                [
                    'label' => 'Work In Progress',
                    'route' => 'gerrit_wip',
                    'extras' => [
                        'icon' => 'bolt',
                    ],
                ]
            );
            $menu['gerrit']->addChild(
                'gerrit_coreteam_merge',
                [
                    'label' => 'Coreteam action required',
                    'route' => 'gerrit_coreteam_merge',
                    'extras' => [
                        'icon' => 'users',
                    ],
                ]
            );
            $menu['gerrit']->addChild(
                'gerrit_stale_issues',
                [
                    'label' => 'Stale Issues',
                    'route' => 'gerrit_stale_issues',
                    'extras' => [
                        'icon' => 'ban',
                    ],
                ]
            );
        $menu->addChild(
            'management',
            [
                'label' => 'Management',
                'route' => 'management_index',
                'extras' => [
                    'icon' => 'list',
                ],
            ]
        );
        $menu->addChild(
            'sprint',
            [
                'label' => 'Sprints',
                'route' => 'page_index',
                'extras' => [
                    'icon' => 'rabbit-fast',
                ],
            ]
        );
        $menu->addChild(
            'utility',
            [
                'label' => 'Utility',
                'route' => 'page_index',
                'extras' => [
                    'icon' => 'home',
                ],
            ]
        );
//        if ($this->authorizationChecker->isGranted('ROLE_USER')) {
//            $menu->addChild(
//                'select',
//                [
//                    'label' => 'Select File to edit',
//                    'route' => 'select_translation',
//                    'extras' => [
//                        'icon' => 'file-excel',
//                    ],
//                ]
//            );
//            $menu->addChild(
//                'git',
//                [
//                    'label' => 'Commit to git',
//                    'route' => 'git_list',
//                    'extras' => [
//                        'icon' => 'code-merge',
//                    ],
//                ]
//            );
//            $menu->addChild(
//                'logout',
//                [
//                    'label' => 'Logout',
//                    'route' => 'logout',
//                    'extras' => [
//                        'icon' => 'sign-out',
//                    ],
//                ]
//            );
//        } else {
//            $menu->addChild(
//                'logout',
//                [
//                    'label' => 'Login',
//                    'route' => 'login',
//                    'extras' => [
//                        'icon' => 'sign-in',
//                    ],
//                ]
//            );
//        }
        return $menu;
    }

    /**
     * @return MenuItem
     */
    public function mainProfile(): MenuItem
    {
        $menu = $this->factory->createItem('root');
        $menu->addChild(
            'help',
            [
                'label' => 'Help',
                'route' => 'page_index',
                'extras' => [
                    'icon' => 'question-circle',
                ],
            ]
        );
//        $menu->addChild(
//            'legal',
//            [
//                'label' => 'Imprint / Legal Information',
//                'route' => 'page_legal',
//                'extras' => [
//                    'icon' => 'balance-scale',
//                ],
//            ]
//        );
        return $menu;
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
                'route' => 'static_privacy'
            ]
        );
        $menu->addChild(
            'legal',
            [
                'label' => 'Legal Information',
                'route' => 'static_legal'
            ]
        );
        return $menu;
    }
}
