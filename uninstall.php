<?php
/**
 * Fires only on deliberate "Delete" from the Plugins screen (not on
 * deactivation). Removes Field Group posts and their meta.
 *
 * Deliberately does NOT touch the field *values* stored on regular
 * posts/pages/options (e.g. `my_repeater_0_subfield`) — those are the
 * site's content, not the plugin's own data, and removing them here
 * would be destructive and surprising.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$field_groups = get_posts(
	array(
		'post_type'      => 'cfs_field_group',
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
	)
);

foreach ( $field_groups as $post_id ) {
	wp_delete_post( $post_id, true );
}
