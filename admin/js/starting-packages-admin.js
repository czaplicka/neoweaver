/* NeoWeaver Admin — Starting Packages Panel JS */
jQuery(function($){
    var nonce  = $('#nw-nonce').val();
    var editId = null;
    var allItems = []; /* cache of cyber_items for slot selects */

    /* -------- load items for selects -------- */
    function loadItemsCache(cb){
        if(allItems.length){cb();return;}
        $.post(ajaxurl,{action:'nw_sp_get_items',nonce:nonce},function(r){
            if(r.success) allItems=r.data||[];
            cb();
        });
    }

    function populateItemSelects(pkg){
        var slotMap={
            'nw-field-head_item_id':  ['head'],
            'nw-field-torso_item_id': ['chest','torso','body'],
            'nw-field-hand_r_item_id':['hand_r','weapon','shield'],
            'nw-field-hand_l_item_id':['hand_l','weapon','shield'],
            'nw-field-belt_item_id':  ['waist','belt','bag']
        };
        $.each(slotMap,function(selId){
            var $sel=$('#'+selId);
            $sel.empty().append('<option value="">— none —</option>');
            var grouped={};
            $.each(allItems,function(_,it){
                var g=it.slot||it.type||'other';
                if(!grouped[g]) grouped[g]=[];
                grouped[g].push(it);
            });
            $.each(grouped,function(grpName,items){
                var $og=$('<optgroup>').attr('label',grpName.toUpperCase());
                $.each(items,function(_,it){
                    $og.append($('<option>').val(it.id).text(it.name+(it.slot?' ['+it.slot+']':'')));
                });
                $sel.append($og);
            });
            var fieldName=selId.replace('nw-field-','');
            var curVal=pkg&&pkg[fieldName]?pkg[fieldName]:'';
            $sel.val(curVal);
        });
    }

    /* -------- load packages -------- */
    function loadPackages(){
        $('#nw-sp-tbody').html('<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading packages…</td></tr>');
        $.post(ajaxurl,{action:'nw_sp_get_all',nonce:nonce},function(r){
            if(!r.success){showNotice('error',r.data);return;}
            renderTable(r.data);
        });
    }

    function renderTable(rows){
        var total=rows.length,sel=0,hidden=0,html='';
        if(!rows.length){html='<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No packages found.</td></tr>';}
        $.each(rows,function(_,p){
            if(p.is_player_selectable) sel++; else hidden++;
            var slots='';
            var slotFields=['head_item_id','torso_item_id','hand_r_item_id','hand_l_item_id','belt_item_id'];
            var slotLabels={head_item_id:'Head',torso_item_id:'Torso',hand_r_item_id:'R-Hand',hand_l_item_id:'L-Hand',belt_item_id:'Belt'};
            $.each(slotFields,function(_,f){
                if(p[f]) slots+='<span class="nw-slot-chip">'+slotLabels[f]+'</span>';
            });
            if(!slots) slots='<span style="color:#333">—</span>';
            var tags='';
            if(p.compatibility_tags&&p.compatibility_tags.length){
                $.each(p.compatibility_tags,function(_,t){tags+='<span class="nw-tag">'+escH(t)+'</span>';});}
            if(!tags) tags='<span style="color:#333">—</span>';
            var classCount=(p.compatible_class_ids&&p.compatible_class_ids.length)?p.compatible_class_ids.length:0;
            html+='<tr data-id="'+escH(p.id)+'">'
                +'<td><div class="nw-pkg-name">'+escH(p.package_name)+'</div>'+(p.description?'<div class="nw-pkg-sub">'+escH(p.description.substring(0,60))+(p.description.length>60?'…':'')+'</div>':'')+'</td>'
                +'<td><span class="nw-armor-val">'+escH(p.base_armor)+'</span></td>'
                +'<td>'+slots+'</td>'
                +'<td><div class="nw-tags">'+tags+'</div></td>'
                +'<td>'+(classCount?'<span class="nw-tag">'+classCount+' class'+(classCount>1?'es':'')+'</span>':'<span style="color:#333">—</span>')+'</td>'
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-toggle-sel" data-id="'+escH(p.id)+'"'+(p.is_player_selectable?' checked':'')+'><span class="nw-toggle-slider"></span></label></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+escH(p.id)+'">Edit</button></div></td>'
                +'</tr>';
        });
        $('#nw-sp-tbody').html(html);
        $('#nw-total').text(total);$('#nw-selectable').text(sel);$('#nw-hidden').text(hidden);
    }

    /* -------- modal -------- */
    function openModal(pkg){
        editId=pkg?pkg.id:null;
        $('#nw-modal-title').text(pkg?'Edit Package':'New Package');
        $('#nw-save-label').text(pkg?'Save Package':'Create Package');
        $('#nw-delete-btn').toggle(!!pkg);
        $('#nw-field-id').val(pkg?pkg.id:'');
        $('#nw-field-package_name').val(pkg?pkg.package_name:'');
        $('#nw-field-description').val(pkg?pkg.description||'':'');
        $('#nw-field-base_armor').val(pkg?pkg.base_armor:0);
        $('#nw-field-is_player_selectable').prop('checked',pkg?pkg.is_player_selectable:false);
        var arrToStr=function(a){return (a&&a.length)?a.join(', '):''};
        $('#nw-field-items_list').val(arrToStr(pkg&&pkg.items_list));
        $('#nw-field-attack_cards_pool').val(arrToStr(pkg&&pkg.attack_cards_pool));
        $('#nw-field-defense_cards_pool').val(arrToStr(pkg&&pkg.defense_cards_pool));
        $('#nw-field-compatibility_tags').val(arrToStr(pkg&&pkg.compatibility_tags));
        $('#nw-field-compatible_class_ids').val(arrToStr(pkg&&pkg.compatible_class_ids));
        loadItemsCache(function(){populateItemSelects(pkg);});
        $('#nw-modal-overlay').show();
    }
    function closeModal(){ $('#nw-modal-overlay').hide(); editId=null; }

    /* -------- save -------- */
    function savePkg(){
        var data={action:'nw_sp_save',nonce:nonce,pkg:{}};
        $('#nw-sp-form').serializeArray().forEach(function(f){data.pkg[f.name]=f.value;});
        data.pkg.is_player_selectable=$('#nw-field-is_player_selectable').is(':checked')?'1':'0';
        ['head_item_id','torso_item_id','hand_r_item_id','hand_l_item_id','belt_item_id'].forEach(function(f){
            data.pkg[f]=$('#nw-field-'+f).val()||'';
        });
        $('#nw-save-btn').prop('disabled',true).text('Saving…');
        $.post(ajaxurl,data,function(r){
            $('#nw-save-btn').prop('disabled',false);
            $('#nw-save-label').text(editId?'Save Package':'Create Package');
            if(!r.success){showNotice('error',r.data);return;}
            showNotice('success',editId?'Package updated.':'Package created.');
            closeModal(); loadPackages();
        });
    }

    /* -------- toggle -------- */
    $(document).on('change','.nw-toggle-sel',function(){
        var id=$(this).data('id'), state=$(this).is(':checked');
        $.post(ajaxurl,{action:'nw_sp_toggle',nonce:nonce,pkg_id:id,is_player_selectable:state?1:0},function(r){
            if(!r.success){showNotice('error',r.data);loadPackages();}
        });
    });

    /* -------- delete -------- */
    $('#nw-delete-btn').on('click',function(){
        if(!editId||!confirm('Delete this package? This cannot be undone.')) return;
        $.post(ajaxurl,{action:'nw_sp_delete',nonce:nonce,pkg_id:editId},function(r){
            if(!r.success){showNotice('error',r.data);return;}
            showNotice('success','Package deleted.');
            closeModal(); loadPackages();
        });
    });

    /* -------- events -------- */
    $('#nw-add-btn').on('click',function(){openModal(null);});
    $('#nw-refresh-btn').on('click',loadPackages);
    $('#nw-modal-close,#nw-cancel-btn').on('click',closeModal);
    $('#nw-modal-overlay').on('click',function(e){if($(e.target).is('#nw-modal-overlay'))closeModal();});
    $('#nw-save-btn').on('click',savePkg);
    $(document).on('click','.nw-edit-btn',function(){
        var id=$(this).data('id');
        $.post(ajaxurl,{action:'nw_sp_get_all',nonce:nonce},function(r){
            if(!r.success) return;
            var pkg=null; $.each(r.data,function(_,p){if(p.id===id){pkg=p;return false;}});
            if(pkg) openModal(pkg);
        });
    });

    function showNotice(type,msg){
        var $n=$('#nw-notice');
        $n.removeClass('nw-notice-success nw-notice-error').addClass('nw-notice-'+type).text(msg).show();
        setTimeout(function(){$n.fadeOut();},4000);
    }
    function escH(s){return $('<div>').text(String(s||'')).html();}

    loadPackages();
});
