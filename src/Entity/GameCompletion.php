<?php

namespace App\Entity;

use App\Enum\GameStatus;
use App\Repository\GameCompletionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameCompletionRepository::class)]
#[ORM\Table(name: 'game_completion')]
#[ORM\UniqueConstraint(name: 'uq_user_app', columns: ['user_token', 'app_id'])]
class GameCompletion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column]
    private int $appId;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $userToken = null;

    #[ORM\Column(length: 20, enumType: GameStatus::class)]
    private GameStatus $status = GameStatus::Unplayed;

    #[ORM\Column(nullable: true)]
    private ?int $rating = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private bool $isSpotlight = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isNotesPublic = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getAppId(): int
    {
        return $this->appId;
    }

    public function setAppId(int $appId): static
    {
        $this->appId = $appId;
        return $this;
    }

    public function getUserToken(): ?string
    {
        return $this->userToken;
    }

    public function setUserToken(string $userToken): static
    {
        $this->userToken = $userToken;
        return $this;
    }

    public function getStatus(): GameStatus
    {
        return $this->status;
    }

    public function setStatus(GameStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->status === GameStatus::Completed;
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }

    public function setRating(?int $rating): static
    {
        $this->rating = $rating;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function isSpotlight(): bool
    {
        return $this->isSpotlight;
    }

    public function setIsSpotlight(bool $isSpotlight): static
    {
        $this->isSpotlight = $isSpotlight;
        return $this;
    }

    public function isNotesPublic(): bool
    {
        return $this->isNotesPublic;
    }

    public function setIsNotesPublic(bool $isNotesPublic): static
    {
        $this->isNotesPublic = $isNotesPublic;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }
}
