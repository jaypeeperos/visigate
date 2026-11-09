/** Custom visibility conditions for classic menu items, including newly added items. */
( function ( wp ) {
	'use strict';
	var __ = wp.i18n ? wp.i18n.__ : function ( s ) { return s; };

	function count( rules ) {
		var n = 0;
		( ( rules && rules.groups ) || [] ).forEach( function ( g ) { n += ( g.conditions || [] ).length; } );
		return n;
	}

	function modeOf( value ) {
		return '0' === value ? 'always' : ( '-1' === value ? 'custom' : 'set' );
	}

	/** Small status label in the collapsed item bar, so rules are visible without opening each item. */
	function badge( fields, select, rules ) {
		var li = fields.closest ? fields.closest( 'li.menu-item' ) : null;
		var controls = li ? li.querySelector( '.item-controls' ) : null;
		if ( ! controls ) { return; }
		var tag = controls.querySelector( '.visigate-menu-badge' );
		var mode = modeOf( select.value );
		var text = '';
		var warn = false;
		if ( 'custom' === mode && count( rules ) ) {
			text = __( 'Custom visibility', 'visigate' );
		} else if ( 'set' === mode ) {
			var opt = select.options[ select.selectedIndex ];
			text = opt ? opt.text : '';
			warn = text.indexOf( ' — ' ) > -1; // Unpublished set: the item is hidden.
		}
		if ( ! text ) {
			if ( tag ) { tag.remove(); }
			return;
		}
		if ( ! tag ) {
			tag = document.createElement( 'span' );
			tag.className = 'visigate-menu-badge';
			controls.insertBefore( tag, controls.firstChild );
		}
		tag.textContent = text;
		tag.title = __( 'Visibility:', 'visigate' ) + ' ' + text;
		tag.classList.toggle( 'visigate-menu-badge--warn', warn );
	}

	function mount( fields ) {
		if ( fields.visigateMounted || ! window.VisigateBuilder ) { return; }
		var select = fields.querySelector( 'select[name^="visigate_menu_set"]' );
		var custom = fields.querySelector( '.visigate-menu-custom' );
		var input = fields.querySelector( '.visigate-menu-rules-json' );
		var target = fields.querySelector( '.visigate-menu-builder' );
		if ( ! select || ! custom || ! input || ! target ) { return; }
		fields.visigateMounted = true;
		var initial = null;
		try { initial = JSON.parse( input.value ); } catch ( e ) {}
		var current = window.VisigateBuilderUtils.normalize( initial );

		function sync() {
			var mode = modeOf( select.value );
			custom.hidden = 'custom' !== mode;
			if ( fields.setAttribute ) { fields.setAttribute( 'data-mode', mode ); }
			var helps = fields.querySelectorAll ? fields.querySelectorAll( '.visigate-menu-help' ) : [];
			Array.prototype.forEach.call( helps, function ( p ) { p.hidden = p.getAttribute( 'data-for' ) !== mode; } );
			var link = fields.querySelector( '.visigate-menu-edit-set' );
			var base = fields.getAttribute ? fields.getAttribute( 'data-edit-url' ) : '';
			if ( link && base && 'set' === mode ) { link.href = base + encodeURIComponent( select.value ); }
			badge( fields, select, current );
		}

		function App() {
			var state = wp.element.useState( current );
			return wp.element.createElement( window.VisigateBuilder, {
				value: state[ 0 ],
				onChange: function ( rules ) {
					current = rules;
					input.value = JSON.stringify( rules );
					state[ 1 ]( rules );
					badge( fields, select, rules );
				},
			} );
		}
		// Initialize an empty payload even when no condition has been added yet.
		input.value = JSON.stringify( current );
		select.addEventListener( 'change', sync );
		sync();
		if ( wp.element.createRoot ) {
			wp.element.createRoot( target ).render( wp.element.createElement( App ) );
		} else {
			wp.element.render( wp.element.createElement( App ), target );
		}
	}
	function scan() { document.querySelectorAll( '.visigate-menu-fields' ).forEach( mount ); }
	function start() {
		scan();
		var menu = document.getElementById( 'menu-to-edit' );
		if ( menu ) { new MutationObserver( scan ).observe( menu, { childList: true, subtree: true } ); }
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', start ); } else { start(); }
}( window.wp ) );
