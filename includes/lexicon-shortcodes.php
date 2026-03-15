<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==========================================
// 7. CYBER LEXICON (Supabase → window.cyberLexicon)
// ==========================================

if ( ! function_exists( 'tw_get_supabase_lexicon' ) ) {
    function tw_get_supabase_lexicon() {
        // Pobieramy wszystkie wpisy z cyber_lexicon
        $data = tw_supabase_get(
            'cyber_lexicon',
            [
                'select' => '*',
            ],
            [
                'timeout' => 10,
            ]
        );

        if ( ! is_array( $data ) ) {
            return [];
        }

        $lexicon = [];

        foreach ( $data as $row ) {
            $clean_key = preg_replace( '/[^a-zA-Z0-9]/', '', $row['tag_key'] ?? '' );
            if ( $clean_key ) {
                $lexicon[ $clean_key ] = [
                    'description' => $row['description'] ?? '',
                    'category'    => $row['category'] ?? 'TAG',
                    'color'       => $row['color'] ?? '#adff00',
                ];
            }
        }

        return $lexicon;
    }
}

// Wstrzyknięcie słownika do window.cyberLexicon na stronie gry (adventure template)
add_action(
    'wp_head',
    function () {
        if ( ! is_page_template( 'templates/adventure.php' ) ) {
            return;
        }
        $lexicon_data = tw_get_supabase_lexicon();
        ?>
        <script id="cyber-lexicon-data">
        window.cyberLexicon = <?php echo wp_json_encode( $lexicon_data ); ?>;
        console.log('🧠 Lexicon Loaded (<?php echo count( $lexicon_data ); ?> tags)');
        </script>
        <?php
    },
    1
);
// ==========================================
// Shortcode [cyber_text]
// ==========================================

add_shortcode(
    'cyber_text',
    function( $atts, $content = null ) {
        if ( ! $content ) {
            return '';
        }
        $processed = preg_replace_callback(
            '/#([a-zA-Z0-9]+)/',
            function( $matches ) {
                $tag = strtolower( $matches[1] );
                return '<span class="cyber-tag" data-tag="' . esc_attr( $tag ) . '">#' . esc_html( $matches[1] ) . '</span>';
            },
            $content
        );
        return '<div class="cyber-narrative">' . do_shortcode( $processed ) . '</div>';
    }
);

// ==========================================
// body_class dla publicznego profilu
// ==========================================

if ( ! function_exists( 'tw_public_profile_body_class' ) ) {
    function tw_public_profile_body_class( $classes ) {
        // BUG-FIX #2: slug was 'public_profile.php' — wrong name, missing
        // 'templates/' prefix. Template is registered under
        // 'templates/public-character-profile.php' in neoweaver-wp-core.php.
        if ( is_page() && get_page_template_slug( get_queried_object_id() ) === 'templates/public-character-profile.php' ) {
            $classes[] = 'character-profile';
        }
        return $classes;
    }
}
add_filter( 'body_class', 'tw_public_profile_body_class' );
