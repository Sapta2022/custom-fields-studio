<?php
/**
 * Storage layer — one place that knows where a field value physically
 * lives, so field types never call get_post_meta()/get_option() directly.
 *
 * A "context" ($ctx) says where to read/write:
 *  - an int post ID  → post meta
 *  - the string 'option' → wp_options, keys prefixed "options_" (used by
 *    Options Pages in a later phase)
 *
 * Composite fields (repeater, later flexible content) build compound keys
 * like "gallery_0_caption" and pass them in as $key, so this class stays
 * dumb: it only gets, updates and deletes one key.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Storage {

	const OPTION_PREFIX = 'options_';

	/**
	 * Read one stored value. Returns '' when nothing is stored (the same
	 * convention as get_post_meta( ..., true )).
	 *
	 * @param string     $key Storage key.
	 * @param int|string $ctx Post ID or 'option'.
	 * @return mixed
	 */
	public static function get( $key, $ctx ) {
		if ( 'option' === $ctx ) {
			return get_option( self::OPTION_PREFIX . $key, '' );
		}

		return get_post_meta( (int) $ctx, $key, true );
	}

	/**
	 * Whether a key has ever been written (even with an empty value).
	 * Used to tell "never saved, show the default" apart from "saved on
	 * purpose as empty".
	 *
	 * @param string     $key Storage key.
	 * @param int|string $ctx Post ID or 'option'.
	 * @return bool
	 */
	public static function exists( $key, $ctx ) {
		if ( 'option' === $ctx ) {
			return false !== get_option( self::OPTION_PREFIX . $key, false );
		}

		return metadata_exists( 'post', (int) $ctx, $key );
	}

	/**
	 * Write one value.
	 *
	 * update_post_meta() expects SLASHED data (it runs wp_unslash() on the
	 * value), so values are wp_slash()ed first — otherwise any backslash in
	 * a saved value (a Windows path, an escaped regex) is silently eaten.
	 * update_option() does not unslash, so options are stored as-is.
	 *
	 * @param string     $key   Storage key.
	 * @param mixed      $value Value to store.
	 * @param int|string $ctx   Post ID or 'option'.
	 */
	public static function update( $key, $value, $ctx ) {
		if ( 'option' === $ctx ) {
			update_option( self::OPTION_PREFIX . $key, $value, false );
			return;
		}

		update_post_meta( (int) $ctx, $key, wp_slash( $value ) );
	}

	/**
	 * Delete one stored value.
	 *
	 * @param string     $key Storage key.
	 * @param int|string $ctx Post ID or 'option'.
	 */
	public static function delete( $key, $ctx ) {
		if ( 'option' === $ctx ) {
			delete_option( self::OPTION_PREFIX . $key );
			return;
		}

		delete_post_meta( (int) $ctx, $key );
	}
}
