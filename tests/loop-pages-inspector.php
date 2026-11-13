<?php
/** Standalone checks for 1.5.3 features: php tests/loop-pages-inspector.php [pro] */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
$hooks = array();
$GLOBALS['vg_query'] = array( 'singular' => false, 'id' => 0, 'types' => array() );
$GLOBALS['vg_posts'] = array();
$GLOBALS['vg_meta']  = array();
function __( $s, $domain = '' ) { return $s; }
function add_filter( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $name ][] = $callback; }
function add_action( ...$args ) {}
function apply_filters( $name, $value, ...$args ) {
	foreach ( $GLOBALS['hooks'][ $name ] ?? array() as $cb ) { $value = $cb( $value, ...$args ); }
	return $value;
}
function do_action( ...$args ) {}
function did_action( $name ) { return 0; }
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; }
function esc_url_raw( $s ) { return $s; }
function wp_roles() { return (object) array( 'roles' => array() ); }
function get_post_types( ...$args ) { return array(); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_admin() { return false; }
function wp_doing_ajax() { return ! empty( $GLOBALS['vg_ajax'] ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return $GLOBALS['vg_home'] . ltrim( $path, '/' ); }
function get_option( $name, $default = false ) { return $default; }
function is_singular( $types = '' ) {
	if ( ! $GLOBALS['vg_query']['singular'] ) { return false; }
	return '' === $types || in_array( get_post_type( $GLOBALS['vg_query']['id'] ), (array) $types, true );
}
function get_queried_object_id() { return $GLOBALS['vg_query']['id']; }
function get_post_type( $id ) { return $GLOBALS['vg_posts'][ $id ]['type'] ?? false; }
function is_post_type_archive( $types = '' ) { return false; }
foreach ( array( 'front_page' => 'is_front_page', 'posts_page' => 'is_home', 'archive' => 'is_archive', 'category' => 'is_category', 'tag' => 'is_tag', 'taxonomy' => 'is_tax', 'author' => 'is_author', 'date' => 'is_date', 'search' => 'is_search', '404' => 'is_404', 'attachment' => 'is_attachment', 'page' => 'is_page' ) as $key => $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( 'function ' . $fn . '( ...$a ) { return in_array( "' . $key . '", $GLOBALS["vg_query"]["types"], true ); }' );
	}
}
function taxonomy_exists( $t ) { return true; }
function absint( $v ) { return abs( (int) $v ); }
function current_datetime() { return new DateTimeImmutable( '2026-11-12 10:00:00', new DateTimeZone( 'UTC' ) ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function get_post( $id ) {
	$p = $GLOBALS['vg_posts'][ $id ] ?? null;
	return $p ? (object) array( 'ID' => $id, 'post_type' => $p['type'], 'post_status' => $p['status'] ?? 'publish', 'post_title' => $p['title'] ?? '' ) : null;
}
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['vg_meta'][ $id ][ $key ] ?? ''; }
class Visigate_Admin { public static function opt( $key ) { return true; } }
class Visigate_View_As {
	public static function now() { return null; }
	public static function device() { return null; }
	public static function is_active() { return false; }
	public static function logged_in() { return false; }
}
function wp_is_mobile() { return false !== stripos( $_SERVER['HTTP_USER_AGENT'] ?? '', 'mobile' ); }
class Visigate_Ruleset { const CPT = 'visigate_set'; const META = '_visigate_rules'; }
function expect( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }

require __DIR__ . '/../includes/class-conditions.php';
require __DIR__ . '/../includes/class-engine.php';
require __DIR__ . '/../includes/class-warnings.php';
require __DIR__ . '/../includes/class-inspector.php';
$mode = $argv[1] ?? 'free';
if ( 'pro' === $mode ) {
	class Visigate_Pro_Plans {
		const BUSINESS = 'business';
		public static function has( $plan ) { return false; }
		public static function label( $plan ) { return $plan; }
	}
	class Visigate_Frontend {
		public static $loop = 0;
		public static function loop_post_id() { return self::$loop; }
	}
	require __DIR__ . '/../../visigate-pro/includes/class-countries.php';
	require __DIR__ . '/../../visigate-pro/includes/class-pro-conditions.php';
	Visigate_Pro_Conditions::init();
}
function vg_rules( $conds, $action = 'show', $extra = array() ) {
	return array_merge( array( 'action' => $action, 'groups' => array( array( 'conditions' => $conds ) ) ), $extra );
}
function vg_page( $types, $id = 0 ) {
	$GLOBALS['vg_query'] = array( 'singular' => in_array( 'singular', $types, true ), 'id' => $id, 'types' => $types );
}

/* ---------------- Page types ---------------- */
$all = Visigate_Conditions::all();
expect( isset( $all['page_type'], $all['url_path'] ) && ! $all['page_type']['pro'] && ! $all['url_path']['pro'], 'Page type and URL path are free' );
expect( ! $all['page_type']['dynamic'] && ! $all['url_path']['dynamic'], 'Page targeting is cache-safe (varies by URL only)' );
$opts = Visigate_Conditions::options();
foreach ( array( 'front_page', 'posts_page', 'search', '404', 'author' ) as $key ) {
	expect( isset( $opts['page_types'][ $key ] ), "Page type option $key" );
}
vg_page( array( 'search' ) );
expect( Visigate_Engine::check( array( 'type' => 'page_type', 'op' => 'is_any', 'value' => array( 'search', '404' ) ) ), 'Search results match' );
expect( ! Visigate_Engine::check( array( 'type' => 'page_type', 'op' => 'is_none', 'value' => array( 'search' ) ) ), 'is none of excludes search' );
vg_page( array( 'archive', 'category' ) );
expect( Visigate_Engine::check( array( 'type' => 'page_type', 'op' => 'is_any', 'value' => array( 'archive' ) ) ), 'A category archive is also "any archive"' );
expect( ! Visigate_Engine::check( array( 'type' => 'page_type', 'op' => 'is_any', 'value' => array( 'front_page' ) ) ), 'Not the front page' );
$saved = Visigate_Conditions::sanitize( vg_rules( array( array( 'type' => 'page_type', 'op' => 'is_any', 'value' => array( 'search', 'bogus' ) ) ) ) );
expect( array( 'search' ) === $saved['groups'][0]['conditions'][0]['value'], 'Unknown page types are dropped on save' );

/* ---------------- URL path ---------------- */
$GLOBALS['vg_home'] = 'https://example.com/';
$path = function ( $uri, $op, $value ) {
	$_SERVER['REQUEST_URI'] = $uri;
	return Visigate_Engine::check( array( 'type' => 'url_path', 'op' => $op, 'value' => $value ) );
};
expect( $path( '/Shop/Shoes/?color=red', 'is', '/shop/shoes' ), 'Exact path ignores case, trailing slash and query string' );
expect( $path( '/shop/shoes/', 'is', 'shop/shoes/' ), 'Leading slash optional' );
expect( $path( '/shop/shoes/', 'is', 'https://example.com/shop/shoes/' ), 'Full URLs are accepted' );
expect( ! $path( '/shop/shoes/red', 'is', '/shop/shoes' ), 'Exact does not match children' );
expect( $path( '/shop/shoes/red', 'is', '/shop/*' ), 'Wildcard' );
expect( ! $path( '/shop', 'is', '/shop/*' ), 'Wildcard needs something after the slash' );
expect( $path( '/blog', 'under', '/blog' ) && $path( '/blog/hello/', 'under', '/blog' ), '"is or is inside" matches the path and its children' );
expect( ! $path( '/blogroll', 'under', '/blog' ), '"is or is inside" respects segment boundaries' );
expect( $path( '/', 'is', '/' ) && $path( '', 'is', '/' ), 'Home page path' );
expect( $path( '/landing/spring-sale', 'contains', 'SPRING' ), 'Contains is case-insensitive' );
expect( $path( '/about', 'not_is', '/shop, /cart' ) && ! $path( '/cart/', 'not_is', '/shop, /cart' ), 'Lists match any entry; negatives match none' );
expect( $path( '/caf%C3%A9', 'is', '/café' ), 'Encoded paths are decoded' );
$GLOBALS['vg_home'] = 'https://example.com/sub/';
expect( $path( '/sub/shop/', 'is', '/shop' ), 'Subdirectory installs are relative to the home URL' );
expect( $path( '/sub/', 'is', '/' ), 'Subdirectory home is /' );
$GLOBALS['vg_home'] = 'https://example.com/';
$GLOBALS['vg_ajax'] = true;
$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
$_SERVER['HTTP_REFERER'] = 'https://example.com/shop/item/';
expect( Visigate_Engine::check( array( 'type' => 'url_path', 'op' => 'under', 'value' => '/shop' ) ), 'admin-ajax.php uses the page that made the request' );
$GLOBALS['vg_ajax'] = false;
$_SERVER['REQUEST_URI'] = '/x';
expect( ! Visigate_Engine::check( array( 'type' => 'url_path', 'op' => 'is', 'value' => '' ) ) && ! Visigate_Engine::check( array( 'type' => 'url_path', 'op' => 'is', 'value' => array( 'a' ) ) ), 'Empty or malformed values never match' );

/* ---------------- Subject (Query Loop item) ---------------- */
$GLOBALS['vg_posts'] = array( 10 => array( 'type' => 'page' ), 21 => array( 'type' => 'post' ), 22 => array( 'type' => 'product' ) );
vg_page( array( 'singular', 'page' ), 10 );
$specific = array( 'type' => 'specific_post', 'op' => 'is_any', 'value' => array( 21 ) );
expect( null === Visigate_Engine::subject() && 10 === Visigate_Engine::subject_id(), 'Page context by default' );
expect( ! Visigate_Engine::check( $specific ), 'Page 10 is not post 21' );
expect( Visigate_Engine::with_subject( 21, function () use ( $specific ) { return Visigate_Engine::check( $specific ); } ), 'Loop item 21 matches' );
expect( null === Visigate_Engine::subject(), 'Subject is restored after the callback' );
try {
	Visigate_Engine::with_subject( 21, function () { throw new LogicException( 'x' ); } );
} catch ( LogicException $e ) {
	expect( null === Visigate_Engine::subject(), 'Subject is restored after an exception' );
}
$type_rule = array( 'type' => 'post_type', 'op' => 'is_any', 'value' => array( 'product' ) );
expect( ! Visigate_Engine::check( $type_rule ) && Visigate_Engine::with_subject( 22, function () use ( $type_rule ) { return Visigate_Engine::check( $type_rule ); } ), 'Post type follows the loop item' );
vg_page( array( 'archive', 'category' ) );
expect( Visigate_Engine::with_subject( 22, function () use ( $type_rule ) { return Visigate_Engine::check( $type_rule ); } ), 'Loop items work on archive pages' );
expect( ! Visigate_Engine::with_subject( 0, function () use ( $specific ) { return Visigate_Engine::check( $specific ); } ), 'Subject 0 never matches a post' );
$item = vg_rules( array( $specific ), 'show', array( 'scope' => 'item' ) );
expect( 'item' === Visigate_Conditions::sanitize( $item )['scope'], 'Scope survives saving' );
expect( ! isset( Visigate_Conditions::sanitize( array( 'scope' => 'evil', 'groups' => array() ) )['scope'] ), 'Only known scopes are kept' );
vg_page( array( 'singular', 'page' ), 10 );
expect( ! Visigate_Engine::visible( $item ), 'Without an add-on, item scope falls back to the page' );
add_filter( 'visigate_rules_subject', function ( $s, $rules ) { return 'item' === ( $rules['scope'] ?? '' ) ? 21 : $s; }, 10, 2 );
expect( Visigate_Engine::visible( $item ), 'The subject filter switches the post being checked' );
expect( ! Visigate_Engine::visible( vg_rules( array( $specific ) ) ), 'Page-scoped rules are not affected (and memo keys differ)' );
array_pop( $GLOBALS['hooks']['visigate_rules_subject'] );

/* ---------------- Cache reasons ---------------- */
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; Mobile)';
Visigate_Engine::visible( vg_rules( array( array( 'type' => 'weekday', 'op' => 'is_any', 'value' => array( '1' ) ) ) ) );
$reasons = Visigate_Engine::dynamic_reasons();
expect( isset( $reasons['weekday'] ) && 'Day of week' === $reasons['weekday'], 'Visitor-specific rules are recorded with their label' );

/* ---------------- Server warnings ---------------- */
$ended = vg_rules( array( array( 'type' => 'date_range', 'op' => 'within', 'value' => array( 'from' => '2026-01-01', 'to' => '2026-11-11' ) ) ) );
expect( in_array( 'A date range has ended', Visigate_Warnings::server_flags( $ended ), true ), 'Ended date ranges are flagged' );
$ended['groups'][0]['conditions'][0]['value']['to'] = '2026-11-12';
expect( ! Visigate_Warnings::server_flags( $ended ), 'A range ending today (whole day) is not flagged' );
$ended['groups'][0]['conditions'][0]['value']['to'] = '2026-11-12T09:00';
expect( (bool) Visigate_Warnings::server_flags( $ended ), 'A range that ended earlier today is flagged' );
expect( 'pro' === $mode || (bool) Visigate_Warnings::server_flags( vg_rules( array( array( 'type' => 'country', 'op' => 'is_any', 'value' => array( 'PH' ) ) ) ) ), 'Inactive add-on conditions are flagged' );

/* ---------------- Inspector explanations ---------------- */
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; Mobile)';
$rules = array(
	'action' => 'show',
	'groups' => array(
		array( 'conditions' => array(
			array( 'type' => 'device', 'op' => 'is_any', 'value' => array( 'desktop' ) ),
			array( 'type' => 'url_param', 'op' => 'equals', 'value' => array( 'key' => 'ref', 'value' => '' ) ),
		) ),
		array( 'conditions' => array(
			array( 'type' => 'device', 'op' => 'is_any', 'value' => array( 'mobile', 'tablet' ) ),
			array( 'type' => 'specific_post', 'op' => 'is_none', 'value' => array( 21 ), 'labels' => array( 21 => 'Hello world (Post #21)' ) ),
		) ),
	),
);
$x = Visigate_Inspector::explain( $rules );
expect( 2 === count( $x['groups'] ), 'Every group is explained' );
expect( false === $x['groups'][0]['conditions'][0]['pass'], 'Failed condition is reported (no short-circuit)' );
expect( null === $x['groups'][0]['conditions'][1]['pass'], 'Incomplete condition is reported as ignored' );
expect( ! $x['groups'][0]['matched'] && $x['groups'][1]['matched'], 'Matched group is reported' );
expect( 'Device type is any of Mobile, Tablet' === $x['groups'][1]['conditions'][0]['text'], 'Token values use their labels: ' . $x['groups'][1]['conditions'][0]['text'] );
expect( false !== strpos( $x['groups'][1]['conditions'][1]['text'], 'Hello world (Post #21)' ), 'Async values use saved labels' );
expect( 'URL parameter “ref” equals “”' === $x['groups'][0]['conditions'][1]['text'], 'Key/value description: ' . $x['groups'][0]['conditions'][1]['text'] );
$x = Visigate_Inspector::explain( array( 'always_hide' => true, 'groups' => $rules['groups'] ) );
expect( $x['always_hide'] && ! $x['groups'] && '' !== $x['note'], 'Always hide is explained without evaluating conditions' );
expect( false !== strpos( Visigate_Inspector::describe( array( 'type' => 'nope', 'op' => 'x' ) ), 'Unavailable' ), 'Unknown condition types are described' );
expect( false !== strpos( Visigate_Inspector::describe( array( 'type' => 'date_range', 'op' => 'within', 'value' => array( 'from' => '2026-01-01T09:00', 'to' => '' ) ) ), '2026-01-01 09:00 – …' ), 'Open-ended date range' );

