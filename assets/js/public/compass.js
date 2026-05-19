/**
 * Logika Kompasu - Tale Weaver
 */
(function () {
	let compassLoaded = false;
	let refreshInProgress = false;

	function onCompassReady() {
		if (compassLoaded) return;
		compassLoaded = true;

		console.log('🧭 Compass ready, refreshing...');
		refreshCompass();
	}

	async function refreshCompass() {
		if (refreshInProgress) return;
		refreshInProgress = true;

		const client = window.twSupabase || null;
		let locationId = Number(window.twCompassData?.activeLocationId || 0);

		const currentLabel = document.getElementById('tw-current-loc-name');

		if (!client) {
			if (currentLabel) {
				currentLabel.innerText = 'Supabase offline';
			}
			console.warn('Compass: Missing Supabase client');
			refreshInProgress = false;
			return;
		}

		if (!locationId) {
			const wpUserId = Number(window.twCompassData?.wpUserId || 0);

			if (!wpUserId) {
				if (currentLabel) {
					currentLabel.innerText = 'Awaiting sync...';
				}
				console.warn('Compass: Missing WP user ID');
				refreshInProgress = false;
				return;
			}

			const { data: sessionRows, error: sessionError } = await client
				.from('v_cyber_map_view')
				.select('current_location_id')
				.eq('wp_user_id', wpUserId)
				.limit(1);

			if (sessionError || !Array.isArray(sessionRows) || !sessionRows.length) {
				if (currentLabel) {
					currentLabel.innerText = 'Awaiting sync...';
				}
				console.warn('Compass: Missing location ID', sessionError);
				refreshInProgress = false;
				return;
			}

			locationId = Number(sessionRows[0]?.current_location_id || 0);
			window.twCompassData = window.twCompassData || {};
			window.twCompassData.activeLocationId = locationId;
		}

		if (!locationId) {
			if (currentLabel) {
				currentLabel.innerText = 'Awaiting sync...';
			}
			console.warn('Compass: Missing location ID');
			refreshInProgress = false;
			return;
		}

		try {
			const { data: node, error: nError } = await client
				.from('v_cyber_world_nodes')
				.select('id, location_name, n_id, e_id, s_id, w_id')
				.eq('id', locationId)
				.limit(1)
				.single();

			if (nError || !node) {
				console.error('Compass: Failed to fetch node', nError);
				if (currentLabel) {
					currentLabel.innerText = 'Unknown Zone';
				}
				refreshInProgress = false;
				return;
			}

			if (currentLabel) {
				currentLabel.innerText = node.location_name || 'Unknown Zone';
			}

			const neighborIds = [node.n_id, node.e_id, node.s_id, node.w_id].filter(function (id) {
				return !!id;
			});

			const neighborMap = {};

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
					label.innerText = name || 'Block';
				}
			});
		} catch (err) {
			console.error('Compass Error:', err);
		} finally {
			refreshInProgress = false;
		}
	}

	window.twRefreshCompass = refreshCompass;

	document.addEventListener('twGameStateHydrated', refreshCompass);
	document.addEventListener('twLocationChanged', function (event) {
		const newLocationId = Number(event?.detail?.locationId || 0);
		if (newLocationId) {
			window.twCompassData = window.twCompassData || {};
			window.twCompassData.activeLocationId = newLocationId;
		}
		refreshCompass();
	});

	if (document.readyState === 'loading') {
		document.addEventListener(
			'DOMContentLoaded',
			function () {
				setTimeout(onCompassReady, 300);
			},
			{ once: true }
		);
	} else {
		setTimeout(onCompassReady, 300);
	}
})();
