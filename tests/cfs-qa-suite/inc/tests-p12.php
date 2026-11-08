<?php
/**
 * Phase 12 checks: Google Map field, custom blocks, multilingual support.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cfs_qa_run_p12( $t, &$phase, &$module, $P1, $ids ) {
	$phase = 'P12';

	$mk = function ( $title, $fields, $loc, $opts = array() ) {
		$id = wp_insert_post( array( 'post_type' => 'cfs_field_group', 'post_status' => 'publish', 'post_title' => $title ) );
		update_post_meta( $id, '_cfs_fields', wp_slash( CFS_Field_Group::sanitize_fields( $fields ) ) );
		update_post_meta( $id, '_cfs_location', CFS_Location_Rules::sanitize( $loc ) );
		update_post_meta( $id, '_cfs_options', CFS_Field_Group::sanitize_options( array_merge( array( 'active' => 1 ), $opts ) ) );
		CFS_Field_Group::flush_cache();
		return $id;
	};
	$rule = function ( $param, $value, $op = '==' ) {
		return array( 'param' => $param, 'operator' => $op, 'value' => $value );
	};
	$made = array();
	$pg   = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'P12 page', 'post_status' => 'publish' ) );

	/* ---------------------------------------------------------------- */
	$module = 'Google Map field';
	$map    = CFS_Field_Registry::get_class( 'google_map' );
	$t( "Type 'google_map' registered and stores a value", (bool) $map && $map::stores_value() );
	$cfg = $map::sanitize( array( 'center_lat' => '22.5726', 'center_lng' => '88.3639', 'zoom' => '99', 'height' => '5' ) );
	$t( 'Settings: centre kept, zoom clamped to 21, height clamped to 100', 22.5726 === $cfg['center_lat'] && 88.3639 === $cfg['center_lng'] && 21 === $cfg['zoom'] && 100 === $cfg['height'], $cfg );
	$cfg = $map::sanitize( array( 'center_lat' => '999', 'center_lng' => '5', 'zoom' => 'x', 'height' => 'y' ) );
	$t( 'Settings: an out-of-range / half centre is dropped; junk numbers fall back', '' === $cfg['center_lat'] && '' === $cfg['center_lng'] && 14 === $cfg['zoom'] && 300 === $cfg['height'], $cfg );
	$fld = array_merge( $map::defaults(), array( 'name' => 'm', 'type' => 'google_map' ) );
	$v   = $map::sanitize_value( array( 'address' => '<b>1 Main St</b>', 'lat' => '22.57', 'lng' => '88.36', 'zoom' => '12' ), $fld );
	$t( 'Value: address stripped of tags, numbers typed', '1 Main St' === $v['address'] && 22.57 === $v['lat'] && 88.36 === $v['lng'] && 12 === $v['zoom'], $v );
	$t( 'Value: missing / invalid / out-of-range position → empty', array() === $map::sanitize_value( array( 'address' => 'x' ), $fld ) && array() === $map::sanitize_value( array( 'lat' => 'abc', 'lng' => '1' ), $fld ) && array() === $map::sanitize_value( array( 'lat' => '95', 'lng' => '1' ), $fld ) && array() === $map::sanitize_value( array( 'lat' => '1', 'lng' => '181' ), $fld ) && array() === $map::sanitize_value( 'junk', $fld ) );
	$t( 'Value: zoom clamped, boundary coordinates accepted', 21 === $map::sanitize_value( array( 'lat' => '90', 'lng' => '-180', 'zoom' => '500' ), $fld )['zoom'] && -180.0 === $map::sanitize_value( array( 'lat' => '90', 'lng' => '-180' ), $fld )['lng'] );
	$t( 'format_value: typed array, null when empty', array( 'address' => '1 Main St', 'lat' => 22.57, 'lng' => 88.36, 'zoom' => 12 ) === $map::format_value( $v, $fld ) && null === $map::format_value( array(), $fld ) && null === $map::format_value( '', $fld ) );
	ob_start();
	$map::render_input( array_merge( $fld, array( 'center_lat' => 1.5, 'center_lng' => 2.5 ) ), array(), 'cfs[m]' );
	$html = ob_get_clean();
	$t( 'Editor input: address / lat / lng / zoom fields; default centre is NOT pre-filled as a value', false !== strpos( $html, 'name="cfs[m][address]"' ) && false !== strpos( $html, 'name="cfs[m][lat]"' ) && false !== strpos( $html, 'name="cfs[m][lng]"' ) && false !== strpos( $html, 'name="cfs[m][zoom]"' ) && false !== strpos( $html, 'data-center-lat="1.5"' ) && 1 === preg_match( '/cfs-gmap-lat" name="cfs\[m\]\[lat\]" value=""/', $html ), substr( $html, 0, 300 ) );
	add_filter( 'cfs/google_maps_api_key', $kf = function () {
		return 'KEY123';
	} );
	ob_start();
	$map::render_input( $fld, array( 'lat' => 1, 'lng' => 2, 'address' => 'A "quoted" <b>' ), 'cfs[m]' );
	$html = ob_get_clean();
	remove_filter( 'cfs/google_maps_api_key', $kf );
	$t( 'API key reaches the widget (data-key); values escaped', false !== strpos( $html, 'data-key="KEY123"' ) && false === strpos( $html, '<b>' ) && false !== strpos( $html, 'value="1"' ), substr( $html, 0, 200 ) );
	$t( 'No key by default', '' === $map::api_key() );

	$mg = $mk(
		'P12 Map',
		array(
			array( 'label' => 'Venue', 'name' => 'p12_venue', 'type' => 'google_map', 'height' => 250 ),
			array( 'label' => 'Branches', 'name' => 'p12_branches', 'type' => 'repeater', 'sub_fields' => array( array( 'label' => 'Place', 'name' => 'place', 'type' => 'google_map' ) ) ),
		),
		array( array( $rule( 'post_id', (string) $pg ) ) ),
		array( 'show_in_rest' => 1 )
	);
	$made[] = $mg;
	$_POST = array( CFS_Meta_Box::NONCE_FIELD => wp_create_nonce( CFS_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'p12_venue' => array( 'address' => 'Kolkata', 'lat' => '22.5726', 'lng' => '88.3639', 'zoom' => '11' ), 'p12_branches' => array( array( 'place' => array( 'address' => 'B1', 'lat' => '1', 'lng' => '2' ) ), array( 'place' => array( 'address' => 'B2', 'lat' => '', 'lng' => '' ) ) ) ) );
	do_action( 'save_post', $pg, get_post( $pg ), true );
	$_POST = array();
	$got = cfs_get_field( 'p12_venue', $pg );
	$t( 'Saved through the meta box; read back with cfs_get_field', is_array( $got ) && 'Kolkata' === $got['address'] && 22.5726 === $got['lat'] && 88.3639 === $got['lng'] && 11 === $got['zoom'], $got );
	$rows = cfs_get_field( 'p12_branches', $pg );
	$t( 'Works inside a repeater (an empty position row stays empty)', 2 === count( $rows ) && 1.0 === $rows[0]['place']['lat'] && null === $rows[1]['place'], $rows );
	$sc = do_shortcode( '[cfs field="p12_venue" post_id="' . $pg . '"]' );
	$t( 'Shortcode renders a keyless Google embed iframe with lat,lng and zoom', false !== strpos( $sc, 'cfs-out-map' ) && false !== strpos( $sc, 'google.com/maps' ) && false !== strpos( $sc, 'output=embed' ) && false !== strpos( $sc, '22.5726,88.3639' ) && false !== strpos( $sc, 'height="250"' ) && false !== strpos( $sc, 'title="Kolkata"' ), $sc );
	$_POST = array( CFS_Meta_Box::NONCE_FIELD => wp_create_nonce( CFS_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'p12_venue' => array( 'address' => '"><script>alert(1)</script>', 'lat' => '1', 'lng' => '2' ) ) );
	do_action( 'save_post', $pg, get_post( $pg ), true );
	$_POST = array();
	$sc = do_shortcode( '[cfs field="p12_venue" post_id="' . $pg . '"]' );
	$t( 'Hostile address cannot break out of the iframe markup', false === strpos( $sc, '<script' ) && substr_count( $sc, '<iframe' ) === 1, $sc );
	$_POST = array( CFS_Meta_Box::NONCE_FIELD => wp_create_nonce( CFS_Meta_Box::NONCE_ACTION ), 'cfs' => array( 'p12_venue' => array( 'address' => '', 'lat' => '', 'lng' => '' ) ) );
	do_action( 'save_post', $pg, get_post( $pg ), true );
	$_POST = array();
	$t( 'Clearing the map empties the value and the shortcode output', null === cfs_get_field( 'p12_venue', $pg ) && '' === do_shortcode( '[cfs field="p12_venue" post_id="' . $pg . '"]' ) );
	$rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/pages/' . $pg ) );
	$rd   = $rest->get_data();
	$t( 'REST exposes the map as a plain array (round trip stable)', 200 === $rest->get_status() && array_key_exists( 'p12_venue', (array) $rd['cfs'] ) && is_array( $rd['cfs']['p12_branches'] ), $rd['cfs'] ?? null );
	ob_start();
	$map::render_settings( array_merge( $fld, array( 'zoom' => 9 ) ), 'cfs_fields[0]' );
	$h = ob_get_clean();
	$t( 'Builder settings block is for google_map only and shows saved zoom', 4 === substr_count( $h, 'data-type="google_map"' ) && false !== strpos( $h, 'value="9"' ), $h );

	/* ---------------------------------------------------------------- */
	$module = 'Blocks: storage & location';
	$old = CFS_Storage::swap_block_values( array() );
	CFS_Storage::update( 'k', 'v', 'block' );
	$t( "'block' storage context is in memory: get / exists / update / delete", 'v' === CFS_Storage::get( 'k', 'block' ) && CFS_Storage::exists( 'k', 'block' ) && '' === CFS_Storage::get( 'nope', 'block' ) && ( CFS_Storage::delete( 'k', 'block' ) || true ) && ! CFS_Storage::exists( 'k', 'block' ) );
	CFS_Storage::update( 'bs', 'C:\\temp', 'block' );
	$t( 'Block storage keeps values byte for byte (no slashing)', 'C:\\temp' === CFS_Storage::get( 'bs', 'block' ) );
	$inner = CFS_Storage::swap_block_values( $old );
	$t( 'swap_block_values hands back the previous values', array( 'bs' => 'C:\\temp' ) === $inner && array() === CFS_Storage::block_values() || CFS_Storage::block_values() === $old );
	$t( "Nothing from the 'block' context reaches post meta or options", ! metadata_exists( 'post', $pg, 'bs' ) && false === get_option( 'options_bs', false ) );
	$t( 'Location param "block" exists in the builder with the screen map', isset( CFS_Location_Rules::get_params()['block'] ) && array( 'block' ) === CFS_Location_Rules::SCREEN_PARAMS['block'] );
	$t( 'Block rules match only the block screen', CFS_Location_Rules::matches( array( array( $rule( 'block', 'cfs/x' ) ) ), 'block', array( 'block' => 'cfs/x' ) ) && ! CFS_Location_Rules::matches( array( array( $rule( 'block', 'cfs/x' ) ) ), 'block', array( 'block' => 'cfs/y' ) ) && ! CFS_Location_Rules::matches( array( array( $rule( 'block', 'cfs/x' ) ) ), 'post', array( 'post_type' => 'page' ) ) && CFS_Location_Rules::matches( array( array( $rule( 'block', 'cfs/x', '!=' ) ) ), 'block', array( 'block' => 'cfs/y' ) ) );

	$module = 'Blocks: registration & rendering';
	$theme  = get_stylesheet_directory();
	$tpl    = $theme . '/p12-testimonial.php';
	file_put_contents(
		$tpl,
		'<?php echo "<blockquote>" . esc_html( (string) cfs_get_field( "quote" ) ) . "</blockquote>"; ?>' .
		'<ul><?php while ( cfs_have_rows( "points" ) ) : cfs_the_row(); ?><li><?php echo esc_html( (string) cfs_get_sub_field( "t" ) ); ?></li><?php endwhile; ?></ul>' .
		'<?php if ( ! empty( $block["is_preview"] ) ) { echo "[preview]"; } echo "[" . esc_html( $block["name"] ) . "]"; ?>'
	);
	$name = cfs_register_block( array( 'name' => 'P12 Testimonial', 'title' => 'P12 Testimonial', 'description' => 'A quote', 'icon' => 'format-quote', 'keywords' => array( 'quote' ), 'render_template' => $tpl ) );
	$t( 'cfs_register_block returns the cfs/ name (slugified)', 'cfs/p12testimonial' === $name, $name );
	$t( 'Errors without a name', is_wp_error( cfs_register_block( array( 'title' => 'x' ) ) ) );
	$bg = $mk( 'P12 Block group', array( array( 'label' => 'Quote', 'name' => 'quote', 'type' => 'text' ), array( 'label' => 'Points', 'name' => 'points', 'type' => 'repeater', 'sub_fields' => array( array( 'label' => 'T', 'name' => 't', 'type' => 'text' ) ) ) ), array( array( $rule( 'block', 'cfs/p12testimonial' ) ) ) );
	$made[] = $bg;
	$t( 'A block group is found for its block only, never on post screens', 1 === count( CFS_Field_Group::get_groups_for_block( 'cfs/p12testimonial' ) ) && 0 === count( CFS_Field_Group::get_groups_for_block( 'cfs/other' ) ) && ! in_array( 'P12 Block group', wp_list_pluck( CFS_Field_Group::get_groups_for_post( $pg ), 'title' ), true ) );
	CFS_Blocks::register_all();
	$t( 'Registered with WordPress as a dynamic block with a form attribute', WP_Block_Type_Registry::get_instance()->is_registered( $name ) && isset( WP_Block_Type_Registry::get_instance()->get_registered( $name )->attributes['form'] ) );
	$form = 'cfs[quote]=' . rawurlencode( 'Great <b>work</b> & thanks' ) . '&cfs[points][0][t]=One&cfs[points][1][t]=Two&cfs[points][2][t]=' . rawurlencode( "<script>x</script>Three" ) . '&cfs[bogus]=hack';
	$html = render_block( array( 'blockName' => $name, 'attrs' => array( 'form' => $form ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
	$t( 'WordPress renders the block: values from the form, loop works', false !== strpos( $html, '<blockquote>Great work &amp; thanks</blockquote>' ) && false !== strpos( $html, '<li>One</li><li>Two</li><li>Three</li>' ) && false !== strpos( $html, '[cfs/p12testimonial]' ), $html );
	$t( 'Wrapper has block class; no preview marker on the front end', false !== strpos( $html, 'class="cfs-block wp-block-cfs-p12testimonial' ) && false === strpos( $html, '[preview]' ) );
	$t( 'Values are sanitised by the field types (no script tag survives)', false === strpos( $html, '<script' ) && false === strpos( $html, 'hack' ), $html );
	$t( 'Nothing was written to the database', ! metadata_exists( 'post', $pg, 'quote' ) && false === get_option( 'options_quote', false ) );
	$t( 'Storage and in_block are restored after rendering', ! CFS_Blocks::in_block() && '' === CFS_Blocks::current() && array() === CFS_Storage::block_values() );
	$prev = CFS_Blocks::render( $name, $form, array(), true );
	$t( 'Preview render flags is_preview for the template', false !== strpos( $prev, '[preview]' ) );
	$empty = CFS_Blocks::render( $name, '', array() );
	$t( 'A block with no data renders empty fields without errors', false !== strpos( $empty, '<blockquote></blockquote>' ) && false !== strpos( $empty, '<ul></ul>' ), $empty );
	$t( 'Alignment attribute becomes a class', false !== strpos( CFS_Blocks::render( $name, $form, array( 'align' => 'wide' ) ), 'alignwide' ) );
	$t( 'Oversized form data is ignored, not parsed', false === strpos( CFS_Blocks::render( $name, 'cfs[quote]=' . str_repeat( 'a', CFS_Blocks::MAX_FORM + 10 ), array() ), 'aaaa' ) );
	$t( 'Unknown block renders nothing', '' === CFS_Blocks::render( 'cfs/ghost', $form ) );
	$t( 'cfs_get_field in a post template is unaffected by a block that rendered earlier', null === cfs_get_field( 'quote', $pg ) || '' === cfs_get_field( 'quote', $pg ) );
	// Callback-style block + nested render restores the outer block's values.
	$g2 = $mk( 'P12 Block group 2', array( array( 'label' => 'Title', 'name' => 'btitle', 'type' => 'text' ) ), array( array( $rule( 'block', 'cfs/p12callback' ) ) ) );
	$made[] = $g2;
	cfs_register_block(
		array(
			'name'            => 'p12callback',
			'render_callback' => function ( $block, $is_preview ) use ( $name, $form ) {
				echo '<h2>' . esc_html( (string) cfs_get_field( 'btitle' ) ) . '</h2>';
				echo CFS_Blocks::render( $name, $form ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<h3>' . esc_html( (string) cfs_get_field( 'btitle' ) ) . '</h3>';
			},
		)
	);
	CFS_Blocks::register_all();
	$html = CFS_Blocks::render( 'cfs/p12callback', 'cfs[btitle]=Outer' );
	$t( 'render_callback blocks work; a nested block render restores the outer block values', false !== strpos( $html, '<h2>Outer</h2>' ) && false !== strpos( $html, '<h3>Outer</h3>' ) && false !== strpos( $html, '<blockquote>Great work' ), $html );
	// Template path safety.
	$out_tpl = get_temp_dir() . 'p12-evil.php';
	file_put_contents( $out_tpl, '<?php echo "PWNED";' );
	cfs_register_block( array( 'name' => 'p12evil', 'render_template' => $out_tpl ) );
	cfs_register_block( array( 'name' => 'p12txt', 'render_template' => $theme . '/p12.txt' ) );
	cfs_register_block( array( 'name' => 'p12trav', 'render_template' => $theme . '/../../../../../../etc/passwd' ) );
	file_put_contents( $theme . '/p12.txt', 'TEXTFILE' );
	$t( 'A template outside the theme/plugin folders is refused', false === strpos( CFS_Blocks::render( 'cfs/p12evil', '' ), 'PWNED' ) );
	$t( 'A non-PHP template and a path-traversal template are refused', false === strpos( CFS_Blocks::render( 'cfs/p12txt', '' ), 'TEXTFILE' ) && false === strpos( CFS_Blocks::render( 'cfs/p12trav', '' ), 'root:' ) );
	unlink( $out_tpl );
	unlink( $theme . '/p12.txt' );

	$module = 'Blocks: theme folder discovery';
	$dir = $theme . '/p12-blocks-tmp';
	mkdir( $dir . '/demo', 0777, true );
	file_put_contents( $dir . '/demo/block.json', wp_json_encode( array( 'name' => 'p12demo', 'title' => 'P12 Demo', 'category' => 'text', 'icon' => 'star-filled', 'description' => 'From json' ) ) );
	file_put_contents( $dir . '/demo/render.php', '<?php echo "<p>demo:" . esc_html( (string) cfs_get_field( "dtitle" ) ) . "</p>";' );
	mkdir( $dir . '/broken', 0777, true );
	file_put_contents( $dir . '/broken/block.json', '{nope' );
	add_filter( 'cfs/blocks_dir', $bf = function () use ( $dir ) {
		return $dir;
	} );
	CFS_Blocks::discover();
	remove_filter( 'cfs/blocks_dir', $bf );
	$blocks = CFS_Blocks::get_blocks();
	$t( 'block.json + render.php in the theme folder registers a block; a broken json is skipped', isset( $blocks['cfs/p12demo'] ) && 'P12 Demo' === $blocks['cfs/p12demo']['title'] && 'star-filled' === $blocks['cfs/p12demo']['icon'] && 0 === count( array_filter( array_keys( $blocks ), function ( $k ) { return 'cfs/broken' === $k; } ) ), array_keys( $blocks ) );
	$g3 = $mk( 'P12 Block group 3', array( array( 'label' => 'D', 'name' => 'dtitle', 'type' => 'text' ) ), array( array( $rule( 'block', 'cfs/p12demo' ) ) ) );
	$made[] = $g3;
	$t( 'The discovered block renders its render.php with its fields', false !== strpos( CFS_Blocks::render( 'cfs/p12demo', 'cfs[dtitle]=Hello' ), '<p>demo:Hello</p>' ) );
	unlink( $dir . '/demo/block.json' );
	unlink( $dir . '/demo/render.php' );
	unlink( $dir . '/broken/block.json' );
	rmdir( $dir . '/demo' );
	rmdir( $dir . '/broken' );
	rmdir( $dir );

	$module = 'Blocks: editor REST form';
	$call = function ( $params ) {
		$req = new WP_REST_Request( 'POST', '/cfs/v1/block-form' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return rest_do_request( $req );
	};
	$res = $call( array( 'block' => $name, 'form' => $form ) );
	$d   = $res->get_data();
	$t( 'POST /cfs/v1/block-form returns the field form filled from the stored form string', 200 === $res->get_status() && 1 === $d['groups'] && false !== strpos( $d['html'], 'name="cfs[quote]"' ) && false !== strpos( $d['html'], 'Great work &amp; thanks' ) && false !== strpos( $d['html'], 'name="cfs[points][1][t]"' ) && false !== strpos( $d['html'], 'value="Two"' ), substr( $d['html'] ?? '', 0, 200 ) );
	$res = $call( array( 'block' => $name, 'form' => '' ) );
	$t( 'An empty form renders the defaults (a fresh block)', 200 === $res->get_status() && false !== strpos( $res->get_data()['html'], 'name="cfs[quote]"' ) );
	$res = $call( array( 'block' => 'cfs/p12evil', 'form' => '' ) );
	$t( 'A block with no field group reports zero groups (editor shows a hint)', 200 === $res->get_status() && 0 === $res->get_data()['groups'] );
	$t( 'Unknown block → 404', 404 === $call( array( 'block' => 'cfs/ghost' ) )->get_status() );
	$sub = wp_insert_user( array( 'user_login' => 'cfs_p12_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	wp_set_current_user( $sub );
	$t( 'A subscriber cannot use the block form endpoint', $call( array( 'block' => $name ) )->get_status() >= 400 );
	wp_set_current_user( 0 );
	$t( 'Anonymous visitors cannot either', $call( array( 'block' => $name ) )->get_status() >= 400 );
	wp_set_current_user( 1 );
	$t( 'REST form rendering leaves no block state behind', ! CFS_Blocks::in_block() && array() === CFS_Storage::block_values() );
	$t( 'Block editor script and settings are wired (enqueue hook, JS file)', has_action( 'enqueue_block_editor_assets', array( 'CFS_Blocks', 'enqueue_editor' ) ) && is_file( CFS_PATH . 'admin/js/blocks.js' ) );

	/* ---------------------------------------------------------------- */
	$module = 'Multilingual: setup';
	$GLOBALS['cfs_qa_lang']    = '';
	$GLOBALS['cfs_qa_default'] = 'en';
	if ( ! function_exists( 'pll_current_language' ) ) {
		function pll_current_language( $field = 'slug' ) {
			return $GLOBALS['cfs_qa_lang'] ? $GLOBALS['cfs_qa_lang'] : false;
		}
		function pll_default_language( $field = 'slug' ) {
			return $GLOBALS['cfs_qa_default'];
		}
	}
	$t( 'A (stand-in) Polylang is detected as active', CFS_I18n::active() );
	$t( 'Language helpers read the plugin', 'en' === CFS_I18n::default_language() && '' === CFS_I18n::current_language() && 'fr' === ( function () {
		$GLOBALS['cfs_qa_lang'] = 'fr';
		$l                      = CFS_I18n::current_language();
		$GLOBALS['cfs_qa_lang'] = '';
		return $l;
	} )() );
	$t( 'Per-language options are OFF by default (no suffix even in French)', ( function () {
		$GLOBALS['cfs_qa_lang'] = 'fr';
		$s                      = CFS_I18n::options_suffix();
		$GLOBALS['cfs_qa_lang'] = '';
		return '' === $s;
	} )() );

	$module = 'Multilingual: options pages';
	update_option( CFS_I18n::OPTION, 1 );
	$suffix = function ( $lang ) {
		$GLOBALS['cfs_qa_lang'] = $lang;
		$s                      = CFS_I18n::options_suffix();
		$GLOBALS['cfs_qa_lang'] = '';
		return $s;
	};
	$t( 'Default language and "all languages" keep plain keys; other languages get "fr_"', '' === $suffix( 'en' ) && '' === $suffix( '' ) && 'fr_' === $suffix( 'fr' ) && 'pt-br_' === $suffix( 'pt-br' ) );
	$write = function ( $lang, $val ) {
		$GLOBALS['cfs_qa_lang'] = $lang;
		CFS_Storage::update( 'p12_slogan', $val, 'option' );
		$GLOBALS['cfs_qa_lang'] = '';
	};
	$read = function ( $lang ) {
		$GLOBALS['cfs_qa_lang'] = $lang;
		$v                      = CFS_Storage::get( 'p12_slogan', 'option' );
		$GLOBALS['cfs_qa_lang'] = '';
		return $v;
	};
	$write( 'en', 'Hello' );
	$write( 'fr', 'Bonjour' );
	$t( 'Each language stores its own value (options_ / options_fr_)', 'Hello' === get_option( 'options_p12_slogan' ) && 'Bonjour' === get_option( 'options_fr_p12_slogan' ), array( get_option( 'options_p12_slogan' ), get_option( 'options_fr_p12_slogan' ) ) );
	$t( 'Reading follows the current language; a missing translation does not leak another language', 'Hello' === $read( 'en' ) && 'Bonjour' === $read( 'fr' ) && '' === $read( 'de' ) );
	$t( 'exists / delete are per language too', ( function () {
		$GLOBALS['cfs_qa_lang'] = 'fr';
		$e1                     = CFS_Storage::exists( 'p12_slogan', 'option' );
		CFS_Storage::delete( 'p12_slogan', 'option' );
		$e2                     = CFS_Storage::exists( 'p12_slogan', 'option' );
		$GLOBALS['cfs_qa_lang'] = '';
		return $e1 && ! $e2 && false !== get_option( 'options_p12_slogan', false );
	} )() );
	$t( 'cfs_get_field(…, "option") follows the language end to end', ( function () use ( $mk, $rule, &$made ) {
		$g      = $mk( 'P12 Opt', array( array( 'label' => 'Tag', 'name' => 'p12_tag', 'type' => 'text' ) ), array( array( $rule( 'options_page', 'p12-i18n-opts' ) ) ) );
		$made[] = $g;
		CFS_Options_Page::add( array( 'page_title' => 'P12 i18n', 'menu_slug' => 'p12-i18n-opts' ) );
		$field = CFS_Field_Group::find_field( CFS_Field_Group::get_options_groups(), 'p12_tag' );
		CFS_Field_Text::save_value( $field, 'English tag', 'option' );
		$GLOBALS['cfs_qa_lang'] = 'fr';
		CFS_Field_Text::save_value( $field, 'Étiquette', 'option' );
		$fr                     = cfs_get_field( 'p12_tag', 'option' );
		$GLOBALS['cfs_qa_lang'] = '';
		$en                     = cfs_get_field( 'p12_tag', 'option' );
		delete_option( 'options_fr_p12_tag' );
		delete_option( 'options_fr__p12_tag' );
		delete_option( 'options_p12_tag' );
		delete_option( 'options__p12_tag' );
		return 'Étiquette' === $fr && 'English tag' === $en;
	} )() );
	update_option( CFS_I18n::OPTION, 0 );
	$t( 'Turning the setting off: French reads the plain keys again', 'Hello' === $read( 'fr' ) );
	add_filter( 'cfs/translate_options', '__return_true' );
	$t( 'The cfs/translate_options filter can force it on', 'fr_' === $suffix( 'fr' ) );
	remove_filter( 'cfs/translate_options', '__return_true' );
	delete_option( 'options_p12_slogan' );
	delete_option( 'options_fr_p12_slogan' );
	delete_option( CFS_I18n::OPTION );

	$module = 'Multilingual: field translation setting';
	$san = CFS_Field_Group::sanitize_fields(
		array(
			array( 'label' => 'A', 'name' => 'a', 'type' => 'text' ),
			array( 'label' => 'B', 'name' => 'b', 'type' => 'text', 'translate' => 'copy' ),
			array( 'label' => 'C', 'name' => 'c', 'type' => 'text', 'translate' => 'ignore' ),
			array( 'label' => 'D', 'name' => 'd', 'type' => 'text', 'translate' => 'translate' ),
			array( 'label' => 'E', 'name' => 'e', 'type' => 'text', 'translate' => '<evil>' ),
		)
	);
	$t( 'Sanitize stores only copy / ignore; the default and junk are not stored (old groups unchanged)', ! isset( $san[0]['translate'] ) && 'copy' === $san[1]['translate'] && 'ignore' === $san[2]['translate'] && ! isset( $san[3]['translate'] ) && ! isset( $san[4]['translate'] ), $san );
	$lg = $mk(
		'P12 Translate',
		array(
			array( 'label' => 'Copied', 'name' => 'tcopy', 'type' => 'text', 'translate' => 'copy' ),
			array( 'label' => 'Copied Extra', 'name' => 'tcopy_extra', 'type' => 'text' ),
			array( 'label' => 'Rows', 'name' => 'trows', 'type' => 'repeater', 'translate' => 'copy', 'sub_fields' => array( array( 'label' => 'S', 'name' => 's', 'type' => 'text' ) ) ),
			array( 'label' => 'Box', 'name' => 'tbox', 'type' => 'group', 'translate' => 'copy', 'sub_fields' => array( array( 'label' => 'X', 'name' => 'x', 'type' => 'text' ) ) ),
			array( 'label' => 'Never', 'name' => 'tnever', 'type' => 'text', 'translate' => 'ignore' ),
			array( 'label' => 'Own', 'name' => 'town', 'type' => 'text' ),
		),
		array( array( $rule( 'post_id', (string) $pg ) ) )
	);
	$made[] = $lg;
	foreach ( array( 'tcopy' => 'c', 'tcopy_extra' => 'ce', 'trows_0_s' => 'r0', 'trows' => 1, 'tbox_x' => 'bx', 'tnever' => 'n', 'town' => 'o' ) as $k => $val ) {
		update_post_meta( $pg, $k, $val );
		update_post_meta( $pg, '_' . $k, 'field_ref' );
	}
	$keys = CFS_I18n::polylang_copy_metas( array( 'tnever', '_edit_last' ), false, $pg, 0, 'fr' );
	$t( 'Polylang: copy fields (value + reference), repeater rows and group sub values are copied', in_array( 'tcopy', $keys, true ) && in_array( '_tcopy', $keys, true ) && in_array( 'trows', $keys, true ) && in_array( 'trows_0_s', $keys, true ) && in_array( '_trows_0_s', $keys, true ) && in_array( 'tbox_x', $keys, true ), $keys );
	$t( 'Polylang: a field whose name merely starts with a copy field name is not swept in', ! in_array( 'tcopy_extra', $keys, true ) && ! in_array( '_tcopy_extra', $keys, true ), $keys );
	$t( 'Polylang: ignore fields are removed even when Polylang had them; translate fields are not added; other keys kept', ! in_array( 'tnever', $keys, true ) && ! in_array( 'town', $keys, true ) && in_array( '_edit_last', $keys, true ), $keys );
	$t( 'Polylang: posts without matching groups pass through untouched; bad ids are safe', array( 'x' ) === CFS_I18n::polylang_copy_metas( array( 'x' ), false, 999999, 0, 'fr' ) && array( 'x' ) === CFS_I18n::polylang_copy_metas( array( 'x' ), false, 0, 0, 'fr' ) );
	$t( 'The pll_copy_post_metas filter is hooked', (bool) has_filter( 'pll_copy_post_metas', array( 'CFS_I18n', 'polylang_copy_metas' ) ) );
	$t( 'JSON export keeps the translate setting (round trip)', ( function () use ( $lg ) {
		$exp = json_decode( CFS_JSON::export_json( array( $lg ) ), true );
		return 'copy' === $exp['groups'][0]['fields'][0]['translate'] && 'ignore' === $exp['groups'][0]['fields'][4]['translate'] && ! isset( $exp['groups'][0]['fields'][5]['translate'] );
	} )() );

	/* ---------------------------------------------------------------- */
	$module = 'Tools settings';
	ob_start();
	CFS_JSON::render_page();
	$page = ob_get_clean();
	$t( 'Tools page shows the Google Maps key and per-language options settings', false !== strpos( $page, 'id="cfs-settings-form"' ) && false !== strpos( $page, 'name="cfs_google_maps_api_key"' ) && false !== strpos( $page, 'name="cfs_translate_options"' ) );
	$t( 'Settings handler is wired to admin-post', (bool) has_action( 'admin_post_cfs_settings' ) );

	/* ---------------------------------------------------------------- */
	$module = 'Clean-up';
	foreach ( $made as $gid ) {
		wp_delete_post( $gid, true );
	}
	wp_delete_post( $pg, true );
	unlink( $tpl );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sub );
	delete_option( 'cfs_google_maps_api_key' );
	CFS_Field_Group::flush_cache();
	$left = array();
	foreach ( get_posts( array( 'post_type' => 'cfs_field_group', 'post_status' => 'any', 'numberposts' => -1 ) ) as $g ) {
		if ( 0 === strpos( $g->post_title, 'P12' ) ) {
			$left[] = $g->post_title;
		}
	}
	$t( 'Phase 12 test data removed (no leftover P12 groups, template files gone)', ! $left && ! file_exists( $tpl ) && ! file_exists( $theme . '/p12-blocks-tmp' ), $left );
}
