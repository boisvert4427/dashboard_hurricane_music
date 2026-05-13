<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'competitor_url_final')]
#[ORM\Index(name: 'idx_final_competitor', columns: ['competitor_id'])]
#[ORM\UniqueConstraint(name: 'uk_final_competitor_url', columns: ['competitor_id', 'url'])]
class CompetitorUrlFinal
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Competitor::class)]
    #[ORM\JoinColumn(name: 'competitor_id', nullable: false, onDelete: 'CASCADE')]
    private Competitor $competitor;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $competitorPrice = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $lastHttpStatus = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $consecutiveHttpFailures = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastHttpErrorAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastHttpErrorMessage = null;

    public function __construct(int $id, Competitor $competitor, string $url, ?string $competitorPrice = null)
    {
        $this->id = $id;
        $this->competitor = $competitor;
        $this->url = $url;
        $this->competitorPrice = $competitorPrice;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCompetitor(): Competitor
    {
        return $this->competitor;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getCompetitorPrice(): ?string
    {
        return $this->competitorPrice;
    }

    public function setCompetitorPrice(?string $competitorPrice): self
    {
        $this->competitorPrice = $competitorPrice;

        return $this;
    }

    public function getLastHttpStatus(): ?int
    {
        return $this->lastHttpStatus;
    }

    public function setLastHttpStatus(?int $lastHttpStatus): self
    {
        $this->lastHttpStatus = $lastHttpStatus;

        return $this;
    }

    public function getConsecutiveHttpFailures(): int
    {
        return $this->consecutiveHttpFailures;
    }

    public function setConsecutiveHttpFailures(int $consecutiveHttpFailures): self
    {
        $this->consecutiveHttpFailures = max(0, $consecutiveHttpFailures);

        return $this;
    }

    public function getLastHttpErrorAt(): ?\DateTimeImmutable
    {
        return $this->lastHttpErrorAt;
    }

    public function setLastHttpErrorAt(?\DateTimeImmutable $lastHttpErrorAt): self
    {
        $this->lastHttpErrorAt = $lastHttpErrorAt;

        return $this;
    }

    public function getLastHttpErrorMessage(): ?string
    {
        return $this->lastHttpErrorMessage;
    }

    public function setLastHttpErrorMessage(?string $lastHttpErrorMessage): self
    {
        $this->lastHttpErrorMessage = $lastHttpErrorMessage;

        return $this;
    }

    public function resetHttpFailureState(): self
    {
        $this->lastHttpStatus = null;
        $this->consecutiveHttpFailures = 0;
        $this->lastHttpErrorAt = null;
        $this->lastHttpErrorMessage = null;

        return $this;
    }
}
