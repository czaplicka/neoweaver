/**
 * Logika Kompasu - Tale Weaver
 */
(function () {
	let compassLoaded = false;

	function onCompassReady() {
		if (compassLoaded) return;
		compassLoaded = true;

		console.log('🧭 Game State ready, refreshing compass...');
		refreshCompass();
	}

	async function refreshCompass() {
		const client = window.twSupabase;
		const locationId = window.twGameState?.currentLocationId;

		if (!client || !locationId) {
			const currentLabel = document.getElementById('tw-current-loc-name');
			if (currentLabel) {
				currentLabel.innerText = 'Awaiting sync...';
			}
			console.warn('Compass: Missing Supabase client or location ID');
			return;
		}

		try {
			const { data: node, error: nError } = await client
				.from('v_cyber_world_nodes')
				.select('location_name, n_id, e_id, s_id, w_id')
				.eq('id', locationId)
				.single();

			if (nError || !node) {
				console.error('Compass: Failed to fetch node', nError);
				const currentLabel = document.getElementById('tw-current-loc-name');
				if (currentLabel) {
					currentLabel.innerText = 'Unknown Zone';
				}
				return;
			}

			const currentLabel = document.getElementById('tw-current-loc-name');
			if (currentLabel) {
				currentLabel.innerText = node.location_name;
			}

			const neighborIds = [node.n_id, node.e_id, node.s_id, node.w_id].filter(function (id) {
				return id !== null;
			});

			let neighborMap = {};

			if (neighborIds.length > 0) {
				const { data: names, error: namesError } = await client
					.from('cyber_world_map')
					.select('id, location_name, is_discovered')
					.in('id', neighborIds);

				if (namesError) {
					console.error('Compass: Failed to fetch neighbour names', namesError);
				} else if (Array.isArray(names)) {
					names.forEach(function (n) {
						neighborMap[n.id] = n.is_discovered ? n.location_name : '???';
					});
				}
			}

			const directions = [
				{ key: 'n', id: node.n_id },
				{ key: 'e', id: node.e_id },
				{ key: 's', id: node.s_id },
				{ key: 'w', id: node.w_id }
			];

			directions.forEach(function (dir) {
				const cell = document.querySelector(`.tw-compass-cell[data-dir="${dir.key}"]`);
				if (!cell) return;

				const label = cell.querySelector('.loc-name');
				const name = dir.id ? neighborMap[dir.id] : null;

				cell.classList.toggle('active', !!name && name !== '???');
				cell.classList.toggle('undiscovered', !!name && name === '???');

				if (label) {
					label.innerText = name ?? 'Block';
				}
			});
		} catch (err) {
			console.error('Compass Error:', err);
		}
	}

	window.twRefreshCompass = refreshCompass;

	document.addEventListener('twGameStateHydrated', onCompassReady);

	if (document.readyState === 'loading') {
		document.addEventListener(
			'DOMContentLoaded',
			function () {
				setTimeout(onCompassReady, 1500);
			},
			{ once: true }
		);
	} else {
		setTimeout(onCompassReady, 1500);
	}
})();
