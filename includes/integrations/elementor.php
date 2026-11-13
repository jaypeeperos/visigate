<?php
defined( 'ABSPATH' ) || exit;

/**
 * Elementor: adds a "Visigate" section (Advanced tab) to widgets, sections, columns
 * and containers, letting you pick a saved Rule Set or define custom rules. Hidden
 * elements are never rendered on the front end; the editor always shows everything.
 *
 * Other builders (Divi, Beaver, Bricks, WPBakery, Oxygen…) can use the [visigate] shortcode
 * or the visigate_visible() PHP helper.
 */

add_action(
	'elementor/controls/register',
	function ( $controls_manager ) {
		require_once __DIR__ . '/class-elementor-rules-control.php';
		$controls_manager->register( new Visigate_Elementor_Rules_Control() );
	}
);

/** Read classic settings and atomic typed props through the same interface. */
function visigate_elementor_value( $value ) {
	if ( is_array( $value ) && isset( $value['$$type'] ) ) {
		return empty( $value['disabled'] ) ? ( $value['value'] ?? '' ) : '';
	}
	return $value;
}

function visigate_elementor_setting( $element, $key ) {
	return visigate_elementor_value( $element->get_settings( $key ) );
}

/** Inside a Loop Grid or Loop Carousel item the global post is that item; 0 on the page itself. */
function visigate_elementor_loop_post() {
	$id = (int) get_the_ID();
	return ( $id && ( ! is_singular() || $id !== (int) get_queried_object_id() ) ) ? $id : 0;
}

/** Inspector key for one element (per loop item). */
function visigate_elementor_key( $element ) {
	$id = method_exists( $element, 'get_id' ) ? (string) $element->get_id() : '';
	return 'elementor:' . $id . ':' . visigate_elementor_loop_post();
}

/** Saved and custom rules must both allow an element, matching the block editor. */
function visigate_elementor_visible( $element ) {
	$label = '';
	$key   = '';
	if ( class_exists( 'Visigate_Inspector' ) && Visigate_Inspector::recording() ) {
		$name  = method_exists( $element, 'get_title' ) ? (string) $element->get_title() : '';
		$name  = '' !== $name ? $name : ( method_exists( $element, 'get_name' ) ? (string) $element->get_name() : 'element' );
		$label = sprintf(
			/* translators: 1: Elementor element name, 2: element ID. */
			__( 'Elementor %1$s (#%2$s)', 'visigate' ),
			$name,
			method_exists( $element, 'get_id' ) ? (string) $element->get_id() : ''
		);
		$key = visigate_elementor_key( $element );
	}
	return Visigate_Frontend::evaluate(
		'elementor',
		$label,
		$key,
		visigate_elementor_loop_post(),
		function () use ( $element ) {
			$set = (int) visigate_elementor_setting( $element, 'visigate_set' );
			if ( $set && ! Visigate_Engine::set_visible( $set ) ) {
				return false;
			}
			$rules = visigate_elementor_setting( $element, 'visigate_rules' );
			if ( is_string( $rules ) ) {
				$rules = json_decode( $rules, true );
			}
			return ! is_array( $rules ) || Visigate_Engine::visible( $rules );
		}
	);
}

