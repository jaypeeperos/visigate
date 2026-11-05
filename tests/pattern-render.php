<?php
/** Real WordPress pipeline: php pattern-render.php /path/to/wp-load.php */
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Pass the path to a WordPress wp-load.php with Visigate active.\n" );
	exit( 2 );
}
require $argv[1];
if ( ! class_exists( 'Visigate_Frontend' ) ) {
	throw new RuntimeException( 'Visigate must be active in the test installation.' );
}
wp_set_current_user( 0 );
unset( $_GET['visigate_preview'] );
function visigate_pattern_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function visigate_pattern_block( $op ) {
	return array(
		'blockName' => 'core/block',
		'attrs' => array(
			'ref' => 44,
			'visigateRules' => array( 'action' => 'show', 'groups' => array( array( 'conditions' => array( array( 'type' => 'logged_in', 'op' => $op ) ) ) ) ),
		),
		'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array(),
	);
}
$original = WP_Block_Type_Registry::get_instance()->get_registered( 'core/block' );
$attributes = $original->attributes;
// Replace only the database lookup callback, in this PHP process's in-memory
// registry. WordPress parsing, serialization and rendering remain real. No posts
// or pattern records are created or changed by this test.
unregister_block_type( 'core/block' );
$renders = 0;
$source_child = null;
register_block_type( 'core/block', array(
	'attributes' => $attributes,
	'render_callback' => function ( $attrs ) use ( &$renders, &$source_child ) {
		visigate_pattern_check( 44 === $attrs['ref'], 'The placement must retain its source reference.' );
		++$renders;
		return $source_child ? render_block( $source_child ) : '<p>Shared pattern content</p>';
	},
) );
$hidden = visigate_pattern_block( 'is' );
$visible = visigate_pattern_block( 'is_not' );
$parsed = parse_blocks( serialize_block( $hidden ) )[0];
visigate_pattern_check( $parsed['attrs']['visigateRules'] === $hidden['attrs']['visigateRules'], 'Pattern rules must survive serialization.' );
visigate_pattern_check( '' === render_block( $parsed ), 'A hidden placement must render nothing.' );
visigate_pattern_check( 0 === $renders, 'A hidden placement must stop before resolving shared content.' );
visigate_pattern_check( '<p>Shared pattern content</p>' === render_block( $visible ), 'Another placement of the same source must remain visible.' );
visigate_pattern_check( 1 === $renders, 'Only the permitted placement should resolve shared content.' );
$source_child = visigate_pattern_block( 'is' );
$source_child['blockName'] = 'core/group';
visigate_pattern_check( '' === render_block( $visible ), 'Inner source rules must still apply when the placement allows rendering.' );
echo "PASS: real WordPress serialization, independent pattern placements, pre-render blocking and inner rules.\n";
