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
            ->where('t.project = :project')
            ->setParameter('project', $project)
            ->orderBy('t.priority', 'ASC')
            ->addOrderBy('t.ticketKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByProjectAndKey(Project $project, string $ticketKey): ?JiraTicket
    {
        return $this->findOneBy(['project' => $project, 'ticketKey' => $ticketKey]);
    }
}
