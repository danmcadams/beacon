<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Briefing;
use App\Entity\JiraBoard;
use App\Entity\JiraTicket;
use App\Entity\Project;
use App\Entity\QuickLink;
use App\Entity\ScheduleBlock;
use App\Enum\BlockType;
use App\Enum\BriefingStatus;
use App\Enum\ProjectStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $today = new \DateTimeImmutable('today');

        $homeceu = $this->makeProject('HomeCEU', '#cba6f7', 1.0, 0, 'https://homeceu.atlassian.net', 'HC');
        $licenseregistry = $this->makeProject('LicenseRegistry', '#a6e3a1', 3.0, 1, 'https://homeceu.atlassian.net', 'LR');
        $legacy = $this->makeProject('Legacy', '#f9e2af', 0.5, 2, 'https://homeceu.atlassian.net', 'LEG');

        foreach ([$homeceu, $licenseregistry, $legacy] as $p) {
            $manager->persist($p);
        }

        $this->seedQuickLinks($manager, $homeceu, [
            ['Jira board', 'https://homeceu.atlassian.net/jira/software/projects/HC/boards'],
            ['Repo', 'https://github.com/scorebuilders/homeceu'],
        ]);
        $this->seedQuickLinks($manager, $licenseregistry, [
            ['Jira board', 'https://homeceu.atlassian.net/jira/software/projects/LR/boards'],
            ['Repo', 'https://github.com/scorebuilders/license-registry'],
            ['Architecture doc', 'http://localhost:8181/?file=cems-rewrite/overview.md'],
        ]);

        $this->seedSchedule($manager);

        $this->seedTickets($manager, $homeceu, [
            ['HC-88', 'Stripe webhook validation failing on staging', 'High', 'In Progress'],
            ['HC-91', 'Certificate PDF generation > 8s p95', 'Medium', 'To Do'],
            ['HC-92', 'Email template typo on enrollment confirmation', 'Low', 'To Do'],
            ['HC-94', 'Audit log retention exceeds 12 months', 'Low', 'To Do'],
        ]);
        $this->seedTickets($manager, $licenseregistry, [
            ['LR-412', 'Provider sync producing duplicate certification rows', 'High', 'In Progress'],
            ['LR-409', 'CSV export returns 502 on result sets > 5k', 'High', 'To Do'],
            ['LR-401', 'Vendor SSO callback intermittently 401s', 'Medium', 'Blocked'],
        ]);
        $this->seedTickets($manager, $legacy, [
            ['LEG-22', 'Move nightly cron to 03:00', 'Low', 'To Do'],
            ['LEG-19', 'Resolve PHP 7 deprecation warnings in audit log', 'Low', 'To Do'],
        ]);

        $this->seedBriefing($manager, $homeceu, $today, BriefingStatus::Success, <<<'MD'
**One ticket today:** `HC-88`. Stripe webhook signature validation flipped to failing on staging Wednesday night — billing emails are queued but not sending. Rotate the webhook secret in `.env.production`, redeploy, and replay the 14 events sitting in the dead-letter queue.

If time permits, glance at `HC-91` (cert PDF generation slow) — but it's not on fire and a 1-hour slot won't cover it.
MD);

        $this->seedBriefing($manager, $licenseregistry, $today, BriefingStatus::Success, <<<'MD'
**Start with `LR-412` — provider sync regression.** Andrei flagged duplicate rows yesterday at 4:51pm; the snapshot you ran has 38 dupes in `provider_certifications`. Reproduce locally first, then patch the upsert in `ProviderSyncService::reconcile`.

Once that's green, move to `LR-409` (CSV export 502 on >5k rows). Likely a memory ceiling in the streaming path — bump the chunk size and add a guard.

Skip `LR-401` for now — it's blocked on a vendor response and won't move today.
MD);

        $this->seedBriefing($manager, $legacy, $today, BriefingStatus::Skipped, <<<'MD'
Quiet day. `LEG-22` is the only thing with movement — Ben wants the nightly cron pushed to 03:00 to clear the long-running report job. Five-minute config change.

If you finish early, `LEG-19` (PHP 7 deprecation warnings in the audit log) is a nice low-stakes cleanup.
MD);

        $manager->flush();
    }

    private function makeProject(string $name, string $color, float $hours, int $sortOrder, string $jiraUrl, string $boardId): Project
    {
        $p = new Project();
        $p->setName($name)
            ->setStatus(ProjectStatus::Active)
            ->setColor($color)
            ->setDailyHoursTarget($hours)
            ->setSortOrder($sortOrder)
            ->setJiraBaseUrl($jiraUrl)
            ->setJiraBoardId($boardId);

        $p->addBoard(
            (new JiraBoard())
                ->setProjectKey($boardId)
                ->setSortOrder(0),
        );

        return $p;
    }

    /** @param list<array{0: string, 1: string}> $links */
    private function seedQuickLinks(ObjectManager $m, Project $p, array $links): void
    {
        foreach ($links as $i => [$label, $url]) {
            $ql = new QuickLink();
            $ql->setProject($p)->setLabel($label)->setUrl($url)->setSortOrder($i);
            $m->persist($ql);
        }
    }

    private function seedSchedule(ObjectManager $m): void
    {
        $blocks = [
            ['07:30', '08:45', 'School run',           BlockType::Personal],
            ['09:00', '11:00', 'Focused work #1',      BlockType::WorkSession],
            ['11:00', '11:15', 'Break',                BlockType::Break],
            ['11:15', '12:30', 'Focused work #2',      BlockType::WorkSession],
            ['12:30', '13:30', 'Lunch',                BlockType::Break],
            ['13:30', '14:00', 'Email & Slack triage', BlockType::Personal],
            ['14:00', '14:30', 'Standup',              BlockType::Meeting],
            ['14:30', '17:00', 'Focused work #3',      BlockType::WorkSession],
        ];

        for ($day = 1; $day <= 5; $day++) {
            foreach ($blocks as $i => [$start, $end, $label, $type]) {
                $sb = new ScheduleBlock();
                $sb->setDayOfWeek($day)
                    ->setStartTime(new \DateTimeImmutable($start))
                    ->setEndTime(new \DateTimeImmutable($end))
                    ->setLabel($label)
                    ->setBlockType($type)
                    ->setSortOrder($i);
                $m->persist($sb);
            }
        }
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string}> $tickets */
    private function seedTickets(ObjectManager $m, Project $p, array $tickets): void
    {
        $now = new \DateTimeImmutable();
        foreach ($tickets as [$key, $summary, $priority, $status]) {
            $category = match ($status) {
                'Done' => 'Done',
                'In Progress' => 'In Progress',
                default => 'To Do',
            };

            $t = new JiraTicket();
            $t->setProject($p)
                ->setTicketKey($key)
                ->setSummary($summary)
                ->setStatus($status)
                ->setStatusCategory($category)
                ->setPriority($priority)
                ->setAssignee('Dan McAdams')
                ->setJiraUpdatedAt($now->modify('-1 day'))
                ->setRawJson([])
                ->setFetchedAt($now);
            $m->persist($t);
        }
    }

    private function seedBriefing(ObjectManager $m, Project $p, \DateTimeImmutable $date, BriefingStatus $status, string $markdown): void
    {
        $b = new Briefing();
        $b->setProject($p)
            ->setBriefingDate($date)
            ->setStatus($status)
            ->setContentMarkdown($markdown)
            ->setModel('claude-sonnet-4-6')
            ->setInputTokens(14210)
            ->setOutputTokens(1847)
            ->setTicketsSnapshot([]);
        $m->persist($b);
    }
}
