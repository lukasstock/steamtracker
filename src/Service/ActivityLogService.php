<?php

namespace App\Service;

use App\Entity\ActivityLog;
use App\Enum\GameStatus;
use App\Repository\ActivityLogRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles deduplication and persistence of activity log entries.
 *
 * Extracted from GameLibraryController::update() so the same logic can be
 * reused from any context without coupling it to HTTP concerns.
 */
class ActivityLogService
{
    // Minimum gap between identical log entries (prevents double-logs on fast saves)
    private const DEDUP_SECONDS = 3600;

    public function __construct(
        private readonly ActivityLogRepository $activityLogRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Persist a status or rating change for a game, skipping duplicates within
     * the last DEDUP_SECONDS window.
     *
     * @param GameStatus      $status         Current (new) status
     * @param GameStatus|null $previousStatus Status before this save (null = new record)
     * @param int|null        $rating         Current rating (1-5), or null
     * @param int|null        $previousRating Rating before this save
     */
    public function logUpdate(
        string      $userToken,
        string      $steamId,
        int         $appId,
        string      $appName,
        GameStatus  $status,
        ?GameStatus $previousStatus,
        ?int        $rating,
        ?int        $previousRating,
        string      $notes,
    ): void {
        if ($appName === '') {
            return;
        }

        $now = new \DateTimeImmutable();

        // Log completed / dropped transitions
        $loggableStatuses = [GameStatus::Completed, GameStatus::Dropped];
        if (in_array($status, $loggableStatuses, true) && $status !== $previousStatus) {
            $last = $this->activityLogRepository->findLastEntry($userToken, $appId, $status->value);
            if ($last === null || ($now->getTimestamp() - $last->getCreatedAt()->getTimestamp()) > self::DEDUP_SECONDS) {
                $meta = array_filter(['rating' => $rating, 'notes' => $notes ?: null]);
                $this->entityManager->persist(
                    (new ActivityLog())
                        ->setUserToken($userToken)
                        ->setSteamId($steamId)
                        ->setType($status->value)
                        ->setAppId($appId)
                        ->setAppName($appName)
                        ->setMetadata($meta ?: null)
                        ->setCreatedAt($now)
                );
            }
        }

        // Log rating changes separately
        if ($rating !== null && $rating !== $previousRating) {
            $last = $this->activityLogRepository->findLastEntry($userToken, $appId, 'rating');
            if ($last === null || ($now->getTimestamp() - $last->getCreatedAt()->getTimestamp()) > self::DEDUP_SECONDS) {
                $this->entityManager->persist(
                    (new ActivityLog())
                        ->setUserToken($userToken)
                        ->setSteamId($steamId)
                        ->setType('rating')
                        ->setAppId($appId)
                        ->setAppName($appName)
                        ->setMetadata(array_filter(['rating' => $rating, 'notes' => $notes ?: null]))
                        ->setCreatedAt($now)
                );
            }
        }

        $this->entityManager->flush();
    }
}
