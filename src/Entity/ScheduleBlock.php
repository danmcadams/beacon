<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BlockType;
use App\Repository\ScheduleBlockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScheduleBlockRepository::class)]
#[ORM\Table(name: 'schedule_blocks')]
class ScheduleBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ISO-8601: 1=Mon, 7=Sun */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $dayOfWeek;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $endTime;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: BlockType::class)]
    private BlockType $blockType = BlockType::Other;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }

    public function getDayOfWeek(): int { return $this->dayOfWeek; }
    public function setDayOfWeek(int $v): self { $this->dayOfWeek = $v; return $this; }

    public function getStartTime(): \DateTimeImmutable { return $this->startTime; }
    public function setStartTime(\DateTimeImmutable $v): self { $this->startTime = $v; return $this; }

    public function getEndTime(): \DateTimeImmutable { return $this->endTime; }
    public function setEndTime(\DateTimeImmutable $v): self { $this->endTime = $v; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $v): self { $this->label = $v; return $this; }

    public function getBlockType(): BlockType { return $this->blockType; }
    public function setBlockType(BlockType $v): self { $this->blockType = $v; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): self { $this->sortOrder = $v; return $this; }
}
