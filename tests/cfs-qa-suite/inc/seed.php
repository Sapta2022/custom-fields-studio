<?php
/**
 * Creates (and removes) the QA fixtures: images, posts, terms, the three
 * field groups, three demo pages and the options-page values. Everything
 * is tagged with the _cfs_qa meta / option prefix so cleanup is exact.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cfs_qa_ids() {
	return (array) get_option( 'cfs_qa_ids', array() );
}

/** Colour PNG attachment. */
function cfs_qa_make_image( $n ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$colors = array( array( 220, 60, 60 ), array( 60, 160, 90 ), array( 60, 100, 220 ), array( 230, 180, 40 ), array( 140, 70, 190 ) );
	$c      = $colors[ $n % count( $colors ) ];
	if ( function_exists( 'imagecreatetruecolor' ) ) {
		$im = imagecreatetruecolor( 800, 500 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, $c[0], $c[1], $c[2] ) );
		imagestring( $im, 5, 20, 20, 'CFS QA image ' . $n, imagecolorallocate( $im, 255, 255, 255 ) );
		ob_start();
		imagepng( $im );
		$bytes = ob_get_clean();
	} else {
		$bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore
	}
	$up = wp_upload_bits( 'cfs-qa-' . $n . '.png', null, $bytes );
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'CFS QA image ' . $n,
			'post_status'    => 'inherit',
		),
		$up['file']
	);
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
	update_post_meta( $id, '_cfs_qa', 1 );
	update_post_meta( $id, '_wp_attachment_image_alt', 'QA alt ' . $n );

	return $id;
}

function cfs_qa_term( $name, $tax ) {
	$t = get_term_by( 'name', $name, $tax );
	if ( $t ) {
		return (int) $t->term_id;
	}
	$r = wp_insert_term( $name, $tax );

	return is_wp_error( $r ) ? 0 : (int) $r['term_id'];
}

