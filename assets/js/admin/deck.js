/* NeoWeaver Admin — Deck Cards JS */
(function($){
'use strict';

const nonce  = $('#nw-nonce').val();
const ajax   = window.ajaxurl || '';
let   cards  = [];   // all cards returned from server
let   editId = null;

const TAG_FIELDS = ['tags','requirement_tags','denied_tags','required_item_tags','required_location_tags','denied_location_tags'];

/* ---- helpers -------------------------------------------------------- */
function notice(msg, type='success'){
    const $n = $('#nw-notice');
    $n.removeClass('nw-notice-success nw-notice-error')
      .addClass('nw-notice-' + type)
      .html(msg).show();
    if(type==='success') setTimeout(()=>$n.fadeOut(),3000);
}

function esc(str){
    return String(str == null ? '' : str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function formatTags(val){
    if(!val) return '';
    const arr = Array.isArray(val) ? val : (typeof val==='string' ? val.split(',').map(s=>s.trim()).filter(Boolean) : []);
    return arr.map(t=>`<span class="nw-tag">${esc(t)}</span>`).join('');
}

function rarityClass(r){ return 'nw-rarity-'+(r||'common'); }

function catClass(c){
    const map={action:'nw-cat-action',magic:'nw-cat-magic',equipment:'nw-cat-equipment'};
    return map[c]||'';
}

function tagsToString(val){
    if(!val) return '';
    return Array.isArray(val) ? val.join(', ') : val;
}

/* ---- load all cards from server (category/rarity/type filtered) ----- */
function loadCards(){
    const cat    = $('#nw-filter-category').val();
    const rarity = $('#nw-filter-rarity').val();
    const type   = $('#nw-filter-type').val();
    $('#nw-deck-tbody').html('<tr class="nw-loading-row"><td colspan="9"><div class="nw-spinner"></div> Loading cards&hellip;</td></tr>');
    $.post(ajax,{action:'nw_deck_get_all',nonce,filter_category:cat,filter_rarity:rarity,filter_type:type},function(res){
        if(!res.success){ notice(res.data||'Load error','error'); return; }
        cards = res.data||[];
        renderTable();
    });
}

/* ---- client-side search filter (runs on already-loaded cards) ------- */
function getSearchQuery(){
    return $('#nw-search').val().trim().toLowerCase();
}

function matchesSearch(c, q){
    if(!q) return true;
    const haystack = [
        c.name, c.type, c.deck_category, c.rarity, c.mechanic,
        c.description, c.effect,
        Array.isArray(c.tags) ? c.tags.join(' ') : (c.tags||'')
    ].join(' ').toLowerCase();
    return haystack.includes(q);
}

function renderTable(){
    const q       = getSearchQuery();
    const visible = cards.filter(c => matchesSearch(c, q));

    const active   = cards.filter(c=>c.is_active).length;
    const inactive = cards.length - active;
    $('#nw-total').text(cards.length);
    $('#nw-active').text(active);
    $('#nw-inactive').text(inactive);

    // show "Showing X" pill only when search is active
    if(q){
        $('#nw-filtered').text(visible.length);
        $('#nw-filtered-pill').show();
    } else {
        $('#nw-filtered-pill').hide();
    }

    if(!visible.length){
        const msg = q ? 'No cards match your search.' : 'No cards found.';
        $('#nw-deck-tbody').html(`<tr><td colspan="9" style="text-align:center;color:#555;padding:30px;">${msg}</td></tr>`);
        return;
    }

    const rows = visible.map(c=>{
        const img = c.img_url
            ? `<img class="nw-card-img" src="${esc(c.img_url)}" alt="">`
            : `<div class="nw-card-img-placeholder">&#127183;</div>`;
        const tags = formatTags(c.tags);
        const cost = c.cost_number ? `<span class="nw-cost-label">${esc(c.cost_label||'Cost')}:</span> ${c.cost_number}` : '&mdash;';
        const time = c.time_cost_minutes ? `${c.time_cost_minutes}m` : '&mdash;';
        return `<tr>
            <td>${img}</td>
            <td><div class="nw-item-name">${esc(c.name)}</div></td>
            <td><span class="nw-type-badge ${catClass(c.deck_category)}">${esc(c.deck_category||'')}</span></td>
            <td><span class="nw-type-label">${esc(c.type||'&mdash;')}</span></td>
            <td><div class="nw-tags">${tags}</div></td>
            <td><span class="${rarityClass(c.rarity)}">${esc(c.rarity||'common')}</span><br><span class="nw-level-badge">Lv ${c.level||1}</span></td>
            <td><div class="nw-cost-info">${cost}</div><div class="nw-item-sub">${time}</div></td>
            <td><div class="nw-toggle-wrap"><label class="nw-toggle">
                <input type="checkbox" class="nw-toggle-active" data-id="${esc(c.id)}" ${c.is_active?'checked':''}>
                <span class="nw-toggle-slider"></span></label></div></td>
            <td><button class="nw-action-btn nw-edit-btn" data-id="${esc(c.id)}">&#9998; Edit</button></td>
        </tr>`;
    }).join('');
    $('#nw-deck-tbody').html(rows);
}

/* ---- search: live filter on keyup ----------------------------------- */
$(document).on('input','#nw-search', renderTable);

/* ---- toggle active -------------------------------------------------- */
$(document).on('change','.nw-toggle-active',function(){
    const id      = $(this).data('id');
    const active  = $(this).prop('checked');
    $.post(ajax,{action:'nw_deck_toggle',nonce,card_id:id,is_active:active?1:0},function(res){
        if(!res.success){ notice(res.data||'Error','error'); loadCards(); }
        else { notice('Card ' + (active?'activated':'deactivated') + '.'); }
    });
});

/* ---- open modal ----------------------------------------------------- */
function openModal(card){
    editId = card ? card.id : null;
    $('#nw-modal-title').text(card ? 'Edit Card' : 'New Card');
    $('#nw-delete-btn').toggle(!!card);
    $('#nw-deck-form')[0].reset();
    $('#nw-img-preview-wrap').hide();
    $('#nw-sound-wrap').hide();

    if(card){
        $.each(card,(k,v)=>{
            const $f = $('#nw-field-'+k);
            if(!$f.length) return;
            if($f.is(':checkbox')) $f.prop('checked', !!v);
            else if(TAG_FIELDS.includes(k)) $f.val(tagsToString(v));
            else if(k==='bonus') $f.val(v && Object.keys(v).length ? JSON.stringify(v) : '');
            else $f.val(v||'');
        });
        if(card.img_url){ $('#nw-img-preview').attr('src',card.img_url); $('#nw-img-preview-wrap').show(); }
        if(card.sound_effect){ $('#nw-audio-preview').attr('src',card.sound_effect); $('#nw-sound-wrap').show(); }
    }
    $('#nw-modal-overlay').show();
}

$(document).on('click','#nw-add-btn',    ()=>openModal(null));
$(document).on('click','.nw-edit-btn',   function(){
    const c = cards.find(x=>x.id===$(this).data('id'));
    if(c) openModal(c);
});

/* ---- close modal ---------------------------------------------------- */
function closeModal(){ $('#nw-modal-overlay').hide(); editId=null; }
$(document).on('click','#nw-modal-close, #nw-cancel-btn', closeModal);
$(document).on('click','#nw-modal-overlay',function(e){
    if($(e.target).is('#nw-modal-overlay')) closeModal();
});

/* ---- image/audio preview -------------------------------------------- */
$(document).on('input','#nw-field-img_url',function(){
    const v=$(this).val().trim();
    if(v){ $('#nw-img-preview').attr('src',v); $('#nw-img-preview-wrap').show(); }
    else  { $('#nw-img-preview-wrap').hide(); }
});
$(document).on('input','#nw-field-sound_effect',function(){
    const v=$(this).val().trim();
    if(v){ $('#nw-audio-preview').attr('src',v); $('#nw-sound-wrap').show(); }
    else  { $('#nw-sound-wrap').hide(); }
});

/* ---- save ----------------------------------------------------------- */
$(document).on('click','#nw-save-btn',function(){
    const $btn=$(this);
    const data={id:editId||''};
    $('#nw-deck-form').serializeArray().forEach(f=>{ data[f.name]=f.value; });
    ['is_leveling','is_disposable','is_active'].forEach(n=>{
        data[n]=$('#nw-field-'+n).prop('checked')?'1':'0';
    });
    $btn.prop('disabled',true).text('Saving...');
    $.post(ajax,{action:'nw_deck_save',nonce,card:data},function(res){
        $btn.prop('disabled',false).text('Save Card');
        if(!res.success){ notice(res.data||'Save error','error'); return; }
        notice('Card saved!');
        closeModal();
        loadCards();
    });
});

/* ---- delete --------------------------------------------------------- */
$(document).on('click','#nw-delete-btn',function(){
    if(!editId) return;
    if(!confirm('Delete this card? This cannot be undone.')) return;
    $.post(ajax,{action:'nw_deck_delete',nonce,card_id:editId},function(res){
        if(!res.success){ notice(res.data||'Delete error','error'); return; }
        notice('Card deleted.');
        closeModal();
        loadCards();
    });
});

/* ---- server-side filters (category/rarity/type) trigger reload ------ */
$(document).on('change','#nw-filter-category, #nw-filter-rarity, #nw-filter-type', loadCards);
$(document).on('click','#nw-refresh-btn', loadCards);

/* ---- init ----------------------------------------------------------- */
$(document).ready(loadCards);

})(jQuery);
