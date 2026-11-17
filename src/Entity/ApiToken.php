<?php

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Personal access token used by machine clients (MCP server).
 *
 * Only a SHA-256 hash of the token is stored: the plain value is shown once
 * at creation and cannot be recovered from the database.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
class ApiToken
{
    public const PREFIX = 'tks_';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $tokenHash = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /**
     * Creates a token and returns it with its plain value, which is never stored.
     *
     * @return array{0: self, 1: string}
     */
    public static function generate(User $owner, string $name): array
    {
        $plainToken = self::PREFIX.bin2hex(random_bytes(32));

        $token = new self();
        $token->owner = $owner;
        $token->name = $name;
        $token->tokenHash = self::hash($plainToken);
        $token->createdAt = new \DateTimeImmutable();

        return [$token, $plainToken];
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }
}
