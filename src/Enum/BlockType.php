<?php

declare(strict_types=1);

namespace App\Enum;

enum BlockType: string
{
    case WorkSession = 'work_session';
    case Meeting = 'meeting';
    case Personal = 'personal';
    case Break = 'break';
    case Other = 'other';
}
