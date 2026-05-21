(function () {
	'use strict';

	function initCharacterEcho() {
		const blocks = document.querySelectorAll('[data-tw-character-echo="1"]');
		if (!blocks.length) return;

		blocks.forEach((block) => {
			block.classList.add('is-ready');
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initCharacterEcho);
	} else {
		initCharacterEcho();
	}
})();
