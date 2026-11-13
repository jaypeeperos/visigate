<?php
/** Standalone integration checks: php tests/elementor.php */
namespace Elementor {
	class Controls_Manager {
		const TAB_ADVANCED = 'advanced';
		const HIDDEN = 'hidden';
		const RAW_HTML = 'raw';
		const SELECT = 'select';
	}
	class Element_Base {
		public $controls = array();
		public $settings = array();
		public $sections = 0;
		public function get_controls( $key ) { return $this->controls[ $key ] ?? null; }
		public function get_settings( $key ) { return $this->settings[ $key ] ?? null; }
		public function start_controls_section( $id, $args ) { ++$this->sections; }
		public function add_control( $id, $args ) { $this->controls[ $id ] = $args; }
		public function end_controls_section() {}
	}
	class Plugin { public static $instance; }
}
namespace Elementor\Modules\AtomicWidgets\PropTypes\Primitives {
	class String_Prop_Type {
		public $default;
		public static function make() { return new static(); }
		public function default( $value ) { $this->default = array( '$$type' => 'string', 'value' => $value ); return $this; }
		public function sanitize( $value ) { $value['value'] = $this->sanitize_value( $value['value'] ); return $value; }
		protected function sanitize_value( $value ) { return $value; }
	}
}
namespace Elementor\Modules\AtomicWidgets\Controls\Base {
	abstract class Atomic_Control_Base {
		public $bind;
		public $label;
		public $options;
		public static function bind_to( $key ) { $control = new static(); $control->bind = $key; return $control; }
		public function set_label( $label ) { $this->label = $label; return $this; }
		public function set_options( $options ) { $this->options = $options; return $this; }
	}
}
namespace Elementor\Modules\AtomicWidgets\Controls\Types {
	class Select_Control extends \Elementor\Modules\AtomicWidgets\Controls\Base\Atomic_Control_Base {}
}
namespace Elementor\Modules\AtomicWidgets\Controls {
	class Section {
		public $id;
		public $items;
		public static function make() { return new static(); }
		public function set_id( $id ) { $this->id = $id; return $this; }
		public function set_label( $label ) { return $this; }
		public function set_description( $description ) { return $this; }
		public function set_items( $items ) { $this->items = $items; return $this; }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	$hooks = array();
	$can_manage = true;
	$preview = false;
	function add_action( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $name ] = $callback; }
	function add_filter( $name, $callback, $priority = 10, $args = 1 ) { add_action( $name, $callback ); }
	function apply_filters( $name, $value, ...$args ) { return isset( $GLOBALS['hooks'][ $name ] ) ? $GLOBALS['hooks'][ $name ]( $value, ...$args ) : $value; }
	function __( $text, $domain ) { return $text; }
	function esc_html__( $text, $domain ) { return $text; }
	function esc_html( $text ) { return $text; }
	function esc_url( $text ) { return $text; }
	function admin_url( $path ) { return $path; }
	function visigate_brand() { return 'Visigate'; }
	function visigate_capability() { return 'visigate_manage'; }
	function current_user_can( $cap ) { return $GLOBALS['can_manage']; }
	function is_user_logged_in() { return false; }
	function is_admin() { return false; }
	function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function get_post( $id ) { return (object) array( 'post_type' => 99 === $id ? 'elementor_library' : 'visigate_set', 'post_status' => 'publish' ); }
	function absint( $value ) { return abs( (int) $value ); }
	function get_the_ID() { return 0; }
	function is_singular() { return false; }
	function get_queried_object_id() { return 0; }
	function sanitize_text_field( $value ) { return strip_tags( $value ); }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ) ); }
	function wp_roles() { return (object) array( 'roles' => array() ); }
	function get_post_types( $args, $output ) { return array(); }
	function get_posts( $args ) { return array( (object) array( 'ID' => 99, 'post_title' => 'Fallback template' ) ); }
	function get_post_meta( $id, $key, $single ) { return rules( 1 === $id ? 'is_not' : 'is' ); }
	class Visigate_Ruleset {
		const CPT = 'visigate_set';
		const META = '_rules';
		public static function all_sets() { return array(); }
		public static function unpublished_sets() { return array(); }
	}
	class Visigate_Admin { public static function opt( $key ) { return false; } }
	class Visigate_View_As { public static function logged_in() { return is_user_logged_in(); } }
	class Visigate_Frontend { public static function preview_enabled() { return $GLOBALS['preview']; } public static function evaluate( $kind, $label, $key, $loop_post, $check ) { return $check(); } }
	function check( $condition, $message ) { if ( ! $condition ) { throw new \Exception( $message ); } }
	function rules( $op, $action = 'show' ) {
		return array( 'action' => $action, 'groups' => array( array( 'conditions' => array( array( 'type' => 'logged_in', 'op' => $op ) ) ) ) );
	}
	require __DIR__ . '/../includes/class-conditions.php';
	require __DIR__ . '/../includes/class-engine.php';
	require __DIR__ . '/../includes/integrations/elementor.php';
	\Elementor\Plugin::$instance = (object) array( 'editor' => null, 'preview' => null );
	$register = $hooks['elementor/element/after_section_end'];
	$register( new \stdClass(), 'custom_css', array( 'tab' => 'advanced' ) );
	$element = new \Elementor\Element_Base();
	$register( $element, 'style', array( 'tab' => 'style' ) );
	check( 0 === $element->sections, 'Controls must stay on Advanced' );
	$register( $element, 'custom_advanced', array( 'tab' => 'advanced' ) );
	$register( $element, 'another_advanced', array( 'tab' => 'advanced' ) );
	check( 1 === $element->sections && 'visigate_rules' === $element->controls['visigate_rules']['type'], 'Register the custom builder once' );
	$can_manage = false;
	$locked = new \Elementor\Element_Base();
	$register( $locked, 'advanced', array( 'tab' => 'advanced' ) );
	check( 'hidden' === $locked->controls['visigate_rules']['type'], 'Preserve custom rules for restricted editors' );
	$can_manage = true;
	$render = $hooks['elementor/frontend/widget/should_render'];
	foreach ( array(
		array( array(), true ),
		array( array( 'visigate_set' => 1 ), true ),
		array( array( 'visigate_set' => 2 ), false ),
		array( array( 'visigate_rules' => json_encode( rules( 'is' ) ) ), false ),
		array( array( 'visigate_rules' => json_encode( rules( 'is_not' ) ) ), true ),
		array( array( 'visigate_rules' => json_encode( rules( 'is_not', 'hide' ) ) ), false ),
		array( array( 'visigate_set' => 1, 'visigate_rules' => json_encode( rules( 'is' ) ) ), false ),
		array( array( 'visigate_set' => 2, 'visigate_rules' => json_encode( rules( 'is_not' ) ) ), false ),
		array( array( 'visigate_set' => 1, 'visigate_rules' => json_encode( rules( 'is_not' ) ) ), true ),
		array( array( 'visigate_rules' => '' ), true ),
		array( array( 'visigate_rules' => '{invalid' ), true ),
		array( array( 'visigate_rules' => rules( 'is' ) ), false ),
	) as $case ) {
		$element->settings = $case[0];
		check( $case[1] === $render( true, $element ), 'Visibility mismatch: ' . json_encode( $case[0] ) );
	}
	$element->settings = array( 'visigate_rules' => json_encode( rules( 'is' ) ) );
	check( false === $render( false, $element ), 'Preserve another render filter rejection' );
	$preview = true;
	check( true === $render( true, $element ), 'Administrator preview bypass' );
	$preview = false;
	\Elementor\Plugin::$instance->editor = new class { public function is_edit_mode() { return true; } };
	check( true === $render( true, $element ), 'Editor must show hidden elements' );
	\Elementor\Plugin::$instance->editor = null;
	$dynamic = $hooks['elementor/element/is_dynamic_content'];
	check( $dynamic( false, array( 'settings' => $element->settings ) ), 'Custom rules must bypass element cache' );
	check( ! $dynamic( false, array( 'settings' => array() ) ), 'Unruled elements may stay cached' );
	check( $dynamic( true, array() ), 'Keep existing dynamic status' );
	// Atomic typed props must behave like classic values, including disabled props.
	$typed = function ( $value, $disabled = false ) { return array( '$$type' => 'string', 'value' => $value, 'disabled' => $disabled ); };
	$element->settings = array( 'visigate_set' => $typed( '1' ), 'visigate_rules' => $typed( json_encode( rules( 'is' ) ) ) );
	check( ! $render( true, $element ), 'Atomic custom rules must combine with typed saved sets' );
	check( $dynamic( false, array( 'settings' => $element->settings ) ), 'Atomic rules must bypass cache' );
	$element->settings['visigate_rules']['disabled'] = true;
	check( $render( true, $element ), 'Disabled atomic rules must not hide elements' );
	check( ! $dynamic( false, array( 'settings' => array( 'visigate_set' => $typed( '' ), 'visigate_rules' => $typed( 'hidden', true ) ) ) ), 'Empty and disabled atomic props must not disable caching' );
	$schema = $hooks['elementor/atomic-widgets/props-schema']( array() );
	check( isset( $schema['visigate_set'], $schema['visigate_rules'] ), 'Declare atomic props so saves retain settings' );
	$clean = $schema['visigate_rules']->sanitize( $typed( json_encode( rules( 'is' ) ) ) );
	check( 'logged_in' === json_decode( $clean['value'], true )['groups'][0]['conditions'][0]['type'], 'Rule JSON must survive atomic sanitization' );
	$clean = $schema['visigate_rules']->sanitize( $typed( '{broken' ) );
	check( '' === $clean['value'], 'Malformed atomic JSON must sanitize to empty' );
	$always = array( 'action' => 'show', 'groups' => array(), 'always_hide' => true );
	$clean = $schema['visigate_rules']->sanitize( $typed( json_encode( $always ) ) );
	check( $always === json_decode( $clean['value'], true ), 'Atomic sanitizer retains Always hide without groups' );
	$element->settings = array( 'visigate_rules' => $typed( json_encode( $always ) ) );
	check( ! $render( true, $element ), 'Atomic elements honor Always hide' );
	$element->settings = array( 'visigate_rules' => json_encode( $always ) );
	check( ! $render( true, $element ), 'Classic elements honor Always hide' );
	$atomic_controls = $hooks['elementor/atomic-widgets/controls'];
	$sections = $atomic_controls( array(), $element );
	check( 'visigate' === $sections[0]->id && 'visigate_rules' === $sections[0]->items[1]->bind, 'Atomic controls must bind to declared props' );
	$can_manage = false;
	check( array() === $atomic_controls( array(), $element ), 'Restricted users cannot edit atomic rules' );
	check( isset( $hooks['elementor/atomic-widgets/props-schema']( array() )['visigate_rules'] ), 'Restricted saves must retain the atomic schema' );
	$can_manage = true;
	class Atomic_Layout extends \Elementor\Element_Base {
		public $type;
		public function get_type() { return $this->type; }
		public function get_atomic_settings() { return array(); }
	}
	foreach ( array( 'e-flexbox', 'e-grid', 'e-div-block', 'e-future-layout' ) as $type ) {
		$layout = new Atomic_Layout();
		$layout->type = $type;
		$layout->settings = array( 'visigate_rules' => $typed( json_encode( rules( 'is' ) ) ) );
		$hooks['elementor/frontend/before_render']( $layout );
		check( ! $hooks["elementor/frontend/{$type}/should_render"]( true, $layout ), 'Atomic layout hook must hide ' . $type );
	}

	// Check the sibling Pro add-on when available, including custom-only fallback.
	$pro = __DIR__ . '/../../visigate-pro/includes/class-elementor-fallback.php';
	if ( is_file( $pro ) ) {
		class Visigate_Pro_Fallback { public static function for_set( $set ) { return 'fallback'; } }
		class Visigate_Pro_Plans {
			const BUSINESS = 'business';
			public static function has( $plan ) { return true; }
		}
		require $pro;
		\Elementor\Plugin::$instance->frontend = new class {
			public function get_builder_content_for_display( $id, $css ) { return 'template fallback'; }
		};
		$element->settings = array( 'visigate_rules' => json_encode( rules( 'is' ) ), 'visigate_fallback' => 'tpl:99' );
		ob_start();
		Visigate_Pro_Elementor_Fallback::after_render( $element );
		check( 'template fallback' === ob_get_clean(), 'Custom-only hidden elements must trigger template fallback' );
		$element->settings['visigate_rules'] = json_encode( rules( 'is_not' ) );
		ob_start();
		Visigate_Pro_Elementor_Fallback::after_render( $element );
		check( '' === ob_get_clean(), 'Visible elements must not trigger fallback' );
		$element->settings = array( 'visigate_set' => 1, 'visigate_rules' => json_encode( rules( 'is' ) ) );
		ob_start();
		Visigate_Pro_Elementor_Fallback::after_render( $element );
		check( '' === ob_get_clean(), 'Custom rules must not trigger a visible saved set fallback' );
		$element->settings['visigate_set'] = 2;
		ob_start();
		Visigate_Pro_Elementor_Fallback::after_render( $element );
		check( 'fallback' === ob_get_clean(), 'Hidden saved sets must retain their default fallback' );
		$element->settings = array( 'visigate_set' => $typed( '' ), 'visigate_rules' => $typed( json_encode( rules( 'is' ) ) ), 'visigate_fallback' => $typed( 'tpl:99' ) );
		ob_start();
		Visigate_Pro_Elementor_Fallback::after_render( $element );
		check( 'template fallback' === ob_get_clean(), 'Atomic props must support explicit template fallbacks' );
		check( isset( Visigate_Pro_Elementor_Fallback::atomic_schema( array() )['visigate_fallback'] ), 'Atomic saves must retain Pro fallback props' );
		$items = Visigate_Pro_Elementor_Fallback::atomic_controls( array() );
		check( 'tpl:99' === $items[0]->options[2]['value'], 'Atomic fallback control must offer saved templates' );
	}
	echo "PASS: classic/atomic controls, typed props, sanitization, layout hooks, combined visibility, editor/preview bypass, cache and Pro fallback.\n";
}
