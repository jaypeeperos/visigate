<?php
/**
 * Plugin Name:       Visigate – Conditional Content & Visibility Rules
 * Plugin URI:        https://github.com/jaypeeperos/visigate
 * Description:       Show or hide any block or Elementor element by role, login status, device, or time. Visual rule builder, server-side evaluation, zero front-end JavaScript.
 * Version:           1.3.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Jaypee Peros
 * Author URI:        https://github.com/jaypeeperos
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       visigate
 */

defined( 'ABSPATH' ) || exit;

define( 'VISIGATE_VERSION', '1.3.0' );
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
 * Display name used in admin menus and labels. Add-ons can rebrand it with the `visigate_brand_name` filter.
 */
function visigate_brand() {
	$name = trim( (string) apply_filters( 'visigate_brand_name', 'Visigate' ) );
	return '' !== $name ? $name : 'Visigate';
}

/**
 * Capability checked by every Visigate screen and save handler.
 *
 * It is granted on the fly (nothing is stored in the database) to users who have the base capability
 * (`manage_options`, filterable with `visigate_base_capability`), unless the `visigate_user_can_manage`
 * filter returns false for that user.
 */
function visigate_capability() {
	return 'visigate_manage';
}

add_filter(
	'user_has_cap',
	function ( $allcaps, $caps, $args, $user ) {
		if ( ! in_array( 'visigate_manage', (array) $caps, true ) ) {
			return $allcaps;
		}
		$base                       = (string) apply_filters( 'visigate_base_capability', 'manage_options' );
		$allcaps['visigate_manage'] = ! empty( $allcaps[ $base ] ) && (bool) apply_filters( 'visigate_user_can_manage', true, $user );
		return $allcaps;
	},
	10,
	4
);

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
