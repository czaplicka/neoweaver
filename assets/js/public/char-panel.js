document.addEventListener('DOMContentLoaded', function () {

	const panel      = document.getElementById('charPanel');
	const sideNav    = document.getElementById('twSideNav');
	const navButtons = document.querySelectorAll('.tw-nav-btn');
	const tabContents = document.querySelectorAll('.tw-tab-content');
	const saveBtn    = document.getElementById('twSaveNotes');
	const notesField = document.getElementById('twNotesField');

	// Render Lucide icons (side nav + any data-lucide in panel).
	if (window.lucide && typeof window.lucide.createIcons === 'function') {
		window.lucide.createIcons();
	}

	const defaultTab = 'status';
	document.querySelector('.tw-nav-btn[data-tab="' + defaultTab + '"]')?.classList.add('active');
	document.getElementById(defaultTab)?.classList.add('active');

	function openPanel(targetTab) {
		if (!panel) {
			console.warn('charPanel not found');
			return;
		}

		if (window.twDestroyEchoTooltips) {
			window.twDestroyEchoTooltips();
		}

		panel.classList.add('is-visible');
		sideNav?.classList.add('panel-open');

		if (targetTab) {
			navButtons.forEach(function (btn) { btn.classList.remove('active'); });
			tabContents.forEach(function (c) { c.classList.remove('active'); });

			document.querySelector('[data-tab="' + targetTab + '"]')?.classList.add('active');
			document.getElementById(targetTab)?.classList.add('active');
		}
	}

	function closePanel() {
		if (!panel) { return; }

		panel.classList.remove('is-visible');
		sideNav?.classList.remove('panel-open');

		navButtons.forEach(function (btn) { btn.classList.remove('active'); });
		tabContents.forEach(function (c) { c.classList.remove('active'); });

		if (window.twDestroyEchoTooltips) {
			window.twDestroyEchoTooltips();
		}
	}

	navButtons.forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			e.stopPropagation();

			const targetTab      = this.dataset.tab;
			const isAlreadyActive = this.classList.contains('active');
			const isVisible       = panel ? panel.classList.contains('is-visible') : false;

			if (isAlreadyActive && isVisible) {
				closePanel();
			} else {
				openPanel(targetTab);
			}
		});
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && panel?.classList.contains('is-visible')) {
			closePanel();
		}
	});

	document.addEventListener('click', function (e) {
		if (!panel?.contains(e.target) && !sideNav?.contains(e.target)) {
			closePanel();
		}
	});

	panel?.addEventListener('click', function (e) {
		e.stopPropagation();
	});

	if (saveBtn && notesField) {
		saveBtn.addEventListener('click', async function (e) {
			e.preventDefault();
			e.stopPropagation();

			const btn          = this;
			const originalText = btn.innerText;
			const charId       = btn.dataset.charId;

			if (!charId) {
				console.error('Missing char_id on save-notes button');
				return;
			}

			btn.innerText = 'SYNCING...';
			btn.disabled  = true;

			try {
				const formData = new FormData();
				formData.append('action',   'save_player_notes');
				formData.append('nonce',    window.twAdventureData?.nonce    || '');
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
				console.error('Notes save error:', err);
				btn.innerText = 'ERROR';
			} finally {
				setTimeout(function () {
					btn.innerText = originalText;
					btn.disabled  = false;
				}, 2000);
			}
		});
	}
});
