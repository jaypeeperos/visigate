/* Standalone control lifecycle checks: node tests/elementor-control.cjs */
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

for ( const modern of [ true, false ] ) {
	let init, nativeInit, Control, rendered, registrations = 0, unmounted = false;
	const mount = {};
	const Base = {
		extend( methods ) { return methods; },
		prototype: { onAfterExternalChange() {} },
	};
	const element = {
		createElement: ( type, props ) => ( { type, props } ),
		render( tree ) { rendered = tree; },
		unmountComponentAtNode( node ) { assert.equal( node, mount ); unmounted = true; },
	};
	if ( modern ) {
		element.createRoot = ( node ) => {
			assert.equal( node, mount );
			return { render: element.render, unmount() { unmounted = true; } };
		};
	}
	const window = {
		wp: { element },
		addEventListener( event, callback ) { assert.equal( event, 'elementor/init' ); nativeInit = callback; },
		jQuery: () => ( { on( event, callback ) { assert.equal( event, 'elementor:init' ); init = callback; } } ),
		VisigateBuilder: function Builder() {},
		elementor: {
			modules: { controls: { BaseData: Base } },
			addControlView( type, control ) { assert.equal( type, 'visigate_rules' ); Control = control; registrations++; },
		},
	};
	vm.runInNewContext( fs.readFileSync( __dirname + '/../assets/js/elementor.js', 'utf8' ), { window } );
	assert.ok( Control, 'Register immediately even if Elementor init already fired' );
	nativeInit();
	init();
	assert.equal( registrations, 1, 'Native and legacy events must not duplicate registration' );
	let saved = '';
	const view = Object.assign( {}, Control, {
		el: { querySelector: () => mount },
		getControlValue: () => saved,
		setValue( value ) { saved = value; },
	} );
	view.onReady();
	assert.equal( rendered.type, window.VisigateBuilder );
	assert.equal( rendered.props.value, null );
	// Selecting Hide before adding conditions must retain the draft action.
	rendered.props.onChange( { action: 'hide', groups: [] } );
	assert.equal( saved, '' );
	assert.equal( rendered.props.value.action, 'hide' );
	const rules = { action: 'hide', groups: [ { conditions: [ { type: 'logged_in', op: 'is' } ] } ] };
	rendered.props.onChange( rules );
	assert.deepEqual( JSON.parse( saved ), rules );
	// Reopening the panel reloads the serialized settings.
	view.onBeforeDestroy();
	assert.ok( unmounted );
	view.onReady();
	assert.equal( JSON.stringify( rendered.props.value ), JSON.stringify( rules ) );
	// Undo/paste refreshes the builder without writing settings again.
	saved = '';
	view.onAfterExternalChange();
	assert.equal( rendered.props.value, null );
	assert.equal( saved, '' );
	saved = '{invalid';
	view.onAfterExternalChange();
	assert.equal( rendered.props.value, null );
	view.onBeforeDestroy();
}

// Deferred editor startup must register through the current native event too.
let deferred, registered = false;
const deferredWindow = {
	wp: {},
	addEventListener( event, callback ) { deferred = callback; },
	jQuery: () => ( { on() {} } ),
};
vm.runInNewContext( fs.readFileSync( __dirname + '/../assets/js/elementor.js', 'utf8' ), { window: deferredWindow } );
deferredWindow.elementor = {
	modules: { controls: { BaseData: { extend: ( methods ) => methods } } },
	addControlView() { registered = true; },
};
deferred();
assert.ok( registered, 'Register when Elementor starts after the script loads' );
console.log( 'PASS: immediate/deferred registration, native/legacy events, React mounting, JSON persistence and cleanup.' );
