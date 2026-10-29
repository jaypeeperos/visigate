<?php
defined( 'ABSPATH' ) || exit;

/**
 * Elementor: adds a "Visigate" section (Advanced tab) to widgets, sections, columns
 * and containers, letting you pick a saved Rule Set. Hidden elements are never rendered
 * on the front end; the editor always shows everything.
 *
 * Other builders (Divi, Beaver, Bricks, WPBakery, Oxygen…) can use the [visigate] shortcode
 * or the visigate_visible() PHP helper.
 */

add_action(
	'elementor/element/after_section_end',
	function ( $element, $section_id ) {
		if ( ! in_array( $section_id, array( '_section_style', 'section_advanced' ), true ) ) {
			return;
		}
		if ( $element->get_controls( 'visigate_set' ) ) {
			return;
		}

		$brand = visigate_brand();

		// Users who may not manage Visigate (e.g. clients, when an add-on restricts access) get a read-only note.
		// The rule-set value is kept in a hidden control so saving the page never removes it.
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

		$options = array( '' => __( '— Always visible —', 'visigate' ) );
		foreach ( Visigate_Ruleset::all_sets() as $set ) {
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
		$element->end_controls_section();
	},
	10,
	2
);

$visigate_elementor_filter = function ( $should_render, $element ) {
	if ( ! $should_render ) {
		return $should_render;
	}
	if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
		return $should_render;
	}
	$set = (int) $element->get_settings( 'visigate_set' );
	if ( ! $set ) {
		return $should_render;
	}
	if ( Visigate_Frontend::preview_enabled() ) {
		return $should_render;
	}
	return Visigate_Engine::set_visible( $set );
};

// Elementor's element cache stores the HTML of "static" elements and reuses it for every visitor.
// Elements with a rule set must be rendered fresh per visitor, so flag them as dynamic.
add_filter(
	'elementor/element/is_dynamic_content',
	function ( $is_dynamic, $raw_data ) {
		return $is_dynamic || ! empty( $raw_data['settings']['visigate_set'] );
	},
	10,
	2
);

foreach ( array( 'widget', 'section', 'column', 'container' ) as $visigate_el_type ) {
	add_filter( "elementor/frontend/{$visigate_el_type}/should_render", $visigate_elementor_filter, 10, 2 );
}
unset( $visigate_el_type );
