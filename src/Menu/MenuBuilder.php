<?php
declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Menu;

use App\Entity\SprintBoardCategory;
use App\Repository\SprintBoardCategoryRepository;
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
class MenuBuilder extends \T3G\Bundle\TemplateBundle\Menu\MenuBuilder
{
    /**
     * @var SprintBoardCategoryRepository
     */
    private $categoryRepository;

    /**
     * @param FactoryInterface $factory
     * @param AuthorizationCheckerInterface $authorizationChecker
     * @param TokenStorageInterface $tokenStorage
     * @param SprintBoardCategoryRepository $sprintBoardCategoryRepository
     */
    public function __construct(
        FactoryInterface $factory,
        AuthorizationCheckerInterface $authorizationChecker,
        TokenStorageInterface $tokenStorage,
        SprintBoardCategoryRepository $sprintBoardCategoryRepository
    ) {
        $this->factory = $factory;
        $this->authorizationChecker = $authorizationChecker;
        $this->tokenStorage = $tokenStorage;
        $this->categoryRepository = $sprintBoardCategoryRepository;
    }

    /**
     * @param array $options
     * @return MenuItem
     */
    public function mainDefault(array $options): MenuItem
    {
        $categories = $this->categoryRepository->findAll();
        $menu = $this->factory->createItem('root');
        $menu->addChild(
            'index',
            [
                'label' => 'Dashboard',
                'route' => 'dashboard_index',
                'extras' => [
                    'icon' => 'tachometer-alt',
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
        $menu['management']->addChild(
            'management-by-year',
            [
                'label' => 'Issue Overview',
                'route' => 'management_index',
                'extras' => [
                    'icon' => 'calendar-alt',
                ],
            ]
        );
        $menu['management']->addChild(
            'management-interactions',
            [
                'label' => 'Team Interactions',
                'route' => 'management_interactions',
                'extras' => [
                    'icon' => 'users',
                ],
            ]
        );
        $menu['management']->addChild(
            'management-by-category',
            [
                'label' => 'Issues by Category',
                'route' => 'management_bycategory',
                'extras' => [
                    'icon' => 'tag',
                ],
            ]
        );
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            $menu['management']->addChild(
                'management-companies',
                [
                    'label' => 'Companies',
                    'route' => 'management_companies',
                    'extras' => [
                        'icon' => 'building',
                    ],
                ]
            );
        }
        $menu->addChild(
            'sprint',
            [
                'label' => 'Sprints',
                'route' => 'sprint',
                'extras' => [
                    'icon' => 'flag-checkered',
                ],
            ]
        );
        /** @var SprintBoardCategory $category */
        foreach ($categories as $category) {
            $menu['sprint']->addChild(
                'sprint_' . $category->getIdentifier(),
                [
                    'label' => $category->getName(),
                    'route' => 'sprint',
                    'routeParameters' => ['category' => $category->getIdentifier()],
                    'extras' => [
                        'icon' => $category->getIcon()
                    ]
                ]
            );
        }
        $menu['sprint']->addChild(
            'sprint_reviews',
            [
                'label' => 'Reviews',
                'route' => 'sprint_reviews',
                'extras' => [
                    'icon' => 'cogs',
                ],
            ]
        );
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            $menu['sprint']->addChild(
                'sprint_create',
                [
                    'label' => 'Create sprint',
                    'route' => 'sprint_create',
                    'extras' => [
                        'icon' => 'plus',
                    ],
                ]
            );
        }
        $menu->addChild(
            'utility',
            [
                'label' => 'Utility',
                'uri' => '#',
                'extras' => [
                    'icon' => 'tools',
                ],
            ]
        );
        $menu['utility']->addChild(
            'utility_backports',
            [
                'label' => 'Backports',
                'route' => 'utilities_backport',
                'extras' => [
                    'icon' => 'chevron-double-left',
                ],
            ]
        );
        $menu['utility']->addChild(
            'utility_rst',
            [
                'label' => 'ReST Helper',
                'route' => 'utilities_rst',
                'extras' => [
                    'icon' => 'file-alt',
                ],
            ]
        );
        if ($this->authorizationChecker->isGranted('ROLE_CORE_COMPONENT')) {
            $menu['utility']->addChild(
                'utility_backport_list',
                [
                    'label' => 'Backport blacklist',
                    'route' => 'utilities_backport_index',
                    'extras' => [
                        'icon' => 'times',
                    ],
                ]
            );
        }
        return $menu;
    }

    /**
     * @param array $options
     * @return MenuItem
     */
    public function mainProfile(array $options): MenuItem
    {
        $menu = $this->factory->createItem('root');
        $menu->addChild(
            'help',
            [
                'label' => 'Help',
                'route' => 'static_help',
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
            $menu['username']->addChild(
                'profile_employment',
                [
                    'label' => 'Company Employment',
                    'route' => 'user_employment',
                    'extras' => [
                        'icon' => 'user-tie',
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
}
