<?php

declare(strict_types=1);

namespace App\Service;

final readonly class BriefingResult
{
    public function __construct(
        public string $markdown,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadInputTokens,
        public int $cacheCreationInputTokens,
    ) {
    }
}
