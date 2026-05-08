jQuery(function($){
    var nonce=$("#nw-nonce").val(), all=[], filtered=[];

    var typeClass={
        'Active':'nw-type-active','Passive':'nw-type-passive','Reaction':'nw-type-reaction',
        'Ultimate':'nw-type-ultimate','Racial':'nw-type-racial','Class':'nw-type-class',
        'Item':'nw-type-item','Special':'nw-type-special'
    };

    function esc(s){return $('<span>').text(s||'').html();}
    function notice(msg,type){var el=$("#nw-notice");el.attr("class","nw-notice nw-notice-"+type).text(msg).show();setTimeout(function(){el.fadeOut(300);},3500);}
    function tagsStr(t){if(!t)return'';if(Array.isArray(t))return t.join(', ');try{var a=JSON.parse(t);return Array.isArray(a)?a.join(', '):t;}catch(e){return t;}}

    // [FIX 3] Debounce helper – 150ms
    function debounce(fn, delay){
        var timer;
        return function(){
            clearTimeout(timer);
            timer=setTimeout(fn, delay);
        };
    }

    function updateStats(data){
        var active=data.filter(function(a){return a.ability_type==='Active';}).length;
        var passive=data.filter(function(a){return a.ability_type==='Passive';}).length;
        $("#nw-total").text(data.length);
        $("#nw-active-count").text(active);
        $("#nw-passive-count").text(passive);
        $("#nw-other-count").text(data.length-active-passive);
    }

    function renderTable(data){
        var tbody=$("#nw-abilities-tbody");
        if(!data.length){tbody.html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No abilities found.</td></tr>');return;}
        tbody.html(data.map(function(a){
            var tags=Array.isArray(a.tags)?a.tags:[];
            var tagsH=tags.slice(0,3).map(function(t){return'<span class="nw-tag">'+esc(t)+'</span>';}).join('')+(tags.length>3?'<span class="nw-tag">+'+(tags.length-3)+'</span>':'');
            var tc=typeClass[a.ability_type]||'nw-type-special';
            var typeH=a.ability_type?'<span class="nw-type-badge '+tc+'">'+esc(a.ability_type)+'</span>':'—';
            // [FIX 1] Zamiast onerror inline – podpinamy addEventListener przez jQuery po renderze
            var imgH=a.img_url?'<img src="'+esc(a.img_url)+'" class="nw-ability-img" loading="lazy" data-fallback="1">':'<div class="nw-ability-img-placeholder">✨</div>';
            return'<tr data-id="'+a.id+'">'
                +'<td>'+imgH+'</td>'
                +'<td><div class="nw-ability-name">'+esc(a.name)+'</div><div class="nw-ability-desc">'+esc(a.description||'')+'</div></td>'
                +'<td>'+typeH+'</td>'
                +'<td><div class="nw-source">'+esc(a.source||'—')+'</div></td>'
                +'<td>'+(a.cost?'<span class="nw-cost-badge">'+esc(a.cost)+'</span>':'<span style="color:#444">—</span>')+'</td>'
                +'<td><div class="nw-tags">'+tagsH+'</div></td>'
                +'<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="'+a.id+'">Edit</button></div></td>'
                +'</tr>';
        }).join(''));
        // [FIX 1] Bezpieczne ukrywanie zepsutych obrazków przez event listener
        tbody.find("img[data-fallback]").on("error", function(){
            $(this).hide();
        });
    }

    // [BUG FIX] Wyszukiwanie uwzględnia też tagi; placeholder w HTML powinien to sygnalizować
    function applySearch(){
        var q=$("#nw-search").val().toLowerCase().trim();
        var shown=q?filtered.filter(function(a){
            var tagMatch=(Array.isArray(a.tags)?a.tags:[]).some(function(t){
                return t.toLowerCase().includes(q);
            });
            return a.name.toLowerCase().includes(q)
                ||(a.source||'').toLowerCase().includes(q)
                ||tagMatch;
        }) : filtered;
        renderTable(shown);
    }

    function loadAll(){
        var ft=$("#nw-filter-type").val();
        $("#nw-abilities-tbody").html('<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading…</td></tr>');
        $.post(ajaxurl,{action:"nw_abilities_get_all",nonce:nonce,filter_type:ft},function(res){
            if(!res.success){notice("Error: "+res.data,"error");return;}
            // [FIX 4] Osobne referencje – mutacja filtered nie zmienia all
            all=res.data||[];
            filtered=[...all];
            updateStats(all);
            applySearch();
        }).fail(function(){notice("Request failed.","error");});
    }

    $("#nw-filter-type").on("change",loadAll);
    // [FIX 3] Debounce 150ms na search
    $("#nw-search").on("input", debounce(applySearch, 150));

    // [FIX 5] Własny modal potwierdzenia zamiast natywnego confirm()
    function confirmModal(message, onConfirm){
        var overlay=$('<div class="nw-confirm-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;display:flex;align-items:center;justify-content:center;">'
            +'<div class="nw-confirm-box" style="background:#1a1a2e;border:1px solid #adff00;border-radius:8px;padding:32px 28px;min-width:320px;text-align:center;">'
            +'<p style="color:#fff;margin-bottom:24px;font-family:\'Chakra Petch\',sans-serif;">'+esc(message)+'</p>'
            +'<button class="nw-confirm-yes nw-action-btn" style="margin-right:12px;">Delete</button>'
            +'<button class="nw-confirm-no nw-action-btn" style="background:#333;">Cancel</button>'
            +'</div></div>');
        $("body").append(overlay);
        overlay.find(".nw-confirm-yes").on("click",function(){overlay.remove();onConfirm();});
        overlay.find(".nw-confirm-no").on("click",function(){overlay.remove();});
        overlay.on("click",function(e){if($(e.target).is(overlay))overlay.remove();});
    }

    function openModal(id){
        $("#nw-ability-form")[0].reset();
        $("#nw-field-id").val("");
        $("#nw-img-preview-wrap").hide();
        if(id){
            var a=all.find(function(x){return x.id===id;});
            if(a){
                $("#nw-field-id").val(a.id);
                $("#nw-field-name").val(a.name||"");
                $("#nw-field-description").val(a.description||"");
                $("#nw-field-gm_notes").val(a.gm_notes||"");
                $("#nw-field-ability_type").val(a.ability_type||"");
                $("#nw-field-source").val(a.source||"");
                $("#nw-field-cost").val(a.cost||"");
                $("#nw-field-tags").val(tagsStr(a.tags));
                if(a.img_url){
                    $("#nw-field-img_url").val(a.img_url);
                    $("#nw-img-preview").attr("src",a.img_url);
                    $("#nw-img-preview-wrap").show();
                }
            }
            $("#nw-modal-title").text("Edit Ability");
            $("#nw-save-label").text("Save Changes");
            $("#nw-delete-btn").show().data("id",id);
        } else {
            $("#nw-modal-title").text("New Ability");
            $("#nw-save-label").text("Create Ability");
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

    // [FIX 2] Flat struktura zamiast zagnieżdżonego ability{}
    $("#nw-save-btn").on("click",function(){
        if(!$("#nw-field-name").val().trim()){notice("Name is required.","error");return;}
        var btn=$(this);btn.prop("disabled",true);$("#nw-save-label").text("Saving…");
        var fd={action:"nw_abilities_save",nonce:nonce};
        $("#nw-ability-form").serializeArray().forEach(function(f){ fd[f.name]=f.value; });
        $.post(ajaxurl,fd,function(res){
            btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");
            if(res.success){notice("Ability saved!","success");$("#nw-modal-overlay").fadeOut(150);loadAll();}
            else{notice("Error: "+(res.data||"Unknown"),"error");}
        }).fail(function(){btn.prop("disabled",false);$("#nw-save-label").text("Save Changes");notice("Request failed.","error");});
    });

    // [FIX 5] Modal zamiast confirm() + [FIX 6] .fail() przy delete
    $("#nw-delete-btn").on("click",function(){
        var id=$(this).data("id");
        if(!id)return;
        confirmModal("Delete this ability permanently?",function(){
            $.post(ajaxurl,{action:"nw_abilities_delete",nonce:nonce,ability_id:id},function(res){
                if(res.success){notice("Ability deleted.","success");$("#nw-modal-overlay").fadeOut(150);loadAll();}
                else{notice("Delete failed: "+res.data,"error");}
            // [FIX 6] Obsługa błędów HTTP przy delete
            }).fail(function(){notice("Delete request failed.","error");});
        });
    });

    loadAll();
});
