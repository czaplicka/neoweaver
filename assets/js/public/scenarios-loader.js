(function () {
	function safeText(value, fallback = '') {
		if (typeof value === 'string') {
			const trimmed = value.trim();
			return trimmed !== '' ? trimmed : fallback;
		}

		if (value === null || typeof value === 'undefined') {
			return fallback;
		}

		return String(value);
	}

	function safeUrl(value, fallback = '') {
		if (typeof value !== 'string') {
			return fallback;
		}

		const trimmed = value.trim();
		if (!trimmed) {
			return fallback;
		}

		try {
			const url = new URL(trimmed, window.location.origin);
			if (url.protocol === 'http:' || url.protocol === 'https:') {
				return url.href;
			}
		} catch (e) {
			return fallback;
		}

		return fallback;
	}

	function createTag(text) {
		const span = document.createElement('span');
		span.className = 'scenario-tag';
		span.textContent = '#' + safeText(text, '');
		return span;
	}

	function createScenarioCard(s) {
		let tags = [];

		if (Array.isArray(s.tags)) {
			tags = s.tags
				.map(function (t) {
					return safeText(t, '');
				})
				.filter(Boolean);
		} else if (typeof s.tags === 'string' && s.tags) {
			tags = s.tags
				.split(',')
				.map(function (t) {
					return safeText(t, '');
				})
				.filter(Boolean);
		}

		const card = document.createElement('article');
		card.className = 'deck-card scenario-card';
		card.dataset.scenarioId = safeText(s.id, '');

		const inner = document.createElement('div');
		inner.className = 'deck-card-inner';

		const imgUrl = safeUrl(s.img_url, '');
		if (imgUrl) {
			const imageWrap = document.createElement('div');
			imageWrap.className = 'scenario-image-wrap';

			const img = document.createElement('img');
			img.className = 'scenario-image';
			img.src = imgUrl;
			img.alt = safeText(s.name, 'Scenario image');
			img.loading = 'lazy';

			imageWrap.appendChild(img);
			inner.appendChild(imageWrap);
		}

		const header = document.createElement('header');
		header.className = 'scenario-header';

		const difficulty = document.createElement('span');
		difficulty.className = 'scenario-difficulty';
		difficulty.textContent = safeText(s.difficulty, '');

		const title = document.createElement('h4');
		title.className = 'scenario-title';
		title.textContent = safeText(s.name, 'Untitled mission');

		header.appendChild(difficulty);
		header.appendChild(title);

		const body = document.createElement('div');
		body.className = 'scenario-body';

		const goal = document.createElement('p');
		goal.className = 'scenario-goal';
		goal.textContent = safeText(s.goal, '');

		const tagsWrap = document.createElement('p');
		tagsWrap.className = 'scenario-tags';

		tags.forEach(function (tag) {
			tagsWrap.appendChild(createTag(tag));
		});

		if (s.is_boss) {
			tagsWrap.appendChild(createTag('boss'));
		}

		if (s.is_key_arc) {
			tagsWrap.appendChild(createTag('key_arc'));
		}

		body.appendChild(goal);
		body.appendChild(tagsWrap);

		const footer = document.createElement('footer');
		footer.className = 'scenario-footer';

		const type = document.createElement('span');
		type.className = 'scenario-type';
		type.textContent = safeText(s.type, '');

		const category = document.createElement('span');
		category.className = 'scenario-category';
		category.textContent = safeText(s.category, '');

		footer.appendChild(type);
		footer.appendChild(category);

		inner.appendChild(header);
		inner.appendChild(body);
		inner.appendChild(footer);

		card.appendChild(inner);

		return card;
	}

	async function loadScenarios() {
		const list = document.getElementById('scenarios-list');

		if (!list) {
			console.warn('⚠️ scenarios-list not found in DOM');
			return;
		}

		list.innerHTML = '<p class="empty-msg">Scanning network for missions...</p>';

		try {
			const campaignId =
				window.twGameState?.currentCampaignId ||
				window.twAdventureData?.active_campaign_id;

			console.log('🔍 Scenarios: Resolved campaignId:', campaignId);

			if (!campaignId) {
				list.innerHTML = '<p class="empty-msg">No active campaign detected.</p>';
				return;
			}

			const formData = new URLSearchParams({
				action: 'tw_get_scenarios_ajax',
				nonce: window.twAdventureData?.nonce || '',
				campaign_id: campaignId
			});

			const ajaxUrl = window.twAdventureData?.ajax_url || '/wp-admin/admin-ajax.php';
			const response = await fetch(ajaxUrl, {
				method: 'POST',
				body: formData
			});

			if (!response.ok) {
				throw new Error('AJAX HTTP error ' + response.status);
			}

			const json = await response.json();

			if (!json.success || !Array.isArray(json.data)) {
				list.innerHTML = '<p class="empty-msg">No missions available for this campaign yet.</p>';
				return;
			}

			const scenarios = json.data.slice(0, 3);

			if (!scenarios.length) {
				list.innerHTML = '<p class="empty-msg">No missions available. Ask your GM to sync the campaign.</p>';
				return;
			}

			list.innerHTML = '';

			scenarios.forEach(function (s) {
				list.appendChild(createScenarioCard(s));
			});

			console.log('✅ Loaded', scenarios.length, 'scenario cards');
		} catch (error) {
			console.error('❌ Error loading scenarios:', error);
			list.innerHTML = '<p class="empty-msg">Mission panel offline. Please refresh the terminal.</p>';
		}
	}

	window.twLoadScenarios = loadScenarios;

	if (window.twGameReady) {
		loadScenarios();
	} else {
		document.addEventListener('twGameStateHydrated', loadScenarios);
	}

	console.log('🎮 Tale Weaver Scenarios Loader - Ready & Waiting');
})();
