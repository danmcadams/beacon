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

    public function findMostRecent(): ?Briefing
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.generatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findMostRecentFailed(): ?Briefing
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', \App\Enum\BriefingStatus::Failed->value)
            ->orderBy('b.generatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array{input: int, output: int, per_day: list<int>, window_days: int}
     */
    public function getTokenAggregates(int $windowDays = 30): array
    {
        $since = (new \DateTimeImmutable('now'))->modify("-{$windowDays} days")->setTime(0, 0);

        /** @var list<array{briefingDate: \DateTimeImmutable, input_sum: string|null, output_sum: string|null}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('b.briefingDate, SUM(b.inputTokens) as input_sum, SUM(b.outputTokens) as output_sum')
            ->where('b.briefingDate >= :since')
            ->setParameter('since', $since->format('Y-m-d'))
            ->groupBy('b.briefingDate')
            ->orderBy('b.briefingDate', 'ASC')
            ->getQuery()
            ->getResult();

        $totalInput = 0;
        $totalOutput = 0;
        $byDate = [];
        foreach ($rows as $row) {
            $input = (int) ($row['input_sum'] ?? 0);
            $output = (int) ($row['output_sum'] ?? 0);
            $totalInput += $input;
            $totalOutput += $output;
            $byDate[$row['briefingDate']->format('Y-m-d')] = $input + $output;
        }

        $perDay = [];
        for ($i = $windowDays - 1; $i >= 0; $i--) {
            $date = (new \DateTimeImmutable("now -{$i} days"))->format('Y-m-d');
            $perDay[] = $byDate[$date] ?? 0;
        }

        return [
            'input' => $totalInput,
            'output' => $totalOutput,
            'per_day' => $perDay,
            'window_days' => $windowDays,
        ];
    }
}
