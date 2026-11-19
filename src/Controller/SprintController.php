<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Elastic\ElasticUtility;
use App\Entity\SprintBoard;
use App\Repository\SprintBoardCategoryRepository;
use App\Repository\SprintBoardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

class SprintController extends AbstractController
{
    public function __construct(
        private readonly SprintBoardCategoryRepository $sprintBoardCategoryRepository,
        private readonly SprintBoardRepository $sprintBoardRepository,
        private readonly ParameterBagInterface $parameters,
        private readonly ManagerRegistry $managerRegistry
    ) {
    }

    #[Route(path: '/sprint/list', name: 'sprint_list')]
    #[IsGranted('ROLE_ADMIN')]
    public function list(): Response
    {
        return $this->render('management/sprint/index.html.twig', [
            'items' => $this->sprintBoardRepository->findAll(),
        ]);
    }

    #[Route(path: '/sprint/reviews/{reviewType}', name: 'sprint_reviews', defaults: ['reviewType' => 'bugfix'])]
    public function reviewSprintAction(string $reviewType): Response
    {
        $reviewBoardData = $this->getReviewBoardData($reviewType);

        return $this->render('sprint/review.html.twig', [
            'menu' => $this->makeReviewMenu($reviewType),
            'cards' => $reviewBoardData,
        ]);
    }

    #[Route(path: '/sprint/create', name: 'sprint_create')]
    #[IsGranted('ROLE_ADMIN')]
    public function createSprint(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $sprint = new SprintBoard();
        $form = $this->createForm(\App\Form\SprintBoard::class, $sprint, ['entity_manager' => $this->managerRegistry->getManager()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $category = $sprint->getCategory();
            if (null !== $category) {
                $sprint->setCategory($category);
                $entityManager->persist($sprint);
                $entityManager->flush();

                $this->addFlash('success', 'Sprint board created.');

                return $this->redirectToRoute('sprint', ['category' => $category->getIdentifier(), 'sprintId' => $sprint->getId()]);
            }
        }

        return $this->render('sprint/form.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/sprint/edit/{sprintId}', name: 'sprint_edit')]
    #[IsGranted('ROLE_ADMIN')]
    public function editSprint(
        Request $request,
        int $sprintId,
        EntityManagerInterface $entityManager
    ): Response {
        $sprint = $this->sprintBoardRepository->find($sprintId);

        if (null === $sprint) {
            return $this->redirectToRoute('sprint_create');
        }

        $form = $this->createForm(\App\Form\SprintBoard::class, $sprint, ['entity_manager' => $this->managerRegistry->getManager()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($sprint);
            $entityManager->flush();

            $this->addFlash('success', 'Sprint board edited.');

            return $this->redirectToRoute('sprint', ['category' => $sprint->getCategory()->getIdentifier(), 'sprintId' => $sprint->getId()]);
        }

        return $this->render('sprint/form.html.twig', [
            'form' => $form,
            'edit' => true,
        ]);
    }

    #[Route(path: '/sprint/delete/{sprintId}', name: 'sprint_delete')]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteSprint(
        int $sprintId,
        EntityManagerInterface $entityManager
    ): Response {
        $sprint = $this->sprintBoardRepository->find($sprintId);

        if (null !== $sprint) {
            $entityManager->remove($sprint);
            $entityManager->flush();
            $this->addFlash('success', 'Sprint deleted.');
        }

        return $this->redirectToRoute('sprint_list');
    }

    #[Route(path: '/sprint/delete/{sprintId}/confirm', name: 'sprint_delete_confirm')]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteSprintConfirm(
        int $sprintId
    ): Response {
        $sprint = $this->sprintBoardRepository->find($sprintId);

        if (null !== $sprint) {
            return $this->render('sprint/delete_confirm.html.twig', [
                'sprint' => $sprint,
            ]);
        }

        return $this->redirectToRoute('dashboard_index');
    }

    protected function getReviewBoardData(string $boardId = ''): array
    {
        if (in_array($boardId, ['bugfix', 'feature', 'task'], true)) {
            $query = $this->getReviewBoardQuery($boardId);
            $out = [
                'missesBoth' => [],
                'missesCodeReview' => [],
                'missesTesting' => [],
                'hasPlusOne' => [],
                'mergeConflict' => [],
                'blocked' => [],
            ];
            $fullRequest = [
                'query' => $query,
                'size' => 500,
                'sort' => [
                    [
                        'updated_on' => [
                            'order' => 'asc',
                        ],
                    ],
                ],
            ];
            $resultSet = ElasticUtility::getIndexByName('review')
                ->createSearch($fullRequest)->search();
            foreach ($resultSet->getResults() as $review) {
                $getBoardGroup = $this->defineReviewBoardGroup($review->getSource());
                $out[$getBoardGroup][] = $review->getSource();
            }

            return $out;
        }

        return [];
    }

