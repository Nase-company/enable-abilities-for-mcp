<?php
/** Disposable HTTP fixtures; no test route or auth bypass is installed. */
require __DIR__ . '/media-original-fixtures.php';
wp_installing( false );
require_once ABSPATH . 'wp-content/plugins/enable-abilities-for-mcp/enable-abilities-for-mcp.php';
$mode = getenv( 'EWPA_HTTP_MODE' );
if ( $mode === 'prepare' ) {
	$id     = make_fixture();
	$keys   = get_option( EWPA_OPTION_KEY );
	$keys   = array_values( array_diff( $keys, array( 'ewpa/replace-media-original' ) ) );
	$keys[] = 'ewpa/inspect-media-original';
	update_option( EWPA_OPTION_KEY, $keys );
	$admin      = WP_Application_Passwords::create_new_application_password( 1, array( 'name' => 'EWPA synthetic acceptance' ) );
	$subscriber = get_user_by( 'login', 'fixture-subscriber' );
	$low        = WP_Application_Passwords::create_new_application_password( $subscriber->ID, array( 'name' => 'EWPA synthetic acceptance' ) );
	echo json_encode(
		array(
			'attachment_id'   => $id,
			'snapshot_sha256' => hash( 'sha256', serialize( frozen_attachment( $id ) ) ),
			'admin'           => array(
				'login'    => 'fixture-admin',
				'password' => $admin[0],
			),
			'subscriber'      => array(
				'login'    => 'fixture-subscriber',
				'password' => $low[0],
			),
		)
	);
} elseif ( $mode === 'enable' ) {
	$keys   = get_option( EWPA_OPTION_KEY );
	$keys[] = 'ewpa/replace-media-original';
	update_option( EWPA_OPTION_KEY, $keys );
	echo 'ENABLED';
} elseif ( $mode === 'readback' ) {
	$id = (int) getenv( 'EWPA_HTTP_ID' );
	$s  = ewpa_media_original_snapshot( $id );
	echo json_encode(
		array(
			'snapshot_sha256' => hash( 'sha256', serialize( frozen_attachment( $id ) ) ),
			'main_sha256'     => $s['main']['sha256'],
			'original_sha256' => $s['original']['sha256'],
		)
	);
} elseif ( $mode === 'disabled-function' ) {
	$ids      = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	$id       = $ids[0];
	$m        = wp_get_attachment_metadata( $id );
	$original = dirname( get_attached_file( $id ) ) . '/' . $m['original_image'];
	$h        = hash_file( 'sha256', $original );
	$r        = ewpa_inspect_media_original( array( 'attachment_id' => $id ) );
	if ( ! is_wp_error( $r ) || $r->get_error_code() !== 'filesystem_unavailable' || $h !== hash_file( 'sha256', $original ) ) {
		throw new Exception( 'disabled function refusal' );
	}
	echo 'DISABLED_FUNCTION_OK';
}
