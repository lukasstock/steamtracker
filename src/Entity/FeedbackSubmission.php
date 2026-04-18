<?php

namespace App\Entity;

use App\Repository\FeedbackSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FeedbackSubmissionRepository::class)]
#[ORM\Table(name: 'feedback_submission')]
class FeedbackSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function getId(): int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): static { $this->message = $message; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
