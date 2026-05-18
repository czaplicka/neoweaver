(function () {
	let shakeInterval = null;
	let glitchInterval = null;

	function ensureAdventureData() {
		window.twAdventureData = window.twAdventureData || {};

		if (window.twCharacterCardData?.supabaseUrl) {
			window.twAdventureData.supabase_url = window.twCharacterCardData.supabaseUrl;
		}

		if (typeof window.twCharacterCardData?.activeCharacterId !== 'undefined') {
			window.twAdventureData.active_character_id = window.twCharacterCardData.activeCharacterId;
		}
	}

	function clearGlitchIntervals() {
		if (shakeInterval !== null) {
			clearInterval(shakeInterval);
			shakeInterval = null;
		}

		if (glitchInterval !== null) {
			clearInterval(glitchInterval);
			glitchInterval = null;
		}
	}

	function resetPanelEffects(charPanel) {
		if (!charPanel) {
			return;
		}

		charPanel.style.transform = '';
		charPanel.style.filter = '';
		charPanel.classList.remove('glitch-active');
	}

	function startLightShake(charPanel) {
		shakeInterval = window.setInterval(function () {
			if (Math.random() > 0.95) {
				charPanel.style.transform =
					'translate(' +
					(Math.random() * 2 - 1).toFixed(2) +
					'px,' +
					(Math.random() * 2 - 1).toFixed(2) +
					'px)';

				window.setTimeout(function () {
					charPanel.style.transform = '';
				}, 50);
			}
		}, 100);
	}

	function startHardGlitch(charPanel) {
		glitchInterval = window.setInterval(function () {
			if (Math.random() > 0.8) {
				const x = (Math.random() * 6 - 3).toFixed(2);
				const y = (Math.random() * 6 - 3).toFixed(2);
				const skew = (Math.random() * 2 - 1).toFixed(2);

				charPanel.style.transform = 'translate(' + x + 'px,' + y + 'px) skewX(' + skew + 'deg)';
				charPanel.style.filter =
					'hue-rotate(' + Math.round(Math.random() * 90) + 'deg) contrast(1.5)';

				window.setTimeout(function () {
					charPanel.style.transform = '';
					charPanel.style.filter = '';
				}, 70);
			}
		}, 150);
	}

	function applyGlitchEffects(charPanel, value) {
		clearGlitchIntervals();
		resetPanelEffects(charPanel);

		if (value <= 20) {
			charPanel.classList.add('glitch-active');
			startHardGlitch(charPanel);
			return;
		}

		if (value <= 50) {
			charPanel.style.filter = 'contrast(1.2) brightness(1.1) sepia(0.2)';
			startLightShake(charPanel);
		}
	}

	function initCharacterCardEffects() {
		ensureAdventureData();

		const charPanel = document.getElementById('charPanel');
		if (!charPanel) {
			clearGlitchIntervals();
			return;
		}

		const syncFill = charPanel.querySelector(
			'.tw-progress-fill.sync-stable, .tw-progress-fill.sync-warning, .tw-progress-fill.sync-critical'
		);

		if (!syncFill) {
			clearGlitchIntervals();
			resetPanelEffects(charPanel);
			return;
		}

		const syncValue = parseFloat(syncFill.style.width);
		if (Number.isNaN(syncValue)) {
			clearGlitchIntervals();
			resetPanelEffects(charPanel);
			return;
		}

		applyGlitchEffects(charPanel, syncValue);
	}

	window.twInitCharacterCard = initCharacterCardEffects;
	window.twDestroyCharacterCardEffects = clearGlitchIntervals;

	document.addEventListener('DOMContentLoaded', initCharacterCardEffects);

	document.addEventListener('visibilitychange', function () {
		if (document.hidden) {
			clearGlitchIntervals();
			return;
		}

		initCharacterCardEffects();
	});

	window.addEventListener('beforeunload', clearGlitchIntervals);
})();
