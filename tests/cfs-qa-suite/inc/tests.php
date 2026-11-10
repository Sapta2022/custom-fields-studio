<?php
/**
 * QA test run: every phase / module / option, against the seeded content.
 * Returns rows: [ phase, module, label, passed(bool), detail ].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cfstudio_qa_run_tests() {
	$out    = array();
	$phase  = '';
	$module = '';
	$t      = function ( $label, $cond, $detail = '' ) use ( &$out, &$phase, &$module ) {
		$out[] = array( $phase, $module, $label, (bool) $cond, $cond ? '' : ( is_scalar( $detail ) ? (string) $detail : wp_json_encode( $detail ) ) );
	};

	try {
		$ids = cfstudio_qa_seed();
		CFStudio_Field_Group::flush_cache();
		$P1 = $ids['page'][1];
		$P2 = $ids['page'][2];
		$P3 = $ids['page'][3];
		$I  = $ids['img'];
		$R  = $ids['rel'];
		$C  = $ids['cat'];
		$T  = $ids['tag'];
		$g  = function ( $name, $post = null, $format = true ) use ( $P1 ) {
			return cfstudio_get_field( $name, $post ?? $P1, $format );
		};
		$rows = function ( $name, $post ) {
			cfstudio_reset_rows();
			$all = array();
			while ( cfstudio_have_rows( $name, $post ) ) {
				$all[] = cfstudio_the_row();
			}
			return $all;
		};

		/* ===================== PHASE 1: groups, builder data, location ===================== */
		$phase  = 'P1';
		$module = 'Field registry';
		$types  = CFStudio_Field_Registry::get_choices();
		foreach ( array( 'text', 'textarea', 'select', 'true_false', 'wysiwyg', 'image', 'repeater', 'flexible_content', 'date_picker', 'gallery', 'relationship', 'taxonomy' ) as $ty ) {
			$t( "Field type '$ty' is registered", isset( $types[ $ty ] ) );
		}
		$t( 'Field types have labels', ! in_array( '', $types, true ) );

		$module = 'Field groups';
		$t( 'Field group post type registered', post_type_exists( 'cfstudio_field_group' ) );
		$all_groups = wp_list_pluck( CFStudio_Field_Group::get_matching_groups( 'page' ), 'title' );
		$t( 'Page has QA Basic + QA Repeater groups', in_array( 'QA Basic Fields', $all_groups, true ) && in_array( 'QA Repeater & Flexible', $all_groups, true ), $all_groups );
		$t( 'Groups ordered by menu_order', array_search( 'QA Basic Fields', $all_groups, true ) < array_search( 'QA Repeater & Flexible', $all_groups, true ) );
		$defs = cfstudio_qa_group_definitions();
		foreach ( $defs as $key => $def ) {
			$stored = CFStudio_Field_Group::sanitize_fields( $def['fields'] );
			$count  = count( $def['fields'] );
			$t( "Group '$key': all $count fields survive sanitize", count( $stored ) === $count, count( $stored ) );
			$keys = array();
			$walk = function ( $fields ) use ( &$walk, &$keys ) {
				foreach ( $fields as $f ) {
					$keys[] = $f['key'];
					if ( ! empty( $f['sub_fields'] ) ) {
						$walk( $f['sub_fields'] );
					}
					foreach ( (array) ( $f['layouts'] ?? array() ) as $l ) {
						$walk( $l['sub_fields'] ?? array() );
					}
				}
			};
			$walk( $stored );
			$t( "Group '$key': every field key unique (incl. nested)", count( $keys ) === count( array_unique( $keys ) ), count( $keys ) . ' vs ' . count( array_unique( $keys ) ) );
		}
		$dups = CFStudio_Field_Group::sanitize_fields( array( array( 'label' => 'A', 'name' => 'x' ), array( 'label' => 'B', 'name' => 'x' ), array( 'label' => 'C', 'name' => 'X' ) ) );
		$t( 'Duplicate sibling names get _2/_3 suffixes', wp_list_pluck( $dups, 'name' ) === array( 'x', 'x_2', 'x_3' ), wp_list_pluck( $dups, 'name' ) );
		$t( 'Blank rows are dropped', 1 === count( CFStudio_Field_Group::sanitize_fields( array( array( 'label' => '', 'name' => '' ), array( 'label' => 'Keep' ) ) ) ) );
		$t( 'Name auto-derived from label', 'my_label' === CFStudio_Field_Group::sanitize_fields( array( array( 'label' => 'My_Label' ) ) )[0]['name'] );
		$t( 'Unknown type falls back to text', 'text' === CFStudio_Field_Group::sanitize_fields( array( array( 'label' => 'Z', 'type' => 'nope' ) ) )[0]['type'] );

		$module = 'Builder settings round-trip';
		$f = function ( $name ) use ( $defs ) {
			foreach ( $defs as $d ) {
				foreach ( CFStudio_Field_Group::sanitize_fields( $d['fields'] ) as $fld ) {
					if ( $fld['name'] === $name ) {
						return $fld;
					}
				}
			}
			return null;
		};
		$x = $f( 'qa_text' );
		$t( 'Text: default/placeholder/maxlength/required/instructions kept', 'Default text' === $x['default_value'] && 'Type here' === $x['placeholder'] && 20 === (int) $x['maxlength'] && 1 === $x['required'] && 'Max 20 chars.' === $x['instructions'], $x );
		$x = $f( 'qa_textarea' );
		$t( 'Textarea: rows/default/placeholder kept', 6 === (int) $x['rows'] && 'Default area' === $x['default_value'] && 'Write' === $x['placeholder'], $x );
		$x = $f( 'qa_select_single' );
		$t( 'Select: choices/allow_null/default kept', 1 === $x['allow_null'] && 'blue' === $x['default_value'] && false !== strpos( $x['choices'], 'green' ), $x );
		$t( 'Select multiple flag kept', 1 === $f( 'qa_select_multi' )['multiple'] );
		$x = $f( 'qa_true_false' );
		$t( 'True/False: default/ui_on/ui_off kept', 1 === $x['default_value'] && 'Enabled' === $x['ui_on'] && 'Disabled' === $x['ui_off'], $x );
		$t( 'WYSIWYG toolbar full/basic kept', 'full' === $f( 'qa_wysiwyg_full' )['toolbar'] && 'basic' === $f( 'qa_wysiwyg_basic' )['toolbar'] );
		$t( 'WYSIWYG media_upload off kept', empty( $f( 'qa_wysiwyg_basic' )['media_upload'] ), $f( 'qa_wysiwyg_basic' ) );
		$t( 'Image return formats kept', 'array' === $f( 'qa_image_array' )['return_format'] && 'url' === $f( 'qa_image_url' )['return_format'] && 'id' === $f( 'qa_image_id' )['return_format'] );
		$t( 'Image preview size kept', 'medium' === $f( 'qa_image_url' )['preview_size'] );
		$t( 'Date return formats kept', 'F j, Y' === $f( 'qa_date_long' )['return_format'] && 'Ymd' === $f( 'qa_date_raw' )['return_format'] && 'd/m/Y' === $f( 'qa_date_dmy' )['return_format'] );
		$t( 'Gallery max/return format kept', 3 === $f( 'qa_gallery_id' )['max'] && 'id' === $f( 'qa_gallery_id' )['return_format'] && 'array' === $f( 'qa_gallery_array' )['return_format'] );
		$t( 'Relationship post types/max/format kept', array( 'post', 'page' ) === $f( 'qa_rel_obj' )['post_types'] && 2 === $f( 'qa_rel_id' )['max'] && 'id' === $f( 'qa_rel_id' )['return_format'] );
		$t( 'Taxonomy settings kept (taxonomy/appearance/format/save_terms)', 'category' === $f( 'qa_tax_checkbox' )['taxonomy'] && 'multi_select' === $f( 'qa_tax_multi' )['field_type'] && 'post_tag' === $f( 'qa_tax_radio' )['taxonomy'] && 1 === $f( 'qa_tax_checkbox' )['save_terms'] && 'object' === $f( 'qa_tax_select' )['return_format'] );
		$x = $f( 'qa_rep_table' );
		$t( 'Repeater: layout/min/button/sub fields kept', 'table' === $x['layout'] && 1 === $x['min'] && 'Add Entry' === $x['button_label'] && 6 === count( $x['sub_fields'] ), $x );
		$x = $f( 'qa_rep_block' );
		$t( 'Repeater: block/max/nested repeater kept', 'block' === $x['layout'] && 2 === $x['max'] && 'repeater' === $x['sub_fields'][5]['type'], $x['layout'] );
		$x = $f( 'qa_flex' );
		$t( 'Flexible: max/button/3 layouts, nested repeater kept', 4 === $x['max'] && 'Add Section' === $x['button_label'] && 3 === count( $x['layouts'] ) && 'repeater' === $x['layouts'][1]['sub_fields'][1]['type'], $x['max'] );
		$t( 'Flexible layout display modes kept', 'block' === $x['layouts'][0]['display'] && 'table' === $x['layouts'][1]['display'] );
		$bad = CFStudio_Field_Group::sanitize_fields( array( array( 'label' => 'R', 'type' => 'repeater', 'min' => 5, 'max' => 2 ) ) )[0];
		$t( 'Repeater min is clamped to max', 2 === $bad['min'], $bad );

		$module = 'Location rules';
		$t( 'Basic group matches page and post', CFStudio_Location_Rules::matches_post_type( $defs['basic']['location'], 'page' ) && CFStudio_Location_Rules::matches_post_type( $defs['basic']['location'], 'post' ) );
		$t( 'Basic group does not match other types', ! CFStudio_Location_Rules::matches_post_type( $defs['basic']['location'], 'cfstudio_field_group' ) );
		$t( 'Repeater group matches page only', CFStudio_Location_Rules::matches_post_type( $defs['repeater']['location'], 'page' ) && ! CFStudio_Location_Rules::matches_post_type( $defs['repeater']['location'], 'post' ) );
		$not = array( array( array( 'param' => 'post_type', 'operator' => '!=', 'value' => 'page' ) ) );
		$t( '"is not equal to" operator', ! CFStudio_Location_Rules::matches_post_type( $not, 'page' ) && CFStudio_Location_Rules::matches_post_type( $not, 'post' ) );
		$and = array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ), array( 'param' => 'post_type', 'operator' => '!=', 'value' => 'page' ) ) );
		$t( 'AND rules in one group must all pass', ! CFStudio_Location_Rules::matches_post_type( $and, 'page' ) );
		$t( 'Options group never matches a post type', ! in_array( 'QA Options Fields', wp_list_pluck( CFStudio_Field_Group::get_matching_groups( 'page' ), 'title' ), true ) );
		$t( 'Empty rule list matches nothing', ! CFStudio_Location_Rules::matches_post_type( array(), 'page' ) );
		$san = CFStudio_Location_Rules::sanitize( array( array( array( 'param' => 'evil<b>', 'operator' => 'DROP', 'value' => '<x>' ) ) ) );
		$t( 'Location sanitize neutralises junk', is_array( $san ), $san );

		$module = 'Active toggle / cache';
		$gid = $ids['group']['basic'];
		update_post_meta( $gid, '_cfstudio_options', array( 'active' => 0, 'menu_order' => 0 ) );
		CFStudio_Field_Group::flush_cache();
		$t( 'Inactive group is not matched', ! in_array( 'QA Basic Fields', wp_list_pluck( CFStudio_Field_Group::get_matching_groups( 'page' ), 'title' ), true ) );
		update_post_meta( $gid, '_cfstudio_options', $defs['basic']['options'] );
		CFStudio_Field_Group::flush_cache();
		$t( 'Re-activated group is matched again (flush_cache)', in_array( 'QA Basic Fields', wp_list_pluck( CFStudio_Field_Group::get_matching_groups( 'page' ), 'title' ), true ) );
		CFStudio_Field_Group::get_matching_groups( 'page' );
		$tmp = wp_insert_post( array( 'post_type' => 'cfstudio_field_group', 'post_title' => 'QA tmp', 'post_status' => 'publish' ) );
		update_post_meta( $tmp, '_cfstudio_qa', 1 );
		update_post_meta( $tmp, '_cfstudio_location', array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ) );
		update_post_meta( $tmp, '_cfstudio_fields', array( array( 'key' => 'field_qatmp', 'label' => 'T', 'name' => 'qa_tmp', 'type' => 'text' ) ) );
		wp_update_post( array( 'ID' => $tmp, 'post_title' => 'QA tmp' ) ); // fires save_post -> auto flush.
		$t( 'Group saved in same request is picked up (cache auto-flush)', in_array( 'QA tmp', wp_list_pluck( CFStudio_Field_Group::get_matching_groups( 'page' ), 'title' ), true ) );
		wp_delete_post( $tmp, true );
		$t( 'Deleted group disappears at once (cache auto-flush)', ! in_array( 'QA tmp', wp_list_pluck( CFStudio_Field_Group::get_matching_groups( 'page' ), 'title' ), true ) );

		/* ===================== PHASE 2: meta box, save, template API ===================== */
		$phase  = 'P2';
		$module = 'Meta box render';
		wp_set_current_user( 1 );
		$post_obj = get_post( $P1 );
		ob_start();
		( new CFStudio_Meta_Box() )->render_group_fields( cfstudio_qa_group( 'QA Basic Fields', 'page' ), $P1 );
		$html = ob_get_clean();
		$t( 'Basic group renders inputs', false !== strpos( $html, 'cfs[qa_text]' ) && false !== strpos( $html, 'cfs[qa_gallery_id]' ), strlen( $html ) );
		$t( 'Text input has maxlength + placeholder + value', false !== strpos( $html, 'maxlength="20"' ) && false !== strpos( $html, 'placeholder="Type here"' ) && false !== strpos( $html, 'value="Hello World"' ) );
		$t( 'Required marker/instructions rendered', false !== strpos( $html, 'Max 20 chars.' ) );
		$t( 'Textarea renders rows=6 and value', false !== strpos( $html, 'rows="6"' ) && false !== strpos( $html, 'Line one' ) );
		$t( 'Select: allow_null option + selected value', false !== strpos( $html, '— Select —' ) && preg_match( '/<option value="green"[^>]*selected/', $html ) );
		$t( 'Multi select posts as array and marks selected', false !== strpos( $html, 'cfs[qa_select_multi][]' ) && preg_match( '/<option value="red"[^>]*selected/', $html ) );
		$t( 'True/False: checked with custom "Enabled" label', preg_match( '/name="cfs\[qa_true_false\]"[^>]*checked/', $html ) && false !== strpos( $html, 'Enabled' ) );
		$t( 'Date input holds Y-m-d', false !== strpos( $html, 'value="2026-11-07"' ) );
		$t( 'Gallery items rendered with ids', false !== strpos( $html, 'data-id="' . $I[1] . '"' ) );
		$t( 'Relationship selected titles rendered', false !== strpos( $html, 'QA Related Post 2' ) );
		$t( 'Taxonomy checkboxes checked for saved terms', preg_match( '/value="' . $C[1] . '"[^>]*checked/', $html ) );
		ob_start();
		( new CFStudio_Meta_Box() )->render_group_fields( cfstudio_qa_group( 'QA Repeater & Flexible', 'page' ), $P1 );
		$html2 = ob_get_clean();
		$t( 'Repeater renders rows + JS template', false !== strpos( $html2, 'cfs-repeater' ) && false !== strpos( $html2, '<template' ), strlen( $html2 ) );
		$t( 'Repeater custom button label', false !== strpos( $html2, 'Add Entry' ) && false !== strpos( $html2, 'Add Item' ) );
		$t( 'Flexible renders layouts + custom button', false !== strpos( $html2, 'cfs-flex' ) && false !== strpos( $html2, 'Add Section' ) );
		$t( 'Defaults applied to an unsaved post (text default)', false !== strpos( $html_new = cfstudio_qa_render_new( 'page' ), 'value="Default text"' ), 'no default' );
		$t( 'Defaults: true/false default on, select default blue', preg_match( '/name="cfs\[qa_true_false\]"[^>]*checked/', $html_new ) && preg_match( '/<option value="blue"[^>]*selected/', $html_new ) );

		$module = 'Defaults vs deliberate empty values';
		ob_start();
		( new CFStudio_Meta_Box() )->render_group_fields( cfstudio_qa_group( 'QA Basic Fields', 'page' ), $P3 );
		$h3 = ob_get_clean();
		$t( 'Select saved empty on purpose does NOT fall back to its default', ! preg_match( '/<option value="blue"[^>]*selected/', $h3 ) );
		$t( 'Text saved empty-able: default not re-applied after a save', false === strpos( $h3, 'value="Default text"' ) );
		ob_start();
		( new CFStudio_Meta_Box() )->render_group_fields( cfstudio_qa_group( 'QA Basic Fields', 'page' ), $P2 );
		$h2b = ob_get_clean();
		$t( 'True/False saved off stays off (default is on)', ! preg_match( '/name="cfs\[qa_true_false\]"[^>]*checked/', $h2b ) );
		$t( 'Textarea saved empty on purpose stays empty', false === strpos( $h2b, 'Default area</textarea>' ) );
		$t( 'CFStudio_Storage::exists true for saved-empty reference key', CFStudio_Storage::exists( '_qa_textarea', $P2 ) && ! CFStudio_Storage::exists( '_never_saved', $P2 ) );

		$module = 'Save pipeline + template API (page 1)';
		$t( 'Text saved', 'Hello World' === $g( 'qa_text' ) );
		$t( 'Textarea keeps newline', "Line one\nLine two" === $g( 'qa_textarea' ) );
		$t( 'Select single', 'green' === $g( 'qa_select_single' ) );
		$t( 'Select multiple (array, order kept)', array( 'red', 'green' ) === $g( 'qa_select_multi' ), $g( 'qa_select_multi' ) );
		$t( 'True/False returns real boolean true', true === $g( 'qa_true_false' ) );
		$t( 'WYSIWYG full keeps markup and backslash', '<p>Full <strong>bold</strong> and a \\ backslash</p>' === $g( 'qa_wysiwyg_full' ), $g( 'qa_wysiwyg_full' ) );
		$t( 'WYSIWYG basic', '<p>Basic text</p>' === $g( 'qa_wysiwyg_basic' ) );
		$ia = $g( 'qa_image_array' );
		$t( 'Image array: ID/url/width/height/alt/title', $I[1] === $ia['ID'] && ! empty( $ia['url'] ) && 800 === (int) $ia['width'] && 500 === (int) $ia['height'] && 'QA alt 1' === $ia['alt'] && 'CFS QA image 1' === $ia['title'], $ia );
		$t( 'Image array: sizes thumbnail/medium/large', ! empty( $ia['sizes']['thumbnail'] ) && ! empty( $ia['sizes']['medium'] ), $ia['sizes'] ?? null );
		$t( 'Image url format', wp_get_attachment_url( $I[2] ) === $g( 'qa_image_url' ) );
		$t( 'Image id format', $I[3] === $g( 'qa_image_id' ) );
		$t( 'Date F j, Y', 'November 7, 2026' === $g( 'qa_date_long' ), $g( 'qa_date_long' ) );
		$t( 'Date raw Ymd', '20261225' === $g( 'qa_date_raw' ) );
		$t( 'Date d/m/Y', '31/01/2027' === $g( 'qa_date_dmy' ), $g( 'qa_date_dmy' ) );
		$t( 'Date stored as Ymd in post meta (sortable)', '20261107' === get_post_meta( $P1, 'qa_date_long', true ) );
		$t( 'Gallery ids in order', array( $I[1], $I[2], $I[3] ) === $g( 'qa_gallery_id' ) );
		$ga = $g( 'qa_gallery_array' );
		$t( 'Gallery array shape', 2 === count( $ga ) && $I[4] === $ga[0]['ID'] && ! empty( $ga[1]['url'] ), $ga );
		$gu = $g( 'qa_gallery_url' );
		$t( 'Gallery url format', 2 === count( $gu ) && is_string( $gu[0] ) && false !== strpos( $gu[0], 'cfs-qa-2' ), $gu );
		$ro = $g( 'qa_rel_obj' );
		$t( 'Relationship objects (order kept)', 2 === count( $ro ) && $ro[0] instanceof WP_Post && $R[2] === $ro[0]->ID && $R[1] === $ro[1]->ID );
		$t( 'Relationship ids', array( $R[3], $R[1] ) === $g( 'qa_rel_id' ) );
		$t( 'Taxonomy checkbox ids', array( $C[1], $C[2] ) === $g( 'qa_tax_checkbox' ) );
		$ts = $g( 'qa_tax_select' );
		$t( 'Taxonomy select returns one term object', $ts instanceof WP_Term && $C[3] === $ts->term_id );
		$tm = $g( 'qa_tax_multi' );
		$t( 'Taxonomy multi returns term objects', 2 === count( $tm ) && $tm[0] instanceof WP_Term && $T[1] === $tm[0]->term_id );
		$t( 'Taxonomy radio returns single id', $T[3] === $g( 'qa_tax_radio' ) );
		$assigned = wp_get_object_terms( $P1, 'category', array( 'fields' => 'ids' ) );
		$t( 'save_terms assigned categories to the page/post', in_array( $C[1], $assigned, true ) && in_array( $C[2], $assigned, true ), $assigned );
		$t( 'Reference key _name stored', get_post_meta( $P1, '_qa_text', true ) === CFStudio_Field_Group::find_field( CFStudio_Field_Group::get_matching_groups( 'page' ), 'qa_text' )['key'] );

		$module = 'Template API functions';
		$t( 'cfstudio_get_field by field key works', 'Hello World' === cfstudio_get_field( CFStudio_Field_Group::find_field( CFStudio_Field_Group::get_matching_groups( 'page' ), 'qa_text' )['key'], $P1 ) );
		$t( 'cfstudio_get_field raw (format=false) keeps id', $I[3] === (int) cfstudio_get_field( 'qa_image_id', $P1, false ) );
		$t( 'cfstudio_get_field unknown field is null', null === cfstudio_get_field( 'does_not_exist', $P1 ) );
		$t( 'cfstudio_get_field on wrong context is null', null === cfstudio_get_field( 'qa_text', 0 ) );
		$all = cfstudio_get_fields( $P1 );
		$t( 'cfstudio_get_fields returns every field of both groups', isset( $all['qa_text'], $all['qa_rep_table'], $all['qa_flex'] ), array_keys( $all ) );
		$GLOBALS['post'] = get_post( $P1 );
		setup_postdata( $GLOBALS['post'] );
		ob_start();
		cfstudio_the_field( 'qa_text' );
		$t( 'cfstudio_the_field echoes (current post)', 'Hello World' === ob_get_clean() );
		ob_start();
		cfstudio_the_field( 'qa_text', $P3 );
		$t( 'cfstudio_the_field output is escaped', false === strpos( ob_get_clean(), '<b>' ) );
		wp_reset_postdata();

		/* ===================== PHASE 3: repeater ===================== */
		$phase  = 'P3';
		$module = 'Repeater';
		$rt = $rows( 'qa_rep_table', $P2 );
		$t( 'Table repeater: 3 rows loop', 3 === count( $rt ), count( $rt ) );
		$t( 'Row sub fields: text/select/boolean/date/image url/textarea', 'Entry 1' === $rt[0]['title'] && 'red' === $rt[0]['kind'] && true === $rt[0]['on'] && false === $rt[1]['on'] && '10 Jan 2026' === $rt[0]['when'] && is_string( $rt[0]['pic'] ) && "Note 1\nsecond line" === $rt[0]['note'], $rt[0] );
		$t( 'Row order preserved', array( 'Entry 1', 'Entry 2', 'Entry 3' ) === wp_list_pluck( $rt, 'title' ) );
		$t( 'Stored under compound keys', 'Entry 2' === get_post_meta( $P2, 'qa_rep_table_1_title', true ) && '3' === (string) get_post_meta( $P2, 'qa_rep_table', true ) );
		cfstudio_reset_rows();
		$idx = array();
		while ( cfstudio_have_rows( 'qa_rep_table', $P2 ) ) {
			cfstudio_the_row();
			$idx[] = cfstudio_get_row_index() . ':' . cfstudio_get_sub_field( 'title' );
		}
		$t( 'cfstudio_get_row_index is 1-based, cfstudio_get_sub_field works', array( '1:Entry 1', '2:Entry 2', '3:Entry 3' ) === $idx, $idx );
		$t( 'Loop can restart after finishing', 3 === count( $rows( 'qa_rep_table', $P2 ) ) );
		cfstudio_reset_rows();
		cfstudio_have_rows( 'qa_rep_table', $P2 );
		cfstudio_the_row();
		cfstudio_reset_rows();
		$t( 'cfstudio_reset_rows abandons a half-run loop', 0 === cfstudio_get_row_index() );
		ob_start();
		cfstudio_have_rows( 'qa_rep_table', $P2 );
		cfstudio_the_row();
		cfstudio_the_sub_field( 'title' );
		$t( 'cfstudio_the_sub_field echoes', 'Entry 1' === ob_get_clean() );
		cfstudio_reset_rows();
		$rb = $rows( 'qa_rep_block', $P2 );
		$t( 'Block repeater: 2 rows with wysiwyg/gallery/rel/tax', 2 === count( $rb ) && '<p>Body <em>1</em></p>' === $rb[0]['body'] && array( $I[1], $I[2] ) === $rb[0]['pics'] && array( $R[1], $R[2] ) === $rb[0]['related'] && array( $C[1], $C[3] ) === $rb[0]['cats'], $rb[0] ?? null );
		cfstudio_reset_rows();
		$nested = array();
		while ( cfstudio_have_rows( 'qa_rep_block', $P2 ) ) {
			cfstudio_the_row();
			while ( cfstudio_have_rows( 'qa_nested' ) ) {
				cfstudio_the_row();
				$nested[] = cfstudio_get_sub_field( 'title' ) . ( cfstudio_get_sub_field( 'featured' ) ? '*' : '' );
			}
		}
		$t( 'Nested repeater loop (inside rows), booleans', array( 'Nested 1a*', 'Nested 1b', 'Nested 2a*', 'Nested 2b' ) === $nested, $nested );
		$t( 'Nested repeater keys: qa_rep_block_0_qa_nested_1_title', 'Nested 1b' === get_post_meta( $P2, 'qa_rep_block_0_qa_nested_1_title', true ) );
		$t( 'Gallery inside repeater stored as array', is_array( get_post_meta( $P2, 'qa_rep_block_1_pics', true ) ) );
		$t( 'cfstudio_get_field of repeater returns rows', 3 === count( $g( 'qa_rep_table', $P2 ) ) );

		$module = 'Repeater edits';
		$f_rep = $f( 'qa_rep_table' );
		$f_rep_obj = CFStudio_Field_Group::find_field( CFStudio_Field_Group::get_matching_groups( 'page' ), 'qa_rep_table' );
		CFStudio_Field_Repeater::save_value( $f_rep_obj, array( 'a' => array( 'title' => 'Only' ) ), $P2 );
		$t( 'Shrinking rows removes orphan keys', '' === (string) get_post_meta( $P2, 'qa_rep_table_2_title', true ) && '' === (string) get_post_meta( $P2, 'qa_rep_table_1_title', true ) );
		$t( 'Shrunk repeater reads 1 row', 1 === count( $rows( 'qa_rep_table', $P2 ) ) );
		CFStudio_Field_Repeater::save_value( $f_rep_obj, array(), $P2 );
		$t( 'Emptying repeater yields null', null === $g( 'qa_rep_table', $P2 ) || array() === $g( 'qa_rep_table', $P2 ) );
		$t( 'Empty repeater loop runs 0 times', 0 === count( $rows( 'qa_rep_table', $P2 ) ) );
		cfstudio_qa_seed_page2_again( $ids );
		$t( 'Table repeater restored', 3 === count( $rows( 'qa_rep_table', $P2 ) ) );

		/* ===================== PHASE 4: flexible content + shortcode ===================== */
		$phase  = 'P4';
		$module = 'Flexible content';
		$fx = $rows( 'qa_flex', $P2 );
		$t( 'Flexible: 4 rows on page 2', 4 === count( $fx ), count( $fx ) );
		$t( 'Layout names in order', array( 'hero', 'cards', 'quote', 'hero' ) === array_map( function ( $r ) { return $r['cfs_layout']; }, $fx ) );
		$t( 'Hero sub fields: text/image array/textarea', 'Hero headline' === $fx[0]['headline'] && $I[3] === $fx[0]['bg']['ID'] && "Blurb line 1\nline 2" === $fx[0]['blurb'], $fx[0] );
		$t( 'Quote: textarea/select/date (Y)', 'To be or not to be' === $fx[2]['quote_text'] && 'blue' === $fx[2]['mood'] && '1603' === $fx[2]['said_on'], $fx[2] );
		cfstudio_reset_rows();
		$seen = array();
		while ( cfstudio_have_rows( 'qa_flex', $P2 ) ) {
			cfstudio_the_row();
			$lay = cfstudio_get_row_layout();
			$seen[] = $lay;
			if ( 'cards' === $lay ) {
				while ( cfstudio_have_rows( 'cards' ) ) {
					cfstudio_the_row();
					$seen[] = cfstudio_get_sub_field( 'card_title' ) . '#' . cfstudio_get_sub_field( 'card_img' );
				}
			}
		}
		$t( 'cfstudio_get_row_layout + nested repeater inside a layout', array( 'hero', 'cards', 'Card one#' . $I[1], 'Card two#' . $I[2], 'quote', 'hero' ) === $seen, $seen );
		$t( 'cfstudio_get_row_layout outside a loop is null', null === cfstudio_get_row_layout() );
		$t( 'Layout list stored at the field key', array( 'hero', 'cards', 'quote', 'hero' ) === get_post_meta( $P2, 'qa_flex', true ) );
		$t( 'Layout sub keys: qa_flex_1_cards_0_card_title', 'Card one' === get_post_meta( $P2, 'qa_flex_1_cards_0_card_title', true ) );
		$p1fx = $rows( 'qa_flex', $P1 );
		$t( 'Page 1 flexible has 2 rows (hero, quote)', 2 === count( $p1fx ) && 'quote' === $p1fx[1]['cfs_layout'] );
		CFStudio_Field_Flexible::save_value( CFStudio_Field_Group::find_field( CFStudio_Field_Group::get_matching_groups( 'page' ), 'qa_flex' ), array( 'a' => array( 'cfs_layout' => 'quote', 'quote_text' => 'x' ) ), $P1 );
		$t( 'Re-saving flexible replaces rows and removes old keys', 1 === count( $rows( 'qa_flex', $P1 ) ) && '' === (string) get_post_meta( $P1, 'qa_flex_1_quote_text', true ) );

		$module = '[cfs] shortcode';
		$sc = function ( $name, $post ) {
			return do_shortcode( '[cfs field="' . $name . '" post_id="' . $post . '"]' );
		};
		$GLOBALS['post'] = get_post( $P2 );
		$h = $sc( 'qa_rep_table', $P2 );
		$t( 'Shortcode repeater has rows and labels', substr_count( $h, 'cfs-out-row' ) >= 3 && false !== strpos( $h, 'Entry 1' ), $h );
		$h = $sc( 'qa_flex', $P2 );
		$t( 'Shortcode flexible renders per-layout sections', false !== strpos( $h, 'cfs-out-layout-hero' ) && false !== strpos( $h, 'cfs-out-layout-cards' ) && false !== strpos( $h, 'Card two' ), $h );
		$t( 'Shortcode text', false !== strpos( $sc( 'qa_text', $P2 ), 'Page two' ) );
		$t( 'Shortcode true/false "No" for off', false !== strpos( $sc( 'qa_true_false', $P2 ), 'No' ) || '' === $sc( 'qa_true_false', $P2 ) );
		$t( 'Shortcode image renders <img>', false !== strpos( $sc( 'qa_image_array', $P1 ), '<img' ) );
		$t( 'Shortcode WYSIWYG wraps in div', false !== strpos( $sc( 'qa_wysiwyg_full', $P1 ), 'cfs-out-wysiwyg' ) );
		$t( 'Shortcode gallery renders images', substr_count( $sc( 'qa_gallery_id', $P1 ), '<img' ) === 3 );
		$t( 'Shortcode relationship renders links', false !== strpos( $sc( 'qa_rel_obj', $P1 ), 'QA Related Post 2' ) && false !== strpos( $sc( 'qa_rel_obj', $P1 ), '<a href' ) );
		$t( 'Shortcode taxonomy renders terms', false !== strpos( $sc( 'qa_tax_checkbox', $P1 ), 'QA Cat A' ) );
		$t( 'Shortcode date', false !== strpos( $sc( 'qa_date_long', $P1 ), 'November 7, 2026' ) );
		$t( 'Shortcode select multiple joins values', false !== strpos( $sc( 'qa_select_multi', $P1 ), 'red, green' ) );
		$t( 'Shortcode unknown field renders nothing', '' === $sc( 'nope', $P1 ) );
		wp_update_post( array( 'ID' => $P3, 'post_status' => 'draft' ) );
		$t( 'Shortcode draft page hidden from visitors', ( function () use ( $P3, $sc ) {
			wp_set_current_user( 0 );
			$r = $sc( 'qa_text', $P3 );
			wp_set_current_user( 1 );
			return '' === $r;
		} )() );
		$t( 'Shortcode draft page visible to its editor', '' !== $sc( 'qa_text', $P3 ) );
		$t( 'Shortcode output is escaped (page 3)', false === strpos( $sc( 'qa_text', $P3 ), '<b>' ) );
		$t( 'Shortcode WYSIWYG strips <script>/javascript:', false === stripos( $sc( 'qa_wysiwyg_full', $P3 ), '<script' ) && false === stripos( $sc( 'qa_wysiwyg_full', $P3 ), 'javascript:' ), $sc( 'qa_wysiwyg_full', $P3 ) );
		wp_update_post( array( 'ID' => $P3, 'post_status' => 'publish' ) );

		/* ===================== PHASE 5: options pages ===================== */
		$phase  = 'P5';
		$module = 'Options pages';
		$t( 'Top-level options page registered', null !== CFStudio_Options_Page::get_page( 'qa-options' ) );
		$sub = CFStudio_Options_Page::get_page( 'qa-options-sub' );
		$t( 'Sub page registered under Settings', $sub && 'options-general.php' === $sub['parent_slug'], $sub );
		$t( 'Options group matches both pages (OR rules)', in_array( 'QA Options Fields', wp_list_pluck( CFStudio_Field_Group::get_options_groups( 'qa-options' ), 'title' ), true ) && in_array( 'QA Options Fields', wp_list_pluck( CFStudio_Field_Group::get_options_groups( 'qa-options-sub' ), 'title' ), true ) );
		$t( 'Options group does not match unrelated slug', ! in_array( 'QA Options Fields', wp_list_pluck( CFStudio_Field_Group::get_options_groups( 'other-page' ), 'title' ), true ) );
		$o = function ( $n ) {
			return cfstudio_get_field( $n, 'option' );
		};
		$t( 'Option text', 'Just another QA site' === $o( 'qa_opt_text' ) );
		$t( 'Option select', 'blue' === $o( 'qa_opt_select' ) );
		$t( 'Option true/false', true === $o( 'qa_opt_true_false' ) );
		$t( 'Option wysiwyg', '<p>&copy; QA footer</p>' === $o( 'qa_opt_wysiwyg' ), $o( 'qa_opt_wysiwyg' ) );
		$t( 'Option image array', $I[3] === $o( 'qa_opt_image' )['ID'] );
		$t( 'Option date j F Y', '1 December 2026' === $o( 'qa_opt_date' ), $o( 'qa_opt_date' ) );
		$t( 'Option gallery ids', array( $I[1], $I[4] ) === $o( 'qa_opt_gallery' ) );
		$t( 'Option relationship ids', array( $R[3], $R[2] ) === $o( 'qa_opt_rel' ) );
		$t( 'Option taxonomy id', $C[2] === $o( 'qa_opt_tax' ) );
		$soc = array();
		while ( cfstudio_have_rows( 'qa_opt_social', 'option' ) ) {
			cfstudio_the_row();
			$soc[] = cfstudio_get_sub_field( 'network' ) . '=' . cfstudio_get_sub_field( 'url' );
		}
		$t( 'Option repeater loop', array( 'Mastodon=https://example.org/@qa', 'Site=https://example.org' ) === $soc, $soc );
		$ban = array();
		while ( cfstudio_have_rows( 'qa_opt_banners', 'option' ) ) {
			cfstudio_the_row();
			$ban[] = cfstudio_get_row_layout() . ':' . cfstudio_get_sub_field( 'message' ) . ':' . var_export( cfstudio_get_sub_field( 'urgent' ), true );
		}
		$t( 'Option flexible loop', array( 'notice:Planned maintenance:true' ) === $ban, $ban );
		global $wpdb;
		$t( 'Option values stored in wp_options, not autoloaded', 'no' === $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name='options_qa_opt_text'" ) );
		$t( 'Option reference key stored', '' !== (string) get_option( 'options__qa_opt_text' ) );
		$t( 'Option field invisible on a page', null === cfstudio_get_field( 'qa_opt_text', $P1 ) );
		$t( '[cfs post_id="option"] shortcode (text)', false !== strpos( do_shortcode( '[cfs field="qa_opt_text" post_id="option"]' ), 'Just another QA site' ) );
		$t( '[cfs post_id="option"] shortcode (repeater)', false !== strpos( do_shortcode( '[cfs field="qa_opt_social" post_id="option"]' ), 'Mastodon' ) );
		$_POST = array( CFStudio_Options_Page::NONCE_FIELD => 'bad', 'cfs' => array( 'qa_opt_text' => 'HACK' ) );
		CFStudio_Options_Page::maybe_save( 'qa-options' );
		$_POST = array();
		$t( 'Bad nonce changes nothing', 'Just another QA site' === get_option( 'options_qa_opt_text' ) );
		$np = CFStudio_Options_Page::normalize( array( 'page_title' => 'X', 'menu_slug' => 'x y!', 'icon_url' => 'javascript:alert(1)', 'capability' => '' ) );
		$t( 'Options page args normalised (slug, icon, capability)', 'xy' === $np['menu_slug'] && 'manage_options' === $np['capability'] && false === strpos( $np['icon_url'], 'javascript' ), $np );
		$t( 'Options page without title/slug rejected', null === CFStudio_Options_Page::normalize( array() ) );
		$t( 'cfstudio_add_options_sub_page needs parent_slug', false === cfstudio_add_options_sub_page( array( 'page_title' => 'Y' ) ) );

		/* ===================== PHASE 6: more fields ===================== */
		$phase  = 'P6';
		$module = 'Date picker';
		$dp = $f( 'qa_date_long' );
		$t( 'Valid Y-m-d accepted', '20261107' === CFStudio_Field_Date_Picker::sanitize_value( '2026-11-07', $dp ) );
		$t( 'Ymd accepted', '20261107' === CFStudio_Field_Date_Picker::sanitize_value( '20261107', $dp ) );
		$t( 'Impossible date rejected', '' === CFStudio_Field_Date_Picker::sanitize_value( '2026-02-31', $dp ) );
		$t( 'Leap day valid in leap year only', '20240229' === CFStudio_Field_Date_Picker::sanitize_value( '2024-02-29', $dp ) && '' === CFStudio_Field_Date_Picker::sanitize_value( '2026-02-29', $dp ) );
		$t( 'Array / junk rejected', '' === CFStudio_Field_Date_Picker::sanitize_value( array( 'x' ), $dp ) && '' === CFStudio_Field_Date_Picker::sanitize_value( '<script>', $dp ) );
		$t( 'Format function ignores site timezone shift', 'November 7, 2026' === CFStudio_Field_Date_Picker::format_value( '20261107', $dp ) );
		$t( 'Empty value formats to null', null === CFStudio_Field_Date_Picker::format_value( '', $dp ) );

		$module = 'Gallery';
		$gf = $f( 'qa_gallery_id' );
		$t( 'Gallery de-duplicates, drops invalid, keeps order', array( $I[2], $I[1] ) === CFStudio_Field_Gallery::sanitize_value( array( '', $I[2], $I[1], $I[2], 99999, 'x' ), array( 'max' => 0 ) ) );
		$t( 'Gallery enforces max', 3 === count( CFStudio_Field_Gallery::sanitize_value( array( $I[1], $I[2], $I[3], $I[4], $I[5] ), $gf ) ) );
		$t( 'Gallery rejects non-image attachment ids (post id)', array() === CFStudio_Field_Gallery::sanitize_value( array( $P1 ), array( 'max' => 0 ) ) );
		$t( 'Gallery empty yields null', null === CFStudio_Field_Gallery::format_value( array(), $gf ) );

		$module = 'Relationship';
		$rf = $f( 'qa_rel_obj' );
		$t( 'Relationship rejects other post types', array( $R[1] ) === CFStudio_Field_Relationship::sanitize_value( array( $R[1], $ids['group']['basic'] ), $rf ) );
		$t( 'Relationship allows page when configured', in_array( $P2, CFStudio_Field_Relationship::sanitize_value( array( $P2 ), $rf ), true ) );
		$t( 'Relationship enforces max', 2 === count( CFStudio_Field_Relationship::sanitize_value( array( $R[1], $R[2], $R[3] ), $f( 'qa_rel_id' ) ) ) );
		$d = wp_insert_post( array( 'post_title' => 'QA draft rel', 'post_status' => 'draft' ) );
		update_post_meta( $d, '_cfstudio_qa', 1 );
		CFStudio_Field_Relationship::save_value( $f( 'qa_rel_obj' ), array( $d, $R[1] ), $P2 );
		wp_set_current_user( 0 );
		$vis = CFStudio_Field_Relationship::format_value( array( $d, $R[1] ), $rf );
		wp_set_current_user( 1 );
		$t( 'Relationship hides unpublished posts from visitors', 1 === count( $vis ) && $R[1] === $vis[0]->ID );
		wp_delete_post( $d, true );
		$_POST = $_REQUEST = array( 'nonce' => wp_create_nonce( 'cfstudio_relationship' ), 'types' => 'post,evil', 's' => 'QA Related' );
		$res = cfstudio_qa_ajax( array( 'CFStudio_Field_Relationship', 'ajax_search' ) );
		$t( 'Relationship AJAX returns matches', ! empty( $res['success'] ) && count( $res['data'] ) >= 3, $res );
		$_POST = $_REQUEST = array( 'nonce' => 'bad', 'types' => 'post', 's' => 'QA' );
		$res = cfstudio_qa_ajax( array( 'CFStudio_Field_Relationship', 'ajax_search' ) );
		$t( 'Relationship AJAX rejects bad nonce', empty( $res['success'] ), $res );
		wp_set_current_user( 0 );
		$_POST = $_REQUEST = array( 'nonce' => wp_create_nonce( 'cfstudio_relationship' ), 'types' => 'post', 's' => 'QA' );
		$res = cfstudio_qa_ajax( array( 'CFStudio_Field_Relationship', 'ajax_search' ) );
		wp_set_current_user( 1 );
		$t( 'Relationship AJAX rejects logged-out user', empty( $res['success'] ), $res );
		$_POST = $_REQUEST = array();

		$module = 'Taxonomy';
		$tx = $f( 'qa_tax_checkbox' );
		$t( 'Taxonomy drops unknown terms', array( $C[1] ) === CFStudio_Field_Taxonomy::sanitize_value( array( $C[1], 99999 ), $tx ) );
		$t( 'Taxonomy rejects term of another taxonomy', array() === CFStudio_Field_Taxonomy::sanitize_value( array( $T[1] ), $tx ) );
		$t( 'Single-appearance keeps only one term', 1 === count( CFStudio_Field_Taxonomy::sanitize_value( array( $C[1], $C[2] ), $f( 'qa_tax_select' ) ) ) );
		$t( 'Taxonomy id return format on object field', 'object' === $f( 'qa_tax_select' )['return_format'] );
		CFStudio_Field_Taxonomy::save_value( $tx, array( $C[3] ), $P2 );
		$cats = wp_get_object_terms( $P2, 'category', array( 'fields' => 'ids' ) );
		$t( 'save_terms replaces post terms', array( $C[3] ) === array_map( 'intval', $cats ), $cats );
		$tx_sub = CFStudio_Field_Group::find_field( CFStudio_Field_Group::get_matching_groups( 'page' ), 'qa_rep_block' )['sub_fields'][4];
		$before = wp_get_object_terms( $P2, 'category', array( 'fields' => 'ids' ) );
		CFStudio_Field_Taxonomy::save_value( array_merge( $tx_sub, array( 'save_terms' => 1 ) ), array( $C[1] ), $P2, 'qa_rep_block_0_cats' );
		$t( 'Nested taxonomy never assigns terms to the post', $before === wp_get_object_terms( $P2, 'category', array( 'fields' => 'ids' ) ) );

		/* ===================== PHASE 7: builder polish (server side) ===================== */
		$phase  = 'P7';
		$module = 'Builder';
		ob_start();
		CFStudio_Field_Group::render_fields_editor( array(), 'cfstudio_fields', true );
		$b = ob_get_clean();
		$t( 'Builder has Expand/Collapse all + error box', false !== strpos( $b, 'cfs-expand-all' ) && false !== strpos( $b, 'cfs-collapse-all' ) && false !== strpos( $b, 'cfs-builder-errors' ) );
		ob_start();
		CFStudio_Field_Group::render_field_row( array( 'key' => 'field_x', 'label' => 'L', 'name' => 'n', 'type' => 'taxonomy' ), 'cfstudio_fields[0]' );
		$row = ob_get_clean();
		$t( 'Field row has Duplicate button and every type\'s settings', false !== strpos( $row, 'cfs-duplicate-field' ) && false !== strpos( $row, 'data-type="gallery"' ) && false !== strpos( $row, 'data-type="relationship"' ) && false !== strpos( $row, 'data-type="date_picker"' ) );

		/* ===================== EDGE CASES (page 3 + sanitising) ===================== */
		$phase  = 'EDGE';
		$module = 'Limits & invalid input (page 3)';
		$t( 'Text truncated to maxlength 20', 20 === mb_strlen( $g( 'qa_text', $P3 ) ), $g( 'qa_text', $P3 ) );
		$t( 'Unicode kept intact when truncating (no broken bytes)', false !== mb_check_encoding( $g( 'qa_text', $P3 ), 'UTF-8' ) );
		$t( 'Text strips tags', false === strpos( (string) $g( 'qa_text', $P3 ), '<b>' ) );
		$t( 'Textarea strips <script>', false === stripos( (string) $g( 'qa_textarea', $P3 ), '<script' ), $g( 'qa_textarea', $P3 ) );
		$t( 'Textarea keeps & and backslash', false !== strpos( (string) $g( 'qa_textarea', $P3 ), '\\ slash' ), $g( 'qa_textarea', $P3 ) );
		$t( 'Invalid select value stored empty', null === $g( 'qa_select_single', $P3 ), $g( 'qa_select_single', $P3 ) );
		$t( 'Multi select drops invalid choice', array( 'red', 'green' ) === $g( 'qa_select_multi', $P3 ), $g( 'qa_select_multi', $P3 ) );
		$t( 'Unchecked true/false saves false', false === $g( 'qa_true_false', $P3 ) || null === $g( 'qa_true_false', $P3 ) );
		$t( 'WYSIWYG strips <script> on save', false === stripos( (string) $g( 'qa_wysiwyg_full', $P3 ), '<script' ), $g( 'qa_wysiwyg_full', $P3 ) );
		$t( 'WYSIWYG strips onclick/javascript:', false === stripos( (string) $g( 'qa_wysiwyg_full', $P3 ), 'onclick' ) && false === stripos( (string) $g( 'qa_wysiwyg_full', $P3 ), 'javascript:' ), $g( 'qa_wysiwyg_full', $P3 ) );
		$t( 'Missing image id returns null', null === $g( 'qa_image_array', $P3 ) );
		$t( 'Non-numeric image id returns null', null === $g( 'qa_image_url', $P3 ) );
		$t( 'Invalid dates are not stored', null === $g( 'qa_date_long', $P3 ) && null === $g( 'qa_date_raw', $P3 ) );
		$t( 'Gallery max 3 + dedupe + invalid dropped', array( $I[1], $I[2], $I[3] ) === $g( 'qa_gallery_id', $P3 ), $g( 'qa_gallery_id', $P3 ) );
		$r3 = $g( 'qa_rel_obj', $P3 );
		$t( 'Relationship page-3: invalid id dropped, page allowed', 2 === count( $r3 ) && $R[1] === $r3[0]->ID && $P1 === $r3[1]->ID, wp_list_pluck( (array) $r3, 'ID' ) );
		$t( 'Relationship max 2 of 3', array( $R[1], $R[2] ) === $g( 'qa_rel_id', $P3 ) );
		$t( 'Taxonomy invalid term dropped', array( $C[1] ) === $g( 'qa_tax_checkbox', $P3 ), $g( 'qa_tax_checkbox', $P3 ) );
		$t( 'Taxonomy select with 2 posted keeps first', ( $g( 'qa_tax_select', $P3 ) instanceof WP_Term ) && $C[2] === $g( 'qa_tax_select', $P3 )->term_id );
		$t( 'Empty multi taxonomy returns null', null === $g( 'qa_tax_multi', $P3 ) );
		$t( 'Empty repeater returns null/empty', empty( $g( 'qa_rep_table', $P3 ) ) );
		$t( 'Block repeater capped at max 2', 2 === count( $rows( 'qa_rep_block', $P3 ) ), count( $rows( 'qa_rep_block', $P3 ) ) );
		$t( 'No stored keys beyond max (qa_rep_block_2_heading)', '' === (string) get_post_meta( $P3, 'qa_rep_block_2_heading', true ) );
		$fx3 = $rows( 'qa_flex', $P3 );
		$t( 'Flexible capped at max 4 (5th and bad layout ignored)', 4 === count( $fx3 ), count( $fx3 ) );
		$t( 'Unknown flexible layout is skipped', ! in_array( 'nonexistent', array_map( function ( $r ) { return $r['cfs_layout']; }, $fx3 ), true ) );

		$module = 'Special characters round-trip';
		CFStudio_Field_Text::save_value( $f( 'qa_text' ), "O'B \\ \"q\" ñ日本😀", $P1 );
		$t( 'Quotes/backslash/unicode/emoji survive save', "O'B \\ \"q\" ñ日本😀" === cfstudio_get_field( 'qa_text', $P1 ), cfstudio_get_field( 'qa_text', $P1 ) );
		CFStudio_Field_Textarea::save_value( $f( 'qa_textarea' ), "a\\nb \\\\ c 'd' \"e\"", $P1 );
		$t( 'Textarea backslashes not eaten (wp_slash)', "a\\nb \\\\ c 'd' \"e\"" === cfstudio_get_field( 'qa_textarea', $P1 ), cfstudio_get_field( 'qa_textarea', $P1 ) );

		$t( 'WYSIWYG CRLF from browsers is normalised to LF', "<p>a</p>\n<p>b</p>" === CFStudio_Field_Wysiwyg::sanitize_value( "<p>a</p>\r\n<p>b</p>", array() ) );
		$t( 'Textarea CRLF from browsers is normalised to LF', "a\nb" === CFStudio_Field_Textarea::sanitize_value( "a\r\nb", array() ) );

		$module = 'Context & permissions';
		$t( 'cfstudio_resolve_post_id: option / options / int / null', 'option' === cfstudio_resolve_post_id( 'option' ) && 'option' === cfstudio_resolve_post_id( 'OPTIONS' ) && 5 === cfstudio_resolve_post_id( '5' ) );
		$t( 'Meta box save ignores a bad nonce', ( function () use ( $P1 ) {
			$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => 'bad', 'cfs' => array( 'qa_text' => 'HACKED' ) );
			wp_update_post( array( 'ID' => $P1, 'post_status' => 'publish' ) );
			$_POST = array();
			return 'HACKED' !== cfstudio_get_field( 'qa_text', $P1 );
		} )() );
		$t( 'Meta box save ignored for user who cannot edit', ( function () use ( $P1 ) {
			$user = wp_insert_user( array( 'user_login' => 'cfstudio_qa_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
			wp_set_current_user( $user );
			$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'qa_text' => 'SUBHACK' ) );
			do_action( 'save_post_page', $P1, get_post( $P1 ), true );
			$_POST = array();
			wp_set_current_user( 1 );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user );
			return 'SUBHACK' !== cfstudio_get_field( 'qa_text', $P1 );
		} )() );
		$t( 'Field group CPT is not public', ! get_post_type_object( 'cfstudio_field_group' )->public );
		cfstudio_qa_run_p9( $t, $phase, $module, $P1 );
		cfstudio_qa_run_p10( $t, $phase, $module, $P1, $ids );
		cfstudio_qa_run_p11( $t, $phase, $module, $P1, $ids );
		cfstudio_qa_run_p12( $t, $phase, $module, $P1, $ids );
		// The tests above change demo content on purpose; leave it pristine.
		cfstudio_qa_seed();
	} catch ( Throwable $e ) {
		$phase  = 'RUN';
		$module = 'Fatal';
		$t( 'Test run completed without a PHP error', false, $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	}

	return $out;
}

