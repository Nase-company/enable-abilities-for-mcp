<?php
/**
 * Narrow, opt-in replacement of a retained original with its own main image.
 * No database writes, image re-encoding, caller paths, or backup implementation.
 *
 * @package EnableAbilitiesForMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Native local IO is required for inode checks, exclusive creation and atomic rename.
// Suppress native warnings only after checking results: paths must not reach API output.
// Canonical DB reads intentionally bypass filters/caches; core metadata is serialized,
// and unserialize explicitly disallows object classes. No caller SQL or paths are accepted.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors, WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

add_action( 'wp_abilities_api_init', 'ewpa_register_media_original_abilities', 20 );

/** Register the two abilities only when enabled by the existing settings UI. */
function ewpa_register_media_original_abilities(): void {
	foreach ( array( 'inspect', 'replace' ) as $operation ) {
		$name = 'ewpa/' . $operation . '-media-original';
		if ( ! ewpa_is_ability_enabled( $name ) ) {
			continue;
		}
		$properties = array(
			'attachment_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		if ( 'replace' === $operation ) {
			foreach ( array( 'expected_original_sha256', 'expected_main_sha256', 'expected_metadata_sha256', 'external_archive_sha256' ) as $key ) {
				$properties[ $key ] = array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				);
			}
			$properties['confirm_replace'] = array(
				'type' => 'boolean',
				'enum' => array( true ),
			);
		}
		ewpa_register_ability_with_log(
			$name,
			array(
				'label'               => ucfirst( $operation ) . ' Media Original',
				'description'         => 'inspect' === $operation ? 'Inspect one retained JPEG/WebP original and its existing main image, including stale guards. Does not write files.' : 'DESTRUCTIVE, opt-in. Overwrite ONLY this attachment’s preserved original at the same path with exact existing main bytes. Requires independently archived and verified old original. external_archive_sha256 acknowledges that responsibility; it is NOT server-verified backup proof. No database changes or re-encoding.',
				'category'            => 'content-management',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => array_keys( $properties ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => 'ewpa_media_original_permission',
				'execute_callback'    => 'ewpa_' . $operation . '_media_original',
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => 'inspect' === $operation,
						'destructive' => 'replace' === $operation,
						'idempotent'  => false,
					),
				),
			)
		);
	}
}

/** Native object-level authorization, shared by transport and direct callbacks.
 *
 * @param mixed $input Ability input.
 */
function ewpa_media_original_permission( $input ): bool {
	return is_array( $input ) && isset( $input['attachment_id'] ) && is_int( $input['attachment_id'] ) && $input['attachment_id'] > 0 && current_user_can( 'manage_options' ) && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $input['attachment_id'] );
}

/** Path-free failures, including restricted-host filesystem failures.
 *
 * @param string $code Safe error category.
 */
function ewpa_media_original_error( string $code ): WP_Error {
	return new WP_Error( $code, 'Media original operation refused: ' . $code . '. No backup is created or verified by this ability.' );
}

/** Reject unknown keys even for direct PHP invocation.
 *
 * @param mixed $input Ability input.
 * @param bool  $replace Whether this is a write.
 */
function ewpa_media_original_input( $input, bool $replace ): bool {
	$keys = array( 'attachment_id' );
	if ( $replace ) {
		$keys = array_merge( $keys, array( 'expected_original_sha256', 'expected_main_sha256', 'expected_metadata_sha256', 'external_archive_sha256', 'confirm_replace' ) );
	}
	if ( ! is_array( $input ) || array_diff( array_keys( $input ), $keys ) || array_diff( $keys, array_keys( $input ) ) || ! is_int( $input['attachment_id'] ) || $input['attachment_id'] < 1 ) {
		return false;
	}
	foreach ( $keys as $key ) {
		if ( false !== strpos( $key, 'sha256' ) && ( ! is_string( $input[ $key ] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input[ $key ] ) ) ) {
			return false;
		}
	}
	return ! $replace || is_bool( $input['confirm_replace'] );
}

