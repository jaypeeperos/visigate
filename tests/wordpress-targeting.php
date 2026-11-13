<?php
/** Real WordPress, isolated plugin loading and no database writes.
 * Stage both plugin folders under one temporary directory, then run:
 * php visigate/tests/wordpress-targeting.php /path/to/wp-load.php
 */
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) { exit( 2 ); }
define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );
// Preinitialized hooks work before WordPress loads. Only this PHP process sees them.
$GLOBALS['wp_filter']['pre_option_active_plugins'][10][] = array(
	'function' => function () { return array( 'visigate/visigate.php' ); }, 'accepted_args' => 1,
);
$GLOBALS['wp_filter']['pre_site_option_active_sitewide_plugins'][10][] = array(
	'function' => function () { return array(); }, 'accepted_args' => 1,
);
require $argv[1];
function vg_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
vg_assert( defined( 'VISIGATE_VERSION' ) && '1.5.3' === VISIGATE_VERSION, 'Load the staged Free plugin' );
wp_set_current_user( 0 );
class Visigate_Pro_Plans {
	const BUSINESS = 'business';
	public static function has( $plan ) { return false; }
	public static function label( $plan ) { return $plan; }
}
require WP_PLUGIN_DIR . '/visigate-pro/includes/class-countries.php';
require WP_PLUGIN_DIR . '/visigate-pro/includes/class-pro-conditions.php';
Visigate_Pro_Conditions::init();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/130.0.0.0 Safari/537.36';
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
function vg_rules( $type, $value ) {
	return array( 'action' => 'show', 'groups' => array( array( 'conditions' => array( array( 'type' => $type, 'op' => 'is_any', 'value' => $value ) ) ) ) );
}
function vg_block( $rules ) {
	return array( 'blockName' => 'core/paragraph', 'attrs' => array( 'visigateRules' => $rules ), 'innerBlocks' => array(), 'innerHTML' => '<p>Visitor content</p>', 'innerContent' => array( '<p>Visitor content</p>' ) );
}
$always = array( 'action' => 'show', 'groups' => array(), 'always_hide' => true );
$parsed = parse_blocks( serialize_block( vg_block( $always ) ) )[0];
vg_assert( $parsed['attrs']['visigateRules'] === $always, 'Always hide survives real block serialization' );
vg_assert( '' === render_block( $parsed ), 'Always hide stops rendering in real WordPress' );
foreach ( array( 'browser' => array( 'chrome' ), 'ip_address' => '192.0.2.10', 'ip_range' => '192.0.2.0/24' ) as $type => $value ) {
	$rules = Visigate_Conditions::sanitize( vg_rules( $type, $value ) );
	$parsed = parse_blocks( serialize_block( vg_block( $rules ) ) )[0];
	vg_assert( Visigate_Engine::visible( $rules ), 'Matching ' . $type . ' evaluates true' );
	$html = render_block( $parsed );
	vg_assert( false !== strpos( $html, 'Visitor content' ), 'Matching ' . $type . ' renders content: ' . $html );
	$rules['action'] = 'hide';
	vg_assert( '' === render_block( vg_block( $rules ) ), 'Matching hide ' . $type . ' renders nothing' );
}
vg_assert( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'Real render requests cache exclusion' );
// Substitute rule-set metadata in memory so the real shortcode/set path needs no posts.
$set = new WP_Post( (object) array( 'ID' => 999999, 'post_type' => 'visigate_set', 'post_status' => 'publish' ) );
wp_cache_add( 999999, $set, 'posts' );
add_filter( 'get_post_metadata', function ( $value, $id, $key ) use ( $always ) {
	return 999999 === (int) $id && Visigate_Ruleset::META === $key ? array( $always ) : $value;
}, 10, 3 );
vg_assert( '' === do_shortcode( '[visigate set="999999"]Hidden shortcode[/visigate]' ), 'Always hide saved set controls shortcodes' );
echo "PASS: real WordPress block serialization/rendering, visitor conditions, saved-set shortcode and cache exclusion.\n";
