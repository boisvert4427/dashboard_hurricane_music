<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'competitor_url_test_result')]
#[ORM\Index(name: 'idx_test_result_competitor', columns: ['competitor_id'])]
#[ORM\Index(name: 'idx_test_result_status', columns: ['result'])]
class CompetitorUrlTestResult
{
    public const RESULT_MATCHED = 'matched';
    public const RESULT_NOT_FOUND = 'not_found';
    public const RESULT_CLOUDFLARE = 'cloudflare';
    public const RESULT_SEARCH_INPUT_NOT_FOUND = 'search_input_not_found';
    public const RESULT_ERROR = 'error';

    #[ORM\Id]
    #[ORM\Column(name: 'id_product', type: 'integer')]
    private int $productId;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Competitor::class)]
    #[ORM\JoinColumn(name: 'competitor_id', nullable: false, onDelete: 'CASCADE')]
    private Competitor $competitor;

    #[ORM\Column(length: 32)]
    private string $result;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $score = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $matchedQuery = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(name: 'last_tested_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastTestedAt;

    public function __construct(
        int $productId,
        Competitor $competitor,
        string $result,
        ?string $url = null,
        ?string $title = null,
        ?int $score = null,
        ?string $matchedQuery = null,
        ?string $message = null,
    ) {
        $this->productId = $productId;
        $this->competitor = $competitor;
        $this->result = $result;
        $this->url = $url;
        $this->title = $title;
        $this->score = $score;
        $this->matchedQuery = $matchedQuery;
        $this->message = $message;
        $this->lastTestedAt = new \DateTimeImmutable();
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getCompetitor(): Competitor
    {
        return $this->competitor;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function setResult(string $result): self
    {
        $this->result = $result;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): self
    {
        $this->score = $score;

        return $this;
    }

    public function getMatchedQuery(): ?string
    {
        return $this->matchedQuery;
    }

    public function setMatchedQuery(?string $matchedQuery): self
    {
        $this->matchedQuery = $matchedQuery;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getLastTestedAt(): \DateTimeImmutable
    {
        return $this->lastTestedAt;
    }

    public function touch(): self
    {
        $this->lastTestedAt = new \DateTimeImmutable();

        return $this;
    }
}
