<?php

declare(strict_types=1);

namespace App\Enum;

enum BriefingStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