    /**
     * @throws \Exception
     */
    #[Route(path: '/sprint/{category}/{sprintId}', name: 'sprint', defaults: ['category' => 'index'])]
    public function index(string $category, ?int $sprintId = null): Response
    {
        $sprintCategory = $this->sprintBoardCategoryRepository->findOneBy([
            'identifier' => $category,
        ]);
        // Exit early if no category could be found
        if (!$sprintCategory) {
            return new Response('Sprint board not found', Response::HTTP_NOT_FOUND);
        }
        $sprints = $this->sprintBoardRepository->findBy(
            ['category' => $sprintCategory],
            ['sorting' => 'ASC']
        );
        // Find active Sprint
        $activeSprintId = $sprintId ?? 0;
        $cards = [];
        $boardInfo = [];
        $progress = [];
        $currentSprint = '';
        if ($activeSprintId > 0) {
            foreach ($sprints as $sprint) {
                if ($sprint->getId() === $activeSprintId) {
                    $currentSprint = $sprint;
                }
            }
            if ($currentSprint instanceof SprintBoard) {
                $cards = $this->getBoardData($currentSprint);
                $boardInfo = $currentSprint->getName();
                $ticketCount = [];
                foreach ($cards as $status => $tickets) {
                    $ticketCount[$status] = ($tickets ? is_countable($tickets) ? count($tickets) : 0 : 0);
                }
                $progress = $this->calculateProgressBars($ticketCount);
            }
        }

        return $this->render('sprint/index.html.twig', [
            'menu' => $this->createMenu($sprints, $activeSprintId),
            'cards' => $cards,
            'boardInfo' => $boardInfo,
            'progress' => $progress,
            'category' => $category,
            'sprintId' => $activeSprintId,
        ]);
    }

    protected function getReviewBoardQuery(string $boardId): array
    {
        return [
            'bool' => [
                'must' => [
                    [
                        'terms' => [
                            'reviewtags' => [
                                strtoupper($boardId),
                            ],
                        ],
                    ],
                    [
                        'query_string' => [
                            'default_field' => 'reviewstatus',
                            'query' => 'NEW',
                        ],
                    ],
                ],
                'must_not' => [
                    [
                        'term' => [
                            'work_in_progress' => true,
                        ],
                    ],
                ],

            ],
        ];
    }

    /**
     * Generates a list of boards to link to.
     */
    protected function makeReviewMenu(string $active = ''): array
    {
        return [
            [
                'id' => 'bugfix',
                'count' => $this->getReviewsByBoardQuery($this->getReviewBoardQuery('bugfix')),
                'name' => 'Bugfixes',
                'active' => 'bugfix' === $active,
            ],
            [
                'id' => 'task',
                'count' => $this->getReviewsByBoardQuery($this->getReviewBoardQuery('task')),
                'name' => 'Tasks',
                'active' => 'task' === $active,
            ],
            [
                'id' => 'feature',
                'count' => $this->getReviewsByBoardQuery($this->getReviewBoardQuery('feature')),
                'name' => 'Features',
                'active' => 'feature' === $active,
            ],
        ];
    }

    private function getReviewsByBoardQuery(array $query): int
    {
        $fullRequest = [
            'query' => $query,
            'size' => 0,
        ];
        $resultSet = ElasticUtility::getIndexByName('review')
            ->createSearch($fullRequest)->search();

        return $resultSet->getTotalHits();
    }

    private function defineReviewBoardGroup(array $review): string
    {
        return match (true) {
            'no' === $review['mergeable'] => 'mergeConflict',
            0 === $review['positive_reviews']
                && 0 === $review['positive_verifications']
                && 0 === $review['negative_reviews']
                && 0 === $review['negative_verifications'] => 'missesBoth',
            $review['positive_reviews'] > 0
                && 0 === $review['positive_verifications']
                && 0 === $review['negative_reviews']
                && 0 === $review['negative_verifications'] => 'missesTesting',
            0 === $review['positive_reviews']
                && $review['positive_verifications'] > 0
                && 0 === $review['negative_reviews']
                && 0 === $review['negative_verifications'] => 'missesCodeReview',
            $review['positive_reviews'] > 0
                && $review['positive_verifications'] > 0
                && 0 === $review['negative_reviews']
                && 0 === $review['negative_verifications'] => 'hasPlusOne',
            default => 'blocked',
        };
    }

    private function createMenu(array $sprints, int $activeId): array
    {
        $menu = [];
        /** @var SprintBoard $sprint */
        foreach ($sprints as $sprint) {
            $resultCount = $this->getTicketByBoardQuery($sprint->getQuery());
            $menuItem = [
                'id' => $sprint->getId(),
                'title' => $sprint->getName(),
                'active' => $activeId === $sprint->getId(),
                'count' => $resultCount,

            ];
            $menu[] = $menuItem;
        }

        return $menu;
    }

