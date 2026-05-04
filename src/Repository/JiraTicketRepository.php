<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JiraTicket;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JiraTicket>
 */
final class JiraTicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JiraTicket::class);
    }

    /** @return list<JiraTicket> */
    public function findForProject(Project $project): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect("CASE WHEN t.statusCategory = 'In Progress' THEN 0 ELSE 1 END AS HIDDEN status_sort")
            ->where('t.project = :project')
            ->setParameter('project', $project)
            ->orderBy('status_sort', 'ASC')
            ->addOrderBy('t.priority', 'ASC')
            ->addOrderBy('t.ticketKey', 'ASC')
            ->getQuery()
            ->getResult();
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
