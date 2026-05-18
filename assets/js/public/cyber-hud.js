(function () {
	const colorMap = {
		rep_local: '#0055ff',
		rep_world: '#6699ff',
		danger: '#ff0033',
		stealth: '#00f2ff',
		order: '#ffd700',
		rep_tech_nature: '#adff00',
		rep_chaos_order: '#cc00ff',
		rep_gold_thief: '#ff8800'
	};

	const ALERT_PRIORITY = ['#ff0033', '#ff8800', '#ffd700', '#cc00ff', '#adff00', '#0055ff', '#6699ff'];

	function toggleHud() {
		const wrapper = document.getElementById('hud-wrapper');
		const triggerText = document.getElementById('hud-trigger-text');

		if (!wrapper || !triggerText) {
			return;
		}

		wrapper.classList.toggle('is-open');
		triggerText.innerText = wrapper.classList.contains('is-open')
			? '\u00d7 DISCONNECT_STREAMS'
			: '\u203a SYSTEM_ACTIVE';
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	async function supaFetch(path) {
		const supaUrl = window.twCyberHud?.supabaseUrl || '';
		const supaKey = window.twCyberHud?.supabaseKey || '';

		if (!supaUrl || !supaKey) {
			console.error('HUD config missing');
			return [];
		}

		const res = await fetch(supaUrl + path, {
			headers: {
				apikey: supaKey,
				Authorization: 'Bearer ' + supaKey
			}
		});

		if (!res.ok) {
			console.error('HUD supaFetch HTTP', res.status, path);
			return [];
		}

		return res.json();
	}

	function updateRow(id, val, tagsStr, isBipolar, activeAlerts) {
		const bar = document.getElementById(`b-${id}`);
		const num = document.getElementById(`v-${id}`);
		const tagBox = document.getElementById(`t-${id}`);
		const dot = document.getElementById(`dot-${id}`);
		const value = parseFloat(val || 0);

		if (bar) {
			if (isBipolar) {
				const width = Math.min(100, Math.abs(value)) / 2;
				bar.style.width = width + '%';
				bar.style.left = value >= 0 ? '50%' : (50 - width) + '%';

				if (id === 'stealth') {
					if (value < 0) {
						bar.style.backgroundColor = value <= -80 ? '#ff8800' : '#ff3300';
						if (value <= -80) {
							activeAlerts.push('#ff8800');
						}
					} else {
						bar.style.backgroundColor = '#00f2ff';
					}
				} else {
					bar.style.backgroundColor = value >= 0 ? colorMap[id] : '#ff3300';
				}
			} else {
				const clamped = Math.min(100, Math.max(0, value));
				bar.style.width = clamped + '%';
				bar.style.left = '0';
				bar.style.backgroundColor = colorMap[id] || '#ffffff';

				if (id === 'danger' && clamped >= 80) {
					activeAlerts.push('#ff0033');
				} else if (clamped >= 80 && colorMap[id]) {
					activeAlerts.push(colorMap[id]);
				}
			}
		}

		if (dot) {
			dot.style.opacity = Math.max(0.1, Math.abs(value) / 100);
			dot.classList.toggle('dot-pulse', Math.abs(value) >= 80);
		}

		if (num) {
			num.innerText = Math.abs(Math.round(value));
		}

		if (tagBox) {
			const tags = String(tagsStr || '')
				.split(',')
				.map(function (t) { return t.trim(); })
				.filter(function (t) {
					return t && !['neutral', 'balance', 'balanced'].includes(t.toLowerCase());
				});

			tagBox.innerHTML = tags.slice(-3).map(function (t) {
				const safe = escapeHtml(t);
				return `<span class="tag-item" style="border-left-color:${colorMap[id] || '#fff'}">#${safe}</span>`;
			}).join('');
		}
	}

	async function updateHUD() {
		try {
			const currentUserId = window.twCyberHud?.currentUserId || 0;
			if (!currentUserId) {
				return;
			}

			const sessArr = await supaFetch(
				`/cyber_game_sessions?wp_user_id=eq.${currentUserId}&order=created_at.desc&limit=1`
			);

			if (!sessArr?.length) {
				return;
			}

			const session = sessArr[0];
			const worldId = session.world_id;
			const locationId = session.location_id;
			const characterId = session.character_id;
			const activeAlerts = [];

			const safeCharId =
				characterId &&
				typeof characterId === 'string' &&
				characterId.trim() !== '' &&
				characterId !== 'null';

			const [worldStatsArr, locStatsArr, repArr] = await Promise.all([
				supaFetch(`/cyber_world_hud_stats?world_id=eq.${worldId}`),
				supaFetch(`/cyber_location_hud_stats?world_id=eq.${worldId}&location_id=eq.${locationId}`),
				safeCharId
					? supaFetch(`/cyber_reputation?character_id=eq.${characterId}&order=updated_at.desc&limit=1`)
					: Promise.resolve([])
			]);

			const worldStats = worldStatsArr[0] || null;
			const locStats = locStatsArr[0] || null;
			const rep = repArr[0] || null;

			if (typeof locStats?.political_val !== 'undefined') {
				updateRow('rep_local', locStats.political_val, locStats.political_tags, false, activeAlerts);
			}

			if (worldStats) {
				updateRow('rep_world', worldStats.political_val, worldStats.political_tags, false, activeAlerts);
			}

			if (typeof locStats?.danger_val !== 'undefined') {
				updateRow('danger', locStats.danger_val, locStats.danger_tags, false, activeAlerts);
			} else if (worldStats) {
				updateRow('danger', worldStats.danger_val, worldStats.danger_tags, false, activeAlerts);
			}

			if (typeof locStats?.stealth_val !== 'undefined') {
				updateRow('stealth', locStats.stealth_val, locStats.stealth_tags, true, activeAlerts);
			}

			if (typeof locStats?.order_val !== 'undefined') {
				updateRow('order', locStats.order_val, locStats.order_tags, true, activeAlerts);
			}

			if (rep) {
				updateRow('rep_tech_nature', rep.tech_vs_nature, null, true, activeAlerts);
				updateRow('rep_chaos_order', rep.chaos_vs_order, null, true, activeAlerts);
				updateRow('rep_gold_thief', rep.gold_vs_thief, null, true, activeAlerts);
			}

			const topAlert = ALERT_PRIORITY.find(function (c) {
				return activeAlerts.includes(c);
			}) || null;

			const globalAlert = document.getElementById('hud-global-alert');
			if (globalAlert) {
				if (topAlert) {
					document.body.classList.add('global-glitch-active');
					globalAlert.style.setProperty('--alert-c', topAlert);
					globalAlert.classList.add('is-visible');
				} else {
					document.body.classList.remove('global-glitch-active');
					globalAlert.classList.remove('is-visible');
				}
			}
		} catch (e) {
			console.error('HUD update error:', e);
		}
	}

	function bindHudToggles() {
		document.querySelectorAll('[data-hud-toggle="1"]').forEach(function (el) {
			if (el.dataset.twBound === '1') {
				return;
			}

			el.addEventListener('click', toggleHud);
			el.dataset.twBound = '1';
		});
	}

	window.toggleHud = toggleHud;
	window.updateCyberHud = updateHUD;

	document.addEventListener('DOMContentLoaded', function () {
		bindHudToggles();
		updateHUD();
	});

	document.addEventListener('twGameStateHydrated', updateHUD);
	setInterval(updateHUD, 5000);
})();
