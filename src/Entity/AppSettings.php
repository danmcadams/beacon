<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppSettingsRepository::class)]
#[ORM\Table(name: 'app_settings')]
final class AppSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $timezone = 'UTC';

    #[ORM\Column(type: Types::JSON)]
    private array $notificationLeadMinutes = [15, 10, 5];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
    }

    public function getNotificationLeadMinutes(): array
    {
        return $this->notificationLeadMinutes;
    }

    public function setNotificationLeadMinutes(array $v): void
    {
        $this->notificationLeadMinutes = $v;
    }
}
