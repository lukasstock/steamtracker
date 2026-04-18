<?php

namespace App\Repository;

use App\Entity\GameCompletion;
use App\Enum\GameStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameCompletion>
 */
class GameCompletionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameCompletion::class);
    }

    /**
     * @return array<int, GameCompletion> keyed by appId
     */
    public function findAllIndexedByAppId(string $userToken): array
    {
        $results = $this->findBy(['userToken' => $userToken]);
        $map = [];
        foreach ($results as $completion) {
            $map[$completion->getAppId()] = $completion;
        }
        return $map;
    }

    public function findOneByAppIdAndToken(int $appId, string $userToken): ?GameCompletion
    {
        return $this->findOneBy(['appId' => $appId, 'userToken' => $userToken]);
    }

    /**
     * @param string[] $userTokens
     * @return array<string, GameCompletion> keyed by userToken
     */
    public function findByAppIdAndTokens(int $appId, array $userTokens): array
    {
        if (empty($userTokens)) {
            return [];
        }

        $results = $this->createQueryBuilder('gc')
            ->where('gc.appId = :appId')
            ->andWhere('gc.userToken IN (:tokens)')
            ->setParameter('appId', $appId)
            ->setParameter('tokens', $userTokens)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($results as $completion) {
            $map[$completion->getUserToken()] = $completion;
        }
        return $map;
    }

    /**
     * Returns count of completed games per userToken for the given set of tokens.
     *
     * @param string[] $userTokens
     * @return array<string, int> keyed by userToken
     */
    public function getCompletedCountsByTokens(array $userTokens): array
    {
        if (empty($userTokens)) {
            return [];
        }

        $rows = $this->createQueryBuilder('gc')
            ->select('gc.userToken, COUNT(gc.id) as completedCount')
            ->where('gc.userToken IN (:tokens)')
            ->andWhere('gc.status = :status')
            ->setParameter('tokens', $userTokens)
            ->setParameter('status', GameStatus::Completed)
            ->groupBy('gc.userToken')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['userToken']] = (int) $row['completedCount'];
        }
        return $result;
    }

    /**
     * Claim all rows that have no userToken yet (existing data from before multi-user migration).
     */
    public function claimUnownedRows(string $userToken): void
    {
        $this->getEntityManager()
            ->createQuery('UPDATE App\Entity\GameCompletion gc SET gc.userToken = :token WHERE gc.userToken IS NULL')
            ->setParameter('token', $userToken)
            ->execute();
    }
}
