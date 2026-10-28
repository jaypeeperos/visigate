<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'visigate_settings' );
// Rule sets (visigate_set posts) are intentionally kept so reinstalling does not break your pages.
