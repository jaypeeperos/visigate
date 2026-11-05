/* Pattern placement editor contract: node tests/pattern-sidebar.cjs */
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( __dirname + '/../assets/js/editor.js', 'utf8' );

function boot( canManage = true, legacy = false ) {
	const filters = {}, plugins = {}, writes = [];
	let selected = null, states = [], effects = [], dependencies = [], cursor = 0;
	const synced = ( clientId ) => ( { clientId, name: 'core/block', attributes: { ref: 44 }, innerBlocks: [
		{ clientId: 'shared-source', name: 'core/block', attributes: { ref: 55 } },
	] } );
	const blocks = [ synced( 'placement-a' ), synced( 'placement-b' ), {
		clientId: 'unsynced', name: 'core/group', attributes: { metadata: { patternName: 'theme/book', name: 'Book promotion' } },
		innerBlocks: [ { clientId: 'book-title', name: 'core/heading', attributes: {} } ],
	}, { clientId: 'ordinary-group', name: 'core/group', attributes: {}, innerBlocks: [] } ];
	const sidebarApi = { PluginSidebar: 'PluginSidebar', PluginSidebarMoreMenuItem: 'PluginSidebarMoreMenuItem' };
	const el = ( type, props, ...children ) => ( { type, props: { ...props, children } } );
	const wp = {
		element: {
			createElement: el, Fragment: 'Fragment',
			useState( initial ) { const index = cursor++; if ( ! ( index in states ) ) states[index] = initial; return [states[index], ( value ) => { states[index] = value; }]; },
			useEffect( callback, deps ) {
				const index = cursor++;
				if ( ! dependencies[index] || deps.some( ( value, i ) => value !== dependencies[index][i] ) ) { dependencies[index] = deps; effects.push( callback ); }
			},
		},
		hooks: {
			addFilter( hook, name, callback ) { filters[hook] = callback; },
			applyFilters( hook, value, props ) { return hook === 'visigate.panel.extras' ? [ { proFallbackFor: props.clientId } ] : value; },
		},
		compose: { createHigherOrderComponent: ( factory ) => factory },
		blockEditor: { InspectorControls: 'InspectorControls' },
		components: { PanelBody: 'PanelBody', SelectControl: 'SelectControl' },
		i18n: { __: ( text ) => text },
		plugins: { registerPlugin( name, config ) { plugins[name] = config; } },
		data: {
			useSelect( callback ) { return callback( () => ( {
				getBlocks: () => blocks,
				getSelectedBlockClientId: () => selected,
				getBlockParents: () => selected === 'book-title' ? [ 'unsynced' ] : [],
			} ) ); },
			dispatch( store ) { assert.equal( store, 'core/block-editor' ); return {
				updateBlockAttributes( id, attributes ) { writes.push( { id, attributes } ); Object.assign( blocks.find( ( block ) => block.clientId === id ).attributes, attributes ); },
			}; },
		},
		...( legacy ? { editPost: sidebarApi } : { editor: sidebarApi } ),
	};
	vm.runInNewContext( source, { window: { wp, VisigateData: { sets: [], canManage }, VisigateBuilder: 'Builder' } } );
	function render() {
		cursor = 0;
		const plugin = plugins['visigate-pattern-visibility'].render();
		const component = plugin.props.children[1];
		const tree = component.type();
		for ( const effect of effects.splice(0) ) effect();
		return { tree, picker: tree.props.children[0].props.children[0], panel: tree.props.children[1] };
	}
	return { render, filters, plugins, writes, blocks, select: ( id ) => { selected = id; } };
}

for ( const legacy of [false, true] ) {
	const app = boot( true, legacy );
	let view = app.render();
	assert.equal( view.panel, null, 'No selected block must not apply rules to an arbitrary pattern' );
	assert.equal( view.picker.props.options.length, 4, 'Offer both synced placements and the unsynced pattern, excluding shared source contents' );
	view.picker.props.onChange( 'placement-a' );
	view = app.render();
	assert.equal( view.panel.props.clientId, 'placement-a', 'Select a placement without entering pattern edit mode' );
	view.panel.props.setAttributes( { visigateSet: 12 } );
	assert.equal( app.writes[0].id, 'placement-a' );
	assert.equal( app.blocks[0].attributes.ref, 44, 'Keep the synced source reference' );
	assert.equal( app.blocks[1].attributes.visigateSet, undefined, 'Other uses of the same synced pattern must remain independent' );
	const panel = view.panel.type( view.panel.props );
	assert.equal( panel.props.initialOpen, true );
	assert.ok( panel.props.children.some( ( child ) => child?.props?.children?.includes( 'Custom rules for this pattern' ) ) );
	assert.equal( panel.props.children.at(-1)[0].proFallbackFor, 'placement-a', 'Reuse Pro panel extras on the wrapper' );
	view.picker.props.onChange( 'placement-b' );
	app.render().panel.props.setAttributes( { visigateRules: { action: 'hide', groups: [] } } );
	assert.equal( app.writes[1].id, 'placement-b' );
	app.select( 'book-title' );
	app.render();
	view = app.render();
	assert.equal( view.panel.props.clientId, 'unsynced', 'Selecting content automatically targets its local pattern wrapper' );
	view.panel.props.setAttributes( { visigateSet: 15 } );
	assert.equal( app.blocks[2].innerBlocks[0].attributes.visigateSet, undefined, 'Do not write rules into the pattern content' );
	app.select( 'ordinary-group' );
	app.render();
	assert.equal( app.render().panel, null, 'Ordinary groups must not be treated as pattern placements' );
}
const restricted = boot( false );
assert.equal( Object.keys( restricted.plugins ).length, 0, 'Client lock must also hide the pattern sidebar' );
const settings = restricted.filters['blocks.registerBlockType']( { attributes: { ref: { type: 'number' } } } );
assert.ok( settings.attributes.ref && settings.attributes.visigateRules, 'Restricted editors still preserve saved attributes' );
console.log( 'PASS: modern/legacy pattern sidebar, no-selection picker, independent synced placements, unsynced ancestors and Pro/client-lock integration.' );
