<?php

namespace App\Entity;

use App\Repository\HltbCacheRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HltbCacheRepository::class)]
#[ORM\Table(name: 'hltb_cache')]
class HltbCache
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $appId;

    /** Null = looked up but not found on HLTB. Positive = hours to beat main story. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $hoursMain = null;

    #[ORM\Column]
    private \DateTimeImmutable $fetchedAt;

    public function getAppId(): int { return $this->appId; }

    public function setAppId(int $appId): static
    {
        $this->appId = $appId;
        return $this;
    }

    public function getHoursMain(): ?int { return $this->hoursMain; }

    public function setHoursMain(?int $hours): static
    {
        $this->hoursMain = $hours;
        return $this;
    }

    public function getFetchedAt(): \DateTimeImmutable { return $this->fetchedAt; }

    public function setFetchedAt(\DateTimeImmutable $dt): static
    {
        $this->fetchedAt = $dt;
        return $this;
    }
}
