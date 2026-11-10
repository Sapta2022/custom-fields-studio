<?php
// Phase 11 E2E fixtures. Usage: php p11setup.php <wp-root> setup|teardown
define( 'WP_USE_THEMES', false );
require $argv[1] . '/wp-load.php';
$cmd = $argv[2] ?? 'setup';

$groups = function () {
	return get_posts( array( 'post_type' => 'cfstudio_field_group', 'post_status' => 'any', 'numberposts' => -1 ) );
};

if ( 'teardown' === $cmd ) {
	foreach ( $groups() as $g ) {
		if ( 0 === strpos( $g->post_title, 'P11' ) ) {
			wp_delete_post( $g->ID, true );
		}
	}
	foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => -1, 's' => 'P11e' ) ) as $p ) {
		wp_delete_post( $p->ID, true );
	}
	foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'name__like' => 'P11e' ) ) as $term ) {
		wp_delete_term( $term->term_id, 'category' );
	}
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '%e2e\\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%e2e\\_%'" );
	echo 'ok';
	return;
}

$mk = function ( $title, $fields, $loc, $opts = array() ) {
	$id = wp_insert_post( array( 'post_type' => 'cfstudio_field_group', 'post_status' => 'publish', 'post_title' => $title ) );
	update_post_meta( $id, '_cfstudio_fields', wp_slash( CFStudio_Field_Group::sanitize_fields( $fields ) ) );
	update_post_meta( $id, '_cfstudio_location', CFStudio_Location_Rules::sanitize( $loc ) );
	update_post_meta( $id, '_cfstudio_options', CFStudio_Field_Group::sanitize_options( array_merge( array( 'active' => 1 ), $opts ) ) );
	return $id;
};
$r = function ( $param, $value, $op = '==' ) {
	return array( 'param' => $param, 'operator' => $op, 'value' => $value );
};

$user = $mk(
	'P11e user group',
	array(
		array( 'label' => 'Twitter', 'name' => 'e2e_twitter', 'type' => 'text' ),
		array( 'label' => 'Skills', 'name' => 'e2e_skills', 'type' => 'repeater', 'sub_fields' => array( array( 'label' => 'Skill', 'name' => 'skill', 'type' => 'text' ) ) ),
	),
	array( array( $r( 'user_role', 'all' ) ) ),
	array( 'show_in_rest' => 1 )
);
$term = $mk(
	'P11e term group',
	array(
		array( 'label' => 'Color', 'name' => 'e2e_color', 'type' => 'text' ),
		array( 'label' => 'Featured', 'name' => 'e2e_featured', 'type' => 'true_false' ),
	),
	array( array( $r( 'taxonomy', 'category' ) ) ),
	array( 'show_in_rest' => 1 )
);
$draft = $mk(
	'P11e draft group',
	array( array( 'label' => 'Draft note', 'name' => 'e2e_draft_note', 'type' => 'text' ) ),
	array( array( $r( 'post_status', 'draft' ), $r( 'post_type', 'post' ) ) ),
	array( 'show_in_rest' => 1 )
);
$cat = wp_insert_term( 'P11e Cat', 'category' );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'P11e Page', 'post_status' => 'publish' ) );

echo wp_json_encode(
	array(
		'user'  => $user,
		'term'  => $term,
		'draft' => $draft,
		'cat'   => is_wp_error( $cat ) ? 0 : $cat['term_id'],
		'page'  => $page,
	)
);
