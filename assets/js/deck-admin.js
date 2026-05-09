/* NeoWeaver Admin — Deck Cards JS */
(function($){
'use strict';

const nonce  = $('#nw-nonce').val();
const ajax   = window.ajaxurl || '';
let   cards  = [];
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

function formatTags(val){
    if(!val) return '';
    const arr = Array.isArray(val) ? val : (typeof val==='string' ? val.split(',').map(s=>s.trim()).filter(Boolean) : []);
    return arr.map(t=>`<span class="nw-tag">${t}</span>`).join('');
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

/* ---- load all cards ------------------------------------------------- */
function loadCards(){
    const cat    = $('#nw-filter-category').val();
    const rarity = $('#nw-filter-rarity').val();
    $('#nw-deck-tbody').html('<tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading cards…</td></tr>');
    $.post(ajax,{action:'nw_deck_get_all',nonce,filter_category:cat,filter_rarity:rarity},function(res){
        if(!res.success){ notice(res.data||'Load error','error'); return; }
        cards = res.data||[];
        renderTable();
    });
}

function renderTable(){
    const active   = cards.filter(c=>c.is_active).length;
    const inactive = cards.length - active;
    $('#nw-total').text(cards.length);
    $('#nw-active').text(active);
    $('#nw-inactive').text(inactive);

    if(!cards.length){
        $('#nw-deck-tbody').html('<tr><td colspan="8" style="text-align:center;color:#555;padding:30px;">No cards found.</td></tr>');
        return;
    }

    const rows = cards.map(c=>{
        const img = c.img_url
            ? `<img class="nw-card-img" src="${c.img_url}" alt="">`
            : `<div class="nw-card-img-placeholder">🃏</div>`;
        const tags = formatTags(c.tags);
        const cost = c.cost_number ? `<span class="nw-cost-label">${c.cost_label||'Cost'}:</span> ${c.cost_number}` : '—';
        const time = c.time_cost_minutes ? `${c.time_cost_minutes}m` : '—';
        return `<tr>
            <td>${img}</td>
            <td><div class="nw-item-name">${c.name}</div><div class="nw-item-sub">${c.type||''}</div></td>
            <td><span class="nw-type-badge ${catClass(c.deck_category)}">${c.deck_category||''}</span></td>
            <td><div class="nw-tags">${tags}</div></td>
            <td><span class="${rarityClass(c.rarity)}">${c.rarity||'common'}</span><br><span class="nw-level-badge">Lv ${c.level||1}</span></td>
            <td><div class="nw-cost-info">${cost}</div><div class="nw-item-sub">${time}</div></td>
            <td><div class="nw-toggle-wrap"><label class="nw-toggle">
                <input type="checkbox" class="nw-toggle-active" data-id="${c.id}" ${c.is_active?'checked':''}>
                <span class="nw-toggle-slider"></span></label></div></td>
            <td><button class="nw-action-btn nw-edit-btn" data-id="${c.id}">✎ Edit</button></td>
        </tr>`;
    }).join('');
    $('#nw-deck-tbody').html(rows);
}

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

    if(card){
        $.each(card,(k,v)=>{
            const $f = $('#nw-field-'+k);
            if(!$f.length) return;
            if($f.is(':checkbox')) $f.prop('checked', !!v);
            else if(TAG_FIELDS.includes(k)) $f.val(tagsToString(v));
            else if(k==='bonus') $f.val(v && Object.keys(v).length ? JSON.stringify(v) : '');
            else $f.val(v||'');
        });
        // previews
        const imgUrl = card.img_url;
        if(imgUrl){ $('#nw-img-preview').attr('src',imgUrl); $('#nw-img-preview-wrap').show(); }
        const snd = card.sound_effect;
        if(snd){ $('#nw-audio-preview').attr('src',snd); $('#nw-sound-wrap').show(); }
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
    // checkboxes not serialised when unchecked
    ['is_leveling','is_disposable','is_active'].forEach(n=>{
        data[n]=$('#nw-field-'+n).prop('checked')?'1':'0';
    });

    $btn.prop('disabled',true).text('Saving…');
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

/* ---- filters & refresh ---------------------------------------------- */
$(document).on('change','#nw-filter-category, #nw-filter-rarity', loadCards);
$(document).on('click','#nw-refresh-btn', loadCards);

/* ---- init ----------------------------------------------------------- */
$(document).ready(loadCards);

})(jQuery);
