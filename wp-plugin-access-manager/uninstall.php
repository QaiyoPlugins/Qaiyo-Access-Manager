<?php
/**
 * Uninstall handler for Qaiyo Access Manager.
 *
 * Only deletes data if the user explicitly opted in via
 * Tools → "Delete all data on uninstall" checkbox.
 * Otherwise, rules are preserved for a potential reinstall.
 *
 * @package wp-plugin-access-manager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$wpam_delete_data = get_option( 'wpam_delete_data_on_uninstall', false );

if ( $wpam_delete_data ) {
	// Access rules.
	delete_option( 'wpam_plugin_role_rules' );
	delete_option( 'wpam_cpt_role_rules' );
	delete_option( 'wpam_plugin_user_rules' );
	delete_option( 'wpam_cpt_user_rules' );

	// v1.x legacy cleanup.
	delete_option( 'wpam_access_rules' );
	delete_option( 'wpam_cpt_access_rules' );
}

// Always clean up the preference itself — it's plugin-specific metadata.
delete_option( 'wpam_delete_data_on_uninstall' );
