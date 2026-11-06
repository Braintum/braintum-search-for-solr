<?php
/**
 * Constants shared by every entry point.
 *
 * `braintum-search-for-solr.php` is the only entry point today. The version, the paths and
 * the schema contract version live here rather than beside the plugin header
 * so that anything else that ever has to start Braintum Search reads the same
 * values: defining them twice is how a User-Agent or a cache key ends up
 * reporting a release the code is not.
 *
 * Nothing here touches WordPress, so it costs nothing to include.
 * `BRAINTUM_SEARCH_PLUGIN_URL` is deliberately absent: it needs `plugin_dir_url()`,
 * and braintum-search-for-solr.php defines it.
 *
 * Must parse on old PHP, for the same reason `braintum-search-for-solr.php` must: a syntax
 * error here is a white screen, not a notice.
 *
 * @package BraintumSearch
 */

// Included by braintum-search-for-solr.php, which WordPress has already loaded.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BRAINTUM_SEARCH_VERSION' ) ) {
	define( 'BRAINTUM_SEARCH_VERSION', '1.0.0' );
}

if ( ! defined( 'BRAINTUM_SEARCH_PLUGIN_DIR' ) ) {
	// Computed rather than taken from plugin_dir_path(), which is a WordPress
	// function this file must not need.
	define( 'BRAINTUM_SEARCH_PLUGIN_DIR', rtrim( str_replace( '\\', '/', __DIR__ ), '/' ) . '/' );
}

if ( ! defined( 'BRAINTUM_SEARCH_PLUGIN_FILE' ) ) {
	define( 'BRAINTUM_SEARCH_PLUGIN_FILE', BRAINTUM_SEARCH_PLUGIN_DIR . 'braintum-search-for-solr.php' );
}

if ( ! defined( 'BRAINTUM_SEARCH_MIN_PHP' ) ) {
	define( 'BRAINTUM_SEARCH_MIN_PHP', '7.4' );
}

if ( ! defined( 'BRAINTUM_SEARCH_MIN_WP' ) ) {
	define( 'BRAINTUM_SEARCH_MIN_WP', '6.9' );
}

/**
 * Schema contract version.
 *
 * Deliberately independent of the plugin version: most releases change nothing
 * about the Solr schema, and coupling the two would force meaningless
 * migrations. See ADR-003.
 */
if ( ! defined( 'BRAINTUM_SEARCH_SCHEMA_CONTRACT_VERSION' ) ) {
	define( 'BRAINTUM_SEARCH_SCHEMA_CONTRACT_VERSION', 1 );
}
