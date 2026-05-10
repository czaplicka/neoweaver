/**
 * NeoWeaver Admin — Seasons Config
 * Extracted from class-nw-seasons-admin.php
 *
 * Expects globals injected via wp_localize_script('nw-seasons-admin'):
 *   nwSeasonsData.nonce, nwSeasonsData.ajax, nwSeasonsData.weights
 */
(function($){
	const NONCE   = nwSeasonsData.nonce;
	const AJAX    = nwSeasonsData.ajax;
	const WEIGHTS = nwSeasonsData.weights; // { weight_sun:"Sun", … }
	const W_KEYS  = Object.keys(WEIGHTS);

	/* colour map for mini table bar */
	const W_COLORS = {
		weight_sun:'#ffd700', weight_cloudy:'#9e9e9e',
		weight_rain:'#4fc3f7', weight_fog:'#b0bec5',
		weight_storm:'#7e57c2', weight_snow:'#e0f7fa'
	};

	function post(action, data){
		return $.post(AJAX, Object.assign({action, nonce:NONCE}, data));
	}
	const esc = s => $('<span>').text(s).html();

	/* ── load list ─────────────────────────────────── */
	function loadList(){
		$('#nw-season-table-wrap').html('<div class="nw-spinner" style="margin:40px auto;display:block;"></div>');
		post('nw_season_list').done(function(res){
			if(!res.success){ $('#nw-season-table-wrap').html('<p style="color:#ff6b6b;">'+esc(res.data)+'</p>'); return; }
			renderTable(res.data);
		}).fail(function(){
			$('#nw-season-table-wrap').html('<p style="color:#ff6b6b;">Request failed.</p>');
		});
	}

	function renderTable(rows){
		if(!rows.length){
			$('#nw-season-table-wrap').html('<div class="nw-empty"><p>No seasons configured yet.</p><p style="font-size:.8rem">Add one with the button above.</p></div>');
			return;
		}
		let html = '<table class="nw-season-table"><thead><tr>'
			+'<th>Name</th><th>Icon</th><th>Color</th><th>Temp ×</th><th>Sort</th><th>Weather Distribution</th><th>Actions</th>'
			+'</tr></thead><tbody>';
		rows.forEach(r => {
			const dot = r.color ? `<span class="nw-color-dot" style="background:${esc(r.color)};"></span>` : '';
			const icon = r.icon ? `<span style="font-size:1.2rem">${esc(r.icon)}</span>` : '—';

			/* mini bar */
			let miniBar = '<div class="nw-mini-bar">';
			W_KEYS.forEach(k => {
				const w = r[k] || 0;
				if(w > 0) miniBar += `<div class="nw-mini-seg" style="width:${w}%;background:${W_COLORS[k]};" title="${WEIGHTS[k]}: ${w}%"></div>`;
			});
			miniBar += '</div>';
			const sumLabel = W_KEYS.reduce((s,k)=>s+(r[k]||0),0);
			miniBar += `<span style="font-size:.7rem;color:var(--nw-muted);margin-left:6px;">${sumLabel}%</span>`;

			html += `<tr>
				<td><strong>${esc(r.season_name)}</strong></td>
				<td>${icon}</td>
				<td>${dot}${r.color ? esc(r.color) : '<span style="color:var(--nw-muted)">—</span>'}</td>
				<td>${r.temp_modifier}</td>
				<td style="color:var(--nw-muted)">${r.sort_order ?? 0}</td>
				<td><div style="display:flex;align-items:center;">${miniBar}</div></td>
				<td><div class="nw-tbl-actions">
					<button class="nw-btn nw-btn-ghost nw-btn-xs nw-edit-btn" data-name="${esc(r.season_name)}">Edit</button>
					<button class="nw-btn nw-btn-danger nw-btn-xs nw-delete-btn" data-name="${esc(r.season_name)}">Delete</button>
				</div></td>
			</tr>`;
		});
		html += '</tbody></table>';
		$('#nw-season-table-wrap').html(html);
	}

	/* ── weight live update ─────────────────────────── */
	function updateWeightUI(){
		let sum = 0;
		W_KEYS.forEach(k => {
			const val = parseInt($('#nw-'+k).val(),10)||0;
			sum += val;
			$('#nw-'+k+'-pct').text(val+'%');
			$('#nw-'+k+'-range').val(val);
			$('#nw-bar-'+k.replace('weight_','')).css('width', val+'%');
		});
		$('#nw-weights-sum').text(sum);
		const badge = $('#nw-weights-sum-badge');
		badge.toggleClass('ok', sum===100).toggleClass('bad', sum!==100);
		$('#nw-season-save-btn').prop('disabled', sum!==100);
	}

	/* sync range → number */
	$(document).on('input','.nw-weight-range',function(){
		const target = $(this).data('target');
		$('#'+target).val($(this).val());
		updateWeightUI();
	});
	$(document).on('input','.nw-weight-num',function(){
		const id = $(this).attr('id');
		$('#'+id+'-range').val($(this).val());
		updateWeightUI();
	});

	/* color picker sync */
	$(document).on('input','#nw-season-color-picker',function(){
		$('#nw-season-color').val($(this).val());
	});
	$(document).on('input','#nw-season-color',function(){
		const v = $(this).val();
		if(/^#[0-9a-fA-F]{6}$/.test(v)) $('#nw-season-color-picker').val(v);
	});

	/* ── modal helpers ──────────────────────────────── */
	function openModal(title){
		$('#nw-season-modal-title').text(title);
		$('#nw-season-save-error').text('');
		$('#nw-season-modal').show();
		$('#nw-season-name').focus();
		updateWeightUI();
	}
	function closeModal(){
		$('#nw-season-modal').hide();
		$('#nw-season-form')[0].reset();
		$('#nw-season-is-edit').val('0');
		$('#nw-season-orig-name').val('');
		updateWeightUI();
	}

	function populateForm(r){
		$('#nw-season-name').val(r.season_name);
		$('#nw-season-orig-name').val(r.season_name);
		$('#nw-season-is-edit').val('1');
		$('#nw-season-desc').val(r.description||'');
		$('#nw-season-temp').val(r.temp_modifier);
		$('#nw-season-color').val(r.color||'');
		if(r.color && /^#[0-9a-fA-F]{6}$/.test(r.color)) $('#nw-season-color-picker').val(r.color);
		$('#nw-season-icon').val(r.icon||'');
		$('#nw-season-sort').val(r.sort_order??0);
		W_KEYS.forEach(k => {
			$('#nw-'+k).val(r[k]??0);
			$('#nw-'+k+'-range').val(r[k]??0);
		});
		updateWeightUI();
	}

	function formToData(){
		const data = {
			season_name:      $('#nw-season-name').val(),
			orig_season_name: $('#nw-season-orig-name').val(),
			is_edit:          $('#nw-season-is-edit').val(),
			description:      $('#nw-season-desc').val(),
			temp_modifier:    $('#nw-season-temp').val(),
			color:            $('#nw-season-color').val(),
			icon:             $('#nw-season-icon').val(),
			sort_order:       $('#nw-season-sort').val(),
		};
		W_KEYS.forEach(k => { data[k] = $('#nw-'+k).val(); });
		return data;
	}

	/* ── events ─────────────────────────────────────── */
	$(document)
		.on('click','#nw-season-add-btn',function(){
			closeModal();
			openModal('Add Season');
		})
		.on('click','#nw-season-modal-close, #nw-season-cancel-btn', closeModal)
		.on('click','#nw-season-modal',function(e){ if($(e.target).is('#nw-season-modal')) closeModal(); })
		.on('keydown',function(e){ if(e.key==='Escape') closeModal(); })

		.on('click','.nw-edit-btn',function(){
			const name = $(this).data('name');
			post('nw_season_get',{season_name:name}).done(function(res){
				if(!res.success){ alert('Could not load season.'); return; }
				populateForm(res.data);
				openModal('Edit Season');
			});
		})

		.on('click','.nw-delete-btn',function(){
			const name = $(this).data('name');
			if(!confirm('Delete season "'+name+'"? This cannot be undone.')) return;
			post('nw_season_delete',{season_name:name}).done(function(res){
				if(!res.success){ alert('Delete failed.'); return; }
				loadList();
			});
		})

		.on('submit','#nw-season-form',function(e){
			e.preventDefault();
			$('#nw-season-save-btn').prop('disabled',true).text('Saving…');
			$('#nw-season-save-error').text('');
			post('nw_season_save', formToData())
				.done(function(res){
					if(!res.success){
						$('#nw-season-save-error').text(res.data||'Save failed.');
						$('#nw-season-save-btn').prop('disabled',false).text('Save Season');
						return;
					}
					closeModal();
					loadList();
				})
				.fail(function(){
					$('#nw-season-save-error').text('Request failed.');
					$('#nw-season-save-btn').prop('disabled',false).text('Save Season');
				});
		});

	/* init */
	$(document).ready(function(){ loadList(); });

})(jQuery);
