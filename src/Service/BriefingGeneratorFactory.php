<?php

declare(strict_types=1);

namespace App\Service;

final readonly class BriefingGeneratorFactory
{
    public function __construct(
        private CliBriefingGenerator $cli,
        private ApiBriefingGenerator $api,
        private string $backend,
    ) {
    }

    public function create(): BriefingGeneratorInterface
    {
        return match (strtolower($this->backend)) {
            'api' => $this->api,
            'cli', '' => $this->cli,
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown BRIEFING_BACKEND "%s" — expected "cli" or "api".',
                $this->backend,
            )),
        };
    }
}
