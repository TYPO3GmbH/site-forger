<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\People\FollowedPerson;
use App\People\Following;
use App\People\OwnAccounts;
use App\People\PeopleActivity;
use App\People\UserDirectory;
use App\Repository\FollowRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use T3G\Bundle\Keycloak\Security\KeyCloakUser;

/**
 * Follow a person and stop it: the button of the activity asks and sets it with JSON, see
 * assets/ts/activity/follow.ts. The page of the people followed sends a form. MyWorkController::following() shows
 * where the people need help and what they did.
 */
#[Route(path: '/my/following', name: 'my_following_')]
#[IsGranted('ROLE_USER')]
class FollowController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'follow';

    /**
     * The new contributors the page suggests to follow.
     */
    private const int NEWCOMERS = 6;

    public function __construct(
        private readonly FollowRepository $follows,
        private readonly UserDirectory $users,
        private readonly Following $following,
        private readonly OwnAccounts $ownAccounts,
    ) {
    }

    /**
     * The people the user follows, each as a card with what the person did lately, to add and to remove them, and
     * the new contributors to follow.
     */
    #[Route(path: '/manage', name: 'manage', methods: ['GET'])]
    public function manage(): Response
    {
        return $this->render('my/people.html.twig', [
            'people' => $this->following->peopleWithActivityOf($this->follower(), new \DateTimeImmutable()),
            'range' => PeopleActivity::RANGE,
            'newcomers' => $this->following->newcomers($this->follower(), self::NEWCOMERS),
            'searchUrl' => $this->generateUrl('activity_people'),
            'limit' => FollowRepository::LIMIT,
            'csrfTokenId' => self::CSRF_TOKEN_ID,
        ]);
    }

    /**
     * The people the user follows, for the menu of people of the activity. Names and avatars only, and every key
     * the filter may name the person by, see UserDirectory::keysOf().
     */
    #[Route(path: '/people', name: 'people', methods: ['GET'])]
    public function people(): JsonResponse
    {
        return new JsonResponse(array_map(
            fn (FollowedPerson $person): array => [
                'key' => $person->key,
                'keys' => $this->users->keysOf($person->key),
                'name' => $person->name,
                'avatar' => $person->avatar,
            ],
            $this->following->peopleOf($this->follower()),
        ));
    }

    /**
     * Whether the user follows the person of "person".
     */
    #[Route(path: '/state', name: 'state', methods: ['GET'])]
    public function state(Request $request): JsonResponse
    {
        $person = $this->users->storedKeyOf($request->query->getString('person'));
        if (null === $person) {
            return new JsonResponse(['error' => 'Only a known person can be followed.'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'person' => $person,
            'following' => $this->follows->isFollowing($this->follower(), $person),
            'self' => $this->isSelf($person),
        ]);
    }

    /**
     * Follows the person of "person" with "following" 1, else stops following it.
     */
    #[Route(path: '/state', name: 'set', methods: ['POST'])]
    public function set(Request $request): Response
    {
        $payload = $request->getPayload();
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $payload->getString('_token'))) {
            return $this->answer($request, 'The form expired. Load the page again.', Response::HTTP_FORBIDDEN);
        }
        $person = $this->users->storedKeyOf($payload->getString('person'));
        if (null === $person) {
            return $this->answer($request, 'Only a known person can be followed.', Response::HTTP_BAD_REQUEST);
        }
        $follower = $this->follower();
        if ('1' === $payload->getString('following') && $this->isSelf($person)) {
            return $this->answer($request, 'You cannot follow yourself.', Response::HTTP_BAD_REQUEST);
        }
        if ('1' !== $payload->getString('following')) {
            $this->follows->unfollow($follower, $person);
        } elseif (!$this->follows->follow($follower, $person, new \DateTimeImmutable())) {
            $message = sprintf('You follow %d people already.', FollowRepository::LIMIT);

            return $this->answer($request, $message, Response::HTTP_CONFLICT);
        }
        if ('json' !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('my_following_manage');
        }

        return new JsonResponse([
            'person' => $person,
            'following' => $this->follows->isFollowing($follower, $person),
            'self' => false,
        ]);
    }

    private function isSelf(string $person): bool
    {
        $user = $this->getUser();
        $email = $user instanceof KeyCloakUser ? (string) $user->getEmail() : '';
        $name = $user instanceof KeyCloakUser ? (string) $user->getDisplayName() : '';

        return $this->ownAccounts->isSelf($this->follower(), $email, $name, $person);
    }

    private function follower(): string
    {
        return (string) $this->getUser()?->getUserIdentifier();
    }

    /**
     * The button reads the error from JSON. The forms of the page of the people go back to it and show the message
     * there.
     */
    private function answer(Request $request, string $error, int $status): Response
    {
        if ('json' === $request->getPreferredFormat()) {
            return new JsonResponse(['error' => $error], $status);
        }
        $this->addFlash('danger', $error);

        return $this->redirectToRoute('my_following_manage');
    }
}
