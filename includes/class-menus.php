<?php
defined( 'ABSPATH' ) || exit;

/**
 * Menu item visibility for classic menus (Appearance → Menus).
 *
 * Each menu item can use a saved rule set. Items the rule set hides are removed on the server,
 * together with their child items, before the menu is printed. Navigation block links use the
 * normal block Visibility Rules panel instead.
 *
 * Add-ons can show a replacement item instead with the `visigate_hidden_menu_item` filter.
 */
class Visigate_Menus {

	const META = '_visigate_set';

	public static function init() {
		add_action( 'wp_nav_menu_item_custom_fields', array( __CLASS__, 'render_fields' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'filter_items' ), 20 );
		add_filter( 'nav_menu_item_title', array( __CLASS__, 'preview_title' ), 10, 2 );
	}

	public static function get_set( $item_id ) {
		return (int) get_post_meta( (int) $item_id, self::META, true );
	}

	/* ------------------------------- admin ------------------------------- */

	public static function render_fields( $item_id, $item ) {
		$current = self::get_set( $item_id );
		$brand   = visigate_brand();
		echo '<div class="visigate-menu-fields description description-wide" style="margin:8px 0;padding:8px 10px;background:#f6f7f7;border-left:3px solid #2271b1">';
		if ( ! current_user_can( visigate_capability() ) ) {
			echo '<p style="margin:0">' . esc_html(
				$current
					/* translators: %s: plugin name. */
					? sprintf( __( '%s: this item uses a visibility rule set managed by your administrator.', 'visigate' ), $brand )
					/* translators: %s: plugin name. */
					: sprintf( __( '%s: visibility rules are managed by your administrator.', 'visigate' ), $brand )
			) . '</p></div>';
			return;
		}
		$sets = Visigate_Ruleset::all_sets();
		$ids  = wp_list_pluck( $sets, 'id' );
		if ( $current && ! in_array( $current, $ids, true ) ) {
			$p      = get_post( $current );
			$sets[] = array(
				'id'    => $current,
				/* translators: %d: rule set ID. */
				'title' => ( $p && $p->post_title ? $p->post_title : sprintf( __( 'Rule set #%d', 'visigate' ), $current ) ) . ' — ' . __( 'not published, item hidden', 'visigate' ),
			);
		}
		$field = 'visigate-menu-set-' . (int) $item_id;
		?>
		<label for="<?php echo esc_attr( $field ); ?>">
			<?php /* translators: %s: plugin name. */ echo esc_html( sprintf( __( '%s: visibility', 'visigate' ), $brand ) ); ?><br>
			<select id="<?php echo esc_attr( $field ); ?>" class="widefat" name="visigate_menu_set[<?php echo (int) $item_id; ?>]">
				<option value="0"><?php esc_html_e( 'Always show (no rule set)', 'visigate' ); ?></option>
				<?php foreach ( $sets as $set ) : ?>
					<option value="<?php echo (int) $set['id']; ?>" <?php selected( $current, (int) $set['id'] ); ?>><?php echo esc_html( $set['title'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<span class="description" style="display:block;margin-top:4px"><?php esc_html_e( 'The item shows only when this rule set allows it. Its sub-items are hidden with it.', 'visigate' ); ?>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Visigate_Ruleset::CPT ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Create a rule set', 'visigate' ); ?></a></span>
		<?php
		do_action( 'visigate_menu_item_fields', $item_id, $item, $current );
		echo '</div>';
	}

	public static function save( $menu_id, $item_id ) {
		// Only the Menus screen sends this field; the Customizer and REST API leave the saved value alone.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below against the Menus screen nonce.
		if ( ! isset( $_POST['visigate_menu_set'] ) || ! is_array( $_POST['visigate_menu_set'] ) || ! array_key_exists( $item_id, $_POST['visigate_menu_set'] ) ) {
			return;
		}
		$nonce = isset( $_POST['update-nav-menu-nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['update-nav-menu-nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'update-nav_menu' ) || ! current_user_can( visigate_capability() ) || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$set = absint( wp_unslash( $_POST['visigate_menu_set'][ $item_id ] ) );
		if ( $set ) {
			update_post_meta( $item_id, self::META, $set );
		} else {
			delete_post_meta( $item_id, self::META );
		}
		do_action( 'visigate_menu_item_save', $item_id, $set );
	}

	/* ------------------------------ front end ------------------------------ */

	private static function front_end() {
		// The Menus screen and the REST API (block editor) must see every item.
		return ! ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) );
	}

	/** Remove hidden menu items (and their children) before the menu is built. */
	public static function filter_items( $items ) {
		if ( ! is_array( $items ) || ! $items || ! self::front_end() ) {
			return $items;
		}
		$preview = Visigate_Frontend::preview_enabled();
		$drop    = array();
		$out     = array();

		foreach ( $items as $i => $item ) {
			$set = isset( $item->ID ) ? self::get_set( $item->ID ) : 0;
			if ( ! $set || Visigate_Engine::set_visible( $set ) ) {
				continue;
			}
			if ( $preview ) {
				$items[ $i ]->visigate_hidden        = true;
				$items[ $i ]->visigate_drop_children = true;
				continue;
			}
			$replacement = apply_filters( 'visigate_hidden_menu_item', null, $item, $set );
			if ( is_object( $replacement ) ) {
				$items[ $i ] = $replacement;
			} else {
				$drop[ (int) $item->ID ] = true;
			}
			// Sub-items of a hidden or replaced item are removed either way.
			$items[ $i ]->visigate_drop_children = true;
		}

		// Hide descendants of hidden or replaced items.
		$parents = array();
		foreach ( $items as $item ) {
			$parents[ (int) $item->ID ] = (int) $item->menu_item_parent;
		}
		$cut = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item->visigate_drop_children ) ) {
				$cut[ (int) $item->ID ] = true;
			}
		}
		foreach ( $items as $item ) {
			$id = (int) $item->ID;
			if ( isset( $drop[ $id ] ) ) {
				continue;
			}
			$p     = $parents[ $id ] ?? 0;
			$guard = 0;
			$under = false;
			while ( $p && $guard++ < 50 ) {
				if ( isset( $cut[ $p ] ) ) {
					$under = true;
					break;
				}
				$p = $parents[ $p ] ?? 0;
			}
			if ( $under ) {
				if ( ! $preview ) {
					continue;
				}
				$item->visigate_hidden = true;
			}
			$out[] = $item;
		}
		return $out;
	}

	/** Admin preview: outline hidden menu items instead of removing them. */
	public static function preview_title( $title, $item ) {
		if ( empty( $item->visigate_hidden ) ) {
			return $title;
		}
		return '<span class="visigate-preview-hidden" title="' . esc_attr__( 'Hidden from this visitor by Visigate', 'visigate' ) . '" style="outline:2px dashed #d63638;outline-offset:2px;opacity:.5">' . $title . '</span>';
	}
}
