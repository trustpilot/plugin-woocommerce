<?php

if ( ! current_user_can( 'activate_plugins' ) ) { return; }

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option('trustpilot_settings');