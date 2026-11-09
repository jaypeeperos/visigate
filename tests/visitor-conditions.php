<?php
/** Standalone regression checks: php tests/visitor-conditions.php [pro|legacy] */
define( 'ABSPATH', __DIR__ );
$hooks = array();
function __( $s, $domain = '' ) { return $s; }
function add_filter( $name, $callback, ...$args ) { $GLOBALS['hooks'][ $name ][] = $callback; }
function apply_filters( $name, $value, ...$args ) {
	foreach ( $GLOBALS['hooks'][ $name ] ?? array() as $cb ) { $value = $cb( $value, ...$args ); }
	return $value;
}
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function wp_unslash( $s ) { return stripslashes( $s ); }
function esc_url_raw( $s ) { return $s; }
function wp_roles() { return (object) array( 'roles' => array() ); }
function get_post_types( ...$args ) { return array(); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_admin() { return false; }
function do_action( $name ) { $GLOBALS['actions'][] = $name; }
class Visigate_Admin { public static function opt( $key ) { return true; } }
function expect( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
require __DIR__ . '/../includes/class-conditions.php';
require __DIR__ . '/../includes/class-engine.php';
$mode = $argv[1] ?? 'free';
if ( 'pro' === $mode ) {
	class Visigate_Pro_Plans {
		const BUSINESS = 'business';
		public static function has( $plan ) { return false; }
		public static function label( $plan ) { return $plan; }
	}
	require __DIR__ . '/../../visigate-pro/includes/class-countries.php';
	require __DIR__ . '/../../visigate-pro/includes/class-pro-conditions.php';
	Visigate_Pro_Conditions::init();
} elseif ( 'legacy' === $mode ) {
	add_filter( 'visigate_conditions', function ( $defs ) {
		foreach ( array( 'url_param', 'cookie', 'referrer' ) as $type ) {
			$defs[ $type ]['pro'] = true;
			$defs[ $type ]['locked'] = true;
		}
		return $defs;
	} );
}
$all = Visigate_Conditions::all();
foreach ( array( 'url_param', 'cookie', 'referrer' ) as $type ) {
	expect( ! $all[ $type ]['pro'] && ! $all[ $type ]['locked'] && $all[ $type ]['dynamic'], "$type must stay free and dynamic" );
}
expect( ! in_array( 'Cookie', array_column( Visigate_Conditions::teasers(), 'label' ), true ), 'Remove paid teasers' );
foreach ( array( 'url_param', 'cookie' ) as $type ) {
	foreach ( array( null, '', 'VIP', array( 'VIP' ) ) as $raw ) {
		$_GET = $_COOKIE = null === $raw ? array() : array( 'campaign' => $raw );
		$expected = array(
			'exists' => 'VIP' === $raw, 'not_exists' => 'VIP' !== $raw,
			'equals' => 'VIP' === $raw, 'not_equals' => 'VIP' !== $raw,
			'contains' => 'VIP' === $raw,
		);
		foreach ( $expected as $op => $hit ) {
			$c = array( 'type' => $type, 'op' => $op, 'value' => array( 'key' => 'campaign', 'value' => 'contains' === $op ? 'vi' : 'VIP' ) );
			expect( $hit === Visigate_Engine::check( $c ), "$type $op handles missing, empty, scalar and array values" );
		}
	}
	$rules = array( 'action' => 'show', 'groups' => array( array( 'conditions' => array( array( 'type' => $type, 'op' => 'exists', 'value' => array( 'key' => 'campaign', 'value' => '' ) ) ) ) ) );
	expect( $rules === Visigate_Conditions::sanitize( $rules ), 'Saved rules survive sanitization' );
	expect( Visigate_Conditions::is_complete( $rules['groups'][0]['conditions'][0] ), 'Existence needs only a key' );
	$_GET = $_COOKIE = array( 'campaign' => 'VIP' );
	expect( Visigate_Engine::visible( $rules ), 'Saved show rule works without a license' );
	$rules['action'] = 'hide';
	expect( ! Visigate_Engine::visible( $rules ), 'Saved hide rule works without a license' );
}
foreach ( array( '', 'https://GOOGLE.com/search', array() ) as $ref ) {
	$_SERVER['HTTP_REFERER'] = $ref;
	$c = array( 'type' => 'referrer', 'op' => 'contains', 'value' => 'google.com' );
	expect( is_string( $ref ) && '' !== $ref ? Visigate_Engine::check( $c ) : ! Visigate_Engine::check( $c ), 'Referrer is case insensitive and handles missing or malformed headers' );
	$c['op'] = 'not_contains';
	expect( ( '' === $ref || is_array( $ref ) ) === Visigate_Engine::check( $c ), 'Negative referrer match' );
}
expect( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'Visitor rules protect page caches' );
expect( ! Visigate_Engine::check( array( 'type' => 'unknown', 'op' => 'is' ) ), 'Unknown rules still fail closed' );
// Unsanitized block attributes must never fatal: array keys, string values and array needles.
$_GET = $_COOKIE = array( 'campaign' => 'VIP' );
foreach ( array( array( 'key' => array( 'campaign' ), 'value' => 'VIP' ), 'campaign', array( 'key' => 'campaign', 'value' => array( 'VIP' ) ), null ) as $bad ) {
	foreach ( array( 'url_param', 'cookie' ) as $type ) {
		expect( ! Visigate_Engine::check( array( 'type' => $type, 'op' => 'equals', 'value' => $bad ) ), "$type with a malformed value fails closed" );
	}
}
$_SERVER['HTTP_REFERER'] = 'https://google.com/';
expect( ! Visigate_Engine::check( array( 'type' => 'referrer', 'op' => 'contains', 'value' => array( 'google' ) ) ), 'Referrer with a malformed value fails closed' );
if ( 'pro' === $mode ) {
	class Visigate_View_As {
		public static function is_active() { return true; }
		public static function extra( $key, $default = null ) {
			return array( 'cookies' => array( 'campaign' => 'preview' ), 'referrer' => 'https://preview.example/' )[ $key ] ?? $default;
		}
	}
	require __DIR__ . '/../../visigate-pro/includes/class-view-as-pro.php';
	expect( Visigate_Engine::check( array( 'type' => 'cookie', 'op' => 'equals', 'value' => array( 'key' => 'campaign', 'value' => 'preview' ) ) ), 'Pro cookie preview survives the move' );
	expect( Visigate_Engine::check( array( 'type' => 'referrer', 'op' => 'contains', 'value' => 'preview.example' ) ), 'Pro referrer preview survives the move' );
}
echo "PASS: visitor conditions ($mode).\n";
