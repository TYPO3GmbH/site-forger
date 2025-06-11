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
use Knp\Menu\ItemInterface;
use Knp\Menu\Matcher\MatcherInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use T3G\Bundle\Keycloak\Security\KeyCloakUser;
use T3G\Bundle\TemplateBundle\Utility\AvatarUtility;

/**
 * MenuBuilder.
 */
class MenuBuilder extends \T3G\Bundle\TemplateBundle\Menu\MenuBuilder
{
    public function __construct(
        ParameterBagInterface $parameters,
        FactoryInterface $factory,
        MatcherInterface $matcher,
        AuthorizationCheckerInterface $authorizationChecker,
        TokenStorageInterface $tokenStorage,
        TranslatorInterface $translator,
        private readonly SprintBoardCategoryRepository $categoryRepository
    ) {
        parent::__construct($parameters, $factory, $matcher, $authorizationChecker, $tokenStorage, $translator);
    }

    public function mainDefault(array $options): ItemInterface
    {
        $categories = $this->categoryRepository->findAll();
        $menu = $this->factory->createItem('root');
        $menu->addChild(
            'index',
            [
                'label' => 'Dashboard',
                'route' => 'dashboard_index',
            ]
        );
        $menu->addChild(
            'gerrit',
            [
                'label' => 'Reviews',
                'route' => 'gerrit_index',
            ]
        );
        $menu['gerrit']->addChild(
            'gerrit_status',
            [
                'label' => 'All (excl. WIP)',
                'route' => 'gerrit_index',
                'extras' => [
                    'icon' => 'actions-code-pull-request',
                ],
            ]
        );
        $menu['gerrit']->addChild(
            'gerrit_hasverifications',
            [
                'label' => 'Has verifications',
                'route' => 'gerrit_hasverifications',
                'extras' => [
                    'icon' => 'actions-check-square',
                ],
            ]
        );
        $menu['gerrit']->addChild(
            'gerrit_missesverifications',
            [
                'label' => 'Needs verification',
                'route' => 'gerrit_missesverifications',
                'extras' => [
                    'icon' => 'actions-plus-square',
                ],
            ]
        );
        $menu['gerrit']->addChild(
            'gerrit_missesreview',
            [
                'label' => 'Needs review',
                'route' => 'gerrit_missesreview',
                'extras' => [
                    'icon' => 'actions-plus-square',
                ],
            ]
        );
        $menu['gerrit']->addChild(
            'gerrit_rebase',
            [
                'label' => 'Rebase',
                'route' => 'gerrit_rebase',
                'extras' => [
                    'icon' => 'actions-refresh',
                ],
            ]
        );
        $menu['gerrit']->addChild(
            'gerrit_files',
            [
                'label' => 'Find Review by filename',
                'route' => 'gerrit_files',
                'extras' => [
                    'icon' => 'actions-file-search',
                ],
            ]
        );
        $menu['gerrit']->addChild(
            'gerrit_negative_vote_cleanup',
            [
                'label' => 'Has negative votes',
                'route' => 'gerrit_negative_vote_cleanup',
                'extras' => [
                    'icon' => 'actions-minus-square',
                ],
            ]
        );
        $menu['gerrit']->addChild(
            'gerrit_wip',
            [
                'label' => 'Work In Progress',
                'route' => 'gerrit_wip',
                'extras' => [
                    'icon' => 'actions-code-pull-request-draft',
                ],
            ]
        );
        $menu['gerrit']->addChild(
            'gerrit_coreteam_merge',
            [
                'label' => 'Coreteam action required',
                'route' => 'gerrit_coreteam_merge',
                'extras' => [
                    'icon' => 'actions-exclamation-triangle',
                ],
            ]
        );
        $menu['gerrit']->addChild(
            'gerrit_stale_issues',
            [
                'label' => 'Stale Issues',
                'route' => 'gerrit_stale_issues',
                'extras' => [
                    'icon' => 'actions-clock',
                ],
            ]
        );
        $menu->addChild(
            'management',
            [
                'label' => 'Management',
                'route' => 'management_index',
            ]
        );
        $menu['management']->addChild(
            'management-by-year',
            [
                'label' => 'Issue Overview',
                'route' => 'management_index',
                'extras' => [
                    'icon' => 'actions-calendar',
                ],
            ]
        );
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            $menu['management']->addChild(
                'management-interactions',
                [
                    'label' => 'Core Team Interactions',
                    'route' => 'management_interactions',
                    'extras' => [
                        'icon' => 'actions-users',
                    ],
                ]
            );
            $menu['management']->addChild(
                'management-docs-interactions',
                [
                    'label' => 'Doc Team Interactions',
                    'route' => 'management_docs_interactions',
                    'extras' => [
                        'icon' => 'actions-users',
                    ],
                ]
            );
        }
        $menu['management']->addChild(
            'management-by-category',
            [
                'label' => 'Issues by Category',
                'route' => 'management_bycategory',
                'extras' => [
                    'icon' => 'actions-tag',
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
                        'icon' => 'actions-building',
                    ],
                ]
            );
            $menu['management']->addChild(
                'sprintcategory_index',
                [
                    'label' => 'Sprint Categories',
                    'route' => 'sprintcategory_index',
                    'extras' => [
                        'icon' => 'actions-category',
                    ],
                ]
            );
            $menu['management']->addChild(
                'sprint_list',
                [
                    'label' => 'Sprints',
                    'route' => 'sprint_list',
                    'extras' => [
                        'icon' => 'actions-document',
                    ],
                ]
            );
        }
        $menu->addChild(
            'sprint',
            [
                'label' => 'Sprints',
                'uri' => '#',
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
                        'icon' => $category->getIcon(),
                    ],
                ]
            );
        }
        $menu['sprint']->addChild(
            'sprint_reviews',
            [
                'label' => 'Reviews',
                'route' => 'sprint_reviews',
                'extras' => [
                    'icon' => 'actions-cog',
                ],
            ]
        );
        $menu->addChild(
            'utility',
            [
                'label' => 'Utility',
                'uri' => '#',
            ]
        );
        $menu['utility']->addChild(
            'utility_backports',
            [
                'label' => 'Backports',
                'route' => 'utilities_backport',
                'extras' => [
                    'icon' => 'actions-code-compare',
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
                        'icon' => 'actions-ban',
                    ],
                ]
            );
        }
        $menu['utility']->addChild(
            'utility_rst',
            [
                'label' => 'ReST Helper',
                'route' => 'utilities_rst',
                'extras' => [
                    'icon' => 'actions-file-text',
                ],
            ]
        );

        return $menu;
    }

    public function mainProfile(array $options): ItemInterface
    {
        $menu = $this->factory->createItem('root');
        $menu->addChild(
            'help',
            [
                'label' => 'Help',
                'route' => 'static_help',
            ]
        );
        if ($this->authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY')) {
            /** @var KeyCloakUser $user */
            $user = $this->tokenStorage->getToken()->getUser();
            $menu->addChild(
                'username',
                [
                    'label' => $user->getDisplayName(),
                    'uri' => '#',
                    'extras' => [
                        'image' => AvatarUtility::getAvatarUrl($user->getEmail(), 32),
                    ],
                ]
            );
            $menu['username']->addChild(
                'profile_employment',
                [
                    'label' => 'Company Employment',
                    'route' => 'user_employment',
                    'extras' => [
                        'icon' => 'actions-id-badge',
                    ],
                ]
            );
            $menu['username']->addChild($this->getDivider());
            $this->addAccountItems($menu['username'], 't3g_keycloak_logout');
        } else {
            $menu->addChild(
                'login',
                [
                    'route' => 'login',
                    'label' => 'Login',
                    'extras' => [
                        'icon' => 'actions-unlock',
                    ],
                ]
            )->setLinkAttribute('class', 'btn btn-lighter');
        }

        return $menu;
    }
}
