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
    public const REVIEW_PENDING = 'pending';
    public const REVIEW_POSTPONED = 'postponed';
    public const REVIEW_VALID = 'valid';
    public const REVIEW_REJECTED = 'rejected';
    public const REVIEW_IGNORED = 'ignored';

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
    private ?string $competitorTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $competitorBrand = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $competitorBreadcrumb = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $score = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $competitorPrice = null;

    #[ORM\Column(length: 16, options: ['default' => self::REVIEW_PENDING])]
    private string $validationStatus = self::REVIEW_PENDING;

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
        ?string $competitorTitle = null,
        ?string $competitorBrand = null,
        ?string $competitorBreadcrumb = null,
        ?int $score = null,
        ?string $competitorPrice = null,
        string $validationStatus = self::REVIEW_PENDING,
        ?string $matchedQuery = null,
        ?string $message = null,
    ) {
        $this->productId = $productId;
        $this->competitor = $competitor;
        $this->result = $result;
        $this->url = $url;
        $this->competitorTitle = $competitorTitle;
        $this->competitorBrand = $competitorBrand;
        $this->competitorBreadcrumb = $competitorBreadcrumb;
        $this->score = $score;
        $this->competitorPrice = $competitorPrice;
        $this->validationStatus = $validationStatus;
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

    public function getCompetitorTitle(): ?string
    {
        return $this->competitorTitle;
    }

    public function setCompetitorTitle(?string $competitorTitle): self
    {
        $this->competitorTitle = $competitorTitle;

        return $this;
    }

    public function getCompetitorBrand(): ?string
    {
        return $this->competitorBrand;
    }

    public function setCompetitorBrand(?string $competitorBrand): self
    {
        $this->competitorBrand = $competitorBrand;

        return $this;
    }

    public function getCompetitorBreadcrumb(): ?string
    {
        return $this->competitorBreadcrumb;
    }

    public function setCompetitorBreadcrumb(?string $competitorBreadcrumb): self
    {
        $this->competitorBreadcrumb = $competitorBreadcrumb;

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

    public function getCompetitorPrice(): ?string
    {
        return $this->competitorPrice;
    }

    public function setCompetitorPrice(?string $competitorPrice): self
    {
        $this->competitorPrice = $competitorPrice;

        return $this;
    }

    public function getValidationStatus(): string
    {
        return $this->validationStatus;
    }

    public function setValidationStatus(string $validationStatus): self
    {
        $this->validationStatus = $validationStatus;

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
