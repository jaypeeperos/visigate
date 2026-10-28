/** Mounts the visual builder on the Rule Set edit screen. */
( function ( wp ) {
	'use strict';
	document.addEventListener( 'DOMContentLoaded', function () {
		var mount = document.getElementById( 'visigate-builder' );
		var input = document.getElementById( 'visigate-rules-json' );
		if ( ! mount || ! input || ! window.VisigateBuilder ) {
			return;
		}
		var initial = null;
		try { initial = JSON.parse( input.value || 'null' ); } catch ( e ) {}

		function App() {
			var st = wp.element.useState( window.VisigateBuilderUtils.normalize( initial ) );
			return wp.element.createElement( window.VisigateBuilder, {
				value: st[ 0 ],
				onChange: function ( v ) {
					st[ 1 ]( v );
					input.value = JSON.stringify( v );
				},
			} );
		}

		if ( wp.element.createRoot ) {
			wp.element.createRoot( mount ).render( wp.element.createElement( App ) );
		} else {
			wp.element.render( wp.element.createElement( App ), mount );
		}
	} );
}( window.wp ) );
