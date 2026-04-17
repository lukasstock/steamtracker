<?php

namespace App\Repository;

use App\Entity\HltbCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class HltbCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HltbCache::class);
    }

    /**
     * Returns array keyed by appId for the given set of app IDs.
     *
     * @param int[] $appIds
     * @return array<int, HltbCache>
     */
    public function findByAppIds(array $appIds): array
    {
        if (empty($appIds)) {
            return [];
        }

        $results = $this->createQueryBuilder('h')
            ->where('h.appId IN (:ids)')
            ->setParameter('ids', $appIds)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($results as $entry) {
            $map[$entry->getAppId()] = $entry;
        }
        return $map;
    }
}
