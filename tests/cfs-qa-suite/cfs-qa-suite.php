<?php
/**
 * Plugin Name: CFS QA Suite
 * Description: Test companion for Custom Fields Studio: seeds 3 field groups, 3 demo pages and 2 options pages that use every field type/option, and runs a per-phase test report. Tools -> CFS QA Suite.
 * Version:     1.0.0
 * Author:      Techshu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CFS_QA_PATH', plugin_dir_path( __FILE__ ) );

require_once CFS_QA_PATH . 'inc/definitions.php';
require_once CFS_QA_PATH . 'inc/seed.php';
require_once CFS_QA_PATH . 'inc/tests.php';
require_once CFS_QA_PATH . 'inc/tests-p9.php';
require_once CFS_QA_PATH . 'inc/tests-p10.php';
require_once CFS_QA_PATH . 'inc/tests-p11.php';
require_once CFS_QA_PATH . 'inc/tests-p12.php';

// Options pages used by the QA group (registered in code on every request).
add_action(
	'init',
	function () {
		if ( ! function_exists( 'cfs_add_options_page' ) ) {
			return;
		}
		cfs_add_options_page(
			array(
				'page_title'      => 'QA Options',
				'menu_slug'       => 'qa-options',
				'icon_url'        => 'dashicons-clipboard',
				'position'        => 61,
				'update_button'   => 'Save QA Options',
				'updated_message' => 'QA options saved.',
			)
		);
		cfs_add_options_sub_page(
			array(
				'page_title'  => 'QA Options (Settings)',
				'menu_slug'   => 'qa-options-sub',
				'parent_slug' => 'options-general.php',
			)
		);
	},
	20
);

add_action(
	'admin_menu',
	function () {
		add_management_page( 'CFS QA Suite', 'CFS QA Suite', 'manage_options', 'cfs-qa-suite', 'cfs_qa_admin_page' );
	}
);

function cfs_qa_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$results = null;
	$notice  = '';

	if ( isset( $_POST['cfs_qa_action'] ) && check_admin_referer( 'cfs_qa' ) ) {
		$action = sanitize_key( $_POST['cfs_qa_action'] );
		if ( 'seed' === $action ) {
			cfs_qa_seed();
			$notice = 'Demo content created.';
		} elseif ( 'clean' === $action ) {
			cfs_qa_cleanup();
			$notice = 'All QA content removed.';
		} elseif ( 'run' === $action ) {
			$results = cfs_qa_run_tests();
		}
	}

	$ids = cfs_qa_ids();
	echo '<div class="wrap"><h1>CFS QA Suite</h1>';
	if ( $notice ) {
		echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
	}
	echo '<form method="post">';
	wp_nonce_field( 'cfs_qa' );
	echo '<p>';
	echo '<button class="button button-primary" name="cfs_qa_action" value="run">Run all tests (re-creates demo content)</button> ';
	echo '<button class="button" name="cfs_qa_action" value="seed">Create demo content only</button> ';
	echo '<button class="button" name="cfs_qa_action" value="clean" onclick="return confirm(\'Remove all QA groups, pages, posts, images and values?\')">Remove all QA content</button>';
	echo '</p></form>';

	if ( ! empty( $ids['page'] ) ) {
		echo '<h2>Demo pages</h2><ul>';
		foreach ( $ids['page'] as $n => $pid ) {
			printf( '<li>%s &mdash; <a href="%s">edit</a> &middot; <a href="%s">preview</a></li>', esc_html( get_the_title( $pid ) ), esc_url( get_edit_post_link( $pid ) ), esc_url( get_preview_post_link( $pid ) ) );
		}
		echo '</ul><p>Options pages: <a href="' . esc_url( admin_url( 'admin.php?page=qa-options' ) ) . '">QA Options</a> &middot; <a href="' . esc_url( admin_url( 'options-general.php?page=qa-options-sub' ) ) . '">QA Options (Settings)</a></p>';
	}

	if ( null !== $results ) {
		$pass = count( array_filter( $results, function ( $r ) { return $r[3]; } ) );
		$fail = count( $results ) - $pass;
		printf( '<h2>Results: <span style="color:%s">%d passed, %d failed</span></h2>', $fail ? '#b32d2e' : '#00a32a', $pass, $fail );
		$by = array();
		foreach ( $results as $r ) {
			$by[ $r[0] ][ $r[1] ][] = $r;
		}
		foreach ( $by as $phase => $mods ) {
			$all = array_merge( ...array_values( $mods ) );
			$bad = count( array_filter( $all, function ( $r ) { return ! $r[3]; } ) );
			printf( '<h3>%s &mdash; %d/%d %s</h3>', esc_html( $phase ), count( $all ) - $bad, count( $all ), $bad ? '&#10060;' : '&#9989;' );
			foreach ( $mods as $mod => $rows ) {
				echo '<details' . ( count( array_filter( $rows, function ( $r ) { return ! $r[3]; } ) ) ? ' open' : '' ) . '><summary><strong>' . esc_html( $mod ) . '</strong> (' . count( $rows ) . ')</summary><table class="widefat striped" style="max-width:1100px">';
				foreach ( $rows as $r ) {
					printf( '<tr><td style="width:60px;color:%s"><strong>%s</strong></td><td>%s</td><td style="color:#666">%s</td></tr>', $r[3] ? '#00a32a' : '#b32d2e', $r[3] ? 'PASS' : 'FAIL', esc_html( $r[2] ), esc_html( $r[4] ) );
				}
				echo '</table></details>';
			}
		}
	}
	echo '</div>';
}
