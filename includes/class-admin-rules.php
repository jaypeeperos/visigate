<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rule sets screen (Visigate → Rule sets): Visigate's own list of rule sets with status filters,
 * plain-language summaries, condition chips, usage counts, an Always hide switch per row, bulk
 * actions and paging.
 *
 * It replaces the default WordPress posts list for the rule set post type. That list stays
 * reachable for the Trash (post_status=trash), searches and core actions such as undoing a trash.
 */
class Visigate_Admin_Rules {

	const SLUG     = 'visigate-rules';
	const PER_PAGE = 20;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'remove_core_item' ), 1000 );
		add_action( 'load-edit.php', array( __CLASS__, 'redirect_core_list' ) );
		add_action( 'admin_post_visigate_toggle_hide', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'admin_post_visigate_bulk_sets', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_visigate_trash', array( __CLASS__, 'handle_trash' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => Visigate_Ruleset::CPT, 'page' => self::SLUG ), $args ), admin_url( 'edit.php' ) );
	}

	public static function menu() {
		add_submenu_page(
			Visigate_Admin_UI::parent_slug(),
			__( 'Rule Sets', 'visigate' ),
			__( 'Rule Sets', 'visigate' ),
			visigate_capability(),
			self::SLUG,
			array( __CLASS__, 'render' ),
			1
		);
	}

	/** The core "Rule Sets" item would now duplicate ours. */
	public static function remove_core_item() {
		remove_submenu_page( Visigate_Admin_UI::parent_slug(), Visigate_Admin_UI::parent_slug() );
	}

	/**
	 * Send the plain WordPress list to this screen. Requests that need the core list (Trash, search,
	 * bulk or undo actions, filters) stay where they are; trash and restore messages come along.
	 */
	public static function redirect_core_list() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['post_type'] ) || Visigate_Ruleset::CPT !== $_GET['post_type'] || isset( $_GET['page'] ) ) {
			return;
		}
		// The core Trash view (nothing else asked for) opens Visigate's Trash.
		if ( isset( $_GET['post_status'] ) && 'trash' === $_GET['post_status'] && ! array_intersect( array_keys( $_GET ), array( 's', 'action', 'action2', 'doaction', 'author', 'm', 'paged', 'orderby' ) ) ) {
			$keep = array( 'status' => 'trash' );
			foreach ( array( 'untrashed', 'deleted' ) as $key ) {
				if ( isset( $_GET[ $key ] ) ) {
					$keep[ $key ] = absint( $_GET[ $key ] );
				}
			}
			wp_safe_redirect( self::url( $keep ) );
			exit;
		}
		foreach ( array( 'post_status', 's', 'action', 'action2', 'doaction', 'author', 'm', 'orderby', 'paged', 'mode' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] && '-1' !== $_GET[ $key ] ) {
				return;
			}
		}
		$keep = array();
		foreach ( array( 'trashed', 'untrashed', 'deleted', 'ids' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$keep[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
			}
		}
		// phpcs:enable
		wp_safe_redirect( self::url( $keep ) );
		exit;
	}

	/* ------------------------------ actions ------------------------------ */

	private static function set_hidden( $id, $hide ) {
		$post = get_post( $id );
		if ( ! $post || Visigate_Ruleset::CPT !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}
		$rules = get_post_meta( $id, Visigate_Ruleset::META, true );
		$rules = is_array( $rules ) ? $rules : array( 'action' => 'show', 'groups' => array() );
		if ( $hide ) {
			$rules['always_hide'] = true;
		} else {
			unset( $rules['always_hide'] );
		}
		update_post_meta( $id, Visigate_Ruleset::META, wp_slash( Visigate_Conditions::sanitize( $rules ) ) );
		Visigate_Cache_Check::flush_issues();
		return true;
	}

	public static function toggle_url( $id, $hide ) {
		return wp_nonce_url(
			add_query_arg( array( 'action' => 'visigate_toggle_hide', 'set' => (int) $id, 'hide' => $hide ? 1 : 0 ), admin_url( 'admin-post.php' ) ),
			'visigate_toggle_hide_' . (int) $id
		);
	}

	public static function handle_toggle() {
		$id = isset( $_GET['set'] ) ? absint( $_GET['set'] ) : 0;
		check_admin_referer( 'visigate_toggle_hide_' . $id );
		if ( ! current_user_can( visigate_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'visigate' ), '', array( 'response' => 403 ) );
		}
		$hide = ! empty( $_GET['hide'] );
		self::set_hidden( $id, $hide );
		$back = wp_get_referer();
		$back = $back ? remove_query_arg( array( 'trashed', 'untrashed', 'deleted', 'ids', 'vg_done', 'vg_n' ), $back ) : self::url();
		wp_safe_redirect( add_query_arg( array( 'vg_done' => $hide ? 'hidden' : 'shown', 'vg_n' => 1 ), $back ) );
		exit;
	}

	public static function handle_bulk() {
		check_admin_referer( 'visigate_bulk_sets' );
		if ( ! current_user_can( visigate_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'visigate' ), '', array( 'response' => 403 ) );
		}
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$ids  = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		$back = wp_validate_redirect( $back, self::url() );
		$back = remove_query_arg( array( 'trashed', 'untrashed', 'deleted', 'ids', 'vg_done', 'vg_n' ), $back );
		// Add-on bulk actions (Visigate Pro: Export to file) handle the request themselves, e.g. a download.
		if ( $ids && array_key_exists( $op, (array) apply_filters( 'visigate_rules_bulk_actions', array() ) ) ) {
			do_action( 'visigate_rules_bulk_action', $op, $ids, $back );
		}
		if ( ! $ids || ! in_array( $op, array( 'hide', 'show', 'trash' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'vg_done', 'none', $back ) );
			exit;
		}
		$done = array();
		foreach ( $ids as $id ) {
			if ( 'trash' === $op ) {
				$post = get_post( $id );
				if ( $post && Visigate_Ruleset::CPT === $post->post_type && current_user_can( 'delete_post', $id ) && wp_trash_post( $id ) ) {
					$done[] = $id;
				}
			} elseif ( self::set_hidden( $id, 'hide' === $op ) ) {
				$done[] = $id;
			}
		}
		if ( 'trash' === $op ) {
			wp_safe_redirect( add_query_arg( array( 'trashed' => count( $done ), 'ids' => implode( ',', $done ) ), $back ) );
		} else {
			wp_safe_redirect( add_query_arg( array( 'vg_done' => 'hide' === $op ? 'hidden' : 'shown', 'vg_n' => count( $done ) ), $back ) );
		}
		exit;
	}

	/* ------------------------------ screen ------------------------------ */

	private static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$n = function ( $key ) {
			return isset( $_GET[ $key ] ) ? absint( $_GET[ $key ] ) : 0;
		};
		if ( $n( 'trashed' ) ) {
			$ids  = isset( $_GET['ids'] ) ? implode( ',', array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) ) : '';
			$undo = wp_nonce_url( admin_url( 'edit.php?post_type=' . Visigate_Ruleset::CPT . '&doaction=undo&action=untrash&ids=' . $ids ), 'bulk-posts' );
			/* translators: %d: number of rule sets. */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d rule set moved to the Trash.', '%d rule sets moved to the Trash.', $n( 'trashed' ), 'visigate' ), $n( 'trashed' ) ) ) . ' <a href="' . esc_url( $undo ) . '">' . esc_html__( 'Undo', 'visigate' ) . '</a></p></div>';
		}
		if ( $n( 'untrashed' ) ) {
			/* translators: %d: number of rule sets. */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d rule set restored from the Trash.', '%d rule sets restored from the Trash.', $n( 'untrashed' ), 'visigate' ), $n( 'untrashed' ) ) ) . '</p></div>';
		}
		if ( $n( 'deleted' ) ) {
			/* translators: %d: number of rule sets. */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d rule set permanently deleted.', '%d rule sets permanently deleted.', $n( 'deleted' ), 'visigate' ), $n( 'deleted' ) ) ) . '</p></div>';
		}
		$done = isset( $_GET['vg_done'] ) ? sanitize_key( wp_unslash( $_GET['vg_done'] ) ) : '';
		if ( 'hidden' === $done || 'shown' === $done ) {
			$msg = 'hidden' === $done
				/* translators: %d: number of rule sets. */
				? _n( 'Always hide turned on for %d rule set. Its conditions are kept.', 'Always hide turned on for %d rule sets. Their conditions are kept.', max( 1, $n( 'vg_n' ) ), 'visigate' )
				/* translators: %d: number of rule sets. */
				: _n( 'Always hide turned off for %d rule set. Its conditions apply again.', 'Always hide turned off for %d rule sets. Their conditions apply again.', max( 1, $n( 'vg_n' ) ), 'visigate' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( $msg, max( 1, $n( 'vg_n' ) ) ) ) . '</p></div>';
		}
		if ( 'none' === $done ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Choose a bulk action and at least one rule set.', 'visigate' ) . '</p></div>';
		}
		// phpcs:enable
	}

	public static function render() {
		if ( ! current_user_can( visigate_capability() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$paged  = isset( $_GET['paged_sets'] ) ? max( 1, absint( $_GET['paged_sets'] ) ) : 1;
		// phpcs:enable

		$posts = get_posts(
			array(
				'post_type'   => Visigate_Ruleset::CPT,
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts' => 1000,
				'orderby'     => 'modified',
				'order'       => 'DESC',
			)
		);
		$rows   = array();
		$counts = array( 'all' => 0, 'active' => 0, 'hidden' => 0, 'unused' => 0, 'draft' => 0 );
		foreach ( $posts as $p ) {
			$sum  = Visigate_Admin_UI::summarize( get_post_meta( $p->ID, Visigate_Ruleset::META, true ) );
			$uses = Visigate_Usage::count( $p->ID );
			$stat = Visigate_Admin_UI::status( $p, $sum, $uses );
			++$counts['all'];
			++$counts[ $stat[0] ];
			if ( '' !== $search && false === stripos( $p->post_title, $search ) ) {
				continue;
			}
			if ( 'all' !== $filter && $stat[0] !== $filter ) {
				continue;
			}
			$rows[] = array( 'post' => $p, 'sum' => $sum, 'uses' => $uses, 'stat' => $stat );
		}
		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$rows  = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$trash = (int) ( wp_count_posts( Visigate_Ruleset::CPT )->trash ?? 0 );
		$counts['trash'] = $trash;

		$filters = array(
			'all'    => __( 'All', 'visigate' ),
			'active' => __( 'Active', 'visigate' ),
			'hidden' => __( 'Always hidden', 'visigate' ),
			'unused' => __( 'Unused', 'visigate' ),
			'draft'  => __( 'Not published', 'visigate' ),
			'trash'  => __( 'Trash', 'visigate' ),
		);
		$base    = array_filter( array( 'status' => 'all' !== $filter ? $filter : '', 'q' => $search ) );
		$current = self::url( array_merge( $base, $paged > 1 ? array( 'paged_sets' => $paged ) : array() ) );
		$new     = admin_url( 'post-new.php?post_type=' . Visigate_Ruleset::CPT );
		?>
		<div class="wrap vg-wrap vg-page">
			<h1 class="vg-sr"><?php esc_html_e( 'Rule Sets', 'visigate' ); ?></h1>
			<hr class="wp-header-end">
			<?php self::notices(); ?>

			<section class="vg-card" aria-labelledby="vg-rs-title">
				<h2 id="vg-rs-title" class="vg-sr"><?php esc_html_e( 'All rule sets', 'visigate' ); ?></h2>
				<div class="vg-toolbar">
					<nav class="vg-segment" aria-label="<?php esc_attr_e( 'Filter by status', 'visigate' ); ?>">
						<?php foreach ( $filters as $key => $label ) : ?>
							<?php
							if ( in_array( $key, array( 'draft', 'trash' ), true ) && ! $counts[ $key ] && $key !== $filter ) {
								continue;
							}
							$on = $key === $filter;
							?>
							<a class="vg-seg<?php echo $on ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::url( array_filter( array( 'status' => 'all' !== $key ? $key : '', 'q' => $search ) ) ) ); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="vg-seg-n"><?php echo (int) $counts[ $key ]; ?></span></a>
						<?php endforeach; ?>
					</nav>
					<form class="vg-toolbar-end" method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" role="search">
						<input type="hidden" name="post_type" value="<?php echo esc_attr( Visigate_Ruleset::CPT ); ?>">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
						<?php if ( 'all' !== $filter ) : ?>
							<input type="hidden" name="status" value="<?php echo esc_attr( $filter ); ?>">
						<?php endif; ?>
						<label class="vg-sr" for="vg-rs-search"><?php esc_html_e( 'Search rule sets', 'visigate' ); ?></label>
						<input id="vg-rs-search" type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search rule sets', 'visigate' ); ?>">
						<a class="vg-btn is-primary" href="<?php echo esc_url( $new ); ?>"><?php esc_html_e( 'New rule set', 'visigate' ); ?></a>
					</form>
				</div>

				<?php if ( 'trash' === $filter ) : ?>
					<?php
					self::trash_list(
						Visigate_Ruleset::CPT,
						$current,
						function ( $p ) {
							$n = Visigate_Usage::count( $p->ID );
							/* translators: %d: number of places */
							return $n ? sprintf( _n( 'Still used in %d place: that content is hidden until you restore this rule set.', 'Still used in %d places: that content is hidden until you restore this rule set.', $n, 'visigate' ), $n ) : '';
						},
						$search
					);
					?>
				<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'visigate_bulk_sets' ); ?>
					<input type="hidden" name="action" value="visigate_bulk_sets">
					<input type="hidden" name="back" value="<?php echo esc_url( $current ); ?>">
					<?php if ( ! $rows ) : ?>
						<div class="vg-empty">
							<?php if ( ! $counts['all'] ) : ?>
								<p><?php esc_html_e( 'No rule sets yet. A rule set is a reusable rule, such as “logged-in customers on mobile”, that you can apply to any block, Elementor element or menu item.', 'visigate' ); ?></p>
								<a class="vg-btn is-primary" href="<?php echo esc_url( $new ); ?>"><?php esc_html_e( 'Create your first rule set', 'visigate' ); ?></a>
							<?php else : ?>
								<p><?php esc_html_e( 'No rule sets match this filter.', 'visigate' ); ?></p>
								<a class="vg-btn" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Show all rule sets', 'visigate' ); ?></a>
							<?php endif; ?>
						</div>
					<?php else : ?>
						<div class="vg-rows">
							<div class="vg-row is-head vg-grid-rules">
								<input type="checkbox" class="vg-check-all" aria-label="<?php esc_attr_e( 'Select all rule sets', 'visigate' ); ?>">
								<span><?php esc_html_e( 'Rule set', 'visigate' ); ?></span>
								<span><?php esc_html_e( 'Conditions', 'visigate' ); ?></span>
								<span><?php esc_html_e( 'Used in', 'visigate' ); ?></span>
								<span><?php esc_html_e( 'Always hide', 'visigate' ); ?></span>
								<span><?php esc_html_e( 'Modified', 'visigate' ); ?></span>
							</div>
							<?php foreach ( $rows as $r ) : ?>
								<?php
								$p      = $r['post'];
								$title  = $p->post_title ? $p->post_title : __( '(no title)', 'visigate' );
								$hidden = 'hidden' === $r['sum']['state'];
								$dup    = wp_nonce_url( add_query_arg( array( 'action' => 'visigate_duplicate_set', 'post' => $p->ID ), admin_url( 'admin-post.php' ) ), 'visigate_duplicate_set_' . $p->ID );
								?>
								<div class="vg-row vg-grid-rules<?php echo $hidden ? ' is-hidden' : ''; ?>">
									<input type="checkbox" name="ids[]" value="<?php echo (int) $p->ID; ?>" aria-label="<?php /* translators: %s: rule set title */ echo esc_attr( sprintf( __( 'Select %s', 'visigate' ), $title ) ); ?>">
									<div class="vg-row-name">
										<span class="<?php echo esc_attr( Visigate_Admin_UI::mark_class( $r['sum']['state'], $r['stat'][0] ) ); ?>" aria-hidden="true"></span>
										<div>
											<span class="vg-row-line">
												<a class="vg-row-title" href="<?php echo esc_url( (string) get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( $title ); ?></a>
												<?php if ( 'active' !== $r['stat'][0] ) : ?>
													<span class="vg-badge <?php echo esc_attr( $r['stat'][2] ); ?>"><?php echo esc_html( $r['stat'][1] ); ?></span>
												<?php endif; ?>
											</span>
											<span class="vg-row-text"><?php echo esc_html( $r['sum']['text'] ); ?></span>
											<?php foreach ( Visigate_Warnings::server_flags( (array) get_post_meta( $p->ID, Visigate_Ruleset::META, true ) ) as $flag ) : ?>
												<span class="vg-row-flag">⚠ <?php echo esc_html( $flag ); ?></span>
											<?php endforeach; ?>
											<span class="vg-row-actions">
												<a href="<?php echo esc_url( (string) get_edit_post_link( $p->ID ) ); ?>"><?php esc_html_e( 'Edit', 'visigate' ); ?></a>
												<span aria-hidden="true">·</span>
												<a href="<?php echo esc_url( $dup ); ?>"><?php esc_html_e( 'Duplicate', 'visigate' ); ?></a>
												<span aria-hidden="true">·</span>
												<a href="<?php echo esc_url( Visigate_Usage::usage_url( $p->ID ) ); ?>"><?php esc_html_e( 'Where used', 'visigate' ); ?></a>
												<?php if ( current_user_can( 'delete_post', $p->ID ) ) : ?>
													<span aria-hidden="true">·</span>
													<a class="is-danger" href="<?php echo esc_url( (string) get_delete_post_link( $p->ID ) ); ?>" <?php echo $r['uses'] ? ' data-vg-confirm="' . esc_attr__( 'This rule set is in use. Content that uses it will be hidden from everyone while it is in the Trash. Move it to the Trash anyway?', 'visigate' ) . '"' : ''; ?>><?php esc_html_e( 'Trash', 'visigate' ); ?></a>
												<?php endif; ?>
											</span>
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
									<span>
										<a class="vg-switch-link<?php echo $hidden ? ' is-on' : ''; ?>" role="switch" aria-checked="<?php echo $hidden ? 'true' : 'false'; ?>" href="<?php echo esc_url( self::toggle_url( $p->ID, ! $hidden ) ); ?>" aria-label="<?php /* translators: %s: rule set title */ echo esc_attr( sprintf( __( 'Always hide %s', 'visigate' ), $title ) ); ?>"><span class="vg-switch-track" aria-hidden="true"></span></a>
									</span>
									<span class="vg-muted"><?php /* translators: %s: time, such as "2 days" */ echo esc_html( sprintf( __( '%s ago', 'visigate' ), human_time_diff( (int) get_post_modified_time( 'U', true, $p ), time() ) ) ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="vg-card-foot vg-table-foot">
							<label class="vg-sr" for="vg-rs-bulk"><?php esc_html_e( 'Bulk action', 'visigate' ); ?></label>
							<select id="vg-rs-bulk" name="op">
								<option value=""><?php esc_html_e( 'Bulk actions', 'visigate' ); ?></option>
								<option value="hide"><?php esc_html_e( 'Turn Always hide on', 'visigate' ); ?></option>
								<option value="show"><?php esc_html_e( 'Turn Always hide off', 'visigate' ); ?></option>
								<option value="trash"><?php esc_html_e( 'Move to Trash', 'visigate' ); ?></option>
								<?php foreach ( (array) apply_filters( 'visigate_rules_bulk_actions', array() ) as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="vg-btn"><?php esc_html_e( 'Apply', 'visigate' ); ?></button>
							<span class="vg-muted">
								<?php
								/* translators: 1: shown count, 2: total count */
								echo esc_html( sprintf( __( 'Showing %1$d of %2$d rule sets', 'visigate' ), count( $rows ), $total ) );
								?>
							</span>
							<?php if ( $pages > 1 ) : ?>
								<nav class="vg-pager" aria-label="<?php esc_attr_e( 'Pages', 'visigate' ); ?>">
									<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
										<a class="vg-page-link<?php echo $i === $paged ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::url( array_merge( $base, array( 'paged_sets' => $i ) ) ) ); ?>"<?php echo $i === $paged ? ' aria-current="page"' : ''; ?>><?php echo (int) $i; ?></a>
									<?php endfor; ?>
								</nav>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</form>
				<?php endif; ?>
			</section>

			<?php self::templates_strip(); ?>
			<?php Visigate_Admin_UI::footer(); ?>
		</div>
		<?php
	}

	/* ------------------------------- Trash ------------------------------- */

	/** Post types whose Trash Visigate shows on its own screens. */
	private static function trash_types() {
		return array( Visigate_Ruleset::CPT, 'wp_block' );
	}

	/** Restore, delete permanently or empty the Trash (rule sets and patterns). */
	public static function handle_trash() {
		// The nonce action includes the post type, so it is read (and sanitized) before the check.
		$type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'visigate_trash_' . $type );
		if ( ! current_user_can( visigate_capability() ) || ! in_array( $type, self::trash_types(), true ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'visigate' ), '', array( 'response' => 403 ) );
		}
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		$back = remove_query_arg( array( 'trashed', 'untrashed', 'deleted', 'ids', 'vg_done', 'vg_n' ), wp_validate_redirect( $back, self::url() ) );
		if ( isset( $_POST['empty'] ) ) {
			$op  = 'delete';
			$ids = get_posts( array( 'post_type' => $type, 'post_status' => 'trash', 'numberposts' => -1, 'fields' => 'ids' ) );
		} else {
			$ids = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
		}
		$done = 0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || $type !== $post->post_type || 'trash' !== $post->post_status || ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}
			if ( 'restore' === $op && wp_untrash_post( $id ) ) {
				++$done;
			} elseif ( 'delete' === $op && wp_delete_post( $id, true ) ) {
				++$done;
			}
		}
		if ( Visigate_Ruleset::CPT === $type ) {
			Visigate_Usage::index( true );
			Visigate_Cache_Check::flush_issues();
		}
		if ( ! in_array( $op, array( 'restore', 'delete' ), true ) || ! $ids ) {
			wp_safe_redirect( add_query_arg( 'vg_done', 'none', $back ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'restore' === $op ? 'untrashed' : 'deleted', max( 1, $done ), $back ) );
		exit;
	}

	/**
	 * A Trash list in Visigate's style: Restore / Delete permanently per row, bulk actions, Empty Trash.
	 *
	 * @param string   $type     visigate_set or wp_block.
	 * @param string   $back     URL of the screen showing the list.
	 * @param callable $note     function( WP_Post ): string, a warning shown under the title ('' for none).
	 * @param string   $search   Optional title filter.
	 */
	public static function trash_list( $type, $back, $note = null, $search = '' ) {
		$posts = get_posts( array( 'post_type' => $type, 'post_status' => 'trash', 'numberposts' => 500, 'orderby' => 'modified', 'order' => 'DESC' ) );
		if ( '' !== $search ) {
			$posts = array_values( array_filter( $posts, function ( $p ) use ( $search ) { return false !== stripos( $p->post_title, $search ); } ) );
		}
		$days = defined( 'EMPTY_TRASH_DAYS' ) ? (int) EMPTY_TRASH_DAYS : 30;
		?>
		<div class="vg-trash-intro">
			<p class="vg-muted">
				<?php
				echo esc_html(
					$days > 0
						/* translators: %d: number of days */
						? sprintf( _n( 'Items in the Trash are deleted for good automatically after %d day. Restored items come back as drafts.', 'Items in the Trash are deleted for good automatically after %d days. Restored items come back as drafts.', $days, 'visigate' ), $days )
						: __( 'Restored items come back as drafts.', 'visigate' )
				);
				?>
			</p>
			<?php if ( $posts ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vg-trash-empty">
					<?php wp_nonce_field( 'visigate_trash_' . $type ); ?>
					<input type="hidden" name="action" value="visigate_trash">
					<input type="hidden" name="post_type" value="<?php echo esc_attr( $type ); ?>">
					<input type="hidden" name="back" value="<?php echo esc_url( $back ); ?>">
					<button type="submit" name="empty" value="1" class="vg-btn is-danger" data-vg-confirm="<?php echo esc_attr( sprintf( /* translators: %d: number of items */ _n( 'Delete %d item for good? This cannot be undone.', 'Delete all %d items for good? This cannot be undone.', count( $posts ), 'visigate' ), count( $posts ) ) ); ?>"><?php esc_html_e( 'Empty Trash', 'visigate' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php if ( ! $posts ) : ?>
			<div class="vg-empty"><p><?php esc_html_e( 'The Trash is empty.', 'visigate' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'visigate_trash_' . $type ); ?>
			<input type="hidden" name="action" value="visigate_trash">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( $type ); ?>">
			<input type="hidden" name="back" value="<?php echo esc_url( $back ); ?>">
			<div class="vg-rows">
				<div class="vg-row is-head vg-grid-trash">
					<input type="checkbox" class="vg-check-all" aria-label="<?php esc_attr_e( 'Select all', 'visigate' ); ?>">
					<span><?php esc_html_e( 'Name', 'visigate' ); ?></span>
					<span><?php esc_html_e( 'Trashed', 'visigate' ); ?></span>
					<span><?php esc_html_e( 'Deleted for good', 'visigate' ); ?></span>
				</div>
				<?php foreach ( $posts as $p ) : ?>
					<?php
					$title   = '' !== $p->post_title ? $p->post_title : __( '(no title)', 'visigate' );
					$when    = (int) get_post_meta( $p->ID, '_wp_trash_meta_time', true );
					$warn    = $note ? (string) call_user_func( $note, $p ) : '';
					$restore = wp_nonce_url( admin_url( 'post.php?post=' . $p->ID . '&action=untrash' ), 'untrash-post_' . $p->ID );
					?>
					<div class="vg-row vg-grid-trash">
						<input type="checkbox" name="ids[]" value="<?php echo (int) $p->ID; ?>" aria-label="<?php /* translators: %s: title */ echo esc_attr( sprintf( __( 'Select %s', 'visigate' ), $title ) ); ?>">
						<div class="vg-row-name">
							<span class="vg-mark is-off" aria-hidden="true"></span>
							<div>
								<span class="vg-row-title vg-trash-title"><?php echo esc_html( $title ); ?></span>
								<?php if ( '' !== $warn ) : ?>
									<span class="vg-row-flag">⚠ <?php echo esc_html( $warn ); ?></span>
								<?php endif; ?>
								<span class="vg-row-actions">
									<a href="<?php echo esc_url( $restore ); ?>"><?php esc_html_e( 'Restore', 'visigate' ); ?></a>
									<span aria-hidden="true">·</span>
									<a class="is-danger" href="<?php echo esc_url( (string) get_delete_post_link( $p->ID, '', true ) ); ?>" data-vg-confirm="<?php esc_attr_e( 'Delete this item for good? This cannot be undone.', 'visigate' ); ?>"><?php esc_html_e( 'Delete permanently', 'visigate' ); ?></a>
								</span>
							</div>
						</div>
						<span class="vg-muted"><?php echo esc_html( $when ? sprintf( /* translators: %s: time */ __( '%s ago', 'visigate' ), human_time_diff( $when, time() ) ) : '—' ); ?></span>
						<span class="vg-muted">
							<?php
							if ( $days > 0 && $when ) {
								$left = (int) ceil( ( $when + $days * DAY_IN_SECONDS - time() ) / DAY_IN_SECONDS );
								/* translators: %d: number of days */
								echo esc_html( $left > 0 ? sprintf( _n( 'in %d day', 'in %d days', $left, 'visigate' ), $left ) : __( 'at the next clean-up', 'visigate' ) );
							} else {
								echo '—';
							}
							?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="vg-card-foot vg-table-foot">
				<label class="vg-sr" for="vg-trash-op"><?php esc_html_e( 'Bulk action', 'visigate' ); ?></label>
				<select id="vg-trash-op" name="op">
					<option value=""><?php esc_html_e( 'Bulk actions', 'visigate' ); ?></option>
					<option value="restore"><?php esc_html_e( 'Restore', 'visigate' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete permanently', 'visigate' ); ?></option>
				</select>
				<button type="submit" class="vg-btn" data-vg-confirm-op="delete" data-vg-confirm="<?php esc_attr_e( 'Delete the selected items for good? This cannot be undone.', 'visigate' ); ?>"><?php esc_html_e( 'Apply', 'visigate' ); ?></button>
				<span class="vg-muted"><?php /* translators: %d: number of items */ echo esc_html( sprintf( _n( '%d item in the Trash', '%d items in the Trash', count( $posts ), 'visigate' ), count( $posts ) ) ); ?></span>
			</div>
		</form>
		<?php
	}

	private static function templates_strip() {
		global $submenu;
		$has_templates = false;
		foreach ( (array) ( $submenu[ Visigate_Admin_UI::parent_slug() ] ?? array() ) as $item ) {
			if ( isset( $item[2] ) && 'visigate-templates' === $item[2] ) {
				$has_templates = true;
			}
		}
		?>
		<section class="vg-strip" aria-labelledby="vg-rs-tpl">
			<div class="vg-strip-main">
				<h2 id="vg-rs-tpl"><?php esc_html_e( 'Start from a template', 'visigate' ); ?> <span class="vg-pill"><?php esc_html_e( 'Pro', 'visigate' ); ?></span></h2>
				<p class="vg-muted"><?php esc_html_e( 'Business hours, new members, mobile visitors, returning customers and more, ready to edit.', 'visigate' ); ?></p>
			</div>
			<?php if ( $has_templates ) : ?>
				<a class="vg-btn is-soft" href="<?php echo esc_url( Visigate_Admin_UI::page_url( 'visigate-templates' ) ); ?>"><?php esc_html_e( 'Browse templates', 'visigate' ); ?></a>
			<?php else : ?>
				<a class="vg-btn is-soft" href="<?php echo esc_url( (string) apply_filters( 'visigate_upgrade_url', 'https://persjay.com/plugins/visigate/#pricing' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro plans', 'visigate' ); ?></a>
			<?php endif; ?>
		</section>
		<?php
	}
}
