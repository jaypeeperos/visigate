<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin look shared by every Visigate screen: the layered header with pill tabs, the admin
 * stylesheet, the Overview page, and small helpers (rule summaries, status badges) used by the
 * Rule Sets list, Where used, Cache check, View as and Settings screens.
 *
 * The header is printed on every screen under the Visigate menu (including screens add-ons such as
 * Visigate Pro register there), so add-on pages get the same navigation without extra code.
 * Add-ons can change the tabs with the `visigate_admin_tabs` filter.
 */
class Visigate_Admin_UI {

	const OVERVIEW = 'visigate-overview';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'order_menu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'print_header' ) );
		add_filter( 'post_class', array( __CLASS__, 'row_class' ), 10, 3 );
	}

	public static function parent_slug() {
		return 'edit.php?post_type=' . Visigate_Ruleset::CPT;
	}

	public static function overview_url() {
		return add_query_arg( array( 'post_type' => Visigate_Ruleset::CPT, 'page' => self::OVERVIEW ), admin_url( 'edit.php' ) );
	}

	public static function page_url( $page ) {
		return add_query_arg( array( 'post_type' => Visigate_Ruleset::CPT, 'page' => $page ), admin_url( 'edit.php' ) );
	}

	/* ------------------------------- menu ------------------------------- */

	public static function menu() {
		add_submenu_page(
			self::parent_slug(),
			/* translators: %s: plugin name */
			sprintf( __( '%s Overview', 'visigate' ), visigate_brand() ),
			__( 'Overview', 'visigate' ),
			visigate_capability(),
			self::OVERVIEW,
			array( __CLASS__, 'render_overview' ),
			0
		);
	}

	/** Overview first in the Visigate menu, Settings after the core screens. */
	public static function order_menu() {
		global $submenu;
		$parent = self::parent_slug();
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}
		$first = array();
		$rest  = array();
		foreach ( $submenu[ $parent ] as $item ) {
			if ( isset( $item[2] ) && self::OVERVIEW === $item[2] ) {
				$first[] = $item;
			} else {
				$rest[] = $item;
			}
		}
		$submenu[ $parent ] = array_merge( $first, $rest ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reordering our own submenu.
	}

	/* ------------------------------ screens ------------------------------ */

	/** Is this request one of Visigate's admin screens? */
	public static function is_screen() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		if ( Visigate_Ruleset::CPT === $screen->post_type && in_array( $screen->base, array( 'edit', 'post' ), true ) ) {
			return true;
		}
		$page = self::current_page();
		return '' !== $page && isset( self::page_slugs()[ $page ] );
	}

	private static function current_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	/** Slugs of every page registered under the Visigate menu. */
	private static function page_slugs() {
		global $submenu;
		$out = array();
		foreach ( (array) ( $submenu[ self::parent_slug() ] ?? array() ) as $item ) {
			if ( isset( $item[2] ) && false === strpos( (string) $item[2], '.php' ) ) {
				$out[ sanitize_key( $item[2] ) ] = $item;
			}
		}
		return $out;
	}

	public static function body_class( $classes ) {
		if ( self::is_screen() ) {
			$classes .= ' visigate-screen';
		}
		return $classes;
	}

	public static function enqueue() {
		if ( ! self::is_screen() ) {
			return;
		}
		wp_enqueue_style( 'visigate-admin', VISIGATE_URL . 'assets/css/admin.css', array(), VISIGATE_VERSION . '.' . filemtime( VISIGATE_DIR . 'assets/css/admin.css' ) );
		// Labels the editor's CSS draws above each condition group ("GROUP 1 · ALL MUST MATCH").
		$enc = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
		wp_add_inline_style( 'visigate-admin', '#visigate-rules{--vg-group-label:' . wp_json_encode( __( 'Group', 'visigate' ), $enc ) . ';--vg-group-all:' . wp_json_encode( __( 'all must match', 'visigate' ), $enc ) . '}' );
		// Admin notices and Screen Options above the header, select-all, confirmations and the View As inspector.
		wp_enqueue_script( 'visigate-admin-ui', VISIGATE_URL . 'assets/js/admin-ui.js', array( 'jquery', 'common' ), VISIGATE_VERSION . '.' . filemtime( VISIGATE_DIR . 'assets/js/admin-ui.js' ), true );
		wp_localize_script(
			'visigate-admin-ui',
			'VisigateAdminUI',
			array(
				'shown'   => __( 'Shown', 'visigate' ),
				'hidden'  => __( 'Hidden', 'visigate' ),
				/* translators: %d: number of items shown on the page */
				'nShown'  => __( '%d shown', 'visigate' ),
				/* translators: %d: number of items hidden on the page */
				'nHidden' => __( '%d hidden', 'visigate' ),
				'none'    => __( 'No content on this page uses a Visigate rule.', 'visigate' ),
				'fail'    => __( 'Could not read the rule checks for this page. Open the page to see the inspector there.', 'visigate' ),
				'always'  => __( 'Always hide is on', 'visigate' ),
				'or'      => __( 'or', 'visigate' ),
			)
		);
	}

	/** Rule set rows that are always hidden get a cream background in the list table. */
	public static function row_class( $classes, $class, $post_id ) {
		if ( is_admin() && Visigate_Ruleset::CPT === get_post_type( $post_id ) ) {
			$r = get_post_meta( $post_id, Visigate_Ruleset::META, true );
			if ( is_array( $r ) && ! empty( $r['always_hide'] ) ) {
				$classes[] = 'vg-is-hidden-row';
			}
		}
		return $classes;
	}

	/* ------------------------------- tabs ------------------------------- */

	private static function tabs() {
		$known = array(
			self::OVERVIEW      => array( 'label' => __( 'Overview', 'visigate' ), 'url' => self::overview_url() ),
			Visigate_Admin_Rules::SLUG => array( 'label' => __( 'Rule sets', 'visigate' ), 'url' => Visigate_Admin_Rules::url(), 'count' => self::set_count() ),
			'visigate-usage'    => array( 'label' => __( 'Where used', 'visigate' ), 'url' => Visigate_Usage::usage_url() ),
			Visigate_Cache_Check::SLUG => array( 'label' => __( 'Cache check', 'visigate' ), 'url' => Visigate_Cache_Check::page_url(), 'count' => Visigate_Cache_Check::issue_count(), 'warn' => true ),
		);
		if ( Visigate_View_As::enabled() ) {
			$known['visigate-view-as'] = array( 'label' => __( 'View as', 'visigate' ), 'url' => Visigate_View_As::page_url() );
		}
		// Pages that add-ons register under the Visigate menu (Visigate Pro: Templates, Import / Export, Pro Settings).
		$skip = array( 'account', 'contact', 'pricing', 'addons', 'support', 'affiliation', 'wp-support-forum' );
		foreach ( self::page_slugs() as $slug => $item ) {
			if ( isset( $known[ $slug ] ) || 'visigate-settings' === $slug ) {
				continue;
			}
			foreach ( $skip as $word ) {
				if ( false !== strpos( $slug, $word ) ) {
					continue 2;
				}
			}
			$known[ $slug ] = array( 'label' => wp_strip_all_tags( (string) $item[0] ), 'url' => self::page_url( $slug ) );
		}
		if ( current_user_can( 'manage_options' ) ) {
			$known['visigate-settings'] = array( 'label' => __( 'Settings', 'visigate' ), 'url' => self::page_url( 'visigate-settings' ) );
		}
		return apply_filters( 'visigate_admin_tabs', $known );
	}

	private static function active_tab() {
		$screen = get_current_screen();
		if ( $screen && Visigate_Ruleset::CPT === $screen->post_type && in_array( $screen->base, array( 'edit', 'post' ), true ) && '' === self::current_page() ) {
			return Visigate_Admin_Rules::SLUG;
		}
		return self::current_page();
	}

	private static function set_count() {
		$c = wp_count_posts( Visigate_Ruleset::CPT );
		$n = 0;
		foreach ( array( 'publish', 'draft', 'pending', 'private', 'future' ) as $s ) {
			$n += isset( $c->$s ) ? (int) $c->$s : 0;
		}
		return $n;
	}

	/* ------------------------------ header ------------------------------ */

	private static function leads() {
		return array(
			self::OVERVIEW                => __( 'Decide who sees which content. Rules run on the server, so hidden content never reaches the page source.', 'visigate' ),
			Visigate_Admin_Rules::SLUG    => __( 'Build a rule once and reuse it everywhere. Edit it here and every block, element and menu item using it updates.', 'visigate' ),
			'visigate-usage'              => __( 'Every block, Elementor element, menu item and shortcode that uses a rule set, and the rule behind it.', 'visigate' ),
			Visigate_Cache_Check::SLUG    => __( 'Checks whether a page cache could show one visitor the content meant for another.', 'visigate' ),
			'visigate-view-as'            => __( 'Preview the site as another visitor to check what they see. You stay logged in.', 'visigate' ),
			'visigate-settings'           => __( 'Site-wide options for previews, cache safety and developers.', 'visigate' ),
		);
	}

	public static function print_header() {
		if ( ! self::is_screen() || ! current_user_can( visigate_capability() ) ) {
			return;
		}
		$active = self::active_tab();
		$brand  = visigate_brand();
		$own    = 'Visigate' === $brand;
		$leads  = self::leads();
		$lead   = (string) apply_filters( 'visigate_admin_lead', $leads[ $active ] ?? '', $active );
		?>
		<div class="vg-wrap vg-head-wrap">
			<div class="vg-head">
				<div class="vg-head-ghost" aria-hidden="true"></div>
				<div class="vg-head-card">
					<div class="vg-head-row">
						<div class="vg-brand">
							<?php if ( $own ) : ?>
								<?php self::logo(); ?>
							<?php else : ?>
								<span class="vg-logo-generic" aria-hidden="true"><span class="dashicons dashicons-visibility"></span></span>
							<?php endif; ?>
							<span class="vg-name"><?php echo esc_html( $brand ); ?></span>
							<?php if ( visigate_is_pro() ) : ?>
								<span class="vg-pill"><?php esc_html_e( 'Pro', 'visigate' ); ?></span>
							<?php endif; ?>
							<?php if ( $own ) : ?>
								<span class="vg-by"><?php esc_html_e( 'by Persjay Apps', 'visigate' ); ?></span>
							<?php endif; ?>
						</div>
						<nav class="vg-tabs" aria-label="<?php /* translators: %s: plugin name */ echo esc_attr( sprintf( __( '%s sections', 'visigate' ), $brand ) ); ?>">
							<?php foreach ( self::tabs() as $key => $tab ) : ?>
								<?php $is = (string) $key === (string) $active; ?>
								<a class="vg-tab<?php echo $is ? ' is-active' : ''; ?>" href="<?php echo esc_url( $tab['url'] ); ?>"<?php echo $is ? ' aria-current="page"' : ''; ?>>
									<?php echo esc_html( $tab['label'] ); ?>
									<?php if ( ! empty( $tab['count'] ) ) : ?>
										<span class="vg-count<?php echo ! empty( $tab['warn'] ) ? ' is-warn' : ''; ?>"><?php echo (int) $tab['count']; ?></span>
									<?php endif; ?>
								</a>
							<?php endforeach; ?>
						</nav>
					</div>
					<?php if ( '' !== $lead ) : ?>
						<p class="vg-lead"><?php echo esc_html( $lead ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function logo( $size = 36 ) {
		?>
		<svg class="vg-logo" width="<?php echo (int) $size; ?>" height="<?php echo (int) $size; ?>" viewBox="0 0 34 34" aria-hidden="true" focusable="false"><rect x="10" y="4" width="20" height="20" rx="4" fill="none" stroke="#f59e0b" stroke-width="2" stroke-dasharray="3 3"/><rect x="4" y="10" width="20" height="20" rx="4" fill="#4f46e5"/><rect x="8" y="15" width="11" height="2.4" rx="1.2" fill="#fff"/><rect x="8" y="20.5" width="7" height="2.4" rx="1.2" fill="#c7d2fe"/></svg>
		<?php
	}

	public static function footer() {
		echo '<p class="vg-foot">' . esc_html( visigate_brand() . ' ' . VISIGATE_VERSION );
		if ( 'Visigate' === visigate_brand() ) {
			echo ' · ' . esc_html__( 'a Persjay Apps plugin', 'visigate' );
		}
		echo '</p>';
	}

	/* ------------------------------ helpers ------------------------------ */

	/**
	 * Plain summary of a rule set's rules.
	 *
	 * @return array{state:string,text:string,chips:string[]} state: hidden|empty|active
	 */
	public static function summarize( $rules ) {
		$rules = is_array( $rules ) ? $rules : array();
		$all   = Visigate_Conditions::all();
		$chips = array();
		$n     = 0;
		foreach ( (array) ( $rules['groups'] ?? array() ) as $g ) {
			foreach ( (array) ( $g['conditions'] ?? array() ) as $c ) {
				++$n;
				$type = (string) ( $c['type'] ?? '' );
				$chips[ $type ] = isset( $all[ $type ]['label'] ) ? (string) $all[ $type ]['label'] : $type;
			}
		}
		$groups = count( (array) ( $rules['groups'] ?? array() ) );
		$show   = 'hide' !== ( $rules['action'] ?? 'show' );
		if ( ! empty( $rules['always_hide'] ) ) {
			return array( 'state' => 'hidden', 'text' => __( 'Always hidden. Its conditions are kept and come back when you turn Always hide off.', 'visigate' ), 'chips' => array_values( $chips ) );
		}
		if ( ! $n ) {
			return array( 'state' => 'empty', 'text' => __( 'No conditions yet, so everyone sees this content.', 'visigate' ), 'chips' => array() );
		}
		if ( 1 === $groups ) {
			$text = 1 === $n
				? ( $show ? __( 'Show when this condition matches.', 'visigate' ) : __( 'Hide when this condition matches.', 'visigate' ) )
				/* translators: %d: number of conditions */
				: sprintf( $show ? __( 'Show when all %d conditions match.', 'visigate' ) : __( 'Hide when all %d conditions match.', 'visigate' ), $n );
		} else {
			/* translators: %d: number of condition groups */
			$text = sprintf( $show ? __( 'Show when any of %d groups matches.', 'visigate' ) : __( 'Hide when any of %d groups matches.', 'visigate' ), $groups );
		}
		return array( 'state' => 'active', 'text' => $text, 'chips' => array_values( $chips ) );
	}

	/**
	 * Status of a rule set for badges: [ key, label, class ].
	 */
	public static function status( $post, $summary = null, $uses = null ) {
		$summary = $summary ? $summary : self::summarize( get_post_meta( $post->ID, Visigate_Ruleset::META, true ) );
		$uses    = null === $uses ? Visigate_Usage::count( $post->ID ) : (int) $uses;
		if ( 'hidden' === $summary['state'] ) {
			return array( 'hidden', __( 'Always hidden', 'visigate' ), 'is-warn' );
		}
		if ( 'publish' !== $post->post_status ) {
			return array( 'draft', __( 'Not published', 'visigate' ), $uses ? 'is-bad' : '' );
		}
		if ( ! $uses ) {
			return array( 'unused', __( 'Unused', 'visigate' ), '' );
		}
		return array( 'active', __( 'Active', 'visigate' ), 'is-ok' );
	}

	/** Mark class for a summary state. */
	public static function mark_class( $state, $status_key = '' ) {
		if ( 'hidden' === $state ) {
			return 'vg-mark is-hidden';
		}
		if ( in_array( $status_key, array( 'unused', 'draft' ), true ) || 'empty' === $state ) {
			return 'vg-mark is-off';
		}
		return 'vg-mark';
	}

	/** Usage index totals: places, and how many blocks / Elementor elements / menu items / shortcodes. */
	public static function usage_totals() {
		$places = array();
		$kinds  = array( 'block' => 0, 'elementor' => 0, 'menu' => 0, 'shortcode' => 0 );
		foreach ( Visigate_Usage::index() as $uses ) {
			foreach ( (array) $uses as $use ) {
				$places[ $use['object'] . ':' . $use['id'] ] = true;
				foreach ( (array) ( $use['how'] ?? array() ) as $kind => $n ) {
					if ( isset( $kinds[ $kind ] ) ) {
						$kinds[ $kind ] += (int) $n;
					}
				}
			}
		}
		return array( 'places' => count( $places ), 'kinds' => $kinds );
	}

	/** "21 blocks · 11 Elementor elements · 6 menu items", skipping zeros. */
	public static function kinds_text( $kinds ) {
		$labels = array(
			/* translators: %d: count */
			'block'     => _n_noop( '%d block', '%d blocks', 'visigate' ),
			/* translators: %d: count */
			'elementor' => _n_noop( '%d Elementor element', '%d Elementor elements', 'visigate' ),
			/* translators: %d: count */
			'menu'      => _n_noop( '%d menu item', '%d menu items', 'visigate' ),
			/* translators: %d: count */
			'shortcode' => _n_noop( '%d shortcode', '%d shortcodes', 'visigate' ),
		);
		$parts = array();
		foreach ( $labels as $k => $noop ) {
			if ( ! empty( $kinds[ $k ] ) ) {
				$parts[] = sprintf( translate_nooped_plural( $noop, $kinds[ $k ], 'visigate' ), $kinds[ $k ] );
			}
		}
		return implode( ' · ', $parts );
	}

	/** Rule cell for the Rule Sets list table. */
	public static function rule_cell( $post_id ) {
		$post  = get_post( $post_id );
		$rules = get_post_meta( $post_id, Visigate_Ruleset::META, true );
		$sum   = self::summarize( $rules );
		$stat  = self::status( $post, $sum );
		echo '<div class="vg-cell">';
		echo '<div class="vg-cell-line"><span class="' . esc_attr( self::mark_class( $sum['state'], $stat[0] ) ) . '" aria-hidden="true"></span>';
		echo '<span class="vg-badge ' . esc_attr( $stat[2] ) . '">' . esc_html( $stat[1] ) . '</span></div>';
		echo '<span class="vg-cell-text">' . esc_html( $sum['text'] ) . '</span>';
		foreach ( Visigate_Warnings::server_flags( is_array( $rules ) ? $rules : array() ) as $flag ) {
			echo '<span class="vg-flag">⚠ ' . esc_html( $flag ) . '</span>';
		}
		if ( $sum['chips'] ) {
			echo '<span class="vg-chips">';
			foreach ( $sum['chips'] as $chip ) {
				echo '<span class="vg-chip">' . esc_html( $chip ) . '</span>';
			}
			echo '</span>';
		}
		echo '</div>';
	}

	/* ------------------------------ Overview ------------------------------ */

	public static function render_overview() {
		if ( ! current_user_can( visigate_capability() ) ) {
			return;
		}
		$sets = get_posts(
			array(
				'post_type'   => Visigate_Ruleset::CPT,
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts' => 500,
				'orderby'     => 'modified',
				'order'       => 'DESC',
			)
		);
		$active = 0;
		$hidden = 0;
		$rows   = array();
		foreach ( $sets as $s ) {
			$sum  = self::summarize( get_post_meta( $s->ID, Visigate_Ruleset::META, true ) );
			$uses = Visigate_Usage::count( $s->ID );
			$stat = self::status( $s, $sum, $uses );
			if ( 'hidden' === $stat[0] ) {
				++$hidden;
			} elseif ( 'publish' === $s->post_status ) {
				++$active;
			}
			if ( count( $rows ) < 8 ) {
				$rows[] = array( 'post' => $s, 'sum' => $sum, 'uses' => $uses, 'stat' => $stat );
			}
		}
		$totals = self::usage_totals();
		$issues = Visigate_Cache_Check::issue_count();
		$new    = admin_url( 'post-new.php?post_type=' . Visigate_Ruleset::CPT );
		?>
		<div class="wrap vg-wrap vg-page">
			<h1 class="vg-sr"><?php /* translators: %s: plugin name */ echo esc_html( sprintf( __( '%s overview', 'visigate' ), visigate_brand() ) ); ?></h1>
			<hr class="wp-header-end">

			<div class="vg-stats">
				<a class="vg-stat is-solid" href="<?php echo esc_url( Visigate_Admin_Rules::url() ); ?>">
					<span class="vg-stat-label"><?php esc_html_e( 'Shown by rules', 'visigate' ); ?></span>
					<span class="vg-stat-value"><?php /* translators: %d: number of rule sets */ echo esc_html( sprintf( _n( '%d rule set', '%d rule sets', $active, 'visigate' ), $active ) ); ?></span>
					<span class="vg-stat-note"><?php esc_html_e( 'published and checking visitors', 'visigate' ); ?></span>
				</a>
				<a class="vg-stat is-ghost" href="<?php echo esc_url( Visigate_Admin_Rules::url() ); ?>">
					<span class="vg-stat-label"><?php esc_html_e( 'Always hidden', 'visigate' ); ?></span>
					<span class="vg-stat-value"><?php /* translators: %d: number of rule sets */ echo esc_html( sprintf( _n( '%d rule set', '%d rule sets', $hidden, 'visigate' ), $hidden ) ); ?></span>
					<span class="vg-stat-note"><?php esc_html_e( 'paused, conditions kept', 'visigate' ); ?></span>
				</a>
				<a class="vg-stat" href="<?php echo esc_url( Visigate_Usage::usage_url() ); ?>">
					<span class="vg-stat-label"><?php esc_html_e( 'Places using rule sets', 'visigate' ); ?></span>
					<span class="vg-stat-value"><?php echo (int) $totals['places']; ?></span>
					<span class="vg-stat-note"><?php echo esc_html( self::kinds_text( $totals['kinds'] ) ? self::kinds_text( $totals['kinds'] ) : __( 'Not used anywhere yet', 'visigate' ) ); ?></span>
				</a>
				<a class="vg-stat" href="<?php echo esc_url( Visigate_Cache_Check::page_url() ); ?>">
					<span class="vg-stat-label"><?php esc_html_e( 'Cache safety', 'visigate' ); ?></span>
					<?php if ( $issues ) : ?>
						<span class="vg-stat-status"><span class="vg-dot is-warn" aria-hidden="true"></span><?php /* translators: %d: number of warnings */ echo esc_html( sprintf( _n( '%d warning', '%d warnings', $issues, 'visigate' ), $issues ) ); ?></span>
						<span class="vg-stat-note"><?php esc_html_e( 'Review the cache check', 'visigate' ); ?></span>
					<?php else : ?>
						<span class="vg-stat-status"><span class="vg-dot is-ok" aria-hidden="true"></span><?php esc_html_e( 'No problems found', 'visigate' ); ?></span>
						<span class="vg-stat-note"><?php esc_html_e( 'Open the cache check', 'visigate' ); ?></span>
					<?php endif; ?>
				</a>
			</div>

			<div class="vg-cols">
				<section class="vg-card vg-col-main" aria-labelledby="vg-ov-sets">
					<div class="vg-card-head">
						<h2 id="vg-ov-sets"><?php esc_html_e( 'Recently edited rule sets', 'visigate' ); ?></h2>
						<div class="vg-actions">
							<a class="vg-btn" href="<?php echo esc_url( Visigate_Admin_Rules::url() ); ?>"><?php esc_html_e( 'All rule sets', 'visigate' ); ?></a>
							<a class="vg-btn is-primary" href="<?php echo esc_url( $new ); ?>"><?php esc_html_e( 'New rule set', 'visigate' ); ?></a>
						</div>
					</div>
					<?php if ( ! $rows ) : ?>
						<div class="vg-empty">
							<p><?php esc_html_e( 'No rule sets yet. A rule set is a reusable rule, such as “logged-in customers on mobile”, that you can apply to any block, Elementor element or menu item.', 'visigate' ); ?></p>
							<a class="vg-btn is-primary" href="<?php echo esc_url( $new ); ?>"><?php esc_html_e( 'Create your first rule set', 'visigate' ); ?></a>
						</div>
					<?php else : ?>
						<div class="vg-rows">
							<div class="vg-row is-head vg-grid-sets" aria-hidden="true">
								<span><?php esc_html_e( 'Rule set', 'visigate' ); ?></span><span><?php esc_html_e( 'Conditions', 'visigate' ); ?></span><span><?php esc_html_e( 'Used in', 'visigate' ); ?></span><span><?php esc_html_e( 'Status', 'visigate' ); ?></span>
							</div>
							<?php foreach ( $rows as $r ) : ?>
								<?php
								$p     = $r['post'];
								$title = $p->post_title ? $p->post_title : '#' . $p->ID;
								?>
								<div class="vg-row vg-grid-sets<?php echo 'hidden' === $r['sum']['state'] ? ' is-hidden' : ''; ?>">
									<div class="vg-row-name">
										<span class="<?php echo esc_attr( self::mark_class( $r['sum']['state'], $r['stat'][0] ) ); ?>" aria-hidden="true"></span>
										<div>
											<a class="vg-row-title" href="<?php echo esc_url( (string) get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( $title ); ?></a>
											<span class="vg-row-text"><?php echo esc_html( $r['sum']['text'] ); ?></span>
										</div>
									</div>
									<div class="vg-chips">
										<?php foreach ( $r['sum']['chips'] as $chip ) : ?>
											<span class="vg-chip"><?php echo esc_html( $chip ); ?></span>
										<?php endforeach; ?>
									</div>
									<span>
										<?php if ( $r['uses'] ) : ?>
											<a href="<?php echo esc_url( Visigate_Usage::usage_url( $p->ID ) ); ?>"><?php /* translators: %d: number of places */ echo esc_html( sprintf( _n( '%d place', '%d places', $r['uses'], 'visigate' ), $r['uses'] ) ); ?></a>
										<?php else : ?>
											<span class="vg-muted"><?php esc_html_e( 'Not used', 'visigate' ); ?></span>
										<?php endif; ?>
									</span>
									<span><span class="vg-badge <?php echo esc_attr( $r['stat'][2] ); ?>"><?php echo esc_html( $r['stat'][1] ); ?></span></span>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</section>

				<aside class="vg-col-side">
					<?php if ( Visigate_View_As::enabled() ) : ?>
						<section class="vg-card is-pad" aria-labelledby="vg-ov-preview">
							<h2 id="vg-ov-preview"><?php esc_html_e( 'Preview the site as', 'visigate' ); ?></h2>
							<p class="vg-muted"><?php esc_html_e( 'Opens your home page as another visitor. You stay logged in.', 'visigate' ); ?></p>
							<div class="vg-actions">
								<?php
								self::preview_button( array( 'user' => 'guest' ), __( 'Logged-out visitor', 'visigate' ), true );
								foreach ( array( 'subscriber', 'customer' ) as $role ) {
									if ( wp_roles()->is_role( $role ) ) {
										self::preview_button( array( 'user' => 'role:' . $role ), translate_user_role( wp_roles()->get_names()[ $role ] ) );
									}
								}
								self::preview_button( array( 'device' => 'mobile' ), __( 'Mobile visitor', 'visigate' ) );
								?>
							</div>
							<a href="<?php echo esc_url( Visigate_View_As::page_url() ); ?>"><?php esc_html_e( 'More options', 'visigate' ); ?></a>
						</section>
					<?php endif; ?>

					<?php if ( $issues ) : ?>
						<section class="vg-card is-pad is-warn" aria-labelledby="vg-ov-cache">
							<h2 id="vg-ov-cache"><?php esc_html_e( 'A page cache may serve the wrong version', 'visigate' ); ?></h2>
							<p><?php echo esc_html( Visigate_Cache_Check::issue_summary() ); ?></p>
							<a href="<?php echo esc_url( Visigate_Cache_Check::page_url() ); ?>"><strong><?php esc_html_e( 'Open the cache check', 'visigate' ); ?></strong></a>
						</section>
					<?php endif; ?>

					<?php if ( ! visigate_is_pro() ) : ?>
						<section class="vg-card is-pad is-ghost" aria-labelledby="vg-ov-pro">
							<h2 id="vg-ov-pro"><?php esc_html_e( 'More conditions with Visigate Pro', 'visigate' ); ?></h2>
							<p><?php esc_html_e( 'Country, cookies, referrer, WooCommerce cart and purchases, fallback content for hidden blocks, and ready-made rule templates.', 'visigate' ); ?></p>
							<a class="vg-btn is-amber" href="<?php echo esc_url( (string) apply_filters( 'visigate_upgrade_url', 'https://persjay.com/plugins/visigate/#pricing' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro plans', 'visigate' ); ?></a>
						</section>
					<?php endif; ?>
				</aside>
			</div>
			<?php self::footer(); ?>
		</div>
		<?php
	}

	/** A one-click View as form button. */
	private static function preview_button( $state, $label, $primary = false ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( Visigate_View_As::NONCE ); ?>
			<input type="hidden" name="action" value="visigate_view_as">
			<input type="hidden" name="return" value="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php foreach ( $state as $k => $v ) : ?>
				<input type="hidden" name="view_as[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $v ); ?>">
			<?php endforeach; ?>
			<button type="submit" class="vg-btn<?php echo $primary ? ' is-soft' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