/* ---------------- Inspector recording (free mode loads the real front end) ---------------- */
if ( 'pro' !== $mode ) {
	require __DIR__ . '/../includes/class-frontend.php';
	$on = new ReflectionProperty( 'Visigate_Inspector', 'on' );
	$on->setAccessible( true );
	$on->setValue( null, true );
	$GLOBALS['vg_posts'][40] = array( 'type' => 'visigate_set', 'title' => 'Members' );
	$GLOBALS['vg_meta'][40]  = array( '_visigate_rules' => vg_rules( array( array( 'type' => 'device', 'op' => 'is_any', 'value' => array( 'desktop' ) ) ) ) );
	$GLOBALS['vg_posts'][41] = array( 'type' => 'visigate_set', 'title' => 'Old', 'status' => 'trash' );
	$v1 = Visigate_Frontend::evaluate( 'block', 'Paragraph: Hi', 'k1', 0, function () {
		return Visigate_Engine::set_visible( 40 ) && Visigate_Engine::visible( vg_rules( array( array( 'type' => 'logged_in', 'op' => 'is_not' ) ) ) );
	} );
	$v1 = Visigate_Frontend::evaluate( 'block', 'Paragraph: Hi', 'k1', 0, function () { return Visigate_Engine::set_visible( 40 ); } );
	$v2 = Visigate_Frontend::evaluate( 'menu_item', 'Menu item: Old', 'k2', 22, function () { return Visigate_Engine::set_visible( 41 ); } );
	Visigate_Inspector::quietly( function () { Visigate_Engine::set_visible( 40 ); } );
	$e = Visigate_Inspector::entries();
	expect( 2 === count( $e ), 'Repeat checks of the same content merge; quiet checks are not recorded' );
	expect( ! $v1 && ! $e[0]['visible'] && 'Paragraph: Hi' === $e[0]['label'], 'Final decision recorded' );
	$titles = array_column( array_values( $e[0]['parts'] ), 'title' );
	expect( in_array( 'Rule set “Members” (#40)', $titles, true ), 'Rule set part is named: ' . implode( ' | ', $titles ) );
	expect( 22 === $e[1]['loop_post'] && false !== strpos( array_values( $e[1]['parts'] )[0]['note'], 'in the Trash' ), 'Trashed rule set explained' );
	Visigate_Engine::visible( vg_rules( array( array( 'type' => 'logged_in', 'op' => 'is' ) ) ) );
	$e = Visigate_Inspector::entries();
	expect( 3 === count( $e ) && 'other' === $e[2]['kind'], 'Checks outside content (PHP templates) get their own entry' );
	expect( array() === Visigate_Frontend::current_source() && 0 === Visigate_Frontend::loop_post_id(), 'Source is cleared after evaluation' );
	$tip = Visigate_Inspector::tooltip( 'k1' );
	expect( false !== strpos( $tip, '#1 Hidden by Visigate (Paragraph: Hi)' ) && false !== strpos( $tip, 'Rule set “Members” (#40)' ) && false !== strpos( $tip, '✗ Device type is any of Desktop' ), 'Detailed tooltip explains rule set and failed conditions: ' . $tip );
	expect( '<!--visigate-entry:1-->' === Visigate_Inspector::anchor( 'k1' ) && '' === Visigate_Inspector::anchor( 'unknown' ), 'HTML comment anchor generated for recorded entries' );
	expect( false !== strpos( Visigate_Inspector::preview_attrs( 'k1' ), 'data-visigate-entry="1"' ), 'Preview attributes attach entry number and tooltip' );

	// Two dynamic/container blocks with identical attrs and empty innerHTML must not collide in the inspector.
	require __DIR__ . '/../includes/class-cache-check.php';
	$entries_prop = new ReflectionProperty( 'Visigate_Inspector', 'entries' );
	$entries_prop->setAccessible( true );
	$entries_prop->setValue( null, array() );
	$shared_attrs = array( 'visigateSet' => 40 );
	Visigate_Frontend::pre_render( null, array( 'blockName' => 'core/post-title', 'attrs' => $shared_attrs, 'innerHTML' => '', 'innerBlocks' => array() ) );
	Visigate_Frontend::pre_render( null, array( 'blockName' => 'core/post-excerpt', 'attrs' => $shared_attrs, 'innerHTML' => '', 'innerBlocks' => array() ) );
	expect( 2 === count( Visigate_Inspector::entries() ), 'Blocks with empty innerHTML but different block structure get distinct inspector entries' );
	$on->setValue( null, false );

	// Cache check helpers ignore incomplete conditions, always_hide and disabled atomic Elementor props.
	$dyn = Visigate_Cache_Check::dynamic_types();
	$tir = new ReflectionMethod( 'Visigate_Cache_Check', 'types_in_rules' );
	$tir->setAccessible( true );
	expect( array() === $tir->invoke( null, vg_rules( array( array( 'type' => 'device', 'op' => 'is_any', 'value' => array() ) ) ), $dyn ), 'Incomplete dynamic condition is not reported by Cache Check' );
	expect( array() === $tir->invoke( null, vg_rules( array( array( 'type' => 'device', 'op' => 'is_any', 'value' => array( 'mobile' ) ) ), 'show', array( 'always_hide' => true ) ), $dyn ), 'Always-hidden rules are not reported as dynamic by Cache Check' );
	expect( array( 'device' => 'Device type' ) === $tir->invoke( null, vg_rules( array( array( 'type' => 'device', 'op' => 'is_any', 'value' => array( 'mobile' ) ) ) ), $dyn ), 'Complete dynamic condition is reported by Cache Check' );
	$tie = new ReflectionMethod( 'Visigate_Cache_Check', 'types_in_elementor' );
	$tie->setAccessible( true );
	$el_types = array();
	$tie->invokeArgs( null, array( array( array( 'visigate_rules' => array( '$$type' => 'visigate-rules', 'disabled' => true, 'value' => wp_json_encode( vg_rules( array( array( 'type' => 'device', 'op' => 'is_any', 'value' => array( 'mobile' ) ) ) ) ) ) ) ), $dyn, &$el_types ) );
	expect( array() === $el_types, 'Disabled atomic Elementor rules are ignored by Cache Check' );
}

