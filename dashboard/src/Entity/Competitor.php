<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'competitor')]
#[ORM\UniqueConstraint(name: 'uk_competitor_domain', columns: ['domain'])]
class Competitor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $domain;

    #[ORM\Column(name: 'search_url_pattern', length: 255)]
    private string $searchUrlPattern;

    public function __construct(string $name, string $domain, string $searchUrlPattern)
    {
        $this->name = $name;
        $this->domain = $domain;
        $this->searchUrlPattern = $searchUrlPattern;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    public function getSearchUrlPattern(): string
    {
        return $this->searchUrlPattern;
    }

    public function setSearchUrlPattern(string $searchUrlPattern): self
    {
        $this->searchUrlPattern = $searchUrlPattern;

        return $this;
    }
}
