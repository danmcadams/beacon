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
    /** A ticket counts as "new" for this many hours after it was created in Jira (72h covers a Fri→Mon weekend gap). */
    public const int NEW_WINDOW_HOURS = 72;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    /** The board this ticket came from. Nullable for rows cached before boards existed. */
    #[ORM\ManyToOne(targetEntity: JiraBoard::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?JiraBoard $board = null;

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

    /** From Jira's `created` field; drives the "new" highlight. Null for rows fetched before this field was captured. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $jiraCreatedAt = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $rawJson = [];

    #[ORM\Column]
    private \DateTimeImmutable $fetchedAt;

    /** Set once, when the ticket first enters the cache; never overwritten on refresh. Null for rows that predate this column. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $firstSeenAt = null;

    public function getId(): ?int { return $this->id; }

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $v): self { $this->project = $v; return $this; }

    public function getBoard(): ?JiraBoard { return $this->board; }
    public function setBoard(?JiraBoard $v): self { $this->board = $v; return $this; }

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

    public function getJiraCreatedAt(): ?\DateTimeImmutable { return $this->jiraCreatedAt; }
    public function setJiraCreatedAt(?\DateTimeImmutable $v): self { $this->jiraCreatedAt = $v; return $this; }

    /** @return array<string, mixed> */
    public function getRawJson(): array { return $this->rawJson; }
    /** @param array<string, mixed> $v */
    public function setRawJson(array $v): self { $this->rawJson = $v; return $this; }

    public function getFetchedAt(): \DateTimeImmutable { return $this->fetchedAt; }
    public function setFetchedAt(\DateTimeImmutable $v): self { $this->fetchedAt = $v; return $this; }

    public function getFirstSeenAt(): ?\DateTimeImmutable { return $this->firstSeenAt; }
    public function setFirstSeenAt(?\DateTimeImmutable $v): self { $this->firstSeenAt = $v; return $this; }

    public function isNewAsOf(\DateTimeImmutable $now): bool
    {
        if ($this->jiraCreatedAt === null) {
            return false;
        }

        return $this->jiraCreatedAt > $now->modify(sprintf('-%d hours', self::NEW_WINDOW_HOURS));
    }
}
