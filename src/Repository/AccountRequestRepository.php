<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\AccountRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The accounts the admins want asked from their source again, see AccountRequest.
 *
 * @extends ServiceEntityRepository<AccountRequest>
 */
class AccountRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountRequest::class);
    }

    /**
     * Requests the accounts, by "forge:<number>" or "gerrit:<number>". An account requested already keeps its place.
     *
     * @param list<string> $accounts
     *
     * @return int how many were requested now
     */
    public function request(array $accounts, string $requestedBy, \DateTimeImmutable $now): int
    {
        $new = array_values(array_diff(array_unique($accounts), array_keys($this->accounts())));
        $connection = $this->getEntityManager()->getConnection();
        $connection->transactional(static function () use ($new, $requestedBy, $now, $connection): void {
            foreach ($new as $account) {
                $connection->insert('account_request', [
                    'account' => $account,
                    'requested_by' => mb_substr($requestedBy, 0, 255),
                    'requested_at' => $now->format('Y-m-d H:i:s'),
                ]);
            }
        });

        return count($new);
    }

    /**
     * @return list<string> the accounts requested longest ago
     */
    public function oldest(int $limit): array
    {
        $requests = $this->findBy([], ['requestedAt' => 'ASC', 'account' => 'ASC'], max(0, $limit));

        return array_map(static fn (AccountRequest $request): string => $request->getAccount(), $requests);
    }

    /**
     * @return array<string, true> all accounts requested
     */
    public function accounts(): array
    {
        $accounts = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT account FROM account_request',
        );

        return array_fill_keys(array_map('strval', $accounts), true);
    }

    /**
     * @param list<string> $accounts
     */
    public function remove(array $accounts): void
    {
        if ([] === $accounts) {
            return;
        }
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM account_request WHERE account IN (?)',
            [$accounts],
            [ArrayParameterType::STRING],
        );
    }
}