    private function getTicketByBoardQuery(string $query): array
    {
        $query = Yaml::parse($query);
        $fullRequest = [
            'query' => $query,
            'size' => 0,
            'aggregations' => [
                'status' => [
                    'terms' => [
                        'field' => 'status.id',
                    ],
                ],
            ],
        ];
        $resultSet = ElasticUtility::getIndexByName('issue')
            ->createSearch($fullRequest)->search();
        $count = [
            'open' => 0,
            'closed' => 0,
            'blocked' => 0,
            'review' => 0,
            'total' => 0,
        ];
        foreach ((array) $resultSet->getAggregation('status')['buckets'] as $bucket) {
            match ($bucket['key']) {
                3, 5, 6 => $count['closed'] += $bucket['doc_count'],
                8 => $count['review'] += $bucket['doc_count'],
                4, 7 => $count['blocked'] += $bucket['doc_count'],
                default => $count['open'] += $bucket['doc_count'],
            };
            $count['total'] += $bucket['doc_count'];
        }
        if ($count['total'] > 0) {
            $count['reviewPercentage'] = round($count['review'] * 100.0 / $count['total']);
            if ($count['reviewPercentage'] < 1) {
                unset($count['reviewPercentage']);
            }
            $count['blockedPercentage'] = round($count['blocked'] * 100.0 / $count['total']);
            if ($count['blockedPercentage'] < 1) {
                unset($count['blockedPercentage']);
            }
            $count['percentage'] = round($count['closed'] * 100.0 / $count['total']);
            if ($count['percentage'] < 1 && $count['total'] < 1) {
                unset($count['percentage']);
            }
        }

        return $count;
    }

    private function getBoardData(SprintBoard $sprint): array
    {
        /** @var array $out */
        $out = $this->parameters->get('ColumnOrder');
        $fullRequest = [
            'query' => Yaml::parse($sprint->getQuery()),
            'size' => 10000,
        ];
        $resultSet = ElasticUtility::getIndexByName('issue')
            ->createSearch($fullRequest)->search();
        foreach ($resultSet->getResults() as $ticket) {
            $status = $ticket->getSource()['status'];
            $getBoardGroup = $this->defineBoardGroup($status['name']);
            // Issues in review show their Gerrit review, if it is known
            $out[$getBoardGroup][] = ('Review' === $getBoardGroup ? $this->getReviewCard($ticket->getData()) : null) ?? $ticket->getData();
        }

        return $out;
    }

    /**
     * The Gerrit review linked in the journal of an issue, null if there is none or it was not imported.
     */
    private function getReviewCard(array $ticket): ?array
    {
        $notes = '';
        foreach ((array) ($ticket['journals'] ?? []) as $journalEntry) {
            if (isset($journalEntry['notes'])
                && (
                    str_contains((string) $journalEntry['notes'], 'It is available at http://review.typo3.org/')
                    || str_contains((string) $journalEntry['notes'], 'It is available at https://review.typo3.org/')
                )
            ) {
                $notes = $journalEntry['notes'];
            }
        }
        $pattern = '|.*https?:\/\/review\.typo3\.org\/(?>(c\/)?)(?>Packages\/TYPO3\.CMS\/\+\/)?(?<reviewId>[\d]{2,6})|';
        if ('' === $notes || 1 !== preg_match($pattern, (string) $notes, $matches)) {
            return null;
        }

        try {
            $review = ElasticUtility::getIndexByName('review')->getDocument($matches['reviewId'])->getData();
        } catch (\Exception) {
            return null;
        }
        $review['_redmine_issue_id'] = $ticket['id'];

        return $review;
    }

    private function defineBoardGroup(string $status): string
    {
        return array_key_exists($status, $this->parameters->get('StatusMapping'))
            ? $this->parameters->get('StatusMapping')[$status]
            : $this->parameters->get('StatusMapping')['DefaultState'];
    }

    private function calculateProgressBars(array $data): array
    {
        $out = [];
        $fullCount = array_sum($data);
        $percentage = ($fullCount / 100) ?: 1;
        foreach ($data as $key => $count) {
            $lowerKey = strtolower($key);
            $out['sections'][$lowerKey]['classes'] = $lowerKey;
            if ('WIP' === $key) {
                $out['sections'][$lowerKey]['classes'] = 'wip active progress-bar-striped';
            }

            $out['sections'][$lowerKey]['value'] = ($count / $percentage);
            $out['sections'][$lowerKey]['label'] = $key;
        }
        $out['total'] = $fullCount;

        return $out;
    }
}
