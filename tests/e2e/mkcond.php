<?php
// Creates a page field group used by p10b.js; prints its id.
define( 'WP_USE_THEMES', false );
require $argv[1] . '/wp-load.php';
$rule = function ( $field, $op, $val ) { return array( 'field' => $field, 'operator' => $op, 'value' => $val ); };
$fields = CFStudio_Field_Group::sanitize_fields(
	array(
		array( 'label' => 'Items', 'name' => 'items', 'type' => 'repeater', 'sub_fields' => array(
			array( 'label' => 'More', 'name' => 'more', 'type' => 'true_false' ),
			array( 'label' => 'Detail', 'name' => 'detail', 'type' => 'text', 'conditional_logic' => array( 'enabled' => 1, 'rules' => array( $rule( 'more', '==', '1' ) ) ) ),
		) ),
		array( 'label' => 'Cgrp', 'name' => 'cgrp', 'type' => 'group', 'sub_fields' => array(
			array( 'label' => 'Mode', 'name' => 'mode', 'type' => 'select', 'choices' => "plain : Plain\ncustom : Custom", 'allow_null' => 1 ),
			array( 'label' => 'Note', 'name' => 'note', 'type' => 'text', 'conditional_logic' => array( 'enabled' => 1, 'rules' => array( $rule( 'mode', '==', 'custom' ) ) ) ),
		) ),
		array( 'label' => 'Qty', 'name' => 'qty', 'type' => 'number' ),
		array( 'label' => 'Tag', 'name' => 'tag', 'type' => 'text' ),
		array( 'label' => 'Anyfield', 'name' => 'anyfield', 'type' => 'text', 'conditional_logic' => array( 'enabled' => 1, 'match' => 'any', 'rules' => array( $rule( 'qty', '>', '10' ), $rule( 'tag', '!empty', '' ) ) ) ),
		array( 'label' => 'Colours', 'name' => 'colours', 'type' => 'checkbox', 'choices' => "red : Red\nblue : Blue" ),
		array( 'label' => 'Red only', 'name' => 'red_only', 'type' => 'text', 'conditional_logic' => array( 'enabled' => 1, 'rules' => array( $rule( 'colours', '==', 'red' ) ) ) ),
	)
);
$id = wp_insert_post( array( 'post_type' => 'cfstudio_field_group', 'post_title' => 'P10b conditional scope', 'post_status' => 'publish' ) );
update_post_meta( $id, '_cfstudio_fields', wp_slash( $fields ) );
update_post_meta( $id, '_cfstudio_location', array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ) );
echo $id;
