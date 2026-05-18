(function () {
	const instances = new Map();

	const categories = {
		brutality: ['Attack', 'Fire', 'Melee', 'Physical', 'Lethal', 'Grit', 'Determination'],
		cunning: ['Stealth', 'Reflex', 'Glitch', 'Escape', 'Thievery', 'Ambush'],
		intellect: ['Technology', 'Hacking', 'EMP', 'Logic', 'Analysis', 'Crafting'],
		spirit: ['Magic', 'Chaos', 'Willpower', 'Madness', 'Void', 'Active'],
		presence: ['Persuasion', 'Diplomacy', 'Intimidation', 'Social', 'Fame']
	};

	const colors = {
		brutality: '#ff4444',
		cunning: '#d946ef',
		intellect: '#00d2ff',
		spirit: '#8b5cf6',
		presence: '#adff00'
	};

	const titles = {
		brutality: 'JUGGERNAUT',
		cunning: 'GHOST',
		intellect: 'ARCHITECT',
		spirit: 'CONDUIT',
		presence: 'ICON'
	};

	function getRootData(container) {
		if (!container) {
			return null;
		}

		return {
			uid: container.dataset.loomUid || '',
			fallbackCharId: container.dataset.characterId || ''
		};
	}

	function ensureInstance(container) {
		const rootData = getRootData(container);
		if (!rootData || !rootData.uid) {
			return null;
		}

		if (!instances.has(rootData.uid)) {
			instances.set(rootData.uid, {
				uid: rootData.uid,
				container: container,
				chart: null,
				bound: false,
				lastArchetype: null,
				refreshInFlight: false
			});
		}

		const instance = instances.get(rootData.uid);
		instance.container = container;

		return instance;
	}

	function getCharacterId(container) {
		const fallbackCharId = container?.dataset?.characterId || '';
		const stateCharId = window.twGameState?.currentCharacterId || '';

		return stateCharId || fallbackCharId || '';
	}

	async function fetchDeckData(client, charId) {
		const result = await client
			.from('cyber_character_deck')
			.select('card_id, cyber_deck(tags)')
			.eq('character_id', charId);

		return result;
	}

	function computeStats(deckData) {
		const stats = {
			brutality: 0,
			cunning: 0,
			intellect: 0,
			spirit: 0,
			presence: 0
		};

		if (!Array.isArray(deckData) || !deckData.length) {
			return stats;
		}

		deckData.forEach(function (entry) {
			const rawTags = entry?.cyber_deck?.tags || '';
			const cleanTags = String(rawTags).replace(/#/g, '').toLowerCase();
			const tagList = cleanTags.split(/[\s,]+/).filter(Boolean);

			Object.keys(categories).forEach(function (category) {
				categories[category].forEach(function (keyword) {
					const key = keyword.toLowerCase();

					if (tagList.some(function (tag) { return tag === key; })) {
						stats[category]++;
					}
				});
			});
		});

		return stats;
	}

	function setLabel(container, uid, id, value) {
		const el = container.querySelector('#' + id + '-' + uid + ' span');
		if (el) {
			el.innerText = value;
		}
	}

	function renderArchetype(container, stats, hasData) {
		const sorted = Object.entries(stats).sort(function (a, b) {
			return b[1] - a[1];
		});

		const nameEl = container.querySelector('[id^="archetype-name-"]');
		if (!nameEl) {
			return 'DEFAULT';
		}

		if (hasData && sorted[0] && sorted[0][1] > 0) {
			const winningKey = sorted[0][0];
			const activeArchetype = titles[winningKey] || 'DEFAULT';

			nameEl.innerText = activeArchetype;
			nameEl.style.color = colors[winningKey];
			nameEl.style.textShadow = `0 0 16px ${colors[winningKey]}`;

			return activeArchetype;
		}

		nameEl.innerText = 'VOID SOUL';
		nameEl.style.color = '#94a3b8';
		nameEl.style.textShadow = 'none';

		return 'DEFAULT';
	}

	function renderChart(instance, stats, hasData) {
		const container = instance.container;
		const uid = instance.uid;
		const canvas = container.querySelector('#fateChart-' + uid);

		if (!canvas || typeof window.Chart === 'undefined') {
			return;
		}

		const ctx = canvas.getContext('2d');
		if (!ctx) {
			return;
		}

		if (instance.chart) {
			instance.chart.destroy();
			instance.chart = null;
		}

		instance.chart = new window.Chart(ctx, {
			type: 'radar',
			data: {
				labels: ['', '', '', '', ''],
				datasets: [
					{
						data: [
							stats.brutality,
							stats.cunning,
							stats.intellect,
							stats.spirit,
							stats.presence
						],
						backgroundColor: 'rgba(0, 210, 255, 0.12)',
						borderColor: 'rgba(0, 210, 255, 0.9)',
						borderWidth: 2,
						pointBackgroundColor: Object.values(colors),
						pointBorderColor: 'rgba(255,255,255,0.9)',
						pointBorderWidth: 1.5,
						pointRadius: 4,
						pointHoverRadius: 6
					}
				]
			},
			options: {
				responsive: true,
				maintainAspectRatio: true,
				aspectRatio: 1,
				layout: { padding: 10 },
				scales: {
					r: {
						min: 0,
						max: Math.min(Math.max.apply(null, Object.values(stats).concat([3])), 50),
						ticks: { display: false },
						angleLines: { color: 'rgba(0, 210, 255, 0.25)', lineWidth: 1 },
						grid: { color: 'rgba(0, 210, 255, 0.15)', lineWidth: 1 },
						pointLabels: { display: false }
					}
				},
				plugins: {
					legend: { display: false },
					tooltip: { enabled: false }
				},
				animation: { duration: 1000, easing: 'easeOutQuart' }
			}
		});

		setLabel(container, uid, 'label-brutality', stats.brutality);
		setLabel(container, uid, 'label-cunning', stats.cunning);
		setLabel(container, uid, 'label-intellect', stats.intellect);
		setLabel(container, uid, 'label-spirit', stats.spirit);
		setLabel(container, uid, 'label-presence', stats.presence);

		const activeArchetype = renderArchetype(container, stats, hasData);
		const prevArchetype = window.twGameState?.currentArchetype;

		window.twGameState = window.twGameState || {};
		window.twGameState.currentArchetype = activeArchetype;

		if (prevArchetype !== activeArchetype && typeof window.twLoadQuickActions === 'function') {
			window.twLoadQuickActions();
		}

		console.log('Loom [' + uid + ']: Archetype set to', activeArchetype);
	}

	async function initLoomInstance(instance) {
		if (!instance || instance.refreshInFlight) {
			return;
		}

		instance.refreshInFlight = true;

		try {
			const client = window.twSupabase;
			window.twGameState = window.twGameState || {};

			const charId = getCharacterId(instance.container);

			if (!client || !charId) {
				console.log('Loom [' + instance.uid + ']: Waiting for data/charId...');
				return;
			}

			const { data: deckData, error } = await fetchDeckData(client, charId);

			if (error) {
				console.error('Loom [' + instance.uid + '] Error:', error);
				return;
			}

			const stats = computeStats(deckData);
			const hasData = Object.values(stats).some(function (v) { return v > 0; });

			renderChart(instance, stats, hasData);
		} finally {
			instance.refreshInFlight = false;
		}
	}

	function initAllLooms() {
		document.querySelectorAll('[data-loom-root="1"]').forEach(function (container) {
			const instance = ensureInstance(container);
			if (!instance) {
				return;
			}

			initLoomInstance(instance);
		});
	}

	function bindGlobalEventsOnce() {
		if (window.__twLoomBound) {
			return;
		}

		window.__twLoomBound = true;

		document.addEventListener('twTagsUpdated', function () {
			console.log('Loom: twTagsUpdated received, refreshing all charts...');
			initAllLooms();
		});

		if (window.twGameReady) {
			initAllLooms();
		} else {
			document.addEventListener('twGameStateHydrated', initAllLooms, { once: true });
		}
	}

	window.twInitLoomOfFate = initAllLooms;

	document.addEventListener('DOMContentLoaded', function () {
		bindGlobalEventsOnce();
		initAllLooms();
	});
})();
