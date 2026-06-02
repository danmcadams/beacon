<?php

declare(strict_types=1);

namespace App\Service;

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
        $jql = $this->resolveJql($project);
        $issues = $this->jira->searchIssues($project->getJiraBaseUrl(), $jql);

        $now = new \DateTimeImmutable();
        $seenKeys = [];
        $created = 0;
        $updated = 0;

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
                ->setSummary((string) ($fields['summary'] ?? ''))
                ->setStatus((string) ($fields['status']['name'] ?? 'Unknown'))
                ->setStatusCategory((string) ($fields['status']['statusCategory']['name'] ?? 'To Do'))
                ->setPriority(isset($fields['priority']['name']) ? (string) $fields['priority']['name'] : null)
                ->setAssignee(isset($fields['assignee']['displayName']) ? (string) $fields['assignee']['displayName'] : null)
                ->setDueDate($this->parseDate($fields['duedate'] ?? null))
                ->setJiraUpdatedAt($this->parseDateTime($fields['updated'] ?? null) ?? $now)
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

        $deleted = $this->deleteStale($project, $seenKeys);

        $this->em->flush();

        return [
            'fetched' => \count($issues),
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
        ];
    }

    private function resolveJql(Project $project): string
    {
        $override = $project->getJiraJqlFilter();
        if ($override !== null && trim($override) !== '') {
            return $override;
        }

        return sprintf(
            'project = "%s" AND assignee = currentUser() AND statusCategory != Done ORDER BY priority DESC, updated DESC',
            $project->getJiraBoardId(),
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
