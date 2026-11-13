<?php
defined( 'ABSPATH' ) || exit;

/**
 * Evaluates rules (free conditions). Everything is server-side and memoised per request.
 * Add-ons (e.g. Visigate Pro) register conditions with `visigate_conditions` and evaluate
 * them with the `visigate_check_condition` filter.
 */
class Visigate_Engine {

	private static $memo = array();

	/** Stack of post IDs that location conditions check instead of the queried page (see with_subject()). */
	private static $subject = array();

	/** Why this response was excluded from page caching: condition type => label. */
	private static $dynamic_reasons = array();

	public static function init() {
		// Reserved for future core hooks.
	}

	/* ------------------------------ subject ------------------------------ */

	/**
	 * Post the page-location conditions are checked against while $callback runs, e.g. one item of a
	 * Query Loop. Add-ons use it through the `visigate_rules_subject` filter.
	 *
	 * @param int      $post_id  Post to check against (0 = no post).
	 * @param callable $callback Work to run.
	 */
	public static function with_subject( $post_id, $callback ) {
		self::$subject[] = (int) $post_id;
		try {
			return $callback();
		} finally {
			array_pop( self::$subject );
		}
	}

	/** Post ID set with with_subject(), or null when conditions check the page being viewed. */
	public static function subject() {
		return self::$subject ? (int) end( self::$subject ) : null;
	}

	/** The post that location conditions check: the current loop item, else the queried singular post, else 0. */
	public static function subject_id() {
		$subject = self::subject();
		if ( null !== $subject ) {
			return $subject;
		}
		return is_singular() ? (int) get_queried_object_id() : 0;
	}

	/** Visibility of a saved rule set. Missing/unpublished sets fail closed (hidden). */
	public static function set_visible( $id ) {
		$id      = (int) $id;
		$post    = $id ? get_post( $id ) : null;
		$inspect = class_exists( 'Visigate_Inspector' ) && Visigate_Inspector::recording();
		if ( ! $post || Visigate_Ruleset::CPT !== $post->post_type || 'publish' !== $post->post_status ) {
			$result = (bool) apply_filters( 'visigate_missing_set_visible', false, $id );
			if ( $inspect ) {
				Visigate_Inspector::record_missing_set( $id, $post, $result );
			}
			return $result;
		}
		if ( $inspect ) {
			Visigate_Inspector::enter_set( $post );
		}
		try {
			return self::visible( get_post_meta( $id, Visigate_Ruleset::META, true ) );
		} finally {
			if ( $inspect ) {
				Visigate_Inspector::leave_set();
			}
		}
	}

	/** Visibility for a rules array. Empty rules = always visible. */
	public static function visible( $rules ) {
		// Rules can ask to be checked against something other than the queried page (e.g. each
		// Query Loop item). Core never sets a subject itself; add-ons decide with this filter.
		if ( is_array( $rules ) && ! empty( $rules['scope'] ) && null === self::subject() ) {
			$subject = apply_filters( 'visigate_rules_subject', null, $rules );
			if ( null !== $subject && false !== $subject ) {
				return self::with_subject(
					(int) $subject,
					function () use ( $rules ) {
						return self::visible( $rules );
					}
				);
			}
		}
		$result = self::decide( $rules );
		if ( class_exists( 'Visigate_Inspector' ) && Visigate_Inspector::recording() ) {
			Visigate_Inspector::record_rules( $rules, $result );
		}
		return $result;
	}

