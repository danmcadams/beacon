<?php

declare(strict_types=1);

namespace App\Service;

final class JiraUrlParser
{
    /**
     * Pulls the Atlassian host and project key out of any Jira URL.
     *
     * Accepts URLs like:
     *   https://acme.atlassian.net/jira/software/c/projects/HCEU/boards/1872
     *   https://acme.atlassian.net/browse/HCEU-412
     *   https://acme.atlassian.net/projects/HCEU
     *
     * @return array{base_url: string, project_key: ?string}
     */
    public function parse(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['base_url' => '', 'project_key' => null];
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return ['base_url' => '', 'project_key' => null];
        }

        $scheme = $parts['scheme'] ?? 'https';
        $base = sprintf('%s://%s', $scheme, $parts['host']);

        $key = null;
        $path = $parts['path'] ?? '';

        if (preg_match('#/projects/([A-Za-z][A-Za-z0-9_]*)#', $path, $m)) {
            $key = strtoupper($m[1]);
        } elseif (preg_match('#/browse/([A-Za-z][A-Za-z0-9_]*)-\d+#', $path, $m)) {
            $key = strtoupper($m[1]);
        }

        return ['base_url' => $base, 'project_key' => $key];
    }
}
