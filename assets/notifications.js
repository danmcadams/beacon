const DISABLED_KEY = 'beacon.notifications.off';
const timers = new Map();

function todayAt(hhmm) {
  const [h, m] = hhmm.split(':').map(Number);
  const d = new Date();
  d.setHours(h, m, 0, 0);
  return d.getTime();
}

function fire(block, lead) {
  if (Date.now() > todayAt(block.start) + 60_000) return;
  new Notification(`${block.label} · in ${lead} min`, {
    body: `Starts at ${block.start}`,
    tag: `block:${block.start}:${lead}`,
  });
}

function clearAll() {
  timers.forEach(id => clearTimeout(id));
  timers.clear();
}

function reschedule() {
  clearAll();
  const el = document.getElementById('notif-data');
  if (!el || Notification.permission !== 'granted') return;
  if (localStorage.getItem(DISABLED_KEY)) return;
  let data;
  try { data = JSON.parse(el.textContent || '{}'); } catch { return; }
  const schedule = data.schedule || [];
  const leads    = data.leads || [];
  const now = Date.now();
  for (const block of schedule) {
    for (const lead of leads) {
      const fireAt = todayAt(block.start) - lead * 60_000;
      if (fireAt <= now) continue;
      if (fireAt - now > 6 * 3600 * 1000) continue;
      const id = setTimeout(() => fire(block, lead), fireAt - now);
      timers.set(`${block.start}:${lead}`, id);
    }
  }
}

document.addEventListener('DOMContentLoaded', reschedule);

export function requestPermission() {
  return Notification.requestPermission().then(reschedule);
}

export function isDisabled() {
  return !!localStorage.getItem(DISABLED_KEY);
}

export function disable() {
  localStorage.setItem(DISABLED_KEY, '1');
  clearAll();
}

export function enable() {
  localStorage.removeItem(DISABLED_KEY);
  reschedule();
}

export function sendTest() {
  new Notification('Beacon · test', { body: 'Notifications are working.' });
}
