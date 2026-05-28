(function() {
		document.querySelectorAll('.nw-asc-btn:not([disabled])').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var wrap   = btn.closest('[data-character]');
				var charId = wrap ? wrap.dataset.character : '';
				var deckId = btn.dataset.deckId;
				var nonce  = btn.dataset.nonce;
				if (!charId || !deckId) return;

				btn.disabled = true;
				btn.textContent = 'Processing…';

				var fd = new FormData();
				fd.append('action',       'nw_ascend_card');
				fd.append('nonce',        nonce);
				fd.append('character_id', charId);
				fd.append('deck_id',      deckId);

				fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method: 'POST',
					body: fd
				})
				.then(function(r) { return r.json(); })
				.then(function(data) {
					if (data.success) {
						// Animate and reload
						var card = btn.closest('.nw-asc-card');
						if (card) { card.classList.add('ascending'); }
						setTimeout(function() { location.reload(); }, 900);
					} else {
						alert(data.data && data.data.message ? data.data.message : 'Ascension failed.');
						btn.disabled = false;
						btn.textContent = 'Ascend';
					}
				})
				.catch(function() {
					alert('Network error.');
					btn.disabled = false;
				});
			});
		});

		// Init Lucide icons if available
		if (typeof lucide !== 'undefined') { lucide.createIcons(); }
	})();
