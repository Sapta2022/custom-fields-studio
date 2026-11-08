<?php
/**
 * Multilingual support (WPML and Polylang).
 *
 *  - Options page values can be stored per language: tick the setting on the
 *    Tools page and a non-default language reads/writes "options_{lang}_name"
 *    instead of "options_name" (the default language keeps the plain keys, so
 *    turning this on loses nothing). In wp-admin the language you are editing
 *    in (WPML's / Polylang's language switcher) decides which set you edit.
 *  - Each field has a Translation setting. "Copy" fields are copied from the
 *    original post to its translations by Polylang (including all repeater /
 *    group sub values); "Ignore" fields are never copied.
 *    WPML has its own per-custom-field settings in its Translation Management
 *    screen; use those for WPML (this plugin does not change them).
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_I18n {

	const OPTION = 'cfs_translate_options';

	public static function hooks() {
		add_filter( 'pll_copy_post_metas', array( __CLASS__, 'polylang_copy_metas' ), 10, 5 );
	}

	/** Whether Polylang or WPML is active. */
	public static function active() {
		return function_exists( 'pll_current_language' ) || has_filter( 'wpml_current_language' ) || defined( 'ICL_SITEPRESS_VERSION' );
	}

	/** Slug of the language being viewed/edited, or '' (all languages / none). */
	public static function current_language() {
		$lang = '';

		if ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language( 'slug' );
		} elseif ( has_filter( 'wpml_current_language' ) ) {
			$lang = apply_filters( 'wpml_current_language', null );
		}

		return ( is_string( $lang ) && 'all' !== $lang ) ? sanitize_key( $lang ) : '';
	}

	/** Slug of the site's default language, or ''. */
	public static function default_language() {
		$lang = '';

		if ( function_exists( 'pll_default_language' ) ) {
			$lang = pll_default_language( 'slug' );
		} elseif ( has_filter( 'wpml_default_language' ) ) {
			$lang = apply_filters( 'wpml_default_language', null );
		}

		return is_string( $lang ) ? sanitize_key( $lang ) : '';
	}

	/** Whether options page values are stored per language. */
	public static function translate_options() {
		return (bool) apply_filters( 'cfs/translate_options', (bool) get_option( self::OPTION, false ) );
	}

	/**
	 * Storage key infix for options values: '' (no multilingual plugin, the
	 * feature is off, or this is the default language) or "fr_".
	 */
	public static function options_suffix() {
		if ( ! self::active() || ! self::translate_options() ) {
			return '';
		}

		$lang = self::current_language();
		if ( '' === $lang || $lang === self::default_language() ) {
			return '';
		}

		return $lang . '_';
	}

	/**
	 * Polylang: meta keys to copy when a translation is created / synced.
	 *
	 * @param string[] $keys Meta keys Polylang would copy.
	 * @param bool     $sync True when synchronising, false when creating.
	 * @param int      $from Source post ID.
	 * @param int      $to   Target post ID.
	 * @param string   $lang Target language.
	 * @return string[]
	 */
	public static function polylang_copy_metas( $keys, $sync = false, $from = 0, $to = 0, $lang = '' ) {
		$keys = (array) $keys;
		$from = (int) $from;
		if ( ! $from || ! get_post( $from ) ) {
			return $keys;
		}

		$copy   = array();
		$ignore = array();
		foreach ( CFS_Field_Group::get_groups_for_post( $from ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				$mode = $field['translate'] ?? 'translate';
				if ( 'copy' === $mode ) {
					$copy[] = $field;
				} elseif ( 'ignore' === $mode ) {
					$ignore[] = $field;
				}
			}
		}

		if ( ! $copy && ! $ignore ) {
			return $keys;
		}

		$meta_keys = array_keys( (array) get_post_meta( $from ) );

		foreach ( $meta_keys as $meta_key ) {
			foreach ( $ignore as $field ) {
				if ( self::key_belongs_to( $meta_key, $field ) ) {
					$keys = array_diff( $keys, array( $meta_key ) );
				}
			}
			foreach ( $copy as $field ) {
				if ( self::key_belongs_to( $meta_key, $field ) ) {
					$keys[] = $meta_key;
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Whether a stored meta key is one of a field's keys: its value, its
	 * "_name" reference, and (repeater / flexible / group) its sub values.
	 */
	public static function key_belongs_to( $meta_key, $field ) {
		$name = preg_quote( $field['name'], '/' );

		if ( in_array( $field['type'], array( 'repeater', 'flexible_content' ), true ) ) {
			return (bool) preg_match( '/^_?' . $name . '(_\d+(_.*)?)?$/', $meta_key );
		}

		if ( 'group' === $field['type'] ) {
			return (bool) preg_match( '/^_?' . $name . '(_.*)?$/', $meta_key );
		}

		return (bool) preg_match( '/^_?' . $name . '$/', $meta_key );
	}
}