/** Fail closed when required native functions are unavailable. */
function ewpa_media_original_filesystem_available(): bool {
	foreach ( array( 'realpath', 'lstat', 'fstat', 'fopen', 'fclose', 'fread', 'fseek', 'feof', 'fwrite', 'fflush', 'fsync', 'flock', 'rename', 'unlink', 'chmod', 'chown', 'chgrp', 'touch', 'hash_init', 'hash_update_stream', 'hash_final', 'hash_file', 'stream_copy_to_stream', 'random_bytes', 'getimagesize' ) as $function ) {
		if ( ! function_exists( $function ) ) {
			return false;
		}
	}
	return DIRECTORY_SEPARATOR === '/';
}

/** Fresh canonical database rows, not attachment filters or request caches.
 *
 * @param int $id Attachment ID.
 */
function ewpa_media_original_rows( int $id ) {
	global $wpdb;
	$previous = $wpdb->suppress_errors( true );
	try {
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
		if ( $wpdb->last_error || ! $post || 'attachment' !== $post['post_type'] ) {
			return ewpa_media_original_error( 'invalid_attachment' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $id ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			return ewpa_media_original_error( 'metadata_unavailable' );
		}
		$values = array();
		foreach ( $rows as $row ) {
			$values[ $row['meta_key'] ][] = $row['meta_value'];
		}
		if ( 1 !== count( $values['_wp_attachment_metadata'] ?? array() ) || 1 !== count( $values['_wp_attached_file'] ?? array() ) ) {
			return ewpa_media_original_error( 'invalid_metadata' );
		}
		$metadata = @unserialize( $values['_wp_attachment_metadata'][0], array( 'allowed_classes' => false ) );
		if ( ! is_array( $metadata ) || ! isset( $metadata['file'], $metadata['original_image'] ) || $metadata['file'] !== $values['_wp_attached_file'][0] || ( isset( $metadata['sizes'] ) && ! is_array( $metadata['sizes'] ) ) ) {
			return ewpa_media_original_error( 'invalid_metadata' );
		}
		foreach ( $metadata['sizes'] ?? array() as $size ) {
			if ( ! is_array( $size ) || ! isset( $size['file'] ) || ! is_string( $size['file'] ) ) {
				return ewpa_media_original_error( 'invalid_metadata' );
			}
		}
		return array(
			'metadata' => $metadata,
			'mime'     => $post['post_mime_type'],
			'digest'   => hash( 'sha256', serialize( array( $post, $rows ) ) ),
		);
	} finally {
		$wpdb->suppress_errors( $previous );
	}
}

/** Safe relative path; no URL, traversal, controls, ambiguous separators or normalization.
 *
 * @param mixed $path Canonical metadata path.
 */
function ewpa_media_original_relative( $path ): bool {
	if ( ! is_string( $path ) || strlen( $path ) > 1024 ) {
		return false;
	}
	foreach ( explode( '/', $path ) as $part ) {
		if ( ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $part ) || '.' === $part || '..' === $part ) {
			return false;
		}
	}
	return true;
}

/** Pin every ancestor, including the actual upload root; no symlinks or world-write.
 *
 * @param string $directory Absolute parent directory.
 */
function ewpa_media_original_parents( string $directory ) {
	$parents = array();
	do {
		clearstatcache( true, $directory );
		$stat = @lstat( $directory );
		if ( ! $stat || 0040000 !== ( $stat['mode'] & 0170000 ) || ( $stat['mode'] & 0002 ) ) {
			return false;
		}
		$parents[ $directory ] = array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'mode', 'uid', 'gid' ) ) );
		$directory             = dirname( $directory );
	} while ( ! isset( $parents[ $directory ] ) );
	return $parents;
}

/** Regular single-link file only.
 *
 * @param string $path Canonical file path.
 */
function ewpa_media_original_stat( string $path ) {
	clearstatcache( true, $path );
	$stat = @lstat( $path );
	return $stat && 0100000 === ( $stat['mode'] & 0170000 ) && 0 === ( $stat['mode'] & 07000 ) && 1 === $stat['nlink'] ? $stat : false;
}

