<?php

namespace App\Entity;

use App\Repository\AchievementCacheRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AchievementCacheRepository::class)]
#[ORM\Table(name: 'achievement_cache')]
class AchievementCache
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 20)]
    private string $steamId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $appId;

    /** 0 means game has no achievements */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $unlockedCount = 0;

    /** 0 means game has no achievements */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $totalCount = 0;

    /** @var array<array{displayName: string, icon: string, globalPercent: float}>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rareAchievements = null;

    #[ORM\Column]
    private \DateTimeImmutable $fetchedAt;

    public function getSteamId(): string { return $this->steamId; }

    public function setSteamId(string $steamId): static
    {
        $this->steamId = $steamId;
        return $this;
    }

    public function getAppId(): int { return $this->appId; }

    public function setAppId(int $appId): static
    {
        $this->appId = $appId;
        return $this;
    }

    public function getUnlockedCount(): int { return $this->unlockedCount; }

    public function setUnlockedCount(int $count): static
    {
        $this->unlockedCount = $count;
        return $this;
    }

    public function getTotalCount(): int { return $this->totalCount; }

    public function setTotalCount(int $count): static
    {
        $this->totalCount = $count;
        return $this;
    }

    public function getRareAchievements(): ?array { return $this->rareAchievements; }

    public function setRareAchievements(?array $rare): static
    {
        $this->rareAchievements = $rare;
        return $this;
    }

    public function getFetchedAt(): \DateTimeImmutable { return $this->fetchedAt; }

    public function setFetchedAt(\DateTimeImmutable $dt): static
    {
        $this->fetchedAt = $dt;
        return $this;
    }
}
