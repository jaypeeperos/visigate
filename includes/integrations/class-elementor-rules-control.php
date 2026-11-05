<?php
defined( 'ABSPATH' ) || exit;

/** Mounts the shared visual builder as an Elementor data control. */
class Visigate_Elementor_Rules_Control extends \Elementor\Base_Data_Control {

	public function get_type() {
		return 'visigate_rules';
	}

	public function content_template() {
		?>
		<div class="vg-elementor-rules">
			<p class="vg-panel-title">{{ data.label }}</p>
			<div class="vg-elementor-builder"></div>
			<p class="elementor-control-field-description">{{ data.description }}</p>
		</div>
		<?php
	}
}
