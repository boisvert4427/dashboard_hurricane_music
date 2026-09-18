<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'competitor_url_final')]
#[ORM\Index(name: 'idx_final_competitor', columns: ['competitor_id'])]
#[ORM\Index(name: 'idx_final_price_due', columns: ['competitor_id', 'next_price_check_at'])]
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

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastPriceAttemptAt = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $lastPriceResult = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $consecutivePriceNotFound = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextPriceCheckAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $priceCheckRequestedAt = null;

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
        if ($this->url !== $url) {
            $this->lastPriceAttemptAt = null;
            $this->lastPriceResult = null;
            $this->consecutivePriceNotFound = 0;
            $this->nextPriceCheckAt = null;
            $this->priceCheckRequestedAt = null;
            $this->resetHttpFailureState();
        }
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
    public function recordPriceAttempt(string $result, ?\DateTimeImmutable $now = null): void
    {
        if (!in_array($result, ['price_found', 'price_not_found', 'temporary_error', 'http_gone'], true)) {
            throw new \InvalidArgumentException('Unknown price attempt result.');
        }
        $now ??= new \DateTimeImmutable();
        $this->lastPriceAttemptAt = $now;
        $this->lastPriceResult = $result;
        $this->priceCheckRequestedAt = null;

        if ($result === 'price_found') {
            $this->consecutivePriceNotFound = 0;
            $this->nextPriceCheckAt = null;
        } elseif ($result === 'price_not_found') {
            $this->consecutivePriceNotFound++;
            $days = match ($this->consecutivePriceNotFound) {
                1 => 1,
                2 => 7,
                default => 30,
            };
            $this->nextPriceCheckAt = $now->modify('+' . $days . ' days');
        } else {
            // A network error does not prove the price is absent or reset its failure count.
            $this->nextPriceCheckAt = $now->modify($result === 'http_gone' ? '+1 day' : '+3 hours');
        }
    }

    public function requestPriceCheck(?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $this->priceCheckRequestedAt ??= $now;
        $this->nextPriceCheckAt = $now;
    }

    public function getLastPriceAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastPriceAttemptAt;
    }

    public function getLastPriceResult(): ?string
    {
        return $this->lastPriceResult;
    }

    public function getConsecutivePriceNotFound(): int
    {
        return $this->consecutivePriceNotFound;
    }

    public function getNextPriceCheckAt(): ?\DateTimeImmutable
    {
        return $this->nextPriceCheckAt;
    }

    public function getPriceCheckRequestedAt(): ?\DateTimeImmutable
    {
        return $this->priceCheckRequestedAt;
    }

}
