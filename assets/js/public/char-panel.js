document.addEventListener('DOMContentLoaded', function () {
	console.log('navButtons count =', document.querySelectorAll('.tw-nav-btn').length);

	const panel = document.getElementById('charPanel');
	const sideNav = document.getElementById('twSideNav');
	const navButtons = document.querySelectorAll('.tw-nav-btn');
	const tabContents = document.querySelectorAll('.tw-tab-content');
	const saveBtn = document.getElementById('twSaveNotes');
	const notesField = document.getElementById('twNotesField');

	const defaultTab = 'status';
	document.querySelector('.tw-nav-btn[data-tab="' + defaultTab + '"]')?.classList.add('active');
	document.getElementById(defaultTab)?.classList.add('active');

	function openPanel(targetTab = null) {
		if (!panel) {
			console.warn('NO PANEL charPanel');
			return;
		}

		if (window.twDestroyEchoTooltips) {
			window.twDestroyEchoTooltips();
		}

		panel.classList.add('is-visible');
		sideNav?.classList.add('panel-open');

		if (targetTab) {
			navButtons.forEach(function (btn) {
				btn.classList.remove('active');
			});

			tabContents.forEach(function (content) {
				content.classList.remove('active');
			});

			document.querySelector('[data-tab="' + targetTab + '"]')?.classList.add('active');

			const target = document.getElementById(targetTab);
			if (target) {
				target.classList.add('active');
			}
		}
	}

	function closePanel() {
		if (!panel) {
			return;
		}

		panel.classList.remove('is-visible');
		sideNav?.classList.remove('panel-open');

		navButtons.forEach(function (btn) {
			btn.classList.remove('active');
		});

		tabContents.forEach(function (content) {
			content.classList.remove('active');
		});

		if (window.twDestroyEchoTooltips) {
			window.twDestroyEchoTooltips();
		}
	}

	navButtons.forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			e.stopPropagation();

			const targetTab = this.dataset.tab;
			const isAlreadyActive = this.classList.contains('active');
			const isVisible = panel ? panel.classList.contains('is-visible') : false;

			if (isAlreadyActive && isVisible) {
				closePanel();
			} else {
				openPanel(targetTab);
			}
		});
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && panel && panel.classList.contains('is-visible')) {
			closePanel();
		}
	});

	document.addEventListener('click', function (e) {
		const clickedInsidePanel = panel ? panel.contains(e.target) : false;
		const clickedInsideNav = sideNav ? sideNav.contains(e.target) : false;

		if (!clickedInsidePanel && !clickedInsideNav) {
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
				formData.append('nonce', window.twAdventureData?.nonce || '');
				formData.append('notes', notesField.value);
				formData.append('char_id', charId);

				const ajaxUrl = window.twAdventureData?.ajax_url || '/wp-admin/admin-ajax.php';
				const res = await fetch(ajaxUrl, {
					method: 'POST',
					body: formData
				});

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
				setTimeout(function () {
					btn.innerText = originalText;
					btn.disabled = false;
				}, 2000);
			}
		});
	}
});
