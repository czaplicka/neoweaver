<?php
/**
 * NeoWeaver Admin — Main Menu & Dashboard
 *
 * Loaded FIRST (explicitly, before glob) so the top-level "neoweaver"
 * menu slug exists when all submenu files run add_submenu_page().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Admin {

\tprivate string $slug = 'neoweaver';

\tpublic function __construct() {
\t\tadd_action( 'admin_menu',            [ $this, 'register_menu'        ] );
\t\tadd_action( 'admin_menu',            [ $this, 'rename_first_submenu' ], 999 );
\t\tadd_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'       ] );
\t\tadd_action( 'wp_ajax_nw_dashboard_data', [ $this, 'ajax_dashboard_data' ] );
\t}

\t/* ------------------------------------------------------------------ */
\t/*  MENU                                                               */
\t/* ------------------------------------------------------------------ */

\tpublic function register_menu(): void {
\t\tadd_menu_page(
\t\t\t'NeoWeaver',
\t\t\t'⚡ NeoWeaver',
\t\t\t'manage_options',
\t\t\t$this->slug,
\t\t\t[ $this, 'render_page' ],
\t\t\t'data:image/svg+xml;base64,' . base64_encode( $this->logo_svg() ),
\t\t\t30
\t\t);
\t}

\tpublic function rename_first_submenu(): void {
\t\tglobal $submenu;
\t\tif ( isset( $submenu[ $this->slug ][0][0] ) ) {
\t\t\t$submenu[ $this->slug ][0][0] = '📊 Dashboard'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
\t\t}
\t}

\t/* ------------------------------------------------------------------ */
\t/*  ASSETS                                                             */
\t/* ------------------------------------------------------------------ */

\tpublic function enqueue_assets( string $hook ): void {
\t\t$is_dashboard = ( $hook === 'toplevel_page_' . $this->slug );
\t\t$is_any_nw    = $is_dashboard || str_contains( $hook, 'neoweaver' );
\t\tif ( ! $is_any_nw ) return;

\t\twp_enqueue_style(
\t\t\t'chakra-petch',
\t\t\t'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
\t\t\t[],
\t\t\tnull
\t\t);

\t\tif ( $is_dashboard ) {
\t\t\twp_enqueue_script( 'jquery' );
\t\t\twp_add_inline_style( 'chakra-petch', $this->get_css() );
\t\t\twp_add_inline_script( 'jquery', $this->get_js() );
\t\t}
\t}

\t/* ------------------------------------------------------------------ */
\t/*  HELPERS                                                            */
\t/* ------------------------------------------------------------------ */

\tprivate function get_supa_url(): string {
\t\treturn function_exists( 'tw_supabase_url' ) ? trim( (string) tw_supabase_url() ) : '';
\t}

\tprivate function get_supa_key(): string {
\t\tif ( function_exists( 'tw_supabase_service_key' ) && tw_supabase_service_key() ) {
\t\t\treturn trim( (string) tw_supabase_service_key() );
\t\t}
\t\tif ( function_exists( 'tw_supabase_anon_key' ) && tw_supabase_anon_key() ) {
\t\t\treturn trim( (string) tw_supabase_anon_key() );
\t\t}
\t\treturn '';
\t}

\tprivate function supa_get( string $path ): array {
\t\t$supa_url = $this->get_supa_url();
\t\t$supa_key = $this->get_supa_key();

\t\tif ( ! $supa_url || ! $supa_key ) {
\t\t\treturn [ 'ok' => false, 'status' => 0, 'headers' => [], 'body' => null, 'error' => 'Supabase not configured.' ];
\t\t}

\t\t$res = wp_remote_get(
\t\t\trtrim( $supa_url, '/' ) . '/rest/v1/' . ltrim( $path, '/' ),
\t\t\t[
\t\t\t\t'timeout' => 12,
\t\t\t\t'headers' => [
\t\t\t\t\t'apikey'        => $supa_key,
\t\t\t\t\t'Authorization' => 'Bearer ' . $supa_key,
\t\t\t\t\t'Accept'        => 'application/json',
\t\t\t\t],
\t\t\t]
\t\t);

\t\tif ( is_wp_error( $res ) ) {
\t\t\treturn [ 'ok' => false, 'status' => 0, 'headers' => [], 'body' => null, 'error' => $res->get_error_message() ];
\t\t}

\t\t$body = wp_remote_retrieve_body( $res );
\t\t$data = json_decode( $body, true );

\t\treturn [
\t\t\t'ok'      => ( wp_remote_retrieve_response_code( $res ) >= 200 && wp_remote_retrieve_response_code( $res ) < 300 ),
\t\t\t'status'  => (int) wp_remote_retrieve_response_code( $res ),
\t\t\t'headers' => wp_remote_retrieve_headers( $res ),
\t\t\t'body'    => $data,
\t\t\t'raw'     => $body,
\t\t\t'error'   => null,
\t\t];
\t}

