<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Setup;

use App\Http\ApiClient;
use App\Http\ApiFailure;
use App\Import\ForgeIssueList;
use App\Repository\UserRepository;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Elastica\Exception\ExceptionInterface as ElasticaException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Who Forge logs Forger in as with FORGE_ACCESS_TOKEN, for the system page.
 *
 * Forge answers 401 without a valid token. It still shows public issues then, but no users.
 *
 * Forge shows a user only to admins and to members of a project: a role in a project lets its members see all
 * active users. To anyone else Forge answers 404 for most users, and ForgeUserSync marks them as gone. So the
 * account must be a member of the project Forger reads (redmine_project_id).
 *
 * The role in the project must also let the account view the issues. A role without that permission sees less
 * than the public: Forge answers 403 for the issues of the project. So check() asks Forge for the issues as the
 * import does, and for the user Forge answered last as the user sync does.
 *
 * @phpstan-type ForgeCheck array{
 *     account: string|null,
 *     error: string|null,
 *     project?: string,
 *     membersUrl?: string,
 *     issues?: string,
 *     users?: string,
 * }
 */
final readonly class ForgeLogin
{
    /**
     * Where the managers of a project add members, by the identifier or number of the project.
     */
    public const string MEMBERS_URL = 'https://forge.typo3.org/projects/%s/settings/members';

    public function __construct(
        private ForgeIssueList $issues,
        private ApiClient $forge,
        private UserRepository $users,
        #[Autowire('%redmine_project_id%')]
        private int $projectId,
    ) {
    }

    /**
     * The account, like "benjaminkott (Benjamin Kott)", or why Forge did not log in, see SetupCheck. "project" names
     * the project Forger reads, like "TYPO3 Core (typo3cms-core)", if the account is no member and no admin.
     * "membersUrl" is where to add the account to it then. "issues" and "users" tell what keeps the account from the
     * issues or the users of the project.
     *
     * @return ForgeCheck
     */
    public function check(): array
    {
        try {
            $user = $this->forge->object(
                '/users/current.json',
                ['include' => 'memberships'],
                'the current user',
            )['user'] ?? null;
        } catch (ApiFailure $e) {
            $error = 401 === $e->status
                ? 'Forge does not accept the token (401).'
                : 'Forge could not be asked: ' . $e->getMessage();

            return ['account' => null, 'error' => $error];
        }
        if (!is_array($user) || !isset($user['login'])) {
            return ['account' => null, 'error' => 'Forge did not name the user.'];
        }
        $name = trim(sprintf('%s %s', $user['firstname'] ?? '', $user['lastname'] ?? ''));
        $login = ['account' => $user['login'] . ('' === $name ? '' : ' (' . $name . ')'), 'error' => null];
        $issues = $this->issuesProblem();
        if (null !== $issues) {
            $login['issues'] = $issues;
        }
        if (true === ($user['admin'] ?? false) || $this->isMember($user)) {
            $users = $this->usersProblem();

            return null === $users ? $login : [...$login, 'users' => $users];
        }

        $project = $this->project();
        $projectName = null === $project
            ? '#' . $this->projectId
            : sprintf('%s (%s)', $project['name'], $project['identifier']);

        return [
            ...$login,
            'project' => $projectName,
            'membersUrl' => sprintf(self::MEMBERS_URL, $project['identifier'] ?? $this->projectId),
        ];
    }

    /**
     * Why Forge does not list the issues of the project as the import asks for them, null if it does.
     */
    private function issuesProblem(): ?string
    {
        try {
            return $this->issues->accessProblem();
        } catch (ApiFailure $e) {
            return 'Forge could not be asked for the issues: ' . $e->getMessage();
        }
    }

    /**
     * Why Forge hides the user it answered for last, null if it shows the user or no user was asked for yet.
     *
     * Forge answers 403 for a locked user, so only 404 counts.
     */
    private function usersProblem(): ?string
    {
        try {
            $user = $this->users->lastFetched();
        } catch (ElasticaException|ElasticsearchException|TransportException) {
            return null;
        }
        if (null === $user || null === $user->forgeId) {
            return null;
        }
        try {
            $this->forge->object(sprintf('/users/%d.json', $user->forgeId), [], 'the user');
        } catch (ApiFailure $e) {
            if (404 !== $e->status) {
                return null;
            }

            return sprintf(
                'Forge hides the user #%d (%s) from the account (404), but it showed the user on %s.'
                . ' The role of the account in the project must see all active users.',
                $user->forgeId,
                $user->forgeName,
                $user->fetchedOn?->format('Y-m-d') ?? 'an earlier run',
            );
        }

        return null;
    }

    /**
     * @param array<mixed> $user
     */
    private function isMember(array $user): bool
    {
        $memberships = $user['memberships'] ?? [];
        foreach (is_array($memberships) ? $memberships : [] as $membership) {
            if (is_array($membership) && $this->projectId === (int) ($membership['project']['id'] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name and identifier of the project, null if Forge does not tell them.
     *
     * @return array{name: string, identifier: string}|null
     */
    private function project(): ?array
    {
        try {
            $project = $this->forge->object(
                sprintf('/projects/%d.json', $this->projectId),
                [],
                'the project',
            )['project'] ?? null;
        } catch (ApiFailure) {
            return null;
        }
        if (!is_array($project) || !is_string($project['name'] ?? null) || !is_string($project['identifier'] ?? null)) {
            return null;
        }

        return ['name' => $project['name'], 'identifier' => $project['identifier']];
    }
}
