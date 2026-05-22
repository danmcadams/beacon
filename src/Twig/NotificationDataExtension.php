<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\AppSettingsRepository;
use App\Repository\ScheduleBlockRepository;
use App\Service\ClockService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NotificationDataExtension extends AbstractExtension
{
    public function __construct(
        private readonly AppSettingsRepository $appSettings,
        private readonly ScheduleBlockRepository $blocks,
        private readonly ClockService $clock,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('notification_data_json', $this->getJson(...), ['is_safe' => ['html']]),
        ];
    }

    public function getJson(): string
    {
        $now = $this->clock->now();
        $blocks = $this->blocks->findForDay((int) $now->format('N'));
        $schedule = array_map(
            fn ($b) => ['start' => $b->getStartTime()->format('H:i'), 'label' => $b->getLabel()],
            $blocks,
        );
        $leads = $this->appSettings->getSettings()->getNotificationLeadMinutes();

        return json_encode(['schedule' => $schedule, 'leads' => $leads], JSON_THROW_ON_ERROR);
    }
}
