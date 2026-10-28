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
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_cpt() {
		$cap = 'manage_options';
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Rule Sets', 'visigate' ),
					'singular_name' => __( 'Rule Set', 'visigate' ),
					'menu_name'     => __( 'Visigate', 'visigate' ),
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
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$raw   = isset( $_POST['visigate_rules_json'] ) ? json_decode( wp_unslash( $_POST['visigate_rules_json'] ), true ) : array(); // phpcs:ignore
		update_post_meta( $post_id, self::META, Visigate_Conditions::sanitize( $raw ) );
	}

	/* ---------------------------- List table ---------------------------- */

	public static function columns( $cols ) {
		return array(
			'cb'            => $cols['cb'],
			'title'         => $cols['title'],
			'visigate_rule' => __( 'Rule', 'visigate' ),
			'visigate_code' => __( 'Shortcode', 'visigate' ),
			'date'          => $cols['date'],
		);
	}

	public static function column_content( $col, $post_id ) {
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
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'type' => array( 'type' => 'string', 'enum' => array( 'product', 'product_cat' ), 'required' => true ),
					'q'    => array( 'type' => 'string', 'default' => '' ),
				),
				'callback'            => array( __CLASS__, 'rest_search' ),
			)
		);
	}

	public static function rest_search( WP_REST_Request $req ) {
		$q    = sanitize_text_field( $req['q'] );
		$rows = array();

		if ( 'product' === $req['type'] ) {
			$args = array(
				'post_type' => 'product', 'post_status' => array( 'publish', 'private' ),
				'posts_per_page' => 20, 'no_found_rows' => true, 's' => $q,
			);
			if ( ctype_digit( $q ) ) {
				$args['p'] = (int) $q;
				unset( $args['s'] );
			}
			foreach ( get_posts( $args ) as $p ) {
				$rows[] = array( 'id' => $p->ID, 'label' => sprintf( '%s (#%d)', wp_strip_all_tags( $p->post_title ), $p->ID ) );
			}
		} else {
			$terms = get_terms( array( 'taxonomy' => 'product_cat', 'search' => $q, 'number' => 20, 'hide_empty' => false ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $t ) {
					$rows[] = array( 'id' => $t->term_id, 'label' => sprintf( '%s (#%d)', $t->name, $t->term_id ) );
				}
			}
		}
		return rest_ensure_response( $rows );
	}
}