\tprivate function supa_count( string $table ): int {
\t\t$supa_url = $this->get_supa_url();
\t\t$supa_key = $this->get_supa_key();

\t\tif ( ! $supa_url || ! $supa_key ) return 0;

\t\t$res = wp_remote_get(
\t\t\trtrim( $supa_url, '/' ) . '/rest/v1/' . $table . '?select=id',
\t\t\t[
\t\t\t\t'timeout' => 10,
\t\t\t\t'headers' => [
\t\t\t\t\t'apikey'        => $supa_key,
\t\t\t\t\t'Authorization' => 'Bearer ' . $supa_key,
\t\t\t\t\t'Range'         => '0-0',
\t\t\t\t\t'Prefer'        => 'count=exact',
\t\t\t\t\t'Accept'        => 'application/json',
\t\t\t\t],
\t\t\t]
\t\t);

\t\tif ( is_wp_error( $res ) ) return 0;

\t\t$cr = wp_remote_retrieve_header( $res, 'content-range' );
\t\tif ( $cr && preg_match( '//(d+)$/', $cr, $m ) ) {
\t\t\treturn (int) $m[1];
\t\t}

\t\treturn 0;
\t}

\tprivate function supa_recent_count( string $table, int $days = 7 ): int {
\t\t$since = gmdate( 'Y-m-dTH:i:sZ', time() - ( $days * DAY_IN_SECONDS ) );

\t\t$res = wp_remote_get(
\t\t\trtrim( $this->get_supa_url(), '/' ) . '/rest/v1/' . $table . '?select=id&created_at=gte.' . rawurlencode( $since ),
\t\t\t[
\t\t\t\t'timeout' => 10,
\t\t\t\t'headers' => [
\t\t\t\t\t'apikey'        => $this->get_supa_key(),
\t\t\t\t\t'Authorization' => 'Bearer ' . $this->get_supa_key(),
\t\t\t\t\t'Range'         => '0-0',
\t\t\t\t\t'Prefer'        => 'count=exact',
\t\t\t\t\t'Accept'        => 'application/json',
\t\t\t\t],
\t\t\t]
\t\t);

\t\tif ( is_wp_error( $res ) ) return 0;

\t\t$cr = wp_remote_retrieve_header( $res, 'content-range' );
\t\tif ( $cr && preg_match( '//(d+)$/', $cr, $m ) ) {
\t\t\treturn (int) $m[1];
\t\t}

\t\treturn 0;
\t}

