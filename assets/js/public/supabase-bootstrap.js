(function () {
	'use strict';

	if (window.twSupabase) {
		return;
	}

	if (typeof window.supabase?.createClient !== 'function') {
		return;
	}

	const cfg = window.twSupabaseConfig || {};
	if (!cfg.url || !cfg.key) {
		return;
	}

	window.twSupabase = window.supabase.createClient(cfg.url, cfg.key);
})();
