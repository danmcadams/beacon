<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class JiraClient
{
    private const FIELDS = ['summary', 'status', 'priority', 'assignee', 'duedate', 'updated', 'created'];

    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $email,
        private readonly string $apiToken,
    ) {
    }

    /**
     * Run a JQL search against a Jira Cloud instance and return the issues.
     *
     * @return list<array<string, mixed>>
     */
    public function searchIssues(string $baseUrl, string $jql): array
    {
        $issues = [];
        $nextPageToken = null;

        do {
            $body = [
                'jql' => $jql,
                'fields' => self::FIELDS,
                'maxResults' => self::PAGE_SIZE,
            ];
            if ($nextPageToken !== null) {
                $body['nextPageToken'] = $nextPageToken;
            }

            $response = $this->http->request('POST', rtrim($baseUrl, '/') . '/rest/api/3/search/jql', [
                'auth_basic' => [$this->email, $this->apiToken],
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
            ]);

            $data = $response->toArray();

            foreach ($data['issues'] ?? [] as $issue) {
                $issues[] = $issue;
            }

            $nextPageToken = $data['nextPageToken'] ?? null;
        } while ($nextPageToken !== null && \count($issues) < 1000); // hard cap to prevent runaway loops

        return $issues;
    }
}
