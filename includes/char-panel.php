<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TALE WEAVER - CHARACTER PANEL LOGIC
 * Logika otwierania/zamykania panelu postaci, nawigacji i notatek.
 * Ładuje się TYLKO na stronie gry (templates/adventure.php).
 * Hook: wp_footer, priorytet 25 (po chat-realtime.php który ma 20).
 */
add_action( 'wp_footer', function () {
	if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
		return;
	}
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function () {
	    console.log('navButtons count =', document.querySelectorAll('.tw-nav-btn').length);

	    // BUG-FIX: The original code immediately called classList.remove('active')
	    // AND classList.add('panel-open') on every nav button before any user
	    // interaction. This caused two problems:
	    //   1. panel-open was added at init but openPanel/closePanel only ever
	    //      removed 'active', never 'panel-open' — so the side-nav appeared
	    //      open on first load and the class was permanently stuck.
	    //   2. Stripping 'active' before the default-tab assignment below
	    //      was harmless but misleading.
	    // Fix: remove both lines entirely. The buttons start without either
	    // class; the default-tab block below adds 'active' to exactly one
	    // button, which is the correct initial state.

	    const panel       = document.getElementById('charPanel');
	    const sideNav     = document.getElementById('twSideNav');
	    const navButtons  = document.querySelectorAll('.tw-nav-btn');
	    const tabContents = document.querySelectorAll('.tw-tab-content');
	    const saveBtn     = document.getElementById('twSaveNotes');
	    const notesField  = document.getElementById('twNotesField');

	    // Activate the default tab button — no panel-open class needed here.
	    const defaultTab = 'status';
	    document.querySelector(`.tw-nav-btn[data-tab="${defaultTab}"]`)?.classList.add('active');
	    document.getElementById(defaultTab)?.classList.add('active');

	    function openPanel(targetTab = null) {
	        if (!panel) {
	            console.warn('NO PANEL charPanel');
	            return;
	        }
	        if (window.twDestroyEchoTooltips) window.twDestroyEchoTooltips();

	        panel.classList.add('is-visible');
	        sideNav?.classList.add('panel-open');

	        if (targetTab) {
	            navButtons.forEach((btn) => btn.classList.remove('active'));
	            tabContents.forEach((c) => c.classList.remove('active'));
	            document.querySelector(`[data-tab="${targetTab}"]`)?.classList.add('active');
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
	        if (window.twDestroyEchoTooltips) window.twDestroyEchoTooltips();
	    }

	    navButtons.forEach((btn) => {
	        btn.addEventListener('click', function (e) {
	            e.stopPropagation();
	            const targetTab = this.dataset.tab;
	            const isAlreadyActive = this.classList.contains('active');
	            if (isAlreadyActive && panel.classList.contains('is-visible')) {
	                closePanel();
	            } else {
	                openPanel(targetTab);
	            }
	        });
	    });

	    document.addEventListener('keydown', (e) => {
	        if (e.key === 'Escape' && panel.classList.contains('is-visible')) closePanel();
	    });

	    document.addEventListener('click', (e) => {
	        if (!panel.contains(e.target) && !sideNav?.contains(e.target)) closePanel();
	    });

	    panel?.addEventListener('click', (e) => e.stopPropagation());

	    // ZAPISYWANIE NOTATEK (AJAX)
	    if (saveBtn && notesField) {
	        saveBtn.addEventListener('click', async function (e) {
	            e.preventDefault();
	            e.stopPropagation();

	            const btn          = this;
	            const originalText = btn.innerText;
	            const charId       = btn.dataset.charId;

	            if (!charId) { console.error('Missing char_id'); return; }

	            btn.innerText = 'SYNCING...';
	            btn.disabled  = true;

	            try {
	                const formData = new FormData();
	                formData.append('action',   'save_player_notes');
	                formData.append('nonce',    window.twAdventureData?.nonce || '');
	                formData.append('notes',    notesField.value);
	                formData.append('char_id',  charId);

	                const ajaxUrl = window.twAdventureData?.ajax_url || '/wp-admin/admin-ajax.php';
	                const res     = await fetch(ajaxUrl, { method: 'POST', body: formData });
	                const data    = await res.json();

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
	                    btn.disabled  = false;
	                }, 2000);
	            }
	        });
	    }
	});
	</script>
	<?php
}, 25 );
