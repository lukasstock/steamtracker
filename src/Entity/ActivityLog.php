<?php

namespace App\Entity;

use App\Repository\ActivityLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActivityLogRepository::class)]
#[ORM\Table(name: 'activity_log')]
#[ORM\Index(columns: ['created_at'], name: 'idx_created_at')]
class ActivityLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 36)]
    private string $userToken;

    #[ORM\Column(length: 20)]
    private string $steamId;

    /** completed | dropped | rating */
    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column]
    private int $appId;

    #[ORM\Column(length: 255)]
    private string $appName;

    /** e.g. ['rating' => 4] */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function getId(): int { return $this->id; }

    public function getUserToken(): string { return $this->userToken; }
    public function setUserToken(string $v): static { $this->userToken = $v; return $this; }

    public function getSteamId(): string { return $this->steamId; }
    public function setSteamId(string $v): static { $this->steamId = $v; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $v): static { $this->type = $v; return $this; }

    public function getAppId(): int { return $this->appId; }
    public function setAppId(int $v): static { $this->appId = $v; return $this; }

    public function getAppName(): string { return $this->appName; }
    public function setAppName(string $v): static { $this->appName = $v; return $this; }

    public function getMetadata(): ?array { return $this->metadata; }
    public function setMetadata(?array $v): static { $this->metadata = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $v): static { $this->createdAt = $v; return $this; }
}
