/**
 * Visigate — asks for confirmation before a rule set that is in use is trashed or deleted.
 * Content that uses a missing or unpublished rule set is hidden (fails closed).
 */
( function () {
	'use strict';
	var data = window.VisigateUsage || { counts: {}, postId: 0 };
	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( s ) { return s; };
	var sprintf = i18n ? i18n.sprintf : function ( s, n ) { return s.replace( '%d', n ); };

	function count( id ) {
		return parseInt( data.counts[ id ] || 0, 10 );
	}

	function confirmFor( ids ) {
		var total = 0, sets = 0;
		ids.forEach( function ( id ) {
			var n = count( id );
			if ( n ) { total += n; sets++; }
		} );
		if ( ! total ) {
			return true;
		}
		var msg = sets > 1
			/* translators: %d: number of places. */
			? sprintf( __( 'The selected rule sets are used in %d places.', 'visigate' ), total )
			/* translators: %d: number of places. */
			: sprintf( __( 'This rule set is used in %d places.', 'visigate' ), total );
		msg += '\n\n' + __( 'Content that uses a deleted or trashed rule set is hidden from everyone until you choose another rule set. Continue?', 'visigate' );
		return window.confirm( msg );
	}

	function rowId( el ) {
		var row = el.closest( 'tr[id^="post-"]' );
		return row ? parseInt( row.id.replace( 'post-', '' ), 10 ) : 0;
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) {
			return;
		}
		var link = e.target.closest( 'a.submitdelete' );
		if ( link ) {
			var id = rowId( link ) || data.postId;
			if ( id && ! confirmFor( [ id ] ) ) {
				e.preventDefault();
				e.stopImmediatePropagation();
			}
			return;
		}
		var btn = e.target.closest( '#doaction, #doaction2' );
		if ( btn ) {
			var select = document.getElementById( 'doaction2' === btn.id ? 'bulk-action-selector-bottom' : 'bulk-action-selector-top' );
			var action = select ? select.value : '';
			if ( 'trash' !== action && 'delete' !== action ) {
				return;
			}
			var ids = Array.prototype.map.call( document.querySelectorAll( 'input[name="post[]"]:checked' ), function ( cb ) {
				return parseInt( cb.value, 10 );
			} );
			if ( ! confirmFor( ids ) ) {
				e.preventDefault();
			}
		}
	}, true );
}() );
