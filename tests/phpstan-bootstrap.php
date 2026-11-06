<?php
/**
 * Constants PHPStan needs to know about.
 *
 * These are defined at runtime by braintum-search-for-solr.php. Declaring them here keeps
 * static analysis honest without the analyser having to execute the plugin.
 *
 * @package BraintumSearch
 */

define( 'BRAINTUM_SEARCH_VERSION', '0.1.0' );
define( 'BRAINTUM_SEARCH_PLUGIN_FILE', __DIR__ . '/../braintum-search-for-solr.php' );
define( 'BRAINTUM_SEARCH_PLUGIN_DIR', __DIR__ . '/../' );
define( 'BRAINTUM_SEARCH_PLUGIN_URL', 'https://example.test/wp-content/plugins/braintum-search-for-solr/' );
define( 'BRAINTUM_SEARCH_MIN_PHP', '7.4' );
define( 'BRAINTUM_SEARCH_MIN_WP', '6.9' );
define( 'BRAINTUM_SEARCH_SCHEMA_CONTRACT_VERSION', 1 );
