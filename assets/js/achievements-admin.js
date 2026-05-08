jQuery(function($){
    var ajaxurl=NWAch.ajaxurl, nonce=NWAch.nonce;
    var all=[];  // [FIX 3] Usunięto nieużywaną zmienną filtered

    function esc(s){return $('<span>').text(s||'').html();}
    function notice(msg,type){var el=$('#nw-notice');el.attr('class','nw-notice nw-notice-'+type).text(msg).show();setTimeout(function(){el.fadeOut(300);},3500);}

    // [FIX 6] Debounce helper
    function debounce(fn,delay){var t;return function(){clearTimeout(t);t=setTimeout(fn,delay);};}

    // [FIX 7] confirmModal zamiast natywnego confirm()
    function confirmModal(message, onConfirm){
        var overlay=$('<div class="nw-confirm-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;display:flex;align-items:center;justify-content:center;">'
            +'<div style="background:#1a1a2e;border:1px solid #adff00;border-radius:8px;padding:32px 28px;min-width:320px;text-align:center;">'
            +'<p style="color:#fff;margin-bottom:24px;font-family:\'Chakra Petch\',sans-serif;">'+esc(message)+'</p>'
            +'<button class="nw-confirm-yes nw-action-btn" style="margin-right:12px;">Delete</button>'
            +'<button class="nw-confirm-no nw-action-btn" style="background:#333;">Cancel</button>'
            +'</div></div>');
        $('body').append(overlay);
        overlay.find('.nw-confirm-yes').on('click',function(){overlay.remove();onConfirm();});
        overlay.find('.nw-confirm-no').on('click',function(){overlay.remove();});
        overlay.on('click',function(e){if($(e.target).is(overlay))overlay.remove();});
    }

    function isEmoji(s){
        if(!s) return false;
        var cp=s.codePointAt(0);
        return cp>127;
    }

    function renderIcon(slug){
        if(!slug||slug==='default_icon'||slug==='trophy') return '<i data-lucide="trophy"></i>';
        if(isEmoji(slug)) return '<span style="font-size:22px;line-height:1;">'+esc(slug)+'</span>';
        return '<i data-lucide="'+esc(slug)+'"></i>';
    }

    function renderIconPreview(slug){
        if(!slug||slug==='default_icon'||slug==='trophy') return '<i data-lucide="trophy"></i>';
        if(isEmoji(slug)) return '<span style="font-size:26px;line-height:1;">'+esc(slug)+'</span>';
        return '<i data-lucide="'+esc(slug)+'"></i>';
    }

    function initLucide(container){
        if(typeof lucide !== 'undefined'){
            lucide.createIcons({attrs:{class:'lucide-icon'},nameAttr:'data-lucide',nodes:container?Array.from(container.querySelectorAll('[data-lucide]')):undefined});
        }
    }

    function updateStats(data){
        var active=0,inactive=0,account=0,character=0,hidden=0;
        $.each(data,function(_,a){
            if(a.is_active!==false) active++; else inactive++;
            if(a.scope==='account') account++;
            if(a.scope==='character') character++;
            if(a.hidden_until_earned===true) hidden++;
        });
        $('#nw-total').text(data.length);
        $('#nw-active').text(active);
        $('#nw-inactive').text(inactive);
        $('#nw-count-account').text(account);
        $('#nw-count-character').text(character);
        $('#nw-count-hidden').text(hidden);
    }

    var catClass={
        exploration:'nw-cat-badge-exploration',social:'nw-cat-badge-social',
        progression:'nw-cat-badge-progression',mission:'nw-cat-badge-mission',
        loot:'nw-cat-badge-loot',secret:'nw-cat-badge-secret',system:'nw-cat-badge-system'
    };

    function catBadge(cat){
        if(!cat) return '<span style="color:#555">\u2014</span>';
        var cls=catClass[cat]||'';
        return '<span class="nw-cat-badge '+cls+'">'+esc(cat)+'</span>';
    }

    function scopeBadge(scope){
        var cls=scope==='character'?'nw-scope-badge-character':'';
        return '<span class="nw-scope-badge '+cls+'">'+esc(scope||'\u2014')+'</span>';
    }

    function renderTable(data){
        var tbody=$('#nw-achievements-tbody');
        if(!data.length){tbody.html('<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">No achievements found.</td></tr>');return;}
        // [FIX 1] Usunięto backslash \ z końców linii — był SyntaxError w strict mode
        tbody.html(data.map(function(a){
            var active=a.is_active!==false;
            var bg=esc(a.bg_color||'#2c3e50');
            var badgeHtml='<div class="nw-ach-badge" style="background:'+bg+'">'+renderIcon(a.icon_slug)+'</div>';
            var hiddenHtml=a.hidden_until_earned
                ?'<span class="nw-hidden-yes" title="Hidden until earned">&#128065; hidden</span>'
                :'<span class="nw-hidden-no">visible</span>';
            return '<tr data-id="'+esc(a.id)+'" class="'+((!active)?'nw-row-inactive':'')+'">'
                +'<td>'+badgeHtml+'</td>'
                +'<td><div class="nw-ach-title">'+esc(a.title)+'</div><div class="nw-ach-id">'+esc(a.id)+'</div></td>'
                +'<td>'+catBadge(a.category)+'</td>'
                +'<td>'+scopeBadge(a.scope)+'</td>'
                +'<td><span class="nw-goal-val">\u00d7'+esc(String(a.goal||1))+'</span></td>'
                +'<td>'+hiddenHtml+'</td>'
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-active-toggle" data-id="'+esc(a.id)+'" '+(active?'checked':'')+'><span class="nw-toggle-slider"></span></label></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+esc(a.id)+'">Edit</button></div></td>'
                +'</tr>';
        }).join(''));
        initLucide(document.getElementById('nw-achievements-tbody'));
    }

    function applyFilters(){
        var scopeF=$('#nw-filter-scope').val();
        var catF=$('#nw-filter-category').val();
        var actF=$('#nw-filter-active').val();
        var hidF=$('#nw-filter-hidden').val();
        var q=$('#nw-search').val().toLowerCase().trim();
        var shown=all.filter(function(a){
            if(scopeF&&a.scope!==scopeF) return false;
            if(catF&&a.category!==catF) return false;
            if(actF==='1'&&a.is_active===false) return false;
            if(actF==='0'&&a.is_active!==false) return false;
            if(hidF==='1'&&!a.hidden_until_earned) return false;
            if(hidF==='0'&&a.hidden_until_earned) return false;
            // [FIX 2] Guard na null/undefined + wyszukiwanie też po category i description
            if(q&&!(
                (a.id||'').toLowerCase().includes(q)
                ||(a.title||'').toLowerCase().includes(q)
                ||(a.category||'').toLowerCase().includes(q)
                ||(a.description||'').toLowerCase().includes(q)
            )) return false;
            return true;
        });
        renderTable(shown);
    }

    $('#nw-filter-scope,#nw-filter-category,#nw-filter-active,#nw-filter-hidden').on('change',applyFilters);
    // [FIX 6] Debounce 150ms na search
    $('#nw-search').on('input', debounce(applyFilters, 150));

    function loadAll(){
        $('#nw-achievements-tbody').html('<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;"><div class="nw-spinner"></div> Loading\u2026</td></tr>');
        $.post(ajaxurl,{action:'nw_achievements_get_all',nonce:nonce},function(res){
            if(!res.success){notice('Error: '+res.data,'error');return;}
            all=res.data||[];
            updateStats(all);
            applyFilters();
        }).fail(function(){notice('Request failed.','error');});
    }

    // [FIX 4] Toggle z .fail() — cofa checkbox przy błędzie sieciowym
    $(document).on('change','.nw-active-toggle',function(){
        var id=$(this).data('id'),val=$(this).is(':checked'),row=$(this).closest('tr');
        $.post(ajaxurl,{action:'nw_achievements_toggle',nonce:nonce,achievement_id:id,is_active:val?1:0},function(res){
            if(res.success){
                row.toggleClass('nw-row-inactive',!val);
                $.each(all,function(_,a){if(a.id===id){a.is_active=val;return false;}});
                updateStats(all);
                notice((val?'Activated':'Deactivated')+'.','success');
            } else {
                notice('Toggle failed: '+res.data,'error');
                row.find('.nw-active-toggle').prop('checked',!val);
            }
        }).fail(function(){
            notice('Toggle request failed.','error');
            row.find('.nw-active-toggle').prop('checked',!val);
        });
    });

    function updatePreview(){
        var title=$('#nw-field-title').val()||'Achievement Title';
        var desc=$('#nw-field-description').val()||'Description\u2026';
        var slug=$('#nw-field-icon_slug').val()||'trophy';
        var bg=$('#nw-field-bg_color').val()||'#2c3e50';
        $('#nw-preview-title').text(title);
        $('#nw-preview-desc').text(desc);
        $('#nw-badge-icon').html(renderIcon(slug)).css('background',bg);
        $('#nw-icon-preview').html(renderIconPreview(slug));
        initLucide(document.getElementById('nw-badge-icon'));
        initLucide(document.getElementById('nw-icon-preview'));
    }

    $(document).on('input','#nw-field-title,#nw-field-description,#nw-field-icon_slug,#nw-field-bg_color',updatePreview);
    $('#nw-field-bg_color_picker').on('input',function(){$('#nw-field-bg_color').val($(this).val());updatePreview();});
    $('#nw-field-bg_color').on('input',function(){
        var v=$(this).val();
        if(/^#[0-9a-fA-F]{6}$/.test(v))$('#nw-field-bg_color_picker').val(v);
        updatePreview();
    });
    $('#nw-field-icon_slug').on('input',updatePreview);

    function openModal(id){
        $('#nw-achievement-form')[0].reset();
        $('#nw-field-original_id').val('');
        $('#nw-field-bg_color_picker').val('#2c3e50');
        $('#nw-field-bg_color').val('#2c3e50');
        $('#nw-icon-preview').html('<i data-lucide="trophy"></i>');
        $('#nw-badge-icon').html('<i data-lucide="trophy"></i>').css('background','#2c3e50');
        initLucide(document.getElementById('nw-icon-preview'));
        initLucide(document.getElementById('nw-badge-icon'));
        updatePreview();
        if(id){
            var a=null;
            $.each(all,function(_,x){if(x.id===id){a=x;return false;}});
            if(a){
                $('#nw-field-original_id').val(a.id);
                $('#nw-field-id').val(a.id);
                $('#nw-field-title').val(a.title||'');
                $('#nw-field-description').val(a.description||'');
                var slug=a.icon_slug||'trophy';
                $('#nw-field-icon_slug').val(slug);
                $('#nw-icon-preview').html(renderIconPreview(slug));
                initLucide(document.getElementById('nw-icon-preview'));
                var bg=a.bg_color||'#2c3e50';
                $('#nw-field-bg_color').val(bg);
                if(/^#[0-9a-fA-F]{6}$/.test(bg))$('#nw-field-bg_color_picker').val(bg);
                $('#nw-field-scope').val(a.scope||'account');
                $('#nw-field-category').val(a.category||'');
                $('#nw-field-goal').val(a.goal||1);
                $('#nw-field-hidden_until_earned').prop('checked',a.hidden_until_earned===true);
                $('#nw-field-is_active').prop('checked',a.is_active!==false);
                updatePreview();
            }
            $('#nw-modal-title').text('Edit Achievement');
            $('#nw-save-label').text('Save Changes');
            $('#nw-delete-btn').show().data('id',id);
        } else {
            $('#nw-modal-title').text('New Achievement');
            $('#nw-save-label').text('Create Achievement');
            $('#nw-delete-btn').hide();
        }
        $('#nw-modal-overlay').fadeIn(150);
    }

    function closeModal(){$('#nw-modal-overlay').fadeOut(150);}
    $('#nw-modal-close,#nw-cancel-btn').on('click',closeModal);
    $('#nw-modal-overlay').on('click',function(e){if($(e.target).is('#nw-modal-overlay'))closeModal();});
    $(document).on('click','.nw-edit-btn',function(){openModal($(this).data('id'));});
    $('#nw-add-btn').on('click',function(){openModal(null);});
    $('#nw-refresh-btn').on('click',loadAll);

    $('#nw-save-btn').on('click',function(){
        if(!$('#nw-field-id').val().trim()){notice('ID (slug) is required.','error');return;}
        if(!$('#nw-field-title').val().trim()){notice('Title is required.','error');return;}
        var btn=$(this); btn.prop('disabled',true);
        $('#nw-save-label').text('Saving\u2026');
        var fd={action:'nw_achievements_save',nonce:nonce,achievement:{}};
        $('#nw-achievement-form').serializeArray().forEach(function(f){
            if(f.name!=='is_active'&&f.name!=='hidden_until_earned') fd.achievement[f.name]=f.value;
        });
        fd.achievement.is_active=$('#nw-field-is_active').is(':checked')?1:0;
        fd.achievement.hidden_until_earned=$('#nw-field-hidden_until_earned').is(':checked')?1:0;
        $.post(ajaxurl,fd,function(res){
            btn.prop('disabled',false);
            $('#nw-save-label').text('Save Changes');
            if(res.success){notice('Achievement saved!','success');closeModal();loadAll();}
            else notice('Error: '+(res.data||'Unknown'),'error');
        }).fail(function(){btn.prop('disabled',false);$('#nw-save-label').text('Save Changes');notice('Request failed.','error');});
    });

    // [FIX 5] confirmModal zamiast confirm() + [FIX 5] .fail() przy delete
    $('#nw-delete-btn').on('click',function(){
        var id=$(this).data('id');
        if(!id) return;
        confirmModal('Delete achievement "'+id+'" permanently?', function(){
            $.post(ajaxurl,{action:'nw_achievements_delete',nonce:nonce,achievement_id:id},function(res){
                if(res.success){notice('Deleted.','success');closeModal();loadAll();}
                else notice('Delete failed: '+res.data,'error');
            // [FIX 5] .fail() przy delete
            }).fail(function(){notice('Delete request failed.','error');});
        });
    });

    loadAll();
});
