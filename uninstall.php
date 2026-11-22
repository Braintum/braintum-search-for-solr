<?php
/**
 * Uninstall handler.
 *
 * Runs only when the user deletes the plugin, never on deactivation. Removes
 * everything Braintum Search created in this site's database.
 *
 * Deliberately does NOT delete documents from Solr. The index belongs to the
 * customer's infrastructure, the endpoint may be unreachable, and deleting
 * someone's data from a remote server during an uninstall is not a decision
 * this file should make silently. The admin screen offers an explicit
 * "remove my documents from Solr" action for that.
 *
 * @package BraintumSearch
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove Braintum Search data for the current site.
 *
 * @since 0.1.0
 *
 * @return void
 */
function braintum_search_uninstall_site() {
	global $wpdb;

	$options = array(
		'braintum_search_connection',
		'braintum_search_credentials',
		'braintum_search_index_config',
		'braintum_search_state',
		'braintum_search_version',
		'braintum_search_installed_at',
		'braintum_search_db_version',
		'braintum_search_meta_keys',
		// Run state. Each is written by a different part of the indexing
		// pipeline, and each would otherwise survive the plugin's removal and
		// confuse a later reinstall into thinking a run was in progress.
		'braintum_search_sync_run',
		'braintum_search_sync_run_rebuild',
		'braintum_search_rebuild',
		'braintum_search_reconciliation',
		// The circuit breaker's failure count. Harmless on its own, but a
		// reinstall would start with search paused if it had tripped.
		'braintum_search_breaker',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// The discovered meta-key list is a transient rather than an option, and
	// transients are stored as options with a prefix, so it needs naming twice.
	delete_transient( 'braintum_search_meta_keys' );
	delete_transient( 'braintum_search_wc_price_ceiling' );

	// Legacy 0.1.0 prototype options.
	delete_option( 'braintum_search_settings' );
	delete_option( 'braintum_search_solr_endpoint' );
	delete_option( 'braintum_search_commit_on_update' );

	/*
	 * Per-backend credentials. Their option names carry the backend identifier,
	 * and a backend registered by an add-on that has since been removed cannot
	 * be enumerated — so they are matched by prefix. The prefix is a literal
	 * this file owns, not anything from a request.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$braintum_search_credential_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'braintum_search_credentials_' ) . '%'
		)
	);

	foreach ( (array) $braintum_search_credential_options as $braintum_search_credential_option ) {
		delete_option( $braintum_search_credential_option );
	}

	$tables = array(
		$wpdb->prefix . 'braintum_search_index_runs',
		$wpdb->prefix . 'braintum_search_failures',
	);

	foreach ( $tables as $table ) {
		// Table names cannot be parameterised, and these are built from the
		// trusted $wpdb prefix rather than from any request input.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	if ( class_exists( '\BraintumSearch\Support\Lifecycle' ) ) {
		// Every WP-Cron hook and every Action Scheduler job in the plugin's
		// group. Shared Action Scheduler tables are left alone.
		\BraintumSearch\Support\Lifecycle::cancel_queued_work();
	} else {
		wp_clear_scheduled_hook( 'braintum_search_reconcile' );
	}
}

// The class that knows which hooks the plugin schedules. Uninstall runs
// without the plugin loaded, so the autoloader is brought in by hand, and
// skipped on an install so broken that it is missing.
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( is_multisite() ) {
	$braintum_search_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $braintum_search_site_ids as $braintum_search_site_id ) {
		switch_to_blog( $braintum_search_site_id );
		braintum_search_uninstall_site();
		restore_current_blog();
	}

	delete_site_option( 'braintum_search_network_connection' );
	delete_site_option( 'braintum_search_network_state' );
	delete_site_option( 'braintum_search_network_credentials' );
} else {
	braintum_search_uninstall_site();
}
