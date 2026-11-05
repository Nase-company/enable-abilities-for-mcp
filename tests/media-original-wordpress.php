<?php
/** Disposable WordPress acceptance. Never run on an existing site. See tests/media-originals.md. */
require __DIR__ . '/media-original-fixtures.php';
wp_installing( false );
require_once ABSPATH . 'wp-content/plugins/enable-abilities-for-mcp/enable-abilities-for-mcp.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$result = activate_plugin( 'enable-abilities-for-mcp/enable-abilities-for-mcp.php' );
if ( is_wp_error( $result ) ) {
	throw new Exception( 'Plugin activation failed' );
}
check_spike( function_exists( 'ewpa_inspect_media_original' ), 'shipped inspect function exists' );
check_spike( ! in_array( 'ewpa/replace-media-original', ewpa_get_default_ability_keys(), true ), 'replacement defaults OFF' );
check_spike( ! ewpa_is_ability_enabled( 'ewpa/replace-media-original' ), 'activation leaves replacement disabled' );
$GLOBALS['acceptance_checks'] = 0;
function assertion( $ok, $label ) {
	++$GLOBALS['acceptance_checks'];
	check_spike( $ok, $label ); }
function err( $result, $code, $label ) {
	assertion( is_wp_error( $result ) && $result->get_error_code() === $code, $label . ' [' . ( is_wp_error( $result ) ? $result->get_error_code() : 'unexpected success' ) . ']' ); }
function params_for( $id ) {
	$s = ewpa_inspect_media_original( array( 'attachment_id' => $id ) );
	if ( is_wp_error( $s ) ) {
		throw new Exception( 'inspect: ' . $s->get_error_code() );
	}
	return array(
		'attachment_id'            => $id,
		'expected_original_sha256' => $s['original_sha256'],
		'expected_main_sha256'     => $s['main_sha256'],
		'expected_metadata_sha256' => $s['metadata_sha256'],
		'external_archive_sha256'  => $s['original_sha256'],
		'confirm_replace'          => true,
	);
}
$keys   = get_option( EWPA_OPTION_KEY );
$keys   = array_values( array_diff( $keys, array( 'ewpa/replace-media-original' ) ) );
$keys[] = 'ewpa/inspect-media-original';
update_option( EWPA_OPTION_KEY, $keys );
$id            = make_fixture();
$p             = params_for( $id );
$main          = get_attached_file( $id );
$m             = wp_get_attachment_metadata( $id );
$orig          = dirname( $main ) . '/' . $m['original_image'];
$original_hash = hash_file( 'sha256', $orig );
err( ewpa_replace_media_original( $p ), 'ability_disabled', 'direct callback cannot bypass disabled toggle' );
$keys   = get_option( EWPA_OPTION_KEY );
$keys[] = 'ewpa/inspect-media-original';
$keys[] = 'ewpa/replace-media-original';
update_option( EWPA_OPTION_KEY, $keys );
$before   = frozen_attachment( $id );
$variants = array();
foreach ( $m['sizes'] as $s ) {
	$path              = dirname( $main ) . '/' . $s['file'];
	$variants[ $path ] = hash_file( 'sha256', $path );}
