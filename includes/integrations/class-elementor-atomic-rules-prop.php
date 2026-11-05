<?php
defined( 'ABSPATH' ) || exit;

/** Sanitize rule data structurally rather than stripping text from encoded JSON. */
class Visigate_Elementor_Atomic_Rules_Prop extends \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type {
	protected function sanitize_value( $value ) {
		$rules = json_decode( $value, true );
		if ( ! is_array( $rules ) || empty( $rules['groups'] ) ) {
			return '';
		}
		return wp_json_encode( Visigate_Conditions::sanitize( $rules ) );
	}
}
