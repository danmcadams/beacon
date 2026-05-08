<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Briefing;
use App\Entity\JiraTicket;
use App\Entity\Project;
use App\Enum\BriefingStatus;
use App\Repository\BriefingRepository;
use App\Repository\JiraTicketRepository;
use App\Repository\ProjectRepository;
use App\Service\BriefingGeneratorInterface;
use App\Service\ClockService;
use App\Twig\MarkdownExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BriefingController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly BriefingRepository $briefings,
        private readonly JiraTicketRepository $tickets,
        private readonly BriefingGeneratorInterface $generator,
        private readonly MarkdownExtension $markdown,
        private readonly ClockService $clock,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/projects/{id}/regenerate-briefing', name: 'project_regenerate_briefing', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function regenerateForDetail(Project $project): Response
    {
        $briefing = $this->regenerate($project);

        return $this->render('projects/_briefing.html.twig', [
            'project' => ['id' => $project->getId(), 'color' => $project->getColor()],
            'briefing_html' => $this->renderBriefingHtml($briefing, '<p class="dimmer">No briefing generated yet for today.</p>'),
        ]);
    }

    #[Route('/projects/{id}/regenerate-briefing-card', name: 'project_regenerate_briefing_card', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function regenerateForCard(Project $project): Response
    {
        $briefing = $this->regenerate($project);
        $tickets = $this->tickets->findForProject($project);

        return $this->render('_partials/_project-card.html.twig', [
            'project' => [
                'id' => $project->getId(),
                'name' => $project->getName(),
                'slug' => strtolower($project->getName()),
                'color' => $project->getColor(),
                'hours' => $project->getDailyHoursTarget(),
                'ticket_count' => \count($tickets),
                'jira_base_url' => $project->getJiraBaseUrl(),
                'briefing_status' => $briefing->getStatus()->value,
                'briefing_html' => $this->renderBriefingHtml($briefing, '<p class="briefing-meta__dim">No briefing generated yet.</p>'),
                'tickets' => array_map(
                    static fn (JiraTicket $t) => [
                        'key' => $t->getTicketKey(),
                        'summary' => $t->getSummary(),
                        'priority' => $t->getPriority(),
                        'status' => $t->getStatus(),
                    ],
                    \array_slice($tickets, 0, 5),
                ),
            ],
        ]);
    }

    #[Route('/briefings/regenerate-all', name: 'briefings_regenerate_all', methods: ['POST'])]
    public function regenerateAll(): Response
    {
        foreach ($this->projects->findActiveOrdered() as $project) {
            $this->regenerate($project);
        }

        $response = new Response();
        $response->headers->set('HX-Refresh', 'true');

        return $response;
    }

    private function regenerate(Project $project): Briefing
    {
        $today = ($this->clock->now())->setTime(0, 0);
        $tickets = $this->tickets->findForProject($project);

        $briefing = $this->briefings->findOneForDateAndProject($today, $project) ?? new Briefing();
        $isNew = $briefing->getId() === null;

        $briefing->setProject($project)
            ->setBriefingDate($today)
            ->setTicketsSnapshot($this->snapshotTickets($tickets))
            ->setGeneratedAt(new \DateTimeImmutable())
            ->setErrorMessage(null);

        try {
            if ($tickets === []) {
                $briefing->setContentMarkdown('_No tickets in cache. Run a Jira refresh, then regenerate._')
                    ->setStatus(BriefingStatus::Skipped)
                    ->setModel('skipped')
                    ->setInputTokens(0)
                    ->setOutputTokens(0);
            } else {
                $result = $this->generator->generate($project, $tickets);
                $briefing->setContentMarkdown($result->markdown)
                    ->setStatus(BriefingStatus::Success)
                    ->setModel($result->model)
                    ->setInputTokens($result->inputTokens)
                    ->setOutputTokens($result->outputTokens);
            }
        } catch (\Throwable $e) {
            $briefing->setStatus(BriefingStatus::Failed)
                ->setErrorMessage($e->getMessage())
                ->setModel($briefing->getModel() ?: 'failed')
                ->setContentMarkdown($briefing->getContentMarkdown() ?: '');
        }

        if ($isNew) {
            $this->em->persist($briefing);
        }
        $this->em->flush();

        return $briefing;
    }

    private function renderBriefingHtml(Briefing $briefing, string $emptyHtml): string
    {
        return match ($briefing->getStatus()) {
            BriefingStatus::Success => $this->markdown->markdown($briefing->getContentMarkdown()),
            BriefingStatus::Failed => '<p class="dimmer">Failed to regenerate briefing: ' . htmlspecialchars($briefing->getErrorMessage() ?? 'unknown error') . '</p>',
            default => $emptyHtml,
        };
    }

    /**
     * @param list<JiraTicket> $tickets
     * @return list<array{key: string, summary: string, priority: ?string, status: string}>
     */
    private function snapshotTickets(array $tickets): array
    {
        return array_map(
            static fn (JiraTicket $t) => [
                'key' => $t->getTicketKey(),
                'summary' => $t->getSummary(),
                'priority' => $t->getPriority(),
                'status' => $t->getStatus(),
            ],
            $tickets,
        );
    }
}
