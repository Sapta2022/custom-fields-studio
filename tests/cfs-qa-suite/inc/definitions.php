<?php
/**
 * Field group definitions used by the QA suite: three groups that between
 * them use every field type with non-default options.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cfstudio_qa_f( $type, $name, $label, $extra = array() ) {
	return array_merge(
		array(
			'label' => $label,
			'name'  => $name,
			'type'  => $type,
		),
		$extra
	);
}

/** Sub-field set shared by several repeaters. */
function cfstudio_qa_simple_subs() {
	return array(
		cfstudio_qa_f( 'text', 'title', 'Title', array( 'maxlength' => 30 ) ),
		cfstudio_qa_f( 'true_false', 'featured', 'Featured', array( 'ui_on' => 'Featured', 'ui_off' => 'Normal' ) ),
	);
}

/**
 * @return array[] id-less definitions: title, location (rule groups), options, fields.
 */
function cfstudio_qa_group_definitions() {
	$choices = "red : Red\nblue : Blue\ngreen";

	$g1 = array(
		cfstudio_qa_f( 'text', 'qa_text', 'Text (maxlength 20, default, placeholder)', array( 'default_value' => 'Default text', 'placeholder' => 'Type here', 'maxlength' => 20, 'instructions' => 'Max 20 chars.', 'required' => 1 ) ),
		cfstudio_qa_f( 'textarea', 'qa_textarea', 'Textarea (rows 6)', array( 'default_value' => 'Default area', 'placeholder' => 'Write', 'rows' => 6 ) ),
		cfstudio_qa_f( 'select', 'qa_select_single', 'Select single (allow null, default blue)', array( 'choices' => $choices, 'allow_null' => 1, 'default_value' => 'blue' ) ),
		cfstudio_qa_f( 'select', 'qa_select_multi', 'Select multiple', array( 'choices' => $choices, 'multiple' => 1 ) ),
		cfstudio_qa_f( 'true_false', 'qa_true_false', 'True/False (default on, custom labels)', array( 'default_value' => 1, 'ui_on' => 'Enabled', 'ui_off' => 'Disabled' ) ),
		cfstudio_qa_f( 'wysiwyg', 'qa_wysiwyg_full', 'WYSIWYG full', array( 'toolbar' => 'full', 'media_upload' => 1 ) ),
		cfstudio_qa_f( 'wysiwyg', 'qa_wysiwyg_basic', 'WYSIWYG basic, no media', array( 'toolbar' => 'basic', 'media_upload' => 0 ) ),
		cfstudio_qa_f( 'image', 'qa_image_array', 'Image (array)', array( 'return_format' => 'array', 'preview_size' => 'thumbnail' ) ),
		cfstudio_qa_f( 'image', 'qa_image_url', 'Image (url)', array( 'return_format' => 'url', 'preview_size' => 'medium' ) ),
		cfstudio_qa_f( 'image', 'qa_image_id', 'Image (id)', array( 'return_format' => 'id', 'preview_size' => 'thumbnail' ) ),
		cfstudio_qa_f( 'date_picker', 'qa_date_long', 'Date (F j, Y)', array( 'return_format' => 'F j, Y' ) ),
		cfstudio_qa_f( 'date_picker', 'qa_date_raw', 'Date (Ymd)', array( 'return_format' => 'Ymd' ) ),
		cfstudio_qa_f( 'date_picker', 'qa_date_dmy', 'Date (d/m/Y)', array( 'return_format' => 'd/m/Y' ) ),
		cfstudio_qa_f( 'gallery', 'qa_gallery_id', 'Gallery (ids, max 3)', array( 'return_format' => 'id', 'max' => 3, 'preview_size' => 'thumbnail' ) ),
		cfstudio_qa_f( 'gallery', 'qa_gallery_array', 'Gallery (arrays)', array( 'return_format' => 'array', 'preview_size' => 'medium' ) ),
		cfstudio_qa_f( 'gallery', 'qa_gallery_url', 'Gallery (urls)', array( 'return_format' => 'url' ) ),
		cfstudio_qa_f( 'relationship', 'qa_rel_obj', 'Relationship (post+page, objects)', array( 'post_types' => array( 'post', 'page' ), 'return_format' => 'object' ) ),
		cfstudio_qa_f( 'relationship', 'qa_rel_id', 'Relationship (posts, ids, max 2)', array( 'post_types' => array( 'post' ), 'return_format' => 'id', 'max' => 2 ) ),
		cfstudio_qa_f( 'taxonomy', 'qa_tax_checkbox', 'Taxonomy checkbox (category, ids, assign)', array( 'taxonomy' => 'category', 'field_type' => 'checkbox', 'return_format' => 'id', 'save_terms' => 1 ) ),
		cfstudio_qa_f( 'taxonomy', 'qa_tax_select', 'Taxonomy select (category, object)', array( 'taxonomy' => 'category', 'field_type' => 'select', 'return_format' => 'object' ) ),
		cfstudio_qa_f( 'taxonomy', 'qa_tax_multi', 'Taxonomy multi select (tags, objects)', array( 'taxonomy' => 'post_tag', 'field_type' => 'multi_select', 'return_format' => 'object' ) ),
		cfstudio_qa_f( 'taxonomy', 'qa_tax_radio', 'Taxonomy radio (tags, ids)', array( 'taxonomy' => 'post_tag', 'field_type' => 'radio', 'return_format' => 'id' ) ),
	);

	$g2 = array(
		cfstudio_qa_f(
			'repeater',
			'qa_rep_table',
			'Repeater (table layout, min 1)',
			array(
				'layout'       => 'table',
				'min'          => 1,
				'button_label' => 'Add Entry',
				'sub_fields'   => array(
					cfstudio_qa_f( 'text', 'title', 'Title' ),
					cfstudio_qa_f( 'select', 'kind', 'Kind', array( 'choices' => $choices, 'allow_null' => 1 ) ),
					cfstudio_qa_f( 'true_false', 'on', 'On', array( 'default_value' => 1 ) ),
					cfstudio_qa_f( 'date_picker', 'when', 'When', array( 'return_format' => 'd M Y' ) ),
					cfstudio_qa_f( 'image', 'pic', 'Pic', array( 'return_format' => 'url' ) ),
					cfstudio_qa_f( 'textarea', 'note', 'Note', array( 'rows' => 2 ) ),
				),
			)
		),
		cfstudio_qa_f(
			'repeater',
			'qa_rep_block',
			'Repeater (block layout, max 2, custom button)',
			array(
				'layout'       => 'block',
				'max'          => 2,
				'button_label' => 'Add Item',
				'sub_fields'   => array(
					cfstudio_qa_f( 'text', 'heading', 'Heading' ),
					cfstudio_qa_f( 'wysiwyg', 'body', 'Body', array( 'toolbar' => 'basic' ) ),
					cfstudio_qa_f( 'gallery', 'pics', 'Pics', array( 'return_format' => 'id' ) ),
					cfstudio_qa_f( 'relationship', 'related', 'Related', array( 'post_types' => array( 'post' ), 'return_format' => 'id' ) ),
					cfstudio_qa_f( 'taxonomy', 'cats', 'Cats', array( 'taxonomy' => 'category', 'field_type' => 'checkbox', 'return_format' => 'id' ) ),
					cfstudio_qa_f( 'repeater', 'qa_nested', 'Nested repeater', array( 'layout' => 'table', 'sub_fields' => cfstudio_qa_simple_subs() ) ),
				),
			)
		),
		cfstudio_qa_f(
			'flexible_content',
			'qa_flex',
			'Flexible Content (max 4)',
			array(
				'max'          => 4,
				'button_label' => 'Add Section',
				'layouts'      => array(
					array(
						'name'       => 'hero',
						'label'      => 'Hero',
						'display'    => 'block',
						'sub_fields' => array(
							cfstudio_qa_f( 'text', 'headline', 'Headline' ),
							cfstudio_qa_f( 'image', 'bg', 'Background', array( 'return_format' => 'array' ) ),
							cfstudio_qa_f( 'textarea', 'blurb', 'Blurb' ),
						),
					),
					array(
						'name'       => 'cards',
						'label'      => 'Cards',
						'display'    => 'table',
						'sub_fields' => array(
							cfstudio_qa_f( 'text', 'section_title', 'Section title' ),
							cfstudio_qa_f(
								'repeater',
								'cards',
								'Cards',
								array(
									'layout'     => 'table',
									'sub_fields' => array(
										cfstudio_qa_f( 'text', 'card_title', 'Card title' ),
										cfstudio_qa_f( 'image', 'card_img', 'Card image', array( 'return_format' => 'id' ) ),
									),
								)
							),
						),
					),
					array(
						'name'       => 'quote',
						'label'      => 'Quote',
						'display'    => 'block',
						'sub_fields' => array(
							cfstudio_qa_f( 'textarea', 'quote_text', 'Quote' ),
							cfstudio_qa_f( 'select', 'mood', 'Mood', array( 'choices' => $choices ) ),
							cfstudio_qa_f( 'date_picker', 'said_on', 'Said on', array( 'return_format' => 'Y' ) ),
						),
					),
				),
			)
		),
	);

	$g3 = array(
		cfstudio_qa_f( 'text', 'qa_opt_text', 'Site tagline', array( 'maxlength' => 40 ) ),
		cfstudio_qa_f( 'select', 'qa_opt_select', 'Theme colour', array( 'choices' => $choices, 'allow_null' => 1 ) ),
		cfstudio_qa_f( 'true_false', 'qa_opt_true_false', 'Maintenance mode', array( 'ui_on' => 'On', 'ui_off' => 'Off' ) ),
		cfstudio_qa_f( 'wysiwyg', 'qa_opt_wysiwyg', 'Footer text', array( 'toolbar' => 'basic' ) ),
		cfstudio_qa_f( 'image', 'qa_opt_image', 'Logo', array( 'return_format' => 'array' ) ),
		cfstudio_qa_f( 'date_picker', 'qa_opt_date', 'Launch date', array( 'return_format' => 'j F Y' ) ),
		cfstudio_qa_f( 'gallery', 'qa_opt_gallery', 'Partner logos', array( 'return_format' => 'id' ) ),
		cfstudio_qa_f( 'relationship', 'qa_opt_rel', 'Featured posts', array( 'post_types' => array( 'post' ), 'return_format' => 'id' ) ),
		cfstudio_qa_f( 'taxonomy', 'qa_opt_tax', 'Main category', array( 'taxonomy' => 'category', 'field_type' => 'select', 'return_format' => 'id', 'save_terms' => 1 ) ),
		cfstudio_qa_f( 'repeater', 'qa_opt_social', 'Social links', array( 'layout' => 'table', 'sub_fields' => array( cfstudio_qa_f( 'text', 'network', 'Network' ), cfstudio_qa_f( 'text', 'url', 'URL' ) ) ) ),
		cfstudio_qa_f(
			'flexible_content',
			'qa_opt_banners',
			'Banners',
			array(
				'layouts' => array(
					array(
						'name'       => 'notice',
						'label'      => 'Notice',
						'display'    => 'block',
						'sub_fields' => array( cfstudio_qa_f( 'text', 'message', 'Message' ), cfstudio_qa_f( 'true_false', 'urgent', 'Urgent' ) ),
					),
				),
			)
		),
	);

	return array(
		'basic'    => array(
			'title'    => 'QA Basic Fields',
			'location' => array(
				array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ),
				array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
			),
			'options'  => array( 'active' => 1, 'position' => 'normal', 'style' => 'default', 'menu_order' => 0 ),
			'fields'   => $g1,
		),
		'repeater' => array(
			'title'    => 'QA Repeater & Flexible',
			'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
			'options'  => array( 'active' => 1, 'position' => 'normal', 'style' => 'seamless', 'menu_order' => 1 ),
			'fields'   => $g2,
		),
		'options'  => array(
			'title'    => 'QA Options Fields',
			'location' => array(
				array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'qa-options' ) ),
				array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'qa-options-sub' ) ),
			),
			'options'  => array( 'active' => 1, 'position' => 'normal', 'style' => 'default', 'menu_order' => 0 ),
			'fields'   => $g3,
		),
	);
}
