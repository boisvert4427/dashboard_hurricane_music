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

    public function __construct(int $id, Competitor $competitor, string $url)
    {
        $this->id = $id;
        $this->competitor = $competitor;
        $this->url = $url;
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
}
