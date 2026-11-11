/** Connect the shared rule builder to Elementor's settings and undo history. */
( function ( wp, $ ) {
	'use strict';
	var registered = false;

	function register() {
		if ( registered || ! window.elementor || ! window.elementor.modules || ! window.elementor.modules.controls || ! window.elementor.modules.controls.BaseData ) {
			return;
		}
		var Base = window.elementor.modules.controls.BaseData;
		var RulesControl = Base.extend( {
			onReady: function () {
				this.mount = this.el.querySelector( '.vg-elementor-builder' );
				// createRoot ships with WordPress 6.2+ (React 18), the plugin's minimum.
				this.root = this.mount ? wp.element.createRoot( this.mount ) : null;
				this.renderBuilder();
			},

			renderBuilder: function ( draft ) {
				if ( ! this.root ) {
					return;
				}
				var view = this;
				var value = draft === undefined ? this.getControlValue() : draft;
				if ( typeof value === 'string' ) {
					try { value = JSON.parse( value || 'null' ); } catch ( e ) { value = null; }
				}
				var builder = wp.element.createElement( window.VisigateBuilder, {
					value: value,
					onChange: function ( rules ) {
						view.setValue( rules && ( rules.always_hide || ( rules.groups && rules.groups.length ) ) ? JSON.stringify( rules ) : '' );
						view.renderBuilder( rules );
					},
				} );
				this.root.render( builder );
			},

			onAfterExternalChange: function () {
				Base.prototype.onAfterExternalChange.apply( this, arguments );
				this.renderBuilder();
			},

			onBeforeDestroy: function () {
				if ( this.root ) {
					this.root.unmount();
				}
				this.root = null;
				this.mount = null;
			},
		} );
		window.elementor.addControlView( 'visigate_rules', RulesControl );
		registered = true;
	}

	// Elementor 4 uses native events. Register immediately when the dependency
	// has loaded, and retain both events for older or deferred editor startup.
	window.addEventListener( 'elementor/init', register );
	$( window ).on( 'elementor:init', register );
	register();
}( window.wp, window.jQuery ) );
