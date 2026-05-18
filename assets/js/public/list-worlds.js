(function () {
	function getConfig() {
		return window.twListWorldsData || {};
	}

	function openWorldModal(data) {
		document.getElementById('m-name').innerText = data.name || '';
		document.getElementById('m-campaign').innerText = data.campaign || '';
		document.getElementById('m-desc').innerText = data.desc || '';
		document.getElementById('m-magic').innerText = data.magic || '';
		document.getElementById('m-tech').innerText = data.tech || '';
		document.getElementById('m-vibe').innerText = data.vibe || '';
		document.getElementById('m-wealth').innerText = data.wealth || '';
		document.getElementById('m-size').innerText = data.size || '';
		document.getElementById('m-diff').innerText = data.diff || '';
		document.getElementById('m-gods').innerText = data.gods || '';
		document.getElementById('m-relations').innerText = data.relations || '';
		document.getElementById('m-tag1').innerText = data.tag1 || '';
		document.getElementById('m-tag2').innerText = data.tag2 || '';
		document.getElementById('m-tag3').innerText = data.tag3 || '';
		document.getElementById('m-conf-title').innerText = data.conf_title || '';
		document.getElementById('m-conf-summary').innerText = data.conf_summary || '';

		if (data.conf_side_1 || data.conf_side_2) {
			document.getElementById('m-conf-sides').innerText =
				(data.conf_side_1 || 'Side A') + ' vs ' + (data.conf_side_2 || 'Side B');
		} else {
			document.getElementById('m-conf-sides').innerText = '';
		}

		const modal = document.getElementById('tw-world-pop');
		if (modal) {
			modal.hidden = false;
			modal.style.display = 'block';
		}
	}

	function closeWorldModal() {
		const modal = document.getElementById('tw-world-pop');
		if (modal) {
			modal.hidden = true;
			modal.style.display = 'none';
		}
	}

	async function twDeleteWorld(worldId) {
		const cfg = getConfig();

		if (!window.confirm(cfg.deleteConfirm || 'Delete world?')) {
			return;
		}

		if (!window.twSupabase) {
			window.alert(cfg.supabaseOffline || 'SUPABASE CLIENT OFFLINE.');
			return;
		}

		const client = window.twSupabase;
		const btnCard = document.getElementById('tw-world-card-' + worldId);

		if (btnCard) {
			btnCard.style.pointerEvents = 'none';

			const overlay = document.createElement('div');
			overlay.className = 'tw-delete-overlay';
			overlay.innerHTML =
				'<div class="tw-delete-spinner"></div><div class="tw-delete-label">' +
				(cfg.erasingLabel || 'ERASING…') +
				'</div>';

			btnCard.appendChild(overlay);
		}

		try {
			const { error } = await client.rpc('fn_delete_world', { p_world_id: worldId });

			if (error) {
				console.error('SUPABASE RPC WORLD DELETE ERROR', error);
				window.alert((cfg.deleteFailed || 'Deletion failed:') + ' ' + (error.message || 'Grid denied execution.'));

				if (btnCard) {
					btnCard.style.pointerEvents = '';
					const ov = btnCard.querySelector('.tw-delete-overlay');
					if (ov) {
						ov.remove();
					}
				}
				return;
			}

			if (btnCard) {
				const ov = btnCard.querySelector('.tw-delete-overlay');
				if (ov) {
					const label = ov.querySelector('.tw-delete-label');
					if (label) {
						label.innerText = cfg.erasedLabel || 'NODE ERASED';
					}
				}

				btnCard.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
				btnCard.style.opacity = '0';
				btnCard.style.transform = 'scale(0.92)';
			}

			window.setTimeout(function () {
				window.location.reload();
			}, Number(cfg.reloadDelayMs || 700));
		} catch (e) {
			console.error('WORLD DELETE EXCEPTION', e);
			window.alert(cfg.deleteException || 'Deletion failed: client exception.');

			if (btnCard) {
				btnCard.style.pointerEvents = '';
				const ov = btnCard.querySelector('.tw-delete-overlay');
				if (ov) {
					ov.remove();
				}
			}
		}
	}

	function bindInitBannerRefresh() {
		const banner = document.querySelector('[data-tw-init-banner="1"]');
		if (!banner) {
			return;
		}

		const cfg = getConfig();
		window.setTimeout(function () {
			const url = new URL(window.location.href);
			url.searchParams.delete('status');
			url.searchParams.delete('world_id');
			window.location.href = url.toString();
		}, Number(cfg.refreshDelayMs || 20000));
	}

	function bindWorldCards() {
		document.querySelectorAll('[data-world-card="1"]').forEach(function (card) {
			if (card.dataset.twBound === '1') {
				return;
			}

			card.addEventListener('click', function () {
				const raw = card.dataset.worldModal || '{}';
				let data = {};

				try {
					data = JSON.parse(raw);
				} catch (e) {
					console.error('World modal payload parse error', e);
				}

				openWorldModal(data);
			});

			card.dataset.twBound = '1';
		});
	}

	function bindWorldActions() {
		document.querySelectorAll('[data-world-action]').forEach(function (btn) {
			if (btn.dataset.twBound === '1') {
				return;
			}

			btn.addEventListener('click', function (event) {
				event.preventDefault();
				event.stopPropagation();

				const action = btn.dataset.worldAction || '';
				const worldId = btn.dataset.worldId || '';
				const campaignId = btn.dataset.campaignId || '';

				if (action === 'enter' && worldId) {
					window.location.href = '/game/?world_id=' + encodeURIComponent(worldId);
					return;
				}

				if (action === 'assign-agent' && worldId && campaignId) {
					window.location.href =
						'/agents/?world_id=' +
						encodeURIComponent(worldId) +
						'&campaign_id=' +
						encodeURIComponent(campaignId);
					return;
				}

				if (action === 'bind-campaign') {
					document.getElementById('tw-deployment-root')?.scrollIntoView({
						behavior: 'smooth',
						block: 'start'
					});
					return;
				}

				if (action === 'delete' && worldId) {
					twDeleteWorld(worldId);
				}
			});

			btn.dataset.twBound = '1';
		});
	}

	function bindModalControls() {
		const modal = document.getElementById('tw-world-pop');
		const modalBox = modal?.querySelector('[data-world-modal-box="1"]');

		document.querySelectorAll('[data-world-modal-close="1"]').forEach(function (btn) {
			if (btn.dataset.twBound === '1') {
				return;
			}

			btn.addEventListener('click', function () {
				closeWorldModal();
			});

			btn.dataset.twBound = '1';
		});

		if (modal && !modal.dataset.twBound) {
			modal.addEventListener('click', function () {
				closeWorldModal();
			});
			modal.dataset.twBound = '1';
		}

		if (modalBox && !modalBox.dataset.twBound) {
			modalBox.addEventListener('click', function (event) {
				event.stopPropagation();
			});
			modalBox.dataset.twBound = '1';
		}

		if (!document.body.dataset.twWorldEscBound) {
			document.addEventListener('keydown', function (event) {
				if (event.key === 'Escape') {
					closeWorldModal();
				}
			});
			document.body.dataset.twWorldEscBound = '1';
		}
	}

	window.openWorldModal = openWorldModal;
	window.closeWorldModal = closeWorldModal;
	window.twDeleteWorld = twDeleteWorld;

	document.addEventListener('DOMContentLoaded', function () {
		bindInitBannerRefresh();
		bindWorldCards();
		bindWorldActions();
		bindModalControls();
	});
})();