add_action(
	'elementor/element/after_section_end',
	function ( $element, $section_id, $args ) {
		// This hook also runs for documents such as Post Settings. Visibility
		// rules only apply to rendered elements, not document settings.
		if ( ! $element instanceof \Elementor\Element_Base ) {
			return;
		}
		// Containers and third-party widgets use different section IDs. Register
		// after the first Advanced section instead of depending on its name.
		if ( ! isset( $args['tab'] ) || \Elementor\Controls_Manager::TAB_ADVANCED !== $args['tab'] ) {
			return;
		}
		if ( $element->get_controls( 'visigate_set' ) ) {
			return;
		}

		$brand = visigate_brand();

		// Users who may not manage Visigate (e.g. clients, when an add-on restricts access) get a read-only note.
		// Rule values are kept in hidden controls so saving the page never removes them.
		if ( ! current_user_can( visigate_capability() ) ) {
			$element->start_controls_section(
				'visigate_section',
				array(
					/* translators: %s: plugin name */
					'label' => sprintf( __( '%s: Visibility', 'visigate' ), $brand ),
					'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
				)
			);
			$element->add_control( 'visigate_set', array( 'type' => \Elementor\Controls_Manager::HIDDEN, 'default' => '' ) );
			$element->add_control( 'visigate_rules', array( 'type' => \Elementor\Controls_Manager::HIDDEN, 'default' => '' ) );
			$element->add_control(
				'visigate_locked_note',
				array(
					'type' => \Elementor\Controls_Manager::RAW_HTML,
					'raw'  => esc_html__( 'Visibility rules on this site are managed by your administrator.', 'visigate' ),
				)
			);
			$element->end_controls_section();
			return;
		}

		$options = array( '' => __( '— None —', 'visigate' ) );
		foreach ( Visigate_Ruleset::all_sets() as $set ) {
			$options[ $set['id'] ] = $set['title'];
		}
		// Keep unpublished sets selectable so an element that uses one shows why it is hidden.
		foreach ( Visigate_Ruleset::unpublished_sets() as $set ) {
			$options[ $set['id'] ] = $set['title'];
		}

		$element->start_controls_section(
			'visigate_section',
			array(
				/* translators: %s: plugin name */
				'label' => sprintf( __( '%s: Visibility', 'visigate' ), $brand ),
				'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
			)
		);
		$element->add_control(
			'visigate_set',
			array(
				'label'       => __( 'Rule set', 'visigate' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $options,
				'default'     => '',
				'description' => sprintf(
					/* translators: %s: link */
					__( 'Create or edit rule sets under %s.', 'visigate' ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Visigate_Ruleset::CPT ) ) . '" target="_blank">' . esc_html( $brand ) . ' → Rule Sets</a>'
				),
			)
		);
		$element->add_control(
			'visigate_rules',
			array(
				'label'       => __( 'Custom rules for this element', 'visigate' ),
				'type'        => 'visigate_rules',
				'default'     => '',
				'separator'   => 'before',
				'label_block' => true,
				'description' => __( 'If a saved rule set is selected, both it and these custom rules must allow this element to show.', 'visigate' ),
			)
		);
		$element->end_controls_section();
	},
	10,
	3
);

$visigate_elementor_filter = function ( $should_render, $element ) {
	if ( ! $should_render ) {
		return $should_render;
	}
	if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
		return $should_render;
	}
	$visible = visigate_elementor_visible( $element );
	if ( $visible && class_exists( 'Visigate_Inspector' ) && Visigate_Inspector::recording() ) {
		echo Visigate_Inspector::anchor( visigate_elementor_key( $element ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML comment with int.
	}
	if ( Visigate_Frontend::preview_enabled() ) {
		return $should_render;
	}
	return $visible;
};

// Elementor's element cache stores the HTML of "static" elements and reuses it for every visitor.
// Elements with saved or custom rules must render fresh per visitor.
add_filter(
	'elementor/element/is_dynamic_content',
	function ( $is_dynamic, $raw_data ) {
		return $is_dynamic
			|| (bool) visigate_elementor_value( $raw_data['settings']['visigate_set'] ?? '' )
			|| (bool) visigate_elementor_value( $raw_data['settings']['visigate_rules'] ?? '' );
	},
	10,
	2
);

foreach ( array( 'widget', 'section', 'column', 'container' ) as $visigate_el_type ) {
	add_filter( "elementor/frontend/{$visigate_el_type}/should_render", $visigate_elementor_filter, 10, 2 );
}
unset( $visigate_el_type );

require_once __DIR__ . '/elementor-atomic.php';
