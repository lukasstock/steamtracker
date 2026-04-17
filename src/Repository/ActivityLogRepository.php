<?php

namespace App\Repository;

use App\Entity\ActivityLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    /**
     * @return ActivityLog[]
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param string[] $steamIds
     * @return ActivityLog[]
     */
    public function findRecentForSteamIds(array $steamIds, int $limit = 60): array
    {
        if (empty($steamIds)) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->where('a.steamId IN (:ids)')
            ->setParameter('ids', $steamIds)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns all log entries for a given user + app, newest first.
     *
     * @return ActivityLog[]
     */
    public function findByUserAndApp(string $userToken, int $appId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.userToken = :token')
            ->andWhere('a.appId = :appId')
            ->setParameter('token', $userToken)
            ->setParameter('appId', $appId)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the most recent log entry for a given user + app + type combination.
     * Used to prevent duplicate events within a short window.
     */
    public function findLastEntry(string $userToken, int $appId, string $type): ?ActivityLog
    {
        return $this->createQueryBuilder('a')
            ->where('a.userToken = :token')
            ->andWhere('a.appId = :appId')
            ->andWhere('a.type = :type')
            ->setParameter('token', $userToken)
            ->setParameter('appId', $appId)
            ->setParameter('type', $type)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
