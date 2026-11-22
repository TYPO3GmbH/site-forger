<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Elastic\Bucket;
use App\Entity\Interest;
use App\People\InterestInput;
use App\Repository\InterestRepository;
use App\Repository\IssueRepository;
use App\Search\OverviewQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The interests of the user: add, change and remove them. The overview of My Work lists their changes and issues,
 * see MyWorkController::overview().
 */
#[Route(path: '/my/interests', name: 'my_interests_')]
#[IsGranted('ROLE_USER')]
class InterestController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'interest';

    public function __construct(
        private readonly InterestRepository $interests,
        private readonly IssueRepository $issues,
    ) {
    }

    #[Route(path: '/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if (count($this->interests->ofOwner($this->owner())) >= InterestRepository::LIMIT) {
            $this->addFlash('danger', sprintf('You can have up to %d interests.', InterestRepository::LIMIT));

            return $this->redirectToRoute('my_interests');
        }

        return $this->edit($request, null);
    }

    #[Route(path: '/{id<\d+>}', name: 'edit', methods: ['GET', 'POST'])]
    public function change(Request $request, int $id): Response
    {
        $interest = $this->interests->ofOwnerById($this->owner(), $id);
        if (null === $interest) {
            throw $this->createNotFoundException('You have no such interest.');
        }

        return $this->edit($request, $interest);
    }

    #[Route(path: '/{id<\d+>}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $interest = $this->interests->ofOwnerById($this->owner(), $id);
        $token = $request->getPayload()->getString('_token');
        if (null !== $interest && $this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            $this->interests->remove($interest);
        }

        return $this->redirectToRoute('my_interests');
    }

    /**
     * Shows the form, and saves what it sends when it is fine.
     */
    private function edit(Request $request, ?Interest $interest): Response
    {
        $categories = $this->categories();
        $input = null === $interest ? InterestInput::empty() : InterestInput::of($interest);
        if ($request->isMethod('POST')) {
            $input = InterestInput::fromForm($request->request, $categories);
            if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
                $this->addFlash('danger', 'The form expired. Send it again.');
            } elseif ($input->isValid()) {
                $saved = $this->save($interest, $input);

                return $this->redirectToRoute('my_interests', ['interest' => $saved->getId()]);
            }
        }

        return $this->render('my/interest.html.twig', [
            'interest' => $interest,
            'input' => $input,
            'categories' => $categories,
            'csrfTokenId' => self::CSRF_TOKEN_ID,
            'maxPaths' => InterestInput::MAX_PATHS,
        ]);
    }

    private function save(?Interest $interest, InterestInput $input): Interest
    {
        if (null === $interest) {
            $interest = new Interest(
                $this->owner(),
                $input->name,
                $input->paths,
                $input->categories,
                new \DateTimeImmutable(),
            );
        } else {
            $interest->change($input->name, $input->paths, $input->categories);
        }
        $this->interests->save($interest);

        return $interest;
    }

    /**
     * The categories of the issues, by name.
     *
     * @return list<string>
     */
    private function categories(): array
    {
        $buckets = Bucket::listOf($this->issues->search(OverviewQuery::categories())->aggregation('categories'));
        $names = array_map(strval(...), $buckets->getKeys());
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    private function owner(): string
    {
        return (string) $this->getUser()?->getUserIdentifier();
    }
}
