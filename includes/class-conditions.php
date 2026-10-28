<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registry of available conditions + sanitization of rule data.
 *
 * Rule data model (stored as JSON-like arrays):
 *
 *   {
 *     "action": "show" | "hide",
 *     "groups": [                       // groups are OR'd
 *        { "conditions": [              // conditions inside a group are AND'd
 *            { "type": "user_role", "op": "is_any", "value": ["customer"] },
 *            { "type": "woo_bought_product", "op": "bought", "value": [12], "days": 30 }
 *        ] }
 *     ]
 *   }
 *
 * Developers can add conditions with the `visigate_conditions` filter and
 * evaluate them with the `visigate_check_condition` filter.
 */
class Visigate_Conditions {

	private static $all = null;

	public static function groups() {
		return array(
			'user'    => __( 'User', 'visigate' ),
			'visitor' => __( 'Visitor', 'visigate' ),
			'time'    => __( 'Time', 'visigate' ),
			'woo'     => __( 'WooCommerce', 'visigate' ),
		);
	}

	/** Option lists used by token fields. */
	public static function options() {
		$roles = array();
		foreach ( wp_roles()->roles as $key => $role ) {
			$roles[ $key ] = translate_user_role( $role['name'] );
		}

		return array(
			'roles'    => $roles,
			'devices'  => array(
				'desktop' => __( 'Desktop', 'visigate' ),
				'tablet'  => __( 'Tablet', 'visigate' ),
				'mobile'  => __( 'Mobile', 'visigate' ),
			),
			'weekdays' => array(
				'1' => __( 'Monday', 'visigate' ),
				'2' => __( 'Tuesday', 'visigate' ),
				'3' => __( 'Wednesday', 'visigate' ),
				'4' => __( 'Thursday', 'visigate' ),
				'5' => __( 'Friday', 'visigate' ),
				'6' => __( 'Saturday', 'visigate' ),
				'7' => __( 'Sunday', 'visigate' ),
			),
			'countries' => self::countries(),
		);
	}

