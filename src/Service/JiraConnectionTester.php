<?php

declare(strict_types=1);

namespace App\Service;

final class JiraConnectionTester
{
    public function __construct(
        private readonly JiraClient $client,
        private readonly string $email,
        private readonly string $apiToken,
    ) {
    }

    public function test(string $baseUrl, string $boardId, ?string $jqlOverride): JiraConnectionResult
    {
        if ($this->email === '' || $this->apiToken === '') {
            return JiraConnectionResult::missingCredentials();
        }
        if ($baseUrl === '' || $boardId === '') {
            return JiraConnectionResult::error('Base URL and board ID are required.');
        }

        $jql = $jqlOverride !== null && trim($jqlOverride) !== ''
            ? $jqlOverride
            : sprintf('project = "%s" AND assignee = currentUser() AND statusCategory != Done', $boardId);

        try {
            $issues = $this->client->searchIssues($baseUrl, $jql);

            return JiraConnectionResult::ok(\count($issues));
        } catch (\Throwable $e) {
            return JiraConnectionResult::error($e->getMessage());
        }
    }
}