$subscriber = get_user_by( 'login', 'fixture-subscriber' );
$subscriber = $subscriber ? $subscriber->ID : wp_create_user( 'fixture-subscriber', bin2hex( random_bytes( 16 ) ), 'subscriber@example.invalid' );
wp_set_current_user( $subscriber );
err( ewpa_inspect_media_original( array( 'attachment_id' => $id ) ), 'forbidden', 'subscriber cannot inspect' );
err( ewpa_replace_media_original( $p ), 'forbidden', 'subscriber cannot replace' );
wp_set_current_user( 1 );
foreach ( array( 'expected_original_sha256', 'expected_main_sha256', 'expected_metadata_sha256' ) as $key ) {
	$bad         = $p;
	$bad[ $key ] = str_repeat( '0', 64 );
	if ( $key === 'expected_original_sha256' ) {
		$bad['external_archive_sha256'] = $bad[ $key ];
	}
	err( ewpa_replace_media_original( $bad ), 'stale_input', 'reject stale ' . $key );
}
$bad                            = $p;
$bad['external_archive_sha256'] = str_repeat( '0', 64 );
err( ewpa_replace_media_original( $bad ), 'archive_confirmation_required', 'archive acknowledgment bound to old bytes' );
$bad                    = $p;
$bad['confirm_replace'] = false;
err( ewpa_replace_media_original( $bad ), 'archive_confirmation_required', 'explicit destruction confirmation' );
$bad         = $p;
$bad['path'] = '/arbitrary';
err( ewpa_replace_media_original( $bad ), 'invalid_input', 'arbitrary path not accepted' );
assertion( hash_file( 'sha256', $orig ) === $original_hash, 'refusals did not mutate original' );
touch( $orig, time() - 3600 );
clearstatcache( true, $orig );
$attrs = stat( $orig );
$r     = ewpa_replace_media_original( $p );
assertion( ! is_wp_error( $r ) && $r['status'] === 'replaced', 'JPEG native replacement success' );
assertion( hash_file( 'sha256', $orig ) === $p['expected_main_sha256'], 'readback exact main hash' );
assertion( $before === frozen_attachment( $id ), 'all raw attachment post/meta rows and URLs unchanged' );
foreach ( $variants as $path => $h ) {
	assertion( hash_file( 'sha256', $path ) === $h, 'variant bytes unchanged' );
}
clearstatcache();
$after = stat( $orig );
foreach ( array( 'mode', 'uid', 'gid' ) as $key ) {
	assertion( $attrs[ $key ] === $after[ $key ], 'preserved attribute ' . $key );
}
assertion( $after['mtime'] > $attrs['mtime'], 'changed bytes refresh HTTP Last-Modified timestamp' );
err( ewpa_replace_media_original( $p ), 'stale_input', 'old request replay refused' );
err( ewpa_replace_media_original( params_for( $id ) ), 'no_byte_benefit', 'equal-size new request refused' );
if ( function_exists( 'imagewebp' ) ) {
	$webp = make_fixture( 'webp' );
	$r    = ewpa_replace_media_original( params_for( $webp ) );
	assertion( ! is_wp_error( $r ) && $r['status'] === 'replaced', 'WebP native replacement success' );}
// Administrative maintenance must not inherit an author's ordinary upload rights.
$author_id = wp_create_user( 'fixture-author', bin2hex( random_bytes( 16 ) ), 'author@example.invalid' );
( new WP_User( $author_id ) )->set_role( 'author' );
$owned_id = make_fixture();
wp_update_post( array( 'ID' => $owned_id, 'post_author' => $author_id ) );
$owned_params = params_for( $owned_id );
wp_set_current_user( $author_id );
assertion( current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $owned_id ), 'author has normal native rights on own media' );
err( ewpa_replace_media_original( $owned_params ), 'forbidden', 'original replacement additionally requires administrator maintenance rights' );
wp_set_current_user( 1 );

// Refuse a canonical source whose bytes disagree with its stored dimensions.
$dimension_id = make_fixture();
$dimension_meta = wp_get_attachment_metadata( $dimension_id );
++$dimension_meta['width'];
wp_update_attachment_metadata( $dimension_id, $dimension_meta );
err( ewpa_inspect_media_original( array( 'attachment_id' => $dimension_id ) ), 'invalid_metadata', 'source dimensions match attachment metadata' );

// Another attachment's main file must never be overwritten as this one's original.
$shared_id = make_fixture();
$shared_params = params_for( $shared_id );
$shared_main = get_attached_file( $shared_id );
$shared_meta = wp_get_attachment_metadata( $shared_id );
$shared_original = dirname( $shared_main ) . '/' . $shared_meta['original_image'];
$shared_before = hash_file( 'sha256', $shared_original );
$other_id = wp_insert_attachment( array( 'post_title' => 'Other attachment owns this file', 'post_mime_type' => 'image/jpeg' ), $shared_original );
err( ewpa_replace_media_original( $shared_params ), 'shared_file', 'another attachment main path is protected' );
assertion( hash_file( 'sha256', $shared_original ) === $shared_before, 'shared file bytes unchanged' );

foreach ( array( 'original_image', 'variant', 'backup' ) as $shared_case ) {
	$shared_id       = make_fixture();
	$shared_params   = params_for( $shared_id );
	$shared_main     = get_attached_file( $shared_id );
	$shared_meta     = wp_get_attachment_metadata( $shared_id );
	$shared_original = dirname( $shared_main ) . '/' . $shared_meta['original_image'];
	$shared_before   = hash_file( 'sha256', $shared_original );
	$other_id        = make_fixture();
	$other_meta      = wp_get_attachment_metadata( $other_id );
	if ( 'original_image' === $shared_case ) {
		$other_meta['original_image'] = basename( $shared_original );
		wp_update_attachment_metadata( $other_id, $other_meta );
	} elseif ( 'variant' === $shared_case ) {
		$other_meta['sizes']['shared-test'] = array( 'file' => basename( $shared_original ) );
		wp_update_attachment_metadata( $other_id, $other_meta );
	} else {
		update_post_meta( $other_id, '_wp_attachment_backup_sizes', array( 'shared-test' => array( 'file' => basename( $shared_original ) ) ) );
	}
	err( ewpa_replace_media_original( $shared_params ), 'shared_file', 'foreign ' . $shared_case . ' reference protected' );
	assertion( hash_file( 'sha256', $shared_original ) === $shared_before, 'foreign ' . $shared_case . ' bytes unchanged' );
}

