<?php

function tw_time_ago( $timestamp ) {
    $created = strtotime( $timestamp );
    if ( ! $created ) return '';

    $diff = time() - $created; // Fix: use time() instead of deprecated current_time('timestamp')

    if ( $diff < 60 )    return 'just now';
    if ( $diff < 3600 )  return floor( $diff / 60 ) . ' min ago';
    if ( $diff < 86400 ) return floor( $diff / 3600 ) . ' h ago';

    return floor( $diff / 86400 ) . ' d ago';
}

function tw_render_quest_card( array $quest ): string {
    $scenario = $quest['cyber_scenarios'] ?? null;
    if ( ! $scenario || ! is_array( $scenario ) ) return '';

    $quest_type = $scenario['type']     ?? 'side';
    $quest_name = $scenario['name']     ?? 'Unknown objective';
    $quest_tags = $scenario['tags']     ?? '';
    $category   = $scenario['category'] ?? 'UNCATEGORIZED';
    $goal       = $scenario['goal']     ?? 'N/A';

    $area      = $scenario['cyber_areas'] ?? null;
    $area_name = is_array( $area ) ? ( $area['name'] ?? 'Unknown area' ) : 'Unknown area';

    $created_at = $quest['created_at'] ?? null;
    $time_ago   = $created_at ? tw_time_ago( $created_at ) : '';

    $tags_html = '';
    if ( ! empty( $quest_tags ) ) {
        foreach ( explode( ',', $quest_tags ) as $tag ) {
            $tag = trim( $tag );
            if ( $tag !== '' ) {
                $tags_html .= '<span class="tw-tag">' . esc_html( $tag ) . '</span>';
            }
        }
    }

    return sprintf(
        "<div class='scenario-card'>
            <div class='quest-header'>// OBJECTIVE: %s - %s</div>
            <div class='quest-name-line'>%s</div>
            <div class='quest-tags-line'>%s</div>
            <div class='quest-what'>// WHAT: %s</div>
            <div class='quest-where'>// WHERE: %s</div>
            <div class='quest-time'>// TIME: %s</div>
        </div>",
        esc_html( strtoupper( $category ) ),
        esc_html( strtoupper( $quest_type ) ),
        esc_html( $quest_name ),
        $tags_html,
        esc_html( $goal ),
        esc_html( $area_name ),
        esc_html( $time_ago )
    );
}

function tw_display_active_scenarios_shortcode(): string {
    $character_id = tw_get_current_character_id();

    if ( ! $character_id ) {
        return '<div class="echo-stream-container">// ERROR: NO ACTIVE SESSION DETECTED</div>';
    }

    // Fix: sanitize character_id before using in URL
    $url = add_query_arg( [
        'character_id' => 'eq.' . rawurlencode( $character_id ),
        'select'       => '*,cyber_scenarios(*,cyber_areas(*))',
    ], 'https://' . TW_SUPABASE_PROJECT_ID . '.supabase.co/rest/v1/cyber_active_quests' );

    $response = wp_remote_get( $url, [
        'headers' => [
            'apikey'        => TW_SUPABASE_ANON_KEY,
            'Authorization' => 'Bearer ' . TW_SUPABASE_ANON_KEY,
        ],
    ] );

    $error_card = '<div class="scenario-card" style="opacity:0.5;text-align:center;border:1px dashed #444;">%s</div>';

    if ( is_wp_error( $response ) ) {
        return sprintf( $error_card, '// CONNECTION ERROR' );
    }

    $quests = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! is_array( $quests ) || isset( $quests['code'], $quests['message'] ) ) {
        return sprintf( $error_card, '// API ERROR' );
    }

    if ( empty( $quests ) ) {
        return sprintf( $error_card, 'NO OBJECTIVES' );
    }

    // Group quests by status; define order explicitly
    $grouped = array_fill_keys( [ 'active', 'completed', 'failed', 'paused' ], [] );

    foreach ( $quests as $quest ) {
        if ( ! is_array( $quest ) ) continue;
        $status = $quest['status'] ?? 'active';
        // Fix: drop unknown statuses into 'active' instead of silently creating ungrouped buckets
        $grouped[ array_key_exists( $status, $grouped ) ? $status : 'active' ][] = $quest;
    }

    $output = '<div class="active-scenarios-container">';

    foreach ( $grouped as $status => $quests_in_group ) {
        if ( empty( $quests_in_group ) ) continue;

        $output .= '<div class="quest-status-header">' . strtoupper( $status ) . ':</div>';
        foreach ( $quests_in_group as $quest ) {
            $output .= tw_render_quest_card( $quest );
        }
    }

    $output .= '</div>';

    return $output;
}

add_shortcode( 'active_scenarios', 'tw_display_active_scenarios_shortcode' );
