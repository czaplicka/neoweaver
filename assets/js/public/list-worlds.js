function openWorldModal(data) {
		document.getElementById('m-name').innerText       = data.name || '';
		document.getElementById('m-campaign').innerText   = data.campaign || '';
		document.getElementById('m-desc').innerText       = data.desc || '';
		document.getElementById('m-magic').innerText      = data.magic || '';
		document.getElementById('m-tech').innerText       = data.tech || '';
		document.getElementById('m-vibe').innerText       = data.vibe || '';
		document.getElementById('m-wealth').innerText     = data.wealth || '';
		document.getElementById('m-size').innerText       = data.size || '';
		document.getElementById('m-diff').innerText       = data.diff || '';
		document.getElementById('m-gods').innerText       = data.gods || '';
		document.getElementById('m-relations').innerText  = data.relations || '';
		document.getElementById('m-tag1').innerText       = data.tag1 || '';
		document.getElementById('m-tag2').innerText       = data.tag2 || '';
		document.getElementById('m-tag3').innerText       = data.tag3 || '';
		document.getElementById('m-conf-title').innerText   = data.conf_title || '';
		document.getElementById('m-conf-summary').innerText = data.conf_summary || '';

		if (data.conf_side_1 || data.conf_side_2) {
			document.getElementById('m-conf-sides').innerText =
				(data.conf_side_1 || 'Side A') + ' vs ' + (data.conf_side_2 || 'Side B');
		} else {
			document.getElementById('m-conf-sides').innerText = '';
		}

		document.getElementById('tw-world-pop').style.display = 'block';
	}

	function closeWorldModal() {
		document.getElementById('tw-world-pop').style.display = 'none';
	}

	function twDeleteWorld(worldId) {
		if (!confirm('This will erase the world from the grid (and all linked data via cascade). Proceed?')) {
			return;
		}

		if (!window.twSupabase) {
			alert('SUPABASE CLIENT OFFLINE. CANNOT ERASE WORLD.');
			return;
		}

		const client  = window.twSupabase;
		const btnCard = document.getElementById('tw-world-card-' + worldId);

		// Dodaj spinner overlay na karcie
		if (btnCard) {
			btnCard.style.pointerEvents = 'none';
			const overlay = document.createElement('div');
			overlay.className = 'tw-delete-overlay';
			overlay.innerHTML = '<div class="tw-delete-spinner"></div><div class="tw-delete-label">ERASING…</div>';
			btnCard.appendChild(overlay);
		}

		(async () => {
			try {
				const { data, error } = await client.rpc('fn_delete_world', { p_world_id: worldId });

				if (error) {
					console.error('SUPABASE RPC WORLD DELETE ERROR', error);
					alert('Deletion failed: ' + (error.message || 'Grid denied execution.'));
					// Przywróć kartę
					if (btnCard) {
						btnCard.style.pointerEvents = '';
						const ov = btnCard.querySelector('.tw-delete-overlay');
						if (ov) ov.remove();
					}
					return;
				}

				// Sukces — animacja znikania i reload
				if (btnCard) {
					const ov = btnCard.querySelector('.tw-delete-overlay');
					if (ov) ov.querySelector('.tw-delete-label').innerText = 'NODE ERASED';
					btnCard.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
					btnCard.style.opacity   = '0';
					btnCard.style.transform = 'scale(0.92)';
				}
				setTimeout(() => window.location.reload(), 700);

			} catch (e) {
				console.error('WORLD DELETE EXCEPTION', e);
				alert('Deletion failed: client exception.');
				if (btnCard) {
					btnCard.style.pointerEvents = '';
					const ov = btnCard.querySelector('.tw-delete-overlay');
					if (ov) ov.remove();
				}
			}
		})();
	}
