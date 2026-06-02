<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Briefing;
use App\Entity\JiraTicket;
use App\Entity\Project;
use App\Enum\BriefingStatus;
use App\Repository\BriefingRepository;
use App\Repository\JiraTicketRepository;
use App\Repository\ProjectRepository;
use App\Service\BriefingGeneratorInterface;
use App\Service\BriefingResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:briefing:generate',
    description: 'Generate today\'s briefings for active projects (idempotent on date).',
)]
final class GenerateBriefingsCommand extends Command
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly BriefingRepository $briefings,
        private readonly JiraTicketRepository $tickets,
        private readonly BriefingGeneratorInterface $generator,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\ClockService $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing briefing for today.')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Limit to a single project (match by lowercased name).')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Generate for a specific date (YYYY-MM-DD). Defaults to today.')
            ->addOption('stub', null, InputOption::VALUE_NONE, 'Use placeholder content instead of calling the Claude API.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $date = $this->resolveDate($input->getOption('date'));
        $force = (bool) $input->getOption('force');
        $stub = (bool) $input->getOption('stub');
        $projectFilter = $input->getOption('project');

        $projects = $this->projects->findActiveOrdered();
        if (\is_string($projectFilter) && $projectFilter !== '') {
            $needle = strtolower($projectFilter);
            $projects = array_values(array_filter(
                $projects,
                static fn (Project $p) => strtolower($p->getName()) === $needle,
            ));
            if ($projects === []) {
                $io->error(sprintf('No active project matches "%s".', $projectFilter));

                return Command::FAILURE;
            }
        }

        $rows = [];
        foreach ($projects as $project) {
            $rows[] = $this->processProject($project, $date, $force, $stub);
        }

        $this->em->flush();

        $io->table(
            ['Project', 'Date', 'Result', 'Tickets', 'In/Out tokens', 'Model'],
            $rows,
        );

        return Command::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: int, 4: string, 5: string}
     */
    private function processProject(Project $project, \DateTimeImmutable $date, bool $force, bool $stub): array
    {
        $existing = $this->briefings->findOneForDateAndProject($date, $project);

        if ($existing !== null && !$force) {
            return [
                $project->getName(),
                $date->format('Y-m-d'),
                'skipped (exists)',
                0,
                '—',
                $existing->getModel(),
            ];
        }

        $tickets = $this->tickets->findForProject($project);
        $briefing = $existing ?? new Briefing();
        $briefing->setProject($project)
            ->setBriefingDate($date)
            ->setTicketsSnapshot($this->snapshotTickets($tickets))
            ->setGeneratedAt(new \DateTimeImmutable())
            ->setErrorMessage(null);

        try {
            if ($stub) {
                $briefing->setContentMarkdown($this->renderStubMarkdown($project, $tickets))
                    ->setStatus(BriefingStatus::Success)
                    ->setModel('stub-v1')
                    ->setInputTokens(0)
                    ->setOutputTokens(0);

                $tokenSummary = '0 / 0';
                $resultLabel = $existing === null ? 'created (stub)' : 'updated (stub)';
            } elseif ($tickets === [] && $existing === null) {
                // Don't burn API tokens on an empty project the first time we see it.
                $briefing->setContentMarkdown('_No tickets in cache. Run a Jira refresh, then regenerate._')
                    ->setStatus(BriefingStatus::Skipped)
                    ->setModel('skipped')
                    ->setInputTokens(0)
                    ->setOutputTokens(0);

                $tokenSummary = '0 / 0';
                $resultLabel = 'skipped (no tickets)';
            } else {
                $result = $this->generator->generate($project, $tickets);
                $briefing->setContentMarkdown($result->markdown)
                    ->setStatus(BriefingStatus::Success)
                    ->setModel($result->model)
                    ->setInputTokens($result->inputTokens)
                    ->setOutputTokens($result->outputTokens);

                $tokenSummary = $this->formatTokenSummary($result);
                $resultLabel = $existing === null ? 'created' : 'updated';
            }
        } catch (\Throwable $e) {
            $briefing->setStatus(BriefingStatus::Failed)
                ->setErrorMessage($e->getMessage())
                ->setModel('failed')
                ->setContentMarkdown($briefing->getContentMarkdown() ?: '');

            $tokenSummary = '—';
            $resultLabel = 'failed';
        }

        if ($existing === null) {
            $this->em->persist($briefing);
        }

        return [
            $project->getName(),
            $date->format('Y-m-d'),
            $resultLabel,
            \count($tickets),
            $tokenSummary,
            $briefing->getModel(),
        ];
    }

    private function formatTokenSummary(BriefingResult $r): string
    {
        $cache = $r->cacheReadInputTokens > 0 || $r->cacheCreationInputTokens > 0
            ? sprintf(' (cache: %d read / %d write)', $r->cacheReadInputTokens, $r->cacheCreationInputTokens)
            : '';

        return sprintf('%d / %d%s', $r->inputTokens, $r->outputTokens, $cache);
    }

    /**
     * @param list<JiraTicket> $tickets
     */
    private function renderStubMarkdown(Project $project, array $tickets): string
    {
        if ($tickets === []) {
            return sprintf(
                "_Stub briefing for **%s** on %s._\n\nNo tickets in cache. Run a Jira refresh, then regenerate.",
                $project->getName(),
                (new \DateTimeImmutable())->format('D M j'),
            );
        }

        $now = $this->clock->now();
        $bullets = array_map(
            static fn (JiraTicket $t) => sprintf(
                '- `%s` — %s _(%s)_%s',
                $t->getTicketKey(),
                $t->getSummary(),
                $t->getPriority() ?? 'no priority',
                $t->isNewAsOf($now) ? ' **[new]**' : '',
            ),
            $tickets,
        );

        return sprintf(
            "_Stub briefing for **%s** (%s hr/day target). Replace with real Claude output once the API integration lands._\n\n%s",
            $project->getName(),
            number_format($project->getDailyHoursTarget(), 1),
            implode("\n", $bullets),
        );
    }

    /**
     * @param list<JiraTicket> $tickets
     * @return list<array{key: string, summary: string, priority: ?string, status: string, is_new: bool}>
     */
    private function snapshotTickets(array $tickets): array
    {
        $now = $this->clock->now();

        return array_map(
            static fn (JiraTicket $t) => [
                'key' => $t->getTicketKey(),
                'summary' => $t->getSummary(),
                'priority' => $t->getPriority(),
                'status' => $t->getStatus(),
                'is_new' => $t->isNewAsOf($now),
            ],
            $tickets,
        );
    }

    private function resolveDate(mixed $raw): \DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return new \DateTimeImmutable('today');
        }

        if (!\is_string($raw)) {
            throw new \InvalidArgumentException('--date must be a string in YYYY-MM-DD format.');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false) {
            throw new \InvalidArgumentException(sprintf('Invalid --date "%s". Expected YYYY-MM-DD.', $raw));
        }

        return $date;
    }
}
