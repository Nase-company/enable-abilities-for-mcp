<?php
if ( getenv( 'EWPA_TEST_URL' ) ) {
	$_SERVER['HTTP_HOST']   = parse_url( getenv( 'EWPA_TEST_URL' ), PHP_URL_HOST ) . ':' . parse_url( getenv( 'EWPA_TEST_URL' ), PHP_URL_PORT );
	$_SERVER['REQUEST_URI'] = '/';
	$_SERVER['SCRIPT_NAME'] = '/index.php';
}
define( 'WP_INSTALLING', true );
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if ( ! is_blog_installed() ) {
	wp_install( 'EWPA disposable acceptance', 'fixture-admin', 'fixture@example.invalid', false, '', bin2hex( random_bytes( 24 ) ) );
}
if ( getenv( 'EWPA_TEST_URL' ) ) {
	update_option( 'siteurl', getenv( 'EWPA_TEST_URL' ) );
	update_option( 'home', getenv( 'EWPA_TEST_URL' ) );
}
wp_set_current_user( 1 );
function check_spike( $ok, $label ) {
	if ( ! $ok ) {
		throw new Exception( $label );
	} echo "PASS $label\n"; }
function make_fixture( $ext = 'jpg' ) {
	add_filter( 'big_image_size_threshold', static fn() => 80 );
	add_filter(
		'intermediate_image_sizes_advanced',
		static fn() => array(
			'thumbnail' => array(
				'width'  => 32,
				'height' => 32,
				'crop'   => true,
			),
		)
	);
	$uploads = wp_upload_dir();
	$path    = $uploads['path'] . '/fixture-' . bin2hex( random_bytes( 5 ) ) . '.' . $ext;
	$im      = imagecreatetruecolor( 160, 120 );
	for ( $y = 0;$y < 120;$y++ ) {
		for ( $x = 0;$x < 160;$x++ ) {
			imagesetpixel( $im, $x, $y, random_int( 0, 0xffffff ) );
		}
	}
	if ( $ext === 'webp' ) {
		imagewebp( $im, $path, 95 );
	} else {
		imagejpeg( $im, $path, 95 );
	}
	imagedestroy( $im );
	$id = wp_insert_attachment(
		array(
			'post_title'     => 'Synthetic fixture',
			'post_content'   => 'Description',
			'post_excerpt'   => 'Caption',
			'post_status'    => 'inherit',
			'post_mime_type' => $ext === 'webp' ? 'image/webp' : 'image/jpeg',
		),
		$path
	);
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	update_post_meta( $id, '_wp_attachment_image_alt', 'Synthetic alt' );
	return $id;
}
function frozen_attachment( $id ) {
	global $wpdb;
	return array( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d", $id ), ARRAY_A ), $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id", $id ), ARRAY_A ), wp_get_attachment_url( $id ), wp_get_original_image_url( $id ) );
}
if ( getenv( 'EWPA_SPIKE_ONLY' ) === '1' ) {
	$id       = make_fixture();
	$m        = wp_get_attachment_metadata( $id );
	$main     = get_attached_file( $id );
	$original = dirname( $main ) . '/' . $m['original_image'];
	check_spike( is_file( $main ) && is_file( $original ) && $main !== $original, 'genuine WP preserved original and scaled main' );
	$before   = frozen_attachment( $id );
	$variants = array();
	foreach ( $m['sizes'] as $s ) {
		$p              = dirname( $main ) . '/' . $s['file'];
		$variants[ $p ] = hash_file( 'sha256', $p );}
	$old = filesize( $original );
	$tmp = tempnam( dirname( $original ), '.spike-' );
	copy( $main, $tmp );
	rename( $tmp, $original );
	clearstatcache();
	check_spike( hash_file( 'sha256', $main ) === hash_file( 'sha256', $original ), 'same-path atomic byte-identical replacement' );
	check_spike( $before === frozen_attachment( $id ), 'entire raw post and metadata rows plus main/original URLs unchanged' );
	foreach ( $variants as $p => $hash ) {
		check_spike( hash_file( 'sha256', $p ) === $hash, 'variant unchanged' );
	}
	echo json_encode(
		array(
			'attachment_id'         => $id,
			'original_before'       => $old,
			'original_after'        => filesize( $original ),
			'main_sha256'           => hash_file( 'sha256', $main ),
			'all_db_rows_unchanged' => true,
		)
	) . "\nSPIKE_VALIDATED\n";
}
