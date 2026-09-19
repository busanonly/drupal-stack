/**
 * @file
 * Membuka Snap popup (Midtrans) dan mengarahkan pembeli setelah pembayaran.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Apakah popup sudah pernah dibuka otomatis di halaman ini.
   */
  var autoOpened = false;

  /**
   * Menampilkan pesan status di area pesan Midtrans.
   *
   * @param {string} message
   *   Pesan.
   * @param {boolean} isError
   *   TRUE bila pesan error.
   */
  function setStatus(message, isError) {
    var status = document.querySelector('[data-commerce-midtrans-status]');
    if (!status) {
      return;
    }
    status.textContent = message || '';
    status.hidden = !message;
    status.classList.toggle('commerce-midtrans-error', !!isError);
  }

  Drupal.behaviors.commerceMidtransSnap = {
    attach: function (context) {
      var settings = drupalSettings.commerceMidtrans;
      if (!settings || !settings.token) {
        return;
      }

      var buttons = once('commerceMidtransSnap', '[data-commerce-midtrans-pay]', context);
      if (!buttons.length) {
        return;
      }

      /**
       * Membuka Snap popup.
       */
      function pay() {
        if (typeof window.snap === 'undefined') {
          setStatus(Drupal.t('Midtrans Snap gagal dimuat. Muat ulang halaman ini untuk mencoba lagi.'), true);
          return;
        }
        window.snap.pay(settings.token, {
          onSuccess: function () {
            window.location.href = settings.returnUrl;
          },
          onPending: function () {
            window.location.href = settings.returnUrl;
          },
          onError: function () {
            window.location.href = settings.cancelUrl;
          },
          onClose: function () {
            setStatus(Drupal.t('Jendela pembayaran ditutup sebelum pembayaran selesai. Klik "Bayar dengan Midtrans" untuk mencoba lagi.'), true);
          }
        });
      }

      buttons.forEach(function (button) {
        button.addEventListener('click', function (event) {
          event.preventDefault();
          pay();
        });
      });

      if (settings.autoOpen && !autoOpened) {
        autoOpened = true;
        pay();
      }
    }
  };
})(Drupal, drupalSettings);
