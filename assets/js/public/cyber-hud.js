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

	let hudRealtimeChannel = null;
	let hudBindingKey = '';
	let hudInitDone = false;
	let hudRefreshPromise = null;

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

	function getHudConfig() {
		return {
			supabaseUrl: window.twCyberHud?.supabaseUrl || '',
			supabaseKey: window.twCyberHud?.supabaseKey || '',
			currentUserId: window.twCyberHud?.currentUserId || 0
		};
	}

	async function supaFetch(path) {
		const cfg = getHudConfig();

		if (!cfg.supabaseUrl || !cfg.supabaseKey) {
			console.error('HUD config missing');
			return [];
		}

		const res = await fetch(cfg.supabaseUrl + path, {
			headers: {
				apikey: cfg.supabaseKey,
				Authorization: 'Bearer ' + cfg.supabaseKey
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

	function applyAlertState(activeAlerts) {
		const topAlert = ALERT_PRIORITY.find(function (c) {
			return activeAlerts.includes(c);
		}) || null;

		const globalAlert = document.getElementById('hud-global-alert');
		if (!globalAlert) {
			return;
		}

		if (topAlert) {
			document.body.classList.add('global-glitch-active');
			globalAlert.style.setProperty('--alert-c', topAlert);
			globalAlert.classList.add('is-visible');
		} else {
			document.body.classList.remove('global-glitch-active');
			globalAlert.classList.remove('is-visible');
		}
	}

	async function fetchHudContext() {
		const cfg = getHudConfig();

		if (!cfg.currentUserId) {
			return null;
		}

		const sessArr = await supaFetch(
			`/cyber_game_sessions?wp_user_id=eq.${cfg.currentUserId}&order=created_at.desc&limit=1`
		);

		if (!sessArr?.length) {
			return null;
		}

		const session = sessArr[0];
		const worldId = session.world_id || null;
		const locationId = session.location_id || null;
		const characterId = session.character_id || null;

		const safeCharId =
			characterId &&
			typeof characterId === 'string' &&
			characterId.trim() !== '' &&
			characterId !== 'null';

		return {
			session,
			worldId,
			locationId,
			characterId,
			safeCharId
		};
	}

	async function updateHUD() {
		try {
			const context = await fetchHudContext();
			if (!context || !context.worldId) {
				return null;
			}

			const activeAlerts = [];

			const [worldStatsArr, locStatsArr, repArr] = await Promise.all([
				supaFetch(`/cyber_world_hud_stats?world_id=eq.${context.worldId}`),
				context.locationId
					? supaFetch(`/cyber_location_stats?world_id=eq.${context.worldId}&location_id=eq.${context.locationId}`)
					: Promise.resolve([]),
				context.safeCharId
					? supaFetch(`/cyber_reputation?character_id=eq.${context.characterId}&order=updated_at.desc&limit=1`)
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

			applyAlertState(activeAlerts);

			return context;
		} catch (e) {
			console.error('HUD update error:', e);
			return null;
		}
	}

	function requestHudRefresh() {
		if (hudRefreshPromise) {
			return hudRefreshPromise;
		}

		hudRefreshPromise = updateHUD().finally(function () {
			hudRefreshPromise = null;
		});

		return hudRefreshPromise;
	}

	function teardownHudRealtime() {
		if (hudRealtimeChannel && window.twSupabase?.removeChannel) {
			window.twSupabase.removeChannel(hudRealtimeChannel);
		}

		hudRealtimeChannel = null;
		hudBindingKey = '';
	}

	function makeBindingKey(context) {
		return [
			context.worldId || '',
			context.locationId || '',
			context.safeCharId ? context.characterId : ''
		].join('|');
	}

	function bindHudRealtimeForContext(context) {
		const supabase = window.twSupabase;

		if (!supabase || !supabase.channel || !context || !context.worldId) {
			return;
		}

		const newKey = makeBindingKey(context);
		if (hudBindingKey === newKey && hudRealtimeChannel) {
			return;
		}

		teardownHudRealtime();

		const channel = supabase.channel(`hud:${newKey}`);

		channel.on(
			'postgres_changes',
			{
				event: '*',
				schema: 'public',
				table: 'cyber_world_hud_stats',
				filter: `world_id=eq.${context.worldId}`
			},
			function () {
				requestHudRefresh();
			}
		);

		if (context.locationId) {
			channel.on(
				'postgres_changes',
				{
					event: '*',
					schema: 'public',
					table: 'cyber_location_hud_stats',
					filter: `location_id=eq.${context.locationId}`
				},
				function () {
					requestHudRefresh();
				}
			);
		}

		if (context.safeCharId) {
			channel.on(
				'postgres_changes',
				{
					event: '*',
					schema: 'public',
					table: 'cyber_reputation',
					filter: `character_id=eq.${context.characterId}`
				},
				function () {
					requestHudRefresh();
				}
			);
		}

		channel.subscribe(function (status) {
			if (status === 'CHANNEL_ERROR' || status === 'CLOSED') {
				console.warn('HUD realtime channel status:', status);
			}
		});

		hudRealtimeChannel = channel;
		hudBindingKey = newKey;
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

	async function initHudRealtimeFlow() {
		const context = await requestHudRefresh();
		if (context) {
			bindHudRealtimeForContext(context);
		}
	}

	function initHud() {
		if (hudInitDone) {
			return;
		}

		hudInitDone = true;
		bindHudToggles();
		initHudRealtimeFlow();
	}

	window.toggleHud = toggleHud;
	window.updateCyberHud = requestHudRefresh;

	document.addEventListener('DOMContentLoaded', function () {
		initHud();
	});

	document.addEventListener('twGameStateHydrated', function () {
		initHudRealtimeFlow();
	});
})();
