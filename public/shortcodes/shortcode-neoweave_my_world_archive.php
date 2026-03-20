<?php
/**
 * Shortcode: neoweave_my_world_archive
 *
 * Renders a terminal-style archive of the current user's dead characters
 * in a given world, fetched from Supabase.
 *
 * Usage: [neoweave_my_world_archive world="<uuid>"]
 * Falls back to ?world_id= query-string when the attribute is omitted.
 */
function shortcode_neoweave_my_world_archive( $atts ) {

    // -------------------------------------------------------------------------
    // 1. Authentication guard
    // -------------------------------------------------------------------------
    $current_user_id = get_current_user_id();
    if ( ! $current_user_id ) {
        return '[ACCESS_DENIED]: No Operator signature detected.';
    }

    // -------------------------------------------------------------------------
    // 2. Resolve and validate world_id
    //
    // BUG FIX 1 — $_GET['world_id'] used raw without sanitization.
    // Any string from the URL must be passed through sanitize_text_field()
    // (strips tags, encodes special chars) before use.  Without this a crafted
    // URL could inject arbitrary text into the Supabase query string.
    // -------------------------------------------------------------------------
    $atts      = shortcode_atts( [ 'world' => '' ], $atts );
    $world_id  = ! empty( $atts['world'] )
        ? sanitize_text_field( $atts['world'] )
        // BUG FIX 1 (cont.): use sanitize_text_field on $_GET value.
        : sanitize_text_field( $_GET['world_id'] ?? '' );

    if ( ! $world_id ) {
        return '[DATA_ERR]: World Node ID is missing.';
    }

    // BUG FIX 2 — No format validation on world_id before it is interpolated
    // into the Supabase URL.  If world_id is expected to be a UUID (standard
    // for Supabase PKs), reject anything that does not match the pattern.
    // This prevents path-traversal / header-injection attempts even after
    // sanitize_text_field strips obvious HTML.
    if ( ! preg_match( '/^[0-9a-f\-]{1,64}$/i', $world_id ) ) {
        return '[DATA_ERR]: World Node ID format is invalid.';
    }

    // -------------------------------------------------------------------------
    // 3. Supabase credentials
    //
    // BUG FIX 3 — No guard against missing constants: if SUPABASE_URL /
    // SUPABASE_KEY are undefined the function would build a broken URL and
    // silently fire a request to "/rest/v1/…" (relative), or expose a PHP
    // notice.  Return early with a clear error so the operator knows
    // immediately that configuration is incomplete.
    // -------------------------------------------------------------------------
    if ( ! defined( 'SUPABASE_URL' ) || ! defined( 'SUPABASE_KEY' ) ) {
        return '[CONFIG_ERR]: Supabase credentials are not configured.';
    }
    $supa_url = SUPABASE_URL;
    $supa_key = SUPABASE_KEY;

    // -------------------------------------------------------------------------
    // 4. Remote request — with caching
    //
    // OPT 1 — The original made a live HTTP request on every shortcode render,
    // including every uncached page load and every widget refresh.  Death
    // records are immutable (a dead character stays dead), so the result is
    // safe to cache.  wp_cache_get/set uses the object cache (Redis/Memcached
    // if available, otherwise the per-request in-memory cache).
    //
    // BUG FIX 4 — http_build_query() URL-encodes values, but the Supabase
    // REST filter syntax "eq.<value>" relies on the dot being literal.
    // http_build_query encodes the dot safely (dots are not encoded by default
    // in PHP), but the "eq." prefix must stay joined to the value, not be
    // passed as a separate key.  The original code had this right; kept as-is
    // but made explicit with a comment for future maintainers.
    // -------------------------------------------------------------------------
    $cache_key  = 'nw_archive_' . $current_user_id . '_' . md5( $world_id );
    $cache_group = 'neoweave';

    $my_dead_agents = wp_cache_get( $cache_key, $cache_group );

    if ( false === $my_dead_agents ) {
        $query_params = http_build_query( [
            'select'      => 'name,notes,created_at,lvl',
            // "eq.<value>" — dot is intentional Supabase filter syntax.
            'wp_user_id'  => 'eq.' . $current_user_id,
            'world_id'    => 'eq.' . $world_id,
            'status'      => 'eq.DEAD',
        ] );

        $query_url = $supa_url . '/rest/v1/cyber_characters?' . $query_params;

        $response = wp_remote_get( $query_url, [
            'headers' => [
                'apikey'        => $supa_key,
                'Authorization' => 'Bearer ' . $supa_key,
            ],
            // OPT 2 — Set an explicit timeout.  The default wp_remote_get
            // timeout is 5 s, which is fine, but being explicit makes it
            // obvious to future maintainers and easy to tune per environment.
            'timeout' => 8,
        ] );

        if ( is_wp_error( $response ) ) {
            return '[SIGNAL_FAILURE]: Unable to sync with Supabase.';
        }

        // BUG FIX 5 — HTTP-level errors (4xx / 5xx) from Supabase are NOT
        // WP_Error instances; wp_remote_get() succeeds at the transport layer.
        // The original code never checked the HTTP status code, so a 401
        // Unauthorized or 500 Internal Server Error would be silently decoded
        // as an empty array and displayed as "ARCHIVE_EMPTY".  Check the
        // status and surface the real error.
        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code < 200 || $http_code >= 300 ) {
            return '[SIGNAL_FAILURE]: Supabase returned HTTP ' . intval( $http_code ) . '.';
        }

        $body = wp_remote_retrieve_body( $response );

        // BUG FIX 6 — json_decode returns null on malformed JSON; the original
        // code passed the result directly to foreach without checking.  A null
        // value would trigger a "foreach argument must be of type array" warning
        // (PHP 8+: TypeError).  Check explicitly.
        $my_dead_agents = json_decode( $body, true );
        if ( ! is_array( $my_dead_agents ) ) {
            return '[DATA_ERR]: Invalid response received from archive.';
        }

        // Cache for 5 minutes.  Short TTL so a GM who resurrects a character
        // doesn't wait long for the archive to update.
        wp_cache_set( $cache_key, $my_dead_agents, $cache_group, 5 * MINUTE_IN_SECONDS );
    }

    // -------------------------------------------------------------------------
    // 5. Empty state
    // -------------------------------------------------------------------------
    if ( empty( $my_dead_agents ) ) {
        return '<div class="operator-archive operator-archive--empty">'
            . '[ARCHIVE_EMPTY]: No personal casualties recorded in this Node.'
            . '</div>';
    }

    // -------------------------------------------------------------------------
    // 6. Build output
    //
    // OPT 3 — The original used string concatenation inside a foreach, which
    // allocates a new string on every iteration.  Collect parts in an array
    // and implode once at the end — faster and easier to read.
    //
    // BUG FIX 7 — date() uses the server's local timezone.  Use wp_date() so
    // the output respects the timezone set in WordPress Settings → General,
    // which is what the operator and players expect.
    //
    // BUG FIX 8 — $agent['lvl'] was passed to esc_html() but lvl is a numeric
    // field; esc_html on a number is harmless but intval() is more semantically
    // correct and prevents unexpected strings like "99; DROP TABLE" if the
    // value somehow arrives as a string (defence in depth).
    //
    // OPT 4 — All visual styling moved to CSS classes (see companion stylesheet
    // or inline <style> block).  Inline styles on every element bloat the HTML,
    // make theming impossible, and hurt Lighthouse scores.  Classes are kept
    // consistent with the existing operator-archive / archive-entry BEM pattern
    // already present in the original.
    // -------------------------------------------------------------------------
    $parts   = [];
    $parts[] = '<div class="operator-archive">';
    $parts[] = '<h2 class="operator-archive__title">'
        . '&gt; PERSONAL_DEATH_LOGS // NODE: '
        . esc_html( substr( $world_id, 0, 8 ) )
        . '</h2>';

    foreach ( $my_dead_agents as $agent ) {
        // BUG FIX 7: wp_date() instead of date() for correct timezone.
        $timestamp = wp_date( 'Y-m-d H:i', strtotime( $agent['created_at'] ?? '' ) );
        // BUG FIX 8: numeric field — intval() for defence in depth.
        $lvl       = intval( $agent['lvl'] ?? 0 );
        $name      = esc_html( $agent['name'] ?? '???' );
        $notes     = ! empty( $agent['notes'] )
            ? esc_html( $agent['notes'] )
            : '[NO_LAST_WORDS_RECORDED]';

        $parts[] = '<div class="archive-entry">';
        $parts[] =   '<div class="archive-entry__header">'
            . 'AGENT: ' . $name
            . ' | LVL: ' . $lvl
            . ' | TERMINATED: ' . esc_html( $timestamp )
            . '</div>';
        $parts[] =   '<div class="archive-entry__notes">' . $notes . '</div>';
        $parts[] = '</div>';
    }

    $parts[] = '<div class="operator-archive__footer">--- END OF ARCHIVE ENTRANCE ---</div>';
    $parts[] = '</div>';

    // OPT 3 (cont.): single allocation instead of N concatenations.
    return implode( "\n", $parts );
}
add_shortcode( 'neoweave_my_world_archive', 'shortcode_neoweave_my_world_archive' );
