/**
 * @file
 * Interaksi halaman produk (theme blank_theme):
 * - tab deskripsi / spesifikasi / ulasan,
 * - galeri foto (thumbnail),
 * - tombol -/+ untuk jumlah,
 * - sinkronisasi harga & stok saat ukuran (variasi) diganti.
 *
 * Data variasi dikirim server lewat drupalSettings.blankProduk (lihat
 * blank_theme_produk() di blank_theme.theme), jadi JS ini tidak menghitung
 * harga sendiri. Nilai radio memakai id attribute value Commerce.
 *
 * Script dimuat dengan `defer` (lihat blank_theme.libraries.yml).
 */
(function () {
  'use strict';

  var RADIO = 'input[name$="[attributes][attribute_ukuran]"]';

  /**
   * Tab: satu tab aktif, panel lain disembunyikan.
   *
   * @param {HTMLElement} root
   *   Elemen [data-produk-tabs].
   */
  function initTabs(root) {
    if (root.dataset.produkTabsInit === 'true') {
      return;
    }
    root.dataset.produkTabsInit = 'true';

    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-produk-tab]'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('[data-produk-panel]'));
    if (!tabs.length) {
      return;
    }

    function aktifkan(nama) {
      tabs.forEach(function (tab) {
        var aktif = tab.dataset.produkTab === nama;
        tab.classList.toggle('is-aktif', aktif);
        tab.setAttribute('aria-selected', aktif ? 'true' : 'false');
        tab.setAttribute('tabindex', aktif ? '0' : '-1');
      });
      panels.forEach(function (panel) {
        panel.hidden = panel.dataset.produkPanel !== nama;
      });
    }

    tabs.forEach(function (tab, index) {
      tab.addEventListener('click', function () {
        aktifkan(tab.dataset.produkTab);
      });
      tab.addEventListener('keydown', function (event) {
        var langkah = event.key === 'ArrowRight' ? 1 : (event.key === 'ArrowLeft' ? -1 : 0);
        if (!langkah) {
          return;
        }
        event.preventDefault();
        var tujuan = tabs[(index + langkah + tabs.length) % tabs.length];
        tujuan.focus();
        aktifkan(tujuan.dataset.produkTab);
      });
    });

    var awal = root.querySelector('[data-produk-tab].is-aktif') || tabs[0];
    aktifkan(awal.dataset.produkTab);
  }

  /**
   * Galeri: thumbnail memilih foto utama.
   *
   * @param {HTMLElement} root
   *   Elemen [data-produk-galeri].
   */
  function initGaleri(root) {
    if (root.dataset.produkGaleriInit === 'true') {
      return;
    }
    var thumbs = Array.prototype.slice.call(root.querySelectorAll('[data-produk-thumb]'));
    var fotos = Array.prototype.slice.call(root.querySelectorAll('[data-produk-foto]'));
    if (thumbs.length < 2 || fotos.length < 2) {
      return;
    }
    root.dataset.produkGaleriInit = 'true';

    function tampil(nomor) {
      fotos.forEach(function (foto) {
        var aktif = Number(foto.dataset.produkFoto) === nomor;
        foto.hidden = !aktif;
        foto.classList.toggle('is-aktif', aktif);
      });
      thumbs.forEach(function (thumb) {
        thumb.classList.toggle('is-aktif', Number(thumb.dataset.produkThumb) === nomor);
      });
    }

    thumbs.forEach(function (thumb) {
      thumb.addEventListener('click', function () {
        tampil(Number(thumb.dataset.produkThumb));
      });
    });
    tampil(0);
  }

  /** Peta attribute value id → data variasi (diisi sekali). */
  var petaCache = null;

  /**
   * Peta variasi dari drupalSettings.
   *
   * @return {Object|null}
   *   Objek dengan key id attribute value, atau null bila data tidak ada.
   */
  function petaVariasi() {
    var settings = window.drupalSettings && drupalSettings.blankProduk;
    if (!settings || !settings.variations || !settings.variations.length) {
      return null;
    }
    if (!petaCache) {
      petaCache = {};
      settings.variations.forEach(function (variasi) {
        petaCache[String(variasi.attribute)] = variasi;
      });
    }
    return petaCache;
  }

  /**
   * Sinkronkan harga, harga coret, diskon, dan stok dengan ukuran terpilih.
   *
   * Dipanggil saat ukuran diganti dan setiap kali behavior ditempel ulang
   * (setelah AJAX/BigPipe menyisipkan form add-to-cart).
   *
   * @param {HTMLElement} root
   *   Elemen .produk (article halaman produk).
   */
  function sinkron(root) {
    var peta = petaVariasi();
    if (!peta) {
      return;
    }
    var radio = root.querySelector(RADIO + ':checked');
    var variasi = radio ? peta[String(radio.value)] : null;
    if (!variasi) {
      return;
    }

    var isi = function (selector, teks) {
      var el = root.querySelector(selector);
      if (el) {
        el.textContent = teks;
      }
    };
    isi('[data-produk-harga-nilai]', variasi.harga);
    isi('[data-produk-stok]', variasi.stok);

    var coret = root.querySelector('[data-produk-harga-coret]');
    if (coret) {
      coret.textContent = variasi.harga_coret || '';
      coret.hidden = !variasi.harga_coret;
    }
    var diskon = root.querySelector('[data-produk-diskon]');
    if (diskon) {
      diskon.textContent = variasi.diskon ? variasi.diskon_label + ' ' + variasi.diskon + '%' : '';
      diskon.hidden = !variasi.diskon;
    }
  }

  /**
   * Buat tombol -/+ di sekitar field jumlah.
   *
   * Markup form Commerce tidak menyediakan tempat untuk markup tambahan
   * (prefix/suffix-nya difilter XSS), jadi tombolnya dibuat di sini. Fungsi ini
   * dipanggil ulang setiap behavior ditempel agar tombol tetap muncul setelah
   * form disisipkan lewat AJAX/BigPipe.
   *
   * @param {HTMLElement} root
   *   Elemen .produk.
   */
  function buatStepper(root) {
    var input = root.querySelector('.field--name-quantity input[type="number"]');
    if (!input || !input.parentNode || input.parentNode.querySelector('[data-produk-step]')) {
      return;
    }
    var label = (window.drupalSettings && drupalSettings.blankProduk && drupalSettings.blankProduk.label) || {};
    var kurang = document.createElement('button');
    kurang.type = 'button';
    kurang.className = 'produk__step';
    kurang.dataset.produkStep = '-1';
    kurang.setAttribute('aria-label', label.stepDown || 'Kurangi');
    kurang.textContent = '−';

    var tambah = kurang.cloneNode(true);
    tambah.dataset.produkStep = '1';
    tambah.setAttribute('aria-label', label.stepUp || 'Tambah');
    tambah.textContent = '+';

    input.parentNode.insertBefore(kurang, input);
    input.parentNode.insertBefore(tambah, input.nextSibling);
  }

  /**
   * Klik tombol -/+ mengubah nilai field jumlah (dipasang sekali per halaman).
   *
   * @param {HTMLElement} root
   *   Elemen .produk.
   */
  function bindJumlah(root) {
    root.addEventListener('click', function (event) {
      var tombol = event.target.closest ? event.target.closest('[data-produk-step]') : null;
      if (!tombol) {
        return;
      }
      var input = root.querySelector('.field--name-quantity input[type="number"]');
      if (!input) {
        return;
      }
      event.preventDefault();
      var minimal = input.min === '' ? 1 : parseFloat(input.min);
      if (isNaN(minimal)) {
        minimal = 1;
      }
      var nilai = (parseFloat(input.value) || 0) + (parseFloat(tombol.dataset.produkStep) || 0);
      input.value = Math.max(minimal, nilai);
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  /**
   * Inisialisasi satu halaman produk.
   *
   * @param {HTMLElement} root
   *   Elemen .produk.
   */
  function initProduk(root) {
    if (!root) {
      return;
    }

    // Bagian yang cukup dipasang sekali: tab, galeri, listener radio & tombol.
    if (root.dataset.produkInit !== 'true') {
      root.dataset.produkInit = 'true';

      var tabs = root.querySelector('[data-produk-tabs]');
      if (tabs) {
        initTabs(tabs);
      }
      var galeri = root.querySelector('[data-produk-galeri]');
      if (galeri) {
        initGaleri(galeri);
      }
      document.addEventListener('change', function (event) {
        var target = event.target;
        if (target && target.matches && target.matches(RADIO)) {
          sinkron(root);
        }
      });
      bindJumlah(root);

      // Commerce membangun ulang form add-to-cart (AJAX) saat ukuran diganti,
      // sehingga tombol -/+ ikut terhapus dan harga harus disinkronkan ulang.
      // Catatan: AJAX Drupal menghentikan propagasi event `change`, jadi
      // sinkronisasi diambil dari mutasi DOM (bukan dari listener change).
      // Pemanggilan ulang aman: buatStepper() berhenti bila tombolnya sudah ada
      // dan sinkron() hanya menulis di luar wadah ini, jadi tidak ada mutasi
      // yang berulang terus.
      var wadah = root.querySelector('.produk__aksi');
      if (wadah && window.MutationObserver) {
        new MutationObserver(function () {
          buatStepper(root);
          sinkron(root);
        }).observe(wadah, { childList: true, subtree: true });
      }
    }

    // Selalu dijalankan ulang: form add-to-cart dapat baru disisipkan lewat
    // BigPipe/AJAX setelah halaman tampil (harga & tombol -/+ mengikutinya).
    buatStepper(root);
    sinkron(root);
  }

  document.querySelectorAll('.produk').forEach(initProduk);

  if (window.Drupal && Drupal.behaviors) {
    Drupal.behaviors.blankThemeProduk = {
      attach: function (context) {
        (context || document).querySelectorAll('.produk').forEach(initProduk);
      }
    };
  }
})();