/** Compare persistent identity/attributes, excluding read-induced atime.
 *
 * @param array $stat Native stat result.
 */
function ewpa_media_original_identity( $stat ): array {
	return is_array( $stat ) ? array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime' ) ) ) : array();
}

/** Reject files also recorded by another attachment, including originals and variants.
 *
 * @param int    $id Current attachment ID.
 * @param string $relative Upload-relative destination.
 */
function ewpa_media_original_exclusive( int $id, string $relative ) {
	global $wpdb;
	$previous = $wpdb->suppress_errors( true );
	try {
		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_key, pm.meta_value, af.meta_value AS attached_file FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id LEFT JOIN {$wpdb->postmeta} af ON af.post_id = pm.post_id AND af.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment' AND pm.post_id <> %d AND ((pm.meta_key = '_wp_attached_file' AND pm.meta_value = %s) OR (pm.meta_key IN ('_wp_attachment_metadata', '_wp_attachment_backup_sizes') AND pm.meta_value LIKE %s)) LIMIT 101",
				$id,
				$relative,
				'%' . $wpdb->esc_like( basename( $relative ) ) . '%'
			),
			ARRAY_A
		);
		if ( $wpdb->last_error || ! is_array( $records ) || count( $records ) > 100 ) {
			return ewpa_media_original_error( 'metadata_unavailable' );
		}
		foreach ( $records as $record ) {
			if ( '_wp_attached_file' === $record['meta_key'] ) {
				return ewpa_media_original_error( 'shared_file' );
			}
			$metadata = @unserialize( $record['meta_value'], array( 'allowed_classes' => false ) );
			if ( ! is_array( $metadata ) ) {
				return ewpa_media_original_error( 'metadata_unavailable' );
			}
			$files = array( $record['attached_file'] );
			if ( '_wp_attachment_backup_sizes' === $record['meta_key'] ) {
				$base = dirname( (string) $record['attached_file'] );
				$base = '.' === $base ? '' : $base . '/';
				foreach ( $metadata as $size ) {
					if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) ) {
						$files[] = $base . $size['file'];
					}
				}
			} else {
				$files[] = $metadata['file'] ?? '';
				$base    = dirname( (string) ( $metadata['file'] ?? $record['attached_file'] ) );
				$base    = '.' === $base ? '' : $base . '/';
				foreach ( array( 'original_image', 'source_image', 'animated_video', 'animated_video_poster' ) as $key ) {
					if ( isset( $metadata[ $key ] ) && is_string( $metadata[ $key ] ) ) {
						$files[] = $base . $metadata[ $key ];
					}
				}
				foreach ( is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array() as $size ) {
					if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) ) {
						$files[] = $base . $size['file'];
					}
				}
			}
			if ( in_array( $relative, $files, true ) ) {
				return ewpa_media_original_error( 'shared_file' );
			}
		}
		return true;
	} finally {
		$wpdb->suppress_errors( $previous );
	}
}

/** Detect animation by scanning WebP container chunks, never decoding pixel data.
 *
 * @param string $path Canonical WebP path.
 * @param int    $size Pinned byte length.
 */
function ewpa_media_original_static_webp( string $path, int $size ): bool {
	$stream = @fopen( $path, 'rb' );
	if ( ! $stream ) {
		return false;
	}
	try {
		$header = @fread( $stream, 12 );
		if ( 12 !== strlen( $header ) || 'RIFF' !== substr( $header, 0, 4 ) || 'WEBP' !== substr( $header, 8, 4 ) || unpack( 'V', substr( $header, 4, 4 ) )[1] + 8 !== $size ) {
			return false;
		}
		$offset = 12;
		for ( $count = 0; $offset < $size && $count < 1024; ++$count ) {
			$chunk = @fread( $stream, 8 );
			if ( 8 !== strlen( $chunk ) ) {
				return false;
			}
			$tag    = substr( $chunk, 0, 4 );
			$length = unpack( 'V', substr( $chunk, 4, 4 ) )[1];
			$next   = $offset + 8 + $length + ( $length % 2 );
			if ( $next > $size || in_array( $tag, array( 'ANIM', 'ANMF' ), true ) || ( 'VP8X' === $tag && ( $length < 10 || ( ord( str_pad( (string) @fread( $stream, 1 ), 1, "\0" ) ) & 2 ) ) ) ) {
				return false;
			}
			if ( 0 !== @fseek( $stream, $next ) ) {
				return false;
			}
			$offset = $next;
		}
		return $offset === $size;
	} finally {
		@fclose( $stream );
	}
}

