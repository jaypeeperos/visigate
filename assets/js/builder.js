/**
 * Visigate visual rule builder.
 * Plain wp.element (no JSX / no build step). Exposes window.VisigateBuilder.
 *
 * Data model:  { action: 'show'|'hide', always_hide?: boolean, groups: [ { conditions: [ {type, op, value, days?, labels?} ] } ] }
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
			if ( ! isLocked( TYPES[ keys[ i ] ] ) ) {
				return keys[ i ];
			}
		}
		return keys[ 0 ];
	}

	/** Locked = needs Pro (not installed/licensed) or a higher plan (`locked` set by an add-on). */
	function isLocked( s ) {
		return ( s.pro && ! D.isPro ) || !! s.locked;
	}

	function lockLabel( s ) {
		return s.locked && s.lock_label ? s.lock_label : __( 'Pro', 'visigate' );
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
			var locked = isLocked( s );
			return {
				value: k,
				label: ( D.groups[ s.group ] || '' ) + ' › ' + s.label + ( locked ? ' (' + lockLabel( s ) + ')' : '' ),
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
		if ( rules.always_hide ) {
			return __( 'Always hidden — existing conditions are preserved until you turn this off.', 'visigate' );
		}
		var parts = rules.groups.map( function ( g ) {
			return g.conditions.map( describeCond ).join( ' AND ' );
		} ).filter( Boolean );
		if ( ! parts.length ) {
			return __( 'No conditions yet — content is always visible.', 'visigate' );
		}
		return ( rules.action === 'hide' ? __( 'Hide when: ', 'visigate' ) : __( 'Show only when: ', 'visigate' ) ) + parts.join( '  OR  ' );
	}

	/* ------------------------------ warnings ------------------------------ */

	/** Mirrors Visigate_Conditions::is_complete(). Unknown types count as complete (they evaluate to "not met"). */
	function isComplete( c ) {
		if ( ! c || ! c.type ) {
			return false;
		}
		var s = TYPES[ c.type ];
		if ( ! s ) {
			return true;
		}
		var v = c.value;
		switch ( s.input ) {
			case 'none':
				return true;
			case 'tokens':
			case 'async':
				return Array.isArray( v ) && v.length > 0;
			case 'number':
			case 'text':
				return v !== null && v !== undefined && String( v ) !== '';
			case 'kv':
				if ( ! v || ! v.key ) {
					return false;
				}
				return ( s.no_value || [] ).indexOf( c.op ) > -1 || String( v.value || '' ) !== '';
			case 'time_range':
				return !! ( v && v.from && v.to );
			case 'date_range':
				return !! ( v && ( v.from || v.to ) );
		}
		return true;
	}

	/** Conditions that can only have one value per visit, so two "is any of" lists must overlap. */
	var SINGLE_VALUE = [ 'device', 'weekday', 'post_type', 'specific_post', 'specific_user', 'country', 'browser', 'woo_billing_country', 'woo_shipping_country', 'woo_stock_status', 'woo_product_type' ];
	var ACCOUNT_TYPES = [ 'user_role', 'specific_user', 'capability', 'user_registered', 'user_meta' ];

	function label( type ) {
		return TYPES[ type ] ? TYPES[ type ].label : type;
	}

	function dateValue( v, end ) {
		if ( ! v ) {
			return '';
		}
		// A date without a time covers that whole day (matches the engine).
		return v.length === 10 ? v + ( end ? 'T23:59' : 'T00:00' ) : v;
	}

	/**
	 * Problems in a rules object that need no server lookup.
	 * Returns [ { level: 'error'|'warning'|'info', text } ]. Add-ons extend it with the
	 * `visigate.builder.warnings` filter.
	 */
	function warnings( rules ) {
		rules = normalize( rules );
		var out = [];
		var sp = wp.i18n.sprintf;
		if ( rules.always_hide ) {
			out.push( { level: 'info', text: __( 'Always hide is on, so this content is hidden for everyone and the conditions below are not used.', 'visigate' ) } );
			return wp.hooks ? wp.hooks.applyFilters( 'visigate.builder.warnings', out, rules ) : out;
		}
		var now = D.siteNow || '';
		var usable = 0;
		var many = rules.groups.length > 1;

		rules.groups.forEach( function ( g, gi ) {
			var where = many ? sp( __( 'Group %d: ', 'visigate' ), gi + 1 ) : '';
			var conds = ( g.conditions || [] ).filter( function ( c, ci ) {
				if ( c && c.type && ! TYPES[ c.type ] ) {
					out.push( { level: 'warning', text: where + sp( __( '"%s" needs an add-on that is not active, so it counts as not met.', 'visigate' ), c.type ) } );
				}
				if ( ! isComplete( c ) ) {
					out.push( { level: 'warning', text: where + sp( __( '"%s" is incomplete and is ignored until you fill it in.', 'visigate' ), label( c.type ) ) } );
					return false;
				}
				return true;
			} );
			if ( ! conds.length ) {
				if ( ( g.conditions || [] ).length ) {
					out.push( { level: 'warning', text: where + __( 'this group has no complete conditions, so it is skipped.', 'visigate' ) } );
				}
				return;
			}
			usable++;

			var never = [];
			var by = {};
			conds.forEach( function ( c ) {
				( by[ c.type ] = by[ c.type ] || [] ).push( c );
			} );

			// Logged in AND logged out.
			var login = ( by.logged_in || [] ).map( function ( c ) { return c.op; } );
			if ( login.indexOf( 'is' ) > -1 && login.indexOf( 'is_not' ) > -1 ) {
				never.push( __( 'it requires the visitor to be both logged in and logged out', 'visigate' ) );
			}
			// Logged out AND an account-based condition that needs a logged-in user.
			if ( login.indexOf( 'is_not' ) > -1 ) {
				ACCOUNT_TYPES.forEach( function ( t ) {
					( by[ t ] || [] ).forEach( function ( c ) {
						if ( [ 'is_any', 'has', 'older', 'newer', 'equals', 'contains', 'exists', 'is_true', 'gte' ].indexOf( c.op ) > -1 ) {
							never.push( sp( __( 'logged-out visitors have no account, so "%s" cannot match', 'visigate' ), label( t ) ) );
						}
					} );
				} );
			}
			// Single-value conditions whose "is any of" lists do not overlap, or are fully excluded.
			Object.keys( by ).forEach( function ( t ) {
				var list = by[ t ];
				var s = TYPES[ t ];
				if ( list.length < 2 || ! s || ( s.input !== 'tokens' && s.input !== 'async' ) ) {
					return;
				}
				var anyLists = [];
				var noneOf = [];
				list.forEach( function ( c ) {
					var vals = ( c.value || [] ).map( String );
					if ( c.op === 'is_any' ) {
						anyLists.push( vals );
					} else if ( c.op === 'is_none' ) {
						noneOf = noneOf.concat( vals );
					}
				} );
				if ( ! anyLists.length ) {
					return;
				}
				// "is any of A" AND "is none of (everything in A)" can never match.
				var excluded = anyLists.some( function ( vals ) {
					return vals.every( function ( x ) { return noneOf.indexOf( x ) > -1; } );
				} );
				// A single-value condition must satisfy every "is any of" list at once.
				var disjoint = false;
				if ( SINGLE_VALUE.indexOf( t ) > -1 ) {
					var common = anyLists.reduce( function ( acc, vals ) {
						return acc.filter( function ( x ) { return vals.indexOf( x ) > -1; } );
					} ).filter( function ( x ) { return noneOf.indexOf( x ) === -1; } );
					disjoint = ! common.length;
				}
				if ( excluded || disjoint ) {
					never.push( sp( __( 'its "%s" conditions exclude each other', 'visigate' ), s.label ) );
				}
			} );
			// Number ranges that cannot both be true ("at least 100" AND "at most 50").
			Object.keys( by ).forEach( function ( t ) {
				var s = TYPES[ t ];
				if ( ! s || s.input !== 'number' ) {
					return;
				}
				var lo = -Infinity;
				var hi = Infinity;
				by[ t ].forEach( function ( c ) {
					var n = parseFloat( c.value );
					if ( isNaN( n ) ) {
						return;
					}
					if ( c.op === 'gte' || c.op === 'older' ) {
						lo = Math.max( lo, n );
					} else if ( c.op === 'lte' || c.op === 'newer' ) {
						hi = Math.min( hi, n );
					} else if ( c.op === 'eq' ) {
						lo = Math.max( lo, n );
						hi = Math.min( hi, n );
					}
				} );
				if ( lo > hi ) {
					never.push( sp( __( 'its "%s" limits cannot both be met', 'visigate' ), s.label ) );
				}
			} );
			// Ranges that are empty or over.
			conds.forEach( function ( c ) {
				var v = c.value || {};
				if ( c.type === 'time_of_day' && v.from && v.from === v.to ) {
					never.push( __( 'its time of day starts and ends at the same minute', 'visigate' ) );
				}
				if ( c.type === 'date_range' ) {
					var from = dateValue( v.from, false );
					var to = dateValue( v.to, true );
					if ( from && to && from > to ) {
						never.push( __( 'its date range ends before it starts', 'visigate' ) );
					} else if ( to && now && to < now ) {
						out.push( {
							level: 'warning',
							text: where + ( c.op === 'within'
								/* translators: %s: end date */
								? sp( __( 'the date range ended on %s, so this group can no longer match.', 'visigate' ), to.replace( 'T', ' ' ) )
								/* translators: %s: end date */
								: sp( __( 'the date range ended on %s, so "is outside" is now always true.', 'visigate' ), to.replace( 'T', ' ' ) ) ),
						} );
					}
				}
			} );

			never.forEach( function ( why ) {
				out.push( {
					level: 'error',
					/* translators: %s: reason, e.g. "it requires the visitor to be both logged in and logged out" */
					text: ( many ? sp( __( 'Group %d can never match: ', 'visigate' ), gi + 1 ) : __( 'These conditions can never all be true: ', 'visigate' ) ) + why + '.',
				} );
			} );
		} );

		if ( ! usable ) {
			if ( rules.groups.length || rules.action === 'hide' ) {
				out.push( {
					level: 'info',
					text: rules.action === 'hide'
						? __( 'No complete conditions yet, so this content is never hidden.', 'visigate' )
						: __( 'No complete conditions yet, so this content is always visible.', 'visigate' ),
				} );
			}
		}
		return wp.hooks ? wp.hooks.applyFilters( 'visigate.builder.warnings', out, rules ) : out;
	}

	/** { source: [ids] } for every async condition, to check that the selected items still exist. */
	function targets( rules ) {
		var out = {};
		normalize( rules ).groups.forEach( function ( g ) {
			( g.conditions || [] ).forEach( function ( c ) {
				var s = TYPES[ c.type ];
				if ( s && s.input === 'async' && s.source && Array.isArray( c.value ) && c.value.length ) {
					out[ s.source ] = ( out[ s.source ] || [] ).concat( c.value );
				}
			} );
		} );
		return out;
	}

	var missingCache = {};

	function useMissingTargets( rules ) {
		var items = targets( rules );
		var key = JSON.stringify( items );
		var state = useState( missingCache[ key ] || null );
		wp.element.useEffect( function () {
			if ( key === '{}' || ! wp.apiFetch ) {
				state[ 1 ]( null );
				return;
			}
			if ( missingCache[ key ] ) {
				state[ 1 ]( missingCache[ key ] );
				return;
			}
			var live = true;
			var t = setTimeout( function () {
				wp.apiFetch( { path: '/visigate/v1/validate', method: 'POST', data: { items: items } } ).then( function ( res ) {
					missingCache[ key ] = ( res && res.missing ) || {};
					if ( live ) {
						state[ 1 ]( missingCache[ key ] );
					}
				} ).catch( function () {} );
			}, 500 );
			return function () {
				live = false;
				clearTimeout( t );
			};
		}, [ key ] );
		return state[ 0 ];
	}

	function missingWarnings( rules, missing ) {
		var out = [];
		if ( ! missing ) {
			return out;
		}
		normalize( rules ).groups.forEach( function ( g ) {
			( g.conditions || [] ).forEach( function ( c ) {
				var s = TYPES[ c.type ];
				var gone = s && s.source && missing[ s.source ] ? missing[ s.source ].map( String ) : [];
				var hit = ( Array.isArray( c.value ) ? c.value : [] ).filter( function ( id ) { return gone.indexOf( String( id ) ) > -1; } );
				if ( hit.length ) {
					out.push( {
						level: 'warning',
						text: wp.i18n.sprintf(
							/* translators: 1: condition name, 2: list of items */
							__( '"%1$s" points at items that were deleted or unpublished: %2$s. Remove them or pick others.', 'visigate' ),
							s.label,
							hit.map( function ( id ) { return ( c.labels && c.labels[ id ] ) || '#' + id; } ).join( ', ' )
						),
					} );
				}
			} );
		} );
		return out;
	}

	function Warnings( p ) {
		var missing = useMissingTargets( p.rules );
		var list = warnings( p.rules ).concat( missingWarnings( p.rules, missing ) );
		if ( ! list.length ) {
			return null;
		}
		var icons = { error: '⛔', warning: '⚠', info: 'ℹ' };
		return el( 'ul', { className: 'vg-warnings', role: 'status' },
			list.map( function ( w, i ) {
				return el( 'li', { key: i, className: 'vg-warning vg-warning--' + w.level },
					el( 'span', { 'aria-hidden': true }, icons[ w.level ] || '•' ), ' ', w.text );
			} )
		);
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
			s.help ? el( 'p', { className: 'description' }, s.help ) : null,
			s.days
				? el( Text, { label: __( 'Within last N days (0 = any time)', 'visigate' ), showLabel: true, type: 'number', min: 0, value: cond.days || '', onChange: function ( x ) { patch( { days: parseInt( x, 10 ) || 0 } ); } } )
				: null,
			isLocked( s )
				? el( 'p', { className: 'vg-pro-note' },
					s.locked && s.lock_label
						? __( 'Editing this condition requires the ', 'visigate' ) + s.lock_label + __( ' plan. It keeps working on your site.', 'visigate' )
						: __( 'This condition requires the Visigate Pro add-on.', 'visigate' ),
					D.upgradeUrl
						? el( Fragment, null, ' ', el( 'a', { href: D.upgradeUrl, target: '_blank', rel: 'noopener' }, __( 'Learn more', 'visigate' ) ) )
						: null
				)
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
			el( comp.ToggleControl, {
				label: __( 'Always hide', 'visigate' ),
				help: __( 'Hide this content regardless of conditions. Turn off to restore your existing rules.', 'visigate' ),
				checked: !! rules.always_hide,
				onChange: function ( value ) {
					var r = clone( rules );
					if ( value ) { r.always_hide = true; } else { delete r.always_hide; }
					emit( r );
				},
				__nextHasNoMarginBottom: true,
			} ),
			el( 'fieldset', { className: 'vg-builder__conditions', disabled: !! rules.always_hide },
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
			// Extension point: add-ons can add controls (e.g. presets) under the action selector.
			wp.hooks ? wp.hooks.applyFilters( 'visigate.builder.extras', [], { rules: rules, onChange: emit, clone: clone } ) : null,
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
			}, rules.groups.length ? '+ ' + __( 'Add OR group', 'visigate' ) : '+ ' + __( 'Add condition', 'visigate' ) ) ),
			el( 'p', { className: 'vg-summary' }, describe( rules ) ),
			el( Warnings, { rules: rules } )
		);
	}

	window.VisigateBuilder = Builder;
	window.VisigateBuilderUtils = { normalize: normalize, warnings: warnings, describe: describe, isComplete: isComplete };
}( window.wp ) );