	public static function all() {
		if ( null !== self::$all ) {
			return self::$all;
		}

		$in = array(
			'is_any'  => __( 'is any of', 'visigate' ),
			'is_none' => __( 'is none of', 'visigate' ),
		);
		$cmp = array(
			'gte' => __( 'is at least', 'visigate' ),
			'lte' => __( 'is at most', 'visigate' ),
			'eq'  => __( 'equals', 'visigate' ),
		);
		$str = array(
			'equals'     => __( 'equals', 'visigate' ),
			'not_equals' => __( 'does not equal', 'visigate' ),
			'contains'   => __( 'contains', 'visigate' ),
			'exists'     => __( 'exists', 'visigate' ),
			'not_exists' => __( 'does not exist', 'visigate' ),
		);
		$no_value = array( 'exists', 'not_exists' );

		$defs = array(
			// ---- User -------------------------------------------------------
			'logged_in'        => array(
				'label' => __( 'Login status', 'visigate' ), 'group' => 'user', 'input' => 'none',
				'ops'   => array( 'is' => __( 'Logged in', 'visigate' ), 'is_not' => __( 'Logged out (visitor)', 'visigate' ) ),
			),
			'user_role'        => array(
				'label' => __( 'User role', 'visigate' ), 'group' => 'user', 'input' => 'tokens', 'options' => 'roles', 'ops' => $in,
			),
			'capability'       => array(
				'label' => __( 'User capability', 'visigate' ), 'group' => 'user', 'input' => 'text',
				'ops'   => array( 'has' => __( 'has', 'visigate' ), 'has_not' => __( 'does not have', 'visigate' ) ),
				'placeholder' => 'edit_posts',
			),
			'user_registered'  => array(
				'label' => __( 'Account age (days)', 'visigate' ), 'group' => 'user', 'input' => 'number',
				'ops'   => array( 'older' => __( 'is more than', 'visigate' ), 'newer' => __( 'is less than', 'visigate' ) ),
			),
			'user_meta'        => array(
				'label' => __( 'User meta', 'visigate' ), 'group' => 'user', 'input' => 'kv', 'pro' => true,
				'ops'   => $str, 'no_value' => $no_value, 'key_label' => __( 'Meta key', 'visigate' ),
			),

			// ---- Visitor ----------------------------------------------------
			'device'           => array(
				'label' => __( 'Device type', 'visigate' ), 'group' => 'visitor', 'input' => 'tokens', 'options' => 'devices', 'ops' => $in, 'dynamic' => true,
			),
			'country'          => array(
				'label' => __( 'Country', 'visigate' ), 'group' => 'visitor', 'input' => 'tokens', 'options' => 'countries', 'ops' => $in, 'pro' => true, 'dynamic' => true,
			),
			'url_param'        => array(
				'label' => __( 'URL parameter', 'visigate' ), 'group' => 'visitor', 'input' => 'kv', 'pro' => true, 'dynamic' => true,
				'ops'   => $str, 'no_value' => $no_value, 'key_label' => __( 'Parameter', 'visigate' ),
			),
			'cookie'           => array(
				'label' => __( 'Cookie', 'visigate' ), 'group' => 'visitor', 'input' => 'kv', 'pro' => true, 'dynamic' => true,
				'ops'   => $str, 'no_value' => $no_value, 'key_label' => __( 'Cookie name', 'visigate' ),
			),
			'referrer'         => array(
				'label' => __( 'Referrer', 'visigate' ), 'group' => 'visitor', 'input' => 'text', 'pro' => true, 'dynamic' => true,
				'ops'   => array( 'contains' => __( 'contains', 'visigate' ), 'not_contains' => __( 'does not contain', 'visigate' ) ),
				'placeholder' => 'google.com',
			),

			// ---- Time -------------------------------------------------------
			'weekday'          => array(
				'label' => __( 'Day of week', 'visigate' ), 'group' => 'time', 'input' => 'tokens', 'options' => 'weekdays', 'ops' => $in, 'dynamic' => true,
			),
			'time_of_day'      => array(
				'label' => __( 'Time of day', 'visigate' ), 'group' => 'time', 'input' => 'time_range', 'dynamic' => true,
				'ops'   => array( 'between' => __( 'is between', 'visigate' ), 'not_between' => __( 'is not between', 'visigate' ) ),
			),
			'date_range'       => array(
				'label' => __( 'Date range', 'visigate' ), 'group' => 'time', 'input' => 'date_range', 'dynamic' => true,
				'ops'   => array( 'within' => __( 'is within', 'visigate' ), 'outside' => __( 'is outside', 'visigate' ) ),
			),

			// ---- WooCommerce ------------------------------------------------
			'woo_bought_product' => array(
				'label' => __( 'Purchased product', 'visigate' ), 'group' => 'woo', 'input' => 'async', 'source' => 'product', 'pro' => true, 'days' => true,
				'ops'   => array( 'bought' => __( 'has bought any of', 'visigate' ), 'not_bought' => __( 'has not bought any of', 'visigate' ) ),
			),
			'woo_bought_category' => array(
				'label' => __( 'Purchased from category', 'visigate' ), 'group' => 'woo', 'input' => 'async', 'source' => 'product_cat', 'pro' => true, 'days' => true,
				'ops'   => array( 'bought' => __( 'has bought from', 'visigate' ), 'not_bought' => __( 'has not bought from', 'visigate' ) ),
			),
			'woo_order_count'  => array(
				'label' => __( 'Lifetime orders', 'visigate' ), 'group' => 'woo', 'input' => 'number', 'pro' => true, 'ops' => $cmp,
			),
			'woo_total_spent'  => array(
				'label' => __( 'Lifetime spend', 'visigate' ), 'group' => 'woo', 'input' => 'number', 'pro' => true, 'ops' => $cmp,
			),
			'woo_cart_product' => array(
				'label' => __( 'Cart contents', 'visigate' ), 'group' => 'woo', 'input' => 'async', 'source' => 'product', 'pro' => true, 'dynamic' => true,
				'ops'   => array( 'has' => __( 'includes any of', 'visigate' ), 'has_not' => __( 'includes none of', 'visigate' ) ),
			),
			'woo_cart_total'   => array(
				'label' => __( 'Cart subtotal', 'visigate' ), 'group' => 'woo', 'input' => 'number', 'pro' => true, 'dynamic' => true, 'ops' => $cmp,
			),
			'woo_subscription' => array(
				'label' => __( 'Subscription', 'visigate' ), 'group' => 'woo', 'input' => 'none', 'pro' => true,
				'ops'   => array( 'active' => __( 'Has an active subscription', 'visigate' ), 'none' => __( 'Has no active subscription', 'visigate' ) ),
			),
		);

		$defs = apply_filters( 'visigate_conditions', $defs );

		foreach ( $defs as $key => &$d ) {
			$d = wp_parse_args(
				$d,
				array(
					'label' => $key, 'group' => 'user', 'input' => 'none', 'pro' => false, 'dynamic' => false,
					'days'  => false, 'options' => '', 'source' => '', 'no_value' => array(), 'ops' => array( 'is' => '' ),
				)
			);
			$d['default_op'] = key( $d['ops'] );
		}
		unset( $d );

		self::$all = $defs;
		return self::$all;
	}