/* ---------------- Pro: rule set as condition, loop products ---------------- */
if ( 'pro' === $mode ) {
	$all = Visigate_Conditions::all();
	expect( isset( $all['rule_set'] ) && 'audience' === $all['rule_set']['group'] && $all['rule_set']['pro'] && ! $all['rule_set']['locked'], 'Rule set condition is a Pro (not Business) condition' );
	expect( isset( Visigate_Conditions::groups()['audience'] ), 'Audience group registered' );
	foreach ( array( 'woo_product_cat', 'woo_product_price', 'woo_featured', 'woo_product_type', 'woo_on_sale', 'post_meta', 'post_term' ) as $t ) {
		expect( ! empty( $all[ $t ]['item'] ), "$t can follow a loop item" );
	}
	expect( empty( $all['country']['item'] ) && empty( $all['woo_cart_total']['item'] ), 'Visitor conditions do not follow loop items' );

	// Rule sets: 31 = mobile only, 32 = uses 31, 33 <-> 34 loop, 35 draft.
	$mobile = vg_rules( array( array( 'type' => 'device', 'op' => 'is_any', 'value' => array( 'mobile' ) ) ) );
	$GLOBALS['vg_posts'] += array(
		31 => array( 'type' => 'visigate_set' ), 32 => array( 'type' => 'visigate_set' ),
		33 => array( 'type' => 'visigate_set' ), 34 => array( 'type' => 'visigate_set' ),
		35 => array( 'type' => 'visigate_set', 'status' => 'draft' ),
	);
	$uses = function ( $ids, $op = 'passes' ) { return vg_rules( array( array( 'type' => 'rule_set', 'op' => $op, 'value' => $ids ) ) ); };
	$GLOBALS['vg_meta'] = array(
		31 => array( '_visigate_rules' => $mobile ),
		32 => array( '_visigate_rules' => $uses( array( 31 ) ) ),
		33 => array( '_visigate_rules' => $uses( array( 34 ) ) ),
		34 => array( '_visigate_rules' => $uses( array( 33 ) ) ),
		35 => array( '_visigate_rules' => $mobile ),
	);
	expect( Visigate_Engine::visible( $uses( array( 31 ) ) ), 'Rule set allows on mobile' );
	expect( ! Visigate_Engine::visible( $uses( array( 31 ), 'fails' ) ), '"allows none of" is the inverse' );
	expect( Visigate_Engine::visible( $uses( array( 32 ) ) ), 'Nested rule sets work' );
	expect( ! Visigate_Engine::visible( $uses( array( 33 ) ) ), 'Loops between rule sets never allow (and do not recurse forever)' );
	expect( ! Visigate_Engine::visible( $uses( array( 35 ) ) ) && ! Visigate_Engine::visible( $uses( array( 999 ) ) ), 'Unpublished or deleted rule sets never allow' );
	expect( Visigate_Engine::visible( $uses( array( 999, 31 ) ) ), 'Any of several rule sets' );
	expect( array( 31 => 1 ) === Visigate_Pro_Conditions::rule_set_refs( wp_json_encode( $uses( array( 31 ) ) ) ), 'References are found in JSON (Elementor) rules' );
	$rie = new ReflectionMethod( 'Visigate_Pro_Conditions', 'refs_in_elementor' );
	$rie->setAccessible( true );
	$el_refs = array();
	$rie->invokeArgs( null, array( array( array( 'visigate_rules' => array( '$$type' => 'visigate-rules', 'disabled' => true, 'value' => wp_json_encode( $uses( array( 31 ) ) ) ) ) ), &$el_refs ) );
	expect( array() === $el_refs, 'Disabled atomic Elementor rules are ignored by Pro usage scan' );

	// Query Loop subject via the Pro filter.
	vg_page( array( 'singular', 'page' ), 10 );
	$scoped = vg_rules( array( $specific ), 'show', array( 'scope' => 'item' ) );
	Visigate_Frontend::$loop = 21;
	expect( Visigate_Engine::visible( $scoped ), 'Item scope checks the loop post' );
	Visigate_Frontend::$loop = 0;
	expect( ! Visigate_Engine::visible( $scoped ), 'Outside a loop, item scope uses the page' );
	Visigate_Frontend::$loop = 21;
	expect( ! Visigate_Engine::visible( vg_rules( array( $specific ) ) ), 'Page scope ignores the loop post' );
	Visigate_Frontend::$loop = 0;
}
echo "PASS: page type, URL path, loop-item subject, cache reasons, server warnings, inspector" . ( 'pro' === $mode ? ', rule set audiences and Pro loop scope' : '' ) . " ($mode).\n";
