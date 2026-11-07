<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reusable, global "Rule Sets" (custom post type) + REST lookups for the builder.
 */
class Visigate_Ruleset {

	const CPT  = 'visigate_set';
	const META = '_visigate_rules';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'add_meta_boxes_' . self::CPT, array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_visigate_duplicate_set', array( __CLASS__, 'duplicate' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_cpt() {
		$cap = visigate_capability();
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Rule Sets', 'visigate' ),
					'singular_name' => __( 'Rule Set', 'visigate' ),
					'menu_name'     => visigate_brand(),
					'add_new'       => __( 'Add Rule Set', 'visigate' ),
					'add_new_item'  => __( 'Add New Rule Set', 'visigate' ),
					'edit_item'     => __( 'Edit Rule Set', 'visigate' ),
					'all_items'     => __( 'Rule Sets', 'visigate' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_rest'        => false,
				'supports'            => array( 'title' ),
				'menu_icon'           => 'dashicons-visibility',
				'menu_position'       => 58,
				'rewrite'             => false,
				'capabilities'        => array(
					'edit_post' => $cap, 'read_post' => $cap, 'delete_post' => $cap, 'edit_posts' => $cap,
					'edit_others_posts' => $cap, 'delete_posts' => $cap, 'publish_posts' => $cap,
					'read_private_posts' => $cap, 'create_posts' => $cap,
				),
			)
		);
	}

	public static function all_sets() {
		$posts = get_posts(
			array(
				'post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => 200,
				'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true,
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array( 'id' => $p->ID, 'title' => $p->post_title ? $p->post_title : '#' . $p->ID );
		}
		return $out;
	}

	/**
	 * Rule sets that exist but are not published (draft, pending, private, scheduled or in the Trash).
	 * Content that uses one is hidden, so editors list them with a warning instead of showing "None".
	 */
	public static function unpublished_sets() {
		$posts = get_posts(
			array(
				'post_type' => self::CPT, 'post_status' => array( 'draft', 'pending', 'private', 'future', 'trash' ),
				'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true,
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array( 'id' => $p->ID, 'title' => self::unpublished_label( $p ) );
		}
		return $out;
	}

	/** "Members (in Trash — content hidden)" */
	public static function unpublished_label( $post ) {
		$title  = $post->post_title ? $post->post_title : '#' . $post->ID;
		$status = 'trash' === $post->post_status ? __( 'in Trash', 'visigate' ) : __( 'not published', 'visigate' );
		/* translators: 1: rule set title, 2: "in Trash" or "not published". */
		return sprintf( __( '%1$s (%2$s — content hidden)', 'visigate' ), $title, $status );
	}

	/* ---------------------------- Meta box ---------------------------- */

	public static function add_metabox() {
		add_meta_box( 'visigate-rules', __( 'Visibility rules', 'visigate' ), array( __CLASS__, 'render_metabox' ), self::CPT, 'normal', 'high' );
	}

	public static function render_metabox( $post ) {
		$rules = get_post_meta( $post->ID, self::META, true );
		if ( ! is_array( $rules ) ) {
			$rules = array( 'action' => 'show', 'groups' => array() );
		}
		wp_nonce_field( 'visigate_save_set', 'visigate_nonce' );
		?>
		<input type="hidden" id="visigate-rules-json" name="visigate_rules_json" value="<?php echo esc_attr( wp_json_encode( $rules ) ); ?>">
		<div id="visigate-builder"></div>
		<p class="description" style="margin-top:14px">
			<?php esc_html_e( 'Use this rule set from the block editor sidebar (Visibility Rules panel), from Elementor (Advanced → Visigate), or with a shortcode:', 'visigate' ); ?>
			<?php if ( $post->ID && 'auto-draft' !== $post->post_status ) : ?>
				<code>[visigate set="<?php echo (int) $post->ID; ?>"]…[/visigate]</code>
			<?php endif; ?>
		</p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['visigate_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['visigate_nonce'] ) ), 'visigate_save_set' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( visigate_capability() ) ) {
			return;
		}
		$raw   = isset( $_POST['visigate_rules_json'] ) ? json_decode( wp_unslash( $_POST['visigate_rules_json'] ), true ) : array(); // phpcs:ignore
		update_post_meta( $post_id, self::META, Visigate_Conditions::sanitize( $raw ) );
	}

	/* ---------------------------- List table ---------------------------- */

	public static function row_actions( $actions, $post ) {
		if ( $post instanceof WP_Post && self::CPT === $post->post_type && 'auto-draft' !== $post->post_status && current_user_can( visigate_capability() ) ) {
			$actions['visigate_usage'] = sprintf( '<a href="%s">%s</a>', esc_url( Visigate_Usage::usage_url( $post->ID ) ), esc_html__( 'Where used', 'visigate' ) );
		}
		if ( self::can_duplicate( $post ) ) {
			$url = add_query_arg(
				array( 'action' => 'visigate_duplicate_set', 'post' => $post->ID ),
				admin_url( 'admin-post.php' )
			);
			$actions['visigate_duplicate'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( $url, 'visigate_duplicate_set_' . $post->ID ) ),
				esc_html__( 'Duplicate', 'visigate' )
			);
		}
		return $actions;
	}

	private static function can_duplicate( $post ) {
		return $post instanceof WP_Post
			&& self::CPT === $post->post_type
			&& ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true )
			&& current_user_can( 'edit_post', $post->ID )
			&& current_user_can( get_post_type_object( self::CPT )->cap->create_posts );
	}

	public static function duplicate() {
		$id = isset( $_GET['post'] ) && is_string( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( 'visigate_duplicate_set_' . $id );

		$source = $id ? get_post( $id ) : null;
		if ( ! self::can_duplicate( $source ) ) {
			wp_die( esc_html__( 'You cannot duplicate this rule set.', 'visigate' ), '', array( 'response' => 403 ) );
		}

		$rules   = get_post_meta( $id, self::META, true );
		$copy_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'draft',
				/* translators: %s: original rule set title. */
				'post_title'  => wp_slash( sprintf( __( '%s (Copy)', 'visigate' ), $source->post_title ) ),
			),
			true
		);
		if ( is_wp_error( $copy_id ) || ! $copy_id ) {
			wp_die( esc_html__( 'Could not create the rule set copy.', 'visigate' ), '', array( 'response' => 500 ) );
		}
		if ( is_array( $rules ) && ! add_post_meta( $copy_id, self::META, wp_slash( $rules ), true ) ) {
			wp_delete_post( $copy_id, true );
			wp_die( esc_html__( 'Could not save the copied visibility rules.', 'visigate' ), '', array( 'response' => 500 ) );
		}

		wp_safe_redirect( get_edit_post_link( $copy_id, 'raw' ) );
		exit;
	}

	public static function columns( $cols ) {
		return array(
			'cb'            => $cols['cb'],
			'title'         => $cols['title'],
			'visigate_rule'  => __( 'Rule', 'visigate' ),
			'visigate_usage' => __( 'Used in', 'visigate' ),
			'visigate_code'  => __( 'Shortcode', 'visigate' ),
			'date'          => $cols['date'],
		);
	}

	public static function column_content( $col, $post_id ) {
		if ( 'visigate_usage' === $col ) {
			Visigate_Usage::render_count( $post_id );
		}
		if ( 'visigate_code' === $col ) {
			echo '<code>[visigate set="' . (int) $post_id . '"]</code>';
		}
		if ( 'visigate_rule' === $col ) {
			$r = get_post_meta( $post_id, self::META, true );
			if ( empty( $r['groups'] ) ) {
				esc_html_e( 'No conditions (always visible)', 'visigate' );
				return;
			}
			$n = 0;
			foreach ( $r['groups'] as $g ) {
				$n += count( $g['conditions'] ?? array() );
			}
			printf(
				/* translators: 1: Show/Hide, 2: condition count, 3: group count */
				esc_html__( '%1$s — %2$d condition(s) in %3$d group(s)', 'visigate' ),
				'hide' === ( $r['action'] ?? 'show' ) ? esc_html__( 'Hide when', 'visigate' ) : esc_html__( 'Show when', 'visigate' ),
				(int) $n,
				count( $r['groups'] )
			);
		}
	}

	/* ------------------------------ REST ------------------------------ */

	public static function register_routes() {
		register_rest_route(
			'visigate/v1',
			'/search',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( visigate_capability() );
				},
				'args'                => array(
					'type' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'q'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
				'callback'            => array( __CLASS__, 'rest_search' ),
			)
		);
	}

	/** Async token-field lookups. Add-ons can provide results via `visigate_rest_search`. */
	public static function rest_search( WP_REST_Request $req ) {
		$rows = array();
		$term = trim( (string) $req['q'], " *\t\n\r\0\x0B" );
		if ( 'users' === $req['type'] && strlen( $term ) >= 2 ) {
			$users = get_users(
				array(
					'search'         => '*' . $term . '*',
					'search_columns' => array( 'display_name', 'user_login' ),
					'fields'         => array( 'ID', 'display_name' ),
					'number'         => 20,
					'orderby'        => 'display_name',
				)
			);
			foreach ( $users as $user ) {
				$rows[] = array(
					'id'    => (int) $user->ID,
					/* translators: 1: user display name, 2: user ID. */
					'label' => sprintf( __( '%1$s (#%2$d)', 'visigate' ), sanitize_text_field( $user->display_name ), $user->ID ),
				);
			}
		}
		if ( 'posts' === $req['type'] && strlen( $term ) >= 2 ) {
			$types = array_filter( get_post_types( array(), 'objects' ), 'is_post_type_viewable' );
			$posts = get_posts(
				array(
					'post_type'              => array_keys( $types ),
					'post_status'            => 'publish',
					's'                      => $term,
					'search_columns'         => array( 'post_title' ),
					'numberposts'            => 20,
					'orderby'                => 'relevance',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $posts as $post ) {
				$title  = sanitize_text_field( $post->post_title );
				$rows[] = array(
					'id'    => (int) $post->ID,
					/* translators: 1: post title, 2: singular post type label, 3: post ID. */
					'label' => sprintf(
						__( '%1$s (%2$s #%3$d)', 'visigate' ),
						'' !== $title ? $title : __( '(no title)', 'visigate' ),
						$types[ $post->post_type ]->labels->singular_name,
						$post->ID
					),
				);
			}
		}
		$rows = apply_filters( 'visigate_rest_search', $rows, $req['type'], $req['q'] );
		return rest_ensure_response( is_array( $rows ) ? array_slice( $rows, 0, 20 ) : array() );
	}
}