	/** Is a condition fully configured? Incomplete conditions are ignored, not failed. */
	public static function is_complete( $c ) {
		$all = self::all();
		if ( ! is_array( $c ) || empty( $c['type'] ) || ! isset( $all[ $c['type'] ] ) ) {
			return false;
		}
		$s = $all[ $c['type'] ];
		$v = $c['value'] ?? null;
		switch ( $s['input'] ) {
			case 'none':
				return true;
			case 'tokens':
			case 'async':
				return ! empty( $v );
			case 'number':
			case 'text':
				return '' !== (string) $v && null !== $v;
			case 'kv':
				if ( empty( $v['key'] ) ) {
					return false;
				}
				return in_array( $c['op'] ?? '', $s['no_value'], true ) || '' !== (string) ( $v['value'] ?? '' );
			case 'time_range':
				return ! empty( $v['from'] ) && ! empty( $v['to'] );
			case 'date_range':
				return ! empty( $v['from'] ) || ! empty( $v['to'] );
		}
		return true;
	}

	/** Whitelist-sanitize rules coming from the builder. */
	public static function sanitize( $rules ) {
		$out = array( 'action' => 'show', 'groups' => array() );
		if ( ! is_array( $rules ) ) {
			return $out;
		}
		$out['action'] = ( ( $rules['action'] ?? '' ) === 'hide' ) ? 'hide' : 'show';
		$all           = self::all();
		$opts          = self::options();

		foreach ( (array) ( $rules['groups'] ?? array() ) as $g ) {
			$conds = array();
			foreach ( (array) ( $g['conditions'] ?? array() ) as $c ) {
				$t = $c['type'] ?? '';
				if ( ! isset( $all[ $t ] ) ) {
					continue;
				}
				$s  = $all[ $t ];
				$op = $c['op'] ?? $s['default_op'];
				if ( ! isset( $s['ops'][ $op ] ) ) {
					$op = $s['default_op'];
				}
				$n = array(
					'type'  => $t,
					'op'    => $op,
					'value' => self::sanitize_value( $s, $c['value'] ?? null, $opts ),
				);
				if ( $s['days'] ) {
					$n['days'] = max( 0, (int) ( $c['days'] ?? 0 ) );
				}
				if ( ! empty( $c['labels'] ) && is_array( $c['labels'] ) ) {
					$n['labels'] = array();
					foreach ( $c['labels'] as $id => $label ) {
						$n['labels'][ (int) $id ] = sanitize_text_field( $label );
					}
				}
				$conds[] = $n;
			}
			if ( $conds ) {
				$out['groups'][] = array( 'conditions' => $conds );
			}
		}
		return $out;
	}

	private static function sanitize_value( $s, $v, $opts ) {
		switch ( $s['input'] ) {
			case 'tokens':
				$v = array_values( array_unique( array_map( 'sanitize_text_field', (array) $v ) ) );
				if ( $s['options'] && isset( $opts[ $s['options'] ] ) ) {
					$v = array_values( array_intersect( $v, array_map( 'strval', array_keys( $opts[ $s['options'] ] ) ) ) );
				}
				return $v;
			case 'async':
				return array_values( array_unique( array_filter( array_map( 'absint', (array) $v ) ) ) );
			case 'number':
				return is_numeric( $v ) ? $v + 0 : '';
			case 'text':
				return sanitize_text_field( (string) $v );
			case 'kv':
				return array(
					'key'   => sanitize_text_field( (string) ( $v['key'] ?? '' ) ),
					'value' => sanitize_text_field( (string) ( $v['value'] ?? '' ) ),
				);
			case 'time_range':
				$f = (string) ( $v['from'] ?? '' );
				$t = (string) ( $v['to'] ?? '' );
				return array(
					'from' => preg_match( '/^\d{2}:\d{2}$/', $f ) ? $f : '',
					'to'   => preg_match( '/^\d{2}:\d{2}$/', $t ) ? $t : '',
				);
			case 'date_range':
				$re = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/';
				$f  = (string) ( $v['from'] ?? '' );
				$t  = (string) ( $v['to'] ?? '' );
				return array(
					'from' => preg_match( $re, $f ) ? $f : '',
					'to'   => preg_match( $re, $t ) ? $t : '',
				);
		}
		return null;
	}

