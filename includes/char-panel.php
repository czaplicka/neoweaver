<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_footer', function () {
    if ( ! is_page( 2857 ) || ! get_current_user_id() ) return;
    ?>
    <script>
document.addEventListener('DOMContentLoaded', function () {
    console.log('navButtons count =', document.querySelectorAll('.tw-nav-btn').length);
    
    // Inicjalizacja stanów przycisków (opcjonalne, wg Twojego kodu)
    document.querySelectorAll('.tw-nav-btn').forEach(btn => {
        btn.classList.remove('active'); 
        btn.classList.add('panel-open'); 
    });
	
    const panel       = document.getElementById('charPanel');
    const sideNav     = document.getElementById('twSideNav');
    const navButtons  = document.querySelectorAll('.tw-nav-btn');
    const tabContents = document.querySelectorAll('.tw-tab-content');
    const saveBtn     = document.getElementById('twSaveNotes');
    const notesField  = document.getElementById('twNotesField');

    // DOMYŚLNY TAB (jeśli panel jest otwarty, ten będzie aktywny)
    const defaultTab = 'status';
    document.querySelector(`.tw-nav-btn[data-tab="${defaultTab}"]`)?.classList.add('active');
    document.getElementById(defaultTab)?.classList.add('active');

    function openPanel(targetTab = null) {
        if (!panel) {
            console.warn('NO PANEL charPanel');
            return;
        }

        console.log('openPanel start, targetTab=', targetTab);

        // Zamknij tooltipy z innego narzędzia, jeśli istnieją
        if (window.twDestroyEchoTooltips) {
            window.twDestroyEchoTooltips();
        }

        panel.classList.add('is-visible');
        sideNav?.classList.add('panel-open');
        console.log('classes after open:', panel.className, sideNav?.className);

        if (targetTab) {
            navButtons.forEach((btn) => btn.classList.remove('active'));
            tabContents.forEach((c) => c.classList.remove('active'));

            const btn = document.querySelector(`[data-tab="${targetTab}"]`);
            btn?.classList.add('active');

            const target = document.getElementById(targetTab);
            if (target) target.classList.add('active');
        }
    }
	
    function closePanel() {
        if (!panel) return;

        panel.classList.remove('is-visible');
        sideNav?.classList.remove('panel-open');

        navButtons.forEach((btn) => btn.classList.remove('active'));
        tabContents.forEach((c) => c.classList.remove('active'));

        if (window.twDestroyEchoTooltips) {
            window.twDestroyEchoTooltips();
        }
    }

    // Obsługa kliknięć w przyciski nawigacji
    navButtons.forEach((btn) => {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const targetTab = this.dataset.tab;

            console.log('CLICK NAV', { targetTab });

            const isAlreadyActive = this.classList.contains('active');
            
            if (isAlreadyActive && panel.classList.contains('is-visible')) {
                console.log('-> closePanel()');
                closePanel();
            } else {
                console.log('-> openPanel()', targetTab);
                openPanel(targetTab);
            }
        });
    });

    // Zamykanie ESC
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && panel.classList.contains('is-visible')) {
            closePanel();
        }
    });

    // Zamykanie kliknięciem poza
    document.addEventListener('click', (e) => {
        if (!panel.contains(e.target) && !sideNav?.contains(e.target)) {
            closePanel();
        }
    });

    panel?.addEventListener('click', (e) => e.stopPropagation());

    // ZAPISYWANIE NOTATEK (AJAX)
    if (saveBtn && notesField) {
        saveBtn.addEventListener('click', async function (e) {
            e.preventDefault();
            e.stopPropagation();

            const btn = this;
            const originalText = btn.innerText;
            const charId = btn.dataset.charId;

            if (!charId) {
                console.error('Missing char_id');
                return;
            }

            btn.innerText = 'SYNCING...';
            btn.disabled = true;

            try {
                const formData = new FormData();
                formData.append('action', 'save_player_notes');
                // Jeśli masz zdefiniowany nonce globalnie, użyj go
                formData.append('nonce', window.twAjaxNonce || '');
                formData.append('notes', notesField.value);
                formData.append('char_id', charId);

                // Zakładamy standardową ścieżkę WordPress AJAX
                const ajaxUrl = '/wp-admin/admin-ajax.php';

                const res = await fetch(ajaxUrl, { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    btn.innerText = 'SYNC SUCCESS';
                } else {
                    throw new Error(data.data || 'Save failed');
                }
            } catch (err) {
                console.error('Notes error:', err);
                btn.innerText = 'ERROR';
            } finally {
                setTimeout(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                }, 2000);
            }
        });
    }
});
    </script>
    <?php
}, 25 );
