function render_player_achievements( $atts ) {
    // Parametry: type (all/earned), user_id (opcjonalnie), character_id (opcjonalnie)
    $a = shortcode_atts(
        [
            'type'    => 'all',
            'user_id' => get_current_user_id(),
            'char_id' => null,
        ],
        $atts
    );

    // TODO: tu podepnij swój dostęp do Supabase zamiast prostego konkatenowania SQL
    $user_id = intval( $a['user_id'] ); // minimalne zabezpieczenie
    $query   = "SELECT * FROM player_achievements_view WHERE user_id = {$user_id}";

    if ( ! empty( $a['char_id'] ) ) {
        $char_id = intval( $a['char_id'] );
        $query  .= " AND (character_id = {$char_id} OR character_id IS NULL)";
    }

    if ( $a['type'] === 'earned' ) {
        $query .= " AND is_unlocked = true";
    }

    // Pobierz wyniki (używając Twojej metody dostępu do danych)
    $results = fetch_from_supabase( $query ); // Funkcja pomocnicza

    if ( empty( $results ) ) {
        return '<p>Brak osiągnięć do wyświetlenia.</p>';
    }

    $output = '<div class="achievements-grid">';

    foreach ( $results as $ach ) {
        // 1. Procent postępu
        $percent = ! empty( $ach->is_unlocked ) ? 100 : ( $ach->progress_percent ?? 0 );

        // 2. Legacy
        $legacy_class = ! empty( $ach->is_legacy ) ? 'ach-legacy' : '';

        // 3. Kształt na podstawie scope
        $scope       = $ach->scope ?? 'account';
        $shape_class = ( $scope === 'character' ) ? 'ach-shape-shield' : 'ach-shape-hex';

        // 4. Style z CSS variables (jeśli z nich korzystasz)
        $bg_color = ! empty( $ach->bg_color ) ? $ach->bg_color : '#222222';
        $style    = "--bg-color: {$bg_color}; --prog-percent: {$percent}%;";

        // 5. Ikona
        $status = $ach->css_status ?? '';
        $icon   = ( $status === 'status-hidden' ) ? 'question' : ( $ach->icon_slug ?? 'star' );

        $output .= '<div class="ach-card ' . esc_attr( $status . ' ' . $shape_class . ' ' . $legacy_class ) . '" style="' . esc_attr( $style ) . '">';

        $output .= '<div class="ach-icon"><i class="fas fa-' . esc_attr( $icon ) . '"></i></div>';

        $output .= '<div class="ach-title">' . esc_html( $ach->display_title ) . '</div>';

        // Progres (pokaż tylko jeśli nieodblokowane i nieukryte i ma sensowny goal)
        $goal = isset( $ach->goal ) ? (int) $ach->goal : 0;
        if ( empty( $ach->is_unlocked ) && $status !== 'status-hidden' && $goal > 1 ) {
            $current = isset( $ach->current_progress ) ? (int) $ach->current_progress : 0;
            $output .= '<div class="ach-progress">' . esc_html( $current . '/' . $goal ) . '</div>';
        }

        $output .= '</div>';
    }

    $output .= '</div>';

    return $output;
}
add_shortcode( 'achievements', 'render_player_achievements' );
