/* Classic menu builder lifecycle: node tests/menus.cjs */
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
for ( const modern of [ true, false ] ) {
	const roots = [];
	const fields = [];
	let observe;
	function item( value, rules ) {
		const select = { value, addEventListener( name, cb ) { this.change = cb; } };
		const custom = { hidden: false };
		const input = { value: JSON.stringify( rules ) };
		const target = {};
		const nodes = { 'select[name^="visigate_menu_set"]': select, '.visigate-menu-custom': custom, '.visigate-menu-rules-json': input, '.visigate-menu-builder': target };
		const field = { querySelector: ( selector ) => nodes[ selector ] };
		fields.push( field );
		return { field, select, custom, input, target };
	}
	const saved = { action: 'hide', groups: [ { conditions: [ { type: 'logged_in', op: 'is' } ] } ] };
	const a = item( '-1', saved );
	const b = item( '55', [] );
	const render = ( app, target ) => { roots.push( target ); target.builder = app.type( app.props ); };
	const element = {
		createElement: ( type, props ) => ( { type, props } ),
		useState: ( initial ) => [ initial, () => {} ],
		render,
	};
	if ( modern ) { element.createRoot = ( target ) => ( { render: ( app ) => render( app, target ) } ); }
	vm.runInNewContext( fs.readFileSync( __dirname + '/../assets/js/menus.js', 'utf8' ), {
		window: { wp: { element }, VisigateBuilder: 'Builder', VisigateBuilderUtils: { normalize: ( v ) => v && Array.isArray( v.groups ) ? v : { action: 'show', groups: [] } } },
		document: { readyState: 'complete', querySelectorAll: () => fields, getElementById: () => ( {} ) },
		MutationObserver: class { constructor( cb ) { observe = cb; } observe() {} },
	} );
	assert.equal( roots.length, 2 );
	assert.equal( a.custom.hidden, false );
	assert.equal( b.custom.hidden, true );
	assert.equal( JSON.stringify( a.target.builder.props.value ), JSON.stringify( saved ) );
	const edited = { action: 'show', groups: saved.groups };
	a.target.builder.props.onChange( edited );
	assert.equal( a.input.value, JSON.stringify( edited ) );
	assert.equal( b.input.value, '{"action":"show","groups":[]}' );
	a.select.value = '0'; a.select.change();
	assert.equal( a.custom.hidden, true );
	a.select.value = '-1'; a.select.change();
	assert.equal( a.custom.hidden, false );
	assert.equal( a.input.value, JSON.stringify( edited ), 'Switching modes retains the draft until saved' );
	const c = item( '-1', [] );
	observe(); observe();
	assert.equal( roots.length, 3, 'Newly added items mount once; existing items retain their builders' );
	assert.equal( c.custom.hidden, false );
}
// Mode-aware help, edit link and collapsed-bar badge.
{
	const attrs = { 'data-edit-url': 'http://x/wp-admin/post.php?action=edit&post=' };
	const helps = [ 'always', 'custom', 'set' ].map( ( mode ) => ( { hidden: false, getAttribute: () => mode } ) );
	const link = { href: '' };
	const children = [];
	const controls = {
		get firstChild() { return children[ 0 ] || null; },
		insertBefore( node ) { children.unshift( node ); node.remove = () => children.splice( children.indexOf( node ), 1 ); },
		querySelector: () => children[ 0 ] || null,
	};
	const options = [ { text: 'Always show' }, { text: 'Custom conditions' }, { text: 'Members' }, { text: 'Draft set — not published, item hidden' } ];
	const values = [ '0', '-1', '12', '13' ];
	const select = {
		value: '0', get selectedIndex() { return values.indexOf( this.value ); }, options,
		addEventListener( name, cb ) { this.change = cb; },
	};
	const nodes = {
		'select[name^="visigate_menu_set"]': select, '.visigate-menu-custom': { hidden: true },
		'.visigate-menu-rules-json': { value: '{"action":"show","groups":[]}' }, '.visigate-menu-builder': {}, '.visigate-menu-edit-set': link,
	};
	const field = {
		querySelector: ( s ) => nodes[ s ], querySelectorAll: () => helps,
		setAttribute: ( k, v ) => { attrs[ k ] = v; }, getAttribute: ( k ) => attrs[ k ],
		closest: () => ( { querySelector: () => controls } ),
	};
	let root;
	const element = { createElement: ( type, props ) => ( { type, props } ), useState: ( v ) => [ v, () => {} ], createRoot: () => ( { render: ( app ) => { root = app.type( app.props ); } } ) };
	const doc = {
		readyState: 'complete', querySelectorAll: () => [ field ], getElementById: () => null,
		createElement: () => { const n = { className: '', textContent: '', title: '', classList: { on: false, toggle( c, v ) { this.on = v; } } }; return n; },
	};
	vm.runInNewContext( fs.readFileSync( __dirname + '/../assets/js/menus.js', 'utf8' ), {
		window: { wp: { element }, VisigateBuilder: 'Builder', VisigateBuilderUtils: { normalize: ( v ) => v && Array.isArray( v.groups ) ? v : { action: 'show', groups: [] } } },
		document: doc, MutationObserver: class { observe() {} }, encodeURIComponent,
	} );
	const shown = () => helps.filter( ( h ) => ! h.hidden ).map( ( h ) => h.getAttribute() ).join();
	assert.equal( shown(), 'always' );
	assert.equal( attrs[ 'data-mode' ], 'always' );
	assert.equal( children.length, 0, 'No badge when always shown' );
	select.value = '-1'; select.change();
	assert.equal( shown(), 'custom' );
	assert.equal( children.length, 0, 'No badge for custom mode without conditions' );
	root.props.onChange( { action: 'show', groups: [ { conditions: [ { type: 'logged_in', op: 'is' } ] } ] } );
	assert.equal( children[ 0 ].textContent, 'Custom visibility', 'Badge appears once a condition exists' );
	select.value = '12'; select.change();
	assert.equal( shown(), 'set' );
	assert.equal( link.href, 'http://x/wp-admin/post.php?action=edit&post=12', 'Edit link follows the chosen set' );
	assert.equal( children[ 0 ].textContent, 'Members' );
	assert.equal( children[ 0 ].classList.on, false );
	select.value = '13'; select.change();
	assert.equal( children[ 0 ].classList.on, true, 'Unpublished sets get a warning badge' );
	select.value = '0'; select.change();
	assert.equal( children.length, 0, 'Badge removed when always shown' );
}
console.log( 'PASS: menu builders preserve independent drafts, switch modes and mount newly added items with modern and legacy React.' );