/** Resolve only current canonical uploads and hash bounded files without decoding.
 *
 * @param int $id Attachment ID.
 */
function ewpa_media_original_snapshot( int $id ) {
	if ( ! ewpa_media_original_filesystem_available() ) {
		return ewpa_media_original_error( 'filesystem_unavailable' );
	}
	$rows = ewpa_media_original_rows( $id );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$m       = $rows['metadata'];
	$uploads = wp_get_upload_dir();
	$root    = rtrim( $uploads['basedir'] ?? '', '/' );
	if ( ! empty( $uploads['error'] ) || ! $root || @realpath( $root ) !== $root || ! ewpa_media_original_relative( $m['file'] ) || ! ewpa_media_original_relative( $m['original_image'] ) || false !== strpos( $m['original_image'], '/' ) ) {
		return ewpa_media_original_error( 'unsafe_path' );
	}
	$main      = $root . '/' . $m['file'];
	$original  = dirname( $main ) . '/' . $m['original_image'];
	$exclusive = ewpa_media_original_exclusive( $id, substr( $original, strlen( $root ) + 1 ) );
	if ( is_wp_error( $exclusive ) ) {
		return $exclusive;
	}
	$parents = ewpa_media_original_parents( dirname( $main ) );
	if ( $main === $original || ! $parents ) {
		return ewpa_media_original_error( 'unsafe_path' );
	}
	foreach ( $m['sizes'] ?? array() as $size ) {
		if ( ( $size['file'] ?? null ) === $m['original_image'] ) {
			return ewpa_media_original_error( 'unsafe_path' );
		}
	}
	$mime    = $rows['mime'];
	$allowed = get_allowed_mime_types();
	if ( ! in_array( $mime, array( 'image/jpeg', 'image/webp' ), true ) || ! in_array( $mime, $allowed, true ) ) {
		return ewpa_media_original_error( 'unsupported_format' );
	}
	$snapshot = array(
		'rows'    => $rows,
		'parents' => $parents,
	);
	foreach ( array(
		'main'     => $main,
		'original' => $original,
	) as $name => $path ) {
		$stat = ewpa_media_original_stat( $path );
		if ( ! $stat || $stat['size'] < 1 || $stat['size'] > 512 * 1024 * 1024 || @realpath( $path ) !== $path ) {
			return ewpa_media_original_error( 'unsafe_file' );
		}
		$type  = wp_check_filetype( basename( $path ), $allowed );
		$image = @getimagesize( $path );
		if ( $type['type'] !== $mime || ! $image || ( $image['mime'] ?? '' ) !== $mime || ( 'image/webp' === $mime && ! ewpa_media_original_static_webp( $path, $stat['size'] ) ) ) {
			return ewpa_media_original_error( 'unsupported_format' );
		}
		if ( 'main' === $name && ( (int) ( $m['width'] ?? 0 ) !== $image[0] || (int) ( $m['height'] ?? 0 ) !== $image[1] ) ) {
			return ewpa_media_original_error( 'invalid_metadata' );
		}
		$hash  = @hash_file( 'sha256', $path );
		$after = ewpa_media_original_stat( $path );
		if ( ! $hash || ! $after || ewpa_media_original_identity( $stat ) !== ewpa_media_original_identity( $after ) ) {
			return ewpa_media_original_error( 'file_changed' );
		}
		$snapshot[ $name ] = array(
			'path'   => $path,
			'stat'   => ewpa_media_original_identity( $stat ),
			'sha256' => $hash,
		);
	}
	// Hashing can take time: reject DB/parent drift during this snapshot itself.
	$final_rows = ewpa_media_original_rows( $id );
	if ( is_wp_error( $final_rows ) || $final_rows !== $rows || ewpa_media_original_parents( dirname( $main ) ) !== $parents ) {
		return ewpa_media_original_error( 'file_changed' );
	}
	return $snapshot;
}

