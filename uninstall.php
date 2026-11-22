<?php
/**
 * Removes plugin data on uninstall.
 *
 * Only the plugin's own option and update-check transients are deleted; the
 * shared core transient (update_plugins) is left alone, because other plugins
 * keep their entries there. On multisite every site stores its own copy, so
 * each site is cleaned in turn.
 *
 * @package CustomStickyPost
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'custom_sticky_uninstall_site' ) ) {
	/**
	 * Deletes the plugin data of the current site.
	 *
	 * The option and transient names are literals on purpose: uninstall.php
	 * runs on its own, before the plugin code is loaded.
	 */
	function custom_sticky_uninstall_site() {
		delete_option( 'custom_sticky_post_id' );

		// Update-check cache created by includes/class-csp-github-updater.php.
		delete_transient( 'csp_github_release' );
		delete_transient( 'csp_github_release_error' );
	}
}

if ( is_multisite() ) {
	$custom_sticky_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $custom_sticky_sites as $custom_sticky_site_id ) {
		switch_to_blog( (int) $custom_sticky_site_id );
		custom_sticky_uninstall_site();
		restore_current_blog();
	}
} else {
	custom_sticky_uninstall_site();
}
