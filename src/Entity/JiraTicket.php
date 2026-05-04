<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JiraTicketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JiraTicketRepository::class)]
#[ORM\Table(name: 'jira_ticket_cache')]
#[ORM\UniqueConstraint(name: 'uniq_project_ticket_key', columns: ['project_id', 'ticket_key'])]
class JiraTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 50)]
    private string $ticketKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $summary;

    #[ORM\Column(length: 50)]
    private string $status;

    #[ORM\Column(length: 20)]
    private string $statusCategory;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $priority = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $assignee = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column]
    private \DateTimeImmutable $jiraUpdatedAt;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $rawJson = [];

    #[ORM\Column]
    private \DateTimeImmutable $fetchedAt;

    public function getId(): ?int { return $this->id; }

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $v): self { $this->project = $v; return $this; }

    public function getTicketKey(): string { return $this->ticketKey; }
    public function setTicketKey(string $v): self { $this->ticketKey = $v; return $this; }

    public function getSummary(): string { return $this->summary; }
    public function setSummary(string $v): self { $this->summary = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): self { $this->status = $v; return $this; }

    public function getStatusCategory(): string { return $this->statusCategory; }
    public function setStatusCategory(string $v): self { $this->statusCategory = $v; return $this; }

    public function getPriority(): ?string { return $this->priority; }
    public function setPriority(?string $v): self { $this->priority = $v; return $this; }

    public function getAssignee(): ?string { return $this->assignee; }
    public function setAssignee(?string $v): self { $this->assignee = $v; return $this; }

    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function setDueDate(?\DateTimeImmutable $v): self { $this->dueDate = $v; return $this; }

    public function getJiraUpdatedAt(): \DateTimeImmutable { return $this->jiraUpdatedAt; }
    public function setJiraUpdatedAt(\DateTimeImmutable $v): self { $this->jiraUpdatedAt = $v; return $this; }

    /** @return array<string, mixed> */
    public function getRawJson(): array { return $this->rawJson; }
    /** @param array<string, mixed> $v */
    public function setRawJson(array $v): self { $this->rawJson = $v; return $this; }

    public function getFetchedAt(): \DateTimeImmutable { return $this->fetchedAt; }
    public function setFetchedAt(\DateTimeImmutable $v): self { $this->fetchedAt = $v; return $this; }
}
