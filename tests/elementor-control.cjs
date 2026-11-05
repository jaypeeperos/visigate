/* Standalone control lifecycle checks: node tests/elementor-control.cjs */
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

for ( const modern of [ true, false ] ) {
	let init, Control, rendered, unmounted = false;
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
		jQuery: () => ( { on( event, callback ) { assert.equal( event, 'elementor:init' ); init = callback; } } ),
		VisigateBuilder: function Builder() {},
		elementor: {
			modules: { controls: { BaseData: Base } },
			addControlView( type, control ) { assert.equal( type, 'visigate_rules' ); Control = control; },
		},
	};
	vm.runInNewContext( fs.readFileSync( __dirname + '/../assets/js/elementor.js', 'utf8' ), { window } );
	init();
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
console.log( 'PASS: modern/legacy React mounting, JSON persistence, drafts, external changes and cleanup.' );
