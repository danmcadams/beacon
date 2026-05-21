<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Repository\AppSettingsRepository;
use App\Repository\BriefingRepository;
use App\Repository\JiraTicketRepository;
use App\Repository\ProjectRepository;
use App\Service\ClockService;
use App\Service\JiraConnectionTester;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController extends AbstractController
{
    private const TIMEZONES = [
        'UTC'                  => 'UTC',
        'America/New_York'     => 'Eastern (ET)',
        'America/Chicago'      => 'Central (CT)',
        'America/Denver'       => 'Mountain (MT)',
        'America/Phoenix'      => 'Mountain no DST (AZ)',
        'America/Los_Angeles'  => 'Pacific (PT)',
        'America/Anchorage'    => 'Alaska (AKT)',
        'Pacific/Honolulu'     => 'Hawaii (HT)',
        'Europe/London'        => 'London (GMT/BST)',
        'Europe/Paris'         => 'Central Europe (CET)',
        'Europe/Helsinki'      => 'Eastern Europe (EET)',
        'Asia/Dubai'           => 'Gulf (GST)',
        'Asia/Kolkata'         => 'India (IST)',
        'Asia/Bangkok'         => 'Indochina (ICT)',
        'Asia/Shanghai'        => 'China (CST)',
        'Asia/Tokyo'           => 'Japan (JST)',
        'Australia/Sydney'     => 'Sydney (AEST)',
        'Pacific/Auckland'     => 'New Zealand (NZST)',
    ];

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly JiraTicketRepository $tickets,
        private readonly BriefingRepository $briefings,
        private readonly JiraConnectionTester $jiraTester,
        private readonly AppSettingsRepository $appSettings,
        private readonly EntityManagerInterface $em,
        private readonly ClockService $clock,
        private readonly string $anthropicApiKey,
        private readonly string $anthropicModel,
    ) {
    }

    #[Route('/settings', name: 'settings', methods: ['GET'])]
    public function index(): Response
    {
        $now = $this->clock->now();
        $allProjects = $this->projects->findOrdered();

        $jiraRows = array_map(function (Project $p) use ($now) {
            $last = $this->tickets->findMostRecentForProject($p);
            return [
                'id' => $p->getId(),
                'name' => $p->getName(),
                'last_ok' => $last !== null ? $this->formatFetchedAt($last->getFetchedAt(), $now) : '—',
                'last_err' => null,
            ];
        }, $allProjects);

        $lastBriefing = $this->briefings->findMostRecent();
        $lastFailed = $this->briefings->findMostRecentFailed();

        $cron = [
            'schedule' => '0 5 * * 1-5',
            'last_run' => $lastBriefing !== null
                ? $lastBriefing->getGeneratedAt()
                    ->setTimezone($this->clock->timezone())
                    ->format('D M j · H:i')
                : '—',
            'last_error' => $lastFailed?->getErrorMessage(),
        ];

        $tokens = $this->briefings->getTokenAggregates(30);
        $costUsd = round(
            ($tokens['input'] / 1_000_000 * 15.0) + ($tokens['output'] / 1_000_000 * 75.0),
            2,
        );

        $keyConfigured = $this->anthropicApiKey !== '';
        $jiraErrorCount = array_sum(array_map(fn (array $row) => $row['last_err'] !== null ? 1 : 0, $jiraRows));

        $appSettings = $this->appSettings->getSettings();

        return $this->render('settings/index.html.twig', [
            'app_timezone' => $appSettings->getTimezone(),
            'notification_leads' => $appSettings->getNotificationLeadMinutes(),
            'timezones' => self::TIMEZONES,
            'jira_rows' => $jiraRows,
            'model' => $this->anthropicModel,
            'key_configured' => $keyConfigured,
            'available_models' => [
                'claude-opus-4-7',
                'claude-sonnet-4-6',
                'claude-haiku-4-5-20251001',
            ],
            'cron' => $cron,
            'tokens' => [
                'input' => $tokens['input'],
                'output' => $tokens['output'],
                'per_day' => $tokens['per_day'],
                'window_days' => $tokens['window_days'],
                'cost_usd' => $costUsd,
                'sparkline_pts' => $this->buildSparklinePoints($tokens['per_day']),
            ],
            'rail_status' => [
                'anthropic_ok' => $keyConfigured,
                'cron_ok' => $cron['last_error'] === null,
                'jira_error_count' => $jiraErrorCount,
                'jira_count' => \count($allProjects),
            ],
        ]);
    }

    #[Route('/settings/app/save', name: 'settings_app_save', methods: ['POST'])]
    public function saveApp(Request $request): Response
    {
        $timezone = $request->request->getString('timezone');

        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return new Response('Invalid timezone.', Response::HTTP_BAD_REQUEST);
        }

        $leadsRaw = $request->request->all('notification_leads');
        $leads = array_values(array_unique(array_filter(
            array_map('intval', $leadsRaw),
            fn (int $v) => in_array($v, [5, 10, 15], true),
        )));
        rsort($leads);

        $settings = $this->appSettings->getSettings();
        $settings->setTimezone($timezone);
        $settings->setNotificationLeadMinutes($leads);
        $this->em->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'settings-saved');
        return $response;
    }

    #[Route('/settings/jira/{id}/test', name: 'settings_jira_test', methods: ['POST'])]
    public function testJira(Project $project): Response
    {
        $result = $this->jiraTester->test(
            $project->getJiraBaseUrl(),
            $project->getJiraBoardId(),
            $project->getJiraJqlFilter(),
        );

        return $this->render('settings/_jira-test-result.html.twig', [
            'result' => $result,
            'project_id' => $project->getId(),
        ]);
    }

    #[Route('/settings/anthropic/test', name: 'settings_anthropic_test', methods: ['POST'])]
    public function testAnthropic(): Response
    {
        $ok = $this->anthropicApiKey !== '';
        $message = $ok
            ? 'Key is set — real test pending API access.'
            : 'ANTHROPIC_API_KEY not set in .env.local';

        return $this->render('settings/_anthropic-test-result.html.twig', [
            'ok' => $ok,
            'message' => $message,
        ]);
    }

    /** @param list<int> $perDay */
    private function buildSparklinePoints(array $perDay): string
    {
        if ($perDay === []) {
            return '0,38 600,38';
        }

        $max = max($perDay);
        $count = \count($perDay);
        $W = 600;
        $H = 40;
        $step = $count > 1 ? $W / ($count - 1) : 0;

        $points = [];
        foreach ($perDay as $i => $v) {
            $x = round($i * $step, 1);
            $y = $max > 0
                ? round($H - ($v / $max) * ($H - 4) - 2, 1)
                : 38.0;
            $points[] = "{$x},{$y}";
        }

        return implode(' ', $points);
    }

    private function formatFetchedAt(\DateTimeImmutable $fetchedAt, \DateTimeImmutable $now): string
    {
        $nowDate = $now->format('Y-m-d');
        $fetchDate = $fetchedAt->format('Y-m-d');

        if ($fetchDate === $nowDate) {
            return 'today · ' . $fetchedAt->format('H:i');
        }

        $yesterdayDate = $now->modify('-1 day')->format('Y-m-d');
        if ($fetchDate === $yesterdayDate) {
            return 'yesterday · ' . $fetchedAt->format('H:i');
        }

        return $fetchedAt->format('M j · H:i');
    }
}
