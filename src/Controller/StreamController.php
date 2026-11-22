<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\People\Person;
use App\People\User;
use App\People\UserDirectory;
use App\Repository\StreamRepository;
use App\Repository\UserRepository;
use App\Search\PeopleQuery;
use App\Stream\StreamPeople;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The activity stream: what happens on Forge and in Gerrit, as a list in the order of time. The page is a frame: the
 * element <forger-activity> renders it with the API, see assets/ts/activity/ and ApiV1Controller::activity(). The
 * filter is in the address, so a view can be shared. The statistics of the same events are on the page of
 * DashboardController::statistics().
 */
#[Route(path: '/activity', name: 'activity_')]
class StreamController extends AbstractController
{
    /**
     * The events the dashboard shows.
     */
    public const int DASHBOARD_COUNT = 6;

    private const int AVATAR_SIZE = 48;

    public function __construct(
        private readonly StreamRepository $stream,
        private readonly UserDirectory $users,
        private readonly UserRepository $accounts,
    ) {
    }

    #[Route(name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('stream/page.html.twig', ['view' => 'stream']);
    }

    /**
     * The people of the activity whose name or username matches "term", for the menu of people of the filter and
     * the search of My Work. Names, keys and avatars only, no email addresses.
     */
    #[Route(path: '/people', name: 'people', methods: ['GET'])]
    public function people(Request $request): JsonResponse
    {
        $term = $request->query->getString('term');
        if (mb_strlen(trim($term)) < PeopleQuery::MIN_LENGTH) {
            return new JsonResponse([]);
        }
        $accounts = $this->accounts->byUsernamePrefix($term, PeopleQuery::SIZE);
        $search = PeopleQuery::search(
            $term,
            array_values(array_filter(array_map(static fn (User $user): ?int => $user->forgeId, $accounts))),
            array_values(array_filter(array_map(static fn (User $user): ?int => $user->gerritId, $accounts))),
        );
        if (null === $search) {
            return new JsonResponse([]);
        }
        $people = [];
        foreach (StreamPeople::found($this->stream->search($search)->aggregation(PeopleQuery::PEOPLE)) as $person) {
            $key = $this->keyOf($person);
            if (null !== $key) {
                $people[$key] ??= [
                    'key' => $key,
                    'name' => $person->name,
                    'avatar' => $this->users->avatarOfPerson($person, self::AVATAR_SIZE),
                ];
            }
        }

        return new JsonResponse(array_values($people));
    }

    /**
     * The key of the person by its numbers: a person known by the name alone cannot be filtered or followed.
     */
    private function keyOf(Person $person): ?string
    {
        if (null === $person->forgeId && null === $person->gerritId) {
            return null;
        }

        return $this->users->keyOfPerson($person);
    }

    /**
     * The statistics had their place here once: shared links keep their filter.
     */
    #[Route(path: '/statistics', name: 'statistics', methods: ['GET'])]
    public function statistics(Request $request): RedirectResponse
    {
        $query = $request->getQueryString();
        $url = $this->generateUrl('dashboard_statistics') . (null === $query ? '' : '?' . $query);

        return $this->redirect($url, Response::HTTP_MOVED_PERMANENTLY);
    }
}
