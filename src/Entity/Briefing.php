<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BriefingStatus;
use App\Repository\BriefingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BriefingRepository::class)]
#[ORM\Table(name: 'briefings')]
#[ORM\UniqueConstraint(name: 'uniq_briefing_date_project', columns: ['briefing_date', 'project_id'])]
class Briefing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $briefingDate;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(type: Types::TEXT)]
    private string $contentMarkdown = '';

    /** @var array<int, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $ticketsSnapshot = [];

    #[ORM\Column(type: Types::STRING, length: 20, enumType: BriefingStatus::class)]
    private BriefingStatus $status = BriefingStatus::Success;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(length: 50)]
    private string $model = '';

    #[ORM\Column(nullable: true)]
    private ?int $inputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $outputTokens = null;

    #[ORM\Column]
    private \DateTimeImmutable $generatedAt;

    public function __construct()
    {
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getBriefingDate(): \DateTimeImmutable { return $this->briefingDate; }
    public function setBriefingDate(\DateTimeImmutable $v): self { $this->briefingDate = $v; return $this; }

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $v): self { $this->project = $v; return $this; }

    public function getContentMarkdown(): string { return $this->contentMarkdown; }
    public function setContentMarkdown(string $v): self { $this->contentMarkdown = $v; return $this; }

    /** @return array<int, mixed> */
    public function getTicketsSnapshot(): array { return $this->ticketsSnapshot; }
    /** @param array<int, mixed> $v */
    public function setTicketsSnapshot(array $v): self { $this->ticketsSnapshot = $v; return $this; }

    public function getStatus(): BriefingStatus { return $this->status; }
    public function setStatus(BriefingStatus $v): self { $this->status = $v; return $this; }

    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $v): self { $this->errorMessage = $v; return $this; }

    public function getModel(): string { return $this->model; }
    public function setModel(string $v): self { $this->model = $v; return $this; }

    public function getInputTokens(): ?int { return $this->inputTokens; }
    public function setInputTokens(?int $v): self { $this->inputTokens = $v; return $this; }

    public function getOutputTokens(): ?int { return $this->outputTokens; }
    public function setOutputTokens(?int $v): self { $this->outputTokens = $v; return $this; }

    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
    public function setGeneratedAt(\DateTimeImmutable $v): self { $this->generatedAt = $v; return $this; }
}
