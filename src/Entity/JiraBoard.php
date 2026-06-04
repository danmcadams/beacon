<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JiraBoardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JiraBoardRepository::class)]
#[ORM\Table(name: 'jira_boards')]
final class JiraBoard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'boards')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    /** The Jira project key, e.g. "ABC". Used to build the default JQL. */
    #[ORM\Column(length: 50)]
    private string $projectKey;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $label = null;

    /** Optional per-board JQL override; null falls back to the key-derived default. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $jqlFilter = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $v): self { $this->project = $v; return $this; }

    public function getProjectKey(): string { return $this->projectKey; }
    public function setProjectKey(string $v): self { $this->projectKey = $v; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $v): self { $this->label = $v; return $this; }

    public function getJqlFilter(): ?string { return $this->jqlFilter; }
    public function setJqlFilter(?string $v): self { $this->jqlFilter = $v; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): self { $this->sortOrder = $v; return $this; }
}
