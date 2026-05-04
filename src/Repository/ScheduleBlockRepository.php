<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScheduleBlock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduleBlock>
 */
final class ScheduleBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduleBlock::class);
    }

    /** @return list<ScheduleBlock> */
    public function findForDay(int $dayOfWeek): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.dayOfWeek = :day')
            ->setParameter('day', $dayOfWeek)
            ->orderBy('s.startTime', 'ASC')
            ->addOrderBy('s.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, list<ScheduleBlock>>  Keys 1–5 (Mon–Fri), always present.
     */
    public function findAllGroupedByDay(): array
    {
        $blocks = $this->createQueryBuilder('s')
            ->orderBy('s.dayOfWeek', 'ASC')
            ->addOrderBy('s.startTime', 'ASC')
            ->addOrderBy('s.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [1 => [], 2 => [], 3 => [], 4 => [], 5 => []];
        foreach ($blocks as $block) {
            if (isset($grouped[$block->getDayOfWeek()])) {
                $grouped[$block->getDayOfWeek()][] = $block;
            }
        }
        return $grouped;
    }

    public function deleteAll(): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->getQuery()
            ->execute();
    }
}