// Every malicious filesystem fixture is synthetic and owned by this suite.
foreach ( array( 'symlink', 'hardlink', 'source_symlink', 'source_hardlink', 'missing', 'directory', 'outside', 'traversal', 'equal', 'mime', 'animated', 'parent_symlink', 'smaller', 'unsupported', 'malformed_sizes' ) as $case ) {
	$fid    = make_fixture( 'animated' === $case ? 'webp' : 'jpg' );
	$fp     = params_for( $fid );
	$fm     = get_attached_file( $fid );
	$meta   = wp_get_attachment_metadata( $fid );
	$fo     = dirname( $fm ) . '/' . $meta['original_image'];
	$saved  = file_get_contents( $fo );
	$target = dirname( $fo ) . '/owned-' . $case;
	if ( $case === 'symlink' ) {
		rename( $fo, $target );
		symlink( $target, $fo );}
	if ( $case === 'hardlink' ) {
		link( $fo, $target );
	}
	if ( $case === 'source_symlink' ) {
		rename( $fm, $target );
		symlink( $target, $fm );
	}
	if ( $case === 'source_hardlink' ) {
		link( $fm, $target );
	}
	if ( $case === 'directory' ) {
		unlink( $fo );
		mkdir( $fo );
	}
	if ( $case === 'unsupported' ) {
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'application/pdf' ), array( 'ID' => $fid ) );
	}
	if ( $case === 'malformed_sizes' ) {
		$meta['sizes'] = 'malformed';
		wp_update_attachment_metadata( $fid, $meta );
	}
	if ( $case === 'missing' ) {
		unlink( $fo );
	}
	if ( $case === 'outside' ) {
		update_post_meta( $fid, '_wp_attached_file', '/outside/fixture.jpg' );
		$meta['file'] = '/outside/fixture.jpg';
		wp_update_attachment_metadata( $fid, $meta );
	}
	if ( $case === 'traversal' ) {
		$meta['original_image'] = '../evil.jpg';
		wp_update_attachment_metadata( $fid, $meta );}
	if ( $case === 'equal' ) {
		$meta['original_image'] = basename( $fm );
		wp_update_attachment_metadata( $fid, $meta );}
	if ( $case === 'mime' ) {
		file_put_contents( $fo, 'not a JPEG' );
	}
	if ( $case === 'animated' ) {
		$wi = imagecreatetruecolor( 2, 2 );
		ob_start();
		imagewebp( $wi );
		$bytes = ob_get_clean();
		imagedestroy( $wi );
		file_put_contents( $fo, substr( $bytes, 0, 12 ) . 'ANIM' . pack( 'V', 6 ) . str_repeat( "\0", 6 ) . substr( $bytes, 12 ) ); }
	if ( $case === 'parent_symlink' ) {
		$link = dirname( $fm ) . '/owned-parent';
		symlink( dirname( $fm ), $link );
		$meta['file'] = dirname( $meta['file'] ) . '/owned-parent/' . basename( $fm );
		update_post_meta( $fid, '_wp_attached_file', $meta['file'] );
		wp_update_attachment_metadata( $fid, $meta ); }
	if ( $case === 'smaller' ) {
		copy( $fm, $fo );
		file_put_contents( $fm, str_repeat( ' ', 100 ), FILE_APPEND );}
	$old   = is_file( $fo ) ? hash_file( 'sha256', $fo ) : null;
	$state = frozen_attachment( $fid );
	$res   = ewpa_replace_media_original( $fp );
	assertion( is_wp_error( $res ), 'unsafe case refused: ' . $case );
	assertion( $old === ( is_file( $fo ) ? hash_file( 'sha256', $fo ) : null ) && $state === frozen_attachment( $fid ), 'unsafe case no mutation: ' . $case );
	if ( $case === 'parent_symlink' ) {
		unlink( $link );
	}
}

