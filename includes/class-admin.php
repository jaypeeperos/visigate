<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings page + visual builder assets for blocks, Elementor and Rule Sets.
 * Nothing in here loads on the public front end.
 */
class Visigate_Admin {

	const OPTION = 'visigate_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor' ) );
		add_action( 'enqueue_block_assets', array( __CLASS__, 'enqueue_canvas' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( __CLASS__, 'enqueue_elementor' ) );
		add_action( 'elementor/editor/scripts/enqueue', array( __CLASS__, 'enqueue_elementor' ) );
		add_action( 'elementor/editor/v2/scripts/enqueue', array( __CLASS__, 'enqueue_elementor_atomic' ) );
		add_filter(
			'option_page_capability_visigate',
			function () {
				return 'manage_options'; // Only administrators change Visigate settings.
			}
		);
	}

	public static function opt( $key ) {
		$defaults = array( 'auto_nocache' => 1, 'admin_preview' => 1, 'view_as' => 1, 'manager' => 'manage_options' );
		$saved    = get_option( self::OPTION, array() );
		$all      = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/* ---------------------------- Settings ---------------------------- */

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Visigate_Ruleset::CPT,
			/* translators: %s: plugin name */
			sprintf( __( '%s Settings', 'visigate' ), visigate_brand() ),
			__( 'Settings', 'visigate' ),
			'manage_options',
			'visigate-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function register_settings() {
		register_setting(
			'visigate',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => function ( $in ) {
					return array(
						'auto_nocache'  => empty( $in['auto_nocache'] ) ? 0 : 1,
						'admin_preview' => empty( $in['admin_preview'] ) ? 0 : 1,
						'view_as'       => empty( $in['view_as'] ) ? 0 : 1,
						'manager'       => isset( $in['manager'] ) && 'edit_others_posts' === $in['manager'] ? 'edit_others_posts' : 'manage_options',
					);
				},
			)
		);
	}

	/** Licence details from Visigate Pro (Freemius), or null when Pro is not installed. */
	private static function licence() {
		if ( ! class_exists( 'Visigate_Pro_License' ) || ! class_exists( 'Visigate_Pro_Plans' ) ) {
			return null;
		}
		$out = array( 'plan' => '', 'active' => Visigate_Pro_License::is_active(), 'trial' => false, 'quota' => null, 'used' => null, 'expires' => '', 'lifetime' => false, 'account' => '', 'upgrade' => '', 'agency' => false );
		foreach ( array( Visigate_Pro_Plans::AGENCY, Visigate_Pro_Plans::BUSINESS, Visigate_Pro_Plans::PRO ) as $tier ) {
			if ( Visigate_Pro_Plans::has( $tier ) ) {
				$out['plan']   = Visigate_Pro_Plans::label( $tier );
				$out['agency'] = Visigate_Pro_Plans::AGENCY === $tier;
				break;
			}
		}
		$fs = Visigate_Pro_License::fs();
		if ( $fs ) {
			try {
				$out['trial']   = method_exists( $fs, 'is_trial' ) && $fs->is_trial();
				$out['account'] = method_exists( $fs, 'is_registered' ) && $fs->is_registered() && method_exists( $fs, 'get_account_url' ) ? (string) $fs->get_account_url() : '';
				$out['upgrade'] = method_exists( $fs, 'get_upgrade_url' ) ? (string) $fs->get_upgrade_url() : '';
				$lic            = method_exists( $fs, '_get_license' ) ? $fs->_get_license() : null;
				if ( is_object( $lic ) ) {
					$out['quota']    = isset( $lic->quota ) && null !== $lic->quota ? (int) $lic->quota : null;
					$out['used']     = isset( $lic->activated ) ? (int) $lic->activated + (int) ( $lic->activated_local ?? 0 ) : null;
					$out['lifetime'] = empty( $lic->expiration );
					$out['expires']  = ! empty( $lic->expiration ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $lic->expiration ) ) : '';
				}
			} catch ( \Throwable $e ) {
				// Licence details are a nicety: never break the settings screen over them.
				unset( $e );
			}
		}
		return $out;
	}

	/** Pages Visigate Pro registers under the Visigate menu, keyed by slug. */
	private static function pro_pages() {
		global $submenu;
		$out = array();
		foreach ( (array) ( $submenu[ Visigate_Admin_UI::parent_slug() ] ?? array() ) as $item ) {
			if ( isset( $item[2] ) && in_array( $item[2], array( 'visigate-pro-settings', 'visigate-templates', 'visigate-transfer' ), true ) ) {
				$out[ $item[2] ] = wp_strip_all_tags( (string) $item[0] );
			}
		}
		return $out;
	}

	public static function render_settings() {
		$name    = self::OPTION;
		$is_pro  = visigate_is_pro();
		$pro     = self::pro_pages();
		$lic     = self::licence();
		$upgrade = (string) apply_filters( 'visigate_upgrade_url', 'https://persjay.com/plugins/visigate/#pricing' );
		$own     = 'Visigate' === visigate_brand();
		$toggles = array(
			'auto_nocache'  => array(
				__( 'Cache safety', 'visigate' ),
				__( 'Automatically exclude a page from page caching when it uses user, device, time or visitor-specific Pro rules such as country or cart.', 'visigate' ),
				__( 'Role, login and purchase-history rules need no exclusion: logged-in users already bypass most page caches.', 'visigate' ),
			),
			'admin_preview' => array(
				__( 'Admin preview', 'visigate' ),
				__( 'Add an admin-bar toggle that reveals hidden content, outlined, so you can debug rules.', 'visigate' ),
				__( 'Only you see the outlines. Visitors never do.', 'visigate' ),
			),
			'view_as'       => array(
				__( 'View as', 'visigate' ),
				__( 'Add a “View as” menu to the admin bar to preview pages as a logged-out visitor, another role or another device, without logging out.', 'visigate' ),
				__( 'Also adds the rule inspector, which shows which conditions passed or failed for every rule on the page.', 'visigate' ),
			),
		);
		$managers = array(
			'manage_options'    => __( 'Administrators only', 'visigate' ),
			'edit_others_posts' => __( 'Administrators and Editors', 'visigate' ),
		);
		?>
		<div class="wrap vg-wrap vg-page">
			<h1 class="vg-sr"><?php /* translators: %s: plugin name */ echo esc_html( sprintf( __( '%s Settings', 'visigate' ), visigate_brand() ) ); ?></h1>
			<hr class="wp-header-end">
			<?php settings_errors(); ?>
			<div class="vg-cols">
				<div class="vg-col-main">
					<form method="post" action="options.php" class="vg-card" aria-labelledby="vg-set-general">
						<?php settings_fields( 'visigate' ); ?>
						<div class="vg-card-head"><h2 id="vg-set-general"><?php esc_html_e( 'General', 'visigate' ); ?></h2></div>
						<?php foreach ( $toggles as $key => $t ) : ?>
							<div class="vg-setting">
								<div class="vg-setting-main">
									<label class="vg-setting-title" for="vg-set-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $t[0] ); ?></label>
									<span class="vg-setting-desc"><?php echo esc_html( $t[1] ); ?></span>
									<span class="vg-setting-note"><?php echo esc_html( $t[2] ); ?></span>
									<?php if ( 'auto_nocache' === $key ) : ?>
										<span class="vg-setting-note"><a href="<?php echo esc_url( Visigate_Cache_Check::page_url() ); ?>"><?php esc_html_e( 'Run a cache check', 'visigate' ); ?></a> — <?php esc_html_e( 'see which caches are active on this site and which pages they could get wrong.', 'visigate' ); ?></span>
									<?php endif; ?>
								</div>
								<span class="vg-switch">
									<input type="checkbox" role="switch" id="vg-set-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="1" <?php checked( self::opt( $key ) ); ?>>
									<span class="vg-switch-track" aria-hidden="true"></span>
								</span>
							</div>
						<?php endforeach; ?>
						<div class="vg-setting">
							<div class="vg-setting-main">
								<label class="vg-setting-title" for="vg-set-manager"><?php esc_html_e( 'Who can manage rules', 'visigate' ); ?></label>
								<span class="vg-setting-desc"><?php esc_html_e( 'Roles that can create rule sets, set visibility on content and use View as. Only administrators can change these settings.', 'visigate' ); ?></span>
							</div>
							<select id="vg-set-manager" name="<?php echo esc_attr( $name . '[manager]' ); ?>">
								<?php foreach ( $managers as $cap => $label ) : ?>
									<option value="<?php echo esc_attr( $cap ); ?>" <?php selected( self::opt( 'manager' ), $cap ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="vg-card-foot vg-table-foot">
							<button type="submit" class="vg-btn is-primary is-lg"><?php esc_html_e( 'Save settings', 'visigate' ); ?></button>
						</div>
					</form>

					<section class="vg-card is-ghost" aria-labelledby="vg-set-pro">
						<div class="vg-card-head is-ghost-head"><h2 id="vg-set-pro"><?php esc_html_e( 'Pro features', 'visigate' ); ?> <span class="vg-pill"><?php esc_html_e( 'Pro', 'visigate' ); ?></span></h2></div>
						<div class="vg-setting is-ghost-row">
							<div class="vg-setting-main">
								<span class="vg-setting-title"><?php esc_html_e( 'Fallback content for hidden blocks', 'visigate' ); ?></span>
								<span class="vg-setting-desc"><?php esc_html_e( 'Show a pattern instead of a gap when content is hidden. Choose it in each rule set, in the Fallback content box.', 'visigate' ); ?></span>
							</div>
							<?php if ( $is_pro ) : ?>
								<a class="vg-btn" href="<?php echo esc_url( Visigate_Admin_Rules::url() ); ?>"><?php esc_html_e( 'Open rule sets', 'visigate' ); ?></a>
							<?php else : ?>
								<span class="vg-badge is-warn"><?php esc_html_e( 'Pro', 'visigate' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="vg-setting is-ghost-row">
							<div class="vg-setting-main">
								<span class="vg-setting-title"><?php esc_html_e( 'Rule templates', 'visigate' ); ?></span>
								<span class="vg-setting-desc"><?php esc_html_e( 'Start from ready-made rule sets such as business hours, new members or returning customers.', 'visigate' ); ?></span>
							</div>
							<?php if ( isset( $pro['visigate-templates'] ) ) : ?>
								<a class="vg-btn" href="<?php echo esc_url( Visigate_Admin_UI::page_url( 'visigate-templates' ) ); ?>"><?php esc_html_e( 'Browse templates', 'visigate' ); ?></a>
							<?php else : ?>
								<span class="vg-badge is-warn"><?php esc_html_e( 'Pro', 'visigate' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="vg-setting is-ghost-row">
							<div class="vg-setting-main">
								<span class="vg-setting-title"><?php esc_html_e( 'Import and export rule sets', 'visigate' ); ?></span>
								<span class="vg-setting-desc"><?php esc_html_e( 'Move rule sets between sites as a file.', 'visigate' ); ?></span>
							</div>
							<?php if ( isset( $pro['visigate-transfer'] ) ) : ?>
								<div class="vg-actions">
									<a class="vg-btn" href="<?php echo esc_url( Visigate_Admin_UI::page_url( 'visigate-transfer' ) ); ?>"><?php esc_html_e( 'Export', 'visigate' ); ?></a>
									<a class="vg-btn" href="<?php echo esc_url( Visigate_Admin_UI::page_url( 'visigate-transfer' ) ); ?>"><?php esc_html_e( 'Import', 'visigate' ); ?></a>
								</div>
							<?php else : ?>
								<span class="vg-badge is-warn"><?php esc_html_e( 'Pro', 'visigate' ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( isset( $pro['visigate-pro-settings'] ) ) : ?>
							<div class="vg-setting is-ghost-row">
								<div class="vg-setting-main">
									<span class="vg-setting-title"><?php echo esc_html( $pro['visigate-pro-settings'] ); ?></span>
									<span class="vg-setting-desc"><?php esc_html_e( 'Plan features, white-label branding and other Pro options.', 'visigate' ); ?></span>
								</div>
								<a class="vg-btn" href="<?php echo esc_url( Visigate_Admin_UI::page_url( 'visigate-pro-settings' ) ); ?>"><?php esc_html_e( 'Open', 'visigate' ); ?></a>
							</div>
						<?php endif; ?>
					</section>

					<section class="vg-card is-pad" aria-labelledby="vg-set-dev">
						<h2 id="vg-set-dev"><?php esc_html_e( 'Developer reference', 'visigate' ); ?></h2>
						<p class="vg-muted"><code>visigate_visible( $set_id_or_rules )</code> <?php esc_html_e( 'checks a rule set in PHP templates.', 'visigate' ); ?></p>
						<p class="vg-muted"><code>[visigate set="12"]…[/visigate]</code> <?php esc_html_e( 'wraps any content in a rule set.', 'visigate' ); ?></p>
						<details>
							<summary class="vg-details-summary"><?php esc_html_e( 'See all filters and hooks', 'visigate' ); ?></summary>
							<p class="vg-muted vg-codes"><code>visigate_conditions</code> <code>visigate_check_condition</code> <code>visigate_condition_options</code> <code>visigate_condition_groups</code> <code>visigate_rest_search</code> <code>visigate_device</code> <code>visigate_missing_set_visible</code> <code>visigate_is_pro</code> <code>visigate_upgrade_url</code> <code>visigate_hidden_block_output</code> <code>visigate_hidden_shortcode_output</code> <code>visigate_brand_name</code> <code>visigate_base_capability</code> <code>visigate_user_can_manage</code> <code>visigate_js_data</code> <code>visigate_usage_index</code> <code>visigate_usage_describe</code> <code>visigate_rules_subject</code> <code>visigate_loop_template_blocks</code> <code>visigate_page_types</code> <code>visigate_target_exists</code> <code>visigate_cache_detect</code> <code>visigate_admin_tabs</code></p>
						</details>
					</section>
				</div>

				<aside class="vg-col-side">
					<?php if ( $lic ) : ?>
						<section class="vg-card is-pad" aria-labelledby="vg-set-lic">
							<h2 id="vg-set-lic"><?php esc_html_e( 'Licence', 'visigate' ); ?></h2>
							<div class="vg-cell-line">
								<strong><?php echo esc_html( $lic['plan'] ? sprintf( /* translators: %s: plan name */ __( '%s plan', 'visigate' ), $lic['plan'] ) : __( 'No active plan', 'visigate' ) ); ?></strong>
								<?php if ( $lic['trial'] ) : ?>
									<span class="vg-badge is-info"><?php esc_html_e( 'Trial', 'visigate' ); ?></span>
								<?php elseif ( $lic['active'] ) : ?>
									<span class="vg-badge is-ok"><?php esc_html_e( 'Active', 'visigate' ); ?></span>
								<?php else : ?>
									<span class="vg-badge is-bad"><?php esc_html_e( 'Inactive', 'visigate' ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( null !== $lic['used'] ) : ?>
								<?php if ( null === $lic['quota'] ) : ?>
									<span class="vg-muted"><?php /* translators: %d: number of sites */ echo esc_html( sprintf( _n( '%d site in use · unlimited sites', '%d sites in use · unlimited sites', $lic['used'], 'visigate' ), $lic['used'] ) ); ?></span>
								<?php else : ?>
									<span class="vg-muted"><?php /* translators: 1: used, 2: allowed */ echo esc_html( sprintf( __( '%1$d of %2$d sites in use', 'visigate' ), $lic['used'], $lic['quota'] ) ); ?></span>
									<span class="vg-meter" aria-hidden="true"><span style="width:<?php echo (int) min( 100, $lic['quota'] ? round( 100 * $lic['used'] / $lic['quota'] ) : 0 ); ?>%"></span></span>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( $lic['expires'] ) : ?>
								<span class="vg-muted"><?php /* translators: %s: date */ echo esc_html( sprintf( __( 'Renews %s', 'visigate' ), $lic['expires'] ) ); ?></span>
							<?php elseif ( $lic['lifetime'] && null !== $lic['used'] ) : ?>
								<span class="vg-muted"><?php esc_html_e( 'Lifetime licence', 'visigate' ); ?></span>
							<?php endif; ?>
							<div class="vg-actions">
								<?php if ( $lic['account'] ) : ?>
									<a class="vg-btn" href="<?php echo esc_url( $lic['account'] ); ?>"><?php esc_html_e( 'Manage account', 'visigate' ); ?></a>
								<?php endif; ?>
								<?php if ( ! $lic['agency'] && $lic['upgrade'] ) : ?>
									<a class="vg-btn is-amber" href="<?php echo esc_url( $lic['upgrade'] ); ?>"><?php echo esc_html( $lic['active'] ? __( 'Upgrade to Agency', 'visigate' ) : __( 'See plans', 'visigate' ) ); ?></a>
								<?php endif; ?>
							</div>
						</section>
					<?php elseif ( ! $is_pro ) : ?>
						<section class="vg-card is-pad is-ghost" aria-labelledby="vg-set-gopro">
							<h2 id="vg-set-gopro"><?php esc_html_e( 'Visigate Pro', 'visigate' ); ?> <span class="vg-pill"><?php esc_html_e( 'Pro', 'visigate' ); ?></span></h2>
							<p><?php esc_html_e( 'Country, cookies, referrer and WooCommerce conditions, fallback content for hidden blocks, rule templates, and import and export.', 'visigate' ); ?></p>
							<a class="vg-btn is-amber" href="<?php echo esc_url( $upgrade ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro plans', 'visigate' ); ?></a>
						</section>
					<?php endif; ?>

					<?php if ( is_multisite() ) : ?>
						<section class="vg-card is-pad" aria-labelledby="vg-set-ms">
							<h2 id="vg-set-ms"><?php esc_html_e( 'Multisite network', 'visigate' ); ?></h2>
							<p class="vg-muted"><?php esc_html_e( 'Share rule sets across every site in a network. Included with the Agency plan, which covers unlimited sites.', 'visigate' ); ?></p>
							<?php if ( current_user_can( 'manage_network_options' ) && class_exists( 'Visigate_Pro_Network' ) ) : ?>
								<a href="<?php echo esc_url( network_admin_url( 'settings.php?page=visigate-network' ) ); ?>"><?php esc_html_e( 'Network settings', 'visigate' ); ?></a>
							<?php endif; ?>
						</section>
					<?php endif; ?>

					<?php do_action( 'visigate_settings_sidebar' ); ?>

					<?php if ( $own ) : ?>
						<section class="vg-card is-pad is-plain" aria-labelledby="vg-set-help">
							<h2 id="vg-set-help"><?php esc_html_e( 'Need a hand?', 'visigate' ); ?></h2>
							<a href="https://persjay.com/plugins/visigate/" target="_blank" rel="noopener"><?php esc_html_e( 'Documentation', 'visigate' ); ?></a>
							<a href="https://persjay.com/" target="_blank" rel="noopener"><?php esc_html_e( 'Other Persjay Apps plugins', 'visigate' ); ?></a>
						</section>
					<?php endif; ?>
				</aside>
			</div>
			<?php Visigate_Admin_UI::footer(); ?>
		</div>
		<?php
	}

	/* ----------------------------- Assets ----------------------------- */

	public static function js_data() {
		$conds = array();
		foreach ( Visigate_Conditions::all() as $key => $c ) {
			if ( 'woo' === $c['group'] && ! class_exists( 'WooCommerce' ) ) {
				continue;
			}
			$conds[ $key ] = array(
				'label'       => $c['label'],
				'group'       => $c['group'],
				'pro'         => (bool) $c['pro'],
				'locked'      => (bool) $c['locked'],
				'lock_label'  => (string) $c['lock_label'],
				'input'       => $c['input'],
				'options'     => $c['options'],
				'source'      => $c['source'],
				'ops'         => $c['ops'],
				'default_op'  => $c['default_op'],
				'days'        => (bool) $c['days'],
				'no_value'    => $c['no_value'],
				'key_label'   => $c['key_label'] ?? '',
				'placeholder' => $c['placeholder'] ?? '',
				'help'        => (string) ( $c['help'] ?? '' ),
				'list'        => ! empty( $c['list'] ),
				// Checks the current post or product, so it can follow a Query Loop item.
				'item'        => ! empty( $c['item'] ),
			);
		}
		$data = array(
			'conditions' => $conds,
			'groups'     => Visigate_Conditions::groups(),
			'options'    => Visigate_Conditions::options(),
			'sets'       => Visigate_Ruleset::all_sets(),
			'otherSets'  => Visigate_Ruleset::unpublished_sets(),
			'isPro'      => visigate_is_pro(),
			'teasers'    => class_exists( 'Visigate_Pro_Conditions' ) ? array() : Visigate_Conditions::teasers(),
			'upgradeUrl' => esc_url_raw( (string) apply_filters( 'visigate_upgrade_url', 'https://persjay.com/plugins/visigate/#pricing' ) ),
			'newSetUrl'  => admin_url( 'post-new.php?post_type=' . Visigate_Ruleset::CPT ),
			'canManage'  => current_user_can( visigate_capability() ),
			'brand'      => visigate_brand(),
			// Site-local "now" for rule warnings such as "this date range has ended".
			'siteNow'    => current_datetime()->format( 'Y-m-d\TH:i' ),
		);
		return apply_filters( 'visigate_js_data', $data );
	}

	private static function enqueue_builder() {
		wp_enqueue_style( 'visigate-builder', VISIGATE_URL . 'assets/css/builder.css', array( 'wp-components' ), self::asset_version( 'assets/css/builder.css' ) );
		wp_enqueue_script( 'visigate-builder', VISIGATE_URL . 'assets/js/builder.js', array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-hooks' ), self::asset_version( 'assets/js/builder.js' ), true );
		wp_set_script_translations( 'visigate-builder', 'visigate' );
		wp_add_inline_script( 'visigate-builder', 'window.VisigateData = ' . wp_json_encode( self::js_data() ) . ';', 'before' );
	}

	public static function enqueue_elementor() {
		if ( ! current_user_can( visigate_capability() ) ) {
			return;
		}
		self::enqueue_builder();
		wp_enqueue_script( 'visigate-elementor', VISIGATE_URL . 'assets/js/elementor.js', array( 'elementor-editor', 'visigate-builder' ), self::asset_version( 'assets/js/elementor.js' ), true );
		wp_enqueue_style( 'visigate-elementor', VISIGATE_URL . 'assets/css/elementor.css', array( 'visigate-builder' ), self::asset_version( 'assets/css/elementor.css' ) );
	}

	/** Refresh editor assets when a local build replaces the same plugin version. */
	private static function asset_version( $path ) {
		return VISIGATE_VERSION . '.' . filemtime( VISIGATE_DIR . $path );
	}

	public static function enqueue_elementor_atomic() {
		if ( ! current_user_can( visigate_capability() ) || ! wp_script_is( 'elementor-v2-editor-editing-panel', 'registered' ) ) {
			return;
		}
		self::enqueue_builder();
		wp_enqueue_script(
			'visigate-elementor-atomic',
			VISIGATE_URL . 'assets/js/elementor-atomic.js',
			array( 'visigate-builder', 'elementor-v2-editor-editing-panel', 'elementor-v2-editor-controls', 'elementor-v2-editor-props' ),
			self::asset_version( 'assets/js/elementor-atomic.js' ),
			true
		);
		wp_enqueue_style( 'visigate-elementor', VISIGATE_URL . 'assets/css/elementor.css', array( 'visigate-builder' ), self::asset_version( 'assets/css/elementor.css' ) );
	}

	public static function enqueue_admin( $hook ) {
		if ( 'nav-menus.php' === $hook && current_user_can( visigate_capability() ) ) {
			self::enqueue_builder();
			wp_enqueue_style( 'visigate-menus', VISIGATE_URL . 'assets/css/menus.css', array( 'visigate-builder' ), self::asset_version( 'assets/css/menus.css' ) );
			wp_enqueue_script( 'visigate-menus', VISIGATE_URL . 'assets/js/menus.js', array( 'visigate-builder' ), self::asset_version( 'assets/js/menus.js' ), true );
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || Visigate_Ruleset::CPT !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		self::enqueue_builder();
		wp_enqueue_script( 'visigate-admin', VISIGATE_URL . 'assets/js/admin.js', array( 'visigate-builder' ), self::asset_version( 'assets/js/admin.js' ), true );
		wp_set_script_translations( 'visigate-admin', 'visigate' );
	}

	/**
	 * Canvas cues (rule badges) must load inside the editor iframe. enqueue_block_assets also
	 * runs on the front end, so only load it in admin, where the editor lives.
	 */
	public static function enqueue_canvas() {
		if ( ! is_admin() ) {
			return;
		}
		wp_enqueue_style( 'visigate-editor-canvas', VISIGATE_URL . 'assets/css/editor-canvas.css', array( 'dashicons' ), self::asset_version( 'assets/css/editor-canvas.css' ) );
	}

	public static function enqueue_editor() {
		self::enqueue_builder();
		wp_enqueue_script(
			'visigate-editor',
			VISIGATE_URL . 'assets/js/editor.js',
			// wp-editor loads core/interface, including builds that bundle it
			// without registering a separate wp-interface script handle.
			array( 'visigate-builder', 'wp-hooks', 'wp-compose', 'wp-block-editor', 'wp-blocks', 'wp-i18n', 'wp-data', 'wp-plugins', 'wp-editor' ),
			self::asset_version( 'assets/js/editor.js' ),
			true
		);
		wp_set_script_translations( 'visigate-editor', 'visigate' );
	}
}
