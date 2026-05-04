(function () {
  function initRows(containerId, templateId, addBtnId) {
    const container = document.getElementById(containerId);
    const addBtn    = document.getElementById(addBtnId);
    if (!container || !addBtn) return;

    function nextIdx() {
      return container.querySelectorAll('.sched-wiz-row').length;
    }

    addBtn.addEventListener('click', () => {
      const tpl = document.getElementById(templateId);
      if (!tpl) return;
      const idx   = nextIdx();
      const clone = tpl.content.cloneNode(true);
      clone.querySelectorAll('[name],[id]').forEach(el => {
        if (el.name) el.name = el.name.replace(/__IDX__/g, idx);
        if (el.id)   el.id   = el.id.replace(/__IDX__/g, idx);
      });
      container.appendChild(clone);
    });

    container.addEventListener('click', e => {
      const btn = e.target.closest('[data-del-row]');
      if (btn) btn.closest('.sched-wiz-row').remove();
    });
  }

  initRows('anchors-rows', 'anchor-row-tpl', 'add-anchor-btn');
  initRows('overrides-rows', 'override-row-tpl', 'add-override-btn');
})();
