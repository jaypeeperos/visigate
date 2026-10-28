/**
 * Visigate — Gutenberg integration.
 * Adds a "Visibility Rules" panel to every block and stores rules in block attributes.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var addFilter = wp.hooks.addFilter;
	var createHOC = wp.compose.createHigherOrderComponent;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var comp = wp.components;
	var __ = wp.i18n.__;
	var D = window.VisigateData || { sets: [] };
	var Builder = window.VisigateBuilder;

	function hasRules( a ) {
		return !! ( a && ( a.visigateSet || ( a.visigateRules && a.visigateRules.groups && a.visigateRules.groups.length ) ) );
	}

	// 1. Register attributes on every block (persisted in the block comment delimiter).
	addFilter( 'blocks.registerBlockType', 'visigate/attributes', function ( settings ) {
		settings.attributes = Object.assign( {}, settings.attributes, {
			visigateSet: { type: 'number', default: 0 },
			visigateRules: { type: 'object' },
		} );
		return settings;
	} );

	// 2. Sidebar panel.
	function Panel( props ) {
		var a = props.attributes;
		var setOptions = [ { value: '0', label: __( '— None —', 'visigate' ) } ].concat(
			( D.sets || [] ).map( function ( s ) { return { value: String( s.id ), label: s.title }; } )
		);
		return el( comp.PanelBody, { title: __( 'Visibility Rules', 'visigate' ), initialOpen: hasRules( a ), icon: 'visibility' },
			el( comp.SelectControl, {
				label: __( 'Saved rule set', 'visigate' ),
				value: String( a.visigateSet || 0 ),
				options: setOptions,
				onChange: function ( v ) { props.setAttributes( { visigateSet: parseInt( v, 10 ) || 0 } ); },
				help: el( 'a', { href: D.newSetUrl, target: '_blank', rel: 'noopener' }, __( 'Create a reusable rule set', 'visigate' ) ),
				__nextHasNoMarginBottom: true,
			} ),
			el( 'hr' ),
			el( 'p', { className: 'vg-panel-title' }, __( 'Custom rules for this block', 'visigate' ) ),
			el( Builder, {
				value: a.visigateRules,
				onChange: function ( v ) {
					props.setAttributes( { visigateRules: v && v.groups && v.groups.length ? v : undefined } );
				},
			} )
		);
	}

	addFilter( 'editor.BlockEdit', 'visigate/panel', createHOC( function ( BlockEdit ) {
		return function ( props ) {
			if ( ! props.isSelected ) {
				return el( BlockEdit, props );
			}
			return el( Fragment, null,
				el( BlockEdit, props ),
				el( InspectorControls, null, el( Panel, props ) )
			);
		};
	}, 'withVisigatePanel' ) );

	// 3. Visual cue in the editor canvas for blocks that carry rules.
	addFilter( 'editor.BlockListBlock', 'visigate/indicator', createHOC( function ( BlockListBlock ) {
		return function ( props ) {
			if ( hasRules( props.attributes ) ) {
				props = Object.assign( {}, props, { className: ( props.className ? props.className + ' ' : '' ) + 'visigate-has-rules' } );
			}
			return el( BlockListBlock, props );
		};
	}, 'withVisigateIndicator' ) );
}( window.wp ) );
