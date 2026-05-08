<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JiraTicket;
use App\Entity\Project;

final class BriefingPromptBuilder
{
    public const SYSTEM_PROMPT = <<<'TXT'
You are Beacon, a daily briefing assistant for a solo developer juggling multiple projects.

Your job: write a short markdown briefing telling the user what to focus on today for the given project, given its open Jira tickets.

Style:
- Lead with the action ("Start with `LR-412`", "One ticket today", "Quiet day")
- Wrap ticket keys in backticks: `LR-412`, not LR-412 or **LR-412**
- 2-3 short paragraphs. No bullet lists unless the day truly has discrete unrelated items.
- Bold a key action sparingly with **double asterisks**.
- Don't preamble ("Today's briefing", "Here is", "Based on the tickets") — get to the point.
- Don't list every ticket — that's already on the page. Pick what matters and say why.
- If a ticket is blocked, say so and tell the user to skip it.
- If the day looks light, say so. Don't manufacture urgency.

Tone: direct, calm, like a sharp peer giving a stand-up update. No corporate-speak.
TXT;

    /**
     * @param list<JiraTicket> $tickets
     */
    public function buildUserPrompt(Project $project, array $tickets): string
    {
        $header = sprintf(
            "Project: %s\nDaily hour target: %.1fh",
            $project->getName(),
            $project->getDailyHoursTarget(),
        );

        if ($project->getDescription() !== null && $project->getDescription() !== '') {
            $header .= "\nDescription: " . $project->getDescription();
        }

        if ($tickets === []) {
            return $header . "\n\nNo tickets in queue. Write a short briefing acknowledging there's nothing on fire and suggesting any kind of slack-time work that fits a "
                . number_format($project->getDailyHoursTarget(), 1) . "h slot.";
        }

        $ticketLines = array_map(
            static fn (JiraTicket $t) => sprintf(
                '- %s [%s, priority=%s]: %s',
                $t->getTicketKey(),
                $t->getStatus(),
                $t->getPriority() ?? '—',
                $t->getSummary(),
            ),
            $tickets,
        );

        return $header
            . "\n\nTickets currently in this project's queue:\n"
            . implode("\n", $ticketLines)
            . "\n\nWrite the briefing.";
    }
}