function cfs_qa_cleanup() {
	global $wpdb;
	foreach ( array( 'cfs_field_group', 'page', 'post', 'attachment' ) as $type ) {
		$ids = get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_cfs_qa', 'meta_value' => 1 ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
	foreach ( (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name REGEXP '^_?options_(_)?qa_opt_'" ) as $name ) {
		delete_option( $name );
	}
	foreach ( array( 'category' => array( 'QA Cat A', 'QA Cat B', 'QA Cat C' ), 'post_tag' => array( 'qa-tag-1', 'qa-tag-2', 'qa-tag-3' ) ) as $tax => $names ) {
		foreach ( $names as $name ) {
			$t = get_term_by( 'name', $name, $tax );
			if ( $t ) {
				wp_delete_term( $t->term_id, $tax );
			}
		}
	}
	delete_option( 'cfs_qa_ids' );
}

/** Run a post save through the real meta-box save handler. */
function cfs_qa_save_post_values( $post_id, $values ) {
	$_POST = array(
		CFS_Meta_Box::NONCE_FIELD => wp_create_nonce( CFS_Meta_Box::NONCE_ACTION ),
		'cfs'                     => wp_slash( $values ),
	);
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	$_POST = array();
}

function cfs_qa_seed() {
	cfs_qa_cleanup();
	$ids = array();

	// Fixtures.
	for ( $i = 1; $i <= 5; $i++ ) {
		$ids['img'][ $i ] = cfs_qa_make_image( $i );
	}
	foreach ( array( 1, 2, 3 ) as $i ) {
		$pid = wp_insert_post( array( 'post_title' => 'QA Related Post ' . $i, 'post_status' => 'publish', 'post_content' => 'QA related body ' . $i ) );
		update_post_meta( $pid, '_cfs_qa', 1 );
		$ids['rel'][ $i ] = $pid;
	}
	$ids['cat'] = array( 1 => cfs_qa_term( 'QA Cat A', 'category' ), 2 => cfs_qa_term( 'QA Cat B', 'category' ), 3 => cfs_qa_term( 'QA Cat C', 'category' ) );
	$ids['tag'] = array( 1 => cfs_qa_term( 'qa-tag-1', 'post_tag' ), 2 => cfs_qa_term( 'qa-tag-2', 'post_tag' ), 3 => cfs_qa_term( 'qa-tag-3', 'post_tag' ) );

	// Groups.
	foreach ( cfs_qa_group_definitions() as $key => $def ) {
		$gid = wp_insert_post( array( 'post_type' => 'cfs_field_group', 'post_title' => $def['title'], 'post_status' => 'publish' ) );
		update_post_meta( $gid, '_cfs_fields', wp_slash( CFS_Field_Group::sanitize_fields( $def['fields'] ) ) );
		update_post_meta( $gid, '_cfs_location', CFS_Location_Rules::sanitize( $def['location'] ) );
		update_post_meta( $gid, '_cfs_options', $def['options'] );
		update_post_meta( $gid, '_cfs_qa', 1 );
		$ids['group'][ $key ] = $gid;
	}

	CFS_Field_Group::flush_cache();

	$I = $ids['img'];
	$R = $ids['rel'];
	$C = $ids['cat'];
	$T = $ids['tag'];

	// Pages (shortcode tour for every field of the groups on that page).
	$names = array();
	foreach ( cfs_qa_group_definitions() as $key => $def ) {
		if ( 'options' === $key ) {
			continue;
		}
		foreach ( $def['fields'] as $f ) {
			$names[ $key ][] = array( $f['name'], $f['label'] );
		}
	}
	$tour = function ( $keys, $post_id ) use ( $names ) {
		$html = '';
		foreach ( $keys as $k ) {
			foreach ( $names[ $k ] as $pair ) {
				$html .= '<h3>' . esc_html( $pair[1] ) . "</h3>\n[cfs field=\"" . $pair[0] . "\" post_id=\"" . $post_id . "\"]\n\n";
			}
		}
		return $html;
	};

	$pages = array(
		1 => 'QA Page 1 - Basic fields (full values)',
		2 => 'QA Page 2 - Repeater and Flexible (rich values)',
		3 => 'QA Page 3 - Edge cases (limits, invalid, special characters)',
	);
	foreach ( $pages as $n => $title ) {
		$pid = wp_insert_post( array( 'post_type' => 'page', 'post_title' => $title, 'post_status' => 'draft' ) );
		update_post_meta( $pid, '_cfs_qa', 1 );
		$ids['page'][ $n ] = $pid;
	}

	$table_rows = function ( $count ) use ( $I ) {
		$rows = array();
		$kinds = array( 'red', 'blue', 'green' );
		for ( $i = 0; $i < $count; $i++ ) {
			$rows[ 'r' . $i ] = array( 'title' => 'Entry ' . ( $i + 1 ), 'kind' => $kinds[ $i % 3 ], 'on' => $i % 2 ? '' : '1', 'when' => '2026-0' . ( $i + 1 ) . '-1' . $i, 'pic' => $I[ ( $i % 5 ) + 1 ], 'note' => "Note " . ( $i + 1 ) . "\nsecond line" );
		}
		return $rows;
	};
	$block_row = function ( $n ) use ( $I, $R, $C ) {
		return array( 'heading' => 'Block ' . $n, 'body' => '<p>Body <em>' . $n . '</em></p>', 'pics' => array( $I[1], $I[ 2 ] ), 'related' => array( $R[1], $R[ 2 ] ), 'cats' => array( $C[1], $C[3] ), 'qa_nested' => array( 'a' => array( 'title' => 'Nested ' . $n . 'a', 'featured' => '1' ), 'b' => array( 'title' => 'Nested ' . $n . 'b' ) ) );
	};
	$flex = array(
		'f1' => array( 'cfs_layout' => 'hero', 'headline' => 'Hero headline', 'bg' => $I[3], 'blurb' => "Blurb line 1\nline 2" ),
		'f2' => array( 'cfs_layout' => 'cards', 'section_title' => 'Cards title', 'cards' => array( 'c1' => array( 'card_title' => 'Card one', 'card_img' => $I[1] ), 'c2' => array( 'card_title' => 'Card two', 'card_img' => $I[2] ) ) ),
		'f3' => array( 'cfs_layout' => 'quote', 'quote_text' => 'To be or not to be', 'mood' => 'blue', 'said_on' => '1603-01-01' ),
		'f4' => array( 'cfs_layout' => 'hero', 'headline' => 'Second hero', 'bg' => $I[4], 'blurb' => 'x' ),
	);

	$repeater_values_full = array( 'qa_rep_table' => $table_rows( 3 ), 'qa_rep_block' => array( 'b0' => $block_row( 1 ), 'b1' => $block_row( 2 ) ), 'qa_flex' => $flex );

	// Page 1.
	cfs_qa_save_post_values(
		$ids['page'][1],
		array_merge(
			array(
				'qa_text'          => 'Hello World',
				'qa_textarea'      => "Line one\nLine two",
				'qa_select_single' => 'green',
				'qa_select_multi'  => array( 'red', 'green' ),
				'qa_true_false'    => '1',
				'qa_wysiwyg_full'  => '<p>Full <strong>bold</strong> and a \\ backslash</p>',
				'qa_wysiwyg_basic' => '<p>Basic text</p>',
				'qa_image_array'   => $I[1],
				'qa_image_url'     => $I[2],
				'qa_image_id'      => $I[3],
				'qa_date_long'     => '2026-11-07',
				'qa_date_raw'      => '2026-12-25',
				'qa_date_dmy'      => '2027-01-31',
				'qa_gallery_id'    => array( $I[1], $I[2], $I[3] ),
				'qa_gallery_array' => array( $I[4], $I[5] ),
				'qa_gallery_url'   => array( $I[2], $I[3] ),
				'qa_rel_obj'       => array( $R[2], $R[1] ),
				'qa_rel_id'        => array( $R[3], $R[1] ),
				'qa_tax_checkbox'  => array( $C[1], $C[2] ),
				'qa_tax_select'    => $C[3],
				'qa_tax_multi'     => array( $T[1], $T[2] ),
				'qa_tax_radio'     => $T[3],
			),
			array( 'qa_rep_table' => $table_rows( 2 ), 'qa_rep_block' => array( 'b0' => $block_row( 1 ) ), 'qa_flex' => array( 'f1' => $flex['f1'], 'f3' => $flex['f3'] ) )
		)
	);
	wp_update_post( array( 'ID' => $ids['page'][1], 'post_content' => $tour( array( 'basic', 'repeater' ), $ids['page'][1] ) ) );

	// Page 2: richer repeater/flexible, a few basics.
	cfs_qa_save_post_values(
		$ids['page'][2],
		array_merge(
			$repeater_values_full,
			array( 'qa_text' => 'Page two', 'qa_select_single' => 'red', 'qa_true_false' => '', 'qa_date_long' => '2030-02-28', 'qa_gallery_id' => array( $I[5] ) )
		)
	);
	wp_update_post( array( 'ID' => $ids['page'][2], 'post_content' => $tour( array( 'repeater' ), $ids['page'][2] ) ) );

	// Page 3: limits / invalid / special characters.
	$special = "Tom & Jerry's <b>x</b> \"q\" \\ ñ 日本 😀";
	cfs_qa_save_post_values(
		$ids['page'][3],
		array(
			'qa_text'          => $special . ' and a long tail past twenty chars',
			'qa_textarea'      => "<script>alert(1)</script>\nsecond & line \\ slash",
			'qa_select_single' => 'purple',
			'qa_select_multi'  => array( 'red', 'purple', 'green' ),
			'qa_wysiwyg_full'  => '<p>ok</p><script>alert(2)</script><a href="javascript:alert(3)" onclick="x()">link</a>',
			'qa_image_array'   => 999999,
			'qa_image_url'     => 'not-a-number',
			'qa_date_long'     => '2026-02-31',
			'qa_date_raw'      => 'garbage',
			'qa_gallery_id'    => array( $I[1], $I[1], $I[2], $I[3], $I[4], 424242 ),
			'qa_rel_obj'       => array( $R[1], $ids['page'][1], 777777 ),
			'qa_rel_id'        => array( $R[1], $R[2], $R[3] ),
			'qa_tax_checkbox'  => array( $C[1], 987654 ),
			'qa_tax_select'    => array( $C[2], $C[3] ),
			'qa_tax_multi'     => array(),
			'qa_rep_table'     => array(),
			'qa_rep_block'     => array( 'b0' => $block_row( 1 ), 'b1' => $block_row( 2 ), 'b2' => $block_row( 3 ) ),
			'qa_flex'          => array(
				'f1' => $flex['f1'],
				'f2' => $flex['f2'],
				'f3' => $flex['f3'],
				'f4' => $flex['f4'],
				'f5' => array( 'cfs_layout' => 'hero', 'headline' => 'Over the max (5th)' ),
				'f6' => array( 'cfs_layout' => 'nonexistent', 'headline' => 'bad layout' ),
			),
		)
	);
	wp_update_post( array( 'ID' => $ids['page'][3], 'post_content' => $tour( array( 'basic', 'repeater' ), $ids['page'][3] ) ) );

	// Options page values (saved through the field classes, 'option' context).
	$opt = array();
	foreach ( cfs_qa_group_definitions()['options']['fields'] as $f ) {
		$sf = CFS_Field_Group::sanitize_fields( array( $f ) );
		$opt[ $f['name'] ] = $sf[0];
	}
	$vals = array(
		'qa_opt_text'       => 'Just another QA site',
		'qa_opt_select'     => 'blue',
		'qa_opt_true_false' => '1',
		'qa_opt_wysiwyg'   => '<p>&copy; QA footer</p>',
		'qa_opt_image'      => $I[3],
		'qa_opt_date'       => '2026-12-01',
		'qa_opt_gallery'    => array( $I[1], $I[4] ),
		'qa_opt_rel'        => array( $R[3], $R[2] ),
		'qa_opt_tax'        => $C[2],
		'qa_opt_social'     => array( 'a' => array( 'network' => 'Mastodon', 'url' => 'https://example.org/@qa' ), 'b' => array( 'network' => 'Site', 'url' => 'https://example.org' ) ),
		'qa_opt_banners'    => array( 'x' => array( 'cfs_layout' => 'notice', 'message' => 'Planned maintenance', 'urgent' => '1' ) ),
	);
	foreach ( $vals as $name => $v ) {
		$c = CFS_Field_Registry::get_class( $opt[ $name ]['type'] );
		$c::save_value( $opt[ $name ], $v, 'option' );
	}

	update_option( 'cfs_qa_ids', $ids, false );

	return $ids;
}
