<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_connect_campaign_world_render' ) ) {
	function tw_connect_campaign_world_render(): string {
		if ( ! is_user_logged_in() ) {
			return '<p class="tw-message">UPLINK REQUIRED. IDENTIFY YOURSELF, FIELD AGENT.</p>';
		}

		if ( function_exists( 'tw_enqueue_connect_campaign_world_assets' ) ) {
			tw_enqueue_connect_campaign_world_assets();
		}

		ob_start();
		?>
		<span id="connector" style="display:block; height:0; overflow:hidden;" aria-hidden="true"></span>

		<div id="tw-deployment-root" class="tw-deployment-main-container" data-tw-connect-campaign-world="1">
			<audio
				id="tw-glitch-sound"
				src="https://cyber.nieodparady.pl/wp-content/uploads/2026/02/soundreality-glitch-177348.mp3"
				preload="auto"></audio>

			<section class="tw-briefing-hero">
				<div class="tw-hero-overlay"></div>

				<div class="tw-hero-content">
					<div class="tw-hero-text">
						<span class="tw-label-alt">MISSION PARAMETERS</span>
						<h1>ANCHORING THE NODE</h1>
						<p>
							Field Agent, you are about to merge a narrative thread with a physical reality node.
							This deployment will stabilise the local sector for multiplayer synchronisation.
						</p>
					</div>

					<div class="tw-hero-stats">
						<div class="tw-hero-stat-item">
							<span class="n" id="stat-latency">0.024</span>
							<span class="l">LATENCY</span>
						</div>

						<div class="tw-hero-stat-item">
							<span class="n">STABLE</span>
							<span class="l">NODE FLUX</span>
						</div>

						<div class="tw-hero-stat-item tw-pulse-stat">
							<span class="n">ACTIVE</span>
							<span class="l">UPLINK</span>
						</div>
					</div>
				</div>
			</section>

			<div class="tw-deploy-grid">
				<div class="tw-deploy-controls">
					<div id="tw-world-console" class="tw-console-box">
						&gt; System: Initializing Deployment Interface...
					</div>

					<form id="tw-anchor-form" class="tw-form-layout">
						<div class="tw-selection-group">
							<div class="tw-field-box">
								<label for="f-camp">
									<i class="dashicons dashicons-backup"></i>
									SOURCE: DEPLOYMENT (Campaign)
								</label>

								<div class="tw-input-wrapper">
									<input
										type="text"
										id="f-camp"
										class="tw-input-cyber"
										placeholder="Search deployments...">
									<select id="s-camp" class="tw-select-cyber" size="6" required></select>
								</div>
							</div>

							<div class="tw-field-box">
								<label for="f-world">
									<i class="dashicons dashicons-networking"></i>
									DESTINATION: THE NODE (World)
								</label>

								<div class="tw-input-wrapper">
									<input
										type="text"
										id="f-world"
										class="tw-input-cyber"
										placeholder="Locate node...">
									<select id="s-world" class="tw-select-cyber" size="6" required></select>
								</div>
							</div>
						</div>

						<button type="submit" id="b-connect" class="tw-btn-deploy" disabled>
							EXECUTE DEPLOYMENT [ENTER]
						</button>
					</form>
				</div>

				<aside class="tw-deploy-sidebar">
					<div class="tw-sidebar-card">
						<h4><i class="dashicons dashicons-info"></i> PROTOCOL BINDING</h4>
						<p>
							Once anchored, the <strong>Deployment</strong> consumes the <strong>Node's</strong>
							resources. Other Field Agents can then synchronize via the Multiplayer Frequency.
						</p>
					</div>

					<div class="tw-sidebar-card" style="margin-top:16px;">
						<h4><i class="dashicons dashicons-admin-users"></i> AGENT BINDING</h4>
						<p>
							You can assign a <strong>Field Agent</strong> to this Deployment later from the
							Deployment management panel via <code>cyber_campaign_characters</code>.
						</p>
					</div>

					<div class="tw-sidebar-card" style="margin-top:16px;">
						<h4><i class="dashicons dashicons-plus-alt"></i> NEW DEPLOYMENT</h4>
						<p>No deployment yet? Initialize a new mission thread first.</p>
						<a href="/new-deployment/" class="tw-btn-outline">
							+ NEW DEPLOYMENT
						</a>
					</div>
				</aside>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}

add_shortcode( 'tw_connect_campaign_world', 'tw_connect_campaign_world_render' );
