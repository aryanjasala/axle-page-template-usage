<?php
/**
 * Removes everything the plugin stored: the history table, the saved plans and the switch result.
 *
 * @package AryanX\Axle\PageTemplateUsage
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans one site.
 */
function axle_page_template_usage_uninstall_site(): void {
	global $wpdb;

	// Schema change on uninstall. The table name is built from the site prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'axle_ptu_history' ) );

	delete_option( 'axle_page_template_usage_db_version' );
	delete_option( 'axle_page_template_usage_theme_maps' );
	delete_option( 'axle_page_template_usage_switch_result' );
	delete_option( 'axle_page_template_usage_missing_state' );
	delete_option( 'axle_page_template_usage_missing_alert' );
	wp_clear_scheduled_hook( 'axle_page_template_usage_purge' );
	wp_clear_scheduled_hook( 'axle_page_template_usage_scan' );
	wp_clear_scheduled_hook( 'axle_page_template_usage_scan_soon' );
	wp_clear_scheduled_hook( 'axle_page_template_usage_continue_switch' );
}

// Per-user state is not per site. One delete covers every user.
delete_metadata( 'user', 0, 'axle_page_template_usage_missing_dismissed', '', true );

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		) 
	) as $axle_page_template_usage_site ) { // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		switch_to_blog( (int) $axle_page_template_usage_site );
		axle_page_template_usage_uninstall_site();
		restore_current_blog();
	}
} else {
	axle_page_template_usage_uninstall_site();
}
