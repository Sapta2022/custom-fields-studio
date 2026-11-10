<?php
// Phase 12 E2E fixtures. Usage: php p12setup.php <wp-root> setup|teardown
define( 'WP_USE_THEMES', false );
require $argv[1] . '/wp-load.php';
$cmd = $argv[2] ?? 'setup';

if ( 'teardown' === $cmd ) {
	foreach ( get_posts( array( 'post_type' => 'cfstudio_field_group', 'post_status' => 'any', 'numberposts' => -1 ) ) as $g ) {
		if ( 0 === strpos( $g->post_title, 'P12' ) ) {
			wp_delete_post( $g->ID, true );
		}
	}
	foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => -1, 's' => 'P12e' ) ) as $p ) {
		wp_delete_post( $p->ID, true );
	}
	delete_option( 'cfstudio_qa_block_editor' );
	delete_option( 'cfstudio_google_maps_api_key' );
	delete_option( 'cfstudio_translate_options' );
	echo 'ok';
	return;
}

$mk = function ( $title, $fields, $loc ) {
	$id = wp_insert_post( array( 'post_type' => 'cfstudio_field_group', 'post_status' => 'publish', 'post_title' => $title ) );
	update_post_meta( $id, '_cfstudio_fields', wp_slash( CFStudio_Field_Group::sanitize_fields( $fields ) ) );
	update_post_meta( $id, '_cfstudio_location', CFStudio_Location_Rules::sanitize( $loc ) );
	update_post_meta( $id, '_cfstudio_options', CFStudio_Field_Group::sanitize_options( array( 'active' => 1 ) ) );
	return $id;
};
$block = $mk(
	'P12e block group',
	array(
		array( 'label' => 'Heading', 'name' => 'heading', 'type' => 'text' ),
		array( 'label' => 'Badge', 'name' => 'badge', 'type' => 'true_false' ),
		array( 'label' => 'Items', 'name' => 'items', 'type' => 'repeater', 'sub_fields' => array( array( 'label' => 'Item', 'name' => 'item', 'type' => 'text' ) ) ),
	),
	array( array( array( 'param' => 'block', 'operator' => '==', 'value' => 'cfstudio/p12e-hero' ) ) )
);
$map = $mk(
	'P12e map group',
	array( array( 'label' => 'Venue', 'name' => 'p12e_venue', 'type' => 'google_map', 'translate' => 'copy' ) ),
	array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) )
);
echo wp_json_encode( array( 'block' => $block, 'map' => $map ) );
