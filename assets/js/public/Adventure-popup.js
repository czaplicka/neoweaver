/**
 * NeoWeaver â Adventure Intro Popup
 * Plik: /wp-content/plugins/neoweaver/assets/js/nw-adventure-popup.js
 *
 * Pokazuje popup tylko przy PIERWSZYM wejĹciu na stronÄ (localStorage flag).
 * ZamkniÄcie: przycisk X, przycisk [ENTER GAME], klik poza popupem, klawisz ESC.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'nw_adventure_intro_seen';

    /** SprawdĹş czy localStorage jest dostÄpny (sandbox guard) */
    function storageAvailable() {
        try {
            localStorage.setItem('__nw_test__', '1');
            localStorage.removeItem('__nw_test__');
            return true;
        } catch (e) {
            return false;
        }
    }

    function hasSeenIntro() {
        if (!storageAvailable()) return false;
        return localStorage.getItem(STORAGE_KEY) === '1';
    }

    function markIntroSeen() {
        if (storageAvailable()) {
            localStorage.setItem(STORAGE_KEY, '1');
        }
    }

    /** Zamknij popup z animacjÄ wyjĹcia */
    function closePopup(overlay) {
        overlay.classList.add('nw-hidden');
        markIntroSeen();

        // usuĹ z DOM po zakoĹczeniu animacji
        overlay.addEventListener('animationend', function onEnd() {
            overlay.removeEventListener('animationend', onEnd);
            overlay.remove();
        }, { once: true });

        // fallback â usuĹ po 400 ms gdyby animacja nie wystrzeliĹa
        setTimeout(function () {
            if (overlay.parentNode) overlay.remove();
        }, 400);
    }

    function init() {
        var overlay = document.getElementById('nw-intro-popup');
        if (!overlay) return; // markup nieobecny

        // JeĹli gracz juĹź widziaĹ intro â od razu usuĹ z DOM
        if (hasSeenIntro()) {
            overlay.remove();
            return;
        }

        var btnClose   = document.getElementById('nw-popup-close');
        var btnConfirm = document.getElementById('nw-popup-confirm');

        /** Zamknij przez X */
        if (btnClose) {
            btnClose.addEventListener('click', function () {
                closePopup(overlay);
            });
        }

        /** Zamknij przez [ENTER GAME] */
        if (btnConfirm) {
            btnConfirm.addEventListener('click', function () {
                closePopup(overlay);
            });
        }

        /** Zamknij klikiem w overlay (poza .nw-popup-screen) */
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                closePopup(overlay);
            }
        });

        /** Zamknij klawiszem ESC */
        document.addEventListener('keydown', function onEsc(e) {
            if (e.key === 'Escape' || e.key === 'Esc') {
                document.removeEventListener('keydown', onEsc);
                closePopup(overlay);
            }
        });

        /** Focus trap â trzymaj fokus wewnÄtrz popupu gdy otwarty */
        var focusable = overlay.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        var firstFocusable = focusable[0];
        var lastFocusable  = focusable[focusable.length - 1];

        overlay.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab') return;
            if (e.shiftKey) {
                if (document.activeElement === firstFocusable) {
                    e.preventDefault();
                    lastFocusable.focus();
                }
            } else {
                if (document.activeElement === lastFocusable) {
                    e.preventDefault();
                    firstFocusable.focus();
                }
            }
        });

        // ustaw fokus na pierwszym interaktywnym elemencie po otwarciu
        setTimeout(function () {
            if (firstFocusable) firstFocusable.focus();
        }, 150);
    }

    // uruchom po zaĹadowaniu DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
