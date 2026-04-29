// GestHotel FE - JavaScript principale

'use strict';

// Sidebar toggle mobile
document.addEventListener('DOMContentLoaded', function () {
  const toggle = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', () => sidebar.classList.toggle('show'));
  }

  // Auto-dismiss alert dopo 5 secondi
  document.querySelectorAll('.alert-dismissible[data-autohide]').forEach(el => {
    setTimeout(() => {
      const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
      bsAlert && bsAlert.close();
    }, 5000);
  });

  // Conferma eliminazione
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', function (e) {
      if (!confirm(this.dataset.confirm || 'Confermare l\'operazione?')) {
        e.preventDefault();
      }
    });
  });

  // Tooltip Bootstrap
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el);
  });
});

// Utility: formatta importo in euro
function formatEuro(value) {
  return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(value);
}

// Utility: flash message
function flashMessage(msg, type = 'success') {
  const div = document.createElement('div');
  div.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 end-0 m-3`;
  div.style.zIndex = 9999;
  div.setAttribute('data-autohide', '1');
  div.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
  document.body.appendChild(div);
  setTimeout(() => div.remove(), 5500);
}
