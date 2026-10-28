<?php
/**
 * Plugin Name:       Visigate – Conditional Content & Visibility Rules
 * Plugin URI:        https://example.com/visigate
 * Description:       Show or hide any block or page-builder element by role, login status, country, device, time, URL/cookie, or WooCommerce purchase history. Visual rule builder, server-side evaluation, zero front-end JavaScript.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       visigate
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'VISIGATE_VERSION', '1.1.0' );
define( 'VISIGATE_FILE', __FILE__ );
define( 'VISIGATE_DIR', plugin_dir_path( __FILE__ ) );
define( 'VISIGATE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Is the Pro add-on licensed and active? Visigate Pro hooks this filter.
 * Pro conditions themselves are registered by the Visigate Pro plugin, not by this one.
 */
function visigate_is_pro() {
	return (bool) apply_filters( 'visigate_is_pro', false );
}

/**
 * Template helper. Pass a rule-set ID or a rules array.
 *
 *   if ( visigate_visible( 42 ) ) { ... }
 *
 * @param int|array $rules_or_set_id
 */
function visigate_visible( $rules_or_set_id ) {
	if ( is_numeric( $rules_or_set_id ) ) {
		return Visigate_Engine::set_visible( (int) $rules_or_set_id );
	}
	return Visigate_Engine::visible( $rules_or_set_id );
}

require_once VISIGATE_DIR . 'includes/class-conditions.php';
require_once VISIGATE_DIR . 'includes/class-engine.php';
require_once VISIGATE_DIR . 'includes/class-ruleset.php';
require_once VISIGATE_DIR . 'includes/class-frontend.php';
require_once VISIGATE_DIR . 'includes/class-admin.php';
require_once VISIGATE_DIR . 'includes/integrations/elementor.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VISIGATE_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		Visigate_Engine::init();
		Visigate_Ruleset::init();
		Visigate_Frontend::init();
		Visigate_Admin::init();
	}
);
