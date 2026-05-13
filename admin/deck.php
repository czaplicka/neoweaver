<?php
/**
 * NeoWeaver — Deck Admin
 *
 * Manages cyber_deck table.
 * Instantiated exclusively by NW_Admin_Bootstrap.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'NW_Deck_Admin', false ) ) {
	return;
}

class NW_Deck_Admin {

	private string $page_slug = 'neoweaver-deck';

	private const CATEGORIES = [ 'action', 'magic', 'equipment' ];
	private const RARITIES   = [ 'common', 'uncommon', 'rare', 'epic', 'legendary' ];

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_deck_list',   [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_nw_deck_get',    [ $this, 'ajax_get' ] );
		add_action( 'wp_ajax_nw_deck_save',   [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_deck_toggle', [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nw_deck_delete', [ $this, 'ajax_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			__( 'NeoWeaver — Deck', 'neoweaver' ),
			__( '🃏 Deck', 'neoweaver' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) {
			return;
		}

		wp_enqueue_style(
			'nw-admin-core',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/admin-core.css',
			[ 'nw-font-chakra-petch' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_style(
			'nw-deck-style',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/deck.css',
			[ 'nw-font-chakra-petch', 'nw-admin-core' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_script(
			'nw-deck-script',
			NEOWEAVER_PLUGIN_URL . 'assets/js/admin/deck.js',
			[ 'jquery', 'nw-lucide' ],
			NEOWEAVER_VERSION,
			true
		);

		wp_localize_script(
			'nw-deck-script',
			'NWDeck',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'neoweaver_deck' ),
			]
		);
	}

	private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
		$method = strtoupper( $method );

		if ( 'GET' === $method && function_exists( 'tw_supabase_get' ) ) {
			[ $table, $qs ] = array_pad( explode( '?', ltrim( $endpoint, '/' ), 2 ), 2, '' );
			$query = [];

			if ( $qs ) {
				parse_str( $qs, $query );
			}

			$data = tw_supabase_get( $table, $query );

			if ( ! is_array( $data ) ) {
				return [
					'ok'    => false,
					'code'  => 0,
					'data'  => null,
					'error' => 'tw_supabase_get returned non-array',
				];
			}

			if ( isset( $data['code'], $data['message'] ) ) {
				return [
					'ok'    => false,
					'code'  => (int) $data['code'],
					'data'  => null,
					'error' => $data['message'],
				];
			}

			return [
				'ok'    => true,
				'code'  => 200,
				'data'  => $data,
				'error' => null,
			];
		}

		if ( function_exists( 'tw_supabase_request' ) ) {
			[ $table, $qs ] = array_pad( explode( '?', ltrim( $endpoint, '/' ), 2 ), 2, '' );
			$query = [];

			if ( $qs ) {
				parse_str( $qs, $query );
			}

			$extra_args = [];

			if ( in_array( $method, [ 'POST', 'PATCH' ], true ) ) {
				$extra_args['headers']['Prefer'] = 'return=representation';
			}

			if ( ! empty( $extra_headers ) ) {
				$extra_args['headers'] = array_merge( $extra_args['headers'] ?? [], $extra_headers );
			}

			$res = tw_supabase_request(
				$method,
				$table,
				$query,
				empty( $body ) ? null : $body,
				$extra_args
			);

			$ok   = $res['ok']   ?? false;
			$code = $res['code'] ?? 0;
			$data = $res['data'] ?? null;

			if ( ! $ok ) {
				$msg = is_array( $data )
					? ( $data['message'] ?? 'Supabase error ' . $code )
					: 'Supabase error ' . $code;

				return [
					'ok'    => false,
					'code'  => $code,
					'data'  => $data,
					'error' => $msg,
				];
			}

			return [
				'ok'    => true,
				'code'  => $code,
				'data'  => $data,
				'error' => null,
			];
		}

		return [
			'ok'    => false,
			'code'  => 0,
			'data'  => null,
			'error' => 'Supabase helper functions not available.',
		];
	}

	private function is_uuid( string $value ): bool {
		return (bool) preg_match(
			'/^[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[1-5][0-9a-fA-F]{3}\-[89abAB][0-9a-fA-F]{3}\-[0-9a-fA-F]{12}$/',
			$value
		);
	}

	private function get_uuid_from_post( string $key = 'id' ): string {
		$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );

		if ( ! $value || ! $this->is_uuid( $value ) ) {
			return '';
		}

		return $value;
	}

	private function parse_json_array_field( $value ): array {
		if ( is_array( $value ) ) {
			$items = $value;
		} else {
			$value = trim( (string) $value );

			if ( '' === $value ) {
				return [];
			}

			$decoded = json_decode( $value, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$items = $decoded;
			} else {
				$items = array_map( 'trim', explode( ',', $value ) );
			}
		}

		$items = array_map( 'sanitize_text_field', $items );
		$items = array_values( array_filter( array_unique( $items ), static fn( $v ) => '' !== $v ) );

		return $items;
	}

	private function parse_json_object_field( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return [];
		}

		$decoded = json_decode( $value, true );

		return ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) ? $decoded : [];
	}

	private function maybe_null_text( $value ): ?string {
		$value = trim( sanitize_text_field( (string) $value ) );
		return '' === $value ? null : $value;
	}

	private function maybe_null_textarea( $value ): ?string {
		$value = trim( sanitize_textarea_field( (string) $value ) );
		return '' === $value ? null : $value;
	}

	private function maybe_uuid( $value ): ?string {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return null;
		}

		return $this->is_uuid( $value ) ? $value : null;
	}

	public function render_page(): void {
		?>
		<div class="wrap nw-deck-admin">
			<style>
				.nw-deck-admin .nw-deck-modal-overlay {
					position: fixed;
					inset: 0;
					background: rgba(0, 0, 0, 0.78);
					z-index: 9999;
					overflow-y: auto;
					padding: 32px 16px;
				}
				.nw-deck-admin .nw-deck-modal-panel {
					background: #050505;
					color: #f2f2f2;
					margin: 24px auto;
					padding: 24px;
					max-width: 980px;
					border-radius: 14px;
					position: relative;
					border: 1px solid #2b2b2b;
					box-shadow: 0 24px 80px rgba(0,0,0,.45);
				}
				.nw-deck-admin .nw-deck-modal-panel h2,
				.nw-deck-admin .nw-deck-modal-panel th,
				.nw-deck-admin .nw-deck-modal-panel label {
					color: #f5f5f5;
				}
				.nw-deck-admin .nw-deck-modal-panel td,
				.nw-deck-admin .nw-deck-modal-panel p,
				.nw-deck-admin .nw-deck-modal-panel span {
					color: #d6d6d6;
				}
				.nw-deck-admin .nw-deck-modal-panel input[type="text"],
				.nw-deck-admin .nw-deck-modal-panel input[type="url"],
				.nw-deck-admin .nw-deck-modal-panel input[type="number"],
				.nw-deck-admin .nw-deck-modal-panel textarea,
				.nw-deck-admin .nw-deck-modal-panel select {
					background: #101010;
					color: #f4f4f4;
					border: 1px solid #313131;
					border-radius: 8px;
				}
				.nw-deck-admin .nw-deck-modal-panel input:focus,
				.nw-deck-admin .nw-deck-modal-panel textarea:focus,
				.nw-deck-admin .nw-deck-modal-panel select:focus {
					border-color: #adff00;
					box-shadow: 0 0 0 1px #adff00;
					outline: none;
				}
				.nw-deck-admin #nw-deck-modal-close {
					color: #f5f5f5;
					background: transparent;
					border: 0;
					cursor: pointer;
				}
				.nw-deck-admin .nw-deck-thumb {
					width: 44px;
					height: 44px;
					object-fit: cover;
					border-radius: 8px;
					background: #111;
					border: 1px solid #2a2a2a;
					display: block;
				}
				.nw-deck-admin .nw-deck-thumb-placeholder {
					display: flex;
					align-items: center;
					justify-content: center;
					color: #666;
				}
				.nw-deck-admin #nw-deck-image-preview {
					display: block;
					max-width: 220px;
					max-height: 220px;
					border-radius: 10px;
					border: 1px solid #2f2f2f;
					background: #111;
					padding: 6px;
				}
			</style>

			<h1><?php esc_html_e( 'NeoWeaver — Deck', 'neoweaver' ); ?></h1>

			<div style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; align-items:center;">
				<select id="nw-deck-filter-category">
					<option value=""><?php esc_html_e( 'All categories', 'neoweaver' ); ?></option>
					<?php foreach ( self::CATEGORIES as $c ) : ?>
						<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<select id="nw-deck-filter-rarity">
					<option value=""><?php esc_html_e( 'All rarities', 'neoweaver' ); ?></option>
					<?php foreach ( self::RARITIES as $r ) : ?>
						<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( ucfirst( $r ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<select id="nw-deck-filter-active">
					<option value=""><?php esc_html_e( 'All statuses', 'neoweaver' ); ?></option>
					<option value="1"><?php esc_html_e( 'Active', 'neoweaver' ); ?></option>
					<option value="0"><?php esc_html_e( 'Inactive', 'neoweaver' ); ?></option>
				</select>

				<input type="text" id="nw-deck-search" placeholder="<?php esc_attr_e( 'Search name…', 'neoweaver' ); ?>" style="width:200px;" class="regular-text">
				<button class="button" id="nw-deck-filter-btn"><?php esc_html_e( 'Filter', 'neoweaver' ); ?></button>
				<button class="button button-primary" id="nw-deck-add-btn"><?php esc_html_e( '+ Add Card', 'neoweaver' ); ?></button>
			</div>

			<table class="wp-list-table widefat fixed striped" id="nw-deck-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Image', 'neoweaver' ); ?></th>
						<th><?php esc_html_e( 'Name', 'neoweaver' ); ?></th>
						<th><?php esc_html_e( 'Category', 'neoweaver' ); ?></th>
						<th><?php esc_html_e( 'Type', 'neoweaver' ); ?></th>
						<th><?php esc_html_e( 'Rarity', 'neoweaver' ); ?></th>
						<th><?php esc_html_e( 'Active', 'neoweaver' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'neoweaver' ); ?></th>
					</tr>
				</thead>
				<tbody id="nw-deck-tbody">
					<tr><td colspan="7"><?php esc_html_e( 'Loading…', 'neoweaver' ); ?></td></tr>
				</tbody>
			</table>

			<div id="nw-deck-modal" class="nw-deck-modal-overlay" style="display:none;">
				<div class="nw-deck-modal-panel">
					<button id="nw-deck-modal-close" style="position:absolute; top:12px; right:12px; font-size:20px;">✕</button>
					<h2 id="nw-deck-modal-title"><?php esc_html_e( 'Card', 'neoweaver' ); ?></h2>

					<input type="hidden" id="nw-deck-id">

					<table class="form-table">
						<tr>
							<th><label for="nw-deck-name"><?php esc_html_e( 'Name *', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-name" class="regular-text" required></td>
						</tr>
						<tr>
							<th><label for="nw-deck-category"><?php esc_html_e( 'Category', 'neoweaver' ); ?></label></th>
							<td>
								<select id="nw-deck-category">
									<?php foreach ( self::CATEGORIES as $c ) : ?>
										<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="nw-deck-type"><?php esc_html_e( 'Type', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-type" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-rarity"><?php esc_html_e( 'Rarity', 'neoweaver' ); ?></label></th>
							<td>
								<select id="nw-deck-rarity">
									<?php foreach ( self::RARITIES as $r ) : ?>
										<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( ucfirst( $r ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="nw-deck-description"><?php esc_html_e( 'Description', 'neoweaver' ); ?></label></th>
							<td><textarea id="nw-deck-description" rows="3" class="large-text"></textarea></td>
						</tr>
						<tr>
							<th><label for="nw-deck-effect"><?php esc_html_e( 'Effect', 'neoweaver' ); ?></label></th>
							<td><textarea id="nw-deck-effect" rows="3" class="large-text"></textarea></td>
						</tr>
						<tr>
							<th><label for="nw-deck-mechanic"><?php esc_html_e( 'Mechanic', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-mechanic" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-mechanic-goal"><?php esc_html_e( 'Mechanic Goal', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-mechanic-goal" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-cost-label"><?php esc_html_e( 'Cost Label', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-cost-label" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-cost-number"><?php esc_html_e( 'Cost Number', 'neoweaver' ); ?></label></th>
							<td><input type="number" id="nw-deck-cost-number" min="0" value="0" style="width:80px;"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-time-cost-minutes"><?php esc_html_e( 'Time Cost Minutes', 'neoweaver' ); ?></label></th>
							<td><input type="number" id="nw-deck-time-cost-minutes" min="0" value="0" style="width:80px;"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-cooldown-messages"><?php esc_html_e( 'Cooldown Messages', 'neoweaver' ); ?></label></th>
							<td><input type="number" id="nw-deck-cooldown-messages" min="0" value="0" style="width:80px;"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-entropy-on-fail"><?php esc_html_e( 'Entropy on Fail', 'neoweaver' ); ?></label></th>
							<td><input type="number" id="nw-deck-entropy-on-fail" min="0" value="0" style="width:80px;"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-bonus"><?php esc_html_e( 'Bonus JSON', 'neoweaver' ); ?></label></th>
							<td><textarea id="nw-deck-bonus" rows="3" class="large-text" placeholder='{"damage":2}'></textarea></td>
						</tr>
						<tr>
							<th><label for="nw-deck-tags"><?php esc_html_e( 'Tags', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-tags" class="large-text" placeholder="comma,separated,tags"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-requirement-tags"><?php esc_html_e( 'Requirement Tags', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-requirement-tags" class="large-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-denied-tags"><?php esc_html_e( 'Denied Tags', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-denied-tags" class="large-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-required-item-tags"><?php esc_html_e( 'Required Item Tags', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-required-item-tags" class="large-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-required-location-tags"><?php esc_html_e( 'Required Location Tags', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-required-location-tags" class="large-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-denied-location-tags"><?php esc_html_e( 'Denied Location Tags', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-denied-location-tags" class="large-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-requirement-description"><?php esc_html_e( 'Requirement Description', 'neoweaver' ); ?></label></th>
							<td><textarea id="nw-deck-requirement-description" rows="3" class="large-text"></textarea></td>
						</tr>
						<tr>
							<th><label for="nw-deck-ai-instruction"><?php esc_html_e( 'AI Instruction', 'neoweaver' ); ?></label></th>
							<td><textarea id="nw-deck-ai-instruction" rows="3" class="large-text"></textarea></td>
						</tr>
						<tr>
							<th><label for="nw-deck-gm"><?php esc_html_e( 'GM Notes', 'neoweaver' ); ?></label></th>
							<td><textarea id="nw-deck-gm" rows="3" class="large-text"></textarea></td>
						</tr>
						<tr>
							<th><label for="nw-deck-sound-effect"><?php esc_html_e( 'Sound Effect URL', 'neoweaver' ); ?></label></th>
							<td><input type="url" id="nw-deck-sound-effect" class="large-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-img-url"><?php esc_html_e( 'Image URL', 'neoweaver' ); ?></label></th>
							<td><input type="url" id="nw-deck-img-url" class="large-text"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Preview', 'neoweaver' ); ?></th>
							<td>
								<div id="nw-deck-image-preview-wrap" style="display:none;">
									<img id="nw-deck-image-preview" src="" alt="">
								</div>
							</td>
						</tr>
						<tr>
							<th><label for="nw-deck-class-id"><?php esc_html_e( 'Class ID', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-class-id" class="regular-text"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Flags', 'neoweaver' ); ?></th>
							<td>
								<label><input type="checkbox" id="nw-deck-is-leveling" checked> <?php esc_html_e( 'Is leveling', 'neoweaver' ); ?></label><br>
								<label><input type="checkbox" id="nw-deck-is-disposable"> <?php esc_html_e( 'Is disposable', 'neoweaver' ); ?></label><br>
								<label><input type="checkbox" id="nw-deck-is-active" checked> <?php esc_html_e( 'Is active', 'neoweaver' ); ?></label>
							</td>
						</tr>
					</table>

					<p>
						<button class="button button-primary" id="nw-deck-save-btn"><?php esc_html_e( 'Save', 'neoweaver' ); ?></button>
						<button class="button" id="nw-deck-cancel-btn"><?php esc_html_e( 'Cancel', 'neoweaver' ); ?></button>
						<button class="button button-link-delete" id="nw-deck-delete-btn" style="display:none;"><?php esc_html_e( 'Delete', 'neoweaver' ); ?></button>
						<span id="nw-deck-msg" style="margin-left:12px;"></span>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_list(): void {
		check_ajax_referer( 'neoweaver_deck', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$category = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );
		$rarity   = sanitize_text_field( wp_unslash( $_POST['rarity'] ?? '' ) );
		$search   = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$active   = sanitize_text_field( wp_unslash( $_POST['active'] ?? '' ) );

		$endpoint = 'cyber_deck?select=id,name,deck_category,type,rarity,img_url,is_active&order=name.asc';

		if ( $category && in_array( $category, self::CATEGORIES, true ) ) {
			$endpoint .= '&deck_category=eq.' . rawurlencode( $category );
		}

		if ( $rarity && in_array( $rarity, self::RARITIES, true ) ) {
			$endpoint .= '&rarity=eq.' . rawurlencode( $rarity );
		}

		if ( '' !== $active ) {
			$endpoint .= '&is_active=eq.' . ( '1' === $active ? 'true' : 'false' );
		}

		if ( $search ) {
			$endpoint .= '&name=ilike.*' . rawurlencode( $search ) . '*';
		}

		$result = $this->supa(
			'GET',
			$endpoint,
			[],
			[ 'Range' => '0-199' ]
		);

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['error'] ?? 'Failed to load deck.' );
			return;
		}

		wp_send_json_success( $result['data'] ?? [] );
	}

	public function ajax_get(): void {
		check_ajax_referer( 'neoweaver_deck', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = $this->get_uuid_from_post( 'id' );

		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$result = $this->supa(
			'GET',
			'cyber_deck?id=eq.' . rawurlencode( $id ) . '&select=*'
		);

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['error'] ?? 'Failed to fetch card.' );
			return;
		}

		$data = $result['data'] ?? [];
		wp_send_json_success( is_array( $data ) ? ( $data[0] ?? null ) : null );
	}

	public function ajax_save(): void {
		check_ajax_referer( 'neoweaver_deck', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id   = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( ! $name ) {
			wp_send_json_error( 'Name is required.' );
			return;
		}

		if ( $id && ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$category = sanitize_text_field( wp_unslash( $_POST['deck_category'] ?? 'action' ) );
		$rarity   = sanitize_text_field( wp_unslash( $_POST['rarity'] ?? 'common' ) );

		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			wp_send_json_error( 'Invalid category.' );
			return;
		}

		if ( ! in_array( $rarity, self::RARITIES, true ) ) {
			wp_send_json_error( 'Invalid rarity.' );
			return;
		}

		$payload = [
			'name'                    => $name,
			'description'             => $this->maybe_null_textarea( wp_unslash( $_POST['description'] ?? '' ) ),
			'deck_category'           => $category,
			'type'                    => $this->maybe_null_text( wp_unslash( $_POST['type'] ?? '' ) ) ?: 'action',
			'mechanic'                => $this->maybe_null_text( wp_unslash( $_POST['mechanic'] ?? '' ) ),
			'mechanic_goal'           => $this->maybe_null_text( wp_unslash( $_POST['mechanic_goal'] ?? '' ) ),
			'cost_label'              => $this->maybe_null_text( wp_unslash( $_POST['cost_label'] ?? '' ) ),
			'cost_number'             => max( 0, intval( wp_unslash( $_POST['cost_number'] ?? 0 ) ) ),
			'effect'                  => $this->maybe_null_textarea( wp_unslash( $_POST['effect'] ?? '' ) ),
			'bonus'                   => $this->parse_json_object_field( wp_unslash( $_POST['bonus'] ?? '' ) ),
			'ai_instruction'          => $this->maybe_null_textarea( wp_unslash( $_POST['ai_instruction'] ?? '' ) ),
			'gm'                      => $this->maybe_null_textarea( wp_unslash( $_POST['gm'] ?? '' ) ),
			'tags'                    => $this->parse_json_array_field( wp_unslash( $_POST['tags'] ?? '' ) ),
			'requirement_tags'        => $this->parse_json_array_field( wp_unslash( $_POST['requirement_tags'] ?? '' ) ),
			'denied_tags'             => $this->parse_json_array_field( wp_unslash( $_POST['denied_tags'] ?? '' ) ),
			'required_item_tags'      => $this->parse_json_array_field( wp_unslash( $_POST['required_item_tags'] ?? '' ) ),
			'required_location_tags'  => $this->parse_json_array_field( wp_unslash( $_POST['required_location_tags'] ?? '' ) ),
			'denied_location_tags'    => $this->parse_json_array_field( wp_unslash( $_POST['denied_location_tags'] ?? '' ) ),
			'requirement_description' => $this->maybe_null_textarea( wp_unslash( $_POST['requirement_description'] ?? '' ) ),
			'time_cost_minutes'       => max( 0, intval( wp_unslash( $_POST['time_cost_minutes'] ?? 0 ) ) ),
			'cooldown_messages'       => max( 0, intval( wp_unslash( $_POST['cooldown_messages'] ?? 0 ) ) ),
			'entropy_on_fail'         => max( 0, intval( wp_unslash( $_POST['entropy_on_fail'] ?? 0 ) ) ),
			'rarity'                  => $rarity,
			'xp_current'              => max( 0, intval( wp_unslash( $_POST['xp_current'] ?? 0 ) ) ),
			'xp_to_next'              => max( 0, intval( wp_unslash( $_POST['xp_to_next'] ?? 10 ) ) ),
			'is_leveling'             => filter_var( wp_unslash( $_POST['is_leveling'] ?? false ), FILTER_VALIDATE_BOOLEAN ),
			'is_disposable'           => filter_var( wp_unslash( $_POST['is_disposable'] ?? false ), FILTER_VALIDATE_BOOLEAN ),
			'is_active'               => filter_var( wp_unslash( $_POST['is_active'] ?? true ), FILTER_VALIDATE_BOOLEAN ),
			'sound_effect'            => esc_url_raw( wp_unslash( $_POST['sound_effect'] ?? '' ) ) ?: null,
			'img_url'                 => esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) ) ?: null,
			'class_id'                => $this->maybe_uuid( wp_unslash( $_POST['class_id'] ?? '' ) ),
		];

		if ( $id ) {
			$result = $this->supa(
				'PATCH',
				'cyber_deck?id=eq.' . rawurlencode( $id ),
				$payload
			);
		} else {
			$result = $this->supa(
				'POST',
				'cyber_deck',
				$payload
			);
		}

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['error'] ?? 'Save failed.' );
			return;
		}

		$data = $result['data'] ?? [];
		wp_send_json_success( is_array( $data ) ? ( $data[0] ?? $data ) : $data );
	}

	public function ajax_toggle(): void {
		check_ajax_referer( 'neoweaver_deck', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id    = $this->get_uuid_from_post( 'id' );
		$state = filter_var( wp_unslash( $_POST['state'] ?? false ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$result = $this->supa(
			'PATCH',
			'cyber_deck?id=eq.' . rawurlencode( $id ),
			[ 'is_active' => $state ]
		);

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['error'] ?? 'Toggle failed.' );
			return;
		}

		wp_send_json_success( [ 'toggled' => true, 'state' => $state ] );
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'neoweaver_deck', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = $this->get_uuid_from_post( 'id' );

		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$result = $this->supa(
			'DELETE',
			'cyber_deck?id=eq.' . rawurlencode( $id )
		);

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['error'] ?? 'Delete failed.' );
			return;
		}

		wp_send_json_success( [ 'deleted' => true ] );
	}
}
