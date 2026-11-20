<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Http\ApiFailure;
use App\Service\ChangeRefreshResult;
use App\Service\ForgeIssueImporter;
use App\Service\GerritChangeImporter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Loads a single Forge issue or Gerrit change again, for when Forger shows it in an old state and the next
 * import is too far away. For the admins only, see templates/_macros/refresh.html.twig.
 */
#[IsGranted(self::ROLE)]
class RefreshController extends AbstractController
{
    public const string ROLE = 'ROLE_ADMIN';
    public const string CSRF_TOKEN_ID = 'refresh';

    public function __construct(
        private readonly ForgeIssueImporter $issueImporter,
        private readonly GerritChangeImporter $changeImporter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/issue/{id}/refresh', name: 'issue_refresh', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function issue(Request $request, int $id): RedirectResponse
    {
        if ($this->isTokenValid($request)) {
            try {
                $result = $this->issueImporter->refresh($id);
                $this->addFlash($result->flashType(), $result->message($id));
            } catch (\RuntimeException $e) {
                $this->failed(sprintf('Issue #%d', $id), 'Forge', $e);
            }
        }

        return $this->back($request, 'issue_show', ['id' => $id]);
    }

    #[Route(path: '/change/{number}/refresh', name: 'change_refresh', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function change(Request $request, int $number): RedirectResponse
    {
        if ($this->isTokenValid($request)) {
            try {
                $result = $this->changeImporter->refresh($number);
                $this->addFlash($result->flashType(), $result->message($number));
                // Its page is gone with it
                if (ChangeRefreshResult::REMOVED === $result) {
                    return $this->redirectToRoute('search_changes');
                }
            } catch (\RuntimeException $e) {
                $this->failed(sprintf('Change %d', $number), 'Gerrit', $e);
            }
        }

        return $this->back($request, 'change_show', ['number' => $number]);
    }

    private function isTokenValid(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('danger', 'The page was open for too long. Reload it and refresh again.');

        return false;
    }

    private function failed(string $subject, string $source, \RuntimeException $e): void
    {
        $this->logger->warning(sprintf('Refreshing %s from %s failed: %s', $subject, $source, $e->getMessage()), ['exception' => $e]);
        $this->addFlash('danger', $e instanceof ApiFailure && $e->isRateLimit()
            ? sprintf('%s is busy, %s was not refreshed. Try again in a minute.', $source, $subject)
            : sprintf('%s could not be loaded from %s (%s). Forger shows it as before.', $subject, $source, $e->getMessage()));
    }

    /**
     * Back to the page the button was on. Only a path of this site is followed, else the given route.
     *
     * @param array<string, string> $parameters
     */
    private function back(Request $request, string $route, array $parameters = []): RedirectResponse
    {
        $target = $request->getPayload()->getString('_target_path');
        if (self::isLocalPath($target)) {
            return $this->redirect($target);
        }

        return $this->redirectToRoute($route, $parameters);
    }

    public static function isLocalPath(string $target): bool
    {
        // "//host" and "/\host" lead to another site in browsers
        return str_starts_with($target, '/') && !str_starts_with($target, '//') && !str_starts_with($target, '/\\') && !preg_match('/[\x00-\x1f]/', $target);
    }
}
