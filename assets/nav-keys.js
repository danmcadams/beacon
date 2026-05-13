function isTypingTarget(el) {
  if (!el) return false;
  if (el.isContentEditable) return true;
  const tag = el.tagName;
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
}

document.addEventListener('keydown', (e) => {
  if (e.key !== 'j' && e.key !== 'k') return;
  if (e.metaKey || e.ctrlKey || e.altKey) return;
  if (isTypingTarget(e.target)) return;

  const links = Array.from(document.querySelectorAll('.app-shell__nav .nav-link'));
  if (links.length === 0) return;

  const activeIdx = links.findIndex((a) => a.classList.contains('nav-link--active'));
  const fallback = e.key === 'k' ? -1 : links.length;
  const base = activeIdx === -1 ? fallback : activeIdx;
  const delta = e.key === 'k' ? 1 : -1;
  const next = base + delta;
  if (next < 0 || next >= links.length) return;

  e.preventDefault();
  window.location.assign(links[next].href);
});
