<?php
/** Reproduce Elementor 4's actual loader hooks: php tests/elementor-assets.php */
define( 'ABSPATH', __DIR__ );
define( 'VISIGATE_VERSION', '1.3.5' );
define( 'VISIGATE_DIR', dirname( __DIR__ ) . '/' );
define( 'VISIGATE_URL', '/plugins/visigate/' );
define( 'VISIGATE_PRO_VERSION', '1.3.3' );
define( 'VISIGATE_PRO_FILE', __DIR__ . '/../../visigate-pro/visigate-pro.php' );
$hooks = array();
$scripts = array();
$styles = array();
$can_manage = true;
function add_action( $name, $callback, $priority = 10 ) { $GLOBALS['hooks'][ $name ][ $priority ][] = $callback; }
function add_filter( $name, $callback ) {}
function do_action( $name ) {
	$callbacks = $GLOBALS['hooks'][ $name ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $group ) {
		foreach ( $group as $callback ) { $callback(); }
	}
}
function apply_filters( $name, $value ) { return $value; }
function current_user_can( $cap ) { return $GLOBALS['can_manage']; }
function visigate_capability() { return 'visigate_manage'; }
function visigate_is_pro() { return false; }
function visigate_brand() { return 'Visigate'; }
function admin_url( $path ) { return $path; }
function esc_url_raw( $url ) { return $url; }
function plugin_dir_url( $file ) { return '/plugins/visigate-pro/'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_enqueue_script( $handle, $url, $deps, $version, $footer ) { $GLOBALS['scripts'][ $handle ] = compact( 'url', 'deps', 'version' ); }
function wp_enqueue_style( $handle, $url, $deps, $version ) { $GLOBALS['styles'][ $handle ] = compact( 'url', 'deps', 'version' ); }
function wp_script_is( $handle, $status ) { return 'registered' === $status ? 'elementor-v2-editor-editing-panel' === $handle : isset( $GLOBALS['scripts'][ $handle ] ); }
function wp_set_script_translations( $handle, $domain ) {}
function wp_add_inline_script( $handle, $script, $position ) {}
class Visigate_Ruleset {
	const CPT = 'visigate_set';
	public static function all_sets() { return array(); }
	public static function unpublished_sets() { return array(); }
}
class Visigate_Conditions {
	public static function all() { return array(); }
	public static function groups() { return array(); }
	public static function options() { return array(); }
	public static function teasers() { return array(); }
}
function check( $condition, $message ) { if ( ! $condition ) { throw new Exception( $message ); } }
require VISIGATE_DIR . 'includes/class-admin.php';
Visigate_Admin::init();
$pro = __DIR__ . '/../../visigate-pro/includes/class-pro-admin.php';
if ( is_file( $pro ) ) {
	require $pro;
	Visigate_Pro_Admin::init();
}
// Editor_Loader::do_editor_action('scripts/enqueue') dispatches these hooks.
// It never dispatches the old elementor/editor/after_enqueue_scripts hook.
foreach ( array( 'elementor/editor/scripts/enqueue', 'elementor/editor/v1/scripts/enqueue', 'elementor/editor/v2/scripts/enqueue' ) as $hook ) {
	do_action( $hook );
}
check( isset( $scripts['visigate-elementor'] ), 'Elementor 4 must load the classic custom control adapter' );
check( isset( $scripts['visigate-builder'], $scripts['visigate-elementor-atomic'] ), 'Load shared builder and atomic controls too' );
check( in_array( 'visigate-builder', $scripts['visigate-elementor']['deps'], true ), 'Builder must load before classic adapter' );
check( $scripts['visigate-elementor']['version'] !== VISIGATE_VERSION, 'Rebuilt adapter must have its own cache version' );
check( isset( $styles['visigate-elementor'] ), 'Elementor styling must load with the controls' );
if ( is_file( $pro ) ) {
	check( isset( $scripts['visigate-pro-builder'] ), 'Load Pro presets on the current editor hooks' );
}
// Older Elementor installations retain their original asset hook.
$scripts = array();
do_action( 'elementor/editor/after_enqueue_scripts' );
check( isset( $scripts['visigate-elementor'] ), 'Preserve legacy editor loading' );
$scripts = array();
$can_manage = false;
do_action( 'elementor/editor/scripts/enqueue' );
check( ! isset( $scripts['visigate-elementor'] ), 'Restricted editors must not load management controls' );
$can_manage = true;
Visigate_Admin::enqueue_editor();
check( ! in_array( 'wp-interface', $scripts['visigate-editor']['deps'], true ), 'Do not depend on a standalone interface handle; current WordPress bundles it in wp-editor' );
check( in_array( 'wp-editor', $scripts['visigate-editor']['deps'], true ), 'Load the editor APIs before the sidebar adapter' );
echo "PASS: actual Elementor 4 loader sequence, classic/atomic assets, Pro presets, cache versions and legacy loading.\n";
