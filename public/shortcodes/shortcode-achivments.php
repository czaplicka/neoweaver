function render_player_achievements($atts) {
    // Parametry: type (all/earned), user_id (opcjonalnie), character_id (opcjonalnie)
    $a = shortcode_atts([
        'type' => 'all',
        'user_id' => get_current_user_id(),
        'char_id' => null
    ], $atts);

    // UWAGA: Tutaj musisz podpiąć swoje połączenie z bazą (np. przez global $wpdb lub SDK Supabase)
    // Poniżej przykładowa logika pobierania danych z View:
    
    $query = "SELECT * FROM player_achievements_view WHERE user_id = '{$a['user_id']}'";
    
    if ($a['char_id']) {
        $query .= " AND (character_id = '{$a['char_id']}' OR character_id IS NULL)";
    }
    
    if ($a['type'] === 'earned') {
        $query .= " AND is_unlocked = true";
    }

    // Pobierz wyniki (używając Twojej metody dostępu do danych)
    $results = fetch_from_supabase($query); // Funkcja pomocnicza

    if (empty($results)) return "<p>Brak osiągnięć do wyświetlenia.</p>";

    $output = '<div class="achievements-grid">';

    foreach ($results as $ach) {
        // Wybór kształtu na podstawie scope (np. postać = tarcza, konto = hex)
        $shape_class = ($ach->scope === 'character') ? 'ach-shape-shield' : 'ach-shape-hex';
        
        $output .= "<div class='ach-card {$ach->css_status} {$shape_class}' style='background-color: {$ach->bg_color};'>";
        
        // Ikona (zakładamy FontAwesome lub podobne)
        $icon = ($ach->css_status === 'status-hidden') ? 'question' : $ach->icon_slug;
        $output .= "<div class='ach-icon'><i class='fas fa-{$icon}'></i></div>";
        
        // Tytuł
        $output .= "<div class='ach-title'>" . esc_html($ach->display_title) . "</div>";
        
        // Progres (pokaż tylko jeśli nieodblokowane i nieukryte)
        if (!$ach->is_unlocked && $ach->css_status !== 'status-hidden' && $ach->goal > 1) {
            $output .= "<div class='ach-progress'>{$ach->current_progress}/{$ach->goal}</div>";
        }
        
        $output .= "</div>";
    }

    $output .= '</div>';
    return $output;
}
add_shortcode('achievements', 'render_player_achievements');
