<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JiraTicket;
use App\Entity\Project;

interface BriefingGeneratorInterface
{
    /**
     * @param list<JiraTicket> $tickets
     */
    public function generate(Project $project, array $tickets): BriefingResult;
}
