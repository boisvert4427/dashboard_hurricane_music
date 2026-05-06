<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'competitor_url_rejected_url')]
#[ORM\UniqueConstraint(name: 'uk_rejected_competitor_url', columns: ['competitor_id', 'url'])]
class CompetitorUrlRejectedUrl
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Competitor::class)]
    #[ORM\JoinColumn(name: 'competitor_id', nullable: false, onDelete: 'CASCADE')]
    private Competitor $competitor;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Competitor $competitor, string $url)
    {
        $this->competitor = $competitor;
        $this->url = $url;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUrl(): string
    {
        return $this->url;
    }
}
