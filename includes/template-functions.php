<?php
/**
 * Template API — the functions theme/plugin code actually calls to read
 * CFS field values. Deliberately ACF-shaped function names/signatures
 * (get_field/the_field/have_rows/...) so templates read familiarly, but
 * this is an original implementation against our own storage.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a single field's formatted value for a post.
 *
 * @param string   $selector Field name or field key.
 * @param int|null $post_id  Post ID, defaults to the current post in The Loop.
 * @param bool     $format   Whether to run the value through the field
 *                           type's format_value() (e.g. image → array/url,
 *                           true_false → real boolean). Pass false to get
 *                           the raw stored value instead.
 * @return mixed|null Null when the field isn't found or has no value.
 */
function cfs_get_field( $selector, $post_id = null, $format = true ) {
	$post_id = cfs_resolve_post_id( $post_id );
	if ( ! $post_id ) {
		return null;
	}

	$post_type = get_post_type( $post_id );
	if ( ! $post_type ) {
		return null;
	}

	$groups = CFS_Field_Group::get_matching_groups( $post_type );
	$field  = CFS_Field_Group::find_field( $groups, $selector );
	if ( ! $field ) {
		return null;
	}

	$value = get_post_meta( $post_id, $field['name'], true );

	if ( '' === $value || null === $value ) {
		return null;
	}

	if ( ! $format ) {
		return $value;
	}

	$type_class = CFS_Field_Registry::get_class( $field['type'] );
	if ( ! $type_class ) {
		return $value;
	}

	return $type_class::format_value( $value, $field );
}

/**
 * Echo a field's value. Scalar values are printed as-is (escaped);
 * array/object values (e.g. an unformatted image field) are printed as
 * a readable var_export() rather than silently producing "Array" —
 * that's a shape mismatch in the calling template, and being loud about
 * it is more useful than emitting nothing.
 *
 * @param string   $selector Field name or field key.
 * @param int|null $post_id  Post ID, defaults to the current post.
 */
function cfs_the_field( $selector, $post_id = null ) {
	$value = cfs_get_field( $selector, $post_id );

	if ( null === $value ) {
		return;
	}

	if ( is_array( $value ) || is_object( $value ) ) {
		echo '<pre>' . esc_html( print_r( $value, true ) ) . '</pre>'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- intentional readable fallback for a shape mismatch, not left-over debug code.
		return;
	}

	echo esc_html( $value );
}

/**
 * Get every field's formatted value for a post, keyed by field name.
 * Useful for dumping a whole field group at once (e.g. building a REST
 * response or an array to hand to a template partial).
 *
 * @param int|null $post_id Post ID, defaults to the current post.
 * @return array
 */
function cfs_get_fields( $post_id = null ) {
	$post_id = cfs_resolve_post_id( $post_id );
	if ( ! $post_id ) {
		return array();
	}

	$post_type = get_post_type( $post_id );
	if ( ! $post_type ) {
		return array();
	}

	$groups = CFS_Field_Group::get_matching_groups( $post_type );
	$values = array();

	foreach ( $groups as $group ) {
		foreach ( $group['fields'] as $field ) {
			$values[ $field['name'] ] = cfs_get_field( $field['name'], $post_id );
		}
	}

	return $values;
}

/**
 * Resolve a $post_id argument the way ACF's functions do: an explicit ID
 * is used as-is, and null falls back to the current post in The Loop.
 *
 * @param int|null $post_id Explicit post ID, or null.
 * @return int 0 if no post could be resolved.
 */
function cfs_resolve_post_id( $post_id ) {
	if ( null !== $post_id ) {
		return absint( $post_id );
	}

	$current = get_post();
	return $current ? $current->ID : 0;
}
