(function () {
	'use strict';

	function initVitalisTiles(scope) {
		var root = scope || document;
		var tiles = root.querySelectorAll('.tw-vitalis-tile[data-percent]');

		if (!tiles.length) {
			return;
		}

		tiles.forEach(function (tile) {
			var percent = parseFloat(tile.getAttribute('data-percent') || '0');
			if (isNaN(percent)) {
				percent = 0;
			}

			percent = Math.max(0, Math.min(100, percent));

			var ring = tile.querySelector('.tw-vitalis-ring-fg');
			if (ring) {
				var offset = 100 - percent;
				ring.style.strokeDashoffset = String(offset);
				ring.style.stroke = 'currentColor';
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initVitalisTiles(document);
		});
	} else {
		initVitalisTiles(document);
	}
})();
