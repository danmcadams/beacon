<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Briefing;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Briefing>
 */
final class BriefingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Briefing::class);
    }

    public function findOneForDateAndProject(\DateTimeImmutable $date, Project $project): ?Briefing
    {
        return $this->findOneBy([
            'briefingDate' => $date,
            'project' => $project,
        ]);
    }

    /** @return list<Briefing> */
    public function findForDate(\DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.briefingDate = :date')
            ->setParameter('date', $date->format('Y-m-d'))
            ->getQuery()
            ->getResult();
    }
}
