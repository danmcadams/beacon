<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppSettings>
 */
final class AppSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSettings::class);
    }

    public function getSettings(): AppSettings
    {
        $settings = $this->find(1);

        if ($settings === null) {
            $settings = new AppSettings();
            $this->getEntityManager()->persist($settings);
            $this->getEntityManager()->flush();
        }

        return $settings;
    }
}
