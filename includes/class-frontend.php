<?php
defined( 'ABSPATH' ) || exit;

/**
 * Front-end enforcement.
 *
 * Gutenberg: rules ride on every block as attributes (`visigateSet`, `visigateRules`).
 * We short-circuit in `pre_render_block`, so hidden blocks (and all their inner
 * blocks) are never rendered at all. No front-end CSS or JS is ever loaded.
 */
class Visigate_Frontend {

	public static function init() {
		add_filter( 'pre_render_block', array( __CLASS__, 'pre_render' ), 10, 3 );
		add_filter( 'render_block_data', array( __CLASS__, 'loop_enter' ), 10, 1 );
		add_filter( 'render_block', array( __CLASS__, 'loop_leave' ), 1, 2 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt_start' ), 9, 2 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt_end' ), 11 );
		add_filter( 'render_block', array( __CLASS__, 'render_block' ), 10, 3 ); // fallback + admin preview
		add_filter( 'register_block_type_args', array( __CLASS__, 'register_attrs' ) );
		add_shortcode( 'visigate', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
	}

	/** Make sure server-registered blocks keep our attributes. */
	public static function register_attrs( $args ) {
		$args['attributes']                  = isset( $args['attributes'] ) ? $args['attributes'] : array();
		$args['attributes']['visigateSet']   = array( 'type' => 'number', 'default' => 0 );
		$args['attributes']['visigateRules'] = array( 'type' => 'object' );
		return $args;
	}

	private static function has_rules( $block ) {
		$a = $block['attrs'] ?? array();
		return ! empty( $a['visigateSet'] ) || ! empty( $a['visigateRules'] );
	}

