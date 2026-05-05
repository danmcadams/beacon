<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AppSettingsRepository;

final class ClockService
{
    public function __construct(private readonly AppSettingsRepository $settings) {}

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone($this->settings->getSettings()->getTimezone()));
    }
}
