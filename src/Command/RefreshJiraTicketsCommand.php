<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\JiraTicketRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:jira:refresh',
    description: 'Refresh the Jira ticket cache for active projects.',
)]
final class RefreshJiraTicketsCommand extends Command
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly JiraTicketRefresher $refresher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Limit to a single project (match by lowercased name).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projects = $this->projects->findActiveOrdered();
        $filter = $input->getOption('project');
        if (\is_string($filter) && $filter !== '') {
            $needle = strtolower($filter);
            $projects = array_values(array_filter(
                $projects,
                static fn (Project $p) => strtolower($p->getName()) === $needle,
            ));
            if ($projects === []) {
                $io->error(sprintf('No active project matches "%s".', $filter));

                return Command::FAILURE;
            }
        }

        $rows = [];
        foreach ($projects as $project) {
            try {
                $stats = $this->refresher->refresh($project);
                $rows[] = [
                    $project->getName(),
                    'ok',
                    $stats['fetched'],
                    $stats['created'],
                    $stats['updated'],
                    $stats['deleted'],
                ];
            } catch (\Throwable $e) {
                $rows[] = [
                    $project->getName(),
                    'failed: ' . $this->shortError($e),
                    0, 0, 0, 0,
                ];
            }
        }

        $io->table(
            ['Project', 'Result', 'Fetched', 'Created', 'Updated', 'Deleted'],
            $rows,
        );

        return Command::SUCCESS;
    }

    private function shortError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        $line = strtok($msg, "\n");
        if ($line === false) {
            $line = 'unknown error';
        }

        return mb_substr($line, 0, 80);
    }
}
