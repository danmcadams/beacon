<?php

declare(strict_types=1);

namespace App\Service;

final readonly class JiraConnectionResult
{
    private function __construct(
        public string $state,
        public ?int $ticketCount,
        public ?string $message,
    ) {
    }

    public static function ok(int $ticketCount): self
    {
        return new self('ok', $ticketCount, null);
    }

    public static function missingCredentials(): self
    {
        return new self(
            'missing_credentials',
            null,
            'JIRA_EMAIL and JIRA_API_TOKEN are not set in .env.local — skip this step for now and the connection can be tested later.',
        );
    }

    public static function error(string $message): self
    {
        return new self('error', null, $message);
    }

    public function isOk(): bool
    {
        return $this->state === 'ok';
    }
}
