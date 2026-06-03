<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JiraTicket;
use App\Entity\Project;
use App\Service\ClockService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JiraTicket>
 */
final class JiraTicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly ClockService $clock)
    {
        parent::__construct($registry, JiraTicket::class);
    }

    /** @return list<JiraTicket> */
    public function findForProject(Project $project): array
    {
        $tickets = $this->createQueryBuilder('t')
            ->where('t.project = :project')
            ->setParameter('project', $project)
            ->orderBy('t.priority', 'ASC')
            ->addOrderBy('t.ticketKey', 'ASC')
            ->getQuery()
            ->getResult();

        $now = $this->clock->now();

        // Stable sort: ties keep the query order (priority, then key).
        // Ranks: 0 new+in-progress, 1 new+backlog, 2 in-progress, 3 backlog.
        usort($tickets, static function (JiraTicket $a, JiraTicket $b) use ($now): int {
            $score = static function (JiraTicket $t) use ($now): int {
                $inProgress = $t->getStatus() === 'In Progress' || $t->getStatusCategory() === 'In Progress';
                return ($t->isNewAsOf($now) ? 0 : 2) + ($inProgress ? 0 : 1);
            };
            return $score($a) <=> $score($b);
        });

        return $tickets;
    }

    public function findOneByProjectAndKey(Project $project, string $ticketKey): ?JiraTicket
    {
        return $this->findOneBy(['project' => $project, 'ticketKey' => $ticketKey]);
    }

    public function findMostRecentForProject(Project $project): ?JiraTicket
    {
        return $this->createQueryBuilder('t')
            ->where('t.project = :project')
            ->setParameter('project', $project)
            ->orderBy('t.fetchedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
