/**
 * Visigate admin screens: small behaviours shared by the Overview, Rule sets, Trash, Where used,
 * Cache check, View As and Settings screens. Loaded only on Visigate screens (see Visigate_Admin_UI).
 *
 * - Moves admin notices and Screen Options above the Visigate header.
 * - "Select all" checkboxes (.vg-check-all) toggle the ids[] boxes of the same form.
 * - Elements with data-vg-confirm ask before going ahead (optionally only for one bulk op).
 * - View As: "Open this page" refreshes this screen after the new tab opens; the rule inspector
 *   loads the chosen page in the background and lists every rule check.
 */
( function ( $ ) {
	'use strict';

	var i18n = window.VisigateAdminUI || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	/* ---------------------- notices and Screen Options ---------------------- */

	$( function () {
		var head = $( '.vg-head-wrap > .vg-head' ).first();
		if ( ! head.length ) {
			return;
		}
		$( '#wpbody-content' )
			.find( 'div.updated, div.error, div.notice, div.update-nag' )
			.filter( function () {
				var n = $( this );
				return ! n.is( '.below-h2, .vg-keep' ) && ( ! n.is( '.inline' ) || n.is( '.update-nag' ) ) && ! n.closest( '.vg-card' ).length;
			} )
			.each( function () {
				$( this ).addClass( 'vg-moved-notice' ).insertBefore( head );
			} );
		$( '#screen-meta' ).insertBefore( head );
		$( '#screen-meta-links' ).insertBefore( head );
	} );

	/* ----------------------------- lists ----------------------------- */

	document.addEventListener( 'change', function ( e ) {
		var all = e.target;
		if ( ! all.classList || ! all.classList.contains( 'vg-check-all' ) ) {
			return;
		}
		var scope = all.closest( 'form' ) || document;
		scope.querySelectorAll( 'input[name="ids[]"]' ).forEach( function ( box ) {
			box.checked = all.checked;
		} );
	} );

	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest ? e.target.closest( '[data-vg-confirm]' ) : null;
		if ( ! el ) {
			return;
		}
		var only = el.getAttribute( 'data-vg-confirm-op' );
		if ( only ) {
			var sel = el.form && el.form.querySelector( 'select[name="op"]' );
			if ( ! sel || sel.value !== only ) {
				return;
			}
		}
		if ( ! window.confirm( el.getAttribute( 'data-vg-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	/* ----------------------------- View As ----------------------------- */

	$( function () {
		var open = document.querySelector( '.vg-va-open' );
		var page = document.getElementById( 'vg-va-return' );
		if ( open && page && open.getAttribute( 'data-vg-reload' ) ) {
			open.addEventListener( 'click', function () {
				var base = open.getAttribute( 'data-vg-reload' );
				// The preview starts in the new tab; then this screen shows the Stop button and the inspector.
				window.setTimeout( function () {
					window.location.href = base + '&inspect=1&return=' + encodeURIComponent( page.value );
				}, 1200 );
			} );
		}

		var box = document.querySelector( '.vg-inspector[data-url]' );
		var url = box && box.getAttribute( 'data-url' );
		if ( ! url ) {
			return;
		}
		var body = box.querySelector( '.vg-inspector-body' );
		var counts = box.querySelector( '.vg-inspector-counts' );

		function el( tag, cls, text ) {
			var node = document.createElement( tag );
			if ( cls ) {
				node.className = cls;
			}
			if ( null !== text && undefined !== text ) {
				node.textContent = text;
			}
			return node;
		}
		function fail() {
			body.innerHTML = '';
			body.appendChild( el( 'p', 'vg-inspector-empty', t( 'fail', 'Could not read the rule checks for this page.' ) ) );
		}

		window.fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.text();
			} )
			.then( function ( html ) {
				var doc = new window.DOMParser().parseFromString( html, 'text/html' );
				var node = doc.getElementById( 'visigate-inspector-data' );
				if ( ! node ) {
					fail();
					return;
				}
				var data = JSON.parse( node.textContent || '[]' );
				body.innerHTML = '';
				if ( ! data.length ) {
					body.appendChild( el( 'p', 'vg-inspector-empty', t( 'none', 'No content on this page uses a Visigate rule.' ) ) );
					counts.textContent = '';
					return;
				}
				var shown = 0;
				var hidden = 0;
				data.forEach( function ( entry ) {
					if ( entry.visible ) {
						shown++;
					} else {
						hidden++;
					}
					var row = el( 'div', 'vg-insp-row' + ( entry.visible ? '' : ' is-hidden' ) );
					var head = el( 'div', 'vg-insp-head' );
					head.appendChild( el( 'span', 'vg-mark' + ( entry.visible ? '' : ' is-hidden' ) ) );
					head.appendChild( el( 'strong', 'vg-insp-label', entry.label || entry.kind ) );
					if ( entry.sets && entry.sets.length ) {
						head.appendChild( el( 'span', 'vg-insp-set', entry.sets.join( ', ' ) ) );
					}
					head.appendChild( el( 'span', 'vg-badge ' + ( entry.visible ? 'is-info' : 'is-warn' ), entry.visible ? t( 'shown', 'Shown' ) : t( 'hidden', 'Hidden' ) ) );
					row.appendChild( head );

					var chips = el( 'div', 'vg-chips vg-insp-chips' );
					( entry.parts || [] ).forEach( function ( part ) {
						if ( part.always_hide ) {
							chips.appendChild( el( 'span', 'vg-chip is-warn', t( 'always', 'Always hide is on' ) ) );
							return;
						}
						if ( part.note && ! ( part.groups || [] ).length ) {
							chips.appendChild( el( 'span', 'vg-chip is-muted', part.note ) );
						}
						( part.groups || [] ).forEach( function ( group, gi ) {
							if ( gi > 0 ) {
								chips.appendChild( el( 'span', 'vg-insp-or', t( 'or', 'or' ) ) );
							}
							( group.conditions || [] ).forEach( function ( c ) {
								var cls = null === c.pass ? 'is-muted' : ( c.pass ? 'is-pass' : 'is-fail' );
								var mark = null === c.pass ? '– ' : ( c.pass ? '✓ ' : '✗ ' );
								chips.appendChild( el( 'span', 'vg-chip ' + cls, mark + c.text ) );
							} );
						} );
					} );
					if ( entry.note ) {
						chips.appendChild( el( 'span', 'vg-chip is-muted', entry.note ) );
					}
					row.appendChild( chips );
					body.appendChild( row );
				} );
				counts.textContent = t( 'nShown', '%d shown' ).replace( '%d', shown ) + '  ·  ' + t( 'nHidden', '%d hidden' ).replace( '%d', hidden );
			} )
			.catch( fail );
	} );
}( window.jQuery ) );