	private static function decide( $rules ) {
		if ( is_array( $rules ) && isset( $rules['always_hide'] ) && in_array( $rules['always_hide'], array( true, 1, '1' ), true ) ) {
			return false;
		}
		if ( ! is_array( $rules ) || empty( $rules['groups'] ) ) {
			return true;
		}
		$key = md5( wp_json_encode( $rules ) . '|' . self::subject() );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		$all     = Visigate_Conditions::all();
		$action  = ( ( $rules['action'] ?? 'show' ) === 'hide' ) ? 'hide' : 'show';
		$matched = false;
		$dynamic = array();
		$groups  = array();

		foreach ( (array) $rules['groups'] as $g ) {
			$conds = array_values( array_filter( (array) ( $g['conditions'] ?? array() ), array( 'Visigate_Conditions', 'is_complete' ) ) );
			if ( ! $conds ) {
				continue;
			}
			foreach ( $conds as $c ) {
				if ( ! empty( $all[ $c['type'] ]['dynamic'] ) ) {
					$dynamic[ $c['type'] ] = $all[ $c['type'] ]['label'];
				}
			}
			$groups[] = $conds;
		}
		if ( $dynamic ) {
			self::mark_dynamic( $dynamic );
		}
		if ( ! $groups ) {
			return self::$memo[ $key ] = true;
		}

		// OR of ANDs, short-circuiting.
		foreach ( $groups as $conds ) {
			$ok = true;
			foreach ( $conds as $c ) {
				if ( ! self::check( $c ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$matched = true;
				break;
			}
		}

		return self::$memo[ $key ] = ( 'show' === $action ) ? $matched : ! $matched;
	}

	/**
	 * Tell page caches not to store this response (visitor-specific rules in play).
	 *
	 * @param array $reasons Condition type => label of the visitor-specific conditions that ran.
	 */
	public static function mark_dynamic( $reasons = array() ) {
		$first = ! self::$dynamic_reasons;
		foreach ( (array) $reasons as $type => $label ) {
			self::$dynamic_reasons[ (string) $type ] = (string) $label;
		}
		if ( ! $reasons && $first ) {
			self::$dynamic_reasons['other'] = __( 'Visitor-specific rule', 'visigate' );
		}
		if ( ! Visigate_Admin::opt( 'auto_nocache' ) || is_admin() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard constant read by page-cache plugins.
		}
		if ( ! $first ) {
			return;
		}
		// Server and CDN caches (Varnish, Nginx, Cloudflare) ignore DONOTCACHEPAGE but read headers.
		// Block themes evaluate rules before any output, so the header can still be sent there.
		if ( ! headers_sent() && function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		// LiteSpeed Cache's own API, in case its DONOTCACHEPAGE check already ran.
		do_action( 'litespeed_control_set_nocache', 'Visigate: visitor-specific visibility rules' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.
		do_action( 'visigate_dynamic_page' );
	}

	/** Visitor-specific conditions that ran on this request (type => label); empty when the page is cache-safe. */
	public static function dynamic_reasons() {
		return self::$dynamic_reasons;
	}

	public static function check( $c ) {
		$type = $c['type'];
		$op   = $c['op'] ?? '';
		$v    = $c['value'] ?? null;

		switch ( $type ) {
			case 'logged_in':
				$in = Visigate_View_As::logged_in();
				return ( 'is' === $op ) ? $in : ! $in;

			case 'user_role':
				$hit = (bool) array_intersect( Visigate_View_As::roles(), array_map( 'strval', (array) $v ) );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'specific_user':
				$user_id = Visigate_View_As::user_id();
				$hit     = $user_id > 0 && in_array( (string) $user_id, array_map( 'strval', (array) $v ), true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'capability':
				$has = Visigate_View_As::logged_in() && Visigate_View_As::can( sanitize_key( (string) $v ) );
				return ( 'has' === $op ) ? $has : ! $has;

			case 'user_registered':
				if ( ! Visigate_View_As::logged_in() || ! is_user_logged_in() ) {
					return false;
				}
				$age = ( self::now()->getTimestamp() - strtotime( wp_get_current_user()->user_registered . ' UTC' ) ) / DAY_IN_SECONDS;
				return ( 'older' === $op ) ? $age > (float) $v : $age < (float) $v;

			case 'url_param':
				// Block attributes are not sanitized before rendering, so never trust the key's type.
				$key = self::kv_part( $v, 'key' );
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$raw = '' !== $key && isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : null;
				return self::string_check( $raw, $op, self::kv_part( $v, 'value' ) );

			case 'cookie':
				$key = self::kv_part( $v, 'key' );
				$raw = '' !== $key && class_exists( 'Visigate_Pro_View_As' ) ? Visigate_Pro_View_As::cookie( $key ) : null;
				if ( null === $raw ) {
					$raw = '' !== $key && isset( $_COOKIE[ $key ] ) && is_scalar( $_COOKIE[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ $key ] ) ) : null;
				}
				return self::string_check( $raw, $op, self::kv_part( $v, 'value' ) );

			case 'referrer':
				if ( class_exists( 'Visigate_Pro_View_As' ) ) {
					$sim = Visigate_Pro_View_As::check( $c );
					if ( null !== $sim ) {
						return $sim;
					}
				}
				$ref = isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
				$needle = is_scalar( $v ) ? (string) $v : '';
				$hit    = '' !== $ref && '' !== $needle && false !== stripos( $ref, $needle );
				return ( 'contains' === $op ) ? $hit : ! $hit;

			case 'device':
				$hit = in_array( self::device(), (array) $v, true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			// Off singular pages (home, archives, search) no listed post is being viewed, so
			// "is any of" is false and "is none of" is true.
			case 'specific_post':
				$post_id = self::subject_id();
				$hit     = $post_id > 0 && in_array( (string) $post_id, array_map( 'strval', (array) $v ), true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'post_type':
				$post_types = array_map( 'strval', (array) $v );
				if ( null !== self::subject() ) {
					$hit = $post_types && self::subject() && in_array( (string) get_post_type( self::subject() ), $post_types, true );
				} else {
					$hit = $post_types && ( is_singular( $post_types ) || is_post_type_archive( $post_types ) );
				}
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'page_view':
				return ( 'singular' === $op && is_singular() ) || ( 'archive' === $op && is_archive() );

			case 'page_type':
				$hit = (bool) array_intersect( self::page_types(), array_map( 'strval', (array) $v ) );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'url_path':
				return self::url_path_check( $op, $v );

			case 'post_category_tag':
				return self::post_terms( $op, (array) $v, array( 'category', 'post_tag' ) );

			case 'weekday':
				$hit = in_array( self::now()->format( 'N' ), array_map( 'strval', (array) $v ), true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'time_of_day':
				$now = self::now();
				$cur = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
				$f   = self::minutes( $v['from'] );
				$t   = self::minutes( $v['to'] );
				$in  = ( $f <= $t ) ? ( $cur >= $f && $cur < $t ) : ( $cur >= $f || $cur < $t ); // overnight ranges
				return ( 'between' === $op ) ? $in : ! $in;

			case 'date_range':
				$now = self::now()->getTimestamp();
				$f   = ! empty( $v['from'] ) ? date_create_immutable( $v['from'], wp_timezone() ) : null;
				$t   = ! empty( $v['to'] ) ? date_create_immutable( $v['to'], wp_timezone() ) : null;
				if ( $t && ! empty( $v['to'] ) && 10 === strlen( (string) $v['to'] ) ) {
					$t = $t->setTime( 23, 59, 59 ); // A date without a time includes that whole day.
				}
				$in  = ( ! $f || $now >= $f->getTimestamp() ) && ( ! $t || $now <= $t->getTimestamp() );
				return ( 'within' === $op ) ? $in : ! $in;
		}

		// Add-on conditions. Unknown / inactive conditions evaluate to false (fail closed for "show" rules).
		return (bool) apply_filters( 'visigate_check_condition', false, $c );
	}

	/** Match the queried page or taxonomy archive; never use the global loop post. */
	public static function post_terms( $op, $ids, $taxonomies ) {
		// A site may unregister post_tag (or category); skip it rather than erroring the whole rule.
		$taxonomies = array_values( array_filter( (array) $taxonomies, 'taxonomy_exists' ) );
		if ( ! $taxonomies || ! in_array( $op, array( 'is_any', 'is_none' ), true ) ) {
			return false;
		}
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		if ( ! $ids || in_array( 0, $ids, true ) ) {
			return false;
		}
		// Do not let a custom/deleted term turn a negative Free condition into a match.
		$valid = get_terms( array( 'taxonomy' => $taxonomies, 'include' => $ids, 'hide_empty' => false, 'fields' => 'ids' ) );
		if ( is_wp_error( $valid ) || array_diff( $ids, array_map( 'intval', $valid ) ) ) {
			return false;
		}
		if ( null !== self::subject() ) {
			// A loop item: its own terms, never the page around it.
			if ( ! self::subject() ) {
				return false;
			}
			$terms = wp_get_object_terms( self::subject(), $taxonomies );
		} elseif ( is_singular() && get_queried_object_id() ) {
			$terms = wp_get_object_terms( get_queried_object_id(), $taxonomies );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, $taxonomies, true ) ) {
				return false;
			}
			$terms = array( $term );
		} else {
			return false;
		}
		if ( is_wp_error( $terms ) ) {
			return false;
		}
		$found = array();
		foreach ( $terms as $term ) {
			$found[] = (int) $term->term_id;
			$found = array_merge( $found, get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
		}
		$hit = (bool) array_intersect( $found, $ids );
		return 'is_any' === $op ? $hit : ! $hit;
	}

	/**
	 * Every page type that describes the current request, e.g. array( 'archive', 'category' ).
	 * Keys match the `page_types` option list.
	 */
	public static function page_types() {
		$types = array();
		$map   = array(
			'front_page'        => 'is_front_page',
			'posts_page'        => 'is_home',
			'singular'          => 'is_singular',
			'archive'           => 'is_archive',
			'category'          => 'is_category',
			'tag'               => 'is_tag',
			'taxonomy'          => 'is_tax',
			'post_type_archive' => 'is_post_type_archive',
			'author'            => 'is_author',
			'date'              => 'is_date',
			'search'            => 'is_search',
			'404'               => 'is_404',
			'attachment'        => 'is_attachment',
		);
		foreach ( $map as $key => $fn ) {
			if ( $fn() ) {
				$types[] = $key;
			}
		}
		if ( is_page() && (int) get_option( 'wp_page_for_privacy_policy' ) === (int) get_queried_object_id() ) {
			$types[] = 'privacy_policy';
		}
		return apply_filters( 'visigate_page_types', $types );
	}

	/**
	 * Path of the page being viewed, relative to the site's home URL: lowercase, decoded, with a leading
	 * slash and without a trailing one ("/" for the home page). Content loaded with admin-ajax.php
	 * (load more, quick view) uses the page that made the request.
	 */
	public static function request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the path is used, normalized below.
		if ( wp_doing_ajax() && isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ) {
			$uri = wp_unslash( $_SERVER['HTTP_REFERER'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the path is used, normalized below.
		}
		$path = self::normalize_path( $uri );
		$home = self::normalize_path( (string) home_url( '/' ) );
		if ( '/' !== $home && ( $path === $home || 0 === strpos( $path, $home . '/' ) ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return '' === $path ? '/' : $path;
	}

	/** "https://x.com/Shop/Item/?a=1" or "shop/item/" => "/shop/item". */
	public static function normalize_path( $url ) {
		$url  = trim( (string) $url );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			$path = '';
		}
		$path = strtolower( rawurldecode( $path ) );
		$path = '/' . trim( preg_replace( '#/+#', '/', $path ), '/' );
		return $path;
	}

	/**
	 * URL path condition. Values are a comma-separated list; the condition matches when any entry does.
	 *   is        exact path, `*` matches anything ("/shop/*")
	 *   under     the path or anything inside it ("/blog" matches "/blog" and "/blog/hello")
	 *   contains  text anywhere in the path
	 * The `not_*` operators match when no entry does.
	 */
	public static function url_path_check( $op, $v ) {
		$entries = array_filter( array_map( 'trim', explode( ',', is_scalar( $v ) ? (string) $v : '' ) ), 'strlen' );
		if ( ! $entries ) {
			return false;
		}
		$path = self::request_path();
		$mode = 0 === strpos( (string) $op, 'not_' ) ? substr( (string) $op, 4 ) : (string) $op;
		$hit  = false;
		foreach ( $entries as $entry ) {
			if ( 'contains' === $mode ) {
				$needle = strtolower( $entry );
				$hit    = '' !== $needle && false !== strpos( $path, $needle );
			} else {
				$needle = self::normalize_path( false !== strpos( $entry, '://' ) || 0 === strpos( $entry, '/' ) ? $entry : '/' . $entry );
				if ( 'under' === $mode ) {
					$hit = '/' === $needle || $path === $needle || 0 === strpos( $path, $needle . '/' );
				} elseif ( false !== strpos( $needle, '*' ) ) {
					$re  = '#^' . str_replace( '\\*', '.*', preg_quote( $needle, '#' ) ) . '$#';
					$hit = (bool) preg_match( $re, $path );
				} else {
					$hit = $path === $needle;
				}
			}
			if ( $hit ) {
				break;
			}
		}
		return 0 === strpos( (string) $op, 'not_' ) ? ! $hit : $hit;
	}

	/** One part ("key" or "value") of a key/value condition as a string; '' when missing or malformed. */
	private static function kv_part( $v, $part ) {
		return is_array( $v ) && isset( $v[ $part ] ) && is_scalar( $v[ $part ] ) ? (string) $v[ $part ] : '';
	}

	public static function string_check( $actual, $op, $expected ) {
		switch ( $op ) {
			case 'exists':
				return null !== $actual && '' !== $actual;
			case 'not_exists':
				return null === $actual || '' === $actual;
			case 'equals':
				return null !== $actual && $actual === $expected;
			case 'not_equals':
				return $actual !== $expected;
			case 'contains':
				return null !== $actual && false !== stripos( $actual, $expected );
		}
		return false;
	}

	/** Current site time, or the time being previewed with View as. */
	public static function now() {
		$sim = Visigate_View_As::now();
		return $sim ? $sim : current_datetime();
	}

	private static function minutes( $hhmm ) {
		$p = explode( ':', (string) $hhmm );
		return ( (int) $p[0] ) * 60 + (int) ( $p[1] ?? 0 );
	}

	public static function device() {
		$sim = Visigate_View_As::device();
		if ( null !== $sim ) {
			return $sim;
		}
		static $d = null;
		if ( null !== $d ) {
			return $d;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( preg_match( '/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $ua ) ) {
			$d = 'tablet';
		} elseif ( wp_is_mobile() ) {
			$d = 'mobile';
		} else {
			$d = 'desktop';
		}
		return $d = apply_filters( 'visigate_device', $d );
	}
}
