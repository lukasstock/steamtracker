<?php

namespace App\Service;

use App\Entity\GameCompletion;
use App\Enum\GameStatus;

class BadgeService
{
    private const TIER_WEIGHT = ['legendary' => 5, 'platinum' => 4, 'gold' => 3, 'silver' => 2, 'bronze' => 1];

    /**
     * Compute all badge definitions for a user.
     *
     * @param  GameCompletion[] $allCompletions
     * @return array[]
     */
    public function computeBadges(array $allCompletions, float $totalPlaytimeHours, int $gameCount): array
    {
        $completedCount = 0;
        $droppedCount   = 0;
        $ratedCount     = 0;
        $spotlitCount   = 0;
        $notesCount     = 0;
        $fiveStarCount  = 0;
        $hasCompleted   = false;
        $hasDropped     = false;
        $hasRated       = false;

        foreach ($allCompletions as $c) {
            $status = $c->getStatus();
            $rating = $c->getRating();
            $notes  = $c->getNotes();

            if ($status === GameStatus::Completed) {
                $completedCount++;
                $hasCompleted = true;
            }
            if ($status === GameStatus::Dropped) {
                $droppedCount++;
                $hasDropped = true;
            }
            if ($rating !== null) {
                $ratedCount++;
                $hasRated = true;
                if ($rating === 5) {
                    $fiveStarCount++;
                }
            }
            if ($c->isSpotlight()) {
                $spotlitCount++;
            }
            if ($notes !== null && trim($notes) !== '') {
                $notesCount++;
            }
        }

        return [
            // ── Completion ──────────────────────────────────────────────
            [
                'category'    => 'Completion',
                'id'          => 'first_finish',
                'name'        => 'First Finish',
                'description' => 'Complete your first game',
                'icon'        => '🏁',
                'tier'        => 'bronze',
                'earned'      => $completedCount >= 1,
                'progress'    => min($completedCount, 1),
                'goal'        => 1,
            ],
            [
                'category'    => 'Completion',
                'id'          => 'on_a_roll',
                'name'        => 'On a Roll',
                'description' => 'Complete 50 games',
                'icon'        => '🎯',
                'tier'        => 'silver',
                'earned'      => $completedCount >= 50,
                'progress'    => min($completedCount, 50),
                'goal'        => 50,
            ],
            [
                'category'    => 'Completion',
                'id'          => 'dedicated',
                'name'        => 'Dedicated Player',
                'description' => 'Complete 100 games',
                'icon'        => '🎖️',
                'tier'        => 'gold',
                'earned'      => $completedCount >= 100,
                'progress'    => min($completedCount, 100),
                'goal'        => 100,
            ],
            [
                'category'    => 'Completion',
                'id'          => 'century_club',
                'name'        => 'Century Club',
                'description' => 'Complete 200 games',
                'icon'        => '💯',
                'tier'        => 'platinum',
                'earned'      => $completedCount >= 200,
                'progress'    => min($completedCount, 200),
                'goal'        => 200,
            ],
            // ── Playtime ────────────────────────────────────────────────
            [
                'category'    => 'Playtime',
                'id'          => 'time_sink',
                'name'        => 'Time Sink',
                'description' => 'Log 500 hours on Steam',
                'icon'        => '⏱️',
                'tier'        => 'bronze',
                'earned'      => $totalPlaytimeHours >= 500,
                'progress'    => (int) min($totalPlaytimeHours, 500),
                'goal'        => 500,
            ],
            [
                'category'    => 'Playtime',
                'id'          => 'veteran',
                'name'        => 'Veteran',
                'description' => 'Log 2,000 hours on Steam',
                'icon'        => '🕹️',
                'tier'        => 'silver',
                'earned'      => $totalPlaytimeHours >= 2000,
                'progress'    => (int) min($totalPlaytimeHours, 2000),
                'goal'        => 2000,
            ],
            [
                'category'    => 'Playtime',
                'id'          => 'addict',
                'name'        => 'Addict',
                'description' => 'Log 5,000 hours on Steam',
                'icon'        => '🖥️',
                'tier'        => 'gold',
                'earned'      => $totalPlaytimeHours >= 5000,
                'progress'    => (int) min($totalPlaytimeHours, 5000),
                'goal'        => 5000,
            ],
            [
                'category'    => 'Playtime',
                'id'          => 'no_life',
                'name'        => 'No Life',
                'description' => 'Log 10,000 hours on Steam',
                'icon'        => '☠️',
                'tier'        => 'legendary',
                'earned'      => $totalPlaytimeHours >= 10000,
                'progress'    => (int) min($totalPlaytimeHours, 10000),
                'goal'        => 10000,
            ],
            // ── Rating ──────────────────────────────────────────────────
            [
                'category'    => 'Rating',
                'id'          => 'critic',
                'name'        => 'Critic',
                'description' => 'Rate 10 games',
                'icon'        => '⭐',
                'tier'        => 'bronze',
                'earned'      => $ratedCount >= 10,
                'progress'    => min($ratedCount, 10),
                'goal'        => 10,
            ],
            [
                'category'    => 'Rating',
                'id'          => 'seasoned_critic',
                'name'        => 'Seasoned Critic',
                'description' => 'Rate 25 games',
                'icon'        => '🌟',
                'tier'        => 'silver',
                'earned'      => $ratedCount >= 25,
                'progress'    => min($ratedCount, 25),
                'goal'        => 25,
            ],
            [
                'category'    => 'Rating',
                'id'          => 'high_standards',
                'name'        => 'High Standards',
                'description' => 'Give a 5★ rating to 20 games',
                'icon'        => '✨',
                'tier'        => 'gold',
                'earned'      => $fiveStarCount >= 20,
                'progress'    => min($fiveStarCount, 20),
                'goal'        => 20,
            ],
            // ── Dropped ─────────────────────────────────────────────────
            [
                'category'    => 'Dropped',
                'id'          => 'rage_quitter',
                'name'        => 'Rage Quitter',
                'description' => 'Drop your first game',
                'icon'        => '💢',
                'tier'        => 'bronze',
                'earned'      => $droppedCount >= 1,
                'progress'    => min($droppedCount, 1),
                'goal'        => 1,
            ],
            [
                'category'    => 'Dropped',
                'id'          => 'serial_dropper',
                'name'        => 'Serial Dropper',
                'description' => 'Drop 20 games',
                'icon'        => '🗑️',
                'tier'        => 'silver',
                'earned'      => $droppedCount >= 20,
                'progress'    => min($droppedCount, 20),
                'goal'        => 20,
            ],
            [
                'category'    => 'Dropped',
                'id'          => 'picky',
                'name'        => 'Picky Eater',
                'description' => 'Drop 50 games',
                'icon'        => '🗑️',
                'tier'        => 'gold',
                'earned'      => $droppedCount >= 50,
                'progress'    => min($droppedCount, 50),
                'goal'        => 50,
            ],
            // ── Library ─────────────────────────────────────────────────
            [
                'category'    => 'Library',
                'id'          => 'enthusiast',
                'name'        => 'Enthusiast',
                'description' => 'Own 50 games on Steam',
                'icon'        => '📦',
                'tier'        => 'bronze',
                'earned'      => $gameCount >= 50,
                'progress'    => min($gameCount, 50),
                'goal'        => 50,
            ],
            [
                'category'    => 'Library',
                'id'          => 'collector',
                'name'        => 'Collector',
                'description' => 'Own 100 games on Steam',
                'icon'        => '🗄️',
                'tier'        => 'silver',
                'earned'      => $gameCount >= 100,
                'progress'    => min($gameCount, 100),
                'goal'        => 100,
            ],
            [
                'category'    => 'Library',
                'id'          => 'hoarder',
                'name'        => 'Hoarder',
                'description' => 'Own 300 games on Steam',
                'icon'        => '🗄️',
                'tier'        => 'gold',
                'earned'      => $gameCount >= 300,
                'progress'    => min($gameCount, 300),
                'goal'        => 300,
            ],
            // ── Special ─────────────────────────────────────────────────
            [
                'category'    => 'Special',
                'id'          => 'chronicler',
                'name'        => 'Chronicler',
                'description' => 'Write a note on a game',
                'icon'        => '📝',
                'tier'        => 'bronze',
                'earned'      => $notesCount >= 1,
                'progress'    => min($notesCount, 1),
                'goal'        => 1,
            ],
            [
                'category'    => 'Special',
                'id'          => 'storyteller',
                'name'        => 'Storyteller',
                'description' => 'Write notes on 25 games',
                'icon'        => '📖',
                'tier'        => 'silver',
                'earned'      => $notesCount >= 25,
                'progress'    => min($notesCount, 25),
                'goal'        => 25,
            ],
            [
                'category'    => 'Special',
                'id'          => 'yapper',
                'name'        => 'Yapper',
                'description' => 'Write notes on 50 games',
                'icon'        => '📖',
                'tier'        => 'gold',
                'earned'      => $notesCount >= 50,
                'progress'    => min($notesCount, 50),
                'goal'        => 50,
            ],
            [
                'category'    => 'Special',
                'id'          => 'curator',
                'name'        => 'Curator',
                'description' => 'Spotlight 5 favourite games',
                'icon'        => '🔦',
                'tier'        => 'gold',
                'earned'      => $spotlitCount >= 5,
                'progress'    => min($spotlitCount, 5),
                'goal'        => 5,
            ],
            [
                'category'    => 'Special',
                'id'          => 'all_rounder',
                'name'        => 'All-Rounder',
                'description' => 'Have completed, dropped, and rated games',
                'icon'        => '🎭',
                'tier'        => 'platinum',
                'earned'      => $hasCompleted && $hasDropped && $hasRated,
                'progress'    => (int) $hasCompleted + (int) $hasDropped + (int) $hasRated,
                'goal'        => 3,
            ],
        ];
    }

    /**
     * Returns only earned badges, sorted highest tier first.
     *
     * @return array[]
     */
    public function earnedBadges(array $badges): array
    {
        $earned = array_filter($badges, fn($b) => $b['earned']);
        usort($earned, fn($a, $b) =>
            (self::TIER_WEIGHT[$b['tier']] ?? 0) <=> (self::TIER_WEIGHT[$a['tier']] ?? 0)
        );
        return array_values($earned);
    }

    /**
     * Returns the top N earned badges (highest tier first).
     *
     * @return array[]
     */
    public function topEarned(array $badges, int $limit = 6): array
    {
        return array_slice($this->earnedBadges($badges), 0, $limit);
    }
}
