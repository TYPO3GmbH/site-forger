<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Service\ForgeIssueImporter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Loads a single issue from Forge again, for when the index shows it in an old state and the next import is
 * too far away. For the admins only.
 */
#[IsGranted(self::ROLE)]
class IssueRefreshController extends AbstractController
{
    public const string ROLE = 'ROLE_ADMIN';
    public const string CSRF_TOKEN_ID = 'issue-refresh';

    public function __construct(
        private readonly ForgeIssueImporter $importer,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/issue/{id}/refresh', name: 'issue_refresh', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refresh(Request $request, int $id): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', 'The page was open for too long. Reload it and refresh the issue again.');

            return $this->back($request, $id);
        }

        try {
            $result = $this->importer->refresh($id);
            $this->addFlash($result->flashType(), $result->message($id));
        } catch (\RuntimeException $e) {
            $this->logger->warning(sprintf('Refreshing Forge issue #%d failed: %s', $id, $e->getMessage()));
            $this->addFlash('danger', 429 === $e->getCode()
                ? sprintf('Forge is busy, issue #%d was not refreshed. Try again in a minute.', $id)
                : sprintf('Issue #%d could not be loaded from Forge (%s). Forger shows it as before.', $id, $e->getMessage()));
        }

        return $this->back($request, $id);
    }

    /**
     * Back to the page the button was on. Only a path of this site is followed, else the search for the issue.
     */
    private function back(Request $request, int $id): RedirectResponse
    {
        $target = $request->getPayload()->getString('_target_path');
        if (self::isLocalPath($target)) {
            return $this->redirect($target);
        }

        return $this->redirectToRoute('search_search', ['query' => (string) $id]);
    }

    public static function isLocalPath(string $target): bool
    {
        // "//host" and "/\host" lead to another site in browsers
        return str_starts_with($target, '/') && !str_starts_with($target, '//') && !str_starts_with($target, '/\\') && !preg_match('/[\x00-\x1f]/', $target);
    }
}
