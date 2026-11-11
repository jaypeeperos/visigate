/** Native atomic control: typed props, reactive updates and Elementor undo history. */
( function ( wp, v2 ) {
	'use strict';
	if ( ! v2 || ! v2.editorEditingPanel || ! v2.editorControls || ! v2.editorProps ) {
		return;
	}
	var el = wp.element.createElement;
	var controls = v2.editorControls;
	var stringProp = v2.editorProps.stringPropTypeUtil;

	function parse( value ) {
		try { return JSON.parse( value || 'null' ); } catch ( e ) { return null; }
	}

	function RulesControl( props ) {
		var bound = controls.useBoundProp( stringProp );
		var serialized = bound.value || '';
		var draft = wp.element.useState( function () { return parse( serialized ); } );
		wp.element.useEffect( function () {
			draft[ 1 ]( parse( serialized ) );
		}, [ serialized, props.context.elementId ] );
		return el( 'div', { className: 'vg-elementor-rules' },
			el( 'p', { className: 'vg-panel-title' }, props.label ),
			el( 'fieldset', { className: 'vg-elementor-builder', disabled: bound.disabled, style: { minWidth: 0, border: 0 } },
				el( window.VisigateBuilder, {
					value: draft[ 0 ],
					onChange: function ( rules ) {
						draft[ 1 ]( rules );
						bound.setValue( rules && ( rules.always_hide || ( rules.groups && rules.groups.length ) ) ? JSON.stringify( rules ) : '' );
					},
				} )
			)
		);
	}

	v2.editorEditingPanel.controlsRegistry.register(
		'visigate-atomic-rules', controls.createControl( RulesControl ), 'custom', stringProp
	);
}( window.wp, window.elementorV2 ) );
