<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BUG-FIX: This file previously registered wp_ajax_tw_get_scenarios_ajax
 * pointing to tw_get_scenarios_ajax_handler(). That hook is already
 * registered (with nonce protection) in deck-scenarios.php as
 * tw_get_scenarios_ajax(). Having two handlers on the same action caused
 * both to fire on every request, producing a double JSON response and
 * potential fatal errors if either handler exited early.
 *
 * Fix: registrations and handler removed from this file entirely.
 * The canonical, nonce-protected handler in deck-scenarios.php
 * is the single source of truth for tw_get_scenarios_ajax.
 */
