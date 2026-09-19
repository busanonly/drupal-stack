/**
 * @file
 * Interaksi header situs (theme blank_theme): tombol menu untuk layar kecil.
 *
 * Tanpa dependency. Script dimuat dengan atribut `defer` (lihat
 * blank_theme.libraries.yml), jadi DOM sudah terparse saat dijalankan. Bila
 * `Drupal.behaviors` tersedia (library lain memuat core/drupal), inisialisasi
 * juga dijalankan lagi untuk header yang disisipkan lewat AJAX.
 */
(function () {
  'use strict';

  var DESKTOP = '(min-width: 1200px)';

  /**
   * Pasang interaksi pada satu elemen header.
   *
   * @param {HTMLElement} root
   *   Elemen .site-header ([data-header]).
   */
  function initHeader(root) {
    if (root.dataset.headerInit === 'true') {
      return;
    }
    root.dataset.headerInit = 'true';

    var toggle = root.querySelector('[data-header-toggle]');
    var panel = root.querySelector('[data-header-panel]');
    if (!toggle || !panel) {
      return;
    }

    var labelOpen = toggle.dataset.labelOpen || toggle.getAttribute('aria-label') || 'Menu';
    var labelClose = toggle.dataset.labelClose || labelOpen;
    var desktop = window.matchMedia(DESKTOP);

    function isOpen() {
      return root.dataset.open === 'true';
    }

    function setOpen(open) {
      root.dataset.open = open ? 'true' : 'false';
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? labelClose : labelOpen);
      document.documentElement.classList.toggle('site-header-menu-open', open);
    }

    toggle.addEventListener('click', function () {
      setOpen(!isOpen());
    });

    // Menutup panel saat link diklik (navigasi halaman).
    panel.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        setOpen(false);
      });
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isOpen()) {
        setOpen(false);
        toggle.focus();
      }
    });

    document.addEventListener('click', function (event) {
      if (isOpen() && event.target instanceof Node && !root.contains(event.target)) {
        setOpen(false);
      }
    });

    // Tutup otomatis saat viewport melebar ke layout desktop.
    function syncToWidth() {
      if (desktop.matches) {
        setOpen(false);
      }
    }
    desktop.addEventListener('change', syncToWidth);

    setOpen(false);
    syncToWidth();
  }

  document.querySelectorAll('[data-header]').forEach(initHeader);

  if (window.Drupal && Drupal.behaviors) {
    Drupal.behaviors.blankThemeHeader = {
      attach: function (context) {
        (context || document).querySelectorAll('[data-header]').forEach(initHeader);
      }
    };
  }
})();
