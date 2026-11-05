<?php
/** Run: php tests/media-original-test.php. Native filesystem, minimal WP doubles. */
define( 'ABSPATH', __DIR__ . '/' );
function add_action( ...$args ) {}
class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) {
		$this->code = $code; }
	public function get_error_code() {
		return $this->code;}
}
require dirname( __DIR__ ) . '/includes/media-originals.php';
$checks = 0;
function check_original( $ok, $label ) {
	global $checks;
	++$checks;
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL $label\n" );
		exit( 1 );
	}echo "PASS $label\n";}
foreach ( array( '2026/11/photo-scaled.webp', 'plain.jpg', 'a_b-1.jpeg' ) as $p ) {
	check_original( ewpa_media_original_relative( $p ), 'safe relative ' . $p );
}
foreach ( array( '', '../bad.jpg', '/outside.jpg', 'a/../b.jpg', 'a//b.jpg', 'https://example.org/a.jpg', 'a\\b.jpg', 'a' . "\0" . '.jpg', '.', '..', 'a/.hidden', 'a/%2e%2e/b.jpg' ) as $p ) {
	check_original( ! ewpa_media_original_relative( $p ), 'unsafe relative path' );
}
check_original( ewpa_media_original_input( array( 'attachment_id' => 1 ), false ), 'inspect input' );
foreach ( array(
	array( 'attachment_id' => '1' ),
	array( 'attachment_id' => 0 ),
	array( 'attachment_id' => -1 ),
	array(
		'attachment_id' => 1,
		'path'          => 'x',
	),
	array(),
	null,
) as $p ) {
	check_original( ! ewpa_media_original_input( $p, false ), 'invalid inspect input' );
}
$p = array(
	'attachment_id'            => 1,
	'expected_original_sha256' => str_repeat( 'a', 64 ),
	'expected_main_sha256'     => str_repeat( 'b', 64 ),
	'expected_metadata_sha256' => str_repeat( 'c', 64 ),
	'external_archive_sha256'  => str_repeat( 'a', 64 ),
	'confirm_replace'          => true,
);
check_original( ewpa_media_original_input( $p, true ), 'strict replacement input' );
foreach ( array( 'ABC', '', str_repeat( 'a', 63 ), str_repeat( 'a', 64 ) . "\n", str_repeat( 'A', 64 ) ) as $hash ) {
	$q                         = $p;
	$q['expected_main_sha256'] = $hash;
	check_original( ! ewpa_media_original_input( $q, true ), 'invalid hash' );}
$q                    = $p;
$q['confirm_replace'] = 'true';
check_original( ! ewpa_media_original_input( $q, true ), 'no truthy confirmation coercion' );
$dir = sys_get_temp_dir() . '/ewpa-unit-' . bin2hex( random_bytes( 8 ) );
mkdir( $dir, 0700 );
$file = $dir . '/regular';
file_put_contents( $file, 'fixture' );
try {
	check_original( false !== ewpa_media_original_stat( $file ), 'regular file accepted' );
	chmod( $file, 04755 );
	check_original( false === ewpa_media_original_stat( $file ), 'special file modes refused rather than silently stripped' );
	chmod( $file, 0600 );
	check_original( false === ewpa_media_original_stat( $dir ), 'directory rejected' );
	symlink( $file, $dir . '/symbolic' );
	check_original( false === ewpa_media_original_stat( $dir . '/symbolic' ), 'symlink rejected' );
	link( $file, $dir . '/hard' );
	check_original( false === ewpa_media_original_stat( $file ), 'hardlinks rejected' );
	unlink( $dir . '/hard' );
	$payload = 'VP8 ' . pack( 'V', 2 ) . 'xx';
	$webp    = 'RIFF' . pack( 'V', strlen( $payload ) + 4 ) . 'WEBP' . $payload;
	file_put_contents( $dir . '/static.webp', $webp );
	check_original( ewpa_media_original_static_webp( $dir . '/static.webp', strlen( $webp ) ), 'static WebP chunk parser' );
	$payload = 'ANIM' . pack( 'V', 6 ) . str_repeat( "\0", 6 );
	$webp    = 'RIFF' . pack( 'V', strlen( $payload ) + 4 ) . 'WEBP' . $payload;
	file_put_contents( $dir . '/animated.webp', $webp );
	check_original( ! ewpa_media_original_static_webp( $dir . '/animated.webp', strlen( $webp ) ), 'animated WebP refused' );
	check_original( ! ewpa_media_original_static_webp( $dir . '/animated.webp', strlen( $webp ) - 1 ), 'truncated WebP refused' );
	$payload = 'VP8X' . pack( 'V', 10 ) . "\x02" . str_repeat( "\0", 9 );
	$webp    = 'RIFF' . pack( 'V', strlen( $payload ) + 4 ) . 'WEBP' . $payload;
	file_put_contents( $dir . '/flag.webp', $webp );
	check_original( ! ewpa_media_original_static_webp( $dir . '/flag.webp', strlen( $webp ) ), 'animation flag refused' );
} finally {
	foreach ( glob( $dir . '/*' ) as $owned ) {
		unlink( $owned );
	}rmdir( $dir );}
echo "$checks checks passed.\nMEDIA_ORIGINAL_UNIT_OK\n";
