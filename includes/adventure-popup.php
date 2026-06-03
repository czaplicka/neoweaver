<?php
/**
 * NeoWeaver – Adventure Layout Intro Popup
 * Plik: /wp-content/plugins/neoweaver/includes/adventure-popup.php
 *
 * Rejestruje skrypty/style i wstrzykuje markup popupu na szablonie adventure.
 * Popup pokazuje się tylko przy PIERWSZYM wejściu na stronę (localStorage flag).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rejestruje i kolejkuje CSS + JS popupu tylko gdy aktywny jest adventure template.
 */
function nw_adventure_popup_enqueue( $template ) {
    // dopasuj adventure template po nazwie pliku szablonu
    if ( strpos( $template, 'adventure' ) === false ) {
        return $template;
    }

    $plugin_url = plugin_dir_url( dirname( __FILE__ ) );

    wp_enqueue_style(
        'nw-adventure-popup',
        $plugin_url . 'assets/css/nw-adventure-popup.css',
        [],
        '1.0.0'
    );

    wp_enqueue_script(
        'nw-adventure-popup',
        $plugin_url . 'assets/js/nw-adventure-popup.js',
        [],
        '1.0.0',
        true // footer
    );

    return $template;
}
add_filter( 'template_include', 'nw_adventure_popup_enqueue' );

/**
 * Wstrzykuje markup popupu tuż po <body>.
 * Uruchamia się tylko gdy adventure template jest aktywny.
 */
function nw_adventure_popup_markup() {
    global $template;
    if ( strpos( $template, 'adventure' ) === false ) {
        return;
    }
    ?>
    <div id="nw-intro-popup" class="nw-popup-overlay" role="dialog" aria-modal="true" aria-labelledby="nw-popup-title" aria-describedby="nw-popup-desc">
        <div class="nw-popup-screen">

            <!-- Scanlines overlay -->
            <div class="nw-popup-scanlines" aria-hidden="true"></div>

            <!-- Header -->
            <div class="nw-popup-header">
                <span class="nw-popup-badge" aria-hidden="true">SYS_INIT</span>
                <h2 id="nw-popup-title" class="nw-popup-title">// INTERFACE MAP</h2>
                <button
                    id="nw-popup-close"
                    class="nw-popup-close"
                    aria-label="Close intro popup"
                    type="button"
                >
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>

            <!-- Body -->
            <div id="nw-popup-desc" class="nw-popup-body">
                <p class="nw-popup-intro">
                    &gt; SYSTEM READY. MAPPING ACTIVE INTERFACE ZONES...
                </p>

                <ul class="nw-popup-zones">
                    <li class="nw-popup-zone" data-dir="top">
                        <span class="nw-popup-zone-icon" aria-hidden="true">
                            <!-- arrow up -->
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="19" x2="12" y2="5"/>
                                <polyline points="5 12 12 5 19 12"/>
                            </svg>
                        </span>
                        <span class="nw-popup-zone-dir">TOP</span>
                        <span class="nw-popup-zone-label">Reputation &amp; World stats</span>
                    </li>
                    <li class="nw-popup-zone" data-dir="left">
                        <span class="nw-popup-zone-icon" aria-hidden="true">
                            <!-- arrow left -->
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <line x1="19" y1="12" x2="5" y2="12"/>
                                <polyline points="12 19 5 12 12 5"/>
                            </svg>
                        </span>
                        <span class="nw-popup-zone-dir">LEFT</span>
                        <span class="nw-popup-zone-label">World Map</span>
                    </li>
                    <li class="nw-popup-zone" data-dir="right">
                        <span class="nw-popup-zone-icon" aria-hidden="true">
                            <!-- arrow right -->
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <line x1="5" y1="12" x2="19" y2="12"/>
                                <polyline points="12 5 19 12 12 19"/>
                            </svg>
                        </span>
                        <span class="nw-popup-zone-dir">RIGHT</span>
                        <span class="nw-popup-zone-label">Character Panel</span>
                    </li>
                    <li class="nw-popup-zone" data-dir="bottom">
                        <span class="nw-popup-zone-icon" aria-hidden="true">
                            <!-- arrow down -->
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <polyline points="19 12 12 19 5 12"/>
                            </svg>
                        </span>
                        <span class="nw-popup-zone-dir">BOTTOM</span>
                        <span class="nw-popup-zone-label">Game Cards</span>
                    </li>
                </ul>
            </div>

            <!-- Footer -->
            <div class="nw-popup-footer">
                <button id="nw-popup-confirm" class="nw-popup-btn" type="button">
                    [ ENTER GAME ]
                </button>
                <span class="nw-popup-hint">// press ESC or click outside to dismiss</span>
            </div>

        </div><!-- .nw-popup-screen -->
    </div><!-- #nw-intro-popup -->
    <?php
}
add_action( 'wp_body_open', 'nw_adventure_popup_markup' );
