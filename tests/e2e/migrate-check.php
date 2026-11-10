<?php
// Usage: php migrate-check.php /path/to/wordpress  — proves the cfs -> cfstudio data migration.
define( 'WP_USE_THEMES', false );
require $argv[1] . '/wp-load.php';
global $wpdb;
$ok = 0; $bad = 0;
$t = function ( $n, $c ) use ( &$ok, &$bad ) { $c ? $ok++ : $bad++; echo ( $c ? 'PASS ' : 'FAIL ' ) . $n . "\n"; };

$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'MIG', 'post_status' => 'publish', 'post_content' => '<!-- wp:cfs/hero {"form":"x"} /-->' ) );
$wpdb->update( $wpdb->posts, array( 'post_type' => 'cfs_field_group' ), array( 'ID' => $id ) );
update_post_meta( $id, '_cfs_fields', array( array( 'name' => 'a' ) ) );
update_post_meta( $id, '_cfs_group_key', 'group_mig' );
update_option( 'cfs_google_maps_api_key', 'KEY123' );
update_option( 'cfs_options_pages', array( 'p' ) );
delete_option( 'cfstudio_google_maps_api_key' );
delete_option( 'cfstudio_options_pages' );
delete_option( CFStudio_Migrate::DONE_OPTION );

CFStudio_Migrate::maybe_run();
clean_post_cache( $id );
$t( 'Post type renamed', 'cfstudio_field_group' === get_post_type( $id ) );
$t( 'Group fields meta renamed', array( array( 'name' => 'a' ) ) === get_post_meta( $id, '_cfstudio_fields', true ) && '' === get_post_meta( $id, '_cfs_fields', true ) );
$t( 'Group key meta renamed', 'group_mig' === get_post_meta( $id, '_cfstudio_group_key', true ) );
$t( 'Options moved', 'KEY123' === get_option( 'cfstudio_google_maps_api_key' ) && false === get_option( 'cfs_google_maps_api_key', false ) && array( 'p' ) === get_option( 'cfstudio_options_pages' ) );
$t( 'Block namespace in content updated', false !== strpos( get_post_field( 'post_content', $id ), 'wp:cfstudio/hero' ) );
$t( 'Runs only once', (bool) get_option( CFStudio_Migrate::DONE_OPTION ) );
// 0.13.0 -> 0.13.1: block location values.
$g = wp_insert_post( array( 'post_type' => 'cfstudio_field_group', 'post_title' => 'MIG2', 'post_status' => 'publish' ) );
update_post_meta( $g, '_cfstudio_location', array( array( array( 'param' => 'block', 'operator' => '==', 'value' => 'cfs/promo' ) ) ) );
update_option( CFStudio_Migrate::DONE_OPTION, '0.13.0' );
CFStudio_Migrate::maybe_run();
$loc = get_post_meta( $g, '_cfstudio_location', true );
$t( 'Block location value cfs/promo -> cfstudio/promo', 'cfstudio/promo' === $loc[0][0]['value'] );
wp_delete_post( $g, true );
wp_delete_post( $id, true );
delete_option( 'cfstudio_google_maps_api_key' );
delete_option( 'cfstudio_options_pages' );
delete_option( 'cfstudio_options_pages' );
echo "$ok passed, $bad failed\n";
