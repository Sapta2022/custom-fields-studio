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

	$groups = cfs_groups_for_context( $post_id );
	$field  = CFS_Field_Group::find_field( $groups, $selector );
	if ( ! $field ) {
		return null;
	}

	$type_class = CFS_Field_Registry::get_class( $field['type'] );
	if ( ! $type_class ) {
		return null;
	}

	// load_value() lets composite fields (repeater) reassemble their value
	// from several stored keys; simple fields read just one.
	$value = $type_class::load_value( $field, $post_id );

	if ( '' === $value || null === $value ) {
		return null;
	}

	if ( ! $format ) {
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

	$groups = cfs_groups_for_context( $post_id );
	$values = array();

	foreach ( $groups as $group ) {
		foreach ( $group['fields'] as $field ) {
			$class = CFS_Field_Registry::get_class( $field['type'] );
			if ( ! $class || ! $class::stores_value() ) {
				continue;
			}
			$values[ $field['name'] ] = cfs_get_field( $field['name'], $post_id );
		}
	}

	return $values;
}

/**
 * Resolve a $post_id argument the way ACF's functions do: an explicit ID
 * is used as-is, 'option' / 'options' means the Options Pages values, and
 * null falls back to the current post in The Loop.
 *
 * Every function below that takes $post_id accepts 'option' — the same
 * value is the storage "context" ($ctx) used by CFS_Storage.
 *
 * @param int|string|null $post_id Explicit post ID, 'option', or null.
 * @return int|string A post ID, the string 'option', or 0 if nothing could be resolved.
 */
function cfs_resolve_post_id( $post_id ) {
	if ( is_string( $post_id ) && in_array( strtolower( $post_id ), array( 'option', 'options' ), true ) ) {
		return 'option';
	}

	// 'user_5' / 'term_3' address user meta / term meta; a WP_User or
	// WP_Term object works too.
	if ( $post_id instanceof WP_User ) {
		return 'user_' . $post_id->ID;
	}
	if ( $post_id instanceof WP_Term ) {
		return 'term_' . $post_id->term_id;
	}
	if ( is_string( $post_id ) && preg_match( '/^(user|term)_(\d+)$/', $post_id ) ) {
		return strtolower( $post_id );
	}

	if ( null !== $post_id ) {
		return absint( $post_id );
	}

	$current = get_post();
	return $current ? $current->ID : 0;
}

/**
 * The field groups that apply to a resolved context: for a post, the
 * groups matching its post type; for 'option', the groups on any options
 * page.
 *
 * @param int|string $ctx Post ID, 'option', 'user_N' or 'term_N' (from cfs_resolve_post_id()).
 * @return array
 */
function cfs_groups_for_context( $ctx ) {
	if ( 'option' === $ctx ) {
		return CFS_Field_Group::get_options_groups();
	}

	if ( is_string( $ctx ) && preg_match( '/^user_(\d+)$/', $ctx, $m ) ) {
		return CFS_Field_Group::get_groups_for_user( (int) $m[1] );
	}

	if ( is_string( $ctx ) && preg_match( '/^term_(\d+)$/', $ctx, $m ) ) {
		return CFS_Field_Group::get_groups_for_term( (int) $m[1] );
	}

	return get_post_type( $ctx ) ? CFS_Field_Group::get_groups_for_post( $ctx ) : array();
}

/* ----------------------------------------------------------------------
 * Repeater loop API
 *
 *   while ( cfs_have_rows( 'team' ) ) {
 *       cfs_the_row();
 *       echo cfs_get_sub_field( 'name' );
 *
 *       while ( cfs_have_rows( 'links' ) ) {   // a repeater inside the row
 *           cfs_the_row();
 *           echo cfs_get_sub_field( 'url' );
 *       }
 *   }
 * -------------------------------------------------------------------- */

/**
 * The stack of active repeater loops (innermost last). Returned by
 * reference so callers can push/pop it; kept in a static so it is not a
 * global another plugin could trip over.
 *
 * Each entry: array( 'selector', 'post_id', 'rows', 'index' ) where
 * 'index' is the current row (-1 before the first cfs_the_row()).
 *
 * @return array
 */
function &cfs_loop_stack() {
	static $stack = array();

	return $stack;
}

/**
 * Whether a repeater has another row to loop over. Call it as the
 * condition of a while loop; it starts the loop on first call, and when
 * the rows run out it ends the loop (so the same repeater can be looped
 * again later) and returns false.
 *
 * Called inside another cfs_have_rows() loop with the name of one of the
 * current row's sub fields, it loops that nested repeater instead.
 *
 * @param string   $selector Repeater field name (or sub field name when nested).
 * @param int|null $post_id  Post ID, defaults to the current post.
 * @return bool
 */
