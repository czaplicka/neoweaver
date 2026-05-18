(function () {
	function updateQuickActionsFromHand(cards) {
		var tags = (cards || []).flatMap(function (c) {
			return (c.tags || '')
				.split(',')
				.map(function (t) { return t.trim(); })
				.filter(Boolean);
		});

		if (typeof window.twUpdatePlayerTags === 'function') {
			window.twUpdatePlayerTags(tags);
		} else if (typeof console !== 'undefined') {
			console.warn('twUpdatePlayerTags is not defined – quick actions bridge has nothing to call.');
		}
	}

	function showTagUpdate(tagName, isSuccess) {
		isSuccess = (isSuccess !== false);

		var popup = document.createElement('div');
		popup.className = 'tag-update-popup' + (isSuccess ? '' : ' failure');

		var labelSpan = document.createElement('span');
		labelSpan.className = 'tag-label';
		labelSpan.textContent = '// DATA SYNC: NEW ECHO TAG ACQUIRED';

		var nameSpan = document.createElement('span');
		nameSpan.className = 'tag-name';
		nameSpan.textContent = tagName;

		popup.appendChild(labelSpan);
		popup.appendChild(nameSpan);
		document.body.appendChild(popup);

		if (window.jQuery) {
			jQuery(popup).fadeIn(300).delay(3000).fadeOut(500, function () {
				this.remove();
			});
		} else {
			popup.style.opacity = '1';
			setTimeout(function () {
				popup.style.transition = 'opacity 0.5s';
				popup.style.opacity = '0';
				setTimeout(function () {
					popup.remove();
				}, 500);
			}, 3000);
		}
	}

	window.twQuickActionsBridge = {
		updateFromCards: updateQuickActionsFromHand
	};

	window.showTagUpdate = showTagUpdate;
})();
