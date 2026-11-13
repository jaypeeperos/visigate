<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Why is this hidden?" inspector.
 *
 * For Visigate managers only, on the front end: records every visibility check made while the page
 * renders (blocks, shortcodes, menu items, Elementor elements) and shows, in a panel at the bottom
 * of the page, which conditions passed or failed, the final decision, whether fallback content was
 * shown and whether the page was excluded from page caching.
 *
 * The panel appears whenever "View as" is active (collapsed), or open when the manager turns on
 * "Inspect rules on this page" from the admin bar. Inspect mode also marks each hidden item in
 * place with its number. Nothing is recorded or printed for anyone else.
 */
class Visigate_Inspector {

	const COOKIE = 'visigate_inspect';
	const PARAM  = 'visigate_inspect';
	const MAX    = 200;

	private static $on         = null;
	private static $entries    = array();
	private static $stack      = array();
	private static $sets       = array();
	private static $explaining = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_toggle' ), 21 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 92 );
		add_action( 'wp_footer', array( __CLASS__, 'render_panel' ), 998 );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ) );
	}

	/* ------------------------------- state ------------------------------- */

	private static function allowed() {
		if ( ! Visigate_View_As::enabled() || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( did_action( 'wp' ) && is_feed() ) ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		return did_action( 'init' ) && is_user_logged_in() && current_user_can( visigate_capability() );
	}

	/** Has the manager turned on inspect mode (markers in place, panel open)? */
	public static function inspecting() {
		return isset( $_COOKIE[ self::COOKIE ] ) && '1' === $_COOKIE[ self::COOKIE ] && self::allowed();
	}

	/**Are checks being recorded on this request? */
	public static function recording() {
		if ( null !== self::$on ) {
			return self::$on;
		}
		if ( ! did_action( 'init' ) ) {
			return false; // Do not lock in a decision before the user is known.
		}
		$on = self::allowed() && ( self::inspecting() || Visigate_View_As::is_active() || ( class_exists( 'Visigate_Frontend' ) && Visigate_Frontend::preview_enabled() ) );
		if ( did_action( 'wp' ) ) {
			self::$on = $on;
		}
		return $on;
	}

	/** Should the bottom inspector panel (with inline CSS/JS) be rendered? */
	public static function panel_active() {
		return self::allowed() && ( self::inspecting() || Visigate_View_As::is_active() );
	}

	/** Entry number (`n`) for a content key, or 0 when not recorded. */
	public static function number( $key ) {
		return isset( self::$entries[ $key ]['n'] ) ? (int) self::$entries[ $key ]['n'] : 0;
	}

	/** Detailed plain-text tooltip for an entry key, explaining why it was hidden or shown. */
	public static function tooltip( $key, $default = '' ) {
		if ( ! isset( self::$entries[ $key ] ) ) {
			return '' !== $default ? $default : __( 'Hidden from this visitor by Visigate', 'visigate' );
		}
		$e     = self::$entries[ $key ];
		$lines = array();
		if ( $e['visible'] ) {
			/* translators: 1: entry number, 2: content label. */
			$lines[] = sprintf( __( '#%1$d Shown by Visigate (%2$s)', 'visigate' ), (int) $e['n'], '' !== $e['label'] ? $e['label'] : __( 'Content', 'visigate' ) );
		} elseif ( $e['fallback'] ) {
			/* translators: 1: entry number, 2: content label. */
			$lines[] = sprintf( __( '#%1$d Hidden by Visigate · fallback shown (%2$s)', 'visigate' ), (int) $e['n'], '' !== $e['label'] ? $e['label'] : __( 'Content', 'visigate' ) );
		} else {
			/* translators: 1: entry number, 2: content label. */
			$lines[] = sprintf( __( '#%1$d Hidden by Visigate (%2$s)', 'visigate' ), (int) $e['n'], '' !== $e['label'] ? $e['label'] : __( 'Content', 'visigate' ) );
		}
		foreach ( $e['parts'] as $part ) {
			$verb = 'hide' === ( $part['action'] ?? 'show' ) ? __( 'Hide when', 'visigate' ) : __( 'Show only when', 'visigate' );
			$res  = ! empty( $part['result'] ) ? __( 'allows', 'visigate' ) : __( 'hides', 'visigate' );
			if ( ! empty( $part['groups'] ) && empty( $part['always_hide'] ) ) {
				$lines[] = sprintf( '%1$s — %2$s… → %3$s', $part['title'], $verb, $res );
			} else {
				$lines[] = sprintf( '%1$s → %2$s', $part['title'], $res );
			}
			if ( ! empty( $part['note'] ) ) {
				$lines[] = '  ' . $part['note'];
			}
			foreach ( (array) ( $part['groups'] ?? array() ) as $gi => $g ) {
				if ( $gi > 0 ) {
					$lines[] = '  ' . __( 'OR', 'visigate' );
				}
				foreach ( (array) ( $g['conditions'] ?? array() ) as $row ) {
					if ( null === $row['pass'] ) {
						$lines[] = '  – ' . $row['text'] . ' ' . __( '(incomplete, ignored)', 'visigate' );
					} else {
						$lines[] = '  ' . ( $row['pass'] ? '✓ ' : '✗ ' ) . $row['text'];
					}
				}
			}
		}
		return implode( "\n", $lines );
	}

	/** Attributes (`title` + `data-visigate-entry`) to attach to a preview wrapper or marker. */
	public static function preview_attrs( $key ) {
		$tip = self::tooltip( $key );
		$n   = self::number( $key );
		$out = 'title="' . esc_attr( $tip ) . '"';
		if ( $n > 0 ) {
			$out .= ' data-visigate-entry="' . $n . '"';
		}
		return $out;
	}

	/** Invisible HTML comment placed before shown content when recording so the inspector can locate it without altering layout. */
	public static function anchor( $key ) {
		if ( ! self::recording() ) {
			return '';
		}
		$n = self::number( $key );
		return $n > 0 ? '<!--visigate-entry:' . $n . '-->' : '';
	}

	public static function no_cache() {
		if ( self::inspecting() ) {
			nocache_headers();
		}
	}

	/* ------------------------------ recording ------------------------------ */

	/** @param array $source kind, label, key, loop_post (see Visigate_Frontend::evaluate()). */
	public static function begin( $source ) {
		if ( self::$explaining ) {
			self::$stack[] = null;
			return;
		}
		$key = '' !== (string) ( $source['key'] ?? '' ) ? (string) $source['key'] : md5( wp_json_encode( $source ) );
		if ( ! isset( self::$entries[ $key ] ) ) {
			if ( count( self::$entries ) >= self::MAX ) {
				self::$stack[] = null;
				return;
			}
			self::$entries[ $key ] = array(
				'n'         => count( self::$entries ) + 1,
				'kind'      => (string) ( $source['kind'] ?? 'other' ),
				'label'     => (string) ( $source['label'] ?? '' ),
				'loop_post' => (int) ( $source['loop_post'] ?? 0 ),
				'parts'     => array(),
				'visible'   => true,
				'fallback'  => null,
			);
		}
		self::$stack[] = $key;
	}

	/** @param bool $visible Final decision for the content. */
	public static function end( $visible ) {
		$key = array_pop( self::$stack );
		if ( null !== $key && isset( self::$entries[ $key ] ) ) {
			self::$entries[ $key ]['visible'] = (bool) $visible;
		}
	}

	/** @param WP_Post $post Rule set being evaluated. */
	public static function enter_set( $post ) {
		self::$sets[] = $post;
	}

	public static function leave_set() {
		array_pop( self::$sets );
	}

	/** Key of the entry checks belong to; checks made outside Visigate_Frontend::evaluate() get an "other" entry. */
	private static function current_key() {
		if ( self::$stack ) {
			return end( self::$stack );
		}
		if ( ! isset( self::$entries['other'] ) && count( self::$entries ) < self::MAX ) {
			self::$entries['other'] = array(
				'n'         => count( self::$entries ) + 1,
				'kind'      => 'other',
				'label'     => __( 'PHP template or add-on check, e.g. visigate_visible()', 'visigate' ),
				'loop_post' => 0,
				'parts'     => array(),
				'visible'   => true,
				'fallback'  => null,
			);
		}
		return isset( self::$entries['other'] ) ? 'other' : null;
	}

	private static function add_part( $part ) {
		if ( self::$explaining ) {
			return;
		}
		$key = self::current_key();
		if ( null === $key || ! isset( self::$entries[ $key ] ) ) {
			return;
		}
		$id = md5( wp_json_encode( array( $part['title'], $part['rules_hash'], $part['subject'] ) ) );
		self::$entries[ $key ]['parts'][ $id ] = $part;
		if ( 'other' === $key && ! $part['result'] ) {
			self::$entries[ $key ]['visible'] = false;
		}
	}

	/**
	 * @param array $rules  Rules that were evaluated.
	 * @param bool  $result Visibility decided by the engine.
	 */
	public static function record_rules( $rules, $result ) {
		if ( self::$explaining ) {
			return;
		}
		$set = self::$sets ? end( self::$sets ) : null;
		if ( $set ) {
			/* translators: 1: rule set title, 2: rule set ID. */
			$title = sprintf( __( 'Rule set “%1$s” (#%2$d)', 'visigate' ), $set->post_title ? $set->post_title : __( '(no title)', 'visigate' ), $set->ID );
		} else {
			$title = __( 'Custom rules', 'visigate' );
		}
		$part               = self::explain( $rules );
		$part['title']      = $title;
		$part['result']     = (bool) $result;
		$part['subject']    = Visigate_Engine::subject();
		$part['rules_hash'] = md5( wp_json_encode( $rules ) );
		self::add_part( $part );
	}

	/**
	 * @param int          $id     Rule set ID.
	 * @param WP_Post|null $post   The post, when it exists.
	 * @param bool         $result Visibility used for it.
	 */
	public static function record_missing_set( $id, $post, $result ) {
		if ( self::$explaining ) {
			return;
		}
		if ( $post && Visigate_Ruleset::CPT === $post->post_type ) {
			$note = sprintf(
				/* translators: 1: rule set title, 2: rule set ID, 3: "in the Trash" or "not published". */
				__( 'Rule set “%1$s” (#%2$d) is %3$s, so content that uses it is hidden.', 'visigate' ),
				$post->post_title,
				$id,
				'trash' === $post->post_status ? __( 'in the Trash', 'visigate' ) : __( 'not published', 'visigate' )
			);
		} else {
			/* translators: %d: rule set ID. */
			$note = sprintf( __( 'Rule set #%d no longer exists, so content that uses it is hidden.', 'visigate' ), $id );
		}
		self::add_part(
			array(
				/* translators: %d: rule set ID. */
				'title'      => sprintf( __( 'Rule set #%d', 'visigate' ), $id ),
				'action'     => 'show',
				'scope'      => '',
				'note'       => $note,
				'groups'     => array(),
				'result'     => (bool) $result,
				'subject'    => Visigate_Engine::subject(),
				'rules_hash' => 'missing:' . $id,
			)
		);
	}

	/**
	 * @param string $key      Entry key.
	 * @param bool   $fallback Was replacement content shown?
	 */
	public static function note_fallback( $key, $fallback ) {
		if ( isset( self::$entries[ $key ] ) ) {
			self::$entries[ $key ]['fallback'] = (bool) $fallback;
		}
	}

	/** In inspect mode, a small numbered badge printed where hidden content would have been. */
	public static function marker( $key ) {
		if ( ! self::inspecting() || ! isset( self::$entries[ $key ] ) ) {
			return '';
		}
		return sprintf(
			'<span class="visigate-inspect-marker" %1$s style="display:inline-block;margin:2px 0;padding:1px 8px;border:1px dashed #d63638;border-radius:10px;font:600 11px/1.6 system-ui,sans-serif;color:#d63638;background:#fff;cursor:pointer">%2$s</span>',
			self::preview_attrs( $key ),
			/* translators: %d: number of the item in the inspector panel. */
			esc_html( sprintf( __( 'Hidden by Visigate · #%d', 'visigate' ), self::$entries[ $key ]['n'] ) )
		);
	}

	/**
	 * Run checks that should not appear in the panel (e.g. an add-on deciding which fallback to show).
	 *
	 * @param callable $callback Work to run.
	 */
	public static function quietly( $callback ) {
		$was              = self::$explaining;
		self::$explaining = true;
		try {
			return $callback();
		} finally {
			self::$explaining = $was;
		}
	}

	/** Recorded checks (for tests and add-ons). */
	public static function entries() {
		return array_values( self::$entries );
	}

	/* ------------------------------ explaining ------------------------------ */

	/** Every condition of a rules array, evaluated without short-circuiting, with a readable description. */
	public static function explain( $rules ) {
		$out = array(
			'action'      => ( is_array( $rules ) && 'hide' === ( $rules['action'] ?? '' ) ) ? 'hide' : 'show',
			'always_hide' => is_array( $rules ) && ! empty( $rules['always_hide'] ),
			'scope'       => is_array( $rules ) && ! empty( $rules['scope'] ) ? (string) $rules['scope'] : '',
			'groups'      => array(),
			'note'        => '',
		);
		if ( $out['always_hide'] ) {
			$out['note'] = __( 'Always hide is on, so any conditions are not used.', 'visigate' );
			return $out;
		}
		if ( ! is_array( $rules ) || empty( $rules['groups'] ) ) {
			$out['note'] = __( 'No conditions, so the content is always visible.', 'visigate' );
			return $out;
		}
		$was              = self::$explaining;
		self::$explaining = true;
		try {
			foreach ( (array) $rules['groups'] as $g ) {
				$rows   = array();
				$any    = false;
				$passed = true;
				foreach ( (array) ( $g['conditions'] ?? array() ) as $c ) {
					if ( ! is_array( $c ) ) {
						continue;
					}
					$complete = Visigate_Conditions::is_complete( $c );
					$pass     = $complete ? (bool) Visigate_Engine::check( $c ) : null;
					if ( $complete ) {
						$any    = true;
						$passed = $passed && $pass;
					}
					$rows[] = array( 'text' => self::describe( $c ), 'pass' => $pass );
				}
				$out['groups'][] = array(
					'conditions' => $rows,
					'matched'    => $any && $passed,
					'skipped'    => ! $any,
				);
			}
		} finally {
			self::$explaining = $was;
		}
		return $out;
	}

	/** "Device type is any of Mobile, Tablet" */
	public static function describe( $c ) {
		$all  = Visigate_Conditions::all();
		$type = (string) ( $c['type'] ?? '' );
		if ( ! isset( $all[ $type ] ) ) {
			/* translators: %s: condition type. */
			return sprintf( __( 'Unavailable condition “%s” (its add-on is inactive, so it counts as not met)', 'visigate' ), $type );
		}
		$s    = $all[ $type ];
		$op   = (string) ( $c['op'] ?? $s['default_op'] );
		$text = $s['label'] . ' ' . ( $s['ops'][ $op ] ?? $op );
		$v    = $c['value'] ?? null;
		switch ( $s['input'] ) {
			case 'tokens':
				$opts  = Visigate_Conditions::options();
				$opts  = isset( $opts[ $s['options'] ] ) ? $opts[ $s['options'] ] : array();
				$names = array();
				foreach ( (array) $v as $x ) {
					$names[] = is_scalar( $x ) && isset( $opts[ $x ] ) ? $opts[ $x ] : ( is_scalar( $x ) ? (string) $x : '?' );
				}
				$text .= ' ' . implode( ', ', $names );
				break;
			case 'async':
				$labels = isset( $c['labels'] ) && is_array( $c['labels'] ) ? $c['labels'] : array();
				$names  = array();
				foreach ( (array) $v as $x ) {
					$names[] = is_scalar( $x ) && isset( $labels[ $x ] ) && is_scalar( $labels[ $x ] ) ? (string) $labels[ $x ] : '#' . (int) $x;
				}
				$text .= ' ' . implode( ', ', $names );
				break;
			case 'kv':
				$key   = is_array( $v ) && isset( $v['key'] ) && is_scalar( $v['key'] ) ? (string) $v['key'] : '';
				$val   = is_array( $v ) && isset( $v['value'] ) && is_scalar( $v['value'] ) ? (string) $v['value'] : '';
				$text  = $s['label'] . ' “' . $key . '” ' . ( $s['ops'][ $op ] ?? $op );
				$text .= in_array( $op, (array) $s['no_value'], true ) ? '' : ' “' . $val . '”';
				break;
			case 'time_range':
			case 'date_range':
				$from  = is_array( $v ) && ! empty( $v['from'] ) && is_scalar( $v['from'] ) ? str_replace( 'T', ' ', (string) $v['from'] ) : '…';
				$to    = is_array( $v ) && ! empty( $v['to'] ) && is_scalar( $v['to'] ) ? str_replace( 'T', ' ', (string) $v['to'] ) : '…';
				$text .= ' ' . $from . ' – ' . $to;
				break;
			case 'none':
				break;
			default:
				$text .= is_scalar( $v ) && '' !== (string) $v ? ' ' . $v : '';
		}
		if ( ! empty( $s['days'] ) && ! empty( $c['days'] ) ) {
			/* translators: %d: number of days. */
			$text .= ' ' . sprintf( __( '(last %d days)', 'visigate' ), (int) $c['days'] );
		}
		return trim( $text );
	}

	/* ------------------------------ admin bar ------------------------------ */

	private static function toggle_url( $on ) {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url  = $host ? set_url_scheme( 'http://' . $host . $uri ) : home_url( '/' );
		$url  = remove_query_arg( array( self::PARAM, '_vgnonce' ), $url );
		return add_query_arg(
			array(
				self::PARAM => $on ? '1' : '0',
				'_vgnonce'  => wp_create_nonce( Visigate_View_As::NONCE ),
			),
			$url
		);
	}

	public static function handle_toggle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::PARAM ], $_GET['_vgnonce'] ) || ! Visigate_View_As::enabled() ) {
			return;
		}
		$on    = '1' === sanitize_text_field( wp_unslash( (string) $_GET[ self::PARAM ] ) );
		$nonce = sanitize_text_field( wp_unslash( (string) $_GET['_vgnonce'] ) );
		// phpcs:enable
		if ( ! wp_verify_nonce( $nonce, Visigate_View_As::NONCE ) || ! current_user_can( visigate_capability() ) ) {
			return;
		}
		$path = COOKIEPATH ? COOKIEPATH : '/';
		if ( $on ) {
			setcookie( self::COOKIE, '1', 0, $path, COOKIE_DOMAIN, is_ssl(), true );
		} else {
			setcookie( self::COOKIE, '', time() - YEAR_IN_SECONDS, $path, COOKIE_DOMAIN, is_ssl(), true );
		}
		wp_safe_redirect( remove_query_arg( array( self::PARAM, '_vgnonce' ) ) );
		exit;
	}

	public static function admin_bar( $bar ) {
		if ( is_admin() || ! self::allowed() || ! $bar->get_node( 'visigate-view-as' ) ) {
			return;
		}
		$on    = self::inspecting();
		$title = $on ? '✓ ' . __( 'Inspect rules on this page', 'visigate' ) : __( 'Inspect rules on this page', 'visigate' );
		$bar->add_node(
			array(
				'parent' => 'visigate-view-as',
				'id'     => 'visigate-va-inspect',
				'title'  => esc_html( $title ),
				'href'   => self::toggle_url( ! $on ),
			)
		);
	}

	/* -------------------------------- panel -------------------------------- */

	public static function render_panel() {
		if ( ! self::panel_active() ) {
			return;
		}
		$entries = array_values( self::$entries );
		$hidden  = 0;
		foreach ( $entries as $e ) {
			$hidden += $e['visible'] ? 0 : 1;
		}
		$reasons = Visigate_Engine::dynamic_reasons();
		?>
		<style>
		#visigate-inspector{position:fixed;left:12px;bottom:12px;z-index:99999;max-width:min(560px,calc(100vw - 24px));font:13px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif;color:#1d2327;text-align:left}
		#visigate-inspector summary{cursor:pointer;list-style:none;background:#1d2327;color:#fff;padding:6px 12px;border-radius:16px;display:inline-block;box-shadow:0 2px 8px rgba(0,0,0,.25)}
		#visigate-inspector summary::-webkit-details-marker{display:none}
		#visigate-inspector .vgi-body{margin-top:6px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.2);max-height:60vh;overflow:auto;padding:10px 12px}
		#visigate-inspector .vgi-entry{border-top:1px solid #f0f0f1;padding:8px 6px;margin:0 -6px;border-radius:4px;cursor:pointer;transition:background-color .2s}
		#visigate-inspector .vgi-entry:hover{background:#f6f7f7}
		#visigate-inspector .vgi-entry.vgi-active{background:#fff8e5;outline:2px solid #dba617}
		#visigate-inspector .vgi-head{display:flex;gap:8px;align-items:baseline;justify-content:space-between}
		#visigate-inspector .vgi-badge{font-size:11px;font-weight:600;padding:1px 7px;border-radius:9px;white-space:nowrap}
		#visigate-inspector .vgi-shown{background:#edfaef;color:#00701a}
		#visigate-inspector .vgi-hidden{background:#fcf0f1;color:#b32d2e}
		#visigate-inspector .vgi-part{margin:6px 0 0 10px;padding-left:8px;border-left:3px solid #dcdcde}
		#visigate-inspector .vgi-group{margin:3px 0 0;padding:0;list-style:none}
		#visigate-inspector .vgi-group li{margin:1px 0}
		#visigate-inspector .vgi-pass{color:#00701a}
		#visigate-inspector .vgi-fail{color:#b32d2e}
		#visigate-inspector .vgi-skip{color:#787c82}
		#visigate-inspector .vgi-muted{color:#646970;font-size:12px}
		#visigate-inspector .vgi-or{font-size:11px;font-weight:600;color:#2271b1;margin-top:3px}
		#visigate-inspector a{color:#2271b1}
		#visigate-inspector .vgi-unlocatable{color:#b32d2e;font-size:11px;margin-top:4px}
		.visigate-locate-flash{outline:3px solid #2271b1!important;outline-offset:3px!important;box-shadow:0 0 0 6px rgba(34,113,177,.25)!important;transition:box-shadow .25s,outline-color .25s}
		#visigate-hover-card{position:fixed;z-index:100000;max-width:360px;background:#1d2327;color:#f0f0f1;padding:8px 10px;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.35);font:12px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif;pointer-events:none;white-space:pre-wrap;display:none}
		</style>
		<details id="visigate-inspector"<?php echo self::inspecting() ? ' open' : ''; ?>>
			<summary>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: plugin name, 2: number of checks, 3: number hidden. */
						__( '%1$s: %2$d checked · %3$d hidden', 'visigate' ),
						visigate_brand(),
						count( $entries ),
						$hidden
					)
				);
				?>
			</summary>
			<div class="vgi-body">
				<?php if ( Visigate_View_As::is_active() ) : ?>
					<p class="vgi-muted" style="margin:0 0 6px">
						<?php
						/* translators: %s: what is simulated, e.g. "Logged-out visitor · Mobile". */
						echo esc_html( sprintf( __( 'Viewing as: %s', 'visigate' ), implode( ' · ', Visigate_View_As::labels() ) ) );
						?>
					</p>
				<?php endif; ?>
				<p class="vgi-muted" style="margin:0 0 6px">
					<?php
					if ( $reasons ) {
						$msg = Visigate_Admin::opt( 'auto_nocache' )
							/* translators: %s: list of condition names. */
							? __( 'Page caching: this page is excluded because it uses visitor-specific conditions (%s).', 'visigate' )
							/* translators: %s: list of condition names. */
							: __( 'Page caching: this page uses visitor-specific conditions (%s), but automatic cache exclusion is off in Settings. Cached copies may show the wrong content.', 'visigate' );
						echo esc_html( sprintf( $msg, implode( ', ', $reasons ) ) );
					} else {
						esc_html_e( 'Page caching: no visitor-specific conditions ran, so this page can be cached safely.', 'visigate' );
					}
					?>
					<a href="<?php echo esc_url( Visigate_Cache_Check::page_url() ); ?>"><?php esc_html_e( 'Cache check', 'visigate' ); ?></a>
				</p>
				<?php if ( ! $entries ) : ?>
					<p style="margin:0"><?php esc_html_e( 'No visibility rules ran on this page.', 'visigate' ); ?></p>
				<?php endif; ?>
				<?php foreach ( $entries as $e ) : ?>
					<div class="vgi-entry" data-vgi-entry="<?php echo (int) $e['n']; ?>" title="<?php esc_attr_e( 'Click to locate on page', 'visigate' ); ?>">
						<div class="vgi-head">
							<strong>#<?php echo (int) $e['n']; ?> <?php echo esc_html( '' !== $e['label'] ? $e['label'] : __( 'Content', 'visigate' ) ); ?></strong>
							<span class="vgi-badge <?php echo $e['visible'] ? 'vgi-shown' : 'vgi-hidden'; ?>">
								<?php
								if ( $e['visible'] ) {
									esc_html_e( 'Shown', 'visigate' );
								} elseif ( $e['fallback'] ) {
									esc_html_e( 'Hidden · fallback shown', 'visigate' );
								} else {
									esc_html_e( 'Hidden', 'visigate' );
								}
								?>
							</span>
						</div>
						<?php if ( $e['loop_post'] ) : ?>
							<div class="vgi-muted">
								<?php
								/* translators: 1: post title, 2: post ID. */
								echo esc_html( sprintf( __( 'In loop item: %1$s (#%2$d)', 'visigate' ), get_the_title( $e['loop_post'] ), $e['loop_post'] ) );
								?>
							</div>
						<?php endif; ?>
						<?php
						foreach ( $e['parts'] as $part ) {
							self::render_part( $part );
						}
						?>
					</div>
				<?php endforeach; ?>
				<p class="vgi-muted" style="margin:8px 0 0">
					<?php if ( self::inspecting() ) : ?>
						<a href="<?php echo esc_url( self::toggle_url( false ) ); ?>"><?php esc_html_e( 'Stop inspecting', 'visigate' ); ?></a>
					<?php else : ?>
						<a href="<?php echo esc_url( self::toggle_url( true ) ); ?>"><?php esc_html_e( 'Turn on inspect mode', 'visigate' ); ?></a>
						<?php esc_html_e( 'to mark each hidden item on the page with its number.', 'visigate' ); ?>
					<?php endif; ?>
				</p>
			</div>
		</details>
		<div id="visigate-hover-card" role="tooltip" aria-hidden="true"></div>
		<script>
		(function(){
			var panel = document.getElementById('visigate-inspector');
			var card  = document.getElementById('visigate-hover-card');
			if (!panel) return;
			var anchors = {};
			try {
				var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_COMMENT, null);
				var node;
				while ((node = walker.nextNode())) {
					var m = /^\s*visigate-entry:(\d+)\s*$/.exec(node.nodeValue || '');
					if (m) {
						var el = node.nextElementSibling || node.parentElement;
						if (el && !anchors[m[1]]) anchors[m[1]] = el;
					}
				}
			} catch (e) {}

			function findTarget(n) {
				var direct = document.querySelector('[data-visigate-entry="' + n + '"]');
				if (direct) return direct;
				return anchors[n] || null;
			}

			function visibleTarget(el) {
				var cur = el;
				while (cur && cur !== document.body) {
					var r = cur.getBoundingClientRect();
					if (r.width > 0 && r.height > 0) return cur;
					cur = cur.parentElement;
				}
				return el;
			}

			var flashTimer = null;
			var lastFlashed = null;
			function flashElement(el) {
				if (lastFlashed) lastFlashed.classList.remove('visigate-locate-flash');
				if (flashTimer) clearTimeout(flashTimer);
				var v = visibleTarget(el);
				if (!v) return;
				v.scrollIntoView({ behavior: 'smooth', block: 'center' });
				v.classList.add('visigate-locate-flash');
				lastFlashed = v;
				flashTimer = setTimeout(function(){
					v.classList.remove('visigate-locate-flash');
				}, 2200);
			}

			var entryTimer = null;
			function selectEntry(n, scrollPanel) {
				var rows = panel.querySelectorAll('.vgi-entry');
				for (var i = 0; i < rows.length; i++) rows[i].classList.remove('vgi-active');
				var row = panel.querySelector('.vgi-entry[data-vgi-entry="' + n + '"]');
				if (!row) return null;
				row.classList.add('vgi-active');
				if (scrollPanel) {
					panel.open = true;
					row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				}
				if (entryTimer) clearTimeout(entryTimer);
				entryTimer = setTimeout(function(){ row.classList.remove('vgi-active'); }, 2500);
				return row;
			}

			panel.addEventListener('click', function(e){
				if (e.target.closest('a, summary')) return;
				var row = e.target.closest('.vgi-entry[data-vgi-entry]');
				if (!row) return;
				var n = row.getAttribute('data-vgi-entry');
				selectEntry(n, false);
				var t = findTarget(n);
				var prevNote = row.querySelector('.vgi-unlocatable');
				if (prevNote) prevNote.remove();
				if (t) {
					flashElement(t);
				} else {
					var note = document.createElement('div');
					note.className = 'vgi-unlocatable';
					note.textContent = <?php echo wp_json_encode( __( 'Not rendered on the page — turn on inspect mode below to mark hidden items in place.', 'visigate' ) ); ?>;
					row.appendChild(note);
				}
			});

			document.addEventListener('click', function(e){
				var marker = e.target.closest('.visigate-inspect-marker[data-visigate-entry], .visigate-preview-hidden[data-visigate-entry]');
				if (!marker || panel.contains(marker)) return;
				var n = marker.getAttribute('data-visigate-entry');
				if (!n) return;
				if (marker.classList.contains('visigate-inspect-marker')) {
					e.preventDefault();
				}
				selectEntry(n, true);
				flashElement(marker);
			});

			if (card) {
				var activeHover = null;
				document.addEventListener('mouseover', function(e){
					var t = e.target.closest('[data-visigate-entry]');
					if (!t || panel.contains(t)) return;
					var tip = t.getAttribute('data-visigate-tip') || t.getAttribute('title');
					if (!tip) return;
					if (t.hasAttribute('title')) {
						t.setAttribute('data-visigate-tip', tip);
						t.removeAttribute('title');
					}
					activeHover = t;
					card.textContent = tip;
					card.style.display = 'block';
				});
				document.addEventListener('mousemove', function(e){
					if (!activeHover || card.style.display !== 'block') return;
					var x = Math.min(e.clientX + 14, window.innerWidth - card.offsetWidth - 12);
					var y = Math.min(e.clientY + 14, window.innerHeight - card.offsetHeight - 12);
					card.style.left = Math.max(8, x) + 'px';
					card.style.top  = Math.max(8, y) + 'px';
				});
				document.addEventListener('mouseout', function(e){
					if (!activeHover) return;
					var rel = e.relatedTarget;
					if (rel && activeHover.contains(rel)) return;
					card.style.display = 'none';
					activeHover = null;
				});
			}
		})();
		</script>
		<?php
	}

	private static function render_part( $part ) {
		$verb = 'hide' === ( $part['action'] ?? 'show' ) ? __( 'Hide when', 'visigate' ) : __( 'Show only when', 'visigate' );
		?>
		<div class="vgi-part">
			<div>
				<strong><?php echo esc_html( $part['title'] ); ?></strong>
				<?php if ( ! empty( $part['groups'] ) && empty( $part['always_hide'] ) ) : ?>
					<span class="vgi-muted">— <?php echo esc_html( $verb ); ?>…</span>
				<?php endif; ?>
				<span class="<?php echo $part['result'] ? 'vgi-pass' : 'vgi-fail'; ?>">→ <?php echo esc_html( $part['result'] ? __( 'allows', 'visigate' ) : __( 'hides', 'visigate' ) ); ?></span>
			</div>
			<?php if ( 'item' === ( $part['scope'] ?? '' ) ) : ?>
				<div class="vgi-muted">
					<?php
					echo esc_html(
						null !== $part['subject']
							/* translators: %s: post title. */
							? sprintf( __( 'Page conditions checked against the loop item: %s', 'visigate' ), get_the_title( (int) $part['subject'] ) )
							: __( 'Set to check each loop item, but this content is not inside a loop, so the page being viewed was used.', 'visigate' )
					);
					?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $part['note'] ) ) : ?>
				<div class="vgi-muted"><?php echo esc_html( $part['note'] ); ?></div>
			<?php endif; ?>
			<?php foreach ( (array) ( $part['groups'] ?? array() ) as $gi => $g ) : ?>
				<?php if ( $gi > 0 ) : ?>
					<div class="vgi-or"><?php esc_html_e( 'OR', 'visigate' ); ?></div>
				<?php endif; ?>
				<ul class="vgi-group">
					<?php
					foreach ( $g['conditions'] as $row ) {
						if ( null === $row['pass'] ) {
							$class = 'vgi-skip';
							$text  = '– ' . $row['text'] . ' ' . __( '(incomplete, ignored)', 'visigate' );
						} else {
							$class = $row['pass'] ? 'vgi-pass' : 'vgi-fail';
							$text  = ( $row['pass'] ? '✓ ' : '✗ ' ) . $row['text'];
						}
						echo '<li class="' . esc_attr( $class ) . '">' . esc_html( $text ) . '</li>';
					}
					?>
				</ul>
				<?php if ( $g['skipped'] ) : ?>
					<div class="vgi-muted"><?php esc_html_e( 'No complete conditions in this group, so it is skipped.', 'visigate' ); ?></div>
				<?php elseif ( $g['matched'] ) : ?>
					<div class="vgi-muted"><?php esc_html_e( 'Every condition in this group is met.', 'visigate' ); ?></div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
