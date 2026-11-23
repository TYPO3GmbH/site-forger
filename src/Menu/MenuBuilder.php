<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Menu;

use App\Sprint\VisibleBoards;
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
    /**
     * The name of the item of the dashboard, the home page: every breadcrumb starts there, see Breadcrumb.
     */
    public const string HOME = 'index';

    public function __construct(
        ParameterBagInterface $parameters,
        FactoryInterface $factory,
        MatcherInterface $matcher,
        AuthorizationCheckerInterface $authorizationChecker,
        TokenStorageInterface $tokenStorage,
        TranslatorInterface $translator,
        private readonly VisibleBoards $visibleBoards,
        private readonly RequestStack $requestStack,
    ) {
        parent::__construct($parameters, $factory, $matcher, $authorizationChecker, $tokenStorage, $translator);
    }

    /**
     * The main menu, by task: what needs a review, the issues, the sprint boards, the tools.
     * Items for a role are only added for users who have it, the admin pages are in the menu on the right.
     *
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function mainDefault(array $options): ItemInterface
    {
        $menu = $this->factory->createItem('root');
        // The work first: the own one, then the lists. Get involved stands beside Help, see mainProfile().
        $menu->addChild(self::HOME, ['label' => 'Dashboard', 'route' => 'dashboard_index']);
        if ($this->authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY')) {
            $this->addMyWork($menu->addChild('my_work', ['label' => 'My Work', 'route' => 'my_overview']));
        }

        // A click on "Reviews" or "Issues" opens their search, all open ones without search words: the entry page,
        // so their dropdowns have no "Search" of their own
        $reviews = $menu->addChild('reviews', ['label' => 'Reviews', 'route' => 'search_changes']);
        $this->addHeader($reviews, 'Overview');
        $this->addLink($reviews, 'reviews_ready', 'Ready for review', 'reviews_ready', 'actions-code-pull-request');
        $this->addLink($reviews, 'reviews_wip', 'Work in progress', 'reviews_wip', 'actions-code-pull-request-draft');
        $this->addHeader($reviews, 'Needs action');
        $this->addLink($reviews, 'reviews_needs_review', 'Needs review', 'reviews_needs_review', 'actions-comment');
        $this->addLink($reviews, 'reviews_needs_verification', 'Needs verification', 'reviews_needs_verification', 'actions-check-circle');
        $this->addLink($reviews, 'reviews_needs_rebase', 'Needs rebase', 'reviews_needs_rebase', 'actions-refresh');
        $this->addLink($reviews, 'reviews_negative_votes', 'Negative votes', 'reviews_negative_votes', 'actions-minus-circle');
        $this->addLink($reviews, 'reviews_verified', 'Reviewed and verified', 'reviews_verified', 'actions-check-badge');
        $this->addLink($reviews, 'reviews_ready_to_merge', 'Ready to merge', 'reviews_ready_to_merge', 'actions-code-merge');

        // The most important first: where work waits, then the overviews
        $issues = $menu->addChild('issues', ['label' => 'Issues', 'route' => 'search_search']);
        $this->addLink($issues, 'issues_stale', 'Stale issues', 'issues_stale', 'actions-clock');
        $this->addLink($issues, 'issues_duplicates', 'Possible duplicates', 'issues_duplicates', 'actions-duplicates');
        $this->addLink($issues, 'issues_by_category', 'By category', 'issues_by_category', 'actions-tag');
        $this->addLink($issues, 'issues_by_month', 'By month', 'issues_by_month', 'actions-calendar');

        // The statistics first: what got done and what waits. The stream of the single events comes second.
        $activity = $menu->addChild('activity', ['label' => 'Activity', 'route' => 'dashboard_statistics']);
        $this->addLink($activity, 'statistics', 'Statistics', 'dashboard_statistics', 'actions-viewmode-tiles');
        $this->addLink($activity, 'activity_index', 'Stream', 'activity_index', 'actions-list');

        $sprints = $menu->addChild('sprint', ['label' => 'Boards', 'route' => 'sprint_overview']);
        $this->addLink($sprints, 'sprint_overview', 'Overview', 'sprint_overview', 'actions-viewmode-layout');
        // The categories with a board the user sees, see VisibleBoards
        foreach (array_column($this->visibleBoards->byCategory(), 'category') as $category) {
            $identifier = $category->getIdentifier();
            if (null === $identifier) {
                continue;
            }
            $this->addLink(
                $sprints,
                'sprint_' . $identifier,
                $category->getName() ?? $identifier,
                'sprint',
                $category->getIcon(),
                ['category' => $identifier],
            );
        }

        // A click on "Tools" opens their overview, its tiles are the items below with their description
        $tools = $menu->addChild('utility', ['label' => 'Tools', 'route' => 'utilities_index']);
        $this->addLink($tools, 'utility_backports', 'Backports', 'utilities_backport', 'actions-code-compare', description: 'Merged into main within a year, with a version in "Releases:" that has no backport yet.');
        if ($this->authorizationChecker->isGranted('ROLE_FORGER_MANAGEMENT')) {
            $this->addLink($tools, 'utility_backport_list', 'Backport exclusions', 'utilities_backport_index', 'actions-ban', description: 'The changes the backports leave out, and why.');
        }
        $this->addLink($tools, 'utility_rst', 'ReST helper', 'utilities_rst', 'actions-file-text', description: 'Writes the changelog file of a breaking change, deprecation or feature.');

        return $menu;
    }

    /**
     * @param array<string, string> $routeParameters
     * @param string|null           $description     a line about the page, for the tiles of an overview like the tools
     */
    private function addLink(
        ItemInterface $parent,
        string $name,
        string $label,
        string $route,
        ?string $icon,
        array $routeParameters = [],
        ?string $description = null,
    ): void {
        $parent->addChild($name, [
            'label' => $label,
            'route' => $route,
            'routeParameters' => $routeParameters,
            'extras' => array_filter(['icon' => $icon, 'description' => $description]),
        ]);
    }

    /**
     * The views of My Work: in the main menu and in the menu of the account.
     */
    private function addMyWork(ItemInterface $parent): void
    {
        $this->addLink($parent, 'my_overview', 'Overview', 'my_overview', 'actions-viewmode-tiles');
        $this->addLink($parent, 'my_changes', 'Reviews', 'my_changes', 'actions-code-pull-request');
        $this->addLink($parent, 'my_issues', 'Issues', 'my_issues', 'actions-list');
        $this->addLink($parent, 'my_interests', 'Interests', 'my_interests', 'actions-filter');
        $this->addLink($parent, 'my_following', 'Following', 'my_following', 'actions-users');
        $this->addLink($parent, 'my_statistics', 'Statistics', 'my_statistics', 'actions-history');
    }

    /**
     * A heading of a group in a dropdown: an item without a link, rendered as label only.
     */
    private function addHeader(ItemInterface $parent, string $label): void
    {
        $parent->addChild('header_' . strtolower(str_replace(' ', '_', $label)), ['label' => $label])
            ->setLabelAttribute('class', 'dropdown-header');
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function mainProfile(array $options): ItemInterface
    {
        $menu = $this->factory->createItem('root');
        // Where somebody new starts, beside the help
        $menu->addChild('get_involved', ['label' => 'Get involved', 'route' => 'get_involved']);
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
            $this->addHeader($admin, 'Operations');
            $this->addLink($admin, 'management-command-runs', 'Command runs', 'management_command_runs', 'actions-history');
            $this->addLink($admin, 'management-system', 'System', 'management_system', 'actions-database');
            $this->addHeader($admin, 'Community');
            $this->addLink($admin, 'management-leaderboard', 'Leaderboard', 'management_leaderboard', 'actions-users');
            $this->addLink($admin, 'management-people', 'People', 'management_people', 'actions-user');
        }
        $user = $this->tokenStorage->getToken()?->getUser();
        if ($user instanceof KeyCloakUser && $this->authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY')) {
            $account = $menu->addChild(
                'username',
                [
                    'label' => $user->getDisplayName(),
                    'uri' => '#',
                    'extras' => [
                        'image' => AvatarUtility::getAvatarUrl($user->getEmail() ?? '', 32),
                    ],
                ]
            );
            // The own pages first, as on other sites the menu of the account
            $this->addHeader($account, 'My Work');
            $this->addMyWork($account);
            $this->addHeader($account, 'Account');
            $this->addLink($account, 'my_following_manage', 'People you follow', 'my_following_manage', 'actions-user');
            if ($this->isKeycloakSession()) {
                $this->addAccountItems($account, 't3g_keycloak_logout');
            } else {
                // Fixed dev users have no Keycloak account, sign them out locally
                $account->addChild('logout', [
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

    /**
     * The links in the footer: the API documentation, then the legal pages of the template bundle.
     * The API is for developers and tools, so it stays out of the main menu.
     *
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function mainFooter(array $options): ItemInterface
    {
        $menu = $this->factory->createItem('root');
        $menu->addChild('api', ['label' => 'API', 'route' => 'app.api_doc']);
        foreach (parent::mainFooter($options)->getChildren() as $item) {
            $menu->addChild($item->copy());
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
