jQuery(function($){

function escapeHtml(str){
	return String(str||"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/\'/g,"&#039;");
}

function drawSparkline(el,series,color){
	var $el=$(el);
	if(!series||!series.length){$el.html(\'<div class="nw-chart-empty">No data yet</div>\');return;}
	var values=series.map(function(p){return parseInt(p.value||0,10);});
	var max=Math.max.apply(null,values);
	if(max<=0){$el.html(\'<div class="nw-chart-empty">No growth yet</div>\');return;}
	var W=320,H=120,pad=10;
	var stepX=(W-pad*2)/Math.max(values.length-1,1);
	var pts=values.map(function(v,i){return[pad+i*stepX,H-pad-(v/max)*(H-pad*2)];});
	var line=pts.map(function(p){return p[0].toFixed(2)+","+p[1].toFixed(2);}).join(" ");
	var bars=values.map(function(v,i){
		var x=pad+i*stepX-3,h=max?(v/max)*(H-pad*2):0,y=H-pad-h;
		return\'<rect x="\'+x.toFixed(2)+\'" y="\'+y.toFixed(2)+\'" width="6" height="\'+h.toFixed(2)+\'" rx="2" fill="\'+color+\'" opacity="0.22"></rect>\';
	}).join("");
	var last=pts[pts.length-1];
	var svg=\'<svg viewBox="0 0 \'+W+" "+H+\'" preserveAspectRatio="none" aria-hidden="true">\'
		+\'<line x1="0" y1="\'+(H-pad)+\'" x2="\'+W+\'" y2="\'+(H-pad)+\'" stroke="#2c2c2c" stroke-width="1"></line>\'
		+bars
		+\'<polyline fill="none" stroke="\'+color+\'" stroke-width="3" points="\'+line+\'"></polyline>\'
		+\'<circle cx="\'+last[0].toFixed(2)+\'" cy="\'+last[1].toFixed(2)+\'" r="4" fill="\'+color+\'"></circle>\'
		+\'</svg>\';
	$el.html(svg);
}

function renderDeckBars(containerId, data, colorPrefix){
	var $w=$(containerId);
	if(!data||!Object.keys(data).length){
		$w.html(\'<div class="nw-empty-state">No data</div>\');
		return;
	}
	var keys=Object.keys(data);
	var max=Math.max.apply(null,keys.map(function(k){return data[k];}));
	if(max<=0){$w.html(\'<div class="nw-empty-state">No cards yet</div>\');return;}
	var html=keys.map(function(k){
		var pct=max>0?Math.round((data[k]/max)*100):0;
		return\'<div class="nw-deck-bar-row">\'
			+\'<div class="nw-deck-bar-meta"><span>\'+escapeHtml(k)+\'</span><span class="nw-deck-bar-count">\'+data[k]+\'</span></div>\'
			+\'<div class="nw-deck-bar-track"><div class="nw-deck-bar-fill \'+colorPrefix+k+\'" style="width:\'+pct+\'%"></div></div>\'
			+\'</div>\';
	}).join("");
	$w.html(html);
}

function renderAlerts(alerts){
	var w=$("#nw-alerts");
	if(!alerts||!alerts.length){
		w.html(\'<div class="nw-alert-card nw-alert-ok"><div class="nw-alert-dot"></div><div class="nw-alert-body"><div class="nw-alert-label">System</div><div class="nw-alert-text">No immediate operational issues detected.</div></div></div>\');
		return;
	}
	w.html(alerts.map(function(a){
		return\'<div class="nw-alert-card nw-alert-\'+escapeHtml(a.level||"info")+\'"><div class="nw-alert-dot"></div><div class="nw-alert-body"><div class="nw-alert-label">\'+escapeHtml(a.label||"Notice")+\'</div><div class="nw-alert-text">\'+escapeHtml(a.text||"")+\'</div></div></div>\';
	}).join(""));
}

function renderLogs(logs){
	var w=$("#nw-logs");
	if(!logs||!logs.length){w.html(\'<div class="nw-empty-state">No recent system events.</div>\');return;}
	w.html(logs.map(function(log){
		var lvl=String(log.level||"info").toLowerCase();
		if(["info","warn","error"].indexOf(lvl)===-1)lvl="info";
		var dataText="";
		if(log.data!==null&&typeof log.data!=="undefined"&&String(log.data).length){
			dataText=typeof log.data==="object"?JSON.stringify(log.data):String(log.data);
		}
		return\'<div class="nw-log-item">\'
			+\'<div class="nw-log-top"><span class="nw-log-level nw-log-level-\'+escapeHtml(lvl)+\'">\'+escapeHtml(lvl)+\'</span><span class="nw-log-date">\'+escapeHtml(log.created_at||"")+\'</span></div>\'
			+\'<div class="nw-log-message">\'+escapeHtml(log.message||"(no message)")+\'</div>\'
			+\'<div class="nw-log-meta">context: \'+escapeHtml(log.context||"&#8212;")+(dataText?" | data: "+escapeHtml(dataText):"")+\'</div>\'
			+\'</div>\';
	}).join(""));
}

function loadDashboard(){
	$("#nw-stat-characters,#nw-stat-worlds,#nw-stat-campaigns,#nw-stat-deck-cards").html(\'<div class="nw-spinner"></div>\');
	$("#nw-recent-characters,#nw-recent-worlds,#nw-recent-campaigns,#nw-recent-deck-cards").text("Last 7d: \u2014");
	$("#nw-health-worlds-without-campaigns,#nw-health-campaigns-without-character").text("\u2014");
	$("#nw-alerts").html(\'<div class="nw-alert-card nw-alert-card-loading"><div class="nw-spinner"></div><span>Refreshing\u2026</span></div>\');
	$("#nw-logs").html(\'<div class="nw-empty-state">Loading recent events\u2026</div>\');
	$("#nw-chart-characters,#nw-chart-worlds,#nw-chart-campaigns,#nw-chart-deck-cards").html(\'<div class="nw-chart-empty">Loading\u2026</div>\');
	$("#nw-deck-categories,#nw-deck-rarities").html(\'<div class="nw-spinner" style="margin:20px auto;display:block;"></div>\');
	$("#nw-deck-active,#nw-deck-inactive").text("\u2014");

	$.post(ajaxurl,{action:"nw_dashboard_data",nonce:$("#nw-dash-nonce").val()},function(res){
		if(!res.success){
			$("#nw-stat-characters,#nw-stat-worlds,#nw-stat-campaigns,#nw-stat-deck-cards").text("\u2014");
			renderAlerts([{level:"warn",label:"Dashboard",text:(res.data||"Could not load dashboard data.")}]);
			$("#nw-logs").html(\'<div class="nw-empty-state">Could not load logs.</div>\');
			return;
		}
		var d=res.data||{},c=d.counts||{},r=d.recent||{},g=d.growth||{},h=d.health||{},deck=d.deck_breakdown||{};

		// Debug info in console
		if(d._debug){console.group("[NeoWeaver] Dashboard debug");console.log("Key type:",d._debug.key_type);console.table(d._debug.growth_meta);console.groupEnd();}

		$("#nw-stat-characters").text(c.characters||0);
		$("#nw-stat-worlds").text(c.worlds||0);
		$("#nw-stat-campaigns").text(c.campaigns||0);
		$("#nw-stat-deck-cards").text(c.deck_cards||0);

		$("#nw-recent-characters").text("Last 7d: +"+(r.characters_7d||0));
		$("#nw-recent-worlds").text("Last 7d: +"+(r.worlds_7d||0));
		$("#nw-recent-campaigns").text("Last 7d: +"+(r.campaigns_7d||0));
		$("#nw-recent-deck-cards").text("Last 7d: +"+(r.deck_cards_7d||0));

		$("#nw-health-worlds-without-campaigns").text(h.worlds_without_campaigns||0);
		$("#nw-health-campaigns-without-character").text(h.campaigns_without_character||0);

		drawSparkline("#nw-chart-characters",g.characters,"#adff00");
		drawSparkline("#nw-chart-worlds",g.worlds,"#00d4ff");
		drawSparkline("#nw-chart-campaigns",g.campaigns,"#ffb703");
		drawSparkline("#nw-chart-deck-cards",g.deck_cards,"#ff5c5c");

		// Deck Library
		if(deck.categories){renderDeckBars("#nw-deck-categories",deck.categories,"nw-deck-cat-");}
		if(deck.rarities){renderDeckBars("#nw-deck-rarities",deck.rarities,"nw-deck-rar-");}
		if(typeof deck.active_count!=="undefined"){
			$("#nw-deck-active").text(deck.active_count||0);
			$("#nw-deck-inactive").text(deck.inactive_count||0);
		}

		renderAlerts(d.alerts||[]);
		renderLogs(d.logs||[]);
	});
}

loadDashboard();
$("#nw-refresh-dashboard").on("click",function(){loadDashboard();});

});
