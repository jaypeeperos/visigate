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
	var sidebarApi = wp.editor && wp.editor.PluginSidebar ? wp.editor : wp.editPost;
	var hasSidebar = !! ( sidebarApi && sidebarApi.PluginSidebar && wp.plugins && wp.data );

	function openVisigate() {
		wp.data.dispatch( 'core/interface' ).enableComplementaryArea( 'core', 'visigate-pattern-visibility/pattern-visibility' );
	}

	function OpenVisigateButton() {
		return el( comp.Button, { variant: 'secondary', icon: 'visibility', onClick: openVisigate },
			/* translators: %s: plugin display name */
			wp.i18n.sprintf( __( 'Open %s', 'visigate' ), D.brand || 'Visigate' )
		);
	}

	function hasRules( a ) {
		return !! ( a && ( a.visigateSet || ( a.visigateRules && ( a.visigateRules.always_hide || ( a.visigateRules.groups && a.visigateRules.groups.length ) ) ) ) );
	}

	// WordPress core block visibility (6.9+: metadata.blockVisibility === false hides the block
	// everywhere; 7.0+: metadata.blockVisibility.viewport.{mobile,tablet,desktop} === false hides
	// it on that device). Visigate only reads it so editors see one complete picture.
	var VIEWPORTS = [ 'mobile', 'tablet', 'desktop' ];

	function viewportLabel( key ) {
		return { mobile: __( 'mobile', 'visigate' ), tablet: __( 'tablet', 'visigate' ), desktop: __( 'desktop', 'visigate' ) }[ key ] || key;
	}

	/** Returns null, { all: true }, or { all: false, viewports: [ 'mobile', … ] }. */
	function coreHidden( a ) {
		var v = a && a.metadata ? a.metadata.blockVisibility : undefined;
		if ( v === false ) {
			return { all: true, viewports: [] };
		}
		if ( ! v || typeof v !== 'object' || ! v.viewport || typeof v.viewport !== 'object' ) {
			return null;
		}
		var hidden = VIEWPORTS.filter( function ( key ) { return v.viewport[ key ] === false; } );
		if ( ! hidden.length ) {
			return null;
		}
		return { all: hidden.length === VIEWPORTS.length, viewports: hidden };
	}

	function coreHiddenText( state ) {
		return state.all
			? __( 'Hidden for everyone by the WordPress "Hide" setting.', 'visigate' )
			/* translators: %s: comma-separated list of devices, e.g. "mobile, tablet" */
			: wp.i18n.sprintf( __( 'Hidden by WordPress on %s.', 'visigate' ), state.viewports.map( viewportLabel ).join( ', ' ) );
	}

	/** Metadata with WordPress block visibility removed (undefined when nothing else is left). */
	function withoutCoreVisibility( metadata ) {
		var next = Object.assign( {}, metadata );
		delete next.blockVisibility;
		return Object.keys( next ).length ? next : undefined;
	}

	function CoreVisibilityNotice( props ) {
		var state = coreHidden( props.attributes );
		if ( ! state ) {
			return null;
		}
		return el( comp.Notice, { status: state.all ? 'warning' : 'info', isDismissible: false, className: 'vg-core-hidden' },
			el( 'p', null, coreHiddenText( state ), ' ', state.all
				? __( 'Visibility rules below have no effect until the block is shown again.', 'visigate' )
				: __( 'On other devices, these rules decide who sees it. Both must allow the block to show.', 'visigate' ) ),
			el( comp.Button, {
				variant: 'link',
				onClick: function () { props.setAttributes( { metadata: withoutCoreVisibility( props.attributes.metadata ) } ); },
			}, state.all ? __( 'Show block again', 'visigate' ) : __( 'Show on all devices', 'visigate' ) )
		);
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
		// A saved set that is no longer published (draft, Trash, deleted) hides this content for
		// everyone. Show it in the list with a warning instead of falling back to "None".
		var setId = parseInt( a.visigateSet, 10 ) || 0;
		var setMissing = setId > 0 && ! ( D.sets || [] ).some( function ( s ) { return s.id === setId; } );
		if ( setMissing ) {
			var other = ( D.otherSets || [] ).find( function ( s ) { return s.id === setId; } );
			setOptions.push( {
				value: String( setId ),
				/* translators: %d: rule set ID */
				label: other ? other.title : wp.i18n.sprintf( __( 'Rule set #%d (deleted — content hidden)', 'visigate' ), setId ),
			} );
		}
		// Extension point: add-ons can append controls (e.g. fallback content) to the panel.
		var extras = wp.hooks.applyFilters( 'visigate.panel.extras', [], props );
		return el( comp.PanelBody, { title: __( 'Visibility Rules', 'visigate' ), initialOpen: props.isPatternInstance || hasRules( a ) || !! coreHidden( a ), icon: 'visibility' },
			el( CoreVisibilityNotice, { attributes: a, setAttributes: props.setAttributes } ),
			setMissing ? el( comp.Notice, { status: 'warning', isDismissible: false, className: 'vg-set-missing' },
				__( 'The saved rule set below is not published, so this content is hidden from everyone. Restore or publish the rule set, or choose another one.', 'visigate' )
			) : null,
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
					// Keep a non-default action even before any condition exists, otherwise the
					// "Hide this content when…" choice is discarded and the select snaps back to "Show".
					// Empty conditional rules stay visible; Always hide also works without groups.
					props.setAttributes( { visigateRules: v && ( v.always_hide || ( v.groups && v.groups.length ) || v.action === 'hide' ) ? v : undefined } );
				},
			} ),
			hasSidebar && ! props.isPatternInstance && ( props.name === 'core/block' || ( a.metadata && a.metadata.patternName ) )
				? el( 'p', null, el( OpenVisigateButton ) ) : null,
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
	if ( hasSidebar ) {
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
					el( PatternVisibility ),
					sidebarApi.PluginDocumentSettingPanel ? el( sidebarApi.PluginDocumentSettingPanel, {
						name: 'visigate-shortcut',
						title: D.brand || __( 'Visigate', 'visigate' ),
						icon: 'visibility',
					},
						el( 'p', null, __( 'Manage visibility rules for pattern placements on this page.', 'visigate' ) ),
						el( OpenVisigateButton )
					) : null
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
			var classes = [];
			if ( hasRules( props.attributes ) ) {
				classes.push( 'visigate-has-rules' );
			}
			var core = coreHidden( props.attributes );
			if ( core ) {
				classes.push( 'visigate-core-hidden' );
			}
			if ( classes.length ) {
				var wrapperProps = props.wrapperProps;
				if ( core ) {
					wrapperProps = Object.assign( {}, wrapperProps, { title: coreHiddenText( core ) } );
				}
				props = Object.assign( {}, props, {
					className: ( props.className ? props.className + ' ' : '' ) + classes.join( ' ' ),
					wrapperProps: wrapperProps,
				} );
			}
			return el( BlockListBlock, props );
		};
	}, 'withVisigateIndicator' ) );
}( window.wp ) );
