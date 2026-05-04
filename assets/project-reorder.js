(function () {
  const table = document.querySelector('.projects-table[data-reorder-url]');
  if (!table) return;

  const reorderUrl = table.dataset.reorderUrl;
  const tbody = table.querySelector('tbody');
  const endZone = document.querySelector('.projects-drop-end');

  let draggingId = null;
  let overRowId = null;

  function getOrderedIds() {
    return [...tbody.querySelectorAll('tr[data-id]')].map(tr => tr.dataset.id);
  }

  function moveBefore(sourceId, targetId) {
    const source = tbody.querySelector(`tr[data-id="${sourceId}"]`);
    const target = tbody.querySelector(`tr[data-id="${targetId}"]`);
    if (source && target) tbody.insertBefore(source, target);
  }

  function moveToEnd(sourceId) {
    const source = tbody.querySelector(`tr[data-id="${sourceId}"]`);
    if (source) tbody.appendChild(source);
  }

  function persistOrder() {
    fetch(reorderUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids: getOrderedIds() }),
    });
  }

  function setTableDragging(on) {
    table.classList.toggle('projects-table--dragging', on);
    if (endZone) endZone.hidden = !on;
  }

  function clearOverRow() {
    if (overRowId) {
      tbody.querySelector(`tr[data-id="${overRowId}"]`)?.classList.remove('projects-table__row--over');
      overRowId = null;
    }
  }

  function clearDragState() {
    if (draggingId) {
      tbody.querySelector(`tr[data-id="${draggingId}"]`)?.classList.remove('projects-table__row--dragging');
      draggingId = null;
    }
    clearOverRow();
    setTableDragging(false);
    if (endZone) endZone.classList.remove('projects-drop-end--active');
  }

  tbody.addEventListener('dragstart', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    draggingId = tr.dataset.id;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', draggingId);
    // Delay so browser snapshots the row before fade
    requestAnimationFrame(() => {
      tr.classList.add('projects-table__row--dragging');
      setTableDragging(true);
    });
  });

  tbody.addEventListener('dragover', (e) => {
    if (!draggingId) return;
    const tr = e.target.closest('tr[data-id]');
    if (!tr || tr.dataset.id === draggingId) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (overRowId !== tr.dataset.id) {
      clearOverRow();
      overRowId = tr.dataset.id;
      tr.classList.add('projects-table__row--over');
    }
  });

  tbody.addEventListener('dragleave', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (tr && tr.dataset.id === overRowId && !tr.contains(e.relatedTarget)) {
      tr.classList.remove('projects-table__row--over');
      overRowId = null;
    }
  });

  tbody.addEventListener('drop', (e) => {
    e.preventDefault();
    const tr = e.target.closest('tr[data-id]');
    if (!tr || !draggingId || tr.dataset.id === draggingId) return;
    moveBefore(draggingId, tr.dataset.id);
    persistOrder();
    clearDragState();
  });

  tbody.addEventListener('dragend', () => clearDragState());

  // Whole-row click → navigate to detail (skip if clicking a link)
  tbody.addEventListener('click', (e) => {
    if (draggingId) return;
    if (e.target.closest('a')) return;
    const tr = e.target.closest('tr[data-href]');
    if (tr) location.href = tr.dataset.href;
  });

  // End drop zone
  if (endZone) {
    endZone.addEventListener('dragover', (e) => {
      if (!draggingId) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      endZone.classList.add('projects-drop-end--active');
    });

    endZone.addEventListener('dragleave', () => {
      endZone.classList.remove('projects-drop-end--active');
    });

    endZone.addEventListener('drop', (e) => {
      e.preventDefault();
      if (draggingId) moveToEnd(draggingId);
      persistOrder();
      clearDragState();
    });
  }
})();
