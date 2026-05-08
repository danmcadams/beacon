<?php

declare(strict_types=1);

namespace App\Service;

use Anthropic\Client;
use App\Entity\JiraTicket;
use App\Entity\Project;

final class ApiBriefingGenerator implements BriefingGeneratorInterface
{
    public function __construct(
        private readonly Client $anthropic,
        private readonly BriefingPromptBuilder $prompts,
        private readonly string $model,
    ) {
    }

    /**
     * @param list<JiraTicket> $tickets
     */
    public function generate(Project $project, array $tickets): BriefingResult
    {
        $userPrompt = $this->prompts->buildUserPrompt($project, $tickets);

        $response = $this->anthropic->messages->create(
            model: $this->model,
            maxTokens: 4096,
            thinking: ['type' => 'adaptive'],
            system: [
                [
                    'type' => 'text',
                    'text' => BriefingPromptBuilder::SYSTEM_PROMPT,
                    'cacheControl' => ['type' => 'ephemeral'],
                ],
            ],
            messages: [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        );

        $text = '';
        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new BriefingResult(
            markdown: trim($text),
            model: $response->model,
            inputTokens: $response->usage->inputTokens ?? 0,
            outputTokens: $response->usage->outputTokens ?? 0,
            cacheReadInputTokens: $response->usage->cacheReadInputTokens ?? 0,
            cacheCreationInputTokens: $response->usage->cacheCreationInputTokens ?? 0,
        );
    }
}
