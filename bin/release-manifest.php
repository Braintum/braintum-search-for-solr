<?php
/**
 * Normalise, describe and verify the release package. Called by build-release.sh.
 *
 * Usage:
 *
 *   php bin/release-manifest.php normalise <package dir> <epoch>
 *   php bin/release-manifest.php check     <package dir>
 *   php bin/release-manifest.php write     <package dir> <manifest file> <header file>
 *   php bin/release-manifest.php verify    <extracted package dir> <manifest file>
 *
 * In PHP rather than in the shell script because `touch`, `stat`, `chmod` and
 * `sha256sum` take different arguments on macOS and on Linux, and a release has
 * to come out the same on a laptop as on CI.
 *
 * @package BraintumSearch
 */

declare( strict_types=1 );

/**
 * Stop with a message.
 *
 * @param string $message What went wrong.
 * @return never
 */
function braintum_search_release_fail( string $message ): void {
	fwrite( STDERR, "Release: {$message}\n" );
	exit( 1 );
}

/**
 * Every path under a directory, relative to it, in byte order.
 *
 * Byte order, not locale order, so the list is the same everywhere.
 *
 * @param string $dir Directory.
 * @return array{files: string[], dirs: string[]}
 */
function braintum_search_release_tree( string $dir ): array {
	$files    = array();
	$dirs     = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		$relative = substr( $item->getPathname(), strlen( $dir ) + 1 );

		if ( $item->isLink() ) {
			braintum_search_release_fail( "{$relative} is a symlink. A plugin zip must not contain one." );
		}

		if ( $item->isDir() ) {
			$dirs[] = $relative;
		} else {
			$files[] = $relative;
		}
	}

	sort( $files, SORT_STRING );
	sort( $dirs, SORT_STRING );

	return array(
		'files' => $files,
		'dirs'  => $dirs,
	);
}

/**
 * One fixed time and mode for everything, so two builds of one commit are one zip.
 *
 * A zip stores each file's modification time and permissions. Left alone they
 * record when and by whom the build ran, and the checksum changes every time.
 *
 * @param string $dir   Package directory.
 * @param int    $epoch Timestamp to give every entry.
 * @return void
 */
function braintum_search_release_normalise( string $dir, int $epoch ): void {
	$tree = braintum_search_release_tree( $dir );

	foreach ( $tree['files'] as $file ) {
		chmod( "{$dir}/{$file}", 0644 );
		touch( "{$dir}/{$file}", $epoch );
	}

	// Deepest first, because touching a file updates its directory.
	foreach ( array_reverse( $tree['dirs'] ) as $sub ) {
		chmod( "{$dir}/{$sub}", 0755 );
		touch( "{$dir}/{$sub}", $epoch );
	}

	touch( $dir, $epoch );
}

/**
 * Refuse a package that is missing what it needs or carries what it must not.
 *
 * @param string $dir Package directory.
 * @return void
 */
function braintum_search_release_check( string $dir ): void {
	$required = array(
		'braintum-search-for-solr.php',
		'constants.php',
		'uninstall.php',
		'readme.txt',
		'changelog.txt',
		'composer.json',
		'vendor/autoload.php',
		'vendor/woocommerce/action-scheduler/action-scheduler.php',
	);

	foreach ( $required as $path ) {
		if ( ! is_file( "{$dir}/{$path}" ) ) {
			braintum_search_release_fail( "{$path} is missing from the package." );
		}
	}

	if ( array() === glob( "{$dir}/assets/build/*.asset.php" ) ) {
		braintum_search_release_fail( 'assets/build has no compiled assets. Run: npm ci && npm run build' );
	}

	// Composer records whether it installed development packages. Read its
	// record rather than trusting the command line that ran it.
	$installed = json_decode( (string) file_get_contents( "{$dir}/vendor/composer/installed.json" ), true );

	if ( ! is_array( $installed ) || ! array_key_exists( 'dev', $installed ) ) {
		braintum_search_release_fail( 'vendor/composer/installed.json is unreadable.' );
	}

	if ( false !== $installed['dev'] || array() !== ( $installed['dev-package-names'] ?? array() ) ) {
		braintum_search_release_fail( 'vendor/ contains development packages: ' . implode( ', ', (array) ( $installed['dev-package-names'] ?? array() ) ) );
	}

	$tree = braintum_search_release_tree( $dir );

	foreach ( array_merge( $tree['files'], $tree['dirs'] ) as $path ) {
		// Plugin Check refuses hidden files outright.
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' !== $part && '.' === $part[0] ) {
				braintum_search_release_fail( "{$path} is a hidden file. Add it to .distignore." );
			}
		}

		if ( preg_match( '#^(tests|docs|bin|node_modules|assets/src|build)(/|$)#', $path ) ) {
			braintum_search_release_fail( "{$path} is development material. Add it to .distignore." );
		}

		if ( preg_match( '#(^|/)(phpunit|phpstan|php_codesniffer|squizlabs|wp-coding-standards)(/|$)#i', $path ) ) {
			braintum_search_release_fail( "{$path} is development tooling." );
		}

		if ( preg_match( '#\.(zip|tar|gz|phar|sql|log|map)$#i', $path ) ) {
			braintum_search_release_fail( "{$path} does not belong in a plugin package." );
		}
	}
}

