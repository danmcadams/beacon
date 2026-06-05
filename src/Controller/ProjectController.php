<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\JiraBoard;
use App\Entity\JiraTicket;
use App\Entity\Project;
use App\Entity\QuickLink;
use App\Enum\BriefingStatus;
use App\Enum\ProjectStatus;
use App\Repository\BriefingRepository;
use App\Repository\JiraTicketRepository;
use App\Repository\ProjectRepository;
use App\Service\JiraConnectionTester;
use App\Service\JiraTicketRefresher;
use App\Service\JiraUrlParser;
use App\Service\ClockService;
use App\Twig\MarkdownExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly JiraTicketRepository $tickets,
        private readonly BriefingRepository $briefings,
        private readonly MarkdownExtension $markdown,
        private readonly JiraConnectionTester $jiraTester,
        private readonly JiraTicketRefresher $jiraRefresher,
        private readonly JiraUrlParser $jiraUrlParser,
        private readonly EntityManagerInterface $em,
        private readonly ClockService $clock,
    ) {
    }

    #[Route('/projects', name: 'projects_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filterRaw = (string) $request->query->get('status', ProjectStatus::Active->value);
        $filter = ProjectStatus::tryFrom($filterRaw);

        $all = $this->projects->findOrdered();
        $rows = $filter === null
            ? $all
            : array_values(array_filter($all, fn (Project $p) => $p->getStatus() === $filter));

        $counts = [
            'all' => \count($all),
            'active' => \count(array_filter($all, fn (Project $p) => $p->getStatus() === ProjectStatus::Active)),
            'on_hold' => \count(array_filter($all, fn (Project $p) => $p->getStatus() === ProjectStatus::OnHold)),
            'archived' => \count(array_filter($all, fn (Project $p) => $p->getStatus() === ProjectStatus::Archived)),
        ];

        $today = ($this->clock->now())->setTime(0, 0);
        $todays = [];
        foreach ($this->briefings->findForDate($today) as $b) {
            $todays[$b->getProject()->getId()] = $b;
        }

        $now = $this->clock->now();
        $rowViews = array_map(
            fn (Project $p) => [
                'id' => $p->getId(),
                'name' => $p->getName(),
                'color' => $p->getColor(),
                'status' => $p->getStatus()->value,
                'hours' => $p->getDailyHoursTarget(),
                'ticket_count' => \count($this->tickets->findForProject($p)),
                'briefing_status' => ($todays[$p->getId()] ?? null)?->getStatus()->value ?? 'pending',
                'updated_relative' => $this->relativeTime($p->getUpdatedAt(), $now),
            ],
            $rows,
        );

        return $this->render('projects/index.html.twig', [
            'rows' => $rowViews,
            'counts' => $counts,
            'active_filter' => $filter?->value ?? 'all',
            'filters' => [
                ['value' => 'all', 'label' => 'All'],
                ['value' => ProjectStatus::Active->value, 'label' => 'Active'],
                ['value' => ProjectStatus::OnHold->value, 'label' => 'On hold'],
                ['value' => ProjectStatus::Archived->value, 'label' => 'Archived'],
            ],
        ]);
    }

    #[Route('/projects/reorder', name: 'projects_reorder', methods: ['POST'])]
    public function reorder(Request $request): Response
    {
        $data = json_decode((string) $request->getContent(), true);
        $ids = \is_array($data['ids'] ?? null) ? $data['ids'] : [];

        foreach ($ids as $position => $id) {
            $project = $this->projects->find((int) $id);
            if ($project !== null) {
                $project->setSortOrder($position);
            }
        }

        $this->em->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/projects/{id}', name: 'project_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Project $project): Response
    {
        $today = ($this->clock->now())->setTime(0, 0);
        $now = $this->clock->now();

        $briefing = $this->briefings->findOneForDateAndProject($today, $project);
        $tickets = $this->tickets->findForProject($project);

        $lastRefreshed = 'never';
        if ($tickets !== []) {
            $latest = array_reduce(
                $tickets,
                fn (?JiraTicket $carry, JiraTicket $t) => ($carry === null || $t->getFetchedAt() > $carry->getFetchedAt()) ? $t : $carry,
            );
            $lastRefreshed = $this->relativeTime($latest->getFetchedAt(), $now);
        }

        $briefingHtml = ($briefing !== null && $briefing->getStatus() === BriefingStatus::Success)
            ? $this->markdown->markdown($briefing->getContentMarkdown())
            : '<p class="dimmer">No briefing generated yet for today.</p>';

        return $this->render('projects/show.html.twig', [
            'project' => [
                'id' => $project->getId(),
                'name' => $project->getName(),
                'color' => $project->getColor(),
                'status' => $project->getStatus()->value,
                'hours' => $project->getDailyHoursTarget(),
                'jira_base_url' => $project->getJiraBaseUrl(),
            ],
            'briefing_html' => $briefingHtml,
            'briefing_has_content' => $briefing?->getStatus() === BriefingStatus::Success,
            'tickets' => array_map(fn (JiraTicket $t) => [
                'key' => $t->getTicketKey(),
                'summary' => $t->getSummary(),
                'status' => $t->getStatus(),
                'is_new' => $t->isNewAsOf($now),
                'board' => $this->boardName($t->getBoard()),
            ], $tickets),
            'ticket_count' => count($tickets),
            'show_boards' => $project->getBoards()->count() > 1,
            'quick_links' => array_map(fn (QuickLink $ql) => [
                'label' => $ql->getLabel(),
                'url' => $ql->getUrl(),
            ], $project->getQuickLinks()->toArray()),
            'last_refreshed' => $lastRefreshed,
        ]);
    }

    #[Route('/projects/{id}/refresh-tickets-card', name: 'project_refresh_tickets_card', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refreshTicketsCard(Project $project): Response
    {
        $this->jiraRefresher->refresh($project);

        $now = $this->clock->now();
        $tickets = $this->tickets->findForProject($project);

        return $this->render('_partials/_card-tickets.html.twig', [
            'project' => [
                'id' => $project->getId(),
                'color' => $project->getColor(),
                'jira_base_url' => $project->getJiraBaseUrl(),
                'ticket_count' => count($tickets),
            ],
            'tickets' => array_map(fn (JiraTicket $t) => [
                'key' => $t->getTicketKey(),
                'summary' => $t->getSummary(),
                'status' => $t->getStatus(),
                'priority' => $t->getPriority(),
                'is_new' => $t->isNewAsOf($now),
            ], \array_slice($tickets, 0, 5)),
        ]);
    }

    #[Route('/projects/{id}/refresh-tickets', name: 'project_refresh_tickets', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refreshTickets(Project $project): Response
    {
        $this->jiraRefresher->refresh($project);

        $now = $this->clock->now();
        $tickets = $this->tickets->findForProject($project);

        $lastRefreshed = 'just now';
        if ($tickets !== []) {
            $latest = array_reduce(
                $tickets,
                fn (?JiraTicket $carry, JiraTicket $t) => ($carry === null || $t->getFetchedAt() > $carry->getFetchedAt()) ? $t : $carry,
            );
            $lastRefreshed = $this->relativeTime($latest->getFetchedAt(), $now);
        }

        return $this->render('projects/_tickets-section.html.twig', [
            'project' => [
                'id' => $project->getId(),
                'color' => $project->getColor(),
                'jira_base_url' => $project->getJiraBaseUrl(),
            ],
            'tickets' => array_map(fn (JiraTicket $t) => [
                'key' => $t->getTicketKey(),
                'summary' => $t->getSummary(),
                'status' => $t->getStatus(),
                'is_new' => $t->isNewAsOf($now),
                'board' => $this->boardName($t->getBoard()),
            ], $tickets),
            'ticket_count' => count($tickets),
            'show_boards' => $project->getBoards()->count() > 1,
            'last_refreshed' => $lastRefreshed,
        ]);
    }

    #[Route('/projects/{id}/edit', name: 'project_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Project $project, Request $request): Response
    {
        $swatchColors = [
            '#89b4fa', '#a6e3a1', '#f9e2af', '#f38ba8', '#cba6f7', '#94e2d5',
            '#fab387', '#f5c2e7', '#89dceb', '#74c7ec', '#b4befe', '#eba0ac',
        ];

        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name', ''));
            $description = trim((string) $request->request->get('description', ''));
            $color = trim((string) $request->request->get('color', '#cba6f7'));
            $statusRaw = (string) $request->request->get('status', 'active');
            $jiraBaseUrlInput = trim((string) $request->request->get('jira_base_url', ''));
            $briefingContext = trim((string) $request->request->get('briefing_context', ''));
            $hoursRaw = (string) $request->request->get('daily_hours_target', '0');
            $hours = is_numeric($hoursRaw) ? (float) $hoursRaw : -1.0;

            $linkIds    = $request->request->all('link_id');
            $linkLabels = $request->request->all('link_label');
            $linkUrls   = $request->request->all('link_url');

            $jiraBaseUrl = $this->jiraUrlParser->parse($jiraBaseUrlInput)['base_url'];
            $submittedBoards = $this->readBoardRows($request);

            $errors = [];
            if ($name === '') {
                $errors['name'] = 'Name is required.';
            } elseif (\strlen($name) > 100) {
                $errors['name'] = 'Name must be 100 characters or fewer.';
            }
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $errors['color'] = 'Color must be a hex value like #cba6f7.';
            }
            $status = ProjectStatus::tryFrom($statusRaw);
            if ($status === null) {
                $errors['status'] = 'Invalid status.';
            }
            if ($jiraBaseUrlInput === '') {
                $errors['jira_base_url'] = 'Jira base URL is required.';
            } elseif ($jiraBaseUrl === '') {
                $errors['jira_base_url'] = 'That doesn\'t look like a valid URL (e.g. https://yourorg.atlassian.net).';
            }
            $boardErrors = [];
            foreach ($submittedBoards as $i => $b) {
                if ($b['key'] === '') {
                    continue;
                }
                if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $b['key'])) {
                    $boardErrors[$i] = 'Invalid project key — letters, digits, and underscores only.';
                }
            }
            if ($boardErrors !== []) {
                $errors['board_rows'] = $boardErrors;
            }
            if (array_filter($submittedBoards, fn (array $b) => $b['key'] !== '') === []) {
                $errors['boards'] = 'Add at least one board (a Jira project key).';
            }
            if ($hours < 0 || $hours > 24) {
                $errors['daily_hours_target'] = 'Daily target must be between 0 and 24.';
            }

            $existingLinkIds = array_flip(
                array_map(fn (QuickLink $ql) => (string) $ql->getId(), $project->getQuickLinks()->toArray())
            );
            $linkRowCount = max(\count($linkIds), \count($linkLabels), \count($linkUrls));
            $linkErrors = [];
            for ($i = 0; $i < $linkRowCount; $i++) {
                $rowLabel = trim((string) ($linkLabels[$i] ?? ''));
                $rowUrl   = trim((string) ($linkUrls[$i] ?? ''));
                if ($rowLabel === '' && $rowUrl === '') {
                    continue;
                }
                if ($rowLabel === '') {
                    $linkErrors[$i] = ['field' => 'label', 'message' => 'Label is required.'];
                } elseif (\strlen($rowLabel) > 100) {
                    $linkErrors[$i] = ['field' => 'label', 'message' => 'Label too long.'];
                } elseif ($rowUrl === '') {
                    $linkErrors[$i] = ['field' => 'url', 'message' => 'URL is required.'];
                } elseif (\strlen($rowUrl) > 500) {
                    $linkErrors[$i] = ['field' => 'url', 'message' => 'URL too long.'];
                } elseif (filter_var($rowUrl, FILTER_VALIDATE_URL) === false) {
                    $linkErrors[$i] = ['field' => 'url', 'message' => 'URL is invalid.'];
                }
            }
            if ($linkErrors !== []) {
                $errors['links'] = $linkErrors;
            }

            $submittedLinks = [];
            for ($i = 0; $i < $linkRowCount; $i++) {
                $submittedLinks[] = [
                    'id'    => trim((string) ($linkIds[$i] ?? '')),
                    'label' => trim((string) ($linkLabels[$i] ?? '')),
                    'url'   => trim((string) ($linkUrls[$i] ?? '')),
                ];
            }

            if ($errors === []) {
                $project->setName($name);
                $project->setDescription($description !== '' ? $description : null);
                $project->setColor($color);
                $project->setStatus($status);
                $project->setJiraBaseUrl($jiraBaseUrl);
                $primary = $this->syncBoards($project, $submittedBoards);

                // Keep the legacy single-board columns mirroring the primary board until they're dropped.
                $project->setJiraBoardId($primary?->getProjectKey() ?? '');
                $project->setJiraJqlFilter($primary?->getJqlFilter());

                $project->setBriefingContext($briefingContext !== '' ? $briefingContext : null);
                $project->setDailyHoursTarget($hours);

                $submittedById = [];
                foreach ($submittedLinks as $i => $row) {
                    if ($row['label'] === '' && $row['url'] === '') {
                        continue;
                    }
                    if ($row['id'] !== '') {
                        if (isset($existingLinkIds[$row['id']])) {
                            $submittedById[(int) $row['id']] = ['label' => $row['label'], 'url' => $row['url'], 'sort' => $i];
                        }
                        // non-matching non-empty ID = tampered; silently ignore the row
                    }
                }

                foreach ($project->getQuickLinks()->toArray() as $ql) {
                    /** @var QuickLink $ql */
                    $id = $ql->getId();
                    if ($id !== null && isset($submittedById[$id])) {
                        $ql->setLabel($submittedById[$id]['label']);
                        $ql->setUrl($submittedById[$id]['url']);
                        $ql->setSortOrder($submittedById[$id]['sort']);
                    } else {
                        $this->em->remove($ql);
                    }
                }

                foreach ($submittedLinks as $i => $row) {
                    if ($row['label'] === '' && $row['url'] === '') {
                        continue;
                    }
                    if ($row['id'] === '') {
                        $ql = new QuickLink();
                        $ql->setProject($project);
                        $ql->setLabel($row['label']);
                        $ql->setUrl($row['url']);
                        $ql->setSortOrder($i);
                        $project->getQuickLinks()->add($ql);
                        $this->em->persist($ql);
                    }
                }

                $this->em->flush();

                return $this->redirectToRoute('project_detail', ['id' => $project->getId()]);
            }

            return $this->render('projects/edit.html.twig', [
                'project' => ['id' => $project->getId(), 'name' => $name, 'color' => $color],
                'form' => [
                    'name' => $name,
                    'description' => $description,
                    'color' => $color,
                    'status' => $statusRaw,
                    'jira_base_url' => $jiraBaseUrlInput,
                    'boards' => $submittedBoards,
                    'briefing_context' => $briefingContext,
                    'daily_hours_target' => $hours,
                    'links' => $submittedLinks,
                ],
                'errors' => $errors,
                'test_result' => null,
                'swatch_colors' => $swatchColors,
            ]);
        }

        $existingLinks = array_map(
            fn (QuickLink $ql) => ['id' => $ql->getId(), 'label' => $ql->getLabel(), 'url' => $ql->getUrl()],
            $project->getQuickLinks()->toArray(),
        );

        return $this->render('projects/edit.html.twig', [
            'project' => [
                'id' => $project->getId(),
                'name' => $project->getName(),
                'color' => $project->getColor(),
            ],
            'form' => [
                'name' => $project->getName(),
                'description' => $project->getDescription() ?? '',
                'color' => $project->getColor(),
                'status' => $project->getStatus()->value,
                'jira_base_url' => $project->getJiraBaseUrl(),
                'boards' => array_map(
                    fn (JiraBoard $b) => [
                        'id' => $b->getId(),
                        'key' => $b->getProjectKey(),
                        'label' => $b->getLabel() ?? '',
                        'jql' => $b->getJqlFilter() ?? '',
                    ],
                    $project->getBoards()->toArray(),
                ),
                'briefing_context' => $project->getBriefingContext() ?? '',
                'daily_hours_target' => $project->getDailyHoursTarget(),
                'links' => $existingLinks,
            ],
            'errors' => [],
            'test_result' => null,
            'swatch_colors' => $swatchColors,
        ]);
    }

    #[Route('/projects/{id}/archive', name: 'project_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(Project $project): Response
    {
        $project->setStatus(ProjectStatus::Archived);
        $this->em->flush();

        $response = new Response();
        $response->headers->set('HX-Redirect', $this->generateUrl('projects_index'));

        return $response;
    }

    #[Route('/projects/{id}/delete', name: 'project_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Project $project): Response
    {
        $this->em->remove($project);
        $this->em->flush();

        $response = new Response();
        $response->headers->set('HX-Redirect', $this->generateUrl('projects_index'));

        return $response;
    }

    #[Route('/projects/{id}/edit/jira-test', name: 'project_edit_jira_test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editJiraTest(Project $project, Request $request): Response
    {
        $baseUrl = $this->jiraUrlParser->parse(trim((string) $request->request->get('jira_base_url', '')))['base_url'];

        // project-edit.js sends a single row's values, so the key/jql are unambiguous.
        $rawKey = trim((string) $request->request->get('test_key', ''));
        if (str_starts_with($rawKey, 'http')) {
            $rawKey = $this->jiraUrlParser->parse($rawKey)['project_key'] ?? '';
        }
        $jql = trim((string) $request->request->get('test_jql', ''));

        $result = $this->jiraTester->test(
            $baseUrl,
            strtoupper($rawKey),
            $jql !== '' ? $jql : null,
        );

        return $this->render('projects/wizard/_jira-test-result.html.twig', ['result' => $result]);
    }

    private function boardName(?JiraBoard $board): ?string
    {
        if ($board === null) {
            return null;
        }

        return $board->getLabel() ?? $board->getProjectKey();
    }

    /**
     * @return list<array{id: string, key: string, label: string, jql: string}>
     */
    private function readBoardRows(Request $request): array
    {
        $ids    = $request->request->all('board_id');
        $keys   = $request->request->all('board_key');
        $labels = $request->request->all('board_label');
        $jqls   = $request->request->all('board_jql');

        $count = max(\count($ids), \count($keys), \count($labels), \count($jqls));
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rawKey = trim((string) ($keys[$i] ?? ''));
            if (str_starts_with($rawKey, 'http')) {
                $rawKey = $this->jiraUrlParser->parse($rawKey)['project_key'] ?? '';
            }
            $rows[] = [
                'id'    => trim((string) ($ids[$i] ?? '')),
                'key'   => strtoupper($rawKey),
                'label' => trim((string) ($labels[$i] ?? '')),
                'jql'   => trim((string) ($jqls[$i] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * Reconcile a project's boards against submitted rows: update matched by id, create new, remove missing.
     * Rows with an empty key are dropped. Returns the primary (first) board, or null if none.
     *
     * @param list<array{id: string, key: string, label: string, jql: string}> $submitted
     */
    private function syncBoards(Project $project, array $submitted): ?JiraBoard
    {
        $rows = array_values(array_filter($submitted, static fn (array $b) => $b['key'] !== ''));

        $existingById = [];
        foreach ($project->getBoards()->toArray() as $b) {
            /** @var JiraBoard $b */
            if ($b->getId() !== null) {
                $existingById[$b->getId()] = $b;
            }
        }

        $kept = [];
        $primary = null;
        foreach ($rows as $i => $row) {
            $id = $row['id'] !== '' ? (int) $row['id'] : null;
            $board = ($id !== null && isset($existingById[$id])) ? $existingById[$id] : null;
            if ($board === null) {
                $board = new JiraBoard();
                $project->addBoard($board);
                $this->em->persist($board);
            }
            $board->setProjectKey($row['key'])
                ->setLabel($row['label'] !== '' ? $row['label'] : null)
                ->setJqlFilter($row['jql'] !== '' ? $row['jql'] : null)
                ->setSortOrder($i);
            $kept[spl_object_id($board)] = true;
            if ($i === 0) {
                $primary = $board;
            }
        }

        foreach ($project->getBoards()->toArray() as $b) {
            /** @var JiraBoard $b */
            if (!isset($kept[spl_object_id($b)])) {
                $project->getBoards()->removeElement($b);
                $this->em->remove($b);
            }
        }

        return $primary;
    }

    private function relativeTime(\DateTimeImmutable $then, \DateTimeImmutable $now): string
    {
        $secs = $now->getTimestamp() - $then->getTimestamp();
        if ($secs < 60) {
            return 'just now';
        }
        if ($secs < 3600) {
            return (int) ($secs / 60) . 'm ago';
        }
        if ($secs < 86400) {
            return (int) ($secs / 3600) . 'h ago';
        }
        $days = (int) ($secs / 86400);
        if ($days < 14) {
            return $days . 'd ago';
        }

        return (int) ($days / 7) . 'w ago';
    }
}
