<?php
/** Standalone menu visibility checks: php tests/menus.php */
require __DIR__ . '/visitor-conditions.php';
$meta = array();
$can_manage = true;
$nonce_ok = true;
$preview = false;
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function vg_unslash_deep( $v ) { return is_array( $v ) ? array_map( 'vg_unslash_deep', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
// Like WordPress, update_post_meta() unslashes the value it stores.
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = vg_unslash_deep( $value ); }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); }
function wp_verify_nonce( $nonce, $action ) { return $GLOBALS['nonce_ok']; }
function current_user_can( $cap ) { return $GLOBALS['can_manage']; }
function visigate_capability() { return 'visigate_manage'; }
function absint( $v ) { return abs( (int) $v ); }
function wp_doing_ajax() { return false; }
function get_post( $id ) { return (object) array( 'post_type' => 'visigate_set', 'post_status' => 'draft' ); }
class Visigate_Ruleset { const CPT = 'visigate_set'; }
class Visigate_Frontend { public static function preview_enabled() { return $GLOBALS['preview']; } public static function evaluate( $kind, $label, $key, $loop_post, $check ) { return $check(); } }
require __DIR__ . '/../includes/class-menus.php';
$rules = array( 'action' => 'show', 'groups' => array( array( 'conditions' => array( array( 'type' => 'url_param', 'op' => 'equals', 'value' => array( 'key' => 'source', 'value' => 'menu' ) ) ) ) ) );
$_GET = array();
$_POST = array( 'update-nav-menu-nonce' => 'ok', 'visigate_menu_set' => array( 10 => '-1' ), 'visigate_menu_rules' => array( 10 => json_encode( $rules ) ) );
update_post_meta( 10, Visigate_Menus::META, 99 );
Visigate_Menus::save( 1, 10 );
expect( $rules === Visigate_Menus::get_rules( 10 ) && 0 === Visigate_Menus::get_set( 10 ), 'Custom mode replaces the saved set' );
$saved = $meta;
$_POST['visigate_menu_rules'][10] = '{bad json';
Visigate_Menus::save( 1, 10 );
expect( $saved === $meta, 'Malformed JSON preserves visibility' );
unset( $_POST['visigate_menu_rules'] );
Visigate_Menus::save( 1, 10 );
expect( $saved === $meta, 'Missing JSON preserves visibility' );
$_POST['visigate_menu_set'][10] = '0';
$nonce_ok = false;
Visigate_Menus::save( 1, 10 );
expect( $saved === $meta, 'Bad nonce cannot change rules' );
$nonce_ok = true;
$can_manage = false;
Visigate_Menus::save( 1, 10 );
expect( $saved === $meta, 'Restricted users cannot change rules' );
$can_manage = true;
$_POST = array();
Visigate_Menus::save( 1, 10 );
expect( $saved === $meta, 'REST and Customizer saves preserve rules' );
function menu_items() {
	return array_map( function ( $row ) { return (object) array( 'ID' => $row[0], 'menu_item_parent' => $row[1], 'title' => 'Link', 'classes' => array() ); }, array( array( 10, 0 ), array( 11, 10 ), array( 12, 11 ), array( 20, 0 ) ) );
}
expect( array( 20 ) === array_column( Visigate_Menus::filter_items( menu_items() ), 'ID' ), 'Custom rules hide a parent and all descendants' );
$preview = true;
$items = Visigate_Menus::filter_items( menu_items() );
expect( 4 === count( $items ) && $items[0]->visigate_hidden && $items[2]->visigate_hidden && empty( $items[3]->visigate_hidden ), 'Preview marks hidden descendants and preserves visible siblings' );
$preview = false;
add_filter( 'visigate_hidden_menu_item', function ( $replacement, $item, $set ) {
	expect( 0 === $set, 'Custom fallback receives no saved set' );
	$item = clone $item;
	$item->title = 'Replacement';
	return $item;
} );
$items = Visigate_Menus::filter_items( menu_items() );
expect( array( 10, 20 ) === array_column( $items, 'ID' ) && 'Replacement' === $items[0]->title, 'Fallback replaces hidden custom item and removes descendants' );
unset( $hooks['visigate_hidden_menu_item'] );
$always = array( 'action' => 'show', 'groups' => array(), 'always_hide' => true );
$_POST = array( 'update-nav-menu-nonce' => 'ok', 'visigate_menu_set' => array( 10 => '-1' ), 'visigate_menu_rules' => array( 10 => json_encode( $always ) ) );
Visigate_Menus::save( 1, 10 );
expect( $always === Visigate_Menus::get_rules( 10 ), 'Always hide persists for menu items' );
expect( array( 20 ) === array_column( Visigate_Menus::filter_items( menu_items() ), 'ID' ), 'Always hide removes parent and descendants without conditions' );
$_POST = array( 'update-nav-menu-nonce' => 'ok', 'visigate_menu_set' => array( 10 => '99' ) );
Visigate_Menus::save( 1, 10 );
expect( 99 === Visigate_Menus::get_set( 10 ) && array() === Visigate_Menus::get_rules( 10 ), 'Saved set mode clears custom rules' );
expect( array( 20 ) === array_column( Visigate_Menus::filter_items( menu_items() ), 'ID' ), 'Unpublished saved sets continue to hide items' );
$slashed = array( 'action' => 'show', 'groups' => array( array( 'conditions' => array( array( 'type' => 'cookie', 'op' => 'equals', 'value' => array( 'key' => 'path', 'value' => 'C:\\temp' ) ) ) ) ) );
// WordPress adds slashes to $_POST, so the JSON arrives slashed.
$_POST = array( 'update-nav-menu-nonce' => 'ok', 'visigate_menu_set' => array( 10 => '-1' ), 'visigate_menu_rules' => array( 10 => addslashes( json_encode( $slashed ) ) ) );
Visigate_Menus::save( 1, 10 );
expect( 'C:\\temp' === Visigate_Menus::get_rules( 10 )['groups'][0]['conditions'][0]['value']['value'], 'Backslashes in custom menu rule values survive saving' );
$_POST['visigate_menu_set'][10] = '0';
Visigate_Menus::save( 1, 10 );
expect( 4 === count( Visigate_Menus::filter_items( menu_items() ) ), 'Always show removes visibility rules' );
echo "PASS: menu custom rules, saved sets, permissions, invalid payloads, descendants, preview and fallback.\n";
