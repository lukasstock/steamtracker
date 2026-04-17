<?php

namespace App\Entity;

use App\Repository\UserProfileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserProfileRepository::class)]
#[ORM\Table(name: 'user_profile')]
class UserProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 36, unique: true)]
    private string $userToken;

    #[ORM\Column(length: 20, unique: true)]
    private string $steamId;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $vanityUrl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserToken(): string
    {
        return $this->userToken;
    }

    public function setUserToken(string $userToken): static
    {
        $this->userToken = $userToken;
        return $this;
    }

    public function getSteamId(): string
    {
        return $this->steamId;
    }

    public function setSteamId(string $steamId): static
    {
        $this->steamId = $steamId;
        return $this;
    }

    public function getVanityUrl(): ?string
    {
        return $this->vanityUrl;
    }

    public function setVanityUrl(?string $vanityUrl): static
    {
        $this->vanityUrl = $vanityUrl;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
