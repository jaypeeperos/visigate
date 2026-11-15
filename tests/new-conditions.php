<?php
/** Standalone checks: php tests/new-conditions.php [none|wpml|polylang|translatepress] */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
$hooks = array();
$singular = true;
$language = 'pt_BR';
$acf = true;
$cart = null;
function __( $s, $domain = '' ) { return $s; }
function add_filter( $name, $cb, ...$args ) { $GLOBALS['hooks'][ $name ][] = $cb; }
function apply_filters( $name, $value, ...$args ) { foreach ( $GLOBALS['hooks'][ $name ] ?? array() as $cb ) { $value = $cb( $value, ...$args ); } return $value; }
function wp_parse_args( $v, $defaults ) { return array_merge( $defaults, $v ); }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function wp_unslash( $s ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function wp_roles() { return (object) array( 'roles' => array() ); }
function get_post_types( ...$args ) { return array(); }
function is_singular() { return $GLOBALS['singular']; }
function get_queried_object_id() { return 42; }
function get_post( $id ) { return 42 === $id ? (object) array( 'post_author' => 7 ) : null; }
function get_post_datetime( $id, $field, $source ) {
	expect( 'gmt' === $source, 'Use absolute GMT timestamps' );
	return new DateTimeImmutable( 'modified' === $field ? '2026-11-14 12:00:00 UTC' : '2026-11-05 00:00:00 UTC' );
}
function current_datetime() { return new DateTimeImmutable( '2026-11-15 00:00:00 UTC' ); }
class Visigate_View_As { public static function now() { return null; } }
class Visigate_Pro_Plans {
	const BUSINESS = 'business';
	public static function has( $plan ) { return false; }
	public static function label( $plan ) { return $plan; }
}
class WooCommerce {}
function wc_get_orders() { return array(); }
function WC() { return (object) array( 'cart' => $GLOBALS['cart'] ); }
class WC_Product {
	private $class;
	public function __construct( $class ) { $this->class = $class; }
	public function get_shipping_class_id() { return $this->class; }
}
class Test_Cart {
	public $items = array();
	public function get_cart() { return $this->items; }
	public function get_cart_contents_weight() { return 12.5; }
}
function get_field_object( $key, $id, $format, $load ) {
	if ( ! $GLOBALS['acf'] || 'unknown' === $key ) { return false; }
	if ( in_array( $key, array( 'rows', 'field_rows', 'empty', 'bad' ), true ) ) {
		expect( false === $load, 'Never load repeater rows' );
		return array( 'type' => 'repeater', 'name' => 'field_rows' === $key ? 'rows' : $key );
	}
	expect( 'options' === $id && false === $format && true === $load, 'Options use raw ACF options storage' );
	return array( 'type' => 'true_false', 'value' => true );
}
function acf_get_metadata( $id, $name ) {
	expect( in_array( $id, array( 42, 'options' ), true ), 'Correct repeater context' );
	return array( 'rows' => '3', 'empty' => null, 'bad' => array() )[ $name ];
}
function expect( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
require __DIR__ . '/../includes/class-conditions.php';
require __DIR__ . '/../includes/class-engine.php';
require __DIR__ . '/../includes/class-cache-check.php';
require __DIR__ . '/../../visigate-pro/includes/class-countries.php';
require __DIR__ . '/../../visigate-pro/includes/class-pro-conditions.php';
require __DIR__ . '/../../visigate-pro/includes/class-pro-woo.php';
Visigate_Pro_Conditions::init();
function check_new( $type, $op, $value ) { return Visigate_Engine::check( array( 'type' => $type, 'op' => $op, 'value' => $value ) ); }
function check_rows( $type, $key, $op, $n = '' ) { return check_new( $type, $op, array( 'key' => $key, 'value' => $n ) ); }
$defs = Visigate_Conditions::all();
foreach ( array( 'post_author', 'post_published_age', 'post_modified_age' ) as $type ) { expect( ! $defs[ $type ]['pro'] && $defs[ $type ]['item'], 'Free current-post conditions' ); }
expect( check_new( 'post_author', 'is_any', array( 7 ) ), 'Author matches' );
expect( ! check_new( 'post_author', 'is_none', array( array() ) ), 'Malformed author fails closed' );
expect( check_new( 'post_published_age', 'older', 9 ) && ! check_new( 'post_published_age', 'older', 10 ), 'Strict age boundary' );
expect( check_new( 'post_modified_age', 'newer', 1 ), 'Fractional elapsed days' );
expect( ! check_new( 'post_modified_age', 'older', -1 ), 'Reject negative age' );
$singular = false;
expect( ! check_new( 'post_author', 'is_none', array( 7 ) ), 'Archives fail closed' );
expect( ! check_new( 'post_published_age', 'older', 1 ), 'No post age on archive' );
expect( Visigate_Engine::with_subject( 42, function () { return check_new( 'post_author', 'is_any', array( 7 ) ); } ), 'Loop subject overrides page' );
expect( check_rows( 'acf_options_field', 'enabled', 'is_true' ), 'Options apply on archives' );
foreach ( array( 'acf_repeater', 'acf_options_repeater' ) as $type ) {
	$singular = true;
	expect( check_rows( $type, 'field_rows', 'has_rows' ), 'Repeater key resolves stored field name' );
	expect( check_rows( $type, 'rows', 'gte', '3' ) && ! check_rows( $type, 'rows', 'gte', '4' ), 'Count threshold' );
	expect( ! check_rows( $type, 'empty', 'has_rows' ) && check_rows( $type, 'empty', 'gte', '0' ), 'Unsaved repeater has zero rows' );
	foreach ( array( '-1', '1.5', 'bad', array() ) as $bad ) { expect( ! check_rows( $type, 'rows', 'gte', $bad ), 'Reject invalid row threshold' ); }
	expect( ! check_rows( $type, 'unknown', 'gte', '0' ) && ! check_rows( $type, 'bad', 'gte', '0' ), 'Unknown field and corrupt metadata fail closed' );
	$c = array( 'type' => $type, 'op' => 'has_rows', 'value' => array( 'key' => 'rows', 'value' => '' ) );
	expect( Visigate_Conditions::is_complete( $c ), 'Has rows needs no threshold' );
	$r = array( 'action' => 'show', 'groups' => array( array( 'conditions' => array( $c ) ) ) );
	expect( $r === Visigate_Conditions::sanitize( $r ), 'Repeater survives save' );
}
$acf = false;
expect( ! check_rows( 'acf_options_field', 'enabled', 'not_exists' ), 'Missing ACF fails closed' );
foreach ( array( 'Android Linux' => 'android', 'iPhone Mac OS X' => 'ios', 'iPad' => 'ios', 'Macintosh Intel Mac OS X' => 'macos', 'Windows NT 10.0' => 'windows', 'X11 CrOS' => 'chromeos', 'X11 Linux' => 'linux', 'bot' => 'other' ) as $ua => $os ) {
	$_SERVER['HTTP_USER_AGENT'] = $ua;
	expect( check_new( 'operating_system', 'is_any', array( $os ) ), 'OS detection order: ' . $os );
}
$_SERVER['HTTP_USER_AGENT'] = array();
expect( ! check_new( 'operating_system', 'is_none', array( 'ios' ) ), 'Missing UA matches neither operator' );
$dynamic = Visigate_Cache_Check::dynamic_types();
expect( isset( $dynamic['operating_system'], $dynamic['woo_cart_weight'], $dynamic['woo_cart_shipping_class'] ), 'Cache Check includes visitor rules' );
expect( ! isset( $dynamic['acf_options_field'], $dynamic['site_language'], $dynamic['post_published_age'] ), 'Site and age rules retain page caching' );
$cart = new Test_Cart();
expect( check_new( 'woo_cart_weight', 'gte', 12.5 ) && ! check_new( 'woo_cart_weight', 'gte', 13 ), 'Cart weight comparison' );
$cart->items = array( array( 'data' => new WC_Product( 12 ), 'quantity' => 2 ) );
expect( check_new( 'woo_cart_shipping_class', 'has', array( '12' ) ), 'Cart product class matches' );
expect( check_new( 'woo_cart_shipping_class', 'has_not', array( '13' ) ), 'Negative class rule' );
$cart->items = array();
expect( check_new( 'woo_cart_shipping_class', 'has_not', array( '12' ) ), 'Empty cart includes none' );
expect( ! check_new( 'woo_cart_weight', 'gte', 'bad' ), 'Reject malformed weight' );
expect( $defs['woo_cart_weight']['locked'] && $defs['woo_cart_shipping_class']['locked'], 'Business editor locks do not stop evaluation' );
$mode = $argv[1] ?? 'none';
if ( 'wpml' === $mode ) {
	define( 'ICL_SITEPRESS_VERSION', 'test' );
	add_filter( 'wpml_current_language', function () { return 'pt-br'; } );
	add_filter( 'wpml_active_languages', function () { return array( 'pt-br' => array( 'default_locale' => 'pt_BR' ) ); } );
} elseif ( 'polylang' === $mode ) {
	function pll_current_language( $field ) { return 'locale' === $field ? 'pt_BR' : 'pt-br'; }
} elseif ( 'translatepress' === $mode ) {
	class TRP_Translate_Press {}
	$GLOBALS['TRP_LANGUAGE'] = 'pt_BR';
}
if ( 'none' === $mode ) {
	expect( ! check_new( 'site_language', 'is_none', 'en' ), 'Missing multilingual plugin fails closed' );
} else {
	expect( check_new( 'site_language', 'is_any', 'de, pt_BR' ) && check_new( 'site_language', 'is_any', 'pt' ), 'Locale and base language match: ' . $mode );
	expect( check_new( 'site_language', 'is_none', 'en' ) && ! check_new( 'site_language', 'is_any', 'pt_PT' ), 'Regional distinction' );
	expect( ! check_new( 'site_language', 'is_none', '***' ), 'Invalid code fails closed' );
}
echo "PASS: new post, ACF, OS, cache, cart and language conditions ($mode).\n";
