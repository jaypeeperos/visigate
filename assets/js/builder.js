/**
 * Visigate visual rule builder.
 * Plain wp.element (no JSX / no build step). Exposes window.VisigateBuilder.
 *
 * Data model:  { action: 'show'|'hide', groups: [ { conditions: [ {type, op, value, days?, labels?} ] } ] }
 * Groups are OR'd together; conditions inside a group are AND'd.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var comp = wp.components;
	var __ = wp.i18n.__;
	var D = window.VisigateData || { conditions: {}, options: {}, groups: {}, sets: [], isPro: false };
	var TYPES = D.conditions;

	/* ---------------------------- helpers ---------------------------- */

	function clone( o ) {
		return JSON.parse( JSON.stringify( o ) );
	}

	function firstType() {
		var keys = Object.keys( TYPES );
		for ( var i = 0; i < keys.length; i++ ) {
			if ( ! TYPES[ keys[ i ] ].pro || D.isPro ) {
				return keys[ i ];
			}
		}
		return keys[ 0 ];
	}

	function initialValue( s ) {
		switch ( s.input ) {
			case 'tokens':
			case 'async':
				return [];
			case 'time_range':
			case 'date_range':
				return { from: '', to: '' };
			case 'kv':
				return { key: '', value: '' };
			default:
				return '';
		}
	}

	function newCond( type ) {
		type = type || firstType();
		var s = TYPES[ type ];
		return { type: type, op: s.default_op, value: initialValue( s ) };
	}

	function normalize( v ) {
		if ( ! v || ! Array.isArray( v.groups ) ) {
			return { action: 'show', groups: [] };
		}
		return v;
	}

	function typeOptions() {
		var out = Object.keys( TYPES ).map( function ( k ) {
			var s = TYPES[ k ];
			var locked = s.pro && ! D.isPro;
			return {
				value: k,
				label: ( D.groups[ s.group ] || '' ) + ' › ' + s.label + ( locked ? ' (' + __( 'Pro', 'visigate' ) + ')' : '' ),
				disabled: locked,
			};
		} );
		( D.teasers || [] ).forEach( function ( t, i ) {
			out.push( {
				value: 'teaser:' + i,
				label: ( D.groups[ t.group ] || '' ) + ' › ' + t.label + ' (' + __( 'Pro', 'visigate' ) + ')',
				disabled: true,
			} );
		} );
		return out;
	}

	function opsOptions( s ) {
		return Object.keys( s.ops ).map( function ( k ) {
			return { value: k, label: s.ops[ k ] };
		} );
	}

	function Select( p ) {
		return el( comp.SelectControl, {
			label: p.label,
			hideLabelFromVision: true,
			value: p.value,
			options: p.options,
			onChange: p.onChange,
			__nextHasNoMarginBottom: true,
		} );
	}

	function Text( p ) {
		return el( comp.TextControl, {
			label: p.label,
			hideLabelFromVision: ! p.showLabel,
			type: p.type || 'text',
			min: p.min,
			placeholder: p.placeholder,
			value: p.value === undefined || p.value === null ? '' : p.value,
			onChange: p.onChange,
			__nextHasNoMarginBottom: true,
		} );
	}

	/* ------------------------ human-readable summary ------------------------ */

	function describeCond( c ) {
		var s = TYPES[ c.type ];
		if ( ! s ) {
			return '';
		}
		var t = s.label + ' ' + ( s.ops[ c.op ] || '' );
		var v = c.value;
		if ( Array.isArray( v ) ) {
			var opts = D.options[ s.options ] || {};
			t += ' ' + v.map( function ( x ) {
				return opts[ x ] || ( c.labels && c.labels[ x ] ) || x;
			} ).join( ', ' );
		} else if ( v && typeof v === 'object' ) {
			t += ' ' + Object.keys( v ).map( function ( k ) { return v[ k ]; } ).filter( Boolean ).join( ' → ' );
		} else if ( s.input !== 'none' && v !== '' && v !== null && v !== undefined ) {
			t += ' ' + v;
		}
		if ( c.days > 0 ) {
			t += ' (' + c.days + 'd)';
		}
		return t;
	}

	function describe( rules ) {
		var parts = rules.groups.map( function ( g ) {
			return g.conditions.map( describeCond ).join( ' AND ' );
		} ).filter( Boolean );
		if ( ! parts.length ) {
			return __( 'No conditions yet — content is always visible.', 'visigate' );
		}
		return ( rules.action === 'hide' ? __( 'Hide when: ', 'visigate' ) : __( 'Show only when: ', 'visigate' ) ) + parts.join( '  OR  ' );
	}

	/* ------------------------------ inputs ------------------------------ */

	function StaticTokens( p ) {
		var cond = p.cond;
		var opts = D.options[ p.schema.options ] || {};
		var rev = {};
		Object.keys( opts ).forEach( function ( k ) { rev[ opts[ k ] ] = k; } );
		return el( comp.FormTokenField, {
			label: __( 'Values', 'visigate' ),
			value: ( cond.value || [] ).map( function ( k ) { return opts[ k ] || k; } ),
			suggestions: Object.keys( rev ),
			onChange: function ( tokens ) {
				p.onChange( { value: tokens.map( function ( t ) { return rev[ t ]; } ).filter( function ( x ) { return x !== undefined; } ) } );
			},
			__experimentalExpandOnFocus: true,
			__experimentalShowHowTo: false,
			__nextHasNoMarginBottom: true,
		} );
	}

	function AsyncTokens( p ) {
		var cond = p.cond;
		var known = useState( {} );
		var sugg = useState( [] );
		var timer = useRef();
		var labels = Object.assign( {}, cond.labels || {}, known[ 0 ] );

		function labelOf( id ) {
			return labels[ id ] || '#' + id;
		}

		function search( q ) {
			clearTimeout( timer.current );
			if ( ! q || q.length < 2 ) {
				return;
			}
			timer.current = setTimeout( function () {
				wp.apiFetch( { path: '/visigate/v1/search?type=' + p.schema.source + '&q=' + encodeURIComponent( q ) } ).then( function ( rows ) {
					var m = {};
					rows.forEach( function ( r ) { m[ r.id ] = r.label; } );
					known[ 1 ]( function ( prev ) { return Object.assign( {}, prev, m ); } );
					sugg[ 1 ]( rows.map( function ( r ) { return r.label; } ) );
				} ).catch( function () {} );
			}, 250 );
		}

		return el( comp.FormTokenField, {
			label: __( 'Search…', 'visigate' ),
			value: ( cond.value || [] ).map( labelOf ),
			suggestions: sugg[ 0 ],
			onInputChange: search,
			onChange: function ( tokens ) {
				var ids = [];
				var keep = {};
				tokens.forEach( function ( t ) {
					Object.keys( labels ).forEach( function ( id ) {
						if ( labels[ id ] === t ) {
							ids.push( parseInt( id, 10 ) );
							keep[ id ] = t;
						}
					} );
				} );
				p.onChange( { value: ids, labels: keep } );
			},
			__experimentalShowHowTo: false,
			__nextHasNoMarginBottom: true,
		} );
	}

	function ValueInput( p ) {
		var cond = p.cond;
		var s = p.schema;
		var patch = p.onChange;
		var v = cond.value;

		switch ( s.input ) {
			case 'tokens':
				return el( StaticTokens, { cond: cond, schema: s, onChange: patch } );
			case 'async':
				return el( AsyncTokens, { cond: cond, schema: s, onChange: patch } );
			case 'number':
				return el( Text, { label: s.label, type: 'number', value: v, onChange: function ( x ) { patch( { value: x } ); } } );
			case 'text':
				return el( Text, { label: s.label, placeholder: s.placeholder, value: v, onChange: function ( x ) { patch( { value: x } ); } } );
			case 'kv':
				v = v || { key: '', value: '' };
				return el( 'div', { className: 'vg-pair' },
					el( Text, { label: s.key_label, placeholder: s.key_label, value: v.key, onChange: function ( k ) { patch( { value: { key: k, value: v.value } } ); } } ),
					s.no_value.indexOf( cond.op ) > -1 ? null :
						el( Text, { label: __( 'Value', 'visigate' ), placeholder: __( 'Value', 'visigate' ), value: v.value, onChange: function ( x ) { patch( { value: { key: v.key, value: x } } ); } } )
				);
			case 'time_range':
				v = v || { from: '', to: '' };
				return el( 'div', { className: 'vg-pair' },
					el( Text, { label: __( 'From', 'visigate' ), type: 'time', value: v.from, onChange: function ( x ) { patch( { value: { from: x, to: v.to } } ); } } ),
					el( Text, { label: __( 'To', 'visigate' ), type: 'time', value: v.to, onChange: function ( x ) { patch( { value: { from: v.from, to: x } } ); } } )
				);
			case 'date_range':
				v = v || { from: '', to: '' };
				return el( 'div', { className: 'vg-pair' },
					el( Text, { label: __( 'From', 'visigate' ), type: 'datetime-local', value: v.from, onChange: function ( x ) { patch( { value: { from: x, to: v.to } } ); } } ),
					el( Text, { label: __( 'To', 'visigate' ), type: 'datetime-local', value: v.to, onChange: function ( x ) { patch( { value: { from: v.from, to: x } } ); } } )
				);
		}
		return null;
	}

	/* ---------------------------- components ---------------------------- */

	function ConditionRow( p ) {
		var cond = p.cond;
		var s = TYPES[ cond.type ];
		if ( ! s ) {
			return el( 'div', { className: 'vg-cond vg-cond--missing' }, __( 'Unavailable condition: ', 'visigate' ) + cond.type,
				el( comp.Button, { icon: 'no-alt', isDestructive: true, label: __( 'Remove', 'visigate' ), onClick: p.onRemove } ) );
		}
		function patch( x ) {
			p.onChange( Object.assign( {}, cond, x ) );
		}
		return el( 'div', { className: 'vg-cond' },
			el( 'div', { className: 'vg-cond__top' },
				el( Select, { label: __( 'Condition', 'visigate' ), value: cond.type, options: typeOptions(), onChange: function ( t ) { p.onChange( newCond( t ) ); } } ),
				el( comp.Button, { icon: 'no-alt', isDestructive: true, label: __( 'Remove condition', 'visigate' ), onClick: p.onRemove } )
			),
			Object.keys( s.ops ).length > 1 || s.input === 'none'
				? el( Select, { label: __( 'Operator', 'visigate' ), value: cond.op, options: opsOptions( s ), onChange: function ( op ) { patch( { op: op } ); } } )
				: null,
			el( ValueInput, { cond: cond, schema: s, onChange: patch } ),
			s.days
				? el( Text, { label: __( 'Within last N days (0 = any time)', 'visigate' ), showLabel: true, type: 'number', min: 0, value: cond.days || '', onChange: function ( x ) { patch( { days: parseInt( x, 10 ) || 0 } ); } } )
				: null,
			s.pro && ! D.isPro
				? el( 'p', { className: 'vg-pro-note' }, __( 'Pro condition — activate your license to edit.', 'visigate' ) + ' ', el( 'a', { href: D.upgradeUrl, target: '_blank', rel: 'noopener' }, __( 'License / plans', 'visigate' ) ) )
				: null
		);
	}

	function Group( p ) {
		var g = p.group;
		return el( 'div', { className: 'vg-group' },
			g.conditions.map( function ( c, i ) {
				return el( Fragment, { key: i },
					i > 0 ? el( 'div', { className: 'vg-and' }, __( 'AND', 'visigate' ) ) : null,
					el( ConditionRow, {
						cond: c,
						onChange: function ( nc ) {
							var ng = clone( g );
							ng.conditions[ i ] = nc;
							p.onChange( ng );
						},
						onRemove: function () {
							var ng = clone( g );
							ng.conditions.splice( i, 1 );
							p.onChange( ng.conditions.length ? ng : null );
						},
					} )
				);
			} ),
			el( comp.Button, {
				variant: 'tertiary',
				size: 'small',
				onClick: function () {
					var ng = clone( g );
					ng.conditions.push( newCond() );
					p.onChange( ng );
				},
			}, '+ ' + __( 'Add condition (AND)', 'visigate' ) )
		);
	}

	function Builder( props ) {
		var rules = normalize( props.value );

		function emit( r ) {
			props.onChange( r );
		}

		return el( 'div', { className: 'vg-builder' },
			el( Select, {
				label: __( 'Action', 'visigate' ),
				value: rules.action,
				options: [
					{ value: 'show', label: __( 'Show this content only when…', 'visigate' ) },
					{ value: 'hide', label: __( 'Hide this content when…', 'visigate' ) },
				],
				onChange: function ( a ) {
					var r = clone( rules );
					r.action = a;
					emit( r );
				},
			} ),
			rules.groups.map( function ( g, gi ) {
				return el( Fragment, { key: gi },
					gi > 0 ? el( 'div', { className: 'vg-or' }, __( 'OR', 'visigate' ) ) : null,
					el( Group, {
						group: g,
						onChange: function ( ng ) {
							var r = clone( rules );
							if ( ng ) {
								r.groups[ gi ] = ng;
							} else {
								r.groups.splice( gi, 1 );
							}
							emit( r );
						},
					} )
				);
			} ),
			el( comp.Button, {
				variant: 'secondary',
				onClick: function () {
					var r = clone( rules );
					r.groups.push( { conditions: [ newCond() ] } );
					emit( r );
				},
			}, rules.groups.length ? '+ ' + __( 'Add OR group', 'visigate' ) : '+ ' + __( 'Add condition', 'visigate' ) ),
			el( 'p', { className: 'vg-summary' }, describe( rules ) )
		);
	}

	window.VisigateBuilder = Builder;
	window.VisigateBuilderUtils = { normalize: normalize };
}( window.wp ) );
