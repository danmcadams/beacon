<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JiraTicket;
use App\Entity\Project;
use Symfony\Component\Process\Process;

final class CliBriefingGenerator implements BriefingGeneratorInterface
{
    public function __construct(
        private readonly BriefingPromptBuilder $prompts,
        private readonly string $binary,
        private readonly string $model,
        private readonly int $timeoutSeconds,
    ) {
    }

    /**
     * @param list<JiraTicket> $tickets
     */
    public function generate(Project $project, array $tickets): BriefingResult
    {
        $userPrompt = $this->prompts->buildUserPrompt($project, $tickets);

        $process = new Process(
            command: [
                $this->binary,
                '--print',
                '--output-format', 'json',
                '--model', $this->model,
                '--system-prompt', BriefingPromptBuilder::SYSTEM_PROMPT,
            ],
            cwd: sys_get_temp_dir(),
            timeout: $this->timeoutSeconds,
        );
        $process->setInput($userPrompt);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new BriefingGenerationFailedException(sprintf(
                'claude CLI exited with code %d: %s',
                $process->getExitCode() ?? -1,
                trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: '(no output)',
            ));
        }

        return $this->parseResponse($process->getOutput());
    }

    private function parseResponse(string $stdout): BriefingResult
    {
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($stdout, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BriefingGenerationFailedException(
                'claude CLI returned non-JSON output: ' . substr($stdout, 0, 500),
                previous: $e,
            );
        }

        if (($payload['is_error'] ?? false) === true) {
            $msg = \is_string($payload['result'] ?? null) ? $payload['result'] : '(no message)';
            throw new BriefingGenerationFailedException('claude CLI reported error: ' . $msg);
        }

        if (!\is_string($payload['result'] ?? null)) {
            throw new BriefingGenerationFailedException(
                'claude CLI response missing "result" field: ' . substr($stdout, 0, 500),
            );
        }

        $usage = \is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $modelReported = \is_string($payload['model'] ?? null) ? $payload['model'] : $this->model;

        return new BriefingResult(
            markdown: trim($payload['result']),
            model: $modelReported,
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cacheReadInputTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
            cacheCreationInputTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
        );
    }
}
