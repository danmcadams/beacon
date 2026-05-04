<?php

declare(strict_types=1);

namespace App\Wizard;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Session-backed state for the multi-step project wizard.
 *
 * The wizard collects per-step input into a single session bag, then the
 * review step persists everything to the DB and clears the bag.
 */
final class ProjectWizardState
{
    private const SESSION_KEY = 'beacon.project_wizard';

    public function __construct(private readonly RequestStack $requests)
    {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->requests->getSession()->get(self::SESSION_KEY, []);
    }

    /** @return array<string, mixed> */
    public function step(string $name): array
    {
        return $this->all()[$name] ?? [];
    }

    /** @param array<string, mixed> $data */
    public function setStep(string $name, array $data): void
    {
        $state = $this->all();
        $state[$name] = $data;
        $this->requests->getSession()->set(self::SESSION_KEY, $state);
    }

    public function hasStep(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function clear(): void
    {
        $this->requests->getSession()->remove(self::SESSION_KEY);
    }
}
