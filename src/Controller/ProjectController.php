<?php

declare(strict_types=1);

namespace App\Controller;

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
        $filterRaw = (string) $request->query->get('status', 'all');
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
            ], $tickets),
            'ticket_count' => count($tickets),
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
            ], $tickets),
            'ticket_count' => count($tickets),
            'last_refreshed' => $lastRefreshed,
        ]);
    }

    #[Route('/projects/{id}/edit', name: 'project_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Project $project, Request $request): Response
    {
        $swatchColors = ['#89b4fa', '#a6e3a1', '#f9e2af', '#f38ba8', '#cba6f7', '#94e2d5', '#fab387', '#f5c2e7'];

        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name', ''));
            $description = trim((string) $request->request->get('description', ''));
            $color = trim((string) $request->request->get('color', '#cba6f7'));
            $statusRaw = (string) $request->request->get('status', 'active');
            $jiraUrl = trim((string) $request->request->get('jira_url', ''));
            $jiraJql = trim((string) $request->request->get('jira_jql_filter', ''));
            $hoursRaw = (string) $request->request->get('daily_hours_target', '0');
            $hours = is_numeric($hoursRaw) ? (float) $hoursRaw : -1.0;

            $parsed = $this->jiraUrlParser->parse($jiraUrl);
            $jiraBaseUrl = $parsed['base_url'];
            $jiraBoardId = $parsed['project_key'] ?? '';

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
            if ($jiraUrl === '') {
                $errors['jira_url'] = 'Jira URL is required.';
            } elseif ($jiraBaseUrl === '' || $jiraBoardId === '') {
                $errors['jira_url'] = 'Could not parse a project key from that URL — paste a board, backlog, or project URL.';
            }
            if ($hours < 0 || $hours > 24) {
                $errors['daily_hours_target'] = 'Daily target must be between 0 and 24.';
            }

            if ($errors === []) {
                $project->setName($name);
                $project->setDescription($description !== '' ? $description : null);
                $project->setColor($color);
                $project->setStatus($status);
                $project->setJiraBaseUrl($jiraBaseUrl);
                $project->setJiraBoardId($jiraBoardId);
                $project->setJiraJqlFilter($jiraJql !== '' ? $jiraJql : null);
                $project->setDailyHoursTarget($hours);

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
                    'jira_url' => $jiraUrl,
                    'jira_parsed_host' => $jiraBaseUrl,
                    'jira_parsed_key' => $jiraBoardId,
                    'jira_jql_filter' => $jiraJql,
                    'daily_hours_target' => $hours,
                ],
                'errors' => $errors,
                'test_result' => null,
                'swatch_colors' => $swatchColors,
            ]);
        }

        $reconstructedUrl = sprintf('%s/projects/%s', $project->getJiraBaseUrl(), $project->getJiraBoardId());

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
                'jira_url' => $reconstructedUrl,
                'jira_parsed_host' => $project->getJiraBaseUrl(),
                'jira_parsed_key' => $project->getJiraBoardId(),
                'jira_jql_filter' => $project->getJiraJqlFilter() ?? '',
                'daily_hours_target' => $project->getDailyHoursTarget(),
            ],
            'errors' => [],
            'test_result' => null,
            'swatch_colors' => $swatchColors,
        ]);
    }

    #[Route('/projects/{id}/edit/jira-test', name: 'project_edit_jira_test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editJiraTest(Project $project, Request $request): Response
    {
        $jiraUrl = trim((string) $request->request->get('jira_url', ''));
        $jql = trim((string) $request->request->get('jira_jql_filter', ''));

        $parsed = $this->jiraUrlParser->parse($jiraUrl);

        $result = $this->jiraTester->test(
            $parsed['base_url'],
            $parsed['project_key'] ?? '',
            $jql !== '' ? $jql : null,
        );

        return $this->render('projects/wizard/_jira-test-result.html.twig', ['result' => $result]);
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
