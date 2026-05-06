import htmx from 'htmx.org';

window.closeBlockModal = function () {
  const slot = document.getElementById('modal-slot');
  if (slot) slot.innerHTML = '';
};

const GRID_HOUR_START = 7;
const GRID_PX_PER_MIN = 1.5;

document.querySelectorAll('.sched-grid__day-col').forEach(col => {
  col.addEventListener('click', e => {
    if (e.target.closest('.sched-block')) return;
    const rect = col.getBoundingClientRect();
    const rawMinutes = GRID_HOUR_START * 60 + (e.clientY - rect.top) / GRID_PX_PER_MIN;
    const snapped = Math.floor(rawMinutes / 30) * 30;
    const h = Math.floor(snapped / 60);
    const m = snapped % 60;
    const time = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    const url = col.dataset.newUrl + '?day=' + col.dataset.day + '&time=' + time;
    htmx.ajax('GET', url, { target: '#modal-slot', swap: 'innerHTML' });
  });
});
