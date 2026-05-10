/* NeoWeaver Admin — Skills Panel JS */
jQuery(function($){
    var nonce   = $('#nw-nonce').val();
    var editId  = null;

    /* ---------- load ---------- */
    function loadSkills(){
        var cat = $('#nw-filter-category').val();
        $('#nw-skills-tbody').html('<tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading skills…</td></tr>');
        $.post(ajaxurl,{action:'nw_skills_get_all',nonce:nonce,filter_category:cat},function(r){
            if(!r.success){showNotice('error',r.data);return;}
            renderTable(r.data);
        });
    }

    function renderTable(rows){
        var total=rows.length,active=0,inactive=0,html='';
        if(!rows.length){html='<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">No skills found.</td></tr>';}
        $.each(rows,function(_,s){
            if(s.is_active) active++; else inactive++;
            var tags='';
            if(s.tags&&s.tags.length){$.each(s.tags,function(_,t){tags+='<span class="nw-tag">'+escH(t)+'</span>';});}
            var img=s.img_url
                ?'<img class="nw-skill-img" src="'+escH(s.img_url)+'" alt="" loading="lazy">'
                :'<div class="nw-skill-img-placeholder">⚡</div>';
            var catCls='nw-cat-'+(s.category||'');
            var cardEff=s.card_effect?'<span class="nw-card-effect" title="'+escH(s.card_effect)+'">'+escH(s.card_effect)+'</span>':'<span style="color:#333">—</span>';
            html+='<tr class="'+(s.is_active?'':'nw-row-inactive')+'" data-id="'+escH(s.id)+'">'
                +'<td>'+img+'</td>'
                +'<td><div class="nw-skill-name">'+escH(s.name)+'</div>'+(s.description?'<div class="nw-skill-sub">'+escH(s.description.substring(0,60))+(s.description.length>60?'…':'')+'</div>':'')+'</td>'
                +'<td>'+(s.category?'<span class="nw-category-badge '+catCls+'">'+escH(s.category)+'</span>':'<span style="color:#333">—</span>')+'</td>'
                +'<td>'+(s.application?escH(s.application):'<span style="color:#333">—</span>')+'</td>'
                +'<td><div class="nw-tags">'+tags+'</div></td>'
                +'<td>'+cardEff+'</td>'
                +'<td><label class="nw-toggle"><input type="checkbox" class="nw-toggle-active" data-id="'+escH(s.id)+'"'+(s.is_active?' checked':'')+'><span class="nw-toggle-slider"></span></label></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+escH(s.id)+'">Edit</button></div></td>'
                +'</tr>';
        });
        $('#nw-skills-tbody').html(html);
        $('#nw-total').text(total);$('#nw-active').text(active);$('#nw-inactive').text(inactive);
    }

    /* ---------- modal ---------- */
    function openModal(skill){
        editId = skill ? skill.id : null;
        $('#nw-modal-title').text(skill?'Edit Skill':'New Skill');
        $('#nw-save-label').text(skill?'Save Skill':'Create Skill');
        $('#nw-delete-btn').toggle(!!skill);
        $('#nw-field-id').val(skill?skill.id:'');
        $('#nw-field-name').val(skill?skill.name:'');
        $('#nw-field-category').val(skill&&skill.category?skill.category:'');
        $('#nw-field-application').val(skill?skill.application||'':'');
        $('#nw-field-description').val(skill?skill.description||'':'');
        $('#nw-field-card_effect').val(skill?skill.card_effect||'':'');
        $('#nw-field-img_url').val(skill?skill.img_url||'':'');
        $('#nw-field-tags').val(skill&&skill.tags?skill.tags.join(', '):'');
        $('#nw-field-linked_attributes').val(skill&&skill.linked_attributes?skill.linked_attributes.join(', '):'');
        $('#nw-field-is_active').prop('checked',skill?skill.is_active:true);
        updateImgPreview($('#nw-field-img_url').val());
        $('#nw-modal-overlay').show();
    }
    function closeModal(){ $('#nw-modal-overlay').hide(); editId=null; }

    function updateImgPreview(url){
        if(url){$('#nw-img-preview').attr('src',url);$('#nw-img-preview-wrap').show();}
        else{$('#nw-img-preview-wrap').hide();}
    }

    /* ---------- save ---------- */
    function saveSkill(){
        var data={action:'nw_skills_save',nonce:nonce,skill:{}};
        $('#nw-skill-form').serializeArray().forEach(function(f){data.skill[f.name]=f.value;});
        data.skill.is_active=$('#nw-field-is_active').is(':checked')?'1':'0';
        $('#nw-save-btn').prop('disabled',true).text('Saving…');
        $.post(ajaxurl,data,function(r){
            $('#nw-save-btn').prop('disabled',false);
            $('#nw-save-label').text(editId?'Save Skill':'Create Skill');
            if(!r.success){showNotice('error',r.data);return;}
            showNotice('success',editId?'Skill updated.':'Skill created.');
            closeModal(); loadSkills();
        });
    }

    /* ---------- toggle ---------- */
    $(document).on('change','.nw-toggle-active',function(){
        var id=$(this).data('id'), state=$(this).is(':checked');
        $.post(ajaxurl,{action:'nw_skills_toggle',nonce:nonce,skill_id:id,is_active:state?1:0},function(r){
            if(!r.success){showNotice('error',r.data);loadSkills();}
            else{$(document).find('tr[data-id="'+id+'"]').toggleClass('nw-row-inactive',!state);}
        });
    });

    /* ---------- delete ---------- */
    $('#nw-delete-btn').on('click',function(){
        if(!editId||!confirm('Delete this skill? This cannot be undone.')) return;
        $.post(ajaxurl,{action:'nw_skills_delete',nonce:nonce,skill_id:editId},function(r){
            if(!r.success){showNotice('error',r.data);return;}
            showNotice('success','Skill deleted.');
            closeModal(); loadSkills();
        });
    });

    /* ---------- events ---------- */
    $('#nw-add-btn').on('click',function(){openModal(null);});
    $('#nw-refresh-btn').on('click',loadSkills);
    $('#nw-filter-category').on('change',loadSkills);
    $('#nw-modal-close,#nw-cancel-btn').on('click',closeModal);
    $('#nw-modal-overlay').on('click',function(e){if($(e.target).is('#nw-modal-overlay'))closeModal();});
    $('#nw-save-btn').on('click',saveSkill);
    $('#nw-field-img_url').on('input',function(){updateImgPreview($(this).val());});
    $(document).on('click','.nw-edit-btn',function(){
        var id=$(this).data('id');
        $.post(ajaxurl,{action:'nw_skills_get_all',nonce:nonce,filter_category:''},function(r){
            if(!r.success) return;
            var skill=null; $.each(r.data,function(_,s){if(s.id===id){skill=s;return false;}});
            if(skill) openModal(skill);
        });
    });

    function showNotice(type,msg){
        var $n=$('#nw-notice');
        $n.removeClass('nw-notice-success nw-notice-error').addClass('nw-notice-'+type).text(msg).show();
        setTimeout(function(){$n.fadeOut();},4000);
    }
    function escH(s){return $('<div>').text(String(s||'')).html();}

    loadSkills();
});
