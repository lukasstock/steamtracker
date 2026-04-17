<?php

namespace App\Repository;

use App\Entity\UserProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserProfile>
 */
class UserProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserProfile::class);
    }

    /**
     * Returns profiles keyed by steamId for the given set of Steam IDs.
     *
     * @param string[] $steamIds
     * @return array<string, UserProfile>
     */
    public function findBySteamIds(array $steamIds): array
    {
        if (empty($steamIds)) {
            return [];
        }

        $results = $this->createQueryBuilder('u')
            ->where('u.steamId IN (:ids)')
            ->setParameter('ids', $steamIds)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($results as $profile) {
            $map[$profile->getSteamId()] = $profile;
        }
        return $map;
    }
}
