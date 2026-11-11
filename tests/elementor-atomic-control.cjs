/* Atomic prop and editor lifecycle contract: node tests/elementor-atomic-control.cjs */
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( __dirname + '/../assets/js/elementor-atomic.js', 'utf8' );
let Component, rawValue = null, disabled = false;
let states = [], dependencies = [], effects = [], cursor = 0;
const stringProp = {
	create: ( value ) => ( { $$type: 'string', value } ),
	extract: ( prop ) => prop?.value ?? null,
};
const wp = {
	element: {
		createElement: ( type, props, ...children ) => ( { type, props: { ...props, children } } ),
		useState( initial ) {
			const index = cursor++;
			if ( ! ( index in states ) ) states[ index ] = typeof initial === 'function' ? initial() : initial;
			return [ states[ index ], ( value ) => { states[ index ] = value; } ];
		},
		useEffect( callback, deps ) {
			const index = cursor++;
			if ( ! dependencies[ index ] || deps.some( ( value, i ) => value !== dependencies[ index ][ i ] ) ) {
				dependencies[ index ] = deps;
				effects.push( callback );
			}
		},
	},
};
const window = {
	wp,
	VisigateBuilder: function Builder() {},
	elementorV2: {
		editorProps: { stringPropTypeUtil: stringProp },
		editorControls: {
			createControl: ( component ) => component,
			useBoundProp( util ) {
				assert.equal( util, stringProp );
				return { value: util.extract( rawValue ), disabled, setValue: ( value ) => { rawValue = util.create( value ); } };
			},
		},
		editorEditingPanel: {
			controlsRegistry: {
				register( name, component, layout, util ) {
					assert.equal( name, 'visigate-atomic-rules' );
					assert.equal( layout, 'custom' );
					assert.equal( util, stringProp );
					Component = component;
				},
			},
		},
	},
};
vm.runInNewContext( source, { window } );

function render( elementId = 'heading-1' ) {
	cursor = 0;
	const tree = Component( { label: 'Custom rules for this element', context: { elementId } } );
	for ( const effect of effects.splice( 0 ) ) effect();
	return tree.props.children[1];
}
function builder( elementId ) { return render( elementId ).props.children[0]; }

assert.equal( builder().props.value, null );
builder().props.onChange( { action: 'hide', groups: [] } );
assert.equal( builder().props.value.action, 'hide', 'Preserve Hide draft before adding a condition' );
assert.equal( rawValue.value, '' );
const rules = { action: 'hide', groups: [ { conditions: [ { type: 'logged_in', op: 'is' } ] } ] };
const always = { action: 'show', groups: [], always_hide: true };
builder().props.onChange( always );
assert.deepEqual( JSON.parse( rawValue.value ), always, 'Atomic Always hide persists without conditions' );
builder().props.onChange( rules );
assert.equal( rawValue.$$type, 'string' );
assert.deepEqual( JSON.parse( rawValue.value ), rules );
assert.equal( JSON.stringify( builder().props.value ), JSON.stringify( rules ) );
// External undo/redo updates are applied by the prop effect on the next render.
rawValue = stringProp.create( '' );
render();
assert.equal( builder().props.value, null );
rawValue = stringProp.create( JSON.stringify( rules ) );
render();
assert.equal( JSON.stringify( builder().props.value ), JSON.stringify( rules ) );
// Switching to a different element must not retain the previous draft.
rawValue = null;
render( 'flexbox-2' );
assert.equal( builder( 'flexbox-2' ).props.value, null );
disabled = true;
assert.equal( render( 'flexbox-2' ).props.disabled, true );
// Classic-only editors have no atomic registry and must still load safely.
vm.runInNewContext( source, { window: { wp } } );
console.log( 'PASS: atomic registration, typed writes, drafts, external changes, element switching and disabled controls.' );
