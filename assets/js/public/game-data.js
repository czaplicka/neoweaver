(function () {
	const config = window.twAdventureData || null;

	if (!config) {
		return;
	}

	if (window.supabase && !window.twSupabase) {
		try {
			const accessToken = config.supabaseToken || null;

			window.twSupabase = window.supabase.createClient(
				config.supabase_url,
				config.supabase_anon_key, // anon key pozostaje jako API key
				{
					global: {
						headers: accessToken
							? { Authorization: 'Bearer ' + accessToken }
							: {},
					},
					auth: {
						// Wyłączamy wbudowany auth Supabase – zarządza nim WordPress
						autoRefreshToken: false,
						persistSession: false,
						detectSessionFromUrl: false,
					},
				}
			);

			if (accessToken) {
				console.log('🔐 twSupabase: authenticated client ready');
			} else {
				console.warn('⚠️ twSupabase: no JWT token, using anon access only');
			}
		} catch (error) {
			console.error('TW Supabase init failed:', error);
		}
	}
})();
