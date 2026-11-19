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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use T3G\Bundle\Keycloak\Security\KeyCloakAuthenticator;
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
        private readonly SprintBoardCategoryRepository $categoryRepository,
        private readonly RequestStack $requestStack,
    ) {
        parent::__construct($parameters, $factory, $matcher, $authorizationChecker, $tokenStorage, $translator);
    }

    /**
     * The main menu, by task: what needs a review, the issues, the sprint boards, the tools.
     * Items for a role are only added for users who have it, the admin pages are in the menu on the right.
     */
    #[\Override]
    public function mainDefault(array $options): ItemInterface
    {
        $menu = $this->factory->createItem('root');
        $menu->addChild('index', ['label' => 'Dashboard', 'route' => 'dashboard_index']);
        if ($this->authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY')) {
            $myWork = $menu->addChild('my_work', ['label' => 'My Work', 'route' => 'my_changes']);
            $this->addLink($myWork, 'my_changes', 'My changes', 'my_changes', 'actions-code-pull-request');
            $this->addLink($myWork, 'my_issues', 'My issues', 'my_issues', 'actions-list');
        }

        $reviews = $menu->addChild('gerrit', ['label' => 'Reviews', 'route' => 'gerrit_index']);
        $this->addHeader($reviews, 'Overview');
        $this->addLink($reviews, 'gerrit_status', 'Ready for review', 'gerrit_index', 'actions-code-pull-request');
        $this->addLink($reviews, 'gerrit_wip', 'Work in progress', 'gerrit_wip', 'actions-code-pull-request-draft');
        $this->addHeader($reviews, 'Needs action');
        $this->addLink($reviews, 'gerrit_missesreview', 'Needs review', 'gerrit_missesreview', 'actions-comment');
        $this->addLink($reviews, 'gerrit_missesverifications', 'Needs verification', 'gerrit_missesverifications', 'actions-check-circle');
        $this->addLink($reviews, 'gerrit_rebase', 'Needs rebase', 'gerrit_rebase', 'actions-refresh');
        $this->addLink($reviews, 'gerrit_negative_vote_cleanup', 'Negative votes', 'gerrit_negative_vote_cleanup', 'actions-minus-circle');
        $this->addLink($reviews, 'gerrit_hasverifications', 'Reviewed and verified', 'gerrit_hasverifications', 'actions-check-badge');
        $this->addLink($reviews, 'gerrit_coreteam_merge', 'Ready to merge', 'gerrit_coreteam_merge', 'actions-code-merge');
        $this->addHeader($reviews, 'Tools');
        $this->addLink($reviews, 'gerrit_files', 'Find by file', 'gerrit_files', 'actions-file-search');

        $issues = $menu->addChild('issues', ['label' => 'Issues', 'route' => 'search_search']);
        $this->addLink($issues, 'issues_search', 'Search', 'search_search', 'actions-search');
        $this->addLink($issues, 'management-by-year', 'By month', 'management_index', 'actions-calendar');
        $this->addLink($issues, 'management-by-category', 'By category', 'management_bycategory', 'actions-tag');
        $this->addLink($issues, 'gerrit_stale_issues', 'Stale issues', 'gerrit_stale_issues', 'actions-clock');

        $sprints = $menu->addChild('sprint', ['label' => 'Sprints', 'route' => 'sprint_overview']);
        $this->addLink($sprints, 'sprint_overview', 'Overview', 'sprint_overview', 'actions-viewmode-layout');
        /** @var SprintBoardCategory $category */
        foreach ($this->categoryRepository->findAll() as $category) {
            $this->addLink($sprints, 'sprint_' . $category->getIdentifier(), $category->getName(), 'sprint', $category->getIcon(), ['category' => $category->getIdentifier()]);
        }
        $this->addLink($sprints, 'sprint_reviews', 'Review board', 'sprint_reviews', 'actions-line-columns');
        if ($this->authorizationChecker->isGranted('ROLE_CORE_COMPONENT')) {
            $this->addHeader($sprints, 'Manage');
            $this->addLink($sprints, 'sprint_list', 'Sprint boards', 'sprint_list', 'actions-viewmode-tiles');
            $this->addLink($sprints, 'sprintcategory_index', 'Categories', 'sprintcategory_index', 'actions-category');
        }

        $tools = $menu->addChild('utility', ['label' => 'Tools', 'uri' => '#']);
        $this->addLink($tools, 'utility_backports', 'Backports', 'utilities_backport', 'actions-code-compare');
        if ($this->authorizationChecker->isGranted('ROLE_CORE_COMPONENT')) {
            $this->addLink($tools, 'utility_backport_list', 'Backport exclusions', 'utilities_backport_index', 'actions-ban');
        }
        $this->addLink($tools, 'utility_rst', 'ReST helper', 'utilities_rst', 'actions-file-text');

        return $menu;
    }

    /**
     * @param array<string, string> $routeParameters
     */
    private function addLink(ItemInterface $parent, string $name, string $label, string $route, string $icon, array $routeParameters = []): void
    {
        $parent->addChild($name, [
            'label' => $label,
            'route' => $route,
            'routeParameters' => $routeParameters,
            'extras' => ['icon' => $icon],
        ]);
    }

    /**
     * A heading of a group in a dropdown: an item without a link, rendered as label only.
     */
    private function addHeader(ItemInterface $parent, string $label): void
    {
        $parent->addChild('header_' . strtolower(str_replace(' ', '_', $label)), ['label' => $label])
            ->setLabelAttribute('class', 'dropdown-header');
    }

    #[\Override]
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
        // Without JavaScript the link opens the search page, assets/js/header-search.js opens a dialog instead
        $menu->addChild(
            'search',
            [
                'label' => 'Search',
                'route' => 'search_search',
                'attributes' => ['class' => 'nav-item nav-item-search'],
                'linkAttributes' => [
                    'class' => 'nav-link js-header-search-toggle',
                    'title' => 'Search',
                    'aria-controls' => 'header-search',
                    'aria-expanded' => 'false',
                ],
                'extras' => [
                    'icon' => 'actions-search',
                ],
            ]
        );
        // Every page only admins may open, next to the account
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            $admin = $menu->addChild('admin', [
                'label' => 'Admin',
                'uri' => '#',
                'extras' => ['icon' => 'actions-cog'],
            ]);
            $this->addHeader($admin, 'Reports');
            $this->addLink($admin, 'management-interactions', 'Core team interactions', 'management_interactions', 'actions-users');
            $this->addLink($admin, 'management-docs-interactions', 'Docs team interactions', 'management_docs_interactions', 'actions-users');
            $this->addHeader($admin, 'Operations');
            $this->addLink($admin, 'management-command-runs', 'Command runs', 'management_command_runs', 'actions-history');
            $this->addLink($admin, 'management-system', 'System', 'management_system', 'actions-database');
        }
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
            if ($this->isKeycloakSession()) {
                $this->addAccountItems($menu['username'], 't3g_keycloak_logout');
            } else {
                // Fixed dev users have no Keycloak account, sign them out locally
                $menu['username']->addChild('logout', [
                    'label' => 'Sign out',
                    'route' => 'logout',
                    'extras' => [
                        'icon' => 'actions-logout',
                    ],
                ]);
            }
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

    private function isKeycloakSession(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request
            && $request->hasSession()
            && $request->getSession()->has(KeyCloakAuthenticator::SESSION_KEYCLOAK_ACCESS_TOKEN);
    }
}