	/** ISO 3166-1 alpha-2 => name. */
	public static function countries() {
		static $list = null;
		if ( null !== $list ) {
			return $list;
		}
		$raw = <<<'EOT'
AD|Andorra
AE|United Arab Emirates
AF|Afghanistan
AG|Antigua and Barbuda
AI|Anguilla
AL|Albania
AM|Armenia
AO|Angola
AR|Argentina
AS|American Samoa
AT|Austria
AU|Australia
AW|Aruba
AX|Åland Islands
AZ|Azerbaijan
BA|Bosnia and Herzegovina
BB|Barbados
BD|Bangladesh
BE|Belgium
BF|Burkina Faso
BG|Bulgaria
BH|Bahrain
BI|Burundi
BJ|Benin
BM|Bermuda
BN|Brunei
BO|Bolivia
BR|Brazil
BS|Bahamas
BT|Bhutan
BW|Botswana
BY|Belarus
BZ|Belize
CA|Canada
CD|DR Congo
CF|Central African Republic
CG|Congo
CH|Switzerland
CI|Côte d'Ivoire
CK|Cook Islands
CL|Chile
CM|Cameroon
CN|China
CO|Colombia
CR|Costa Rica
CU|Cuba
CV|Cape Verde
CW|Curaçao
CY|Cyprus
CZ|Czechia
DE|Germany
DJ|Djibouti
DK|Denmark
DM|Dominica
DO|Dominican Republic
DZ|Algeria
EC|Ecuador
EE|Estonia
EG|Egypt
ER|Eritrea
ES|Spain
ET|Ethiopia
FI|Finland
FJ|Fiji
FK|Falkland Islands
FM|Micronesia
FO|Faroe Islands
FR|France
GA|Gabon
GB|United Kingdom
GD|Grenada
GE|Georgia
GF|French Guiana
GG|Guernsey
GH|Ghana
GI|Gibraltar
GL|Greenland
GM|Gambia
GN|Guinea
GP|Guadeloupe
GQ|Equatorial Guinea
GR|Greece
GT|Guatemala
GU|Guam
GW|Guinea-Bissau
GY|Guyana
HK|Hong Kong
HN|Honduras
HR|Croatia
HT|Haiti
HU|Hungary
ID|Indonesia
IE|Ireland
IL|Israel
IM|Isle of Man
IN|India
IQ|Iraq
IR|Iran
IS|Iceland
IT|Italy
JE|Jersey
JM|Jamaica
JO|Jordan
JP|Japan
KE|Kenya
KG|Kyrgyzstan
KH|Cambodia
KI|Kiribati
KM|Comoros
KN|Saint Kitts and Nevis
KP|North Korea
KR|South Korea
KW|Kuwait
KY|Cayman Islands
KZ|Kazakhstan
LA|Laos
LB|Lebanon
LC|Saint Lucia
LI|Liechtenstein
LK|Sri Lanka
LR|Liberia
LS|Lesotho
LT|Lithuania
LU|Luxembourg
LV|Latvia
LY|Libya
MA|Morocco
MC|Monaco
MD|Moldova
ME|Montenegro
MG|Madagascar
MH|Marshall Islands
MK|North Macedonia
ML|Mali
MM|Myanmar
MN|Mongolia
MO|Macao
MQ|Martinique
MR|Mauritania
MS|Montserrat
MT|Malta
MU|Mauritius
MV|Maldives
MW|Malawi
MX|Mexico
MY|Malaysia
MZ|Mozambique
NA|Namibia
NC|New Caledonia
NE|Niger
NG|Nigeria
NI|Nicaragua
NL|Netherlands
NO|Norway
NP|Nepal
NR|Nauru
NZ|New Zealand
OM|Oman
PA|Panama
PE|Peru
PF|French Polynesia
PG|Papua New Guinea
PH|Philippines
PK|Pakistan
PL|Poland
PR|Puerto Rico
PS|Palestine
PT|Portugal
PW|Palau
PY|Paraguay
QA|Qatar
RE|Réunion
RO|Romania
RS|Serbia
RU|Russia
RW|Rwanda
SA|Saudi Arabia
SB|Solomon Islands
SC|Seychelles
SD|Sudan
SE|Sweden
SG|Singapore
SI|Slovenia
SK|Slovakia
SL|Sierra Leone
SM|San Marino
SN|Senegal
SO|Somalia
SR|Suriname
SS|South Sudan
ST|São Tomé and Príncipe
SV|El Salvador
SY|Syria
SZ|Eswatini
TC|Turks and Caicos Islands
TD|Chad
TG|Togo
TH|Thailand
TJ|Tajikistan
TL|Timor-Leste
TM|Turkmenistan
TN|Tunisia
TO|Tonga
TR|Türkiye
TT|Trinidad and Tobago
TV|Tuvalu
TW|Taiwan
TZ|Tanzania
UA|Ukraine
UG|Uganda
US|United States
UY|Uruguay
UZ|Uzbekistan
VA|Vatican City
VC|Saint Vincent and the Grenadines
VE|Venezuela
VG|British Virgin Islands
VI|U.S. Virgin Islands
VN|Vietnam
VU|Vanuatu
WS|Samoa
XK|Kosovo
YE|Yemen
ZA|South Africa
ZM|Zambia
ZW|Zimbabwe
EOT;
		$list = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			$p = explode( '|', trim( $line ), 2 );
			if ( 2 === count( $p ) ) {
				$list[ $p[0] ] = $p[1];
			}
		}
		return $list;
	}
}
