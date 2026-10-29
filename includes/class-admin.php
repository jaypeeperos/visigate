<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings page + asset loading for the visual builder (block editor + Rule Set screen).
 * Nothing in here loads on the public front end.
 */
class Visigate_Admin {

	const OPTION = 'visigate_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor' ) );
		add_filter(
			'option_page_capability_visigate',
			function () {
				return visigate_capability();
			}
		);
	}

	public static function opt( $key ) {
		$defaults = array( 'auto_nocache' => 1, 'admin_preview' => 1 );
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
			visigate_capability(),
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
					);
				},
			)
		);
	}

	public static function render_settings() {
		?>
		<div class="wrap">
			<h1><?php /* translators: %s: plugin name */ echo esc_html( sprintf( __( '%s Settings', 'visigate' ), visigate_brand() ) ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'visigate' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Cache safety', 'visigate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[auto_nocache]" value="1" <?php checked( self::opt( 'auto_nocache' ) ); ?>>
								<?php esc_html_e( 'Automatically exclude a page from page caching when it uses visitor-specific rules (device, time, and Pro conditions such as country, URL, cookie, referrer, cart).', 'visigate' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Role, login and purchase-history rules need no exclusion because logged-in users already bypass most page caches.', 'visigate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Admin preview', 'visigate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[admin_preview]" value="1" <?php checked( self::opt( 'admin_preview' ) ); ?>>
								<?php esc_html_e( 'Add an admin-bar toggle that reveals hidden content (outlined in red) so you can debug rules.', 'visigate' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<h2><?php esc_html_e( 'Developer reference', 'visigate' ); ?></h2>
			<p><code>visigate_visible( $set_id_or_rules )</code> — <?php esc_html_e( 'check a rule set in PHP templates.', 'visigate' ); ?></p>
			<p><code>visigate_conditions</code>, <code>visigate_check_condition</code>, <code>visigate_condition_options</code>, <code>visigate_rest_search</code>, <code>visigate_is_pro</code>, <code>visigate_hidden_block_output</code>, <code>visigate_hidden_shortcode_output</code>, <code>visigate_brand_name</code>, <code>visigate_user_can_manage</code>, <code>visigate_js_data</code> — <?php esc_html_e( 'filters.', 'visigate' ); ?></p>
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
			);
		}
		$data = array(
			'conditions' => $conds,
			'groups'     => Visigate_Conditions::groups(),
			'options'    => Visigate_Conditions::options(),
			'sets'       => Visigate_Ruleset::all_sets(),
			'isPro'      => visigate_is_pro(),
			'teasers'    => class_exists( 'Visigate_Pro_Conditions' ) ? array() : Visigate_Conditions::teasers(),
			'upgradeUrl' => esc_url_raw( (string) apply_filters( 'visigate_upgrade_url', 'https://persjay.com/plugins/visigate/#pricing' ) ),
			'newSetUrl'  => admin_url( 'post-new.php?post_type=' . Visigate_Ruleset::CPT ),
			'canManage'  => current_user_can( visigate_capability() ),
			'brand'      => visigate_brand(),
		);
		return apply_filters( 'visigate_js_data', $data );
	}

	private static function enqueue_builder() {
		wp_enqueue_style( 'visigate-builder', VISIGATE_URL . 'assets/css/builder.css', array( 'wp-components' ), VISIGATE_VERSION );
		wp_enqueue_script( 'visigate-builder', VISIGATE_URL . 'assets/js/builder.js', array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-hooks' ), VISIGATE_VERSION, true );
		wp_set_script_translations( 'visigate-builder', 'visigate' );
		wp_add_inline_script( 'visigate-builder', 'window.VisigateData = ' . wp_json_encode( self::js_data() ) . ';', 'before' );
	}

	public static function enqueue_admin( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || Visigate_Ruleset::CPT !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		self::enqueue_builder();
		wp_enqueue_script( 'visigate-admin', VISIGATE_URL . 'assets/js/admin.js', array( 'visigate-builder' ), VISIGATE_VERSION, true );
		wp_set_script_translations( 'visigate-admin', 'visigate' );
	}

	public static function enqueue_editor() {
		self::enqueue_builder();
		wp_enqueue_script(
			'visigate-editor',
			VISIGATE_URL . 'assets/js/editor.js',
			array( 'visigate-builder', 'wp-hooks', 'wp-compose', 'wp-block-editor', 'wp-blocks', 'wp-i18n' ),
			VISIGATE_VERSION,
			true
		);
		wp_set_script_translations( 'visigate-editor', 'visigate' );
	}
}
