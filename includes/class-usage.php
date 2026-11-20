<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Where is this rule set used?"
 *
 * Builds an index of every place that references a saved rule set:
 *  - blocks (`visigateSet` attribute) in any post type, including patterns, templates, template parts and navigation menus
 *  - [visigate set="…"] shortcodes in post content and widgets
 *  - Elementor elements (classic and atomic) in `_elementor_data`
 *  - classic menu items (`_visigate_set` meta)
 *
 * The index is cached per site and rebuilt after content that could reference a rule set changes.
 * PHP calls such as visigate_visible( 12 ) in theme files cannot be detected.
 */
class Visigate_Usage {

	const CACHE = 'visigate_usage_index';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'add_meta_boxes_' . Visigate_Ruleset::CPT, array( __CLASS__, 'add_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'edit_notice' ) );
		add_action( 'admin_post_visigate_usage_refresh', array( __CLASS__, 'handle_refresh' ) );

		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'wp_update_nav_menu', 'wp_delete_nav_menu' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_meta' ), 10, 3 );
		}
		foreach ( self::widget_options() as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'flush' ) );
		}
	}

	private static function widget_options() {
		return array( 'widget_block', 'widget_text', 'widget_custom_html' );
	}

	public static function flush() {
		delete_transient( self::CACHE );
	}

	public static function flush_meta( $meta_id, $object_id, $meta_key ) {
		if ( in_array( $meta_key, array( '_elementor_data', Visigate_Menus::META, Visigate_Ruleset::META ), true ) ) {
			self::flush();
		}
	}

	/* ------------------------------- index ------------------------------- */

	/**
	 * Map of rule set ID => list of uses. Each use:
	 *   array( 'object' => 'post'|'menu_item'|'widget', 'id' => int|string, 'how' => array( 'block' => n, 'shortcode' => n, 'elementor' => n, 'menu' => n ) )
	 *
	 * @param bool $refresh Rebuild instead of using the cached index.
	 */
	public static function index( $refresh = false ) {
		$index = $refresh ? false : get_transient( self::CACHE );
		if ( is_array( $index ) ) {
			return $index;
		}
		$index = self::build();
		set_transient( self::CACHE, $index, DAY_IN_SECONDS );
		return $index;
	}

	/** Uses of one rule set. */
	public static function for_set( $set_id ) {
		$index = self::index();
		return isset( $index[ (int) $set_id ] ) ? $index[ (int) $set_id ] : array();
	}

	/** Number of places (posts, menu items, widgets) that use a rule set. */
	public static function count( $set_id ) {
		return count( self::for_set( $set_id ) );
	}

	/** IDs found in a piece of content, grouped by kind: array( set_id => array( kind => count ) ). */
	public static function scan_content( $content ) {
		$found   = array();
		$content = (string) $content;
		if ( false !== strpos( $content, 'visigateSet' ) && preg_match_all( '/"visigateSet"\s*:\s*"?(\d+)/', $content, $m ) ) {
			foreach ( $m[1] as $id ) {
				self::bump( $found, (int) $id, 'block' );
			}
		}
		if ( false !== stripos( $content, '[visigate' ) && preg_match_all( '/\[visigate\b[^\]]*?\bset\s*=\s*(?:"|\'|&quot;|&#039;|&#8221;|&#8243;)?\s*(\d+)/i', $content, $m ) ) {
			foreach ( $m[1] as $id ) {
				self::bump( $found, (int) $id, 'shortcode' );
			}
		}
		return $found;
	}

	/** Rule set IDs in Elementor data (classic string settings and atomic typed props). */
	public static function scan_elementor( $json ) {
		$found = array();
		if ( preg_match_all( '/"visigate_set"\s*:\s*(?:"(\d+)"|(\d+)|\{[^{}]*?"value"\s*:\s*"?(\d+)"?[^{}]*\})/', (string) $json, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$id = 0;
				foreach ( array( 1, 2, 3 ) as $i ) {
					if ( ! empty( $hit[ $i ] ) ) {
						$id = (int) $hit[ $i ];
						break;
					}
				}
				self::bump( $found, $id, 'elementor' );
			}
		}
		return $found;
	}

	private static function bump( &$found, $id, $kind ) {
		if ( $id <= 0 ) {
			return;
		}
		$found[ $id ][ $kind ] = ( $found[ $id ][ $kind ] ?? 0 ) + 1;
	}

	private static function add( &$index, $found, $object, $id ) {
		foreach ( $found as $set_id => $how ) {
			$key = $object . ':' . $id;
			if ( isset( $index[ $set_id ][ $key ] ) ) {
				foreach ( $how as $kind => $n ) {
					$index[ $set_id ][ $key ]['how'][ $kind ] = ( $index[ $set_id ][ $key ]['how'][ $kind ] ?? 0 ) + $n;
				}
			} else {
				$index[ $set_id ][ $key ] = array( 'object' => $object, 'id' => $id, 'how' => $how );
			}
		}
	}

	private static function build() {
		global $wpdb;
		$index = array();

		// Blocks and shortcodes in post content (all post types, including patterns, templates and navigation menus).
		$offset = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN (%s, %s) AND post_status NOT IN ('auto-draft', 'trash', 'inherit') AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID LIMIT %d, 200", 'revision', Visigate_Ruleset::CPT, '%visigateSet%', '%[visigate%', $offset ) );
			foreach ( $rows as $row ) {
				self::add( $index, self::scan_content( $row->post_content ), 'post', (int) $row->ID );
			}
			$offset += 200;
		} while ( count( $rows ) === 200 );

		// Elementor elements.
		$offset = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_data' AND pm.meta_value LIKE %s AND p.post_type NOT IN (%s, %s) AND p.post_status NOT IN ('auto-draft', 'trash', 'inherit') ORDER BY pm.meta_id LIMIT %d, 100", '%visigate\_set%', 'revision', Visigate_Ruleset::CPT, $offset ) );
			foreach ( $rows as $row ) {
				self::add( $index, self::scan_elementor( $row->meta_value ), 'post', (int) $row->post_id );
			}
			$offset += 100;
		} while ( count( $rows ) === 100 );

		// Classic menu items.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'nav_menu_item' AND p.post_status NOT IN ('trash','auto-draft')", Visigate_Menus::META ) );
		foreach ( $rows as $row ) {
			if ( (int) $row->meta_value > 0 ) {
				self::add( $index, array( (int) $row->meta_value => array( 'menu' => 1 ) ), 'menu_item', (int) $row->post_id );
			}
		}

		// Widgets (block, text and custom HTML widgets).
		foreach ( self::widget_options() as $option ) {
			$widgets = get_option( $option, array() );
			if ( ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $n => $w ) {
				if ( ! is_array( $w ) ) {
					continue;
				}
				$text = (string) ( $w['content'] ?? '' ) . ' ' . (string) ( $w['text'] ?? '' );
				$base = substr( $option, 7 ); // widget_block => block.
				self::add( $index, self::scan_content( $text ), 'widget', $base . '-' . $n );
			}
		}

		foreach ( $index as $set_id => $uses ) {
			$index[ $set_id ] = array_values( $uses );
		}
		$index = apply_filters( 'visigate_usage_index', $index );
		return is_array( $index ) ? $index : array();
	}

	/* ---------------------------- presentation ---------------------------- */

	/** Display details for one use: title, type label, edit URL, view URL, status label and "how" text. */
	public static function describe( $use ) {
		$d = array( 'title' => '', 'type' => '', 'edit' => '', 'view' => '', 'status' => '', 'how' => self::how_text( $use['how'] ) );

		if ( 'post' === $use['object'] ) {
			$post = get_post( (int) $use['id'] );
			if ( ! $post ) {
				return null;
			}
			$type       = get_post_type_object( $post->post_type );
			$labels     = array(
				'wp_block'          => __( 'Pattern', 'visigate' ),
				'wp_template'       => __( 'Template', 'visigate' ),
				'wp_template_part'  => __( 'Template part', 'visigate' ),
				'wp_navigation'     => __( 'Navigation menu', 'visigate' ),
				'elementor_library' => __( 'Elementor template', 'visigate' ),
			);
			$d['title'] = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'visigate' );
			$d['type']  = $labels[ $post->post_type ] ?? ( $type ? $type->labels->singular_name : $post->post_type );
			$d['edit']  = (string) get_edit_post_link( $post->ID, 'raw' );
			if ( ! empty( $use['how']['elementor'] ) && current_user_can( 'edit_post', $post->ID ) ) {
				$d['edit'] = admin_url( 'post.php?post=' . $post->ID . '&action=elementor' );
			}
			if ( 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
				$d['view'] = (string) get_permalink( $post );
			}
			$status      = get_post_status_object( $post->post_status );
			$d['status'] = $status ? $status->label : $post->post_status;
			return $d;
		}

		if ( 'menu_item' === $use['object'] ) {
			$item = get_post( (int) $use['id'] );
			if ( ! $item ) {
				return null;
			}
			$item  = wp_setup_nav_menu_item( $item );
			$menus = wp_get_object_terms( $item->ID, 'nav_menu' );
			$menu  = ( ! is_wp_error( $menus ) && $menus ) ? $menus[0] : null;
			/* translators: 1: menu item title, 2: menu name. */
			$d['title']  = $menu ? sprintf( __( '%1$s (in %2$s)', 'visigate' ), $item->title, $menu->name ) : $item->title;
			$d['type']   = __( 'Menu item', 'visigate' );
			$d['edit']   = $menu && current_user_can( 'edit_theme_options' ) ? admin_url( 'nav-menus.php?action=edit&menu=' . $menu->term_id ) : '';
			$d['status'] = __( 'Published', 'visigate' );
			return $d;
		}

		if ( 'widget' === $use['object'] ) {
			/* translators: %s: widget ID. */
			$d['title'] = sprintf( __( 'Widget %s', 'visigate' ), $use['id'] );
			$d['type']  = __( 'Widget', 'visigate' );
			$d['edit']  = current_user_can( 'edit_theme_options' ) ? admin_url( 'widgets.php' ) : '';
			return $d;
		}
		return apply_filters( 'visigate_usage_describe', null, $use );
	}

	private static function how_text( $how ) {
		$parts = array();
		foreach ( (array) $how as $kind => $n ) {
			$n = (int) $n;
			switch ( $kind ) {
				case 'block':
					/* translators: %d: count. */
					$parts[] = sprintf( _n( '%d block', '%d blocks', $n, 'visigate' ), $n );
					break;
				case 'shortcode':
					/* translators: %d: count. */
					$parts[] = sprintf( _n( '%d shortcode', '%d shortcodes', $n, 'visigate' ), $n );
					break;
				case 'elementor':
					/* translators: %d: count. */
					$parts[] = sprintf( _n( '%d Elementor element', '%d Elementor elements', $n, 'visigate' ), $n );
					break;
				case 'menu':
					/* translators: %d: count. */
					$parts[] = sprintf( _n( '%d menu item', '%d menu items', $n, 'visigate' ), $n );
					break;
				case 'audience':
					/* translators: %d: count. */
					$parts[] = sprintf( _n( '%d "Rule set" condition', '%d "Rule set" conditions', $n, 'visigate' ), $n );
					break;
			}
		}
		return implode( ', ', $parts );
	}

	public static function usage_url( $set_id = 0 ) {
		$args = array( 'post_type' => Visigate_Ruleset::CPT, 'page' => 'visigate-usage' );
		if ( $set_id ) {
			$args['set'] = (int) $set_id;
		}
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/* ------------------------------- admin ------------------------------- */

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Visigate_Ruleset::CPT,
			__( 'Rule Set Usage', 'visigate' ),
			__( 'Usage', 'visigate' ),
			visigate_capability(),
			'visigate-usage',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_refresh() {
		check_admin_referer( 'visigate_usage_refresh' );
		if ( ! current_user_can( visigate_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'visigate' ), '', array( 'response' => 403 ) );
		}
		self::index( true );
		$set = isset( $_POST['set'] ) ? absint( $_POST['set'] ) : 0;
		wp_safe_redirect( add_query_arg( 'refreshed', 1, self::usage_url( $set ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( visigate_capability() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$set_id = isset( $_GET['set'] ) ? absint( $_GET['set'] ) : 0;
		$set    = $set_id ? get_post( $set_id ) : null;
		if ( $set && Visigate_Ruleset::CPT !== $set->post_type ) {
			$set = null;
		}
		$refreshed = isset( $_GET['refreshed'] );
		$kind      = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$kind      = in_array( $kind, array( 'block', 'elementor', 'menu', 'shortcode' ), true ) ? $kind : '';
		$group     = isset( $_GET['group'] ) && 'page' === $_GET['group'] ? 'page' : 'set';
		// phpcs:enable
		echo '<div class="wrap vg-wrap vg-page visigate-usage">';
		if ( $set ) {
			/* translators: %s: rule set title. */
			echo '<h1 class="vg-sr">' . esc_html( sprintf( __( 'Where “%s” is used', 'visigate' ), $set->post_title ? $set->post_title : '#' . $set->ID ) ) . '</h1>';
		} else {
			echo '<h1 class="vg-sr">' . esc_html__( 'Rule Set Usage', 'visigate' ) . '</h1>';
		}
		echo '<hr class="wp-header-end">';
		if ( $refreshed ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Usage list refreshed.', 'visigate' ) . '</p></div>';
		}

		if ( ! $set ) {
			$totals = Visigate_Admin_UI::usage_totals();
			$tiles  = array(
				''          => array( __( 'All places', 'visigate' ), $totals['places'] ),
				'block'     => array( __( 'Blocks', 'visigate' ), $totals['kinds']['block'] ),
				'elementor' => array( __( 'Elementor elements', 'visigate' ), $totals['kinds']['elementor'] ),
				'menu'      => array( __( 'Menu items', 'visigate' ), $totals['kinds']['menu'] ),
				'shortcode' => array( __( 'Shortcodes', 'visigate' ), $totals['kinds']['shortcode'] ),
			);
			echo '<nav class="vg-stats" aria-label="' . esc_attr__( 'Filter by type', 'visigate' ) . '">';
			foreach ( $tiles as $k => $t ) {
				$on   = $k === $kind;
				$href = add_query_arg( array_filter( array( 'kind' => $k, 'group' => 'page' === $group ? 'page' : '' ) ), self::usage_url() );
				echo '<a class="vg-stat' . ( $on ? ' is-solid' : '' ) . '" href="' . esc_url( $href ) . '"' . ( $on ? ' aria-current="true"' : '' ) . '><span class="vg-stat-label">' . esc_html( $t[0] ) . '</span><span class="vg-stat-value">' . (int) $t[1] . '</span></a>';
			}
			echo '</nav>';
		}

		echo '<section class="vg-card">';
		echo '<div class="vg-card-head">';
		if ( $set ) {
			/* translators: %s: rule set title. */
			echo '<h2>' . esc_html( sprintf( __( 'Where “%s” is used', 'visigate' ), $set->post_title ? $set->post_title : '#' . $set->ID ) ) . '</h2>';
		} else {
			$n = Visigate_Admin_UI::usage_totals()['places'];
			/* translators: %d: number of places. */
			echo '<h2>' . esc_html( sprintf( _n( '%d place uses a rule set', '%d places use rule sets', $n, 'visigate' ), $n ) ) . '</h2>';
		}
		echo '<div class="vg-actions">';
		if ( ! $set ) {
			echo '<nav class="vg-segment" aria-label="' . esc_attr__( 'Group by', 'visigate' ) . '">';
			foreach ( array( 'set' => __( 'By rule set', 'visigate' ), 'page' => __( 'By page', 'visigate' ) ) as $g => $label ) {
				$href = add_query_arg( array_filter( array( 'kind' => $kind, 'group' => 'page' === $g ? 'page' : '' ) ), self::usage_url() );
				echo '<a class="vg-seg' . ( $g === $group ? ' is-active' : '' ) . '" href="' . esc_url( $href ) . '"' . ( $g === $group ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			echo '</nav>';
		}
		if ( $set ) {
			echo '<a class="vg-btn" href="' . esc_url( self::usage_url() ) . '">' . esc_html__( 'All rule sets', 'visigate' ) . '</a>';
			echo '<a class="vg-btn" href="' . esc_url( (string) get_edit_post_link( $set->ID ) ) . '">' . esc_html__( 'Edit rule set', 'visigate' ) . '</a>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'visigate_usage_refresh' ); ?>
			<input type="hidden" name="action" value="visigate_usage_refresh">
			<input type="hidden" name="set" value="<?php echo (int) ( $set ? $set->ID : 0 ); ?>">
			<button type="submit" class="vg-btn"><?php esc_html_e( 'Refresh usage list', 'visigate' ); ?></button>
		</form>
		<?php
		echo '</div></div>';

		if ( $set ) {
			self::render_set_table( $set );
		} else {
			self::render_overview( $kind, $group );
		}
		echo '<div class="vg-card-foot"><span>' . esc_html__( 'Checks blocks, patterns, templates, template parts, navigation menus, shortcodes, widgets, Elementor elements and menu items. Revisions and trashed content are skipped. Rule sets used from PHP, such as visigate_visible( 12 ) in a theme file, cannot be detected.', 'visigate' ) . '</span></div>';
		echo '</section>';
		Visigate_Admin_UI::footer();
		echo '</div>';
	}

	/** One rule set's header row (mark, title, summary, count). */
	private static function render_group_head( $set, $count, $link = true ) {
		$sum   = Visigate_Admin_UI::summarize( get_post_meta( $set->ID, Visigate_Ruleset::META, true ) );
		$stat  = Visigate_Admin_UI::status( $set, $sum, $count );
		$title = $set->post_title ? $set->post_title : '#' . $set->ID;
		echo '<div class="vg-group-head' . ( 'hidden' === $sum['state'] ? ' is-hidden' : '' ) . '">';
		echo '<span class="' . esc_attr( Visigate_Admin_UI::mark_class( $sum['state'], $stat[0] ) ) . '" aria-hidden="true"></span>';
		if ( $link ) {
			echo '<a class="vg-row-title" href="' . esc_url( self::usage_url( $set->ID ) ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo '<strong class="vg-row-title">' . esc_html( $title ) . '</strong>';
		}
		echo '<span class="vg-row-text">' . esc_html( $sum['text'] ) . '</span>';
		if ( 'active' !== $stat[0] && 'unused' !== $stat[0] ) {
			echo '<span class="vg-badge ' . esc_attr( $stat[2] ) . '" style="margin-left:0">' . esc_html( $stat[1] ) . '</span>';
		}
		/* translators: %d: number of places. */
		echo '<span class="vg-badge is-info">' . esc_html( sprintf( _n( '%d place', '%d places', $count, 'visigate' ), $count ) ) . '</span>';
		echo '</div>';
	}

	/** Rows for a list of uses. */
	private static function render_use_rows( $uses ) {
		echo '<div class="vg-rows">';
		foreach ( $uses as $use ) {
			$d = self::describe( $use );
			if ( ! $d ) {
				continue;
			}
			echo '<div class="vg-row is-sub vg-grid-uses">';
			echo '<span class="vg-kind">' . esc_html( $d['type'] ) . '</span>';
			echo '<span>' . ( $d['edit'] ? '<a class="vg-row-title" href="' . esc_url( $d['edit'] ) . '">' . esc_html( $d['title'] ) . '</a>' : '<strong>' . esc_html( $d['title'] ) . '</strong>' ) . '</span>';
			echo '<span class="vg-row-text">' . esc_html( $d['how'] ) . '</span>';
			echo '<span class="vg-row-text">' . esc_html( $d['status'] ) . '</span>';
			echo '<span>' . ( $d['view'] ? '<a href="' . esc_url( $d['view'] ) . '">' . esc_html__( 'View', 'visigate' ) . '</a>' : '' ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	private static function render_set_table( $set ) {
		$uses = self::for_set( $set->ID );
		if ( 'publish' !== $set->post_status && $uses ) {
			echo '<div class="vg-card-body"><div class="notice notice-warning inline"><p>' . esc_html__( 'This rule set is not published, so content that uses it is hidden from everyone. Publish it, or choose another rule set in this content.', 'visigate' ) . '</p></div></div>';
		}
		self::render_group_head( $set, count( $uses ), false );
		if ( ! $uses ) {
			echo '<div class="vg-empty"><p>' . esc_html__( 'This rule set is not used anywhere yet. Editing or deleting it will not change any content.', 'visigate' ) . '</p></div>';
			return;
		}
		echo '<div class="vg-card-body"><p class="vg-muted">' . esc_html(
			sprintf(
				/* translators: %d: number of places. */
				_n( 'Used in %d place. Changes to this rule set apply to all of them.', 'Used in %d places. Changes to this rule set apply to all of them.', count( $uses ), 'visigate' ),
				count( $uses )
			)
		) . '</p></div>';
		self::render_use_rows( $uses );
	}

	/** Uses that include the chosen kind (block, elementor, menu, shortcode), or all of them. */
	private static function filter_kind( $uses, $kind ) {
		if ( '' === $kind ) {
			return $uses;
		}
		return array_values(
			array_filter(
				$uses,
				function ( $u ) use ( $kind ) {
					return ! empty( $u['how'][ $kind ] );
				}
			)
		);
	}

	private static function render_overview( $kind = '', $group = 'set' ) {
		$sets = get_posts(
			array(
				'post_type'   => Visigate_Ruleset::CPT,
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts' => 500,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		if ( ! $sets ) {
			echo '<div class="vg-empty"><p>' . esc_html__( 'No rule sets yet.', 'visigate' ) . '</p><a class="vg-btn is-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=' . Visigate_Ruleset::CPT ) ) . '">' . esc_html__( 'New rule set', 'visigate' ) . '</a></div>';
			return;
		}
		if ( 'page' === $group ) {
			self::render_by_page( $sets, $kind );
			return;
		}
		$unused = array();
		$shown  = 0;
		foreach ( $sets as $s ) {
			$all  = self::for_set( $s->ID );
			$uses = self::filter_kind( $all, $kind );
			if ( ! $all ) {
				$unused[] = $s;
				continue;
			}
			if ( ! $uses ) {
				continue;
			}
			++$shown;
			self::render_group_head( $s, count( $uses ) );
			self::render_use_rows( array_slice( $uses, 0, 10 ) );
			if ( count( $uses ) > 10 ) {
				/* translators: %d: number of places. */
				echo '<div class="vg-card-foot"><a href="' . esc_url( self::usage_url( $s->ID ) ) . '">' . esc_html( sprintf( __( 'See all %d places', 'visigate' ), count( $uses ) ) ) . '</a></div>';
			}
		}
		if ( ! $shown ) {
			echo '<div class="vg-empty"><p>' . esc_html( '' !== $kind ? __( 'No rule set is used in this type of content.', 'visigate' ) : __( 'No rule set is used anywhere yet. Choose one in the block editor sidebar, in Elementor (Advanced → Visigate), on a menu item, or with a shortcode.', 'visigate' ) ) . '</p></div>';
		}
		if ( $unused && '' === $kind ) {
			$links = array();
			foreach ( $unused as $s ) {
				$links[] = '<a href="' . esc_url( (string) get_edit_post_link( $s->ID ) ) . '">' . esc_html( $s->post_title ? $s->post_title : '#' . $s->ID ) . '</a>';
			}
			echo '<div class="vg-card-foot" style="border-top:1px solid #eef0f6"><strong>' . esc_html__( 'Not used anywhere:', 'visigate' ) . '</strong> <span>' . wp_kses( implode( ', ', $links ), array( 'a' => array( 'href' => array() ) ) ) . '</span></div>';
		}
	}

	/** One group per page, menu or widget, listing the rule sets it uses. */
	private static function render_by_page( $sets, $kind ) {
		$by_id  = array();
		foreach ( $sets as $s ) {
			$by_id[ $s->ID ] = $s;
		}
		$places = array();
		foreach ( self::index() as $set_id => $uses ) {
			if ( ! isset( $by_id[ $set_id ] ) ) {
				continue;
			}
			foreach ( self::filter_kind( (array) $uses, $kind ) as $use ) {
				$key = $use['object'] . ':' . $use['id'];
				if ( ! isset( $places[ $key ] ) ) {
					$places[ $key ] = array( 'use' => $use, 'sets' => array() );
				}
				$places[ $key ]['sets'][ $set_id ] = $use['how'];
			}
		}
		if ( ! $places ) {
			echo '<div class="vg-empty"><p>' . esc_html__( 'Nothing found for this filter.', 'visigate' ) . '</p></div>';
			return;
		}
		foreach ( $places as $place ) {
			$d = self::describe( $place['use'] );
			if ( ! $d ) {
				continue;
			}
			echo '<div class="vg-group-head">';
			echo '<span class="vg-kind">' . esc_html( $d['type'] ) . '</span>';
			echo $d['edit'] ? '<a class="vg-row-title" href="' . esc_url( $d['edit'] ) . '">' . esc_html( $d['title'] ) . '</a>' : '<strong class="vg-row-title">' . esc_html( $d['title'] ) . '</strong>';
			echo '<span class="vg-row-text">' . esc_html( $d['status'] ) . '</span>';
			if ( $d['view'] ) {
				echo '<a href="' . esc_url( $d['view'] ) . '">' . esc_html__( 'View', 'visigate' ) . '</a>';
			}
			/* translators: %d: number of rule sets. */
			echo '<span class="vg-badge is-info">' . esc_html( sprintf( _n( '%d rule set', '%d rule sets', count( $place['sets'] ), 'visigate' ), count( $place['sets'] ) ) ) . '</span>';
			echo '</div><div class="vg-rows">';
			foreach ( $place['sets'] as $set_id => $how ) {
				$set  = $by_id[ $set_id ];
				$sum  = Visigate_Admin_UI::summarize( get_post_meta( $set_id, Visigate_Ruleset::META, true ) );
				$stat = Visigate_Admin_UI::status( $set, $sum );
				echo '<div class="vg-row is-sub vg-grid-bypage">';
				echo '<div class="vg-row-name"><span class="' . esc_attr( Visigate_Admin_UI::mark_class( $sum['state'], $stat[0] ) ) . '" aria-hidden="true"></span><div>';
				echo '<a class="vg-row-title" href="' . esc_url( (string) get_edit_post_link( $set_id ) ) . '">' . esc_html( $set->post_title ? $set->post_title : '#' . $set_id ) . '</a>';
				echo '<span class="vg-row-text">' . esc_html( $sum['text'] ) . '</span></div></div>';
				echo '<span class="vg-row-text">' . esc_html( self::how_text( $how ) ) . '</span>';
				echo '<span><span class="vg-badge ' . esc_attr( $stat[2] ) . '">' . esc_html( $stat[1] ) . '</span></span>';
				echo '</div>';
			}
			echo '</div>';
		}
	}

	/** "N places" link, or a muted "Not used". Also used by the Rule Sets list column. */
	public static function render_count( $set_id ) {
		$n = self::count( $set_id );
		if ( ! $n ) {
			echo '<span style="color:#646970">' . esc_html__( 'Not used', 'visigate' ) . '</span>';
			return;
		}
		/* translators: %d: number of places. */
		echo '<a href="' . esc_url( self::usage_url( $set_id ) ) . '">' . esc_html( sprintf( _n( '%d place', '%d places', $n, 'visigate' ), $n ) ) . '</a>';
		if ( 'publish' !== get_post_status( $set_id ) ) {
			echo '<br><span style="color:#b32d2e">' . esc_html__( 'Not published: content using it is hidden', 'visigate' ) . '</span>';
		}
	}

	public static function add_metabox( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}
		add_meta_box( 'visigate-usage', __( 'Where it’s used', 'visigate' ), array( __CLASS__, 'render_metabox' ), Visigate_Ruleset::CPT, 'side', 'default' );
	}

	public static function render_metabox( $post ) {
		$uses = self::for_set( $post->ID );
		if ( ! $uses ) {
			echo '<p>' . esc_html__( 'Not used anywhere yet.', 'visigate' ) . '</p>';
			return;
		}
		echo '<ul style="margin:0">';
		foreach ( array_slice( $uses, 0, 6 ) as $use ) {
			$d = self::describe( $use );
			if ( ! $d ) {
				continue;
			}
			echo '<li>' . ( $d['edit'] ? '<a href="' . esc_url( $d['edit'] ) . '">' . esc_html( $d['title'] ) . '</a>' : esc_html( $d['title'] ) ) . ' <span style="color:#646970">(' . esc_html( $d['type'] ) . ')</span></li>';
		}
		echo '</ul>';
		/* translators: %d: number of places. */
		echo '<p><a href="' . esc_url( self::usage_url( $post->ID ) ) . '">' . esc_html( sprintf( _n( 'View all %d place', 'View all %d places', count( $uses ), 'visigate' ), count( $uses ) ) ) . '</a></p>';
	}

	/** Reminder on the rule set edit screen that edits apply everywhere it is used. */
	public static function edit_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || Visigate_Ruleset::CPT !== $screen->post_type ) {
			return;
		}
		$post = get_post();
		$n    = $post ? self::count( $post->ID ) : 0;
		if ( $n < 2 ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			/* translators: %d: number of places. */
			esc_html( sprintf( __( 'This rule set is used in %d places. Changes you save here apply to all of them.', 'visigate' ), $n ) ),
			esc_url( self::usage_url( $post->ID ) ),
			esc_html__( 'See where', 'visigate' )
		);
	}

	/** Confirm before trashing or deleting a rule set that is in use (list table, bulk actions and edit screen). */
	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || Visigate_Ruleset::CPT !== $screen->post_type || ! in_array( $hook, array( 'edit.php', 'post.php' ), true ) ) {
			return;
		}
		$counts = array();
		foreach ( self::index() as $set_id => $uses ) {
			$counts[ $set_id ] = count( $uses );
		}
		wp_enqueue_script( 'visigate-usage', VISIGATE_URL . 'assets/js/usage.js', array( 'wp-i18n' ), VISIGATE_VERSION, true );
		wp_set_script_translations( 'visigate-usage', 'visigate' );
		wp_add_inline_script(
			'visigate-usage',
			'window.VisigateUsage = ' . wp_json_encode(
				array(
					'counts' => (object) $counts,
					'postId' => ( 'post.php' === $hook && get_post() ) ? (int) get_post()->ID : 0,
				)
			) . ';',
			'before'
		);
	}
}
