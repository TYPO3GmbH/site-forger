<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Command\ImportUsersCommand;
use App\Entity\PersonLink;
use App\Enum\LinkReasonEnum;
use App\Import\ForgeUserSync;
use App\Import\GerritAccountLookup;
use App\People\AccountActivity;
use App\People\LinkSuggestion;
use App\People\LinkSuggestions;
use App\People\PersonLinkCleanup;
use App\People\User;
use App\People\UserDirectory;
use App\Repository\AccountRequestRepository;
use App\Repository\PersonLinkDismissalRepository;
use App\Repository\PersonLinkRepository;
use App\Repository\QueuedJobRepository;
use App\Repository\StreamRepository;
use App\Repository\UserRepository;
use App\Search\AccountActivityQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The people for the admins: the accounts of a person on Forge, Gerrit and of TYPO3, and the accounts an admin
 * links to a person, see App\Entity\PersonLink. Forge, Gerrit and the TYPO3 accounts stay as they are: Forger only
 * counts a linked account as the person's, everywhere, see UserDirectory. The suggestions are for checking:
 * nothing is linked without a click.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route(path: '/management/people', name: 'management_people')]
class PeopleController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'person_link';

    /**
     * The value of the parameter "view" for the list of the accounts linked.
     */
    public const string VIEW_LINKED = 'linked';

    /**
     * The value of the parameter "view" for the list of the Forge accounts without a username, see
     * UserRepository::withoutUsername().
     */
    public const string VIEW_NO_USERNAME = 'no-username';

    private const int SUGGESTIONS = 50;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserDirectory $directory,
        private readonly PersonLinkRepository $links,
        private readonly LinkSuggestions $suggestions,
        private readonly StreamRepository $stream,
        private readonly PersonLinkDismissalRepository $dismissals,
        private readonly ForgeUserSync $forgeSync,
        private readonly GerritAccountLookup $gerritLookup,
        private readonly PersonLinkCleanup $linkCleanup,
        private readonly AccountRequestRepository $accountRequests,
        private readonly QueuedJobRepository $jobQueue,
    ) {
    }

    /**
     * The search of a person, and the accounts that may belong to another person, by reason: the first ones, more
     * on request. With "view" "linked" the accounts linked already instead, with "view" "no-username" the
     * Forge accounts without a username. A pick in the search opens the person.
     */
    #[Route(name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $picked = trim($request->query->getString('person'));
        if ('' !== $picked) {
            return $this->redirectToRoute('management_people_show', ['key' => $picked]);
        }
        $all = $this->suggestions->suggestions();
        $counts = [];
        foreach (LinkReasonEnum::cases() as $reason) {
            $counts[$reason->value] = count(array_filter(
                $all,
                static fn (LinkSuggestion $suggestion): bool => $suggestion->reason === $reason,
            ));
        }
        $view = $request->query->getString('view');
        $view = in_array($view, [self::VIEW_LINKED, self::VIEW_NO_USERNAME], true) ? $view : 'suggestions';
        $links = $this->links->latest();
        $withoutUsername = $this->accountsWithoutUsername($links);
        $reason = LinkReasonEnum::tryFrom($request->query->getString('reason'))
            ?? (0 === $counts[LinkReasonEnum::RENAMED->value] ? LinkReasonEnum::SAME_NAME : LinkReasonEnum::RENAMED);
        $limit = max(self::SUGGESTIONS, $request->query->getInt('limit', self::SUGGESTIONS));
        $ofReason = array_values(array_filter(
            $all,
            static fn (LinkSuggestion $suggestion): bool => $suggestion->reason === $reason,
        ));
        $shown = 'suggestions' === $view ? array_slice($ofReason, 0, $limit) : [];
        $withoutUsernameShown = self::VIEW_NO_USERNAME === $view ? array_slice($withoutUsername, 0, $limit) : [];

        return $this->render('management/people/index.html.twig', [
            'view' => $view,
            'links' => self::VIEW_LINKED === $view ? $this->linkedPairs($links) : [],
            'linkedCount' => count($links),
            'withoutUsername' => $withoutUsernameShown,
            'withoutUsernameCount' => count($withoutUsername),
            'groups' => array_combine(
                array_map(static fn (User $user): string => $user->id(), $withoutUsernameShown),
                array_map(self::groupOf(...), $withoutUsernameShown),
            ),
            'requested' => self::VIEW_NO_USERNAME === $view ? $this->accountRequests->accounts() : [],
            'requestedPerRun' => ImportUsersCommand::REQUESTED_PER_RUN,
            'suggestions' => $shown,
            'total' => count($ofReason),
            'reason' => $reason,
            'reasons' => LinkReasonEnum::cases(),
            'counts' => $counts,
            'limit' => $limit,
            'more' => self::SUGGESTIONS,
            'activity' => $this->activityOf([
                ...array_map(static fn (LinkSuggestion $suggestion): User => $suggestion->account, $shown),
                ...$withoutUsernameShown,
            ]),
            'csrfTokenId' => self::CSRF_TOKEN_ID,
        ]);
    }

    /**
     * Links the account of one suggestion to its person: "<account> <person>" in "pair". Each link is confirmed on
     * its own, there is no link of many at once.
     */
    #[Route(path: '/suggestions/link', name: '_link_suggestion', methods: ['POST'])]
    public function linkSuggestion(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        [$account, $person] = $this->pair($request);
        if (null === $this->users->byKey($person)?->username || null === $this->users->byKey($account)) {
            $this->addFlash('danger', 'The suggestion is no longer valid.');

            return $this->backToSuggestions($request);
        }
        $this->links->link($account, $person, $this->admin(), new \DateTimeImmutable());
        $this->directory->forget();
        $this->addFlash('success', sprintf('%s counts as %s now.', $account, $person));

        return $this->backToSuggestions($request);
    }

    /**
     * Turns one suggestion down: the account is not the person's. It does not come again.
     */
    #[Route(path: '/suggestions/dismiss', name: '_dismiss_suggestion', methods: ['POST'])]
    public function dismissSuggestion(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        [$account, $person] = $this->pair($request);
        if ('' !== $account && '' !== $person) {
            $this->dismissals->dismiss($account, $person, $this->admin(), new \DateTimeImmutable());
            $this->addFlash('success', sprintf('%s is not %s: it is not suggested again.', $account, $person));
        }

        return $this->backToSuggestions($request);
    }

    /**
     * A person with the accounts of its document and the ones linked to it. A key of an account linked leads to
     * the person.
     */
    #[Route(path: '/{key}', name: '_show', requirements: ['key' => '[^/]+'], methods: ['GET'])]
    public function show(string $key): Response
    {
        $person = $this->directory->personOf($key);
        if (null !== $person?->username && $person->username !== mb_strtolower($key)) {
            return $this->redirectToRoute('management_people_show', ['key' => $person->username]);
        }
        $document = $this->users->byKey($key);
        if (null === $document) {
            throw $this->createNotFoundException('The index of the users knows no person of this key.');
        }
        $linked = [];
        foreach ($this->links->ofPerson($document->id()) as $link) {
            $linked[] = ['link' => $link, 'document' => $this->users->byKey($link->getAccount())];
        }

        return $this->render('management/people/show.html.twig', [
            'person' => $person,
            'document' => $document,
            'linked' => $linked,
            'activity' => $this->activityOf([
                $document,
                ...array_filter(array_map(static fn (array $entry): ?User => $entry['document'], $linked)),
            ]),
            'canLink' => null !== $document->username,
            'csrfTokenId' => self::CSRF_TOKEN_ID,
        ]);
    }

    /**
     * Links the account in "person" to the person of the key. Only a person with a TYPO3 username takes accounts.
     */
    #[Route(path: '/{key}/link', name: '_link', requirements: ['key' => '[^/]+'], methods: ['POST'])]
    public function link(string $key, Request $request): RedirectResponse
    {
        $this->checkToken($request);

        return $this->linkAccount(
            $this->users->byKey($request->request->getString('person')),
            $this->users->byKey($key),
            $key,
        );
    }

    /**
     * Links the account of the key to the person in "person": for an account without a username, from its own page.
     */
    #[Route(path: '/{key}/link-to', name: '_link_to', requirements: ['key' => '[^/]+'], methods: ['POST'])]
    public function linkTo(string $key, Request $request): RedirectResponse
    {
        $this->checkToken($request);

        return $this->linkAccount(
            $this->users->byKey($key),
            $this->users->byKey($request->request->getString('person')),
            $key,
        );
    }

    /**
     * Links the account to the person and opens the person. On a refusal the page of the key shows why.
     */
    private function linkAccount(?User $account, ?User $person, string $key): RedirectResponse
    {
        if (null === $person?->username) {
            return $this->refuse($key, 'Only a person with a TYPO3 username takes accounts.');
        }
        if (null === $account) {
            return $this->refuse($key, 'The index of the users knows no such account.');
        }
        if ($account->id() === $person->id()) {
            return $this->refuse($key, 'An account cannot be linked to itself.');
        }
        $this->links->link(
            $account->id(),
            $person->id(),
            $this->admin(),
            new \DateTimeImmutable(),
        );
        $this->directory->forget();
        $this->addFlash('success', sprintf('%s counts as %s now.', $account->id(), $person->id()));

        return $this->redirectToRoute('management_people_show', ['key' => $person->id()]);
    }

    /**
     * Takes the account in "account" from the person again: it counts as its own person.
     */
    #[Route(path: '/{key}/unlink', name: '_unlink', requirements: ['key' => '[^/]+'], methods: ['POST'])]
    public function unlink(string $key, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $account = $request->request->getString('account');
        $this->links->unlink($account);
        $this->directory->forget();
        $this->addFlash('success', sprintf('%s counts as its own person again.', $account));
        if (self::VIEW_LINKED === $request->request->getString('back')) {
            return $this->redirectToRoute('management_people', ['view' => self::VIEW_LINKED]);
        }

        return $this->redirectToRoute('management_people_show', ['key' => $key]);
    }

    /**
     * Requests all accounts of one group of the tab "No username" from their source, see groupOf(): "group" "gone",
     * "not-asked" or "gerrit". The job "Update users" asks for them, see ImportUsersCommand::askRequested(). It is
     * queued, so the first ones come within a minute.
     */
    #[Route(path: '/no-username/request', name: '_request_accounts', methods: ['POST'])]
    public function requestAccounts(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $group = $request->request->getString('group');
        $accounts = [];
        foreach ($this->accountsWithoutUsername($this->links->latest()) as $user) {
            if (self::groupOf($user) === $group) {
                $accounts[] = $user->id();
            }
        }
        $now = new \DateTimeImmutable();
        $requested = $this->accountRequests->request($accounts, $this->admin(), $now);
        $this->jobQueue->queue('app:import:users', sprintf('Requested by %s', $this->admin()), $now);
        $this->addFlash('success', sprintf(
            '%d %s. "Update users" starts within a minute and asks for %d per run.',
            $requested,
            1 === $requested ? 'account waits for its source' : 'accounts wait for their source',
            ImportUsersCommand::REQUESTED_PER_RUN,
        ));

        return $this->redirectToRoute('management_people', ['view' => self::VIEW_NO_USERNAME]);
    }

    /**
     * The group of an account without a username: "gone" when Forge does not know it, "not-asked" for another
     * account of Forge, "gerrit" for an account of Gerrit only.
     */
    public static function groupOf(User $user): string
    {
        return match (true) {
            $user->gone => 'gone',
            null !== $user->forgeId => 'not-asked',
            default => 'gerrit',
        };
    }

    /**
     * Asks Forge or Gerrit again for an account of the document of the key, "source" "forge" or "gerrit": for what
     * is missing, like the username of an account Forge was not asked for yet. Forge joins the user to its person by
     * the username, so the page may move to the person.
     */
    #[Route(path: '/{key}/refresh', name: '_refresh', requirements: ['key' => '[^/]+'], methods: ['POST'])]
    public function refresh(string $key, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $document = $this->users->byKey($key);
        if (null === $document) {
            return $this->refuse($key, 'The index of the users knows no person of this key.');
        }
        $now = new \DateTimeImmutable();
        try {
            if ('gerrit' === $request->request->getString('source')) {
                $this->askGerrit($document, $now);
            } else {
                $this->askForge($document, $now);
            }
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', sprintf('The source could not be asked: %s', $e->getMessage()));
        }
        $removed = $this->linkCleanup->removeJoined();
        if ($removed > 0) {
            $this->addFlash('success', sprintf(
                'The sources joined %d linked %s: the %s removed.',
                $removed,
                1 === $removed ? 'account' : 'accounts',
                1 === $removed ? 'link is' : 'links are',
            ));
        }
        $this->directory->forget();
        $moved = match (true) {
            null !== $document->forgeId => $this->directory->keyOf('forge', $document->forgeId),
            null !== $document->gerritId => $this->directory->keyOf('gerrit', $document->gerritId),
            default => null,
        };

        return $this->redirectToRoute('management_people_show', ['key' => $moved ?? $document->id()]);
    }

    private function askForge(User $document, \DateTimeImmutable $now): void
    {
        if (null === $document->forgeId) {
            $this->addFlash('danger', 'This person has no account on Forge.');

            return;
        }
        $result = $this->forgeSync->refresh($document, $now);
        $this->addFlash(...match (true) {
            null !== $result['stopped'] => ['danger', sprintf('Forge did not answer: %s', $result['stopped'])],
            $result['gone'] > 0 => ['warning', sprintf('Forge does not know forge:%d any more.', $document->forgeId)],
            default => ['success', sprintf('Forge told forge:%d again.', $document->forgeId)],
        });
    }

    private function askGerrit(User $document, \DateTimeImmutable $now): void
    {
        if (null === $document->username) {
            $this->askGerritByNumber($document, $now);

            return;
        }
        $user = $this->gerritLookup->lookUp($document, $now, true);
        if (null === $user) {
            $this->addFlash('danger', 'Gerrit did not answer. Try again in a minute.');

            return;
        }
        $this->users->write([$user]);
        $this->addFlash('success', null === $user->gerritId
            ? sprintf('Gerrit has no account of the username %s.', $document->username)
            : sprintf('Gerrit told gerrit:%d again.', $user->gerritId));
    }

    /**
     * Asks Gerrit for an account of Gerrit only, by its number: for its username.
     */
    private function askGerritByNumber(User $document, \DateTimeImmutable $now): void
    {
        if (null === $document->gerritId) {
            $this->addFlash('danger', 'This person has no account on Gerrit and no username to look one up by.');

            return;
        }
        $result = $this->gerritLookup->lookUpNumber($document, $now);
        $this->addFlash(...match (true) {
            null === $result => ['danger', 'Gerrit did not answer. Try again in a minute.'],
            !$result['known'] => ['warning', sprintf('Gerrit does not know gerrit:%d.', $document->gerritId)],
            null === $result['user']->username => [
                'warning',
                sprintf('Gerrit shows no username for gerrit:%d.', $document->gerritId),
            ],
            default => [
                'success',
                sprintf('gerrit:%d is %s on Gerrit.', $document->gerritId, $result['user']->username),
            ],
        });
    }

    /**
     * Each link with the documents of its account and of its person, null where the index has none.
     *
     * @param list<PersonLink> $links
     *
     * @return list<array{link: PersonLink, account: User|null, person: User|null, contradicted: string|null}>
     */
    private function linkedPairs(array $links): array
    {
        $persons = $this->users->byIds(array_map(static fn (PersonLink $link): string => $link->getPerson(), $links));

        return array_map(function (PersonLink $link) use ($persons): array {
            $account = $this->users->byKey($link->getAccount());

            return [
                'link' => $link,
                'account' => $account,
                'person' => $persons[$link->getPerson()] ?? null,
                'contradicted' => PersonLinkCleanup::contradicted($link, $account),
            ];
        }, $links);
    }

    /**
     * The Forge accounts without a username that are not linked to a person, see UserRepository::withoutUsername().
     *
     * @param list<PersonLink> $links
     *
     * @return list<User>
     */
    private function accountsWithoutUsername(array $links): array
    {
        $linked = array_flip(array_map(static fn (PersonLink $link): string => $link->getAccount(), $links));

        return array_values(array_filter(
            $this->users->withoutUsername(),
            static fn (User $user): bool => !isset($linked[$user->id()]),
        ));
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('The form has expired. Load the page again.');
        }
    }

    private function admin(): string
    {
        return (string) $this->getUser()?->getUserIdentifier();
    }

    /**
     * The pair of the form: "<account> <person>" in "pair". Empty strings for none.
     *
     * @return array{0: string, 1: string}
     */
    private function pair(Request $request): array
    {
        $parts = explode(' ', trim($request->request->getString('pair')), 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * The list of suggestions the form was sent from, with its reason and length.
     */
    private function backToSuggestions(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('management_people', array_filter([
            'reason' => $request->request->getString('reason'),
            'limit' => $request->request->getString('limit'),
        ]));
    }

    private function refuse(string $key, string $message): RedirectResponse
    {
        $this->addFlash('danger', $message);

        return $this->redirectToRoute('management_people_show', ['key' => $key]);
    }

    /**
     * How much each account of the documents did.
     *
     * @param list<User> $documents
     *
     * @return array<string, AccountActivity> by "forge:<number>" and "gerrit:<number>"
     */
    private function activityOf(array $documents): array
    {
        $forgeIds = array_values(array_filter(array_map(static fn (User $user): ?int => $user->forgeId, $documents)));
        $gerritIds = array_values(array_filter(array_map(static fn (User $user): ?int => $user->gerritId, $documents)));
        if ([] === $forgeIds && [] === $gerritIds) {
            return [];
        }
        $result = $this->stream->search(AccountActivityQuery::request($forgeIds, $gerritIds));

        return AccountActivity::byAccount(
            $result->aggregation(AccountActivityQuery::FORGE),
            $result->aggregation(AccountActivityQuery::GERRIT),
        );
    }
}