function cfs_have_rows( $selector, $post_id = null ) {
	$stack = &cfs_loop_stack();
	$depth = count( $stack );
	$top   = $depth ? $stack[ $depth - 1 ] : null;

	$resolved_id = cfs_resolve_post_id( $post_id );

	if ( $top && $top['selector'] === $selector && ( null === $post_id || $top['post_id'] === $resolved_id ) ) {
		// Already looping this repeater: keep going.
		$current = &$stack[ $depth - 1 ];
	} else {
		$rows = null;

		// A sub field of the current row? Then this is a nested loop.
		if ( $top && null === $post_id && $top['index'] >= 0 ) {
			$row = $top['rows'][ $top['index'] ] ?? array();
			if ( is_array( $row ) && isset( $row[ $selector ] ) && is_array( $row[ $selector ] ) ) {
				$rows    = $row[ $selector ];
				$post_id = $top['post_id'];
			}
		}

		if ( null === $rows ) {
			// A fresh top-level loop: discard any loops left half-finished
			// (a `break` out of an earlier while) so they cannot leak in.
			$stack = array();
			$rows  = cfs_get_field( $selector, $post_id );
			$rows  = is_array( $rows ) ? $rows : array();
			$post_id = $resolved_id;
		}

		$stack[] = array(
			'selector' => $selector,
			'post_id'  => $post_id,
			'rows'     => array_values( $rows ),
			'index'    => -1,
		);
		$current = &$stack[ count( $stack ) - 1 ];
	}

	if ( $current['index'] + 1 < count( $current['rows'] ) ) {
		return true;
	}

	// Out of rows: end this loop, leaving any parent loop active.
	unset( $current );
	array_pop( $stack );

	return false;
}

/**
 * Advance to the next row of the current loop and return it (an assoc
 * array of sub field values), or false when there is no loop / no more rows.
 *
 * @return array|false
 */
function cfs_the_row() {
	$stack = &cfs_loop_stack();
	$depth = count( $stack );

	if ( ! $depth ) {
		return false;
	}

	$current = &$stack[ $depth - 1 ];

	if ( $current['index'] + 1 >= count( $current['rows'] ) ) {
		return false;
	}

	$current['index']++;

	return $current['rows'][ $current['index'] ];
}

/**
 * A sub field's value in the current row, or null.
 *
 * @param string $name Sub field name.
 * @return mixed|null
 */
function cfs_get_sub_field( $name ) {
	$stack = cfs_loop_stack();
	$depth = count( $stack );

	if ( ! $depth ) {
		return null;
	}

	$current = $stack[ $depth - 1 ];
	$row     = $current['index'] >= 0 ? ( $current['rows'][ $current['index'] ] ?? null ) : null;

	if ( ! is_array( $row ) || ! array_key_exists( $name, $row ) ) {
		return null;
	}

	return $row[ $name ];
}

/**
 * Echo a sub field's value in the current row (escaped; arrays are
 * printed readably, same as cfs_the_field()).
 *
 * @param string $name Sub field name.
 */
function cfs_the_sub_field( $name ) {
	$value = cfs_get_sub_field( $name );

	if ( null === $value ) {
		return;
	}

	if ( is_array( $value ) || is_object( $value ) ) {
		echo '<pre>' . esc_html( print_r( $value, true ) ) . '</pre>'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- intentional readable fallback for a shape mismatch.
		return;
	}

	echo esc_html( is_bool( $value ) ? (string) (int) $value : $value );
}

/**
 * Layout name of the current row of a flexible content loop (e.g. "hero"),
 * or null when not in a loop / before cfs_the_row(). Repeater rows have none.
 *
 *   while ( cfs_have_rows( 'sections' ) ) {
 *       cfs_the_row();
 *       switch ( cfs_get_row_layout() ) { case 'hero': ...; case 'text': ...; }
 *   }
 *
 * @return string|null
 */
function cfs_get_row_layout() {
	$layout = cfs_get_sub_field( CFS_Field_Flexible::LAYOUT_KEY );

	return is_string( $layout ) ? $layout : null;
}

/**
 * 1-based number of the current row (1 for the first row), or 0 when not
 * in a loop / before cfs_the_row().
 *
 * @return int
 */
function cfs_get_row_index() {
	$stack = cfs_loop_stack();
	$depth = count( $stack );

	return $depth ? $stack[ $depth - 1 ]['index'] + 1 : 0;
}

/**
 * Abandon all active loops. Use after breaking out of a while loop early.
 */
function cfs_reset_rows() {
	$stack = &cfs_loop_stack();
	$stack = array();
}

/* ----------------------------------------------------------------------
 * Options Pages
 * -------------------------------------------------------------------- */

/**
 * Register an Options Page from code (theme functions.php, client plugin).
 * Pages can also be created in the UI under Field Groups → Options Pages.
 *
 *   cfs_add_options_page( array(
 *       'page_title' => 'Site Options',
 *       'menu_title' => 'Site Options',   // defaults to page_title
 *       'menu_slug'  => 'site-options',   // defaults to the menu title
 *       'capability' => 'manage_options', // default
 *       'parent_slug'=> '',               // '' = top level menu
 *       'icon_url'   => 'dashicons-admin-generic',
 *       'position'   => 59,
 *   ) );
 *
 * Then give a field group the location "Options Page is equal to Site
 * Options" and read values with cfs_get_field( 'phone', 'option' ).
 *
 * @param array $args Page args (all optional except a title or slug).
 * @return string|false The menu slug, or false if the args were unusable.
 */
function cfs_add_options_page( $args = array() ) {
	if ( empty( $args ) ) {
		$args = array(
			'page_title' => __( 'Site Options', 'cfs' ),
			'menu_slug'  => 'cfs-site-options',
		);
	}

	return CFS_Options_Page::add( $args );
}

/**
 * Register an Options Page under an existing admin menu — a core one such
 * as 'options-general.php' (Settings), or another options page's slug.
 *
 * @param array $args Same as cfs_add_options_page(); 'parent_slug' is required.
 * @return string|false
 */
function cfs_add_options_sub_page( $args = array() ) {
	if ( empty( $args['parent_slug'] ) ) {
		return false;
	}

	return CFS_Options_Page::add( $args );
}