\tprivate function supa_growth_series( string $table, int $days = 30 ): array {
\t\t$since = gmdate( 'Y-m-dTH:i:sZ', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

\t\t$path = $table
\t\t\t. '?select=created_at'
\t\t\t. '&created_at=gte.' . rawurlencode( $since )
\t\t\t. '&order=created_at.asc'
\t\t\t. '&limit=5000';

\t\t$res = $this->supa_get( $path );
\t\t$rows = ( $res['ok'] && is_array( $res['body'] ) ) ? $res['body'] : [];

\t\t$series = [];
\t\tfor ( $i = $days - 1; $i >= 0; $i-- ) {
\t\t\t$d = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
\t\t\t$series[ $d ] = 0;
\t\t}

\t\tforeach ( $rows as $row ) {
\t\t\tif ( empty( $row['created_at'] ) ) continue;
\t\t\t$key = gmdate( 'Y-m-d', strtotime( $row['created_at'] ) );
\t\t\tif ( isset( $series[ $key ] ) ) {
\t\t\t\t$series[ $key ]++;
\t\t\t}
\t\t}

\t\t$out = [];
\t\tforeach ( $series as $date => $count ) {
\t\t\t$out[] = [
\t\t\t\t'date'  => $date,
\t\t\t\t'value' => $count,
\t\t\t];
\t\t}

\t\treturn $out;
\t}

\tprivate function supa_recent_logs( int $limit = 10 ): array {
\t\t$path = 'cyber_debug_logs?select=id,created_at,level,message,context,data&order=created_at.desc&limit=' . (int) $limit;
\t\t$res  = $this->supa_get( $path );
\t\treturn ( $res['ok'] && is_array( $res['body'] ) ) ? $res['body'] : [];
\t}

\tprivate function supa_campaigns_without_character(): int {
\t\t$path = 'cyber_campaign?select=id,cyber_campaigncharacters!left(character_id)&limit=5000';
\t\t$res  = $this->supa_get( $path );
\t\t$rows = ( $res['ok'] && is_array( $res['body'] ) ) ? $res['body'] : [];

\t\t$count = 0;
\t\tforeach ( $rows as $row ) {
\t\t\tif ( empty( $row['cyber_campaigncharacters'] ) ) {
\t\t\t\t$count++;
\t\t\t}
\t\t}
\t\treturn $count;
\t}

\tprivate function supa_worlds_without_campaigns(): int {
\t\t$path = 'cyber_worlds?select=id,cyber_campaign!left(id)&limit=5000';
\t\t$res  = $this->supa_get( $path );
\t\t$rows = ( $res['ok'] && is_array( $res['body'] ) ) ? $res['body'] : [];

\t\t$count = 0;
\t\tforeach ( $rows as $row ) {
\t\t\tif ( empty( $row['cyber_campaign'] ) ) {
\t\t\t\t$count++;
\t\t\t}
\t\t}
\t\treturn $count;
\t}

\t/* ------------------------------------------------------------------ */
\t/*  AJAX: DASHBOARD                                                    */
\t/* ------------------------------------------------------------------ */

\tpublic function ajax_dashboard_data(): void {
\t\tcheck_ajax_referer( 'neoweaver_dashboard', 'nonce' );

\t\tif ( ! current_user_can( 'manage_options' ) ) {
\t\t\twp_send_json_error( 'Forbidden', 403 );
\t\t}

\t\t$supa_url = $this->get_supa_url();
\t\t$supa_key = $this->get_supa_key();

\t\tif ( ! $supa_url || ! $supa_key ) {
\t\t\twp_send_json_error( 'Supabase not configured.' );
\t\t}

\t\t$counts = [
\t\t\t'characters' => $this->supa_count( 'cyber_characters' ),
\t\t\t'worlds'     => $this->supa_count( 'cyber_worlds' ),
\t\t\t'campaigns'  => $this->supa_count( 'cyber_campaign' ),
\t\t];

\t\t$recent = [
\t\t\t'characters_7d' => $this->supa_recent_count( 'cyber_characters', 7 ),
\t\t\t'worlds_7d'     => $this->supa_recent_count( 'cyber_worlds', 7 ),
\t\t\t'campaigns_7d'  => $this->supa_recent_count( 'cyber_campaign', 7 ),
\t\t];

\t\t$growth = [
\t\t\t'characters' => $this->supa_growth_series( 'cyber_characters', 30 ),
\t\t\t'worlds'     => $this->supa_growth_series( 'cyber_worlds', 30 ),
\t\t\t'campaigns'  => $this->supa_growth_series( 'cyber_campaign', 30 ),
\t\t];

\t\t$health = [
\t\t\t'worlds_without_campaigns'    => $this->supa_worlds_without_campaigns(),
\t\t\t'campaigns_without_character' => $this->supa_campaigns_without_character(),
\t\t];

\t\t$alerts = [];

\t\tif ( $recent['characters_7d'] === 0 ) {
\t\t\t$alerts[] = [ 'level' => 'warn', 'label' => 'Characters', 'text' => 'No new characters in the last 7 days.' ];
\t\t}
\t\tif ( $recent['worlds_7d'] === 0 ) {
\t\t\t$alerts[] = [ 'level' => 'warn', 'label' => 'Worlds', 'text' => 'No new worlds in the last 7 days.' ];
\t\t}
\t\tif ( $recent['campaigns_7d'] === 0 ) {
\t\t\t$alerts[] = [ 'level' => 'warn', 'label' => 'Campaigns', 'text' => 'No new campaigns in the last 7 days.' ];
\t\t}
\t\tif ( $health['worlds_without_campaigns'] > 0 ) {
\t\t\t$alerts[] = [
\t\t\t\t'level' => 'info',
\t\t\t\t'label' => 'World Coverage',
\t\t\t\t'text'  => $health['worlds_without_campaigns'] . ' world(s) have no campaign yet.',
\t\t\t];
\t\t}
\t\tif ( $health['campaigns_without_character'] > 0 ) {
\t\t\t$alerts[] = [
\t\t\t\t'level' => 'warn',
\t\t\t\t'label' => 'Campaign Setup',
\t\t\t\t'text'  => $health['campaigns_without_character'] . ' campaign(s) have no assigned character.',
\t\t\t];
\t\t}

\t\t$logs = $this->supa_recent_logs( 10 );

\t\twp_send_json_success(
\t\t\t[
\t\t\t\t'counts' => $counts,
\t\t\t\t'recent' => $recent,
\t\t\t\t'growth' => $growth,
\t\t\t\t'health' => $health,
\t\t\t\t'alerts' => $alerts,
\t\t\t\t'logs'   => $logs,
\t\t\t]
\t\t);
\t}

\t/* ------------------------------------------------------------------ */
\t/*  RENDER                                                             */
\t/* ------------------------------------------------------------------ */

\tpublic function render_page(): void {
\t\t$supa_url = $this->get_supa_url();
\t\t$key_ok   = (bool) $this->get_supa_key();
\t\t?>
\t\t<div class="wrap nw-dash" id="nw-dashboard">

\t\t\t<div class="nw-dash-header">
\t\t\t\t<div class="nw-dash-logo">
\t\t\t\t\t<?php echo $this->logo_svg( 44, '#adff00' ); ?>
\t\t\t\t\t<div>
\t\t\t\t\t\t<span class="nw-logo-name"><span class="nw-accent">Neo</span>Weaver</span>
\t\t\t\t\t\t<span class="nw-logo-version">v<?php echo esc_html( NEOWEAVER_VERSION ); ?> &mdash; Game Ops Dashboard</span>
\t\t\t\t\t</div>
\t\t\t\t</div>
\t\t\t\t<button class="nw-btn nw-btn-ghost" id="nw-refresh-dashboard">↻ Refresh</button>
\t\t\t</div>

\t\t\t<div class="nw-grid-main">

\t\t\t\t<section class="nw-block">
\t\t\t\t\t<div class="nw-block-head">
\t\t\t\t\t\t<h2 class="nw-section-title">Overview</h2>
\t\t\t\t\t\t<span class="nw-section-kicker">product activity</span>
\t\t\t\t\t</div>

\t\t\t\t\t<div class="nw-stat-grid">
\t\t\t\t\t\t<div class="nw-stat-card">
\t\t\t\t\t\t\t<div class="nw-stat-label-top">Characters</div>
\t\t\t\t\t\t\t<div class="nw-stat-value" id="nw-stat-characters"><div class="nw-spinner"></div></div>
\t\t\t\t\t\t\t<div class="nw-stat-sub" id="nw-recent-characters">Last 7d: —</div>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<div class="nw-stat-card">
\t\t\t\t\t\t\t<div class="nw-stat-label-top">Worlds</div>
\t\t\t\t\t\t\t<div class="nw-stat-value" id="nw-stat-worlds"><div class="nw-spinner"></div></div>
\t\t\t\t\t\t\t<div class="nw-stat-sub" id="nw-recent-worlds">Last 7d: —</div>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<div class="nw-stat-card">
\t\t\t\t\t\t\t<div class="nw-stat-label-top">Campaigns</div>
\t\t\t\t\t\t\t<div class="nw-stat-value" id="nw-stat-campaigns"><div class="nw-spinner"></div></div>
\t\t\t\t\t\t\t<div class="nw-stat-sub" id="nw-recent-campaigns">Last 7d: —</div>
\t\t\t\t\t\t</div>
\t\t\t\t\t</div>
\t\t\t\t</section>

\t\t\t\t<section class="nw-block">
\t\t\t\t\t<div class="nw-block-head">
\t\t\t\t\t\t<h2 class="nw-section-title">Growth</h2>
\t\t\t\t\t\t<span class="nw-section-kicker">last 30 days</span>
\t\t\t\t\t</div>

\t\t\t\t\t<div class="nw-chart-grid">
\t\t\t\t\t\t<div class="nw-chart-card">
\t\t\t\t\t\t\t<div class="nw-chart-title">Characters</div>
\t\t\t\t\t\t\t<div class="nw-chart" id="nw-chart-characters"></div>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<div class="nw-chart-card">
\t\t\t\t\t\t\t<div class="nw-chart-title">Worlds</div>
\t\t\t\t\t\t\t<div class="nw-chart" id="nw-chart-worlds"></div>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<div class="nw-chart-card">
\t\t\t\t\t\t\t<div class="nw-chart-title">Campaigns</div>
\t\t\t\t\t\t\t<div class="nw-chart" id="nw-chart-campaigns"></div>
\t\t\t\t\t\t</div>
\t\t\t\t\t</div>
\t\t\t\t</section>

\t\t\t\t<div class="nw-grid-2">
\t\t\t\t\t<section class="nw-block">
\t\t\t\t\t\t<div class="nw-block-head">
\t\t\t\t\t\t\t<h2 class="nw-section-title">Needs Attention</h2>
\t\t\t\t\t\t\t<span class="nw-section-kicker">exceptions first</span>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<div id="nw-alerts" class="nw-alerts-list">
\t\t\t\t\t\t\t<div class="nw-alert-card nw-alert-card-loading">
\t\t\t\t\t\t\t\t<div class="nw-spinner"></div>
\t\t\t\t\t\t\t\t<span>Checking system state…</span>
\t\t\t\t\t\t\t</div>
\t\t\t\t\t\t</div>

\t\t\t\t\t\t<div class="nw-health-grid">
\t\t\t\t\t\t\t<div class="nw-health-card">
\t\t\t\t\t\t\t\t<div class="nw-health-label">Worlds without campaigns</div>
\t\t\t\t\t\t\t\t<div class="nw-health-value" id="nw-health-worlds-without-campaigns">—</div>
\t\t\t\t\t\t\t</div>
\t\t\t\t\t\t\t<div class="nw-health-card">
\t\t\t\t\t\t\t\t<div class="nw-health-label">Campaigns without character</div>
\t\t\t\t\t\t\t\t<div class="nw-health-value" id="nw-health-campaigns-without-character">—</div>
\t\t\t\t\t\t\t</div>
\t\t\t\t\t\t</div>
\t\t\t\t\t</section>

\t\t\t\t\t<section class="nw-block">
\t\t\t\t\t\t<div class="nw-block-head">
\t\t\t\t\t\t\t<h2 class="nw-section-title">Recent System Events</h2>
\t\t\t\t\t\t\t<span class="nw-section-kicker">cyber_debug_logs</span>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<div id="nw-logs" class="nw-logs-list">
\t\t\t\t\t\t\t<div class="nw-empty-state">Loading recent events…</div>
\t\t\t\t\t\t</div>
\t\t\t\t\t</section>
\t\t\t\t</div>

\t\t\t\t<section class="nw-block">
\t\t\t\t\t<div class="nw-block-head">
\t\t\t\t\t\t<h2 class="nw-section-title">System</h2>
\t\t\t\t\t\t<span class="nw-section-kicker">environment</span>
\t\t\t\t\t</div>

\t\t\t\t\t<div class="nw-sysinfo">
\t\t\t\t\t\t<div class="nw-sysinfo-row">
\t\t\t\t\t\t\t<span class="nw-sysinfo-label">Plugin version</span>
\t\t\t\t\t\t\t<span class="nw-sysinfo-val"><?php echo esc_html( NEOWEAVER_VERSION ); ?></span>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<div class="nw-sysinfo-row">
\t\t\t\t\t\t\t<span class="nw-sysinfo-label">Supabase URL</span>
\t\t\t\t\t\t\t<span class="nw-sysinfo-val"><?php echo $supa_url ? esc_html( $supa_url ) : '<span class="nw-text-danger">Not configured</span>'; ?></span>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<div class="nw-sysinfo-row">
\t\t\t\t\t\t\t<span class="nw-sysinfo-label">Supabase Key</span>
\t\t\t\t\t\t\t<span class="nw-sysinfo-val"><?php echo $key_ok ? '<span class="nw-text-good">✓ Configured</span>' : '<span class="nw-text-danger">✗ Missing</span>'; ?></span>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<div class="nw-sysinfo-row">
\t\t\t\t\t\t\t<span class="nw-sysinfo-label">PHP</span>
\t\t\t\t\t\t\t<span class="nw-sysinfo-val"><?php echo esc_html( PHP_VERSION ); ?></span>
\t\t\t\t\t\t</div>
\t\t\t\t\t\t<div class="nw-sysinfo-row">
\t\t\t\t\t\t\t<span class="nw-sysinfo-label">WordPress</span>
\t\t\t\t\t\t\t<span class="nw-sysinfo-val"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
\t\t\t\t\t\t</div>
\t\t\t\t\t</div>
\t\t\t\t</section>

\t\t\t</div>

\t\t\t<input type="hidden" id="nw-dash-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_dashboard' ) ); ?>">
\t\t</div>
\t\t<?php
\t}

\t/* ------------------------------------------------------------------ */
\t/*  SVG LOGO                                                           */
\t/* ------------------------------------------------------------------ */

\tprivate function logo_svg( int $size = 20, string $color = '#ffffff' ): string {
\t\treturn '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 40 40" fill="none" aria-label="NeoWeaver">'
\t\t\t. '<polygon points="20,2 36,11 36,29 20,38 4,29 4,11" stroke="' . esc_attr( $color ) . '" stroke-width="2.5" fill="none"/>'
\t\t\t. '<polyline points="11,27 11,13 20,24 29,13 29,27" stroke="' . esc_attr( $color ) . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" fill="none"/>'
\t\t\t. '</svg>';
\t}

\t/* ------------------------------------------------------------------ */
\t/*  CSS                                                                */
\t/* ------------------------------------------------------------------ */

\tprivate function get_css(): string { return <<<'CSS'
.nw-dash{font-family:'Chakra Petch',monospace;color:#e0e0e0;max-width:1280px}
.nw-dash *{box-sizing:border-box}
.nw-dash-header{display:flex;align-items:center;justify-content:space-between;padding:20px 0;border-bottom:1px solid #2a2a2a;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.nw-dash-logo{display:flex;align-items:center;gap:14px}
.nw-logo-name{display:block;font-size:26px;font-weight:700;color:#fff;line-height:1}
.nw-accent{color:#adff00}
.nw-logo-version{display:block;font-size:11px;color:#555;margin-top:4px;letter-spacing:.5px}
.nw-btn{font-family:'Chakra Petch',monospace;font-size:12px;font-weight:600;padding:7px 16px;border-radius:5px;border:1px solid transparent;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.5px}
.nw-btn-ghost{background:transparent;color:#adff00;border-color:#2e2e2e}
.nw-btn-ghost:hover{border-color:#adff00;background:#141414}

.nw-grid-main{display:flex;flex-direction:column;gap:18px}
.nw-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:980px){.nw-grid-2{grid-template-columns:1fr}}

.nw-block{background:#101010;border:1px solid #1f1f1f;border-radius:14px;padding:18px 18px 16px;box-shadow:0 8px 24px rgba(0,0,0,.18)}
.nw-block-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.nw-section-title{font-size:13px;text-transform:uppercase;letter-spacing:1px;color:#adff00;font-weight:700;margin:0}
.nw-section-kicker{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:#555}

.nw-stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
@media(max-width:800px){.nw-stat-grid{grid-template-columns:1fr}}
.nw-stat-card{background:#141414;border:1px solid #242424;border-radius:12px;padding:16px;transition:border-color .2s,transform .2s}
.nw-stat-card:hover{border-color:#adff00;transform:translateY(-1px)}
.nw-stat-label-top{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#666}
.nw-stat-value{font-size:36px;font-weight:700;color:#adff00;font-variant-numeric:tabular-nums;min-height:44px;display:flex;align-items:center;margin-top:8px}
.
