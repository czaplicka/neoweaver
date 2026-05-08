jQuery(function($){
    var nonce=$("#nw-nonce").val(), all=[];

    function esc(s){return $('<span>').text(s||'').html();}
    function notice(msg,type){var el=$("#nw-notice");el.attr("class","nw-notice nw-notice-"+type).text(msg).show();setTimeout(function(){el.fadeOut(300);},3500);}
    function tagsStr(t){if(!t)return'';if(Array.isArray(t))return t.join(', ');try{var a=JSON.parse(t);return Array.isArray(a)?a.join(', '):t;}catch(e){return t;}}

    function renderTable(data){
        var tbody=$("#nw-classes-tbody");
        if(!data.length){tbody.html('<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">No classes found.</td></tr>');return;}
        tbody.html(data.map(function(c){
            var tags=Array.isArray(c.tags)?c.tags:(c.tags?[c.tags]:[]);
            var tagsH=tags.slice(0,4).map(function(t){return'<span class="nw-tag">'+esc(t)+'</span>';}).join('')+(tags.length>4?'<span class="nw-tag">+'+(tags.length-4)+'</span>':'');
            var imgH=c.img_url?'<img src="'+esc(c.img_url)+'" class="nw-class-img" loading="lazy" onerror="this.style.display=\'none\'">':'<div class="nw-class-img-placeholder">⚔️</div>';
            var activeH=c.is_active?'<span class="nw-badge-active">Active</span>':'<span class="nw-badge-inactive">Inactive</span>';
            return'<tr data-id="'+c.id+'">'
                +'<td>'+imgH+'</td>'
                +'<td><div class="nw-class-name">'+esc(c.name)+'</div>'+(c.description?'<div class="nw-class-sub">'+esc(c.description.substring(0,60))+(c.description.length>60?'…':'')+'</div>':'')+'</td>'
                +'<td><div class="nw-tags">'+tagsH+'</div></td>'
                +'<td>'+(c.starting_gold!=null?'<span class="nw-gold">'+c.starting_gold+' g</span>':'<span style="color:#444">—</span>')+'</td>'
                +'<td><span style="color:#aaa">'+( c.skill_limit!=null?c.skill_limit:'—')+'</span></td>'
                +'<td>'+(c.vulnerability?'<span class="nw-vuln">'+esc(c.vulnerability)+'</span>':'<span style="color:#444">—</span>')+'</td>'
                +'<td>'+activeH+'</td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+c.id+'">Edit</button></div></td>'
                +'</tr>';
        }).join(''));
    }

    function applySearch(){
        var q=$("#nw-search").val().toLowerCase().trim();
        var shown=q?all.filter(function(c){return c.name.toLowerCase().includes(q)||(c.icon_slug||'').toLowerCase().includes(q);}):all;
        renderTable(shown);
    }

    function loadAll(){
        $("#nw-classes-tbody").html('<tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading…</td></tr>');
        $.post(ajaxurl,{action:"nw_classes_get_all",nonce:nonce},function(res){
            if(!res.success){notice("Error: "+res.data,"error");return;}
            all=res.data||[];
            $("#nw-total").text(all.length);
            $("#nw-active").text(all.filter(function(c){return c.is_active;}).length);
            applySearch();
        }).fail(function(){notice("Request failed.","error");});
    }

    $("#nw-search").on("input",applySearch);

    function abToStr(ab){
        if(!ab)return'';
        if(typeof ab==='string')return ab;
        try{return JSON.stringify(ab);}catch(e){return'';}
    }

    function openModal(id){
        $("#nw-class-form")[0].reset();
        $("#nw-field-id").val("");
        $("#nw-img-preview-wrap").hide();
        $("#nw-field-is_active").val("1");
        if(id){
            var c=all.find(function(x){return x.id===id;});
            if(c){
                $("#nw-field-id").val(c.id);
                $("#nw-field-name").val(c.name||"");
                $("#nw-field-description").val(c.description||"");
                $("#nw-field-icon_slug").val(c.icon_slug||"");
                $("#nw-field-starting_gold").val(c.starting_gold!=null?c.starting_gold:"");
                $("#nw-field-skill_limit").val(c.skill_limit!=null?c.skill_limit:"");
                $("#nw-field-vulnerability").val(c.vulnerability||"");
                $("#nw-field-tags").val(tagsStr(c.tags));
                $("#nw-field-is_active").val(c.is_active?"1":"0");
                $("#nw-field-mechanics").val(c.mechanics||"");
                $("#nw-field-gm_instructions").val(c.gm_instructions||"");
                $("#nw-field-ai_personality_modifier").val(c.ai_personality_modifier||"");
                $("#nw-field-attribute_bonuses").val(abToStr(c.attribute_bonuses));
                if(c.img_url){$("#nw-field-img_url").val(c.img_url);$("#nw-img-preview").attr("src",c.img_url);$("#nw-img-preview-wrap").show();}
            }
            $("#nw-modal-title").text("Edit Class");
            $("#nw-save-label").text("Save Changes");
            $("#nw-delete-btn").show().data("id",id);
        } else {
            $("#nw-modal-title").text("New Class");
            $("#nw-save-label").text("Create Class");
            $("#nw-delete-btn").hide();
        }
        $("#nw-modal-overlay").fadeIn(150);
    }

    $("#nw-field-img_url").on("input",function(){
        var v=$(this).val().trim();
        if(v){$("#nw-img-preview").attr("src",v);$("#nw-img-preview-wrap").show();}
        else{$("#nw-img-preview-wrap").hide();}
    });

    $("#nw-modal-close,#nw-cancel-btn").on("click",function(){$("#nw-modal-overlay").fadeOut(150);});
    $("#nw-modal-overlay").on("click",function(e){if($(e.target).is("#nw-modal-overlay"))$("#nw-modal-overlay").fadeOut(150);});
    $(document).on("click",".nw-edit-btn",function(){openModal($(this).data("id"));});
    $("#nw-add-btn").on("click",function(){openModal(null);});
    $("#nw-refresh-btn").on("click",loadAll);

    $("#nw-save-btn").on("click",function(){
        if(!$("#nw-field-name").val().trim()){notice("Name is required.","error");return;}
        var btn=$(this);btn.prop("disabled",true);$("#nw-save-label").text("Saving…");
        var fd={action:"nw_classes_save",nonce:nonce,"nw_class":{}};
        $("#nw-class-form").serializeArray().forEach(function(f){fd["nw_class"][f.name]=f.value;});
        $.post(ajaxurl,fd,function(res){
            btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");
            if(res.success){notice("Class saved!","success");$("#nw-modal-overlay").fadeOut(150);loadAll();}
            else{notice("Error: "+(res.data||"Unknown"),"error");}
        }).fail(function(){btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");notice("Request failed.","error");});
    });

    $("#nw-delete-btn").on("click",function(){
        var id=$(this).data("id");
        if(!id||!confirm("Delete this class permanently?"))return;
        $.post(ajaxurl,{action:"nw_classes_delete",nonce:nonce,class_id:id},function(res){
            if(res.success){notice("Class deleted.","success");$("#nw-modal-overlay").fadeOut(150);loadAll();}
            else{notice("Delete failed: "+res.data,"error");}
        });
    });

    loadAll();
});