/** Public path-free inspection/readback.
 *
 * @param int   $id Attachment ID.
 * @param array $s Pinned snapshot.
 */
function ewpa_media_original_summary( int $id, array $s ): array {
	return array(
		'attachment_id'    => $id,
		'main_file'        => $s['rows']['metadata']['file'],
		'original_file'    => $s['rows']['metadata']['original_image'],
		'mime_type'        => $s['rows']['mime'],
		'main_bytes'       => $s['main']['stat']['size'],
		'original_bytes'   => $s['original']['stat']['size'],
		'bytes_saved'      => max( 0, $s['original']['stat']['size'] - $s['main']['stat']['size'] ),
		'main_sha256'      => $s['main']['sha256'],
		'original_sha256'  => $s['original']['sha256'],
		'metadata_sha256'  => $s['rows']['digest'],
		'has_byte_benefit' => $s['original']['stat']['size'] > $s['main']['stat']['size'],
		'backup_verified'  => false,
	);
}

/** Read-only inspection, including for a retained original with no byte benefit.
 *
 * @param mixed $input Ability input.
 */
function ewpa_inspect_media_original( $input ) {
	if ( ! ewpa_media_original_input( $input, false ) ) {
		return ewpa_media_original_error( 'invalid_input' );
	}
	if ( ! ewpa_is_ability_enabled( 'ewpa/inspect-media-original' ) ) {
		return ewpa_media_original_error( 'ability_disabled' );
	}
	if ( ! ewpa_media_original_permission( $input ) ) {
		return ewpa_media_original_error( 'forbidden' );
	}
	$s = ewpa_media_original_snapshot( $input['attachment_id'] );
	return is_wp_error( $s ) ? $s : ewpa_media_original_summary( $input['attachment_id'], $s );
}

/**
 * Atomic same-directory replace. Nonblocking DB mutex serializes this feature only.
 * Independent filesystem/DB writers do not share the mutex: see documented limits.
 *
 * @param mixed $input Ability input.
 */
