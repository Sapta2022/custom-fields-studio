<?php
/**
 * Phase 11 checks: extra location rules, user / term storage and screens,
 * clone field, JSON import / export / local JSON sync, REST API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cfstudio_qa_run_p11( $t, &$phase, &$module, $P1, $ids ) {
	$phase = 'P11';

	$mk = function ( $title, $fields, $loc, $opts = array() ) {
		$id = wp_insert_post( array( 'post_type' => 'cfstudio_field_group', 'post_status' => 'publish', 'post_title' => $title ) );
		update_post_meta( $id, '_cfstudio_fields', wp_slash( CFStudio_Field_Group::sanitize_fields( $fields ) ) );
		update_post_meta( $id, '_cfstudio_location', CFStudio_Location_Rules::sanitize( $loc ) );
		update_post_meta( $id, '_cfstudio_options', CFStudio_Field_Group::sanitize_options( array_merge( array( 'active' => 1 ), $opts ) ) );
		CFStudio_Field_Group::flush_cache();
		return $id;
	};
	$rule = function ( $param, $value, $op = '==' ) {
		return array( 'param' => $param, 'operator' => $op, 'value' => $value );
	};
	$titles = function ( $groups ) {
		return wp_list_pluck( $groups, 'title' );
	};
	$made = array(); // Group IDs to delete at the end.

	/* ---------------------------------------------------------------- */
	$module = 'Location rules';
	$ctx    = array( 'post_type' => 'page', 'post_id' => '42', 'post_status' => 'draft', 'page_template' => 'wide.php', 'post_category' => array( '3', '5' ), 'post_taxonomy' => array( 'category:news', 'post_tag:x' ), 'current_user_role' => array( 'editor' ) );
	$m      = function ( $rules ) use ( $ctx ) {
		return CFStudio_Location_Rules::matches( array( $rules ), 'post', $ctx );
	};
	$t( 'post_status == matches / != does not', $m( array( $rule( 'post_status', 'draft' ) ) ) && ! $m( array( $rule( 'post_status', 'draft', '!=' ) ) ) );
	$t( 'page_template matches the template file', $m( array( $rule( 'page_template', 'wide.php' ) ) ) && ! $m( array( $rule( 'page_template', 'default' ) ) ) );
	$t( 'post_id matches only that post', $m( array( $rule( 'post_id', '42' ) ) ) && ! $m( array( $rule( 'post_id', '43' ) ) ) );
	$t( 'post_category matches ANY of the post categories', $m( array( $rule( 'post_category', '5' ) ) ) && ! $m( array( $rule( 'post_category', '9' ) ) ) && $m( array( $rule( 'post_category', '9', '!=' ) ) ) );
	$t( 'post_taxonomy matches taxonomy:slug', $m( array( $rule( 'post_taxonomy', 'category:news' ) ) ) && $m( array( $rule( 'post_taxonomy', 'post_tag:x' ) ) ) && ! $m( array( $rule( 'post_taxonomy', 'post_tag:y' ) ) ) );
	$t( 'current_user_role alongside a screen rule', $m( array( $rule( 'post_type', 'page' ), $rule( 'current_user_role', 'editor' ) ) ) && ! $m( array( $rule( 'post_type', 'page' ), $rule( 'current_user_role', 'administrator' ) ) ) );
	$t( 'AND inside a group, OR between groups', ! $m( array( $rule( 'post_type', 'page' ), $rule( 'post_status', 'publish' ) ) ) && CFStudio_Location_Rules::matches( array( array( $rule( 'post_type', 'page' ), $rule( 'post_status', 'publish' ) ), array( $rule( 'post_id', '42' ) ) ), 'post', $ctx ) );
	$t( 'A group of only current_user_role rules never matches (no screen)', ! $m( array( $rule( 'current_user_role', 'editor' ) ) ) );
	$t( 'Params of another screen never match a post screen', ! $m( array( $rule( 'post_type', 'page' ), $rule( 'user_role', 'editor' ) ) ) && ! $m( array( $rule( 'options_page', 'x' ) ) ) && ! $m( array( $rule( 'taxonomy', 'category' ) ) ) );
	$t( 'user screen: user_role, all wildcard, != works', CFStudio_Location_Rules::matches( array( array( $rule( 'user_role', 'author' ) ) ), 'user', array( 'user_role' => array( 'author' ) ) ) && CFStudio_Location_Rules::matches( array( array( $rule( 'user_role', 'all' ) ) ), 'user', array( 'user_role' => array( 'x' ) ) ) && ! CFStudio_Location_Rules::matches( array( array( $rule( 'user_role', 'author', '!=' ) ) ), 'user', array( 'user_role' => array( 'author' ) ) ) );
	$t( 'term screen: taxonomy match and all wildcard', CFStudio_Location_Rules::matches( array( array( $rule( 'taxonomy', 'category' ) ) ), 'term', array( 'taxonomy' => 'category' ) ) && ! CFStudio_Location_Rules::matches( array( array( $rule( 'taxonomy', 'post_tag' ) ) ), 'term', array( 'taxonomy' => 'category' ) ) && CFStudio_Location_Rules::matches( array( array( $rule( 'taxonomy', 'all' ) ) ), 'term', array( 'taxonomy' => 'category' ) ) );
	$t( 'Unknown screen / empty rules never match', ! CFStudio_Location_Rules::matches( array(), 'post', $ctx ) && ! CFStudio_Location_Rules::matches( array( array( $rule( 'post_type', 'page' ) ) ), 'nope', $ctx ) );
	$t( 'Old API still works: matches_post_type / matches_options_page', CFStudio_Location_Rules::matches_post_type( array( array( $rule( 'post_type', 'page' ) ) ), 'page' ) && ! CFStudio_Location_Rules::matches_post_type( array( array( $rule( 'post_type', 'page' ), $rule( 'post_status', 'draft' ) ) ), 'page' ) && CFStudio_Location_Rules::matches_options_page( array( array( $rule( 'options_page', 's' ) ) ), 's' ) );
	$params = CFStudio_Location_Rules::get_params();
	$t( 'All new params are offered in the builder, each with values (or free: none needed)', 11 === count( $params ) && ! empty( CFStudio_Location_Rules::get_values_for_param( 'post_status' ) ) && isset( CFStudio_Location_Rules::get_values_for_param( 'page_template' )['default'] ) && isset( CFStudio_Location_Rules::get_values_for_param( 'current_user_role' )['administrator'] ) && isset( CFStudio_Location_Rules::get_values_for_param( 'user_role' )['all'] ) && isset( CFStudio_Location_Rules::get_values_for_param( 'taxonomy' )['category'] ) && ! empty( CFStudio_Location_Rules::get_values_for_param( 'post_id' ) ), array_keys( $params ) );
	$clean = CFStudio_Location_Rules::sanitize( array( array( array( 'param' => 'post_status', 'operator' => '!=', 'value' => 'draft' ), array( 'param' => 'post_taxonomy', 'operator' => '==', 'value' => 'category:news' ) ) ) );
	$t( 'Sanitize keeps the new params', 'post_status' === $clean[0][0]['param'] && '!=' === $clean[0][0]['operator'] && 'category:news' === $clean[0][1]['value'], $clean );

	$module = 'Location rules on real posts';
	$cat    = wp_insert_term( 'P11 Cat', 'category' );
	$cat_id = $cat['term_id'];
	$pg     = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'P11 page', 'post_status' => 'publish' ) );
	$dr     = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'P11 draft', 'post_status' => 'draft' ) );
	wp_set_post_categories( $dr, array( $cat_id ) );
	$pid = $mk( 'P11 by id', array( array( 'label' => 'A', 'type' => 'text' ) ), array( array( $rule( 'post_id', (string) $pg ) ) ) );
	$pst = $mk( 'P11 by status', array( array( 'label' => 'S', 'type' => 'text' ) ), array( array( $rule( 'post_status', 'draft' ), $rule( 'post_type', 'post' ) ) ) );
	$pct = $mk( 'P11 by category', array( array( 'label' => 'C', 'type' => 'text' ) ), array( array( $rule( 'post_category', (string) $cat_id ) ) ) );
	$ptp = $mk( 'P11 by template', array( array( 'label' => 'T', 'type' => 'text' ) ), array( array( $rule( 'page_template', 'default' ), $rule( 'post_type', 'page' ) ) ) );
	$pro = $mk( 'P11 admin only', array( array( 'label' => 'R', 'type' => 'text' ) ), array( array( $rule( 'post_type', 'page' ), $rule( 'current_user_role', 'administrator' ) ) ) );
	$made = array_merge( $made, array( $pid, $pst, $pct, $ptp, $pro ) );
	$for_pg = $titles( CFStudio_Field_Group::get_groups_for_post( $pg ) );
	$for_dr = $titles( CFStudio_Field_Group::get_groups_for_post( $dr ) );
	$t( 'post_id group shows on that page only', in_array( 'P11 by id', $for_pg, true ) && ! in_array( 'P11 by id', $for_dr, true ) );
	$t( 'post_status group shows on the draft post only', in_array( 'P11 by status', $for_dr, true ) && ! in_array( 'P11 by status', $for_pg, true ) );
	$t( 'category group shows on the post in that category only', in_array( 'P11 by category', $for_dr, true ) && ! in_array( 'P11 by category', $for_pg, true ) );
	$t( 'default page template group shows on a normal page', in_array( 'P11 by template', $for_pg, true ) && ! in_array( 'P11 by template', $for_dr, true ) );
	$t( 'current_user_role: shows for the administrator', in_array( 'P11 admin only', $for_pg, true ) );
	$ed = (int) get_user_by( 'login', 'qaeditor' ) ? get_user_by( 'login', 'qaeditor' )->ID : 0;
	if ( ! $ed ) {
		$ed = wp_insert_user( array( 'user_login' => 'cfstudio_p11_ed', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	}
	wp_set_current_user( $ed );
	$t( 'current_user_role: hidden for an editor', ! in_array( 'P11 admin only', $titles( CFStudio_Field_Group::get_groups_for_post( $pg ) ), true ) );
	wp_set_current_user( 1 );
	wp_update_post( array( 'ID' => $dr, 'post_status' => 'publish' ) );
	$t( 'Publishing the draft drops the status group', ! in_array( 'P11 by status', $titles( CFStudio_Field_Group::get_groups_for_post( $dr ) ), true ) );
	wp_set_post_categories( $dr, array() );
	$t( 'Removing the category drops the category group', ! in_array( 'P11 by category', $titles( CFStudio_Field_Group::get_groups_for_post( $dr ) ), true ) );
	update_post_meta( $pg, '_wp_page_template', 'wide.php' );
	$t( 'A page with another template leaves the default-template group', ! in_array( 'P11 by template', $titles( CFStudio_Field_Group::get_groups_for_post( $pg ) ), true ) );
	$sv  = $mk( 'P11 status save', array( array( 'label' => 'Sv', 'name' => 'sv', 'type' => 'text' ) ), array( array( $rule( 'post_status', 'draft' ), $rule( 'post_type', 'post' ) ) ) );
	$made[] = $sv;
	$d2 = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'P11 save-publish', 'post_status' => 'draft' ) );
	$d3 = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'P11 save-publish 2', 'post_status' => 'draft' ) );
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'sv' => 'typed-while-draft' ), 'cfstudio_rendered' => array( (string) $sv ) );
	wp_update_post( array( 'ID' => $d2, 'post_status' => 'publish' ) );
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'sv' => 'no-report' ) );
	wp_update_post( array( 'ID' => $d3, 'post_status' => 'publish' ) );
	$_POST = array();
	$t( 'Publishing a draft saves what was typed into a draft-only group (it was on screen)', 'typed-while-draft' === get_post_meta( $d2, 'sv', true ) );
	$t( 'Without the screen reporting the group, a group that no longer matches is not saved', ! metadata_exists( 'post', $d3, 'sv' ) );
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'sv' => 'forged', 'a' => 'forged' ), 'cfstudio_rendered' => array( (string) $ptp, (string) $pro ) );
	wp_update_post( array( 'ID' => $d3, 'post_title' => 'P11 save-publish 2b' ) );
	$_POST = array();
	$t( 'A forged rendered-group id for another post type (page groups) saves nothing', ! metadata_exists( 'post', $d3, 'lname' ) && ! metadata_exists( 'post', $d3, 'a' ) );
	wp_delete_post( $d2, true );
	wp_delete_post( $d3, true );
	update_post_meta( $pg, '_wp_page_template', '' );
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'a' => 'saved-on-id-match', 's' => 'x', 'c' => 'x' ) );
	do_action( 'save_post', $pg, get_post( $pg ), true );
	do_action( 'save_post', $dr, get_post( $dr ), true );
	$_POST = array();
	$t( 'Meta box saves the fields of groups that match this post', 'saved-on-id-match' === get_post_meta( $pg, 'a', true ) );
	$t( 'Meta box ignores groups that do not match (the other post got no "a")', ! metadata_exists( 'post', $dr, 'a' ) && ! metadata_exists( 'post', $pg, 's' ) );

	/* ---------------------------------------------------------------- */
	$module = 'User & term storage';
	$ug  = $mk( 'P11 user group', array( array( 'label' => 'Twitter', 'name' => 'p11_twitter', 'type' => 'text' ), array( 'label' => 'Bio Extra', 'name' => 'p11_bio', 'type' => 'textarea' ), array( 'label' => 'Skills', 'name' => 'p11_skills', 'type' => 'repeater', 'sub_fields' => array( array( 'label' => 'Skill', 'name' => 'skill', 'type' => 'text' ) ) ) ), array( array( $rule( 'user_role', 'all' ) ) ), array( 'show_in_rest' => 1 ) );
	$tg  = $mk( 'P11 term group', array( array( 'label' => 'Color', 'name' => 'p11_color', 'type' => 'text' ), array( 'label' => 'Featured', 'name' => 'p11_featured', 'type' => 'true_false' ) ), array( array( $rule( 'taxonomy', 'category' ) ) ), array( 'show_in_rest' => 1 ) );
	$made = array_merge( $made, array( $ug, $tg ) );
	$t( 'Stable group key created for a new group', 0 === strpos( CFStudio_Field_Group::get_group_key( $ug ), 'group_' ) && CFStudio_Field_Group::get_group_key( $ug ) === CFStudio_Field_Group::get_group_key( $ug ) );
	$t( 'Two groups never share a key', CFStudio_Field_Group::get_group_key( $ug ) !== CFStudio_Field_Group::get_group_key( $tg ) );
	$t( 'cfstudio_resolve_post_id understands user_N, term_N, WP_User, WP_Term', 'user_5' === cfstudio_resolve_post_id( 'user_5' ) && 'term_7' === cfstudio_resolve_post_id( 'term_7' ) && 'user_1' === cfstudio_resolve_post_id( get_userdata( 1 ) ) && 'term_' . $cat_id === cfstudio_resolve_post_id( get_term( $cat_id ) ) && 'option' === cfstudio_resolve_post_id( 'options' ) && 9 === cfstudio_resolve_post_id( '9' ) );
	$t( 'User group applies to a user; not to a term', in_array( 'P11 user group', $titles( cfstudio_groups_for_context( 'user_1' ) ), true ) && ! in_array( 'P11 user group', $titles( cfstudio_groups_for_context( 'term_' . $cat_id ) ), true ) );
	$t( 'Term group applies to a category; not to a tag', in_array( 'P11 term group', $titles( cfstudio_groups_for_context( 'term_' . $cat_id ) ), true ) );
	$tag = wp_insert_term( 'P11 Tag', 'post_tag' );
	$t( 'Term group does not apply to a tag', ! in_array( 'P11 term group', $titles( cfstudio_groups_for_context( 'term_' . $tag['term_id'] ) ), true ) );
	$f_tw = CFStudio_Field_Group::find_field( cfstudio_groups_for_context( 'user_1' ), 'p11_twitter' );
	CFStudio_Field_Text::save_value( $f_tw, '@zed', 'user_1' );
	$t( 'Value stored in user meta with ACF-style reference key', '@zed' === get_user_meta( 1, 'p11_twitter', true ) && $f_tw['key'] === get_user_meta( 1, '_p11_twitter', true ) );
	$t( 'cfstudio_get_field reads user_1 / WP_User', '@zed' === cfstudio_get_field( 'p11_twitter', 'user_1' ) && '@zed' === cfstudio_get_field( 'p11_twitter', get_userdata( 1 ) ) );
	$t( 'Nothing leaked into post meta or options', ! metadata_exists( 'post', 1, 'p11_twitter' ) && false === get_option( 'options_p11_twitter', false ) );
	CFStudio_Field_Repeater::save_value( CFStudio_Field_Group::find_field( cfstudio_groups_for_context( 'user_1' ), 'p11_skills' ), array( array( 'skill' => 'PHP' ), array( 'skill' => 'JS' ) ), 'user_1' );
	$t( 'Repeater works on a user (rows stored as user meta)', 2 === (int) get_user_meta( 1, 'p11_skills', true ) && 'JS' === get_user_meta( 1, 'p11_skills_1_skill', true ) && 'PHP' === cfstudio_get_fields( 'user_1' )['p11_skills'][0]['skill'], cfstudio_get_fields( 'user_1' ) );
	CFStudio_Field_Text::save_value( CFStudio_Field_Group::find_field( cfstudio_groups_for_context( 'term_' . $cat_id ), 'p11_color' ), 'red', 'term_' . $cat_id );
	$t( 'Value stored in term meta; read back with cfstudio_get_field', 'red' === get_term_meta( $cat_id, 'p11_color', true ) && 'red' === cfstudio_get_field( 'p11_color', 'term_' . $cat_id ) );
	CFStudio_Field_True_False::save_value( CFStudio_Field_Group::find_field( cfstudio_groups_for_context( 'term_' . $cat_id ), 'p11_featured' ), '1', 'term_' . $cat_id );
	$t( 'True/False on a term formats to a boolean', true === cfstudio_get_field( 'p11_featured', 'term_' . $cat_id ) );
	$t( 'Storage::exists / delete work for user and term contexts', CFStudio_Storage::exists( 'p11_twitter', 'user_1' ) && ( CFStudio_Storage::delete( 'p11_twitter', 'user_1' ) || true ) && ! CFStudio_Storage::exists( 'p11_twitter', 'user_1' ) && CFStudio_Storage::exists( 'p11_color', 'term_' . $cat_id ) );
	$t( 'Storage keeps backslashes in user meta (slashing)', ( CFStudio_Storage::update( 'p11_bs', 'C:\\temp\\x', 'user_1' ) || true ) && 'C:\\temp\\x' === get_user_meta( 1, 'p11_bs', true ) );
	delete_user_meta( 1, 'p11_bs' );

	$module = 'User & term screens';
	$mb = new CFStudio_Meta_Box();
	ob_start();
	$mb->render_user_groups( get_userdata( 1 ) );
	$html = ob_get_clean();
	$t( 'Profile screen renders the user group with its field inputs and the nonce', false !== strpos( $html, 'P11 user group' ) && false !== strpos( $html, 'name="cfs[p11_twitter]"' ) && false !== strpos( $html, CFStudio_Meta_Box::NONCE_FIELD ), substr( $html, 0, 120 ) );
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'p11_twitter' => '@posted', 'p11_bio' => "line1\nline2" ) );
	$mb->save_user( 1 );
	$_POST = array();
	$t( 'Saving the profile stores the posted values in user meta', '@posted' === get_user_meta( 1, 'p11_twitter', true ) && "line1\nline2" === get_user_meta( 1, 'p11_bio', true ) );
	$_POST = array( 'cfs' => array( 'p11_twitter' => '@nononce' ) );
	$mb->save_user( 1 );
	$_POST = array();
	$t( 'Saving without the nonce does nothing', '@posted' === get_user_meta( 1, 'p11_twitter', true ) );
	$sub = wp_insert_user( array( 'user_login' => 'cfstudio_p11_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	wp_set_current_user( $sub );
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'p11_twitter' => '@hack' ) );
	$mb->save_user( 1 );
	$_POST = array();
	$t( "A subscriber cannot save another user's profile fields", '@posted' === get_user_meta( 1, 'p11_twitter', true ) );
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'p11_twitter' => '@mine' ) );
	$mb->save_user( $sub );
	$_POST = array();
	$t( 'A user can save their own profile fields', '@mine' === get_user_meta( $sub, 'p11_twitter', true ) );
	wp_set_current_user( 1 );
	ob_start();
	$mb->render_term_groups( get_term( $cat_id ) );
	$html = ob_get_clean();
	$t( 'Term edit screen renders the term group', false !== strpos( $html, 'P11 term group' ) && false !== strpos( $html, 'name="cfs[p11_color]"' ) && false !== strpos( $html, 'value="red"' ), substr( $html, 0, 120 ) );
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'p11_color' => 'blue' ) );
	$mb->save_term( $cat_id, 0, 'category' );
	$_POST = array();
	$t( 'Saving the term stores term meta; unchecked true/false saves off', 'blue' === get_term_meta( $cat_id, 'p11_color', true ) && 0 === (int) get_term_meta( $cat_id, 'p11_featured', true ) );
	wp_set_current_user( $sub );
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'p11_color' => 'hacked' ) );
	$mb->save_term( $cat_id, 0, 'category' );
	$_POST = array();
	wp_set_current_user( 1 );
	$t( 'A subscriber cannot save term fields', 'blue' === get_term_meta( $cat_id, 'p11_color', true ) );
	$t( 'Shortcode hides user fields from people who cannot edit that user', ( function () use ( $sub ) {
		wp_set_current_user( $sub );
		$out = do_shortcode( '[cfs field="p11_bio" post_id="user_1"]' );
		wp_set_current_user( 1 );
		return '' === $out;
	} )() && false !== strpos( do_shortcode( '[cfs field="p11_bio" post_id="user_1"]' ), 'line1' ) );
	$t( 'Shortcode reads term fields', false !== strpos( do_shortcode( '[cfs field="p11_color" post_id="term_' . $cat_id . '"]' ), 'blue' ) );

	/* ---------------------------------------------------------------- */
	$module = 'Clone field';
	$reg = CFStudio_Field_Registry::get_class( 'clone' );
	$t( "Type 'clone' registered, does not store a value", (bool) $reg && ! $reg::stores_value() );
	ob_start();
	$reg::render_settings( array_merge( $reg::defaults(), array( 'clone' => array( CFStudio_Field_Group::get_group_key( $ug ) ) ) ), 'cfstudio_fields[0]' );
	$html = ob_get_clean();
	$t( 'Builder settings list the other groups, pre-ticking the saved one', false !== strpos( $html, 'data-type="clone"' ) && false !== strpos( $html, 'P11 user group' ) && 1 === preg_match( '/value="' . preg_quote( CFStudio_Field_Group::get_group_key( $ug ), '/' ) . '"\s+checked/', $html ), substr( $html, 0, 200 ) );
	$cs = $reg::sanitize( array( 'clone' => array( 'group_a', 'Group_B!', 'group_a', '', array( 'x' ) ), 'display' => 'evil', 'prefix_name' => '1' ) );
	$t( 'Sanitize: keys cleaned and de-duplicated, bad display falls back to group', array( 'group_a', 'group_b' ) === $cs['clone'] && 'group' === $cs['display'] && 1 === $cs['prefix_name'], $cs );
	$seo = $mk( 'P11 SEO', array( array( 'label' => 'SEO Title', 'name' => 'seo_title', 'type' => 'text' ), array( 'label' => 'Noindex', 'name' => 'noindex', 'type' => 'true_false' ), array( 'label' => 'Reason', 'name' => 'reason', 'type' => 'text', 'conditional_logic' => array( 'enabled' => 1, 'rules' => array( array( 'field' => 'noindex', 'operator' => '==', 'value' => '1' ) ) ) ) ), array( array( $rule( 'options_page', 'p11-none' ) ) ) );
	$made[] = $seo;
	$seo_key = CFStudio_Field_Group::get_group_key( $seo );
	$host = $mk(
		'P11 Host',
		array(
			array( 'label' => 'Headline', 'name' => 'headline', 'type' => 'text' ),
			array( 'label' => 'Meta', 'name' => 'meta', 'type' => 'clone', 'clone' => array( $seo_key ), 'display' => 'group' ),
			array( 'label' => 'Seo', 'name' => 'seo', 'type' => 'clone', 'clone' => array( $seo_key ), 'display' => 'seamless', 'prefix_name' => 1 ),
			array( 'label' => 'Seo Flat', 'name' => 'flat', 'type' => 'clone', 'clone' => array( $seo_key, 'group_missing' ), 'display' => 'seamless' ),
		),
		array( array( $rule( 'post_id', (string) $pg ) ) ),
		array( 'show_in_rest' => 1 )
	);
	$made[] = $host;
	$hg    = null;
	foreach ( CFStudio_Field_Group::get_groups_for_post( $pg ) as $g ) {
		if ( 'P11 Host' === $g['title'] ) {
			$hg = $g;
		}
	}
	$names = $hg ? wp_list_pluck( $hg['fields'], 'name' ) : array();
	$t( 'Clones are resolved when groups load: headline, meta (group), prefixed seamless x3, flat x3', array( 'headline', 'meta', 'seo_seo_title', 'seo_noindex', 'seo_reason', 'seo_title', 'noindex', 'reason' ) === $names, $names );
	$meta = $hg['fields'][1] ?? array();
	$t( 'Group display wraps the cloned fields in a group field named after the clone', 'group' === ( $meta['type'] ?? '' ) && 3 === count( $meta['sub_fields'] ?? array() ) && 'seo_title' === ( $meta['sub_fields'][0]['name'] ?? '' ), $meta );
	$all_keys = wp_list_pluck( $hg['fields'], 'key' );
	$t( 'Cloned fields get keys that cannot collide (same group cloned 3 times)', count( $all_keys ) === count( array_unique( $all_keys ) ) && count( array_unique( array_merge( $all_keys, wp_list_pluck( $meta['sub_fields'], 'key' ) ) ) ) === count( $all_keys ) + 3, $all_keys );
	$t( 'Conditional logic between cloned siblings follows the prefix', 'seo_noindex' === ( $hg['fields'][4]['conditional_logic']['rules'][0]['field'] ?? '' ) && 'noindex' === ( $hg['fields'][7]['conditional_logic']['rules'][0]['field'] ?? '' ), $hg['fields'][4] ?? null );
	$t( 'The source group itself is untouched', 'seo_title' === ( CFStudio_Field_Group::find_field( array( array( 'fields' => get_post_meta( $seo, '_cfstudio_fields', true ) ) ), 'seo_title' )['name'] ?? '' ) );
	$t( 'Missing group is ignored without an error', true );
	// Values through the template API & meta box.
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'headline' => 'H', 'meta' => array( 'seo_title' => 'T-meta', 'noindex' => '1', 'reason' => 'because' ), 'seo_seo_title' => 'T-seo', 'seo_noindex' => '', 'seo_reason' => '', 'seo_title' => 'T-flat' ) );
	update_post_meta( $pg, '_wp_page_template', '' );
	do_action( 'save_post', $pg, get_post( $pg ), true );
	$_POST = array();
	$t( 'Group-display clone: values come back as one array', 'T-meta' === ( cfstudio_get_field( 'meta', $pg )['seo_title'] ?? '' ) && true === ( cfstudio_get_field( 'meta', $pg )['noindex'] ?? null ), cfstudio_get_field( 'meta', $pg ) );
	$t( 'Seamless clone: stored under the prefixed / plain names', 'T-seo' === cfstudio_get_field( 'seo_seo_title', $pg ) && 'T-flat' === cfstudio_get_field( 'seo_title', $pg ) && 'T-seo' === get_post_meta( $pg, 'seo_seo_title', true ) );
	$t( 'Clone fields themselves store nothing and are not in cfstudio_get_fields', ! metadata_exists( 'post', $pg, 'meta_seo' ) && ! array_key_exists( 'seo', cfstudio_get_fields( $pg ) ) && array_key_exists( 'meta', cfstudio_get_fields( $pg ) ) );
	// Cycle guard + nesting.
	$a = $mk( 'P11 Cycle A', array( array( 'label' => 'A1', 'name' => 'a1', 'type' => 'text' ) ), array( array( $rule( 'options_page', 'p11-none' ) ) ) );
	$b = $mk( 'P11 Cycle B', array( array( 'label' => 'B1', 'name' => 'b1', 'type' => 'text' ), array( 'label' => 'FromA', 'name' => 'from_a', 'type' => 'clone', 'clone' => array( CFStudio_Field_Group::get_group_key( $a ) ), 'display' => 'seamless' ) ), array( array( $rule( 'options_page', 'p11-none' ) ) ) );
	update_post_meta( $a, '_cfstudio_fields', wp_slash( CFStudio_Field_Group::sanitize_fields( array( array( 'label' => 'A1', 'name' => 'a1', 'type' => 'text' ), array( 'label' => 'FromB', 'name' => 'from_b', 'type' => 'clone', 'clone' => array( CFStudio_Field_Group::get_group_key( $b ) ), 'display' => 'seamless' ) ) ) ) );
	$cyc = $mk( 'P11 Cycle User', array( array( 'label' => 'Self', 'name' => 'self', 'type' => 'clone', 'clone' => array( CFStudio_Field_Group::get_group_key( $a ) ), 'display' => 'seamless' ) ), array( array( $rule( 'post_id', (string) $pg ) ) ) );
	$made = array_merge( $made, array( $a, $b, $cyc ) );
	CFStudio_Field_Group::flush_cache();
	$cg = null;
	foreach ( CFStudio_Field_Group::get_groups_for_post( $pg ) as $g ) {
		if ( 'P11 Cycle User' === $g['title'] ) {
			$cg = $g;
		}
	}
	$t( 'Groups that clone each other (A→B→A) resolve without looping forever', is_array( $cg ) && array( 'a1', 'b1' ) === wp_list_pluck( $cg['fields'], 'name' ), $cg ? wp_list_pluck( $cg['fields'], 'name' ) : null );
	$self = $mk( 'P11 Self', array( array( 'label' => 'X', 'name' => 'x', 'type' => 'text' ) ), array( array( $rule( 'options_page', 'p11-none' ) ) ) );
	update_post_meta( $self, '_cfstudio_fields', wp_slash( CFStudio_Field_Group::sanitize_fields( array( array( 'label' => 'X', 'name' => 'x', 'type' => 'text' ), array( 'label' => 'Me', 'name' => 'me', 'type' => 'clone', 'clone' => array( CFStudio_Field_Group::get_group_key( $self ) ), 'display' => 'seamless' ) ) ) ) );
	$made[] = $self;
	CFStudio_Field_Group::flush_cache();
	$t( 'A group cloning itself does not loop', is_array( CFStudio_Field_Group::all_active() ) );
	$rep = $mk( 'P11 Nested', array( array( 'label' => 'Rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => array( array( 'label' => 'Seo', 'name' => 'seo', 'type' => 'clone', 'clone' => array( $seo_key ), 'display' => 'seamless' ) ) ) ), array( array( $rule( 'post_id', (string) $pg ) ) ) );
	$made[] = $rep;
	$rg = null;
	foreach ( CFStudio_Field_Group::get_groups_for_post( $pg ) as $g ) {
		if ( 'P11 Nested' === $g['title'] ) {
			$rg = $g;
		}
	}
	$t( 'A clone inside a repeater is resolved too', array( 'seo_title', 'noindex', 'reason' ) === wp_list_pluck( $rg['fields'][0]['sub_fields'] ?? array(), 'name' ), $rg['fields'][0] ?? null );
	update_post_meta( $seo, '_cfstudio_options', array( 'active' => 0 ) );
	CFStudio_Field_Group::flush_cache();
	$t( 'A clone source that is inactive still feeds its clones', in_array( 'seo_title', wp_list_pluck( CFStudio_Field_Group::get_groups_for_post( $pg )[0]['fields'], 'name' ), true ) || in_array( 'seo_title', array_merge( ...array_map( function ( $g ) { return wp_list_pluck( $g['fields'], 'name' ); }, CFStudio_Field_Group::get_groups_for_post( $pg ) ) ), true ) );
	update_post_meta( $seo, '_cfstudio_options', CFStudio_Field_Group::sanitize_options( array( 'active' => 1 ) ) );
	CFStudio_Field_Group::flush_cache();

	/* ---------------------------------------------------------------- */
	$module = 'JSON export / import';
	$exp = json_decode( CFStudio_JSON::export_json( array( $host, $seo ) ), true );
	$t( 'Export is valid JSON with version and the chosen groups only', is_array( $exp ) && CFSTUDIO_VERSION === $exp['cfstudio_version'] && 2 === count( $exp['groups'] ), $exp ? array_keys( $exp ) : null );
	$eg = array();
	foreach ( $exp['groups'] as $one ) {
		if ( 'P11 Host' === $one['title'] ) {
			$eg = $one;
		}
	}
	$host_i = 'P11 Host' === $exp['groups'][0]['title'] ? 0 : 1;
	$t( 'Exported group carries key, title, fields, location, options, modified', $eg['key'] === CFStudio_Field_Group::get_group_key( $host ) && 'P11 Host' === $eg['title'] && 4 === count( $eg['fields'] ) && ! empty( $eg['location'] ) && 1 === $eg['options']['show_in_rest'] && $eg['modified'] > 0, array_keys( $eg ) );
	$t( 'Clone references are exported as group KEYS, not post IDs', array( $seo_key ) === $eg['fields'][1]['clone'], $eg['fields'][1] );
	$all = json_decode( CFStudio_JSON::export_json(), true );
	$t( 'Exporting without a selection exports every group', count( $all['groups'] ) >= 8 );
	$before = count( get_posts( array( 'post_type' => 'cfstudio_field_group', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) );
	$r = CFStudio_JSON::import_data( $exp );
	$after = count( get_posts( array( 'post_type' => 'cfstudio_field_group', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) );
	$t( 'Re-importing the same export updates in place (no duplicates)', ! is_wp_error( $r ) && 0 === $r['created'] && 2 === $r['updated'] && $before === $after, $r );
	$again = json_decode( CFStudio_JSON::export_json( array( $host, $seo ) ), true );
	foreach ( array( 0, 1 ) as $i ) {
		unset( $exp['groups'][ $i ]['modified'], $again['groups'][ $i ]['modified'] );
	}
	$t( 'Export → import → export is identical (lossless round trip)', $exp['groups'] === $again['groups'], null );
	$new = $exp;
	$new['groups'][ $host_i ]['key']   = 'group_p11_import_new';
	$new['groups'][ $host_i ]['title'] = 'P11 Imported';
	$new['groups'][0] = $new['groups'][ $host_i ];
	$r2 = CFStudio_JSON::import_data( array( 'groups' => array( $new['groups'][0] ) ) );
	$imp_id = ! is_wp_error( $r2 ) ? $r2['ids'][0] : 0;
	$made[] = $imp_id;
	$t( 'A group with a new key is created, published, with the same fields', ! is_wp_error( $r2 ) && 1 === $r2['created'] && 'publish' === get_post_status( $imp_id ) && 'group_p11_import_new' === CFStudio_Field_Group::get_group_key( $imp_id ) && 4 === count( get_post_meta( $imp_id, '_cfstudio_fields', true ) ), $r2 );
	$t( 'Imported group is active at once', in_array( 'P11 Imported', wp_list_pluck( CFStudio_Field_Group::all_active(), 'title' ), true ) );
	$t( 'A single group (not wrapped) and a bare list are accepted', ! is_wp_error( CFStudio_JSON::import_data( $new['groups'][0] ) ) && ! is_wp_error( CFStudio_JSON::import_data( array( $new['groups'][0] ) ) ) );
	$e1 = CFStudio_JSON::import_data( 'junk' );
	$e2 = CFStudio_JSON::import_data( array( 'hello' => 'world' ) );
	$e3 = CFStudio_JSON::import_data( array( 'groups' => array( 'x', array( 'nope' => 1 ) ) ) );
	$t( 'Junk, unrelated JSON and malformed groups are rejected with an error', is_wp_error( $e1 ) && is_wp_error( $e2 ) && is_wp_error( $e3 ) );
	$evil = array( 'key' => 'Group P11 Evil!', 'title' => 'P11 <b>Evil</b><script>x</script>', 'fields' => array( array( 'label' => 'Bad', 'name' => 'Bad Name!', 'type' => 'not_a_type', 'required' => 'yes', 'instructions' => '<script>alert(1)</script>ok' ), array( 'label' => 'Rep', 'type' => 'repeater', 'sub_fields' => array( array( 'label' => 'S', 'type' => 'text', 'maxlength' => 'abc' ) ), 'max' => '-5' ) ), 'location' => array( array( array( 'param' => 'post_type', 'operator' => 'DROP', 'value' => 'page' ) ) ), 'options' => array( 'active' => 1, 'position' => 'evil', 'style' => 'evil', 'menu_order' => 'x' ) );
	$r3 = CFStudio_JSON::import_data( array( 'groups' => array( $evil ) ) );
	$ev = ! is_wp_error( $r3 ) ? $r3['ids'][0] : 0;
	$made[] = $ev;
	$evf = $ev ? get_post_meta( $ev, '_cfstudio_fields', true ) : array();
	$evo = $ev ? get_post_meta( $ev, '_cfstudio_options', true ) : array();
	$t( 'Hostile import is sanitised like the builder: key, title, name, type', 'groupp11evil' === CFStudio_Field_Group::get_group_key( $ev ) && false === strpos( get_the_title( $ev ), '<' ) && 'badname' === $evf[0]['name'] && 'text' === $evf[0]['type'] && false === strpos( $evf[0]['instructions'], '<script' ), array( $evf[0] ?? null, get_the_title( $ev ) ) );
	$t( 'Hostile import: options / operators coerced to valid values', 'normal' === $evo['position'] && 'default' === $evo['style'] && 0 === $evo['menu_order'] && '==' === get_post_meta( $ev, '_cfstudio_location', true )[0][0]['operator'], array( $evo, get_post_meta( $ev, '_cfstudio_location', true ) ) );
	$t( 'Hostile import: sub field settings are sanitised too', '' === ( $evf[1]['sub_fields'][0]['maxlength'] ?? '' ) || is_int( $evf[1]['sub_fields'][0]['maxlength'] ), $evf[1] ?? null );
	$t( 'Import with new keys on every field still gives unique field keys', ( function () use ( $evf ) {
		$k = wp_list_pluck( $evf, 'key' );
		return count( $k ) === count( array_unique( $k ) );
	} )() );

	$module = 'JSON Tools page';
	$admin_screen = ( function () {
		ob_start();
		CFStudio_JSON::render_page();
		return ob_get_clean();
	} )();
	$t( 'Tools page renders export checkboxes, import form and the local JSON status', false !== strpos( $admin_screen, 'id="cfs-export-form"' ) && false !== strpos( $admin_screen, 'P11 Host' ) && false !== strpos( $admin_screen, 'id="cfs-import-form"' ) && false !== strpos( $admin_screen, 'enctype="multipart/form-data"' ) && ( false !== strpos( $admin_screen, 'cfs-json-off' ) || false !== strpos( $admin_screen, 'cfs-json-on' ) ), strlen( $admin_screen ) );
	$t( 'Tools page needs manage_options', ( function () {
		$u = wp_insert_user( array( 'user_login' => 'cfstudio_p11_tools', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
		wp_set_current_user( $u );
		ob_start();
		CFStudio_JSON::render_page();
		$out = ob_get_clean();
		wp_set_current_user( 1 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $u );
		return '' === $out;
	} )() );
	$t( 'Tools page is registered under Field Groups', ( function () {
		global $submenu;
		do_action( 'admin_menu' );
		$ok = false;
		foreach ( (array) ( $submenu['edit.php?post_type=cfstudio_field_group'] ?? array() ) as $item ) {
			if ( CFStudio_JSON::MENU_SLUG === $item[2] ) {
				$ok = true;
			}
		}
		return $ok || has_action( 'admin_menu', array( 'CFStudio_JSON', 'register_menu' ) );
	} )() );
	$t( 'Export / import / sync handlers are wired to admin-post', has_action( 'admin_post_cfstudio_export' ) && has_action( 'admin_post_cfstudio_import' ) && has_action( 'admin_post_cfstudio_sync' ) );

	$module = 'Local JSON sync';
	$dir = get_temp_dir() . 'cfs-p11-json-' . wp_generate_password( 6, false );
	$t( 'Local JSON is off while the folder does not exist', '' === CFStudio_JSON::json_dir() || is_dir( CFStudio_JSON::json_dir() ) );
	$filter = function () use ( $dir ) {
		return $dir;
	};
	add_filter( 'cfstudio/json_dir', $filter );
	$t( 'Off when the folder is missing, even with the filter', '' === CFStudio_JSON::json_dir() );
	mkdir( $dir );
	$t( 'On once the folder exists', untrailingslashit( $dir ) === CFStudio_JSON::json_dir() );
	$lg = $mk( 'P11 Local', array( array( 'label' => 'L', 'name' => 'lname', 'type' => 'text' ) ), array( array( $rule( 'options_page', 'p11-none' ) ) ) );
	wp_update_post( array( 'ID' => $lg, 'post_title' => 'P11 Local' ) );
	$file = $dir . '/' . CFStudio_Field_Group::get_group_key( $lg ) . '.json';
	$t( 'Saving a group writes {key}.json', is_file( $file ), glob( $dir . '/*' ) );
	$fd = is_file( $file ) ? json_decode( file_get_contents( $file ), true ) : array();
	$t( 'File holds the full group', 'P11 Local' === ( $fd['title'] ?? '' ) && 'lname' === ( $fd['fields'][0]['name'] ?? '' ) && ! empty( $fd['modified'] ), $fd );
	$t( 'A group saved by us is not offered for sync', ! in_array( CFStudio_Field_Group::get_group_key( $lg ), wp_list_pluck( CFStudio_JSON::pending_sync(), 'key' ), true ) );
	// A new group arrives via the folder (e.g. git pull).
	$incoming = array( 'key' => 'group_p11_fromfile', 'title' => 'P11 From file', 'fields' => array( array( 'label' => 'FF', 'name' => 'ff', 'type' => 'text', 'key' => 'field_p11ff' ) ), 'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'p11-none' ) ) ), 'options' => array( 'active' => 1 ), 'modified' => time() + 100 );
	file_put_contents( $dir . '/group_p11_fromfile.json', wp_json_encode( $incoming ) );
	file_put_contents( $dir . '/garbage.json', '{not json' );
	$pend = CFStudio_JSON::pending_sync();
	$pk   = wp_list_pluck( $pend, 'status', 'key' );
	$t( 'A file unknown to the database is pending as "new"; garbage files are ignored', 'new' === ( $pk['group_p11_fromfile'] ?? '' ) && 1 === count( $pend ), $pk );
	$edited = $fd;
	$edited['title']    = 'P11 Local (edited in git)';
	$edited['modified'] = time() + 200;
	file_put_contents( $file, wp_json_encode( $edited ) );
	$pk = wp_list_pluck( CFStudio_JSON::pending_sync(), 'status', 'key' );
	$t( 'A file newer than the database copy is pending as "newer"', 'newer' === ( $pk[ CFStudio_Field_Group::get_group_key( $lg ) ] ?? '' ), $pk );
	$n = CFStudio_JSON::sync_all();
	$ff = CFStudio_JSON::find_group_by_key( 'group_p11_fromfile' );
	$made[] = $ff;
	$t( 'Sync imports both and reports the count', 2 === $n && $ff > 0 && 'P11 Local (edited in git)' === get_the_title( $lg ), $n );
	$t( 'After sync nothing is pending', array() === CFStudio_JSON::pending_sync() );
	$t( 'Syncing does not rewrite the files (git stays clean)', time() + 100 === (int) json_decode( file_get_contents( $dir . '/group_p11_fromfile.json' ), true )['modified'] && time() + 200 === (int) json_decode( file_get_contents( $file ), true )['modified'] );
	$t( 'Synced group is live', in_array( 'P11 From file', wp_list_pluck( CFStudio_Field_Group::all_active(), 'title' ), true ) );
	$r5 = CFStudio_JSON::import_data( array( 'groups' => array( $new['groups'][0] ) ) );
	$t( 'Importing with local JSON on writes the file with the final content', is_file( $dir . '/group_p11_import_new.json' ) && 4 === count( json_decode( file_get_contents( $dir . '/group_p11_import_new.json' ), true )['fields'] ) );
	$key_lg = CFStudio_Field_Group::get_group_key( $lg );
	wp_delete_post( $lg, true );
	$t( 'Deleting a group removes its file', ! is_file( $file ), glob( $dir . '/*' ) );
	remove_filter( 'cfstudio/json_dir', $filter );
	foreach ( (array) glob( $dir . '/*' ) as $leftover ) {
		unlink( $leftover );
	}
	rmdir( $dir );
	$t( 'Filter removed → local JSON off again', true );

	/* ---------------------------------------------------------------- */
	$module = 'REST API';
	$server = rest_get_server();
	$call   = function ( $method, $route, $params = array(), $json = null ) {
		$req = new WP_REST_Request( $method, $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		if ( null !== $json ) {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $json ) );
		}
		return rest_do_request( $req );
	};
	$rest_page = $mk(
		'P11 REST',
		array(
			array( 'label' => 'Title Line', 'name' => 'r_title', 'type' => 'text' ),
			array( 'label' => 'Count', 'name' => 'r_count', 'type' => 'number' ),
			array( 'label' => 'On', 'name' => 'r_on', 'type' => 'true_false' ),
			array( 'label' => 'Pick', 'name' => 'r_pick', 'type' => 'select', 'choices' => "a : A\nb : B" ),
			array( 'label' => 'Rows', 'name' => 'r_rows', 'type' => 'repeater', 'sub_fields' => array( array( 'label' => 'Name', 'name' => 'nm', 'type' => 'text' ), array( 'label' => 'Qty', 'name' => 'qty', 'type' => 'number' ) ) ),
			array( 'label' => 'Related', 'name' => 'r_rel', 'type' => 'post_object', 'return_format' => 'object', 'post_types' => array( 'post', 'page' ) ),
			array( 'label' => 'Hello', 'name' => 'r_msg', 'type' => 'message', 'message' => 'x' ),
		),
		array( array( $rule( 'post_id', (string) $pg ) ) ),
		array( 'show_in_rest' => 1 )
	);
	$hidden = $mk( 'P11 REST hidden', array( array( 'label' => 'Secret', 'name' => 'r_secret', 'type' => 'text' ) ), array( array( $rule( 'post_id', (string) $pg ) ) ) );
	$made   = array_merge( $made, array( $rest_page, $hidden ) );
	update_post_meta( $pg, '_wp_page_template', '' );
	update_post_meta( $pg, 'r_secret', 'TOP-SECRET' );
	$resp = $call( 'GET', '/wp/v2/pages/' . $pg, array( 'context' => 'edit' ) );
	$d    = $resp->get_data();
	$t( 'GET a page returns a cfs object', 200 === $resp->get_status() && isset( $d['cfs'] ) && ( is_array( $d['cfs'] ) || is_object( $d['cfs'] ) ), $resp->get_status() );
	$cf = (array) $d['cfs'];
	$t( 'Exposed fields listed (null when empty), layout fields skipped', array_key_exists( 'r_title', $cf ) && array_key_exists( 'r_rows', $cf ) && ! array_key_exists( 'r_msg', $cf ), array_keys( $cf ) );
	$t( 'Groups without "Show in REST API" are not exposed', ! array_key_exists( 'r_secret', $cf ) && false === strpos( wp_json_encode( $d ), 'TOP-SECRET' ) );
	$t( 'Cloned fields in an exposed group appear (resolved)', array_key_exists( 'headline', $cf ) && array_key_exists( 'meta', $cf ) && array_key_exists( 'seo_seo_title', $cf ), array_keys( $cf ) );
	$resp = $call( 'POST', '/wp/v2/pages/' . $pg, array(), array( 'cfs' => array( 'r_title' => 'Hello <b>x</b>', 'r_count' => '12', 'r_on' => true, 'r_pick' => 'b', 'r_rows' => array( array( 'nm' => 'one', 'qty' => 1 ), array( 'nm' => 'two', 'qty' => 2 ) ), 'r_rel' => $ids['rel'][1], 'r_secret' => 'HACK', 'unknown' => 'x' ) ) );
	$t( 'POST with cfs updates the page (200)', 200 === $resp->get_status(), $resp->get_data() );
	$t( 'Values stored through the field types (text sanitised)', 'Hello x' === get_post_meta( $pg, 'r_title', true ) && '12' === (string) get_post_meta( $pg, 'r_count', true ) && 'b' === get_post_meta( $pg, 'r_pick', true ) );
	$t( 'Repeater rows written as compound keys', 2 === (int) get_post_meta( $pg, 'r_rows', true ) && 'two' === get_post_meta( $pg, 'r_rows_1_nm', true ) && '2' === (string) get_post_meta( $pg, 'r_rows_1_qty', true ) );
	$t( 'Non-exposed and unknown fields cannot be written', 'TOP-SECRET' === get_post_meta( $pg, 'r_secret', true ) && ! metadata_exists( 'post', $pg, 'unknown' ) );
	$d = $call( 'GET', '/wp/v2/pages/' . $pg, array( 'context' => 'edit' ) )->get_data();
	$cf = (array) $d['cfs'];
	$t( 'GET returns what was written (raw values)', 'b' === $cf['r_pick'] && 2 === count( $cf['r_rows'] ) && 'one' === $cf['r_rows'][0]['nm'] && $ids['rel'][1] === (int) $cf['r_rel'], $cf );
	$resp = $call( 'POST', '/wp/v2/pages/' . $pg, array(), array( 'cfs' => $cf ) );
	$d2   = $call( 'GET', '/wp/v2/pages/' . $pg, array( 'context' => 'edit' ) )->get_data();
	$t( 'Read → write the same values back changes nothing (symmetry)', 200 === $resp->get_status() && (array) $d2['cfs'] === $cf, array( $cf, (array) $d2['cfs'] ) );
	$resp = $call( 'POST', '/wp/v2/pages/' . $pg, array(), array( 'cfs' => array( 'r_title' => 'Only title' ) ) );
	$d3   = (array) $call( 'GET', '/wp/v2/pages/' . $pg, array( 'context' => 'edit' ) )->get_data()['cfs'];
	$t( 'Fields not sent are left alone (partial update)', 'Only title' === $d3['r_title'] && 2 === count( $d3['r_rows'] ) );
	$d4 = $call( 'GET', '/wp/v2/pages/' . $pg, array( 'context' => 'edit', 'cfstudio_format' => 1 ) )->get_data()['cfs'];
	$d4 = (array) $d4;
	$t( 'cfstudio_format=1 returns formatted values, JSON-safe (post object → small array, boolean)', is_array( $d4['r_rel'] ) && $ids['rel'][1] === $d4['r_rel']['id'] && isset( $d4['r_rel']['permalink'] ) && is_bool( $d4['r_on'] ), $d4['r_rel'] ?? null );
	$bad = $call( 'POST', '/wp/v2/pages/' . $pg, array(), array( 'cfs' => 'notanobject' ) );
	$t( 'A non-object cfs value is rejected', $bad->get_status() >= 400 );
	// Permissions.
	wp_set_current_user( $sub );
	$resp = $call( 'POST', '/wp/v2/pages/' . $pg, array(), array( 'cfs' => array( 'r_title' => 'SUBHACK' ) ) );
	wp_set_current_user( 1 );
	$t( 'A subscriber cannot write through the page endpoint', $resp->get_status() >= 400 && 'SUBHACK' !== get_post_meta( $pg, 'r_title', true ) );
	wp_set_current_user( 0 );
	$anon = $call( 'GET', '/wp/v2/pages/' . $pg );
	wp_set_current_user( 1 );
	$t( 'Anonymous GET of a published page works', 200 === $anon->get_status() );
	// Users & terms.
	$resp = $call( 'POST', '/wp/v2/users/1', array(), array( 'cfs' => array( 'p11_twitter' => '@rest', 'p11_skills' => array( array( 'skill' => 'Go' ) ) ) ) );
	$t( 'POST cfs on a user writes user meta', 200 === $resp->get_status() && '@rest' === get_user_meta( 1, 'p11_twitter', true ) && 'Go' === get_user_meta( 1, 'p11_skills_0_skill', true ), $resp->get_data() );
	$ud = $call( 'GET', '/wp/v2/users/1', array( 'context' => 'edit' ) )->get_data();
	$t( 'GET a user returns its cfs values', '@rest' === ( (array) $ud['cfs'] )['p11_twitter'], $ud['cfs'] ?? null );
	wp_set_current_user( $sub );
	$ud2 = $call( 'GET', '/wp/v2/users/1' )->get_data();
	wp_set_current_user( 1 );
	$t( "Another user's cfs values are not shown to a subscriber", empty( $ud2['cfs'] ) || null === $ud2['cfs'], $ud2['cfs'] ?? null );
	$resp = $call( 'POST', '/wp/v2/categories/' . $cat_id, array(), array( 'cfs' => array( 'p11_color' => 'green', 'p11_featured' => true ) ) );
	$cd   = (array) $call( 'GET', '/wp/v2/categories/' . $cat_id, array( 'context' => 'edit' ) )->get_data()['cfs'];
	$t( 'POST / GET cfs on a category (term meta)', 200 === $resp->get_status() && 'green' === get_term_meta( $cat_id, 'p11_color', true ) && 'green' === $cd['p11_color'], $resp->get_data() );
	// Options endpoint.
	CFStudio_Options_Page::add( array( 'page_title' => 'P11 Opt', 'menu_slug' => 'p11-opt' ) );
	$og = $mk( 'P11 Opt group', array( array( 'label' => 'Phone', 'name' => 'p11_phone', 'type' => 'text' ), array( 'label' => 'Secret Opt', 'name' => 'p11_hidden_opt', 'type' => 'text' ) ), array( array( $rule( 'options_page', 'p11-opt' ) ) ), array( 'show_in_rest' => 1 ) );
	$og2 = $mk( 'P11 Opt private', array( array( 'label' => 'Private', 'name' => 'p11_private_opt', 'type' => 'text' ) ), array( array( $rule( 'options_page', 'p11-opt' ) ) ) );
	$made = array_merge( $made, array( $og, $og2 ) );
	$resp = $call( 'POST', '/cfstudio/v1/options', array(), array( 'p11_phone' => '555-0100', 'p11_private_opt' => 'nope' ) );
	$t( 'POST /cfstudio/v1/options as admin saves exposed option values', 200 === $resp->get_status() && '555-0100' === get_option( 'options_p11_phone' ) && false === get_option( 'options_p11_private_opt', false ), $resp->get_data() );
	wp_set_current_user( 0 );
	$anon = $call( 'GET', '/cfstudio/v1/options' );
	$anon_body = wp_json_encode( $anon->get_data() );
	$t( 'GET /cfstudio/v1/options is public and lists only exposed groups', 200 === $anon->get_status() && false !== strpos( $anon_body, '555-0100' ) && false === strpos( $anon_body, 'p11_private_opt' ), $anon_body );
	$post_anon = $call( 'POST', '/cfstudio/v1/options', array(), array( 'p11_phone' => 'ANON' ) );
	wp_set_current_user( $sub );
	$post_sub = $call( 'POST', '/cfstudio/v1/options', array(), array( 'p11_phone' => 'SUB' ) );
	wp_set_current_user( 1 );
	$t( 'Writing options needs manage_options (anonymous and subscriber refused)', $post_anon->get_status() >= 400 && $post_sub->get_status() >= 400 && '555-0100' === get_option( 'options_p11_phone' ) );
	$t( 'GET /cfstudio/v1/groups describes exposed groups only, for editors', ( function () use ( $call, $sub ) {
		$ok = $call( 'GET', '/cfstudio/v1/groups' );
		$names = wp_list_pluck( (array) $ok->get_data(), 'title' );
		wp_set_current_user( 0 );
		$anon = $call( 'GET', '/cfstudio/v1/groups' );
		wp_set_current_user( 1 );
		return 200 === $ok->get_status() && in_array( 'P11 REST', $names, true ) && ! in_array( 'P11 REST hidden', $names, true ) && $anon->get_status() >= 400;
	} )() );
	$gd = $call( 'GET', '/cfstudio/v1/groups' )->get_data();
	$rg = null;
	foreach ( $gd as $g ) {
		if ( 'P11 REST' === $g['title'] ) {
			$rg = $g;
		}
	}
	$t( 'Group schema lists names/types, repeater sub fields, select choices; skips layout fields', $rg && array( 'r_title', 'r_count', 'r_on', 'r_pick', 'r_rows', 'r_rel' ) === wp_list_pluck( $rg['fields'], 'name' ) && 2 === count( $rg['fields'][4]['sub_fields'] ) && ! empty( $rg['fields'][3]['choices'] ), $rg );
	$t( 'show_in_rest setting is saved from the builder form and shown in Settings', ( function () use ( $host ) {
		$post = get_post( $host );
		ob_start();
		( new CFStudio_Field_Group() )->render_settings_meta_box( $post );
		$h = ob_get_clean();
		return false !== strpos( $h, 'name="cfstudio_options[show_in_rest]"' ) && false !== strpos( $h, 'checked' );
	} )() );

	/* ---------------------------------------------------------------- */
	// Clean up everything this phase created.
	foreach ( $made as $gid ) {
		if ( $gid ) {
			wp_delete_post( $gid, true );
		}
	}
	foreach ( array( $pg, $dr ) as $pid_ ) {
		wp_delete_post( $pid_, true );
	}
	wp_delete_term( $cat_id, 'category' );
	wp_delete_term( $tag['term_id'], 'post_tag' );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sub );
	foreach ( array( 'p11_twitter', 'p11_bio', 'p11_skills', 'p11_skills_0_skill', 'p11_skills_1_skill', '_p11_twitter', '_p11_bio', '_p11_skills' ) as $mk_ ) {
		delete_user_meta( 1, $mk_ );
	}
	delete_option( 'options_p11_phone' );
	delete_option( '_options_p11_phone' );
	delete_option( 'options__p11_phone' );
	CFStudio_Field_Group::flush_cache();
	$module = 'Clean-up';
	$left = array();
	foreach ( get_posts( array( 'post_type' => 'cfstudio_field_group', 'post_status' => 'any', 'numberposts' => -1 ) ) as $g ) {
		if ( 0 === strpos( $g->post_title, 'P11' ) ) {
			$left[] = $g->post_title;
		}
	}
	$t( 'Phase 11 test data removed (no leftover P11 groups)', ! $left, $left );
}
