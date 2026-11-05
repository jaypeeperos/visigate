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
		// Extension point: add-ons can append controls (e.g. fallback content) to the panel.
		var extras = wp.hooks.applyFilters( 'visigate.panel.extras', [], props );
		return el( comp.PanelBody, { title: __( 'Visibility Rules', 'visigate' ), initialOpen: props.isPatternInstance || hasRules( a ), icon: 'visibility' },
			el( comp.SelectControl, {
				label: __( 'Saved rule set', 'visigate' ),
				value: String( a.visigateSet || 0 ),
				options: setOptions,
				onChange: function ( v ) { props.setAttributes( { visigateSet: parseInt( v, 10 ) || 0 } ); },
				help: el( 'a', { href: D.newSetUrl, target: '_blank', rel: 'noopener' }, __( 'Create a reusable rule set', 'visigate' ) ),
				__nextHasNoMarginBottom: true,
			} ),
			el( 'hr' ),
			el( 'p', { className: 'vg-panel-title' }, props.isPatternInstance ? __( 'Custom rules for this pattern', 'visigate' ) : __( 'Custom rules for this block', 'visigate' ) ),
			el( Builder, {
				value: a.visigateRules,
				onChange: function ( v ) {
					props.setAttributes( { visigateRules: v && v.groups && v.groups.length ? v : undefined } );
				},
			} ),
			extras
		);
	}

	// Users who may not manage Visigate keep the attributes above (so saving never strips rules)
	// but get no panel and no canvas indicator.
	if ( D.canManage === false ) {
		return;
	}

	// Content-only pattern editing can suppress InspectorControls entirely.
	// A plugin sidebar stays accessible and writes to the placement's wrapper,
	// never to the shared wp_block record or the pattern's internal blocks.
	var sidebarApi = wp.editor && wp.editor.PluginSidebar ? wp.editor : wp.editPost;
	if ( sidebarApi && sidebarApi.PluginSidebar && wp.plugins && wp.data ) {
		function PatternVisibility() {
			var choice = wp.element.useState( '' );
			var context = wp.data.useSelect( function ( select ) {
				var store = select( 'core/block-editor' );
				var selected = store.getSelectedBlockClientId();
				return { blocks: store.getBlocks(), selected: selected, parents: selected ? store.getBlockParents( selected ) : null };
			}, [] );
			var patterns = [];
			function collect( blocks ) {
				( blocks || [] ).forEach( function ( block ) {
					var metadata = block.attributes.metadata || {};
					if ( block.name === 'core/block' || metadata.patternName ) {
						patterns.push( {
							clientId: block.clientId,
							name: block.name,
							attributes: block.attributes,
							title: metadata.name || metadata.patternName || __( 'Synced pattern', 'visigate' ) + ( block.attributes.ref ? ' #' + block.attributes.ref : '' ),
						} );
					}
					// Synced content belongs to another entity; only its wrapper is local.
					if ( block.name !== 'core/block' ) {
						collect( block.innerBlocks );
					}
				} );
			}
			collect( context.blocks );
			var ancestry = context.selected ? [ context.selected ].concat( ( context.parents || [] ).slice().reverse() ) : [];
			var nearest = '';
			ancestry.some( function ( id ) {
				if ( patterns.some( function ( pattern ) { return pattern.clientId === id; } ) ) {
					nearest = id;
					return true;
				}
				return false;
			} );
			wp.element.useEffect( function () { choice[ 1 ]( '' ); }, [ context.selected ] );
			var targetId = choice[ 0 ] || nearest;
			var target = patterns.find( function ( pattern ) { return pattern.clientId === targetId; } );
			var options = [ { value: '', label: __( '— Select a pattern —', 'visigate' ) } ].concat(
				patterns.map( function ( pattern, index ) {
					return { value: pattern.clientId, label: ( index + 1 ) + '. ' + pattern.title };
				} )
			);
			return el( sidebarApi.PluginSidebar, { name: 'pattern-visibility', title: __( 'Pattern Visibility', 'visigate' ), icon: 'visibility' },
				el( 'div', { style: { padding: '16px' } },
					el( comp.SelectControl, {
						label: __( 'Pattern placement', 'visigate' ),
						value: target ? target.clientId : '',
						options: options,
						onChange: choice[ 1 ],
						__nextHasNoMarginBottom: true,
					} ),
					el( 'p', null, patterns.length
						? __( 'Select a pattern on the page or choose a placement above. These rules apply to this placement of the pattern.', 'visigate' )
						: __( 'Insert a pattern to configure visibility for its placement.', 'visigate' ) )
				),
				target ? el( Panel, {
					key: target.clientId,
					clientId: target.clientId,
					name: target.name,
					attributes: target.attributes,
					isPatternInstance: true,
					setAttributes: function ( attributes ) {
						wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( target.clientId, attributes );
					},
				} ) : null
			);
		}
		wp.plugins.registerPlugin( 'visigate-pattern-visibility', {
			icon: 'visibility',
			render: function () {
				return el( Fragment, null,
					sidebarApi.PluginSidebarMoreMenuItem ? el( sidebarApi.PluginSidebarMoreMenuItem, { target: 'pattern-visibility', icon: 'visibility' }, __( 'Pattern Visibility', 'visigate' ) ) : null,
					el( PatternVisibility )
				);
			},
		} );
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
