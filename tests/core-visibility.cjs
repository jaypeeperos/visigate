/* WordPress core block visibility (metadata.blockVisibility) contract: node tests/core-visibility.cjs */
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( __dirname + '/../assets/js/editor.js', 'utf8' );

const filters = {};
const el = ( type, props, ...children ) => ( { type, props: { ...props, children } } );
const wp = {
	element: { createElement: el, Fragment: 'Fragment', useState: ( v ) => [ v, () => {} ], useEffect() {} },
	hooks: { addFilter( hook, name, cb ) { filters[ hook ] = cb; }, applyFilters: ( hook, value ) => value },
	compose: { createHigherOrderComponent: ( f ) => f },
	blockEditor: { InspectorControls: 'InspectorControls' },
	components: { PanelBody: 'PanelBody', SelectControl: 'SelectControl', Button: 'Button', Notice: 'Notice' },
	i18n: { __: ( t ) => t, sprintf: ( t, v ) => t.replace( '%s', v ) },
};
vm.runInNewContext( source, { window: { wp, VisigateData: { sets: [], canManage: true }, VisigateBuilder: 'Builder' } } );

function panelFor( attributes ) {
	const writes = [];
	const edit = filters['editor.BlockEdit']( 'BlockEdit' )( { name: 'core/paragraph', isSelected: true, attributes, setAttributes: ( a ) => writes.push( a ) } );
	const panelEl = edit.props.children[1].props.children[0];
	const panel = panelEl.type( panelEl.props );
	const noticeEl = panel.props.children[0];
	return { panel, notice: noticeEl.type( noticeEl.props ), writes };
}
const text = ( notice ) => notice.props.children[0].props.children.join( '' );
const indicator = ( attributes, wrapperProps ) => filters['editor.BlockListBlock']( 'BlockListBlock' )( { attributes, className: 'x', wrapperProps } ).props;

// No core visibility: no notice, panel closed, no indicator.
let r = panelFor( {} );
assert.equal( r.notice, null );
assert.equal( r.panel.props.initialOpen, false );
assert.equal( indicator( {} ).className, 'x' );

// Hidden everywhere (WordPress 6.9).
r = panelFor( { metadata: { blockVisibility: false, name: 'Hero' } } );
assert.equal( r.panel.props.initialOpen, true, 'Open the panel so the core state is visible' );
assert.equal( r.notice.props.status, 'warning' );
assert.match( text( r.notice ), /Hidden for everyone/ );
r.notice.props.children[1].props.onClick();
assert.equal( JSON.stringify( r.writes[0] ), '{"metadata":{"name":"Hero"}}', 'Clearing keeps other metadata' );

// Hidden on some devices (WordPress 7.0).
r = panelFor( { metadata: { blockVisibility: { viewport: { mobile: false, tablet: true, desktop: true } } } } );
assert.equal( r.notice.props.status, 'info' );
assert.match( text( r.notice ), /Hidden by WordPress on mobile\./ );
r.notice.props.children[1].props.onClick();
assert.ok( 'metadata' in r.writes[0] && r.writes[0].metadata === undefined, 'Empty metadata is removed entirely' );

// All three devices hidden counts as hidden everywhere.
r = panelFor( { metadata: { blockVisibility: { viewport: { mobile: false, tablet: false, desktop: false } } } } );
assert.equal( r.notice.props.status, 'warning' );

// All devices visible, or unknown shapes, are ignored.
assert.equal( panelFor( { metadata: { blockVisibility: { viewport: { mobile: true } } } } ).notice, null );
assert.equal( panelFor( { metadata: { blockVisibility: true } } ).notice, null );

// Canvas indicator.
let p = indicator( { metadata: { blockVisibility: { viewport: { tablet: false, desktop: false } } } }, { 'data-a': 1 } );
assert.equal( p.className, 'x visigate-core-hidden' );
assert.equal( p.wrapperProps.title, 'Hidden by WordPress on tablet, desktop.' );
assert.equal( p.wrapperProps['data-a'], 1, 'Keep existing wrapper props' );
p = indicator( { visigateSet: 3, metadata: { blockVisibility: false } } );
assert.equal( p.className, 'x visigate-has-rules visigate-core-hidden' );
p = indicator( { visigateSet: 3 }, { 'data-a': 1 } );
assert.equal( p.className, 'x visigate-has-rules' );
assert.equal( p.wrapperProps.title, undefined, 'No tooltip for Visigate-only blocks' );

console.log( 'PASS: core Hide block and per-device visibility shown in the panel, clearable, and marked on the canvas.' );
