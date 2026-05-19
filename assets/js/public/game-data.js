(function () {
	const config = window.twAdventureData || null;

	if (!config) {
		return;
	}

	if (window.supabase && !window.twSupabase) {
		try {
			window.twSupabase = window.supabase.createClient(
				config.supabase_url,
				config.supabase_anon_key
			);
		} catch (error) {
			console.error('TW Supabase init failed:', error);
		}
	}

	console.log('🔗 twAdventureData injected:', config);
})();
