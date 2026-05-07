<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'competitor_url_price_history')]
#[ORM\Index(name: 'idx_price_history_competitor', columns: ['competitor_id'])]
#[ORM\Index(name: 'idx_price_history_product', columns: ['id_product'])]
#[ORM\Index(name: 'idx_price_history_observed_at', columns: ['observed_at'])]
class CompetitorUrlPriceHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'id_product', type: 'integer')]
    private int $productId;

    #[ORM\ManyToOne(targetEntity: Competitor::class)]
    #[ORM\JoinColumn(name: 'competitor_id', nullable: false, onDelete: 'CASCADE')]
    private Competitor $competitor;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(name: 'observed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $observedAt;

    public function __construct(
        int $productId,
        Competitor $competitor,
        string $url,
        string $price,
        ?string $source = null,
    ) {
        $this->productId = $productId;
        $this->competitor = $competitor;
        $this->url = $url;
        $this->price = $price;
        $this->source = $source;
        $this->observedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getCompetitor(): Competitor
    {
        return $this->competitor;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function getObservedAt(): \DateTimeImmutable
    {
        return $this->observedAt;
    }
}
