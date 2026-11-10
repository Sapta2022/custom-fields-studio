<?php
/**
 * One-time data migration from the pre-0.13 "cfs" prefix to "cfstudio".
 *
 * Renames the field-group post type, the group meta keys, the plugin
 * options, and the block namespace used inside saved post content.
 * Field VALUES (the site's own content) are never touched.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFStudio_Migrate {

	const DONE_OPTION = 'cfstudio_prefix_migrated';

	/**
	 * Run once per site (called from the loader on plugins_loaded, before the post type is registered).
	 */
	public static function maybe_run() {
		$done = get_option( self::DONE_OPTION );
		if ( ! $done ) {
			self::run();
		}
		// 0.13.1: block names inside saved location rules (missed in 0.13.0).
		if ( ! $done || version_compare( (string) $done, '0.13.1', '<' ) ) {
			self::migrate_block_locations();
		}
		if ( $done !== CFSTUDIO_VERSION ) {
			update_option( self::DONE_OPTION, CFSTUDIO_VERSION, false );
		}
	}

	/**
	 * Perform the renames. Safe to call repeatedly.
	 */
	public static function run() {
		global $wpdb;

		// Field group posts.
		$wpdb->update( $wpdb->posts, array( 'post_type' => 'cfstudio_field_group' ), array( 'post_type' => 'cfs_field_group' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Field group meta keys.
		foreach ( array( 'fields', 'location', 'options', 'group_key', 'json_modified' ) as $key ) {
			$wpdb->update( $wpdb->postmeta, array( 'meta_key' => '_cfstudio_' . $key ), array( 'meta_key' => '_cfs_' . $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// Plugin options.
		foreach ( array( 'options_pages', 'google_maps_api_key', 'translate_options', 'version' ) as $key ) {
			$old = get_option( 'cfs_' . $key, null );
			if ( null !== $old && false === get_option( 'cfstudio_' . $key, false ) ) {
				update_option( 'cfstudio_' . $key, $old );
			}
			delete_option( 'cfs_' . $key );
		}

		// Block namespace inside saved content.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"UPDATE {$wpdb->posts} SET post_content = REPLACE(REPLACE(post_content, '<!-- wp:cfs/', '<!-- wp:cfstudio/'), '<!-- /wp:cfs/', '<!-- /wp:cfstudio/') WHERE post_content LIKE '%wp:cfs/%'"
		);

		clean_post_cache( 0 );
		wp_cache_flush();
	}

	/**
	 * Rewrite "cfs/<name>" block values in every group's location rules.
	 */
	public static function migrate_block_locations() {
		$ids = get_posts(
			array(
				'post_type'   => 'cfstudio_field_group',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			$location = get_post_meta( $id, '_cfstudio_location', true );
			if ( ! is_array( $location ) ) {
				continue;
			}
			$changed = false;
			foreach ( $location as $gi => $group ) {
				foreach ( (array) $group as $ri => $rule ) {
					if ( isset( $rule['param'], $rule['value'] ) && 'block' === $rule['param'] && 0 === strpos( (string) $rule['value'], 'cfs/' ) ) {
						$location[ $gi ][ $ri ]['value'] = 'cfstudio/' . substr( $rule['value'], 4 );
						$changed                         = true;
					}
				}
			}
			if ( $changed ) {
				update_post_meta( $id, '_cfstudio_location', $location );
			}
		}
	}
}
