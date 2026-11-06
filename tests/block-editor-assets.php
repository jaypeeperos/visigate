<?php
/** Real WordPress dependency resolution: php block-editor-assets.php /path/to/wp-load.php */
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Pass the path to a WordPress wp-load.php with Visigate active.\n" );
	exit( 2 );
}
require $argv[1];
if ( ! class_exists( 'Visigate_Admin' ) ) {
	throw new RuntimeException( 'Visigate must be active in the test installation.' );
}
// Runtime-only user context; no user settings or post content are changed.
wp_set_current_user( isset( $argv[2] ) ? (int) $argv[2] : 1 );
Visigate_Admin::enqueue_editor();
$scripts = wp_scripts();
$editor = $scripts->registered['visigate-editor'] ?? null;
if ( ! $editor ) { throw new RuntimeException( 'Visigate editor script was not registered.' ); }
foreach ( $editor->deps as $handle ) {
	if ( ! isset( $scripts->registered[ $handle ] ) ) {
		throw new RuntimeException( 'Unregistered editor dependency: ' . $handle );
	}
}
$scripts->all_deps( array( 'visigate-editor' ) );
if ( ! in_array( 'visigate-editor', $scripts->to_do, true ) ) {
	throw new RuntimeException( 'WordPress dropped Visigate from the script queue.' );
}
ob_start();
wp_print_scripts( array( 'visigate-editor' ) );
$html = ob_get_clean();
if ( false === strpos( $html, VISIGATE_URL . 'assets/js/editor.js' ) ) {
	throw new RuntimeException( 'WordPress did not print the Visigate editor script tag.' );
}
echo "PASS: all editor dependencies resolve and WordPress prints the Visigate editor script tag.\n";
