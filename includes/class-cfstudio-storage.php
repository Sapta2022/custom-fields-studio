<?php
/**
 * Storage layer — one place that knows where a field value physically
 * lives, so field types never call get_post_meta()/get_option() directly.
 *
 * A "context" ($ctx) says where to read/write:
 *  - an int post ID  → post meta
 *  - the string 'option' → wp_options, keys prefixed "options_" (used by
 *    Options Pages)
 *  - the string 'user_N' → user meta of user N (user profile screens)
 *  - the string 'term_N' → term meta of term N (taxonomy term screens)
 *  - the string 'block' → an in-memory array, filled while a custom block
 *    renders (values live in the block's attributes, not the database)
 *
 * Composite fields (repeater, later flexible content) build compound keys
 * like "gallery_0_caption" and pass them in as $key, so this class stays
 * dumb: it only gets, updates and deletes one key.
 *
 * @package Fieldloom
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFStudio_Storage {

	const OPTION_PREFIX = 'options_';

	/** @var array In-memory values of the 'block' context (see CFStudio_Blocks). */
	protected static $block = array();

	/** Replace the in-memory block values, returning the previous ones. */
	public static function swap_block_values( $values ) {
		$old         = self::$block;
		self::$block = $values;

		return $old;
	}

	/** The in-memory block values. */
	public static function block_values() {
		return self::$block;
	}

	/**
	 * The wp_options key prefix: "options_", or "options_{lang}_" for a
	 * non-default language when option values are stored per language.
	 */
	protected static function option_prefix() {
		return self::OPTION_PREFIX . CFStudio_I18n::options_suffix();
	}

	/**
	 * Split a non-option, non-post context into array( meta_type, object_id ).
	 * Post IDs give array( 'post', id ); 'user_5' gives array( 'user', 5 ).
	 *
	 * @param int|string $ctx Context.
	 * @return array
	 */
	public static function target( $ctx ) {
		if ( is_string( $ctx ) && preg_match( '/^(user|term)_(\d+)$/', $ctx, $m ) ) {
			return array( $m[1], (int) $m[2] );
		}

		return array( 'post', (int) $ctx );
	}

	/**
	 * Read one stored value. Returns '' when nothing is stored (the same
	 * convention as get_post_meta( ..., true )).
	 *
	 * @param string     $key Storage key.
	 * @param int|string $ctx Post ID or 'option'.
	 * @return mixed
	 */
	public static function get( $key, $ctx ) {
		if ( 'block' === $ctx ) {
			return self::$block[ $key ] ?? '';
		}

		if ( 'option' === $ctx ) {
			return get_option( self::option_prefix() . $key, '' );
		}

		list( $type, $id ) = self::target( $ctx );

		return get_metadata( $type, $id, $key, true );
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
		if ( 'block' === $ctx ) {
			return array_key_exists( $key, self::$block );
		}

		if ( 'option' === $ctx ) {
			return false !== get_option( self::option_prefix() . $key, false );
		}

		list( $type, $id ) = self::target( $ctx );

		return metadata_exists( $type, $id, $key );
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
		if ( 'block' === $ctx ) {
			self::$block[ $key ] = $value;
			return;
		}

		if ( 'option' === $ctx ) {
			update_option( self::option_prefix() . $key, $value, false );
			return;
		}

		list( $type, $id ) = self::target( $ctx );

		update_metadata( $type, $id, $key, wp_slash( $value ) );
	}

	/**
	 * Delete one stored value.
	 *
	 * @param string     $key Storage key.
	 * @param int|string $ctx Post ID or 'option'.
	 */
	public static function delete( $key, $ctx ) {
		if ( 'block' === $ctx ) {
			unset( self::$block[ $key ] );
			return;
		}

		if ( 'option' === $ctx ) {
			delete_option( self::option_prefix() . $key );
			return;
		}

		list( $type, $id ) = self::target( $ctx );

		delete_metadata( $type, $id, $key );
	}
}
