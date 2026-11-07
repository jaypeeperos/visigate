<?php
defined( 'ABSPATH' ) || exit;

use Elementor\Modules\AtomicWidgets\Controls\Section;
use Elementor\Modules\AtomicWidgets\Controls\Types\Select_Control;
use Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type;

// Atomic settings are validated against a separate schema when Elementor saves.
// Register these even for restricted editors so existing rules are preserved.
add_filter(
	'elementor/atomic-widgets/props-schema',
	function ( $schema ) {
		if ( ! class_exists( String_Prop_Type::class ) ) {
			return $schema;
		}
		require_once __DIR__ . '/class-elementor-atomic-rules-prop.php';
		$schema['visigate_set']   = String_Prop_Type::make()->default( '' );
		$schema['visigate_rules'] = Visigate_Elementor_Atomic_Rules_Prop::make()->default( '' );
		return $schema;
	}
);

add_filter(
	'elementor/atomic-widgets/controls',
	function ( $controls, $element ) {
		if ( ! current_user_can( visigate_capability() ) || ! class_exists( Section::class ) || ! class_exists( Select_Control::class ) ) {
			return $controls;
		}
		require_once __DIR__ . '/class-elementor-atomic-rules-control.php';
		$options = array( array( 'value' => '', 'label' => __( '— None —', 'visigate' ) ) );
		foreach ( array_merge( Visigate_Ruleset::all_sets(), Visigate_Ruleset::unpublished_sets() ) as $set ) {
			$options[] = array( 'value' => (string) $set['id'], 'label' => $set['title'] );
		}
		$items = array(
			Select_Control::bind_to( 'visigate_set' )
				->set_label( __( 'Saved rule set', 'visigate' ) )
				->set_options( $options ),
			Visigate_Elementor_Atomic_Rules_Control::bind_to( 'visigate_rules' )
				->set_label( __( 'Custom rules for this element', 'visigate' ) ),
		);
		$items = apply_filters( 'visigate_elementor_atomic_controls', $items, $element );
		$controls[] = Section::make()
			->set_id( 'visigate' )
			/* translators: %s: plugin name */
			->set_label( sprintf( __( '%s: Visibility', 'visigate' ), visigate_brand() ) )
			->set_description( __( 'If a saved rule set is selected, both it and these custom rules must allow this element to show.', 'visigate' ) )
			->set_items( $items );
		return $controls;
	},
	10,
	2
);

// Atomic layout elements use their own render types (e-flexbox, e-div-block,
// e-grid, etc.). Subscribe to the instance's type before its should_render hook.
add_action(
	'elementor/frontend/before_render',
	function ( $element ) use ( $visigate_elementor_filter ) {
		static $types = array( 'widget' => true, 'section' => true, 'column' => true, 'container' => true );
		if ( ! method_exists( $element, 'get_atomic_settings' ) ) {
			return;
		}
		$type = $element->get_type();
		if ( ! isset( $types[ $type ] ) ) {
			add_filter( "elementor/frontend/{$type}/should_render", $visigate_elementor_filter, 10, 2 );
			$types[ $type ] = true;
		}
	}
);
