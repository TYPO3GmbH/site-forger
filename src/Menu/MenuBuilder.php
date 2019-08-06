<?php
declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Menu;

use Knp\Menu\FactoryInterface;
//use Knp\Menu\Matcher\MatcherInterface;
use Knp\Menu\MenuFactory;
use Knp\Menu\MenuItem;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

//use Symfony\Component\DependencyInjection\ContainerInterface;
//use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
//use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * MenuBuilder
 */
class MenuBuilder
{
    /**
     * @var MenuFactory
     */
    private $factory;

    /**
     * @var AuthorizationCheckerInterface
     */
    private $authorizationChecker;

    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @param FactoryInterface $factory
     * @param AuthorizationCheckerInterface $authorizationChecker
     */
    public function __construct(
        FactoryInterface $factory,
        AuthorizationCheckerInterface $authorizationChecker,
        TokenStorageInterface $tokenStorage
    ) {
        $this->factory = $factory;
        $this->authorizationChecker = $authorizationChecker;
        $this->tokenStorage = $tokenStorage;
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
                'route' => 'dashboard_index',
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
                'route' => 'dashboard_index',
                'extras' => [
                    'icon' => 'rabbit-fast',
                ],
            ]
        );
        $menu->addChild(
            'utility',
            [
                'label' => 'Utility',
                'route' => 'dashboard_index',
                'extras' => [
                    'icon' => 'home',
                ],
            ]
        );
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
                'route' => 'dashboard_index',
                'extras' => [
                    'icon' => 'question-circle',
                ],
            ]
        );
        if ($this->authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY')) {
            $menu->addChild(
                'username',
                [
                    'label' => $this->tokenStorage->getToken()->getUsername(),
                    'uri' => '#',
                    'extras' => [
                        'icon' => 'user',
                    ],
                ]
            );
            $menu->addChild(
                'logout',
                [
                    'route' => 'logout',
                    'label' => 'Logout',
                    'extras' => [
                        'icon' => 'lock',
                    ],
                ]
            )->setLinkAttribute('class', 'btn btn-primary');
        } else {
            $menu->addChild(
                'login',
                [
                    'route' => 'login',
                    'label' => 'Login',
                    'extras' => [
                        'icon' => 'unlock',
                    ],
                ]
            )->setLinkAttribute('class', 'btn btn-primary');
        }
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
