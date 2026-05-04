(function () {
  window.closeBlockModal = function () {
    const slot = document.getElementById('modal-slot');
    if (slot) slot.innerHTML = '';
  };
})();
