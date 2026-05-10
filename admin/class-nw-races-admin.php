
<?php
/**
 * NeoWeaver Admin Panel — Races (cyber_races)
 * Kolumny: id, name, parent_race, tags, gm_instructions, description,
 *          race_base_hp, img_url, preferred_tech, preferred_magic,
 *          base_mana, base_stamina, is_active, world_id
 *
 * Akcje AJAX:
 *   nw_get_races          – lista (z filtrem world_id, search, is_active)
 *   nw_get_race           – pojedynczy rekord
 *   nw_save_race          – zapis (POST / PATCH)
 *   nw_toggle_race        – przełącz is_active
 *   nw_delete_race        – usuń rekord
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NW_Races_Admin extends NW_Admin_Base {

	/* ───────────────────────────────── boot ── */

	public function __construct() {
		parent::__construct();
		$this->register_ajax( 'nw_get_races',   'ajax_get_races'  );
		$this->register_ajax( 'nw_get_race',    'ajax_get_race'   );
		$this->register_ajax( 'nw_save_race',   'ajax_save_race'  );
		$this->register_ajax( 'nw_toggle_race', 'ajax_toggle_race');
		$this->register_ajax( 'nw_delete_race', 'ajax_delete_race');
	}

	/* ───────────────────────────── ajax: lista ── */

	public function ajax_get_races() {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$world_id  = sanitize_text_field( $_POST['world_id']  ?? '' );
		$search    = sanitize_text_field( $_POST['search']    ?? '' );
		$is_active = $_POST['is_active'] ?? '';
		$page      = max( 1, intval( $_POST['page'] ?? 1 ) );
		$per_page  = intval( $_POST['per_page'] ?? 20 );
		$offset    = ( $page - 1 ) * $per_page;

		$qs = 'cyber_races?select=*&order=name.asc';

		if ( $world_id  ) $qs .= '&world_id=eq.'  . rawurlencode( $world_id );
		if ( $search    ) $qs .= '&name=ilike.*'  . rawurlencode( $search ) . '*';
		if ( $is_active !== '' ) $qs .= '&is_active=eq.' . ( $is_active ? 'true' : 'false' );

		$qs .= '&limit=' . $per_page . '&offset=' . $offset;

		$res = $this->supa( 'GET', $qs );
		wp_send_json_success( $res );
	}

	/* ──────────────────────────── ajax: single ── */

	public function ajax_get_race() {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
		$id = sanitize_text_field( $_POST['id'] ?? '' );
		if ( ! $id ) {
			wp_send_json_error( 'Missing id' );
			return;
		}

		$res = $this->supa( 'GET', 'cyber_races?id=eq.' . rawurlencode( $id ) . '&select=*' );
		wp_send_json_success( $res[0] ?? null );
	}

	/* ───────────────────────────── ajax: save ── */

	public function ajax_save_race() {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$id = sanitize_text_field( $_POST['id'] ?? '' );

		// Parse tags JSON
		$tags_raw = $_POST['tags'] ?? '[]';
		$tags = is_array( $tags_raw )
			? array_map( 'sanitize_text_field', $tags_raw )
			: json_decode( stripslashes( $tags_raw ), true ) ?? [];

		$payload = [
			'name'            => sanitize_text_field( $_POST['name']            ?? '' ),
			'parent_race'     => sanitize_text_field( $_POST['parent_race']     ?? '' ) ?: null,
			'description'     => sanitize_textarea_field( $_POST['description'] ?? '' ),
			'gm_instructions' => sanitize_textarea_field( $_POST['gm_instructions'] ?? '' ),
			'img_url'         => esc_url_raw( $_POST['img_url']                 ?? '' ),
			'race_base_hp'    => intval( $_POST['race_base_hp']                 ?? 0 ),
			'base_mana'       => intval( $_POST['base_mana']                    ?? 0 ),
			'base_stamina'    => intval( $_POST['base_stamina']                 ?? 0 ),
			'preferred_tech'  => sanitize_text_field( $_POST['preferred_tech']  ?? '' ),
			'preferred_magic' => sanitize_text_field( $_POST['preferred_magic'] ?? '' ),
			'world_id'        => sanitize_text_field( $_POST['world_id']        ?? '' ) ?: null,
			'tags'            => $tags,
			'is_active'       => filter_var( $_POST['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN ),
		];

		$res = $id
			? $this->supa( 'PATCH', 'cyber_races?id=eq.' . rawurlencode( $id ), $payload )
			: $this->supa( 'POST',  'cyber_races', $payload );

		wp_send_json_success( $res );
	}

	/* ──────────────────────────── ajax: toggle ── */

	public function ajax_toggle_race() {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
		$id    = sanitize_text_field( $_POST['id']    ?? '' );
		$state = filter_var( $_POST['state'] ?? false, FILTER_VALIDATE_BOOLEAN );

		if ( ! $id ) {
			wp_send_json_error( 'Missing id' );
			return;
		}

		$res = $this->supa( 'PATCH', 'cyber_races?id=eq.' . rawurlencode( $id ), [ 'is_active' => $state ] );
		wp_send_json_success( $res );
	}

	/* ──────────────────────────── ajax: delete ── */

	public function ajax_delete_race() {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
		$id = sanitize_text_field( $_POST['id'] ?? '' );
		if ( ! $id ) {
			wp_send_json_error( 'Missing id' );
			return;
		}

		$res = $this->supa( 'DELETE', 'cyber_races?id=eq.' . rawurlencode( $id ), [], [ 'Prefer' => '' ] );
		wp_send_json_success( $res );
	}

	/* ─────────────────────────────── render ── */

	public function render() {
		$this->enqueue_assets();
		?>
		<div class="wrap nw-admin-wrap">
			<h1><?php esc_html_e( 'NeoWeaver — Races', 'neoweaver' ); ?></h1>

			<!-- FILTERS -->
			<div class="nw-filters" style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
				<input type="text"   id="nw-race-search"    placeholder="<?php esc_attr_e('Search name…','neoweaver'); ?>" style="width:200px;" class="regular-text">
				<select id="nw-race-world">
					<option value=""><?php esc_html_e('All worlds','neoweaver'); ?></option>
				</select>
				<select id="nw-race-active">
					<option value=""><?php esc_html_e('All statuses','neoweaver'); ?></option>
					<option value="1"><?php esc_html_e('Active','neoweaver'); ?></option>
					<option value="0"><?php esc_html_e('Inactive','neoweaver'); ?></option>
				</select>
				<button class="button" id="nw-race-search-btn"><?php esc_html_e('Filter','neoweaver'); ?></button>
				<button class="button button-primary" id="nw-race-add-btn"><?php esc_html_e('+ Add Race','neoweaver'); ?></button>
			</div>

			<!-- TABLE -->
			<table class="widefat nw-table" id="nw-races-table">
				<thead>
					<tr>
						<th><?php esc_html_e('Name','neoweaver'); ?></th>
						<th><?php esc_html_e('Parent','neoweaver'); ?></th>
						<th><?php esc_html_e('World','neoweaver'); ?></th>
						<th><?php esc_html_e('HP','neoweaver'); ?></th>
						<th><?php esc_html_e('Mana','neoweaver'); ?></th>
						<th><?php esc_html_e('Stamina','neoweaver'); ?></th>
						<th><?php esc_html_e('Tech','neoweaver'); ?></th>
						<th><?php esc_html_e('Magic','neoweaver'); ?></th>
						<th><?php esc_html_e('Tags','neoweaver'); ?></th>
						<th><?php esc_html_e('Active','neoweaver'); ?></th>
						<th><?php esc_html_e('Actions','neoweaver'); ?></th>
					</tr>
				</thead>
				<tbody id="nw-races-tbody">
					<tr><td colspan="11"><?php esc_html_e('Loading…','neoweaver'); ?></td></tr>
				</tbody>
			</table>
			<div id="nw-races-pagination" style="margin-top:10px;"></div>

			<!-- MODAL -->
			<div id="nw-race-modal" style="display:none;" class="nw-modal-overlay">
				<div class="nw-modal">
					<h2 id="nw-race-modal-title"><?php esc_html_e('Race','neoweaver'); ?></h2>
					<form id="nw-race-form">
						<input type="hidden" id="nw-race-id">

						<table class="form-table">
							<tr><th><label for="nw-race-name"><?php esc_html_e('Name *','neoweaver'); ?></label></th>
								<td><input type="text" id="nw-race-name" class="regular-text" required></td></tr>

							<tr><th><label for="nw-race-parent"><?php esc_html_e('Parent Race','neoweaver'); ?></label></th>
								<td><input type="text" id="nw-race-parent" class="regular-text" placeholder="<?php esc_attr_e('e.g. Human','neoweaver'); ?>"></td></tr>

							<tr><th><label for="nw-race-world-sel"><?php esc_html_e('World','neoweaver'); ?></label></th>
								<td><select id="nw-race-world-sel" class="regular-text">
									<option value=""><?php esc_html_e('— global —','neoweaver'); ?></option>
								</select></td></tr>

							<tr><th><label for="nw-race-description"><?php esc_html_e('Description','neoweaver'); ?></label></th>
								<td><textarea id="nw-race-description" rows="3" class="large-text"></textarea></td></tr>

							<tr><th><label for="nw-race-gm"><?php esc_html_e('GM Instructions','neoweaver'); ?></label></th>
								<td><textarea id="nw-race-gm" rows="3" class="large-text"></textarea></td></tr>

							<tr><th><label for="nw-race-img"><?php esc_html_e('Image URL','neoweaver'); ?></label></th>
								<td><input type="url" id="nw-race-img" class="large-text"></td></tr>

							<tr><th><label><?php esc_html_e('Stats','neoweaver'); ?></label></th>
								<td style="display:flex;gap:10px;flex-wrap:wrap;">
									<label><?php esc_html_e('Base HP','neoweaver'); ?><br>
										<input type="number" id="nw-race-hp" style="width:80px;" min="0" value="0"></label>
									<label><?php esc_html_e('Base Mana','neoweaver'); ?><br>
										<input type="number" id="nw-race-mana" style="width:80px;" min="0" value="0"></label>
									<label><?php esc_html_e('Base Stamina','neoweaver'); ?><br>
										<input type="number" id="nw-race-stamina" style="width:80px;" min="0" value="0"></label>
								</td></tr>

							<tr><th><label for="nw-race-tech"><?php esc_html_e('Preferred Tech','neoweaver'); ?></label></th>
								<td><input type="text" id="nw-race-tech" class="regular-text"></td></tr>

							<tr><th><label for="nw-race-magic"><?php esc_html_e('Preferred Magic','neoweaver'); ?></label></th>
								<td><input type="text" id="nw-race-magic" class="regular-text"></td></tr>

							<tr><th><label for="nw-race-tags"><?php esc_html_e('Tags (comma-separated)','neoweaver'); ?></label></th>
								<td><input type="text" id="nw-race-tags" class="large-text"
								placeholder="<?php esc_attr_e('tag1, tag2, tag3','neoweaver'); ?>"></td></tr>

							<tr><th><label for="nw-race-active-chk"><?php esc_html_e('Active','neoweaver'); ?></label></th>
								<td><input type="checkbox" id="nw-race-active-chk" checked></td></tr>
						</table>

						<p>
							<button type="submit" class="button button-primary"><?php esc_html_e('Save','neoweaver'); ?></button>
							<button type="button" class="button" id="nw-race-cancel"><?php esc_html_e('Cancel','neoweaver'); ?></button>
						</p>
					</form>
				</div>
			</div><!-- /modal -->
		</div><!-- /wrap -->

		<script>
		(function($){
			const nonce   = '<?php echo wp_create_nonce("nw_nonce"); ?>';
			const ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';

			let currentPage = 1;
			const perPage   = 20;

			/* ── worlds dropdown ── */
			function loadWorldsDropdown( selectId, selectedVal ) {
				$.post( ajaxUrl, { action:'nw_get_nodes', nonce }, function(r){
					if ( ! r.success ) return;
					const $s = $( selectId ).empty().append('<option value="">— global —</option>');
					( r.data || [] ).forEach( w => {
						$s.append( `<option value="${w.id}" ${w.id==selectedVal?'selected':''}>${$('<span>').text(w.name).html()}</option>` );
					});
				});
			}

			loadWorldsDropdown( '#nw-race-world',     '' );
			loadWorldsDropdown( '#nw-race-world-sel', '' );

			/* ── load list ── */
			function loadRaces( page ) {
				currentPage = page || 1;
				$.post( ajaxUrl, {
					action    : 'nw_get_races',
					nonce,
					search    : $( '#nw-race-search'  ).val(),
					world_id  : $( '#nw-race-world'   ).val(),
					is_active : $( '#nw-race-active'  ).val(),
					page      : currentPage,
					per_page  : perPage,
				}, function(r){
					const $tb = $( '#nw-races-tbody' ).empty();
					if ( ! r.success || ! r.data.length ) {
						$tb.html( '<tr><td colspan="11"><?php esc_html_e("No races found.","neoweaver"); ?></td></tr>' );
						return;
					}
					r.data.forEach( race => {
						const tags = Array.isArray(race.tags) ? race.tags.join(', ') : (race.tags||'');
						$tb.append(`
							<tr data-id="${race.id}">
								<td><strong>${$('<span>').text(race.name).html()}</strong></td>
								<td>${$('<span>').text(race.parent_race||'').html()}</td>
								<td>${$('<span>').text(race.world_id||'').html()}</td>
								<td>${race.race_base_hp||0}</td>
								<td>${race.base_mana||0}</td>
								<td>${race.base_stamina||0}</td>
								<td>${$('<span>').text(race.preferred_tech||'').html()}</td>
								<td>${$('<span>').text(race.preferred_magic||'').html()}</td>
								<td><small>${$('<span>').text(tags).html()}</small></td>
								<td>
									<button class="button nw-toggle" data-id="${race.id}" data-state="${race.is_active?1:0}">
										${race.is_active ? '✅' : '⛔'}
									</button>
								</td>
								<td>
									<button class="button nw-edit" data-id="${race.id}"><?php esc_html_e('Edit','neoweaver'); ?></button>
									<button class="button nw-delete" data-id="${race.id}"><?php esc_html_e('Delete','neoweaver'); ?></button>
								</td>
							</tr>`);
					});
					renderPagination( r.data.length );
				});
			}

			function renderPagination( count ) {
				const $p = $( '#nw-races-pagination' ).empty();
				if ( currentPage > 1 ) $p.append(`<button class="button nw-prev">← <?php esc_html_e('Prev','neoweaver'); ?></button> `);
				$p.append(`<?php esc_html_e('Page','neoweaver'); ?> ${currentPage}`);
				if ( count >= perPage ) $p.append(` <button class="button nw-next"><?php esc_html_e('Next','neoweaver'); ?> →</button>`);
			}

			$( '#nw-races-pagination' ).on( 'click', '.nw-prev', () => loadRaces( currentPage - 1 ) );
			$( '#nw-races-pagination' ).on( 'click', '.nw-next', () => loadRaces( currentPage + 1 ) );

			loadRaces(1);

			/* ── filter ── */
			$( '#nw-race-search-btn' ).on( 'click', () => loadRaces(1) );
			$( '#nw-race-search' ).on( 'keypress', e => { if(e.which===13) loadRaces(1); } );

			/* ── open add modal ── */
			$( '#nw-race-add-btn' ).on( 'click', function(){
				$( '#nw-race-modal-title' ).text( '<?php esc_html_e('Add Race','neoweaver'); ?>' );
				$( '#nw-race-form' )[0].reset();
				$( '#nw-race-id' ).val('');
				$( '#nw-race-active-chk' ).prop('checked', true);
				loadWorldsDropdown( '#nw-race-world-sel', '' );
				$( '#nw-race-modal' ).show();
			});

			/* ── edit ── */
			$( '#nw-races-tbody' ).on( 'click', '.nw-edit', function(){
				const id = $( this ).data('id');
				$.post( ajaxUrl, { action:'nw_get_race', nonce, id }, function(r){
					if ( ! r.success ) return alert(r.data);
					const d = r.data;
					$( '#nw-race-modal-title' ).text('<?php esc_html_e('Edit Race','neoweaver'); ?>');
					$( '#nw-race-id'          ).val( d.id );
					$( '#nw-race-name'        ).val( d.name );
					$( '#nw-race-parent'      ).val( d.parent_race||'' );
					$( '#nw-race-description' ).val( d.description||'' );
					$( '#nw-race-gm'          ).val( d.gm_instructions||'' );
					$( '#nw-race-img'         ).val( d.img_url||'' );
					$( '#nw-race-hp'          ).val( d.race_base_hp||0 );
					$( '#nw-race-mana'        ).val( d.base_mana||0 );
					$( '#nw-race-stamina'     ).val( d.base_stamina||0 );
					$( '#nw-race-tech'        ).val( d.preferred_tech||'' );
					$( '#nw-race-magic'       ).val( d.preferred_magic||'' );
					$( '#nw-race-active-chk'  ).prop('checked', !!d.is_active );

					const tagsStr = Array.isArray(d.tags) ? d.tags.join(', ') : (d.tags||'');
					$( '#nw-race-tags' ).val( tagsStr );

					loadWorldsDropdown( '#nw-race-world-sel', d.world_id||'' );
					$( '#nw-race-modal' ).show();
				});
			});

			/* ── save ── */
			$( '#nw-race-form' ).on( 'submit', function(e){
				e.preventDefault();
				const tagsRaw = $( '#nw-race-tags' ).val().split(',').map(t=>t.trim()).filter(Boolean);
				$.post( ajaxUrl, {
					action           : 'nw_save_race',
					nonce,
					id               : $( '#nw-race-id'          ).val(),
					name             : $( '#nw-race-name'        ).val(),
					parent_race      : $( '#nw-race-parent'      ).val(),
					description      : $( '#nw-race-description' ).val(),
					gm_instructions  : $( '#nw-race-gm'          ).val(),
					img_url          : $( '#nw-race-img'         ).val(),
					race_base_hp     : $( '#nw-race-hp'          ).val(),
					base_mana        : $( '#nw-race-mana'        ).val(),
					base_stamina     : $( '#nw-race-stamina'     ).val(),
					preferred_tech   : $( '#nw-race-tech'        ).val(),
					preferred_magic  : $( '#nw-race-magic'       ).val(),
					world_id         : $( '#nw-race-world-sel'   ).val(),
					tags             : JSON.stringify(tagsRaw),
					is_active        : $( '#nw-race-active-chk'  ).is(':checked') ? 1 : 0,
				}, function(r){
					if ( r.success ) { $( '#nw-race-modal' ).hide(); loadRaces( currentPage ); }
					else alert( r.data );
				});
			});

			$( '#nw-race-cancel' ).on( 'click', () => $( '#nw-race-modal' ).hide() );

			/* ── toggle active ── */
			$( '#nw-races-tbody' ).on( 'click', '.nw-toggle', function(){
				const $btn  = $(this);
				const id    = $btn.data('id');
				const state = $btn.data('state') == 1 ? 0 : 1;
				$.post( ajaxUrl, { action:'nw_toggle_race', nonce, id, state }, function(r){
					if ( r.success ) loadRaces( currentPage );
					else alert(r.data);
				});
			});

			/* ── delete ── */
			$( '#nw-races-tbody' ).on( 'click', '.nw-delete', function(){
				if ( ! confirm('<?php esc_html_e('Delete this race?','neoweaver'); ?>') ) return;
				const id = $(this).data('id');
				$.post( ajaxUrl, { action:'nw_delete_race', nonce, id }, function(r){
					if ( r.success ) loadRaces( currentPage );
					else alert(r.data);
				});
			});

		})(jQuery);
		</script>
		<?php
	}
}
