import './styles/app.css';
import 'htmx.org';
import './project-reorder.js';
import './project-edit.js';
import './schedule.js';
import './schedule-wizard.js';
import './nav-keys.js';
import 'notifications';

const root   = document.getElementById('root');
const toggle = document.getElementById('theme-toggle');

function applyTheme(theme) {
  root.dataset.theme = theme;
  localStorage.setItem('theme', theme);
  if (toggle) toggle.textContent = theme === 'dark' ? '☾ dark' : '☀ light';
}

applyTheme(localStorage.getItem('theme') || 'dark');

if (toggle) {
  toggle.addEventListener('click', () => {
    applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark');
  });
}
