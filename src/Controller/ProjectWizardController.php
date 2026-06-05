<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\JiraBoard;
use App\Entity\Project;
use App\Entity\QuickLink;
use App\Service\JiraConnectionTester;
use App\Service\JiraTicketRefresher;
use App\Service\JiraUrlParser;
use App\Wizard\ProjectWizardState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/projects/new', name: 'project_wizard_')]
final class ProjectWizardController extends AbstractController
{
    private const STEPS = ['basics', 'jira', 'hours', 'links', 'review'];

    public function __construct(
        private readonly ProjectWizardState $state,
        private readonly JiraConnectionTester $jiraTester,
        private readonly JiraTicketRefresher $jiraRefresher,
        private readonly JiraUrlParser $jiraUrls,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'start', methods: ['GET'])]
    public function start(): Response
    {
        return $this->redirectToRoute('project_wizard_basics');
    }

    #[Route('/basics', name: 'basics', methods: ['GET', 'POST'])]
    public function basics(Request $request): Response
    {
        $data = $this->state->step('basics') ?: [
            'name' => '',
            'description' => '',
            'color' => '#cba6f7',
            'sort_order' => 0,
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            $data = [
                'name' => trim((string) $request->request->get('name', '')),
                'description' => trim((string) $request->request->get('description', '')),
                'color' => trim((string) $request->request->get('color', '#cba6f7')),
                'sort_order' => (int) $request->request->get('sort_order', 0),
            ];

            if ($data['name'] === '') {
                $errors['name'] = 'Name is required.';
            } elseif (\strlen($data['name']) > 100) {
                $errors['name'] = 'Name must be 100 characters or fewer.';
            }
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $data['color'])) {
                $errors['color'] = 'Color must be a hex value like #cba6f7.';
            }
            if ($data['sort_order'] < 0) {
                $errors['sort_order'] = 'Sort order must be zero or positive.';
            }

            if ($errors === []) {
                $this->state->setStep('basics', $data);

                return $this->redirectToRoute('project_wizard_jira');
            }
        }

        return $this->render('projects/wizard/basics.html.twig', [
            'step' => $this->stepView('basics'),
            'data' => $data,
            'errors' => $errors,
        ]);
    }

    #[Route('/jira', name: 'jira', methods: ['GET', 'POST'])]
    public function jira(Request $request): Response
    {
        if (!$this->state->hasStep('basics')) {
            return $this->redirectToRoute('project_wizard_basics');
        }

        $data = $this->state->step('jira') ?: ['base_url' => '', 'boards' => []];
        $errors = [];

        if ($request->isMethod('POST')) {
            $rawBase = trim((string) $request->request->get('jira_base_url', ''));
            $normBase = $this->jiraUrls->parse($rawBase)['base_url'];
            $boards = $this->readBoardRows($request);

            if ($rawBase === '') {
                $errors['jira_base_url'] = 'Jira base URL is required.';
            } elseif ($normBase === '') {
                $errors['jira_base_url'] = 'That doesn\'t look like a valid URL (e.g. https://yourorg.atlassian.net).';
            }
            $boardErrors = [];
            foreach ($boards as $i => $b) {
                if ($b['key'] !== '' && !preg_match('/^[A-Z][A-Z0-9_]*$/', $b['key'])) {
                    $boardErrors[$i] = 'Invalid project key — letters, digits, and underscores only.';
                }
            }
            if ($boardErrors !== []) {
                $errors['board_rows'] = $boardErrors;
            }
            if (array_filter($boards, static fn (array $b) => $b['key'] !== '') === []) {
                $errors['boards'] = 'Add at least one board (a Jira project key).';
            }

            $data = ['base_url' => $rawBase, 'boards' => $boards];

            if ($errors === []) {
                $this->state->setStep('jira', [
                    'base_url' => $normBase,
                    'boards' => array_values(array_filter($boards, static fn (array $b) => $b['key'] !== '')),
                ]);

                return $this->redirectToRoute('project_wizard_hours');
            }
        }

        return $this->render('projects/wizard/jira.html.twig', [
            'step' => $this->stepView('jira'),
            'data' => $data,
            'errors' => $errors,
            'test_result' => null,
        ]);
    }

    #[Route('/jira/test', name: 'jira_test', methods: ['POST'])]
    public function jiraTest(Request $request): Response
    {
        $baseUrl = $this->jiraUrls->parse(trim((string) $request->request->get('jira_base_url', '')))['base_url'];

        $rawKey = trim((string) $request->request->get('test_key', ''));
        if (str_starts_with($rawKey, 'http')) {
            $rawKey = $this->jiraUrls->parse($rawKey)['project_key'] ?? '';
        }
        $jql = trim((string) $request->request->get('test_jql', ''));

        $result = $this->jiraTester->test(
            $baseUrl,
            strtoupper($rawKey),
            $jql !== '' ? $jql : null,
        );

        return $this->render('projects/wizard/_jira-test-result.html.twig', [
            'result' => $result,
        ]);
    }

    #[Route('/hours', name: 'hours', methods: ['GET', 'POST'])]
    public function hours(Request $request): Response
    {
        if (!$this->state->hasStep('jira')) {
            return $this->redirectToRoute('project_wizard_jira');
        }

        $data = $this->state->step('hours') ?: ['daily_hours_target' => 1.0];
        $errors = [];

        if ($request->isMethod('POST')) {
            $raw = (string) $request->request->get('daily_hours_target', '');
            $hours = is_numeric($raw) ? (float) $raw : -1.0;

            if ($hours < 0) {
                $errors['daily_hours_target'] = 'Daily target must be zero or more.';
            } elseif ($hours > 24) {
                $errors['daily_hours_target'] = 'Daily target must be 24 or fewer.';
            } else {
                $this->state->setStep('hours', ['daily_hours_target' => $hours]);

                return $this->redirectToRoute('project_wizard_links');
            }

            $data = ['daily_hours_target' => $hours];
        }

        return $this->render('projects/wizard/hours.html.twig', [
            'step' => $this->stepView('hours'),
            'data' => $data,
            'errors' => $errors,
        ]);
    }

    #[Route('/links', name: 'links', methods: ['GET', 'POST'])]
    public function links(Request $request): Response
    {
        if (!$this->state->hasStep('hours')) {
            return $this->redirectToRoute('project_wizard_hours');
        }

        $links = $this->state->step('links')['links'] ?? [];
        $errors = [];

        if ($request->isMethod('POST')) {
            $labels = (array) $request->request->all('label');
            $urls = (array) $request->request->all('url');
            $links = [];

            foreach ($labels as $i => $label) {
                $label = trim((string) $label);
                $url = trim((string) ($urls[$i] ?? ''));

                if ($label === '' && $url === '') {
                    continue;
                }
                if ($label === '') {
                    $errors[$i] = 'Label is required.';
                } elseif ($url === '') {
                    $errors[$i] = 'URL is required.';
                } elseif (!filter_var($url, \FILTER_VALIDATE_URL)) {
                    $errors[$i] = 'URL must be valid.';
                }

                $links[] = ['label' => $label, 'url' => $url];
            }

            if ($errors === []) {
                $this->state->setStep('links', ['links' => $links]);

                return $this->redirectToRoute('project_wizard_review');
            }
        }

        return $this->render('projects/wizard/links.html.twig', [
            'step' => $this->stepView('links'),
            'links' => $links,
            'errors' => $errors,
        ]);
    }

    #[Route('/review', name: 'review', methods: ['GET', 'POST'])]
    public function review(Request $request): Response
    {
        if (!$this->state->hasStep('links')) {
            return $this->redirectToRoute('project_wizard_links');
        }

        if ($request->isMethod('POST')) {
            $project = $this->createProjectFromState();
            $this->em->persist($project);
            $this->em->flush();
            $this->state->clear();

            try {
                $this->jiraRefresher->refresh($project);
            } catch (\Throwable) {
                // non-fatal — tickets can be refreshed manually
            }

            $this->addFlash('success', sprintf('Project "%s" created.', $project->getName()));

            return $this->redirectToRoute('home');
        }

        return $this->render('projects/wizard/review.html.twig', [
            'step' => $this->stepView('review'),
            'state' => $this->state->all(),
        ]);
    }

    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(): Response
    {
        $this->state->clear();

        return $this->redirectToRoute('home');
    }

    /**
     * @return list<array{key: string, label: string, jql: string}>
     */
    private function readBoardRows(Request $request): array
    {
        $keys   = $request->request->all('board_key');
        $labels = $request->request->all('board_label');
        $jqls   = $request->request->all('board_jql');

        $count = max(\count($keys), \count($labels), \count($jqls));
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rawKey = trim((string) ($keys[$i] ?? ''));
            if (str_starts_with($rawKey, 'http')) {
                $rawKey = $this->jiraUrls->parse($rawKey)['project_key'] ?? '';
            }
            $key   = strtoupper($rawKey);
            $label = trim((string) ($labels[$i] ?? ''));
            $jql   = trim((string) ($jqls[$i] ?? ''));

            if ($key === '' && $label === '' && $jql === '') {
                continue;
            }
            $rows[] = ['key' => $key, 'label' => $label, 'jql' => $jql];
        }

        return $rows;
    }

    private function createProjectFromState(): Project
    {
        $s = $this->state->all();
        $boards = $s['jira']['boards'] ?? [];
        $primary = $boards[0] ?? ['key' => '', 'jql' => ''];

        $project = new Project();
        $project
            ->setName($s['basics']['name'])
            ->setDescription($s['basics']['description'] !== '' ? $s['basics']['description'] : null)
            ->setColor($s['basics']['color'])
            ->setSortOrder((int) $s['basics']['sort_order'])
            ->setJiraBaseUrl($s['jira']['base_url'])
            // Legacy single-board columns mirror the primary board until they're dropped.
            ->setJiraBoardId($primary['key'])
            ->setJiraJqlFilter(($primary['jql'] ?? '') !== '' ? $primary['jql'] : null)
            ->setDailyHoursTarget((float) $s['hours']['daily_hours_target']);

        foreach ($boards as $i => $b) {
            $project->addBoard(
                (new JiraBoard())
                    ->setProjectKey($b['key'])
                    ->setLabel(($b['label'] ?? '') !== '' ? $b['label'] : null)
                    ->setJqlFilter(($b['jql'] ?? '') !== '' ? $b['jql'] : null)
                    ->setSortOrder($i),
            );
        }

        foreach ($s['links']['links'] ?? [] as $i => $link) {
            $ql = new QuickLink();
            $ql->setProject($project)
                ->setLabel($link['label'])
                ->setUrl($link['url'])
                ->setSortOrder($i);
            $project->getQuickLinks()->add($ql);
        }

        return $project;
    }

    /**
     * @return array{name: string, index: int, total: int, all: list<array{name: string, index: int, active: bool, complete: bool}>}
     */
    private function stepView(string $current): array
    {
        $index = array_search($current, self::STEPS, true);
        $all = [];
        foreach (self::STEPS as $i => $name) {
            $all[] = [
                'name' => $name,
                'index' => $i + 1,
                'active' => $name === $current,
                'complete' => $i < $index,
            ];
        }

        return [
            'name' => $current,
            'index' => $index + 1,
            'total' => \count(self::STEPS),
            'all' => $all,
        ];
    }
}
