<?php

namespace App\Repository;

use App\Entity\AchievementCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AchievementCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AchievementCache::class);
    }

    public function findOneByAppIdAndSteamId(int $appId, string $steamId): ?AchievementCache
    {
        return $this->findOneBy(['appId' => $appId, 'steamId' => $steamId]);
    }

    /**
     * Returns array keyed by appId for a given steamId and set of app IDs.
     *
     * @param int[] $appIds
     * @return array<int, AchievementCache>
     */
    public function findByAppIdsAndSteamId(array $appIds, string $steamId): array
    {
        if (empty($appIds)) {
            return [];
        }

        $results = $this->createQueryBuilder('a')
            ->where('a.appId IN (:ids)')
            ->andWhere('a.steamId = :steamId')
            ->setParameter('ids', $appIds)
            ->setParameter('steamId', $steamId)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($results as $entry) {
            $map[$entry->getAppId()] = $entry;
        }
        return $map;
    }
}
