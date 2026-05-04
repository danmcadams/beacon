<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuickLinkRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuickLinkRepository::class)]
#[ORM\Table(name: 'quick_links')]
class QuickLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'quickLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\Column(length: 500)]
    private string $url;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $v): self { $this->project = $v; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $v): self { $this->label = $v; return $this; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $v): self { $this->url = $v; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): self { $this->sortOrder = $v; return $this; }
}
