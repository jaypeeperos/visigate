<?php
defined( 'ABSPATH' ) || exit;

/**
 * Menu item visibility for classic menus (Appearance → Menus).
 *
 * Each menu item can use a saved rule set or custom conditions. Hidden items are removed on the server,
 * together with their child items, before the menu is printed. Navigation block links use the
 * normal block Visibility Rules panel instead.
 *
 * Add-ons can show a replacement item instead with the `visigate_hidden_menu_item` filter.
 */
class Visigate_Menus {

	const META = '_visigate_set';
	const RULES_META = '_visigate_rules';

	public static function init() {
		add_action( 'wp_nav_menu_item_custom_fields', array( __CLASS__, 'render_fields' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'filter_items' ), 20 );
		add_filter( 'nav_menu_item_title', array( __CLASS__, 'preview_title' ), 10, 2 );
	}

	public static function get_set( $item_id ) {
		return (int) get_post_meta( (int) $item_id, self::META, true );
	}

	public static function get_rules( $item_id ) {
		$rules = get_post_meta( (int) $item_id, self::RULES_META, true );
		return is_array( $rules ) ? $rules : array();
	}

	/* ------------------------------- admin ------------------------------- */

	public static function render_fields( $item_id, $item ) {
		$current = self::get_set( $item_id );
		$rules   = self::get_rules( $item_id );
		$custom  = ! $current && ! empty( $rules );
		$brand   = visigate_brand();
		if ( ! current_user_can( visigate_capability() ) ) {
			echo '<div class="visigate-menu-fields description description-wide" style="margin:8px 0;padding:8px 10px;background:#f6f7f7;border-left:3px solid #2271b1"><p style="margin:0">' . esc_html(
				( $current || $rules )
					/* translators: %s: plugin name. */
					? sprintf( __( '%s: this item has visibility rules managed by your administrator.', 'visigate' ), $brand )
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
		$mode  = $custom ? 'custom' : ( $current ? 'set' : 'always' );
		$edit  = admin_url( 'post.php?action=edit&post=' );
		echo '<div class="visigate-menu-fields description description-wide" data-mode="' . esc_attr( $mode ) . '" data-edit-url="' . esc_url( $edit ) . '">';
		?>
		<div class="visigate-menu-head">
			<label for="<?php echo esc_attr( $field ); ?>" class="visigate-menu-label"><?php esc_html_e( 'Visibility', 'visigate' ); ?></label>
			<span class="visigate-menu-brand"><?php echo esc_html( $brand ); ?></span>
		</div>
		<select id="<?php echo esc_attr( $field ); ?>" class="widefat visigate-menu-mode" name="visigate_menu_set[<?php echo (int) $item_id; ?>]">
			<option value="0"><?php esc_html_e( 'Always show', 'visigate' ); ?></option>
			<option value="-1" <?php selected( $custom ); ?>><?php esc_html_e( 'Custom conditions', 'visigate' ); ?></option>
			<?php if ( $sets ) : ?>
				<optgroup label="<?php esc_attr_e( 'Saved rule sets', 'visigate' ); ?>">
					<?php foreach ( $sets as $set ) : ?>
						<option value="<?php echo (int) $set['id']; ?>" <?php selected( $current, (int) $set['id'] ); ?>><?php echo esc_html( $set['title'] ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endif; ?>
		</select>
		<div class="visigate-menu-custom" <?php echo $custom ? '' : 'hidden'; ?>>
			<input type="hidden" class="visigate-menu-rules-json" name="visigate_menu_rules[<?php echo (int) $item_id; ?>]" value="<?php echo esc_attr( wp_json_encode( $rules ) ); ?>">
			<div class="visigate-menu-builder"></div>
		</div>
		<p class="visigate-menu-help" data-for="always" <?php echo 'always' === $mode ? '' : 'hidden'; ?>>
			<?php esc_html_e( 'Everyone sees this item. Pick custom conditions or a saved rule set to limit who sees it.', 'visigate' ); ?>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Visigate_Ruleset::CPT ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Create a rule set', 'visigate' ); ?></a>
		</p>
		<p class="visigate-menu-help" data-for="custom" <?php echo 'custom' === $mode ? '' : 'hidden'; ?>>
			<?php esc_html_e( 'Applies to this item only. Sub-items are hidden with it.', 'visigate' ); ?>
		</p>
		<p class="visigate-menu-help" data-for="set" <?php echo 'set' === $mode ? '' : 'hidden'; ?>>
			<?php esc_html_e( 'Uses the saved rule set, so changes to it apply everywhere it is used. Sub-items are hidden with it.', 'visigate' ); ?>
			<a class="visigate-menu-edit-set" href="<?php echo esc_url( $current ? $edit . $current : $edit ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit rule set', 'visigate' ); ?></a>
		</p>
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
		$selection = wp_unslash( $_POST['visigate_menu_set'][ $item_id ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Scalar-checked and cast with absint() below.
		if ( ! is_scalar( $selection ) ) {
			return;
		}
		$set = absint( $selection );
		if ( '-1' === (string) $selection ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; the JSON is decoded and whitelist-sanitized by Visigate_Conditions::sanitize() below.
			$json = isset( $_POST['visigate_menu_rules'][ $item_id ] ) ? wp_unslash( $_POST['visigate_menu_rules'][ $item_id ] ) : null;
			$rules = is_string( $json ) ? json_decode( $json, true ) : null;
			if ( ! is_array( $rules ) || ! isset( $rules['groups'] ) || ! is_array( $rules['groups'] ) ) {
				return; // A missing or malformed builder payload must not erase saved visibility.
			}
			// update_post_meta() unslashes its value, so slash it to keep backslashes in cookie or URL values.
			update_post_meta( $item_id, self::RULES_META, wp_slash( Visigate_Conditions::sanitize( $rules ) ) );
			$set = 0;
		} else {
			delete_post_meta( $item_id, self::RULES_META );
		}
		if ( $set ) {
			update_post_meta( $item_id, self::META, $set );
		} else {
			delete_post_meta( $item_id, self::META );
		}
		do_action( 'visigate_menu_item_save', $item_id, $set );
	}

	/* ------------------------------ front end ------------------------------ */

	private static function front_end() {
		// The Menus screen must see every item. admin-ajax.php is used for front-end content too.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		// The block editor reads menus over REST as someone who can edit them. Public REST
		// endpoints (headless themes, menu API plugins) still get rules applied.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_theme_options' ) ) {
			return false;
		}
		return true;
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
			$rules = isset( $item->ID ) ? self::get_rules( $item->ID ) : array();
			if ( $set ? Visigate_Engine::set_visible( $set ) : Visigate_Engine::visible( $rules ) ) {
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
