<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Enum\ProjectStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
final class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /** @return list<Project> */
    public function findActiveOrdered(): array
    {
        return $this->findOrdered(ProjectStatus::Active);
    }

    /** @return list<Project> */
    public function findOrdered(?ProjectStatus $status = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.id', 'ASC');

        if ($status !== null) {
            $qb->where('p.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }
}
