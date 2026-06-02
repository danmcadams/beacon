<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Briefing;
use App\Entity\Project;
use App\Entity\ScheduleBlock;
use App\Repository\BriefingRepository;
use App\Repository\JiraTicketRepository;
use App\Repository\ProjectRepository;
use App\Repository\ScheduleBlockRepository;
use App\Service\ClockService;
use App\Twig\MarkdownExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ScheduleBlockRepository $blocks,
        private readonly JiraTicketRepository $tickets,
        private readonly BriefingRepository $briefings,
        private readonly MarkdownExtension $markdown,
        private readonly ClockService $clock,
    ) {
    }

    #[Route('/yesterday', name: 'yesterday', methods: ['GET'])]
    public function yesterday(): Response
    {
        $now       = $this->clock->now();
        $yesterday = $now->modify('-1 day')->setTime(0, 0);

        $projects           = $this->projects->findActiveOrdered();
        $yesterdayBriefings = $this->indexBriefingsByProject($this->briefings->findForDate($yesterday));

        $projectViews = array_map(
            fn (Project $p) => $this->buildProjectView($p, $yesterdayBriefings[$p->getId()] ?? null, $now),
            $projects,
        );

        $totalHours = array_sum(array_map(fn (array $v) => $v['hours'], $projectViews));

        return $this->render('home/yesterday.html.twig', [
            'projects'    => $projectViews,
            'total_hours' => $totalHours,
            'date'        => [
                'day_label'  => $yesterday->format('l'),
                'date_label' => $yesterday->format('D M j'),
                'full_label' => $yesterday->format('l, M j'),
            ],
            'briefing' => $this->buildBriefingMeta($yesterdayBriefings),
        ]);
    }

    #[Route('/rail/now', name: 'home_rail_now', methods: ['GET'])]
    public function railNow(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        $since = $request->query->get('since', '');
        $latest = $this->briefings->findMostRecent();
        $latestAt = $latest?->getGeneratedAt()->format(\DateTimeInterface::ATOM) ?? '';

        if ($since !== '' && $since !== $latestAt) {
            $response = new Response('', Response::HTTP_NO_CONTENT);
            $response->headers->set('HX-Refresh', 'true');
            return $response;
        }

        $now = $this->clock->now();
        $schedule = $this->buildScheduleView(
            $this->blocks->findForDay((int) $now->format('N')),
            $now,
        );

        return $this->render('home/_rail-now.html.twig', [
            'schedule' => $schedule['blocks'],
            'briefing_snapshot_at' => $since,
            'now' => [
                'day_label' => $now->format('l'),
                'date_label' => $now->format('D M j'),
                'time_label' => $now->format('H:i'),
                'in_block' => $schedule['in_block'],
                'up_next' => $schedule['up_next'],
            ],
        ]);
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        $now = $this->clock->now();
        $today = $now->setTime(0, 0);

        $projects = $this->projects->findActiveOrdered();
        $todaysBriefings = $this->indexBriefingsByProject($this->briefings->findForDate($today));

        $projectViews = array_map(
            fn (Project $p) => $this->buildProjectView($p, $todaysBriefings[$p->getId()] ?? null, $now),
            $projects,
        );

        $totalHours = array_sum(array_map(fn (array $v) => $v['hours'], $projectViews));

        $schedule = $this->buildScheduleView(
            $this->blocks->findForDay((int) $now->format('N')),
            $now,
        );

        $latest = $this->briefings->findMostRecent();
        $latestAt = $latest?->getGeneratedAt()->format(\DateTimeInterface::ATOM) ?? '';

        return $this->render('home/index.html.twig', [
            'projects' => $projectViews,
            'total_hours' => $totalHours,
            'schedule' => $schedule['blocks'],
            'briefing_snapshot_at' => $latestAt,
            'now' => [
                'day_label' => $now->format('l'),
                'date_label' => $now->format('D M j'),
                'time_label' => $now->format('H:i'),
                'in_block' => $schedule['in_block'],
                'up_next' => $schedule['up_next'],
            ],
            'briefing' => $this->buildBriefingMeta($todaysBriefings),
        ]);
    }

    /**
     * @param array<int, Briefing> $briefingsByProjectId
     * @return array{
     *     name: string, slug: string, color: string, hours: float, ticket_count: int,
     *     briefing_status: string, briefing_html: string,
     *     tickets: list<array{key: string, summary: string, priority: ?string, is_new: bool}>
     * }
     */
    private function buildProjectView(Project $p, ?Briefing $briefing, \DateTimeImmutable $now): array
    {
        $tickets = $this->tickets->findForProject($p);

        return [
            'id' => $p->getId(),
            'name' => $p->getName(),
            'slug' => strtolower($p->getName()),
            'color' => $p->getColor(),
            'hours' => $p->getDailyHoursTarget(),
            'ticket_count' => count($tickets),
            'jira_base_url' => $p->getJiraBaseUrl(),
            'briefing_status' => $briefing?->getStatus()->value ?? 'pending',
            'briefing_html' => $briefing
                ? $this->markdown->markdown($briefing->getContentMarkdown())
                : '<p class="briefing-meta__dim">No briefing generated yet.</p>',
            'tickets' => array_map(
                fn ($t) => [
                    'key' => $t->getTicketKey(),
                    'summary' => $t->getSummary(),
                    'priority' => $t->getPriority(),
                    'status' => $t->getStatus(),
                    'is_new' => $t->isNewAsOf($now),
                ],
                \array_slice($tickets, 0, 5),
            ),
        ];
    }

    /**
     * @param list<ScheduleBlock> $blocks
     * @return array{
     *     blocks: list<array{start: string, end: string, label: string, is_current: bool, is_past: bool}>,
     *     in_block: ?array{start: string, end: string, label: string},
     *     up_next: ?array{start: string, label: string}
     * }
     */
    private function buildScheduleView(array $blocks, \DateTimeImmutable $now): array
    {
        $nowMinutes = ((int) $now->format('H')) * 60 + (int) $now->format('i');
        $rendered = [];
        $inBlock = null;
        $upNext = null;

        foreach ($blocks as $b) {
            $start = $b->getStartTime()->format('H:i');
            $end = $b->getEndTime()->format('H:i');
            $startMin = $this->toMinutes($start);
            $endMin = $this->toMinutes($end);

            $isCurrent = $nowMinutes >= $startMin && $nowMinutes < $endMin;
            $isPast = $endMin <= $nowMinutes;

            $rendered[] = [
                'start' => $start,
                'end' => $end,
                'label' => $b->getLabel(),
                'is_current' => $isCurrent,
                'is_past' => $isPast,
            ];

            if ($isCurrent) {
                $inBlock = ['start' => $start, 'end' => $end, 'label' => $b->getLabel()];
            }
            if ($upNext === null && $startMin > $nowMinutes) {
                $upNext = ['start' => $start, 'label' => $b->getLabel()];
            }
        }

        return ['blocks' => $rendered, 'in_block' => $inBlock, 'up_next' => $upNext];
    }

    /**
     * @param array<int, Briefing> $briefingsByProjectId
     * @return array{generated_at_long: string, model: string, input_tokens: string, output_tokens: string}
     */
    private function buildBriefingMeta(array $briefingsByProjectId): array
    {
        if ($briefingsByProjectId === []) {
            return [
                'generated_at_long' => '—',
                'model' => '—',
                'input_tokens' => '0',
                'output_tokens' => '0',
            ];
        }

        $latest = null;
        $inputSum = 0;
        $outputSum = 0;
        foreach ($briefingsByProjectId as $b) {
            if ($latest === null || $b->getGeneratedAt() > $latest->getGeneratedAt()) {
                $latest = $b;
            }
            $inputSum += $b->getInputTokens() ?? 0;
            $outputSum += $b->getOutputTokens() ?? 0;
        }

        return [
            'generated_at_long' => $latest->getGeneratedAt()
                ->setTimezone($this->clock->timezone())
                ->format('D M j · H:i'),
            'model' => $latest->getModel(),
            'input_tokens' => number_format($inputSum),
            'output_tokens' => number_format($outputSum),
        ];
    }

    /**
     * @param list<Briefing> $briefings
     * @return array<int, Briefing>
     */
    private function indexBriefingsByProject(array $briefings): array
    {
        $byId = [];
        foreach ($briefings as $b) {
            $id = $b->getProject()->getId();
            if ($id !== null) {
                $byId[$id] = $b;
            }
        }
        return $byId;
    }

    private function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $h * 60 + $m;
    }
}
