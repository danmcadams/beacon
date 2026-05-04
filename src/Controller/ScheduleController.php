<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ScheduleBlock;
use App\Enum\BlockType;
use App\Repository\ScheduleBlockRepository;
use App\Wizard\ScheduleWizardState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/schedule', name: 'schedule_')]
final class ScheduleController extends AbstractController
{
    private const STEPS = ['anchors', 'overrides', 'review', 'save'];

    private const DAYS = [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
    ];

    private const GRID_HOUR_START = 7;
    private const GRID_HOUR_END   = 18;
    private const GRID_PX_PER_MIN = 1.5;

    public function __construct(
        private readonly ScheduleBlockRepository $blocks,
        private readonly ScheduleWizardState $state,
        private readonly EntityManagerInterface $em,
    ) {
    }

    // ── Editor ───────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $view     = $request->query->get('view', 'grid');
        $grouped  = $this->blocks->findAllGroupedByDay();
        $isEmpty  = array_sum(array_map('count', $grouped)) === 0;
        $weekView = $this->buildWeekView($grouped);

        return $this->render('schedule/index.html.twig', [
            'view'      => $view,
            'week'      => $weekView,
            'is_empty'  => $isEmpty,
            'days'      => self::DAYS,
            'hours'     => range(self::GRID_HOUR_START, self::GRID_HOUR_END),
            'grid_height' => (self::GRID_HOUR_END - self::GRID_HOUR_START) * 60 * self::GRID_PX_PER_MIN,
            'hour_height' => 60 * self::GRID_PX_PER_MIN,
        ]);
    }

    // ── Block add/edit/delete ─────────────────────────────────────

    #[Route('/block/new', name: 'block_new', methods: ['GET'])]
    public function blockNew(Request $request): Response
    {
        $day  = max(1, min(5, (int) $request->query->get('day', 1)));
        $time = $request->query->get('time', '09:00');

        return $this->render('schedule/_block-form.html.twig', [
            'mode'   => 'add',
            'block'  => null,
            'days'   => self::DAYS,
            'types'  => BlockType::cases(),
            'defaults' => ['day' => $day, 'start' => $time, 'end' => '', 'label' => '', 'type' => 'work_session'],
            'errors' => [],
        ]);
    }

    #[Route('/block', name: 'block_create', methods: ['POST'])]
    public function blockCreate(Request $request): Response
    {
        [$data, $errors] = $this->readBlockInput($request);

        if ($errors !== []) {
            return $this->render('schedule/_block-form.html.twig', [
                'mode'     => 'add',
                'block'    => null,
                'days'     => self::DAYS,
                'types'    => BlockType::cases(),
                'defaults' => $data,
                'errors'   => $errors,
            ]);
        }

        $block = $this->hydrateBlock(new ScheduleBlock(), $data);
        $this->em->persist($block);
        $this->em->flush();

        return $this->htmxRedirect('schedule_index');
    }

    #[Route('/block/{id}/edit', name: 'block_edit', methods: ['GET'])]
    public function blockEdit(ScheduleBlock $block): Response
    {
        return $this->render('schedule/_block-form.html.twig', [
            'mode'   => 'edit',
            'block'  => $block,
            'days'   => self::DAYS,
            'types'  => BlockType::cases(),
            'defaults' => [
                'day'   => $block->getDayOfWeek(),
                'start' => $block->getStartTime()->format('H:i'),
                'end'   => $block->getEndTime()->format('H:i'),
                'label' => $block->getLabel(),
                'type'  => $block->getBlockType()->value,
            ],
            'errors' => [],
        ]);
    }

    #[Route('/block/{id}/update', name: 'block_update', methods: ['POST'])]
    public function blockUpdate(Request $request, ScheduleBlock $block): Response
    {
        [$data, $errors] = $this->readBlockInput($request);

        if ($errors !== []) {
            return $this->render('schedule/_block-form.html.twig', [
                'mode'     => 'edit',
                'block'    => $block,
                'days'     => self::DAYS,
                'types'    => BlockType::cases(),
                'defaults' => $data,
                'errors'   => $errors,
            ]);
        }

        $this->hydrateBlock($block, $data);
        $this->em->flush();

        return $this->htmxRedirect('schedule_index');
    }

    #[Route('/block/{id}/delete', name: 'block_delete', methods: ['POST'])]
    public function blockDelete(ScheduleBlock $block): Response
    {
        $this->em->remove($block);
        $this->em->flush();

        return $this->htmxRedirect('schedule_index');
    }

    // ── Wizard ───────────────────────────────────────────────────

    #[Route('/wizard', name: 'wizard_start', methods: ['GET'])]
    public function wizardStart(): Response
    {
        return $this->redirectToRoute('schedule_wizard_anchors');
    }

    #[Route('/wizard/anchors', name: 'wizard_anchors', methods: ['GET', 'POST'])]
    public function wizardAnchors(Request $request): Response
    {
        $rows   = $this->state->step('anchors')['rows'] ?? $this->defaultAnchors();
        $errors = [];

        if ($request->isMethod('POST')) {
            $raw  = $this->parseBlockRows($request->request->all()['rows'] ?? []);
            $errors = $this->validateRows($raw, false);
            if ($errors === []) {
                $this->state->setStep('anchors', ['rows' => $raw]);
                return $this->redirectToRoute('schedule_wizard_overrides');
            }
            $rows = $raw;
        }

        return $this->render('schedule/wizard/anchors.html.twig', [
            'step'  => $this->stepView('anchors'),
            'rows'  => $rows,
            'types' => BlockType::cases(),
            'errors' => $errors,
        ]);
    }

    #[Route('/wizard/overrides', name: 'wizard_overrides', methods: ['GET', 'POST'])]
    public function wizardOverrides(Request $request): Response
    {
        if (!$this->state->hasStep('anchors')) {
            return $this->redirectToRoute('schedule_wizard_anchors');
        }

        $rows   = $this->state->step('overrides')['rows'] ?? [];
        $errors = [];

        if ($request->isMethod('POST')) {
            $raw  = $this->parseBlockRows($request->request->all()['rows'] ?? [], true);
            $errors = $this->validateRows($raw, true);
            if ($errors === []) {
                $this->state->setStep('overrides', ['rows' => $raw]);
                return $this->redirectToRoute('schedule_wizard_review');
            }
            $rows = $raw;
        }

        return $this->render('schedule/wizard/overrides.html.twig', [
            'step'   => $this->stepView('overrides'),
            'rows'   => $rows,
            'types'  => BlockType::cases(),
            'days'   => self::DAYS,
            'errors' => $errors,
        ]);
    }

    #[Route('/wizard/review', name: 'wizard_review', methods: ['GET'])]
    public function wizardReview(): Response
    {
        if (!$this->state->hasStep('overrides')) {
            return $this->redirectToRoute('schedule_wizard_overrides');
        }

        $anchors   = $this->state->step('anchors')['rows'] ?? [];
        $overrides = $this->state->step('overrides')['rows'] ?? [];
        $workMins  = array_sum(array_map(
            fn ($r) => $r['type'] === 'work_session' ? $this->minuteDiff($r['start'], $r['end']) : 0,
            $anchors
        ));

        return $this->render('schedule/wizard/review.html.twig', [
            'step'           => $this->stepView('review'),
            'anchor_count'   => \count($anchors),
            'override_count' => \count($overrides),
            'work_hours'     => round($workMins / 60, 1),
        ]);
    }

    #[Route('/wizard/save', name: 'wizard_save', methods: ['GET', 'POST'])]
    public function wizardSave(): Response
    {
        if (!$this->state->hasStep('overrides')) {
            return $this->redirectToRoute('schedule_wizard_overrides');
        }

        $anchors   = $this->state->step('anchors')['rows'] ?? [];
        $overrides = $this->state->step('overrides')['rows'] ?? [];

        $this->blocks->deleteAll();
        $this->em->clear();

        $sortOrder = 0;
        foreach ($anchors as $row) {
            for ($day = 1; $day <= 5; $day++) {
                $this->em->persist($this->buildBlock($day, $row, $sortOrder++));
            }
        }
        foreach ($overrides as $row) {
            $this->em->persist($this->buildBlock((int) $row['day'], $row, $sortOrder++));
        }
        $this->em->flush();

        $this->state->clear();
        $this->addFlash('success', 'Schedule saved.');

        return $this->redirectToRoute('schedule_index');
    }

    #[Route('/wizard/cancel', name: 'wizard_cancel', methods: ['POST'])]
    public function wizardCancel(): Response
    {
        $this->state->clear();
        return $this->redirectToRoute('schedule_index');
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * @param array<int, list<ScheduleBlock>> $grouped
     * @return array<int, list<array<string, mixed>>>
     */
    private function buildWeekView(array $grouped): array
    {
        $week = [];
        foreach ($grouped as $day => $dayBlocks) {
            $views = [];
            foreach ($dayBlocks as $block) {
                $startMin = (int) $block->getStartTime()->format('G') * 60
                          + (int) $block->getStartTime()->format('i');
                $endMin   = (int) $block->getEndTime()->format('G') * 60
                          + (int) $block->getEndTime()->format('i');
                $views[] = [
                    'block'     => $block,
                    'top'       => ($startMin - self::GRID_HOUR_START * 60) * self::GRID_PX_PER_MIN,
                    'height'    => ($endMin - $startMin) * self::GRID_PX_PER_MIN,
                    'compact'   => ($endMin - $startMin) < 30,
                    'start_fmt' => $block->getStartTime()->format('H:i'),
                    'end_fmt'   => $block->getEndTime()->format('H:i'),
                ];
            }
            $week[$day] = $views;
        }
        return $week;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function readBlockInput(Request $request): array
    {
        $day   = max(1, min(5, (int) $request->request->get('day', 1)));
        $start = trim((string) $request->request->get('start', ''));
        $end   = trim((string) $request->request->get('end', ''));
        $label = trim((string) $request->request->get('label', ''));
        $type  = trim((string) $request->request->get('type', 'work_session'));

        $data   = compact('day', 'start', 'end', 'label', 'type');
        $errors = [];

        if ($label === '') {
            $errors['label'] = 'Label is required.';
        } elseif (\strlen($label) > 100) {
            $errors['label'] = 'Label must be 100 characters or fewer.';
        }
        if (!$this->validTime($start)) {
            $errors['start'] = 'Start time is required.';
        }
        if (!$this->validTime($end)) {
            $errors['end'] = 'End time is required.';
        } elseif ($this->validTime($start) && $start >= $end) {
            $errors['end'] = 'End must be after start.';
        }
        if (!BlockType::tryFrom($type)) {
            $errors['type'] = 'Invalid type.';
        }

        return [$data, $errors];
    }

    /** @param array<string, mixed> $data */
    private function hydrateBlock(ScheduleBlock $block, array $data): ScheduleBlock
    {
        return $block
            ->setDayOfWeek((int) $data['day'])
            ->setStartTime(\DateTimeImmutable::createFromFormat('H:i', (string) $data['start']))
            ->setEndTime(\DateTimeImmutable::createFromFormat('H:i', (string) $data['end']))
            ->setLabel((string) $data['label'])
            ->setBlockType(BlockType::from((string) $data['type']));
    }

    /** @param array<string, mixed> $row */
    private function buildBlock(int $day, array $row, int $sortOrder): ScheduleBlock
    {
        $block = new ScheduleBlock();
        $block->setDayOfWeek($day)
            ->setStartTime(\DateTimeImmutable::createFromFormat('H:i', (string) $row['start']))
            ->setEndTime(\DateTimeImmutable::createFromFormat('H:i', (string) $row['end']))
            ->setLabel((string) $row['label'])
            ->setBlockType(BlockType::from((string) $row['type']))
            ->setSortOrder($sortOrder);
        return $block;
    }

    /**
     * @param array<mixed> $raw
     * @return list<array<string, string>>
     */
    private function parseBlockRows(array $raw, bool $withDay = false): array
    {
        $rows = [];
        foreach ($raw as $r) {
            if (!\is_array($r)) {
                continue;
            }
            $row = [
                'start' => trim((string) ($r['start'] ?? '')),
                'end'   => trim((string) ($r['end'] ?? '')),
                'label' => trim((string) ($r['label'] ?? '')),
                'type'  => trim((string) ($r['type'] ?? 'work_session')),
            ];
            if ($withDay) {
                $row['day'] = trim((string) ($r['day'] ?? '1'));
            }
            if ($row['start'] === '' && $row['end'] === '' && $row['label'] === '') {
                continue;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param list<array<string, string>> $rows
     * @return array<string, string>
     */
    private function validateRows(array $rows, bool $withDay): array
    {
        foreach ($rows as $i => $row) {
            if ($row['label'] === '') {
                return [$i => 'Label is required.'];
            }
            if (!$this->validTime($row['start'])) {
                return [$i => 'Start time is required.'];
            }
            if (!$this->validTime($row['end'])) {
                return [$i => 'End time is required.'];
            }
            if ($row['start'] >= $row['end']) {
                return [$i => 'End must be after start.'];
            }
            if (!BlockType::tryFrom($row['type'])) {
                return [$i => 'Invalid type.'];
            }
            if ($withDay && !isset(self::DAYS[(int) $row['day']])) {
                return [$i => 'Invalid day.'];
            }
        }
        return [];
    }

    private function validTime(string $t): bool
    {
        return (bool) preg_match('/^\d{2}:\d{2}$/', $t);
    }

    private function minuteDiff(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));
        return ($eh * 60 + $em) - ($sh * 60 + $sm);
    }

    /** @return list<array<string, string>> */
    private function defaultAnchors(): array
    {
        return [
            ['start' => '09:00', 'end' => '11:00', 'label' => 'Focused work · #1', 'type' => 'work_session'],
            ['start' => '11:00', 'end' => '11:15', 'label' => 'Break',              'type' => 'break'],
            ['start' => '11:15', 'end' => '12:30', 'label' => 'Focused work · #2', 'type' => 'work_session'],
            ['start' => '12:30', 'end' => '13:30', 'label' => 'Lunch',              'type' => 'break'],
        ];
    }

    /**
     * @return array{name: string, index: int, total: int, all: list<array{name: string, index: int, active: bool, complete: bool}>}
     */
    private function stepView(string $current): array
    {
        $index = array_search($current, self::STEPS, true);
        $all   = [];
        foreach (self::STEPS as $i => $name) {
            $all[] = [
                'name'     => $name,
                'index'    => $i + 1,
                'active'   => $name === $current,
                'complete' => $i < $index,
            ];
        }
        return [
            'name'  => $current,
            'index' => $index + 1,
            'total' => \count(self::STEPS),
            'all'   => $all,
        ];
    }

    private function htmxRedirect(string $route): Response
    {
        $response = new Response();
        $response->headers->set('HX-Redirect', $this->generateUrl($route));
        return $response;
    }
}
