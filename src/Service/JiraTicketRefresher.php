<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JiraBoard;
use App\Entity\JiraTicket;
use App\Entity\Project;
use App\Repository\JiraTicketRepository;
use Doctrine\ORM\EntityManagerInterface;

final class JiraTicketRefresher
{
    public function __construct(
        private readonly JiraClient $jira,
        private readonly JiraTicketRepository $repo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{fetched: int, created: int, updated: int, deleted: int}
     */
    public function refresh(Project $project): array
    {
        $now = new \DateTimeImmutable();
        $seenKeys = [];
        $fetched = 0;
        $created = 0;
        $updated = 0;

        foreach ($project->getBoards() as $board) {
            $issues = $this->jira->searchIssues($project->getJiraBaseUrl(), $this->resolveJql($board));
            $fetched += \count($issues);

            foreach ($issues as $issue) {
                $key = (string) ($issue['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $seenKeys[] = $key;

                $existing = $this->repo->findOneByProjectAndKey($project, $key);
                $ticket = $existing ?? (new JiraTicket())->setProject($project)->setTicketKey($key);

                $fields = $issue['fields'] ?? [];
                $ticket
                    ->setBoard($board)
                    ->setSummary((string) ($fields['summary'] ?? ''))
                    ->setStatus((string) ($fields['status']['name'] ?? 'Unknown'))
                    ->setStatusCategory((string) ($fields['status']['statusCategory']['name'] ?? 'To Do'))
                    ->setPriority(isset($fields['priority']['name']) ? (string) $fields['priority']['name'] : null)
                    ->setAssignee(isset($fields['assignee']['displayName']) ? (string) $fields['assignee']['displayName'] : null)
                    ->setDueDate($this->parseDate($fields['duedate'] ?? null))
                    ->setJiraUpdatedAt($this->parseDateTime($fields['updated'] ?? null) ?? $now)
                    ->setJiraCreatedAt($this->parseDateTime($fields['created'] ?? null))
                    ->setRawJson($issue)
                    ->setFetchedAt($now);

                if ($existing === null) {
                    $ticket->setFirstSeenAt($now);
                    $this->em->persist($ticket);
                    ++$created;
                } else {
                    ++$updated;
                }
            }
        }

        // Prune only after every board has reported, or one board's results would delete another's tickets.
        $deleted = $this->deleteStale($project, $seenKeys);

        $this->em->flush();

        return [
            'fetched' => $fetched,
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
        ];
    }

    private function resolveJql(JiraBoard $board): string
    {
        $override = $board->getJqlFilter();
        if ($override !== null && trim($override) !== '') {
            return $override;
        }

        return sprintf(
            'project = "%s" AND assignee = currentUser() AND statusCategory != Done ORDER BY priority DESC, updated DESC',
            $board->getProjectKey(),
        );
    }

    /**
     * @param list<string> $seenKeys
     */
    private function deleteStale(Project $project, array $seenKeys): int
    {
        $existing = $this->repo->findForProject($project);
        $deleted = 0;
        foreach ($existing as $ticket) {
            if (!\in_array($ticket->getTicketKey(), $seenKeys, true)) {
                $this->em->remove($ticket);
                ++$deleted;
            }
        }

        return $deleted;
    }

    private function parseDate(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $d === false ? null : $d;
    }

    private function parseDateTime(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
