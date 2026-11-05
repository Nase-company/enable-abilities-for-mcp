<?php
/**
 * Builds the GitHub release zip from the WordPress.org SVN tag.
 *
 * The zip is exactly what WordPress.org ships for that version (already filtered
 * by .svnignore and carrying the production vendor/), so a GitHub download can
 * never include local files such as .mcp.json. Entries always use "/" as the
 * separator: zips built with Windows tools that store "\" install as broken
 * file names on Linux hosts.
 *
 * Usage:  php build-release-zip.php 2.10.1
 * NC fork: php build-release-zip.php 2.14.1 --local
 * --local packages an allowlisted checkout with composer install --no-dev vendor.
 * It never downloads the same-numbered upstream SVN tag for a fork release.
 * Output: dist/enable-abilities-for-mcp-2.10.1.zip
 *
 * Requires the svn command line client and the zip PHP extension. If php.ini
 * does not enable it: php -d extension=zip build-release-zip.php 2.10.1
 *
 * @package EnableAbilitiesForMCP
 */

// phpcs:disable -- CLI build tool, never loaded by WordPress.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$slug    = 'enable-abilities-for-mcp';
$version = $argv[1] ?? '';
$local   = in_array( '--local', $argv, true );

if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
	fwrite( STDERR, 'Usage: php build-release-zip.php <version>   e.g. 2.10.1' . PHP_EOL );
	exit( 1 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, 'The zip PHP extension is required. Try: php -d extension=zip build-release-zip.php ' . $version . PHP_EOL );
	exit( 1 );
}

$work   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ewpa-release-' . $version . '-' . getmypid();
$export = $work . DIRECTORY_SEPARATOR . $slug;
$url    = 'https://plugins.svn.wordpress.org/' . $slug . '/tags/' . $version;
$dist   = __DIR__ . DIRECTORY_SEPARATOR . 'dist';
$target = $dist . DIRECTORY_SEPARATOR . $slug . '-' . $version . '.zip';

/**
 * Deletes a directory tree.
 *
 * @param string $dir Directory.
 */
function ewpa_rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $dir );
}

/**
 * Stops the build with an error after cleaning up.
 *
 * @param string $message Error.
 * @param string $work    Work directory to remove.
 */
function ewpa_fail( string $message, string $work ): void {
	ewpa_rrmdir( $work );
	fwrite( STDERR, 'ERROR: ' . $message . PHP_EOL );
	exit( 1 );
}

ewpa_rrmdir( $work );
mkdir( $work, 0777, true );

echo ( $local ? 'Packaging allowlisted local checkout' : 'Exporting ' . $url ) . PHP_EOL;
if ( $local ) {
	$installed = json_decode( (string) @file_get_contents( __DIR__ . '/vendor/composer/installed.json' ), true );
	if ( ! is_array( $installed ) || ! array_key_exists( 'dev', $installed ) || false !== $installed['dev'] ) {
		ewpa_fail( 'Local build requires composer install --no-dev first.', $work );
	}
	mkdir( $export, 0755, true );
	$allow = array( $slug . '.php', 'uninstall.php', 'LICENSE', 'readme.txt', 'changelog.txt', 'includes', 'assets', 'languages', 'vendor' );
	foreach ( $allow as $entry ) {
		$source = __DIR__ . '/' . $entry;
		if ( is_link( $source ) || ! file_exists( $source ) ) {
			ewpa_fail( 'Missing or linked allowlisted source.', $work );
		}
		$paths = is_dir( $source ) ? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) ) : array( new SplFileInfo( $source ) );
		foreach ( $paths as $file ) {
			if ( $file->isLink() || ! $file->isFile() ) {
				ewpa_fail( 'Local build requires regular files only.', $work );
			}
			$relative = substr( $file->getPathname(), strlen( __DIR__ ) + 1 );
			$target_file = $export . '/' . $relative;
			if ( ! is_dir( dirname( $target_file ) ) ) {
				mkdir( dirname( $target_file ), 0755, true );
			}
			if ( ! copy( $file->getPathname(), $target_file ) ) {
				ewpa_fail( 'Local source copy failed.', $work );
			}
		}
	}
} else {
	exec( 'svn export --force -q ' . escapeshellarg( $url ) . ' ' . escapeshellarg( $export ) . ' 2>&1', $output, $code );
	if ( 0 !== $code || ! is_file( $export . '/' . $slug . '.php' ) ) {
		ewpa_fail( 'svn export failed: ' . implode( ' ', $output ), $work );
	}
}

// The main file must declare the requested version.
$main = (string) file_get_contents( $export . '/' . $slug . '.php' );
if ( ! preg_match( '/^\s*\*\s*Version:\s*' . preg_quote( $version, '/' ) . '\s*$/m', $main ) ) {
	ewpa_fail( 'The plugin header in the SVN tag does not say Version: ' . $version, $work );
}
if ( ! is_file( $export . '/vendor/autoload.php' ) ) {
	ewpa_fail( 'vendor/autoload.php is missing from the SVN tag.', $work );
}

// Never publish local or development files, even if one ever reaches SVN.
$forbidden = array( '.mcp.json', '.env', '.claude', '.sdd', '.codegraph', '.atl', '.git', '.svn', '.vscode', '.idea', 'tests', 'dist', 'node_modules', 'sync-to-svn.ps1', 'build-release-zip.php', 'future-plans.md', 'phpcs.xml', 'phpcs.xml.dist', 'debug.log', 'error_log' );

$files = array();
$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $export, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iter as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $export ) + 1 ) );
	foreach ( explode( '/', $relative ) as $segment ) {
		if ( in_array( $segment, $forbidden, true ) ) {
			ewpa_fail( 'Refusing to package forbidden path: ' . $relative, $work );
		}
	}
	$files[ $relative ] = $file->getPathname();
}
ksort( $files );

if ( ! is_dir( $dist ) ) {
	mkdir( $dist, 0777, true );
}
if ( is_file( $target ) ) {
	unlink( $target );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	ewpa_fail( 'Could not create ' . $target, $work );
}
foreach ( $files as $relative => $path ) {
	$name = $slug . '/' . $relative;
	if ( ! $zip->addFile( $path, $name ) || ! $zip->setMtimeName( $name, 315532800 ) ) {
		$zip->close();
		ewpa_fail( 'Could not add a deterministic release entry.', $work );
	}
}
$zip->close();

// Verify what was written, not what was intended.
$check = new ZipArchive();
$check->open( $target );
$count = $check->numFiles;
for ( $i = 0; $i < $count; $i++ ) {
	$name = (string) $check->getNameIndex( $i );
	if ( false !== strpos( $name, '\\' ) || 0 !== strpos( $name, $slug . '/' ) ) {
		$check->close();
		ewpa_fail( 'Bad zip entry name: ' . $name, $work );
	}
}
$check->close();

ewpa_rrmdir( $work );

echo 'Built ' . $target . PHP_EOL;
echo 'Entries: ' . $count . PHP_EOL;
echo 'SHA-256: ' . hash_file( 'sha256', $target ) . PHP_EOL;