function ewpa_replace_media_original( $input ) {
	if ( ! ewpa_media_original_input( $input, true ) ) {
		return ewpa_media_original_error( 'invalid_input' );
	}
	if ( ! ewpa_is_ability_enabled( 'ewpa/replace-media-original' ) ) {
		return ewpa_media_original_error( 'ability_disabled' );
	}
	if ( ! ewpa_media_original_permission( $input ) ) {
		return ewpa_media_original_error( 'forbidden' );
	}
	if ( true !== $input['confirm_replace'] || $input['external_archive_sha256'] !== $input['expected_original_sha256'] ) {
		return ewpa_media_original_error( 'archive_confirmation_required' );
	}
	global $wpdb;
	$lock = 'ewpa-original-' . substr( hash( 'sha256', $wpdb->prefix ), 0, 32 );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
		return ewpa_media_original_error( 'busy' );
	}
	$tmp        = null;
	$in         = null;
	$temp_owner = null;
	$out        = null;
	$committed  = false;
	try {
		$s = ewpa_media_original_snapshot( $input['attachment_id'] );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		if ( $s['main']['sha256'] !== $input['expected_main_sha256'] || $s['original']['sha256'] !== $input['expected_original_sha256'] || $s['rows']['digest'] !== $input['expected_metadata_sha256'] ) {
			return ewpa_media_original_error( 'stale_input' );
		}
		if ( $s['main']['stat']['size'] >= $s['original']['stat']['size'] ) {
			return ewpa_media_original_error( 'no_byte_benefit' );
		}
		$in = @fopen( $s['main']['path'], 'rb' );
		if ( ! $in || ! @flock( $in, LOCK_SH | LOCK_NB ) || ewpa_media_original_identity( @fstat( $in ) ) !== $s['main']['stat'] ) {
			return ewpa_media_original_error( 'file_changed' );
		}
		$tmp = dirname( $s['original']['path'] ) . '/.ewpa-original-' . bin2hex( random_bytes( 16 ) );
		$out = @fopen( $tmp, 'x+b' );
		if ( ! $out ) {
			$tmp = null; // Exclusive creation failed: never unlink somebody else's file.
			return ewpa_media_original_error( 'copy_failed' );
		}
		$temp_owner = @fstat( $out );
		if ( ! $temp_owner || ! @chmod( $tmp, 0600 ) || @stream_copy_to_stream( $in, $out, $s['main']['stat']['size'] + 1 ) !== $s['main']['stat']['size'] || ! @fflush( $out ) ) {
			return ewpa_media_original_error( 'copy_failed' );
		}
		$attrs = $s['original']['stat'];
		$owned = @fstat( $out );
		// Changed content needs a fresh modification time for HTTP conditional requests.
		if ( ! $owned || ( $owned['uid'] !== $attrs['uid'] && ! @chown( $tmp, $attrs['uid'] ) ) || ( $owned['gid'] !== $attrs['gid'] && ! @chgrp( $tmp, $attrs['gid'] ) ) || ! @chmod( $tmp, $attrs['mode'] & 0777 ) || ! @touch( $tmp, time() ) || ! @fsync( $out ) || @hash_file( 'sha256', $tmp ) !== $s['main']['sha256'] ) {
			return ewpa_media_original_error( 'copy_failed' );
		}
		// Fresh DB rows, parents, identities and hashes, immediately before commit.
		$now = ewpa_media_original_snapshot( $input['attachment_id'] );
		if ( is_wp_error( $now ) || $now !== $s || ! ewpa_is_ability_enabled( 'ewpa/replace-media-original' ) || ! ewpa_media_original_permission( $input ) || ewpa_media_original_identity( @fstat( $in ) ) !== $s['main']['stat'] ) {
			return ewpa_media_original_error( 'file_changed' );
		}
		$temp_stat = ewpa_media_original_stat( $tmp );
		if ( ! $temp_stat || $temp_stat['ino'] !== $owned['ino'] || $temp_stat['dev'] !== $owned['dev'] || @hash_file( 'sha256', $tmp ) !== $s['main']['sha256'] || ! @rename( $tmp, $s['original']['path'] ) ) {
			return ewpa_media_original_error( 'replace_failed' );
		}
		$committed = true;
		$tmp       = null;
		$readback  = ewpa_media_original_snapshot( $input['attachment_id'] );
		if ( is_wp_error( $readback ) || $readback['original']['sha256'] !== $s['main']['sha256'] || $readback['rows'] !== $s['rows'] || $readback['main'] !== $s['main'] ) {
			return ewpa_media_original_error( 'outcome_unknown' );
		}
		$result                 = ewpa_media_original_summary( $input['attachment_id'], $readback );
		$result['status']       = 'replaced';
		$result['bytes_saved']  = $attrs['size'] - $readback['original']['stat']['size'];
		$result['prior_sha256'] = $s['original']['sha256'];
		return $result;
	} catch ( Throwable $error ) {
		// Never expose exception text, which may include private filesystem paths.
		return ewpa_media_original_error( $committed ? 'outcome_unknown' : 'filesystem_error' );
	} finally {
		if ( is_resource( $in ) ) {
			@fclose( $in );
		}
		if ( is_resource( $out ) ) {
			@fclose( $out );
		}
		if ( null !== $tmp && $temp_owner ) {
			$current = ewpa_media_original_stat( $tmp );
			if ( $current && $current['dev'] === $temp_owner['dev'] && $current['ino'] === $temp_owner['ino'] ) {
				@unlink( $tmp );
			}
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}
