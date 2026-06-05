import htmx from 'htmx.org';

// Per-board "test connection" on the project edit page.
// A board row's test must check only that row's key/jql. htmx would otherwise
// serialize the whole form (every board_key[]), so we issue a scoped request
// carrying just this row's values plus the shared base URL.
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.board-row__test');
  if (!btn) {
    return;
  }

  const row = btn.closest('.board-row');
  const form = btn.closest('form');
  const baseUrl = form?.querySelector('[name="jira_base_url"]')?.value ?? '';
  const key = row?.querySelector('input[name="board_key[]"]')?.value ?? '';
  const jql = row?.querySelector('input[name="board_jql[]"]')?.value ?? '';

  htmx.ajax('POST', btn.dataset.testUrl, {
    target: '#jira-test-result',
    swap: 'outerHTML',
    values: { jira_base_url: baseUrl, test_key: key, test_jql: jql },
  });
});