/**
 * Write the manifest: the header lines, then a checksum for every file.
 *
 * Same format as `sha256sum`, so `sha256sum -c` can check an extracted copy
 * against it directly, once the header lines are dropped.
 *
 * @param string $dir      Package directory.
 * @param string $manifest Manifest to write.
 * @param string $header   File holding the header lines.
 * @return void
 */
function braintum_search_release_write( string $dir, string $manifest, string $header ): void {
	$lines = array();

	foreach ( file( $header, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$lines[] = '# ' . $line;
	}

	$tree    = braintum_search_release_tree( $dir );
	$lines[] = '# files: ' . count( $tree['files'] );
	$lines[] = '';

	foreach ( $tree['files'] as $file ) {
		$lines[] = hash_file( 'sha256', "{$dir}/{$file}" ) . '  braintum-search-for-solr/' . $file;
	}

	file_put_contents( $manifest, implode( "\n", $lines ) . "\n" );
}

/**
 * Check an extracted package against the manifest, file for file.
 *
 * Catches what the zip step could get wrong on its own: a file left out, a
 * file added, or the wrong directory archived.
 *
 * @param string $dir      Extracted `braintum-search-for-solr` directory.
 * @param string $manifest Manifest written for the package.
 * @return void
 */
function braintum_search_release_verify( string $dir, string $manifest ): void {
	$expected = array();

	foreach ( file( $manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		if ( '#' === $line[0] ) {
			continue;
		}

		list( $hash, $path ) = explode( '  ', $line, 2 );

		$expected[ substr( $path, strlen( 'braintum-search-for-solr/' ) ) ] = $hash;
	}

	$actual = braintum_search_release_tree( $dir )['files'];

	$missing = array_diff( array_keys( $expected ), $actual );
	$extra   = array_diff( $actual, array_keys( $expected ) );

	if ( array() !== $missing ) {
		braintum_search_release_fail( 'The zip is missing: ' . implode( ', ', $missing ) );
	}

	if ( array() !== $extra ) {
		braintum_search_release_fail( 'The zip has files the manifest does not: ' . implode( ', ', $extra ) );
	}

	foreach ( $expected as $path => $hash ) {
		if ( hash_file( 'sha256', "{$dir}/{$path}" ) !== $hash ) {
			braintum_search_release_fail( "{$path} in the zip differs from the package." );
		}
	}

	echo 'Verified ' . count( $expected ) . " files against the manifest.\n";
}

$command = $argv[1] ?? '';

switch ( $command ) {
	case 'normalise':
		braintum_search_release_normalise( rtrim( $argv[2], '/' ), (int) $argv[3] );
		break;
	case 'check':
		braintum_search_release_check( rtrim( $argv[2], '/' ) );
		break;
	case 'write':
		braintum_search_release_write( rtrim( $argv[2], '/' ), $argv[3], $argv[4] );
		break;
	case 'verify':
		braintum_search_release_verify( rtrim( $argv[2], '/' ), $argv[3] );
		break;
	default:
		braintum_search_release_fail( 'Usage: php bin/release-manifest.php normalise|check|write|verify ...' );
}
