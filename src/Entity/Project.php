<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProjectStatus;
use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
#[ORM\HasLifecycleCallbacks]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ProjectStatus::class)]
    private ProjectStatus $status = ProjectStatus::Active;

    #[ORM\Column(length: 255)]
    private string $jiraBaseUrl;

    #[ORM\Column(length: 50)]
    private string $jiraBoardId;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $jiraJqlFilter = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $briefingContext = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 1)]
    private string $dailyHoursTarget = '0.0';

    #[ORM\Column(length: 7)]
    private string $color = '#cba6f7';

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, QuickLink> */
    #[ORM\OneToMany(targetEntity: QuickLink::class, mappedBy: 'project', orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $quickLinks;

    /** @var Collection<int, JiraBoard> */
    #[ORM\OneToMany(targetEntity: JiraBoard::class, mappedBy: 'project', orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $boards;

    public function __construct()
    {
        $this->quickLinks = new ArrayCollection();
        $this->boards = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): self { $this->name = $v; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): self { $this->description = $v; return $this; }

    public function getStatus(): ProjectStatus { return $this->status; }
    public function setStatus(ProjectStatus $v): self { $this->status = $v; return $this; }

    public function getJiraBaseUrl(): string { return $this->jiraBaseUrl; }
    public function setJiraBaseUrl(string $v): self { $this->jiraBaseUrl = $v; return $this; }

    public function getJiraBoardId(): string { return $this->jiraBoardId; }
    public function setJiraBoardId(string $v): self { $this->jiraBoardId = $v; return $this; }

    public function getJiraJqlFilter(): ?string { return $this->jiraJqlFilter; }
    public function setJiraJqlFilter(?string $v): self { $this->jiraJqlFilter = $v; return $this; }

    public function getBriefingContext(): ?string { return $this->briefingContext; }
    public function setBriefingContext(?string $v): self { $this->briefingContext = $v; return $this; }

    public function getDailyHoursTarget(): float { return (float) $this->dailyHoursTarget; }
    public function setDailyHoursTarget(float $v): self { $this->dailyHoursTarget = (string) $v; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $v): self { $this->color = $v; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): self { $this->sortOrder = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, QuickLink> */
    public function getQuickLinks(): Collection { return $this->quickLinks; }

    /** @return Collection<int, JiraBoard> */
    public function getBoards(): Collection { return $this->boards; }

    public function addBoard(JiraBoard $board): self
    {
        if (!$this->boards->contains($board)) {
            $board->setProject($this);
            $this->boards->add($board);
        }

        return $this;
    }

    /** The lowest-sorted board, treated as primary while the UI is still single-board. */
    public function getPrimaryBoard(): ?JiraBoard
    {
        return $this->boards->first() ?: null;
    }
}
