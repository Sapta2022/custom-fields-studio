<?php
/**
 * Phase 10 checks: layout fields, group, post object / page link / user,
 * conditional logic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cfstudio_qa_run_p10( $t, &$phase, &$module, $P1, $ids ) {
	$phase = 'P10';
	$R     = $ids['rel'];
	$P2    = $ids['page'][2];
	$f     = function ( $type, $extra = array() ) {
		return array_merge( CFStudio_Field_Registry::get_class( $type )::defaults(), array( 'type' => $type, 'name' => 'x', 'key' => 'field_x', 'label' => 'X' ), $extra );
	};
	$sub = function ( $type, $name, $extra = array() ) use ( $f ) {
		return $f( $type, array_merge( array( 'name' => $name, 'key' => 'field_' . $name, 'label' => ucfirst( $name ) ), $extra ) );
	};

	$module = 'Registry';
	foreach ( array( 'message', 'tab', 'accordion', 'group', 'post_object', 'page_link', 'user' ) as $ty ) {
		$cls = CFStudio_Field_Registry::get_class( $ty );
		$t( "Type '$ty' registered", (bool) $cls );
		if ( $cls ) {
			ob_start();
			$cls::render_settings( $f( $ty ), 'cfstudio_fields[0]' );
			$html = ob_get_clean();
			$t( "'$ty' builder settings render", false !== strpos( $html, 'data-type="' . $ty . '"' ), substr( $html, 0, 60 ) );
			$t( "'$ty' sanitize() copes with an empty post", is_array( $cls::sanitize( array() ) ) );
		}
	}

	$module = 'Message / Tab / Accordion';
	$t( 'Layout types store nothing and say so', ! CFStudio_Field_Message::stores_value() && ! CFStudio_Field_Tab::stores_value() && ! CFStudio_Field_Accordion::stores_value() && CFStudio_Field_Text::stores_value() );
	$t( 'Tab and accordion have no label wrapper; message has', ! CFStudio_Field_Tab::has_wrapper() && ! CFStudio_Field_Accordion::has_wrapper() && CFStudio_Field_Message::has_wrapper() );
	$m = CFStudio_Field_Message::sanitize( array( 'message' => "Hi <b>there</b><script>alert(1)</script>\r\nline", 'new_lines' => 'evil' ) );
	$t( 'Message: script stripped, safe HTML kept, CRLF normalised, bad mode ignored', false === strpos( $m['message'], '<script' ) && false !== strpos( $m['message'], '<b>there</b>' ) && false === strpos( $m['message'], "\r" ) && 'wpautop' === $m['new_lines'], $m );
	ob_start();
	CFStudio_Field_Message::render_input( $f( 'message', array( 'message' => "a\n\nb <i>x</i>" ) ), null, 'cfs[m]' );
	$html = ob_get_clean();
	$t( 'Message: wpautop paragraphs rendered', 2 === substr_count( $html, '<p>' ) && false !== strpos( $html, '<i>x</i>' ), $html );
	ob_start();
	CFStudio_Field_Message::render_input( $f( 'message', array( 'message' => '<b>x</b>', 'esc_html' => 1, 'new_lines' => '' ) ), null, 'cfs[m]' );
	$html = ob_get_clean();
	$t( 'Message: "show HTML as text" escapes tags', false !== strpos( $html, '&lt;b&gt;x&lt;/b&gt;' ), $html );
	CFStudio_Field_Message::save_value( $f( 'message', array( 'name' => 'p10_msg' ) ), 'x', $P1 );
	$t( 'Message: saving writes no meta', ! metadata_exists( 'post', $P1, 'p10_msg' ) && ! metadata_exists( 'post', $P1, '_p10_msg' ) );
	$t( 'Layout fields format to null', null === CFStudio_Field_Tab::format_value( 'x', array() ) );
	ob_start();
	CFStudio_Meta_Box::render_field( $f( 'tab', array( 'label' => 'My <Tab>' ) ), null, 'cfs[t]' );
	$html = ob_get_clean();
	$t( 'Tab marker rendered without a field label, label escaped', false !== strpos( $html, 'cfs-tab-marker' ) && false === strpos( $html, 'cfs-field-label' ) && false !== strpos( $html, 'data-label="My &lt;Tab&gt;"' ), $html );
	ob_start();
	CFStudio_Meta_Box::render_field( $f( 'accordion', array( 'label' => 'More', 'open' => 1 ) ), null, 'cfs[a]' );
	$html = ob_get_clean();
	$t( 'Accordion marker carries open flag', false !== strpos( $html, 'cfs-accordion-marker' ) && false !== strpos( $html, 'data-open="1"' ), $html );

	$module = 'Group';
	$grp = $f(
		'group',
		array(
			'name'       => 'p10_grp',
			'key'        => 'field_grp',
			'sub_fields' => array(
				$sub( 'text', 'title', array( 'maxlength' => 5, 'default_value' => 'dflt' ) ),
				$sub( 'true_false', 'on' ),
				$sub( 'number', 'qty', array( 'min' => 1, 'max' => 9 ) ),
				$sub( 'group', 'inner', array( 'sub_fields' => array( $sub( 'text', 'deep' ) ) ) ),
				$sub( 'message', 'note', array( 'message' => 'm' ) ),
			),
		)
	);
	$t( 'Group: never saved loads as null (so defaults show)', null === CFStudio_Field_Group_Field::load_value( $grp, $P1 ) );
	ob_start();
	CFStudio_Field_Group_Field::render_input( $grp, null, 'cfs[p10_grp]' );
	$html = ob_get_clean();
	$t( 'Group: renders sub fields under cfs[group][sub] and applies defaults', false !== strpos( $html, 'name="cfs[p10_grp][title]"' ) && false !== strpos( $html, 'value="dflt"' ) && false !== strpos( $html, 'name="cfs[p10_grp][inner][deep]"' ), $html );
	$t( 'Group: message sub field shown, but no input name for it', false !== strpos( $html, 'cfs-message' ) && false === strpos( $html, 'cfs[p10_grp][note]' ) );
	CFStudio_Field_Group_Field::save_value( $grp, array( 'title' => 'toolongvalue', 'on' => '1', 'qty' => '50', 'inner' => array( 'deep' => 'deepval' ), 'note' => 'x', 'evil' => 'y' ), $P1 );
	$t( 'Group: sub values stored under compound keys', 'toolo' === get_post_meta( $P1, 'p10_grp_title', true ) && '1' === (string) get_post_meta( $P1, 'p10_grp_on', true ) && 'deepval' === get_post_meta( $P1, 'p10_grp_inner_deep', true ) );
	$t( 'Group: reference keys written for group and sub fields', 'field_grp' === get_post_meta( $P1, '_p10_grp', true ) && 'field_title' === get_post_meta( $P1, '_p10_grp_title', true ) && 'field_deep' === get_post_meta( $P1, '_p10_grp_inner_deep', true ) );
	$t( 'Group: sub field sanitising applies (maxlength, number max)', 9 === (int) get_post_meta( $P1, 'p10_grp_qty', true ) );
	$t( 'Group: unknown / layout-only keys are not stored', ! metadata_exists( 'post', $P1, 'p10_grp_evil' ) && ! metadata_exists( 'post', $P1, 'p10_grp_note' ) );
	$fmt = CFStudio_Field_Group_Field::format_value( CFStudio_Field_Group_Field::load_value( $grp, $P1 ), $grp );
	$t( 'Group: formats to an assoc array with nested group', 'toolo' === $fmt['title'] && true === $fmt['on'] && 9 === $fmt['qty'] && 'deepval' === $fmt['inner']['deep'] && ! array_key_exists( 'note', $fmt ), $fmt );
	ob_start();
	CFStudio_Field_Group_Field::render_input( $grp, CFStudio_Field_Group_Field::load_value( $grp, $P1 ), 'cfs[p10_grp]' );
	$html = ob_get_clean();
	$t( 'Group: saved values shown', false !== strpos( $html, 'value="toolo"' ) );
	CFStudio_Field_Group_Field::save_value( $grp, array( 'title' => '' ), $P1 );
	ob_start();
	CFStudio_Field_Group_Field::render_input( $grp, CFStudio_Field_Group_Field::load_value( $grp, $P1 ), 'cfs[p10_grp]' );
	$html = ob_get_clean();
	$t( 'Group: a saved empty value does not bring the default back', false === strpos( $html, 'value="dflt"' ) );
	CFStudio_Field_Group_Field::save_value( $grp, array( 'title' => 'ab', 'inner' => array( 'deep' => 'd' ) ), $P1 );
	CFStudio_Field_Group_Field::delete_value( $grp, $P1 );
	$left = array_filter( array_keys( get_post_meta( $P1 ) ), function ( $k ) { return 0 === strpos( ltrim( $k, '_' ), 'p10_grp' ); } );
	$t( 'Group: delete removes every key it wrote', ! $left, array_values( $left ) );
	$t( 'Group: format of a missing value is null', null === CFStudio_Field_Group_Field::format_value( null, $grp ) );

	$module = 'Group inside a Repeater';
	$rep = $f(
		'repeater',
		array(
			'name'       => 'p10_rep',
			'key'        => 'field_rep',
			'sub_fields' => array(
				$sub( 'text', 'label' ),
				$sub( 'group', 'meta', array( 'sub_fields' => array( $sub( 'text', 'a' ), $sub( 'email', 'mail' ) ) ) ),
				$sub( 'tab', 'a_tab' ),
			),
		)
	);
	CFStudio_Field_Repeater::save_value( $rep, array( 'n1' => array( 'label' => 'One', 'meta' => array( 'a' => 'A1', 'mail' => 'bad' ) ), 'n2' => array( 'label' => 'Two', 'meta' => array( 'a' => 'A2', 'mail' => 'ok@x.io' ) ) ), $P1 );
	$rows = CFStudio_Field_Repeater::format_value( CFStudio_Field_Repeater::load_value( $rep, $P1 ), $rep );
	$t( 'Repeater row holds group values', 'A1' === $rows[0]['meta']['a'] && '' === (string) $rows[0]['meta']['mail'] && 'ok@x.io' === $rows[1]['meta']['mail'], $rows );
	$t( 'Compound keys are repeater_row_group_sub', 'A2' === get_post_meta( $P1, 'p10_rep_1_meta_a', true ) );
	$t( 'Repeater rows contain no tab entry', ! array_key_exists( 'a_tab', $rows[0] ), $rows[0] );
	CFStudio_Field_Repeater::delete_value( $rep, $P1 );
	$left = array_filter( array_keys( get_post_meta( $P1 ) ), function ( $k ) { return 0 === strpos( ltrim( $k, '_' ), 'p10_rep' ); } );
	$t( 'Repeater delete removes group keys too', ! $left, array_values( $left ) );

	$module = 'Post Object';
	$po = $f( 'post_object', array( 'post_types' => array( 'post' ) ) );
	$t( 'Single: valid post id kept as an int', $R[1] === CFStudio_Field_Post_Object::sanitize_value( array( '', (string) $R[1] ), $po ) );
	$t( 'Single: only the first of several is kept', $R[1] === CFStudio_Field_Post_Object::sanitize_value( array( $R[1], $R[2] ), $po ) );
	$t( 'Single: wrong post type / junk / missing rejected', '' === CFStudio_Field_Post_Object::sanitize_value( array( $P2 ), $po ) && '' === CFStudio_Field_Post_Object::sanitize_value( array( 'x', 999999 ), $po ) && '' === CFStudio_Field_Post_Object::sanitize_value( '', $po ) );
	$pom = $f( 'post_object', array( 'post_types' => array( 'post' ), 'multiple' => 1 ) );
	$t( 'Multiple: ordered, de-duplicated, invalid dropped', array( $R[2], $R[1] ) === CFStudio_Field_Post_Object::sanitize_value( array( $R[2], $R[1], $R[2], $P2, 'x' ), $pom ) );
	$t( 'Return object', is_object( CFStudio_Field_Post_Object::format_value( $R[1], $po ) ) && $R[1] === CFStudio_Field_Post_Object::format_value( $R[1], $po )->ID );
	$t( 'Return id', $R[1] === CFStudio_Field_Post_Object::format_value( $R[1], $f( 'post_object', array( 'return_format' => 'id', 'post_types' => array( 'post' ) ) ) ) );
	$mm = CFStudio_Field_Post_Object::format_value( array( $R[1], $R[2] ), $pom );
	$t( 'Multiple returns a list', is_array( $mm ) && 2 === count( $mm ) );
	$t( 'Nothing selected returns null', null === CFStudio_Field_Post_Object::format_value( '', $po ) );
	$d = wp_insert_post( array( 'post_title' => 'QA draft po', 'post_status' => 'draft' ) );
	update_post_meta( $d, '_cfstudio_qa', 1 );
	wp_set_current_user( 0 );
	$vis = CFStudio_Field_Post_Object::format_value( $d, $po );
	wp_set_current_user( 1 );
	$t( 'Draft post is hidden from visitors', null === $vis );
	wp_delete_post( $d, true );
	ob_start();
	CFStudio_Field_Post_Object::render_input( $po, $R[1], 'cfs[po]' );
	$html = ob_get_clean();
	$t( 'Picker renders in single mode with the saved post', false !== strpos( $html, 'data-max="1"' ) && false !== strpos( $html, 'value="' . $R[1] . '"' ), $html );
	ob_start();
	CFStudio_Field_Post_Object::render_input( $pom, array(), 'cfs[po]' );
	$html = ob_get_clean();
	$t( 'Picker renders unlimited in multiple mode', false !== strpos( $html, 'data-max="0"' ) );
	$s = CFStudio_Field_Post_Object::sanitize( array( 'post_types' => array( 'post', 'evil', 'attachment' ), 'multiple' => '1', 'return_format' => 'junk' ) );
	$t( 'Settings: post types validated, return format defaulted', array( 'post' ) === $s['post_types'] && 1 === $s['multiple'] && 'object' === $s['return_format'], $s );

	$module = 'Page Link';
	$pl = $f( 'page_link' );
	$t( 'Page link defaults to pages', array( 'page' ) === $pl['post_types'] );
	$t( 'Accepts a page, rejects a post', $P2 === CFStudio_Field_Page_Link::sanitize_value( array( $P2 ), $pl ) && '' === CFStudio_Field_Page_Link::sanitize_value( array( $R[1] ), $pl ) );
	$t( 'Returns the permalink', get_permalink( $P2 ) === CFStudio_Field_Page_Link::format_value( $P2, $pl ) );
	$t( 'Multiple returns permalinks', array( get_permalink( $P2 ) ) === CFStudio_Field_Page_Link::format_value( array( $P2 ), $f( 'page_link', array( 'multiple' => 1 ) ) ) );
	$t( 'No return-format setting is stored', ! array_key_exists( 'return_format', CFStudio_Field_Page_Link::sanitize( array() ) ) );

	$module = 'User';
	$editor = (int) get_user_by( 'login', 'qaeditor' )->ID;
	$admin  = 1;
	$u      = $f( 'user' );
	$t( 'User: existing id kept', $admin === CFStudio_Field_User::sanitize_value( (string) $admin, $u ) );
	$t( 'User: missing / junk rejected', '' === CFStudio_Field_User::sanitize_value( '999999', $u ) && '' === CFStudio_Field_User::sanitize_value( 'abc', $u ) && '' === CFStudio_Field_User::sanitize_value( array(), $u ) );
	$t( 'User: role filter enforced', '' === CFStudio_Field_User::sanitize_value( (string) $admin, $f( 'user', array( 'role' => 'editor' ) ) ) && $editor === CFStudio_Field_User::sanitize_value( (string) $editor, $f( 'user', array( 'role' => 'editor' ) ) ) );
	$um = $f( 'user', array( 'multiple' => 1 ) );
	$t( 'User: multiple de-duplicates, drops invalid', array( $editor, $admin ) === CFStudio_Field_User::sanitize_value( array( $editor, $admin, $editor, 999999 ), $um ) );
	$arr = CFStudio_Field_User::format_value( $admin, $u );
	$t( 'User: array format has name fields and no email', $admin === $arr['ID'] && ! empty( $arr['display_name'] ) && ! array_key_exists( 'user_email', $arr ), $arr );
	$t( 'User: object / id formats', CFStudio_Field_User::format_value( $admin, $f( 'user', array( 'return_format' => 'object' ) ) ) instanceof WP_User && $admin === CFStudio_Field_User::format_value( $admin, $f( 'user', array( 'return_format' => 'id' ) ) ) );
	$t( 'User: multiple returns list, none returns null', 2 === count( CFStudio_Field_User::format_value( array( $admin, $editor ), $f( 'user', array( 'multiple' => 1, 'return_format' => 'id' ) ) ) ) && null === CFStudio_Field_User::format_value( '', $u ) );
	ob_start();
	CFStudio_Field_User::render_input( $f( 'user', array( 'role' => 'editor', 'allow_null' => 1 ) ), $editor, 'cfs[u]' );
	$html = ob_get_clean();
	$t( 'User: dropdown lists only that role, selected one marked, null option present', false !== strpos( $html, 'value="' . $editor . '" selected' ) && false === strpos( $html, 'value="' . $admin . '"' ) && false !== strpos( $html, 'value=""' ), $html );
	$s = CFStudio_Field_User::sanitize( array( 'role' => 'not_a_role', 'return_format' => 'x' ) );
	$t( 'User: settings validated', '' === $s['role'] && 'array' === $s['return_format'], $s );

	$module = 'Conditional logic';
	$c = CFStudio_Conditional::sanitize( array( 'enabled' => '1', 'match' => 'any', 'rules' => array( array( 'field' => 'Other Field!', 'operator' => '!empty', 'value' => '<b>x</b>' ), array( 'field' => '', 'operator' => '==', 'value' => 'skipped' ), array( 'field' => 'b', 'operator' => 'drop table', 'value' => 'y' ), 'junk' ) ) );
	$t( 'Sanitise: keeps valid rules, drops blank / junk, bad operator becomes ==', 'any' === $c['match'] && 2 === count( $c['rules'] ) && 'otherfield' === $c['rules'][0]['field'] && 'x' === $c['rules'][0]['value'] && '==' === $c['rules'][1]['operator'], $c );
	$t( 'Sanitise: disabled / no rules / junk give nothing', array() === CFStudio_Conditional::sanitize( array() ) && array() === CFStudio_Conditional::sanitize( array( 'rules' => array( array( 'field' => 'a' ) ) ) ) && array() === CFStudio_Conditional::sanitize( array( 'enabled' => 1, 'rules' => array() ) ) && array() === CFStudio_Conditional::sanitize( 'junk' ) );
	$t( 'Sanitise: unknown match falls back to all', 'all' === CFStudio_Conditional::sanitize( array( 'enabled' => 1, 'match' => 'evil', 'rules' => array( array( 'field' => 'a', 'operator' => '==', 'value' => '1' ) ) ) )['match'] );
	$clean = CFStudio_Field_Group::sanitize_fields(
		array(
			array( 'label' => 'Kind', 'name' => 'kind', 'type' => 'select', 'choices' => "a : A\nb : B" ),
			array( 'label' => 'Extra', 'name' => 'extra', 'type' => 'text', 'conditional_logic' => array( 'enabled' => 1, 'rules' => array( array( 'field' => 'kind', 'operator' => '==', 'value' => 'b' ) ) ) ),
			array( 'label' => 'Plain', 'name' => 'plain', 'type' => 'text', 'conditional_logic' => array( 'enabled' => 0, 'rules' => array( array( 'field' => 'kind', 'operator' => '==', 'value' => 'b' ) ) ) ),
		)
	);
	$t( 'Field group save keeps the rule on the right field only', 'kind' === $clean[1]['conditional_logic']['rules'][0]['field'] && ! isset( $clean[0]['conditional_logic'] ) && ! isset( $clean[2]['conditional_logic'] ), $clean );
	$attr = CFStudio_Conditional::data_attr( $clean[1] );
	$t( 'data attribute is JSON, empty for fields without logic', is_array( json_decode( $attr, true ) ) && '' === CFStudio_Conditional::data_attr( $clean[0] ) );
	ob_start();
	CFStudio_Meta_Box::render_field( $clean[1], null, 'cfs[extra]' );
	$html = ob_get_clean();
	$t( 'Meta box wrapper carries data-cfs-cond', false !== strpos( $html, 'data-cfs-cond="' ) && false !== strpos( $html, '&quot;field&quot;:&quot;kind&quot;' ), $html );
	ob_start();
	CFStudio_Conditional::render_builder( $clean[1], 'cfstudio_fields[0]' );
	$html = ob_get_clean();
	$t( 'Builder UI: checkbox checked, one rule row with the saved field/operator/value', false !== strpos( $html, 'checked' ) && 1 === substr_count( $html, 'class="cfs-cond-rule"' ) && false !== strpos( $html, 'name="cfstudio_fields[0][conditional_logic][rules][0][field]"' ) && false !== strpos( $html, 'value="b"' ), $html );
	ob_start();
	CFStudio_Conditional::render_builder( $clean[0], 'cfstudio_fields[0]' );
	$html = ob_get_clean();
	$t( 'Builder UI: collapsed with no rules for a normal field', 0 === substr_count( $html, 'class="cfs-cond-rule"' ) && false !== strpos( $html, 'display:none' ) );
	$nested = CFStudio_Field_Group::sanitize_fields( array( array( 'label' => 'Rep', 'name' => 'rep', 'type' => 'repeater', 'sub_fields' => array( array( 'label' => 'A', 'name' => 'a', 'type' => 'true_false' ), array( 'label' => 'B', 'name' => 'b', 'type' => 'text', 'conditional_logic' => array( 'enabled' => 1, 'rules' => array( array( 'field' => 'a', 'operator' => '==', 'value' => '1' ) ) ) ) ) ) ) );
	$t( 'Rules survive inside a repeater sub field', 'a' === $nested[0]['sub_fields'][1]['conditional_logic']['rules'][0]['field'] );

	$module = 'Shortcode output';
	$sc = function ( $type, $value, $extra = array() ) use ( $f ) {
		return CFStudio_Shortcode::render_field( $f( $type, $extra ), $value );
	};
	$t( 'Group renders its cells', false !== strpos( $sc( 'group', array( 'a' => 'AA' ), array( 'sub_fields' => array( $sub( 'text', 'a' ) ) ) ), 'AA' ) );
	$t( 'Post object renders a link', false !== strpos( $sc( 'post_object', get_post( $R[1] ) ), get_the_title( $R[1] ) ) );
	$t( 'Page link renders the url', false !== strpos( $sc( 'page_link', get_permalink( $P2 ) ), 'href=' ) );
	$t( 'User renders the display name only', false !== strpos( $sc( 'user', array( 'ID' => 1, 'display_name' => 'Zed <b>' ) ), 'Zed &lt;b&gt;' ) );
	$t( 'Layout types render nothing', '' === $sc( 'tab', null ) && '' === $sc( 'message', null ) );
}