	private static function in_editor_context() {
		// admin-ajax.php counts as admin, but themes and plugins use it to load front-end content
		// (load more, quick view, popups), so rules still apply there.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return true;
		}
		// The raw, rule-free markup is only for people who can edit content. Anyone else adding
		// ?context=edit to a REST request still gets rules applied.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_GET['context'] ) && 'edit' === $_GET['context'] && current_user_can( 'edit_posts' );
	}

	public static function preview_enabled() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return Visigate_Admin::opt( 'admin_preview' ) && isset( $_GET['visigate_preview'] ) && current_user_can( visigate_capability() );
	}

	/* --------------------------- evaluation source --------------------------- */

	/** What is being evaluated right now: kind (block|shortcode|menu_item|elementor|other), label, loop post. */
	private static $source = array();

	/** Depth of Query Loop / product template blocks being rendered. */
	private static $loop_depth = 0;

	/**
	 * Run a visibility check for one piece of content, so add-ons and the inspector know what it is.
	 *
	 * @param string   $kind      block | shortcode | menu_item | elementor | other.
	 * @param string   $label     Short human label, e.g. "Paragraph: Members only…".
	 * @param string   $key       Stable ID for this piece of content (repeat checks are merged).
	 * @param int      $loop_post Query Loop item being rendered (0 = none).
	 * @param callable $check     Returns true when the content is visible.
	 */
	public static function evaluate( $kind, $label, $key, $loop_post, $check ) {
		$previous     = self::$source;
		self::$source = array( 'kind' => (string) $kind, 'label' => (string) $label, 'key' => (string) $key, 'loop_post' => (int) $loop_post );
		$inspect      = class_exists( 'Visigate_Inspector' ) && Visigate_Inspector::recording();
		if ( $inspect ) {
			Visigate_Inspector::begin( self::$source );
		}
		try {
			$visible = (bool) $check();
		} finally {
			if ( $inspect ) {
				Visigate_Inspector::end( isset( $visible ) ? $visible : false );
			}
			self::$source = $previous;
		}
		return $visible;
	}

	/** The content being evaluated (see evaluate()), or an empty array outside an evaluation. */
	public static function current_source() {
		return self::$source;
	}

	/** Query Loop item the content being evaluated sits in (0 when it is not inside a Query Loop). */
	public static function loop_post_id() {
		return (int) ( self::$source['loop_post'] ?? 0 );
	}

	/** Blocks that render their inner blocks once per post of a query. */
	private static function loop_blocks() {
		return (array) apply_filters( 'visigate_loop_template_blocks', array( 'core/post-template', 'woocommerce/product-template' ) );
	}

	/** @param array $block Parsed block about to render. */
	public static function loop_enter( $block ) {
		if ( isset( $block['blockName'] ) && in_array( $block['blockName'], self::loop_blocks(), true ) ) {
			++self::$loop_depth;
		}
		return $block;
	}

	/**
	 * @param string $content Rendered block.
	 * @param array  $block   Parsed block.
	 */
	public static function loop_leave( $content, $block ) {
		if ( self::$loop_depth > 0 && isset( $block['blockName'] ) && in_array( $block['blockName'], self::loop_blocks(), true ) ) {
			--self::$loop_depth;
		}
		return $content;
	}

	/**
	 * Post ID a block instance is rendered for inside a Query Loop, from block context.
	 *
	 * @param WP_Block|null $wp_block The block itself or its parent.
	 */
	private static function context_post( $wp_block ) {
		if ( self::$loop_depth < 1 || ! $wp_block instanceof WP_Block ) {
			return 0;
		}
		foreach ( array( 'context', 'available_context' ) as $prop ) {
			$ctx = isset( $wp_block->$prop ) && is_array( $wp_block->$prop ) ? $wp_block->$prop : array();
			if ( ! empty( $ctx['postId'] ) ) {
				return (int) $ctx['postId'];
			}
		}
		return 0;
	}

	/** "Paragraph: Members get 20% off…" */
	private static function block_label( $block ) {
		$name = (string) ( $block['blockName'] ?? '' );
		$type = $name && class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance()->get_registered( $name ) : null;
		$label = $type && ! empty( $type->title ) ? $type->title : ( $name ? $name : __( 'Block', 'visigate' ) );
		if ( ! empty( $block['attrs']['metadata']['name'] ) && is_string( $block['attrs']['metadata']['name'] ) ) {
			return $label . ': ' . $block['attrs']['metadata']['name'];
		}
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( self::static_html( $block ) ) ) );
		if ( '' !== $text ) {
			$label .= ': ' . ( function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $text, 0, 48, '…' ) : substr( $text, 0, 48 ) );
		}
		return $label;
	}

	/** Saved HTML of a block and its inner blocks, without rendering anything. */
	private static function static_html( $block, $depth = 0 ) {
		$html = (string) ( $block['innerHTML'] ?? '' );
		if ( $depth < 4 && strlen( wp_strip_all_tags( $html ) ) < 48 ) {
			foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner ) {
				$html .= ' ' . self::static_html( $inner, $depth + 1 );
			}
		}
		return $html;
	}

	private static function block_visible( $block, $loop_post = 0 ) {
		$inspect = class_exists( 'Visigate_Inspector' ) && Visigate_Inspector::recording();
		return self::evaluate(
			'block',
			$inspect ? self::block_label( $block ) : '',
			$inspect ? self::block_hash( $block ) . ':' . (int) $loop_post : '',
			$loop_post,
			function () use ( $block ) {
				$a = $block['attrs'] ?? array();
				if ( ! empty( $a['visigateSet'] ) && ! Visigate_Engine::set_visible( (int) $a['visigateSet'] ) ) {
					return false;
				}
				if ( ! empty( $a['visigateRules'] ) && is_array( $a['visigateRules'] ) && ! Visigate_Engine::visible( $a['visigateRules'] ) ) {
					return false;
				}
				return true;
			}
		);
	}

	/**
	 * @param string|null   $pre          Short-circuit output.
	 * @param array         $block        Parsed block.
	 * @param WP_Block|null $parent_block Parent block instance (WordPress 5.9+), which carries Query Loop context.
	 */
	public static function pre_render( $pre, $block, $parent_block = null ) {
		// Inside an auto-generated excerpt, blocks nested in a hidden Group/Columns are rendered on
		// their own (without their hidden parent), so drop them here.
		if ( null === $pre && self::$excerpt_hidden && isset( self::$excerpt_hidden[ self::block_hash( $block ) ] ) ) {
			return '';
		}
		if ( null !== $pre || ! self::has_rules( $block ) || self::in_editor_context() || self::preview_enabled() ) {
			return $pre;
		}
		$loop_post = self::context_post( $parent_block );
		return self::block_visible( $block, $loop_post ) ? $pre : self::hidden_block_output( $block, $loop_post );
	}

	/** Hashes of blocks nested inside hidden blocks, while an excerpt is being generated. */
	private static $excerpt_hidden = null;

	private static function block_hash( $block ) {
		return md5( wp_json_encode( $block ) );
	}

	/**
	 * Auto-generated excerpts (archives, feeds, SEO descriptions) render the inner blocks of
	 * Group and Columns blocks directly, skipping the wrapper, so a hidden wrapper would leak its
	 * text. Before WordPress builds the excerpt, note every block that sits inside a hidden block.
	 *
	 * @param string           $text Manual excerpt, or '' when WordPress will generate one.
	 * @param WP_Post|int|null $post Post the excerpt is for.
	 */
	public static function excerpt_start( $text, $post = null ) {
		self::$excerpt_hidden = null;
		if ( '' !== trim( (string) $text ) || self::in_editor_context() || self::preview_enabled() ) {
			return $text;
		}
		$post = get_post( $post );
		if ( ! $post || false === strpos( (string) $post->post_content, 'visigate' ) || ! has_blocks( $post->post_content ) ) {
			return $text;
		}
		$hidden  = array();
		$collect = function () use ( $post, &$hidden ) {
			self::collect_hidden( parse_blocks( $post->post_content ), false, $hidden );
		};
		// Excerpt pre-checks repeat the real checks; keep them out of the inspector panel.
		if ( class_exists( 'Visigate_Inspector' ) && Visigate_Inspector::recording() ) {
			Visigate_Inspector::quietly( $collect );
		} else {
			$collect();
		}
		self::$excerpt_hidden = $hidden ? $hidden : null;
		return $text;
	}

	/** Excerpt generated: stop dropping nested blocks. */
	public static function excerpt_end( $text ) {
		self::$excerpt_hidden = null;
		return $text;
	}

	private static function collect_hidden( $blocks, $under_hidden, &$hidden ) {
		foreach ( (array) $blocks as $block ) {
			if ( $under_hidden ) {
				$hidden[ self::block_hash( $block ) ] = true;
			}
			$is_hidden = $under_hidden || ( self::has_rules( $block ) && ! self::block_visible( $block ) );
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_hidden( $block['innerBlocks'], $is_hidden, $hidden );
			}
		}
	}

	/**
	 * What a hidden block renders as. Empty by default; add-ons can return replacement ("fallback") HTML.
	 *
	 * @param array $block Parsed block.
	 */
	private static function hidden_block_output( $block, $loop_post = 0 ) {
		$inspect = class_exists( 'Visigate_Inspector' ) && Visigate_Inspector::recording();
		$filter  = function () use ( $block ) {
			return apply_filters( 'visigate_hidden_block_output', '', $block );
		};
		// Add-ons re-check rules to pick a fallback; those checks are not shown separately.
		$out = $inspect ? Visigate_Inspector::quietly( $filter ) : $filter();
		$out = is_string( $out ) ? $out : '';
		if ( $inspect ) {
			$key = self::block_hash( $block ) . ':' . (int) $loop_post;
			Visigate_Inspector::note_fallback( $key, '' !== trim( $out ) );
			$marker = Visigate_Inspector::marker( $key );
			$out    = ( '' !== $marker ? $marker : Visigate_Inspector::anchor( $key ) ) . $out;
		}
		return $out;
	}

	/**
	 * What a hidden [visigate] shortcode renders as. Empty by default.
	 *
	 * @param array $atts Raw shortcode attributes (including any unknown ones, e.g. from add-ons).
	 */
	private static function hidden_shortcode_output( $atts ) {
		$inspect = class_exists( 'Visigate_Inspector' ) && Visigate_Inspector::recording();
		$filter  = function () use ( $atts ) {
			return apply_filters( 'visigate_hidden_shortcode_output', '', (array) $atts );
		};
		$out = $inspect ? Visigate_Inspector::quietly( $filter ) : $filter();
		$out = is_string( $out ) ? $out : '';
		if ( $inspect ) {
			$key = 'shortcode:' . md5( wp_json_encode( $atts ) );
			Visigate_Inspector::note_fallback( $key, '' !== trim( $out ) );
			$marker = Visigate_Inspector::marker( $key );
			$out    = ( '' !== $marker ? $marker : Visigate_Inspector::anchor( $key ) ) . $out;
		}
		return $out;
	}

	/**
	 * Admin preview: show hidden content faded and outlined in red instead of removing it.
	 *
	 * @param string $html Content that is hidden from the current visitor.
	 * @param string $key  Optional inspector entry key.
	 */
	private static function preview_wrap( $html, $key = '' ) {
		$attrs = ( '' !== $key && class_exists( 'Visigate_Inspector' ) && Visigate_Inspector::recording() )
			? Visigate_Inspector::preview_attrs( $key )
			: 'title="' . esc_attr__( 'Hidden from this visitor by Visigate', 'visigate' ) . '"';
		return '<div class="visigate-preview-hidden" ' . $attrs . ' style="outline:2px dashed #d63638;outline-offset:2px;opacity:.5">' . $html . '</div>';
	}

	/**
	 * Output for a [visigate] shortcode whose content is hidden: the fallback, or the
	 * revealed content while an administrator is using admin preview.
	 *
	 * @param array  $atts    Raw shortcode attributes.
	 * @param string $content Shortcode content.
	 */
	private static function hidden_shortcode( $atts, $content ) {
		$key = 'shortcode:' . md5( wp_json_encode( $atts ) );
		if ( self::preview_enabled() ) {
			return self::preview_wrap( do_shortcode( (string) $content ), $key );
		}
		return self::hidden_shortcode_output( $atts );
	}

	/**
	 * @param string        $content  Rendered block.
	 * @param array         $block    Parsed block.
	 * @param WP_Block|null $instance Block instance (WordPress 5.9+).
	 */
	public static function render_block( $content, $block, $instance = null ) {
		if ( ! self::has_rules( $block ) || self::in_editor_context() ) {
			return $content;
		}
		$loop_post = self::context_post( $instance );
		$visible   = self::block_visible( $block, $loop_post );
		$key       = self::block_hash( $block ) . ':' . (int) $loop_post;
		$anchor    = ( $visible && class_exists( 'Visigate_Inspector' ) && Visigate_Inspector::recording() ) ? Visigate_Inspector::anchor( $key ) : '';
		if ( self::preview_enabled() ) {
			return $visible ? $anchor . $content : self::preview_wrap( $content, $key );
		}
		return $visible ? $anchor . $content : self::hidden_block_output( $block, $loop_post );
	}

	/** [visigate set="12" logged_in="yes" roles="customer,subscriber" device="mobile" bought="45,46" bought_days="30" hide="no"]…[/visigate] */
	public static function shortcode( $atts, $content = '' ) {
		$key     = 'shortcode:' . md5( wp_json_encode( $atts ) );
		$visible = self::evaluate(
			'shortcode',
			__( '[visigate] shortcode', 'visigate' ),
			$key,
			0,
			function () use ( $atts ) {
				return self::shortcode_visible( $atts );
			}
		);
		if ( ! $visible ) {
			return self::hidden_shortcode( $atts, $content );
		}
		$anchor = ( class_exists( 'Visigate_Inspector' ) && Visigate_Inspector::recording() ) ? Visigate_Inspector::anchor( $key ) : '';
		return $anchor . do_shortcode( $content );
	}

	/** @param array|string $atts Raw shortcode attributes. */
	private static function shortcode_visible( $atts ) {
		$a = shortcode_atts(
			array( 'set' => '', 'logged_in' => '', 'roles' => '', 'device' => '', 'bought' => '', 'bought_days' => '0', 'hide' => 'no' ),
			$atts,
			'visigate'
		);

		if ( '' !== $a['set'] && ! Visigate_Engine::set_visible( (int) $a['set'] ) ) {
			return false;
		}

		$csv   = function ( $s ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', (string) $s ) ) ) );
		};
		$conds = array();
		if ( '' !== $a['logged_in'] ) {
			$conds[] = array( 'type' => 'logged_in', 'op' => in_array( strtolower( $a['logged_in'] ), array( 'yes', '1', 'true' ), true ) ? 'is' : 'is_not', 'value' => null );
		}
		if ( $csv( $a['roles'] ) ) {
			$conds[] = array( 'type' => 'user_role', 'op' => 'is_any', 'value' => $csv( $a['roles'] ) );
		}
		if ( $csv( $a['device'] ) ) {
			$conds[] = array( 'type' => 'device', 'op' => 'is_any', 'value' => $csv( $a['device'] ) );
		}
		if ( $csv( $a['bought'] ) ) {
			$conds[] = array( 'type' => 'woo_bought_product', 'op' => 'bought', 'value' => array_map( 'absint', $csv( $a['bought'] ) ), 'days' => (int) $a['bought_days'] );
		}
		if ( $conds ) {
			$hide  = in_array( strtolower( trim( (string) $a['hide'] ) ), array( 'yes', '1', 'true' ), true );
			$rules = array( 'action' => $hide ? 'hide' : 'show', 'groups' => array( array( 'conditions' => $conds ) ) );
			if ( ! Visigate_Engine::visible( $rules ) ) {
				return false;
			}
		}
		return true;
	}

	/** Admin-bar toggle: preview what is being hidden on the current page. */
	public static function admin_bar( $bar ) {
		if ( is_admin() || ! current_user_can( visigate_capability() ) || ! Visigate_Admin::opt( 'admin_preview' ) ) {
			return;
		}
		$on = self::preview_enabled();
		$bar->add_node(
			array(
				'id'    => 'visigate-preview',
				/* translators: %s: plugin name */
				'title' => sprintf( $on ? __( '%s: hide hidden content', 'visigate' ) : __( '%s: reveal hidden content', 'visigate' ), visigate_brand() ),
				'href'  => $on ? remove_query_arg( 'visigate_preview' ) : add_query_arg( 'visigate_preview', '1' ),
			)
		);
	}
}