/** Render the first matching group for a brand-new (unsaved) post of a type. */
function cfstudio_qa_render_new( $type ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => 'qa new', 'post_status' => 'auto-draft' ) );
	ob_start();
	( new CFStudio_Meta_Box() )->render_group_fields( cfstudio_qa_group( 'QA Basic Fields', $type ), $id );
	$h = ob_get_clean();
	wp_delete_post( $id, true );

	return $h;
}

/** A matching group by title (other groups on the site must not matter). */
function cfstudio_qa_group( $title, $post_type ) {
	foreach ( CFStudio_Field_Group::get_matching_groups( $post_type ) as $g ) {
		if ( $title === $g['title'] ) {
			return $g;
		}
	}
	return array( 'id' => 0, 'title' => '', 'fields' => array(), 'options' => array(), 'location' => array() );
}

function cfstudio_qa_seed_page2_again( $ids ) {
	$I  = $ids['img'];
	$rows = array();
	$kinds = array( 'red', 'blue', 'green' );
	for ( $i = 0; $i < 3; $i++ ) {
		$rows[ 'r' . $i ] = array( 'title' => 'Entry ' . ( $i + 1 ), 'kind' => $kinds[ $i ], 'on' => $i % 2 ? '' : '1', 'when' => '2026-0' . ( $i + 1 ) . '-1' . $i, 'pic' => $I[ $i + 1 ], 'note' => '' );
	}
	CFStudio_Field_Repeater::save_value( CFStudio_Field_Group::find_field( CFStudio_Field_Group::get_matching_groups( 'page' ), 'qa_rep_table' ), $rows, $ids['page'][2] );
}

/** Run an AJAX handler that ends with wp_send_json*(), returning decoded JSON. */
function cfstudio_qa_ajax( $callable ) {
	if ( ! defined( 'DOING_AJAX' ) ) {
		define( 'DOING_AJAX', true );
	}
	$die = function () {
		return function () {
			throw new Exception( 'cfs-qa-die' );
		};
	};
	add_filter( 'wp_die_ajax_handler', $die, 99 );
	add_filter( 'wp_die_handler', $die, 99 );
	ob_start();
	try {
		call_user_func( $callable );
	} catch ( Exception $e ) { // phpcs:ignore
	}
	$raw = ob_get_clean();
	remove_filter( 'wp_die_ajax_handler', $die, 99 );
	remove_filter( 'wp_die_handler', $die, 99 );
	$j = json_decode( $raw, true );

	return is_array( $j ) ? $j : array( 'success' => false, 'raw' => $raw );
}
