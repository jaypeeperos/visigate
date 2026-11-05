<?php
defined( 'ABSPATH' ) || exit;

/** Atomic editor control backed by the shared Visigate rule builder. */
class Visigate_Elementor_Atomic_Rules_Control extends \Elementor\Modules\AtomicWidgets\Controls\Base\Atomic_Control_Base {
	public function get_type(): string {
		return 'visigate-atomic-rules';
	}

	public function get_props(): array {
		return array();
	}
}