// A directory which cannot accept an exclusive staging file must leave bytes alone.
$fid = make_fixture();
$fp = params_for($fid);
$fm = get_attached_file($fid);
$meta = wp_get_attachment_metadata($fid);
$fo = dirname($fm).'/'.$meta['original_image'];
$h = hash_file('sha256',$fo);
$directory_mode = fileperms(dirname($fo)) & 0777;
chmod(dirname($fo), 0555);
try {
 err(ewpa_replace_media_original($fp),'copy_failed','unwritable staging directory refused');
 assertion(hash_file('sha256',$fo)===$h,'failed staging original unchanged');
} finally {
 chmod(dirname($fo), $directory_mode);
}
assertion(array() === glob(dirname($fo).'/.ewpa-original-*'),'no staging files leaked by refused operations');
// Genuine stale state (not only wrong supplied guards), plus independent-connection contention.
foreach ( array( 'main', 'metadata' ) as $change ) {
	$fid  = make_fixture();
	$fp   = params_for( $fid );
	$fm   = get_attached_file( $fid );
	$meta = wp_get_attachment_metadata( $fid );
	$fo   = dirname( $fm ) . '/' . $meta['original_image'];
	$h    = hash_file( 'sha256', $fo );
	if ( $change === 'main' ) {
		file_put_contents( $fm, ' ', FILE_APPEND );
	} else {
		$wpdb->update( $wpdb->posts, array( 'post_excerpt' => 'concurrent change' ), array( 'ID' => $fid ) );}
	err( ewpa_replace_media_original( $fp ), 'stale_input', 'actual ' . $change . ' drift refused' );
	assertion( $h === hash_file( 'sha256', $fo ), 'drift left original unchanged' );
}
$fid    = make_fixture();
$fp     = params_for( $fid );
$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lock   = 'ewpa-original-' . substr( hash( 'sha256', $wpdb->prefix ), 0, 32 );
assertion( '1' === (string) $second->get_var( $second->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ), 'independent connection owns mutex' );
err( ewpa_replace_media_original( $fp ), 'busy', 'nonblocking contention refusal' );
$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
$second->close();
// Mutation during the second snapshot must be refused before rename.
$fid   = make_fixture();
$fp    = params_for( $fid );
$fm    = get_attached_file( $fid );
$meta  = wp_get_attachment_metadata( $fid );
$fo    = dirname( $fm ) . '/' . $meta['original_image'];
$h     = hash_file( 'sha256', $fo );
$calls = 0;
$drift = static function ( $types ) use ( &$calls, $fm ) {
	if ( ++$calls === 2 ) {
		file_put_contents( $fm, ' ', FILE_APPEND );
	}
	return $types;
};
add_filter( 'upload_mimes', $drift );
err( ewpa_replace_media_original( $fp ), 'file_changed', 'source drift during IO refused' );
remove_filter( 'upload_mimes', $drift );
assertion( hash_file( 'sha256', $fo ) === $h, 'late drift no original mutation' );
$fid   = make_fixture();
$fp    = params_for( $fid );
$fm    = get_attached_file( $fid );
$meta  = wp_get_attachment_metadata( $fid );
$fo    = dirname( $fm ) . '/' . $meta['original_image'];
$h     = hash_file( 'sha256', $fo );
$calls = 0;
$drift = static function ( $types ) use ( &$calls, $fid ) {
	global $wpdb;
	if ( ++$calls === 2 ) {
		$wpdb->update( $wpdb->posts, array( 'post_excerpt' => 'late DB drift' ), array( 'ID' => $fid ) );
	} return $types;
};
add_filter( 'upload_mimes', $drift );
err( ewpa_replace_media_original( $fp ), 'file_changed', 'metadata drift during hashing refused before commit' );
remove_filter( 'upload_mimes', $drift );
assertion( hash_file( 'sha256', $fo ) === $h, 'late metadata drift no original mutation' );
$fid  = make_fixture();
$fm   = get_attached_file( $fid );
$meta = wp_get_attachment_metadata( $fid );
$fo   = dirname( $fm ) . '/' . $meta['original_image'];
copy( $fm, $fo );
file_put_contents( $fm, ' ', FILE_APPEND );
err( ewpa_replace_media_original( params_for( $fid ) ), 'no_byte_benefit', 'genuinely smaller original remains' );
// Exercise genuine registry/schema/permission execution (HTTP is run separately).
do_action( 'wp_abilities_api_init' );
$ability = wp_get_ability( 'ewpa/inspect-media-original' );
assertion( $ability instanceof WP_Ability, 'native ability registered' );
$result = $ability->execute( array( 'attachment_id' => $id ) );
assertion( ! is_wp_error( $result ) && $result['attachment_id'] === $id, 'native ability execute' );
assertion( strpos( wp_json_encode( $result ), ABSPATH ) === false, 'response has no absolute server paths' );
echo 'ACCEPTANCE_CHECKS=' . $GLOBALS['acceptance_checks'] . "\nMEDIA_ORIGINAL_ACCEPTANCE_OK\n";
