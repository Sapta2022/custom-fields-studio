<?php
/**
 * Phase 12 checks: Google Map field, custom blocks, multilingual support.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cfstudio_qa_run_p12( $t, &$phase, &$module, $P1, $ids ) {
	$phase = 'P12';

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
	$made = array();
	$pg   = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'P12 page', 'post_status' => 'publish' ) );

	/* ---------------------------------------------------------------- */
	$module = 'Google Map field';
	$map    = CFStudio_Field_Registry::get_class( 'google_map' );
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
	$map::render_input( array_merge( $fld, array( 'center_lat' => 1.5, 'center_lng' => 2.5 ) ), array(), 'cfstudio[m]' );
	$html = ob_get_clean();
	$t( 'Editor input: address / lat / lng / zoom fields; default centre is NOT pre-filled as a value', false !== strpos( $html, 'name="cfstudio[m][address]"' ) && false !== strpos( $html, 'name="cfstudio[m][lat]"' ) && false !== strpos( $html, 'name="cfstudio[m][lng]"' ) && false !== strpos( $html, 'name="cfstudio[m][zoom]"' ) && false !== strpos( $html, 'data-center-lat="1.5"' ) && 1 === preg_match( '/cfs-gmap-lat" name="cfstudio\[m\]\[lat\]" value=""/', $html ), substr( $html, 0, 300 ) );
	add_filter( 'cfstudio/google_maps_api_key', $kf = function () {
		return 'KEY123';
	} );
	ob_start();
	$map::render_input( $fld, array( 'lat' => 1, 'lng' => 2, 'address' => 'A "quoted" <b>' ), 'cfstudio[m]' );
	$html = ob_get_clean();
	remove_filter( 'cfstudio/google_maps_api_key', $kf );
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
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfstudio' => array( 'p12_venue' => array( 'address' => 'Kolkata', 'lat' => '22.5726', 'lng' => '88.3639', 'zoom' => '11' ), 'p12_branches' => array( array( 'place' => array( 'address' => 'B1', 'lat' => '1', 'lng' => '2' ) ), array( 'place' => array( 'address' => 'B2', 'lat' => '', 'lng' => '' ) ) ) ) );
	do_action( 'save_post', $pg, get_post( $pg ), true );
	$_POST = array();
	$got = cfstudio_get_field( 'p12_venue', $pg );
	$t( 'Saved through the meta box; read back with cfstudio_get_field', is_array( $got ) && 'Kolkata' === $got['address'] && 22.5726 === $got['lat'] && 88.3639 === $got['lng'] && 11 === $got['zoom'], $got );
	$rows = cfstudio_get_field( 'p12_branches', $pg );
	$t( 'Works inside a repeater (an empty position row stays empty)', 2 === count( $rows ) && 1.0 === $rows[0]['place']['lat'] && null === $rows[1]['place'], $rows );
	$sc = do_shortcode( '[cfstudio field="p12_venue" post_id="' . $pg . '"]' );
	$t( 'Shortcode renders a keyless Google embed iframe with lat,lng and zoom', false !== strpos( $sc, 'cfs-out-map' ) && false !== strpos( $sc, 'google.com/maps' ) && false !== strpos( $sc, 'output=embed' ) && false !== strpos( $sc, '22.5726,88.3639' ) && false !== strpos( $sc, 'height="250"' ) && false !== strpos( $sc, 'title="Kolkata"' ), $sc );
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfstudio' => array( 'p12_venue' => array( 'address' => '"><script>alert(1)</script>', 'lat' => '1', 'lng' => '2' ) ) );
	do_action( 'save_post', $pg, get_post( $pg ), true );
	$_POST = array();
	$sc = do_shortcode( '[cfstudio field="p12_venue" post_id="' . $pg . '"]' );
	$t( 'Hostile address cannot break out of the iframe markup', false === strpos( $sc, '<script' ) && substr_count( $sc, '<iframe' ) === 1, $sc );
	$_POST = array( CFStudio_Meta_Box::NONCE_FIELD => wp_create_nonce( CFStudio_Meta_Box::NONCE_ACTION ), 'cfstudio' => array( 'p12_venue' => array( 'address' => '', 'lat' => '', 'lng' => '' ) ) );
	do_action( 'save_post', $pg, get_post( $pg ), true );
	$_POST = array();
	$t( 'Clearing the map empties the value and the shortcode output', null === cfstudio_get_field( 'p12_venue', $pg ) && '' === do_shortcode( '[cfstudio field="p12_venue" post_id="' . $pg . '"]' ) );
	$rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/pages/' . $pg ) );
	$rd   = $rest->get_data();
	$t( 'REST exposes the map as a plain array (round trip stable)', 200 === $rest->get_status() && array_key_exists( 'p12_venue', (array) $rd['cfstudio'] ) && is_array( $rd['cfstudio']['p12_branches'] ), $rd['cfstudio'] ?? null );
	ob_start();
	$map::render_settings( array_merge( $fld, array( 'zoom' => 9 ) ), 'cfstudio_fields[0]' );
	$h = ob_get_clean();
	$t( 'Builder settings block is for google_map only and shows saved zoom', 4 === substr_count( $h, 'data-type="google_map"' ) && false !== strpos( $h, 'value="9"' ), $h );

	/* ---------------------------------------------------------------- */
	$module = 'Blocks: storage & location';
	$old = CFStudio_Storage::swap_block_values( array() );
	CFStudio_Storage::update( 'k', 'v', 'block' );
	$t( "'block' storage context is in memory: get / exists / update / delete", 'v' === CFStudio_Storage::get( 'k', 'block' ) && CFStudio_Storage::exists( 'k', 'block' ) && '' === CFStudio_Storage::get( 'nope', 'block' ) && ( CFStudio_Storage::delete( 'k', 'block' ) || true ) && ! CFStudio_Storage::exists( 'k', 'block' ) );
	CFStudio_Storage::update( 'bs', 'C:\\temp', 'block' );
	$t( 'Block storage keeps values byte for byte (no slashing)', 'C:\\temp' === CFStudio_Storage::get( 'bs', 'block' ) );
	$inner = CFStudio_Storage::swap_block_values( $old );
	$t( 'swap_block_values hands back the previous values', array( 'bs' => 'C:\\temp' ) === $inner && array() === CFStudio_Storage::block_values() || CFStudio_Storage::block_values() === $old );
	$t( "Nothing from the 'block' context reaches post meta or options", ! metadata_exists( 'post', $pg, 'bs' ) && false === get_option( 'options_bs', false ) );
	$t( 'Location param "block" exists in the builder with the screen map', isset( CFStudio_Location_Rules::get_params()['block'] ) && array( 'block' ) === CFStudio_Location_Rules::SCREEN_PARAMS['block'] );
	$t( 'Block rules match only the block screen', CFStudio_Location_Rules::matches( array( array( $rule( 'block', 'cfstudio/x' ) ) ), 'block', array( 'block' => 'cfstudio/x' ) ) && ! CFStudio_Location_Rules::matches( array( array( $rule( 'block', 'cfstudio/x' ) ) ), 'block', array( 'block' => 'cfstudio/y' ) ) && ! CFStudio_Location_Rules::matches( array( array( $rule( 'block', 'cfstudio/x' ) ) ), 'post', array( 'post_type' => 'page' ) ) && CFStudio_Location_Rules::matches( array( array( $rule( 'block', 'cfstudio/x', '!=' ) ) ), 'block', array( 'block' => 'cfstudio/y' ) ) );

	$module = 'Blocks: registration & rendering';
	$theme  = get_stylesheet_directory();
	$tpl    = $theme . '/p12-testimonial.php';
	file_put_contents(
		$tpl,
		'<?php echo "<blockquote>" . esc_html( (string) cfstudio_get_field( "quote" ) ) . "</blockquote>"; ?>' .
		'<ul><?php while ( cfstudio_have_rows( "points" ) ) : cfstudio_the_row(); ?><li><?php echo esc_html( (string) cfstudio_get_sub_field( "t" ) ); ?></li><?php endwhile; ?></ul>' .
		'<?php if ( ! empty( $block["is_preview"] ) ) { echo "[preview]"; } echo "[" . esc_html( $block["name"] ) . "]"; ?>'
	);
	$name = cfstudio_register_block( array( 'name' => 'P12 Testimonial', 'title' => 'P12 Testimonial', 'description' => 'A quote', 'icon' => 'format-quote', 'keywords' => array( 'quote' ), 'render_template' => $tpl ) );
	$t( 'cfstudio_register_block returns the cfs/ name (slugified)', 'cfstudio/p12testimonial' === $name, $name );
	$t( 'Errors without a name', is_wp_error( cfstudio_register_block( array( 'title' => 'x' ) ) ) );
	$bg = $mk( 'P12 Block group', array( array( 'label' => 'Quote', 'name' => 'quote', 'type' => 'text' ), array( 'label' => 'Points', 'name' => 'points', 'type' => 'repeater', 'sub_fields' => array( array( 'label' => 'T', 'name' => 't', 'type' => 'text' ) ) ) ), array( array( $rule( 'block', 'cfstudio/p12testimonial' ) ) ) );
	$made[] = $bg;
	$t( 'A block group is found for its block only, never on post screens', 1 === count( CFStudio_Field_Group::get_groups_for_block( 'cfstudio/p12testimonial' ) ) && 0 === count( CFStudio_Field_Group::get_groups_for_block( 'cfstudio/other' ) ) && ! in_array( 'P12 Block group', wp_list_pluck( CFStudio_Field_Group::get_groups_for_post( $pg ), 'title' ), true ) );
	CFStudio_Blocks::register_all();
	$t( 'Registered with WordPress as a dynamic block with a form attribute', WP_Block_Type_Registry::get_instance()->is_registered( $name ) && isset( WP_Block_Type_Registry::get_instance()->get_registered( $name )->attributes['form'] ) );
	$form = 'cfstudio[quote]=' . rawurlencode( 'Great <b>work</b> & thanks' ) . '&cfstudio[points][0][t]=One&cfstudio[points][1][t]=Two&cfstudio[points][2][t]=' . rawurlencode( "<script>x</script>Three" ) . '&cfstudio[bogus]=hack';
	$html = render_block( array( 'blockName' => $name, 'attrs' => array( 'form' => $form ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
	$t( 'WordPress renders the block: values from the form, loop works', false !== strpos( $html, '<blockquote>Great work &amp; thanks</blockquote>' ) && false !== strpos( $html, '<li>One</li><li>Two</li><li>Three</li>' ) && false !== strpos( $html, '[cfstudio/p12testimonial]' ), $html );
	$t( 'Wrapper has block class; no preview marker on the front end', false !== strpos( $html, 'class="cfs-block wp-block-cfstudio-p12testimonial' ) && false === strpos( $html, '[preview]' ) );
	$t( 'Values are sanitised by the field types (no script tag survives)', false === strpos( $html, '<script' ) && false === strpos( $html, 'hack' ), $html );
	$t( 'Nothing was written to the database', ! metadata_exists( 'post', $pg, 'quote' ) && false === get_option( 'options_quote', false ) );
	$t( 'Storage and in_block are restored after rendering', ! CFStudio_Blocks::in_block() && '' === CFStudio_Blocks::current() && array() === CFStudio_Storage::block_values() );
	$prev = CFStudio_Blocks::render( $name, $form, array(), true );
	$t( 'Preview render flags is_preview for the template', false !== strpos( $prev, '[preview]' ) );
	$empty = CFStudio_Blocks::render( $name, '', array() );
	$t( 'A block with no data renders empty fields without errors', false !== strpos( $empty, '<blockquote></blockquote>' ) && false !== strpos( $empty, '<ul></ul>' ), $empty );
	$t( 'Alignment attribute becomes a class', false !== strpos( CFStudio_Blocks::render( $name, $form, array( 'align' => 'wide' ) ), 'alignwide' ) );
	$t( 'Oversized form data is ignored, not parsed', false === strpos( CFStudio_Blocks::render( $name, 'cfstudio[quote]=' . str_repeat( 'a', CFStudio_Blocks::MAX_FORM + 10 ), array() ), 'aaaa' ) );
	$t( 'Unknown block renders nothing', '' === CFStudio_Blocks::render( 'cfstudio/ghost', $form ) );
	$t( 'cfstudio_get_field in a post template is unaffected by a block that rendered earlier', null === cfstudio_get_field( 'quote', $pg ) || '' === cfstudio_get_field( 'quote', $pg ) );
	// Callback-style block + nested render restores the outer block's values.
	$g2 = $mk( 'P12 Block group 2', array( array( 'label' => 'Title', 'name' => 'btitle', 'type' => 'text' ) ), array( array( $rule( 'block', 'cfstudio/p12callback' ) ) ) );
	$made[] = $g2;
	cfstudio_register_block(
		array(
			'name'            => 'p12callback',
			'render_callback' => function ( $block, $is_preview ) use ( $name, $form ) {
				echo '<h2>' . esc_html( (string) cfstudio_get_field( 'btitle' ) ) . '</h2>';
				echo CFStudio_Blocks::render( $name, $form ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<h3>' . esc_html( (string) cfstudio_get_field( 'btitle' ) ) . '</h3>';
			},
		)
	);
	CFStudio_Blocks::register_all();
	$html = CFStudio_Blocks::render( 'cfstudio/p12callback', 'cfstudio[btitle]=Outer' );
	$t( 'render_callback blocks work; a nested block render restores the outer block values', false !== strpos( $html, '<h2>Outer</h2>' ) && false !== strpos( $html, '<h3>Outer</h3>' ) && false !== strpos( $html, '<blockquote>Great work' ), $html );
	// Template path safety.
	$out_tpl = get_temp_dir() . 'p12-evil.php';
	file_put_contents( $out_tpl, '<?php echo "PWNED";' );
	cfstudio_register_block( array( 'name' => 'p12evil', 'render_template' => $out_tpl ) );
	cfstudio_register_block( array( 'name' => 'p12txt', 'render_template' => $theme . '/p12.txt' ) );
	cfstudio_register_block( array( 'name' => 'p12trav', 'render_template' => $theme . '/../../../../../../etc/passwd' ) );
	file_put_contents( $theme . '/p12.txt', 'TEXTFILE' );
	$t( 'A template outside the theme/plugin folders is refused', false === strpos( CFStudio_Blocks::render( 'cfstudio/p12evil', '' ), 'PWNED' ) );
	$t( 'A non-PHP template and a path-traversal template are refused', false === strpos( CFStudio_Blocks::render( 'cfstudio/p12txt', '' ), 'TEXTFILE' ) && false === strpos( CFStudio_Blocks::render( 'cfstudio/p12trav', '' ), 'root:' ) );
	unlink( $out_tpl );
	unlink( $theme . '/p12.txt' );

	$module = 'Blocks: theme folder discovery';
	$dir = $theme . '/p12-blocks-tmp';
	mkdir( $dir . '/demo', 0777, true );
	file_put_contents( $dir . '/demo/block.json', wp_json_encode( array( 'name' => 'p12demo', 'title' => 'P12 Demo', 'category' => 'text', 'icon' => 'star-filled', 'description' => 'From json' ) ) );
	file_put_contents( $dir . '/demo/render.php', '<?php echo "<p>demo:" . esc_html( (string) cfstudio_get_field( "dtitle" ) ) . "</p>";' );
	mkdir( $dir . '/broken', 0777, true );
	file_put_contents( $dir . '/broken/block.json', '{nope' );
	add_filter( 'cfstudio/blocks_dir', $bf = function () use ( $dir ) {
		return $dir;
	} );
	CFStudio_Blocks::discover();
	remove_filter( 'cfstudio/blocks_dir', $bf );
	$blocks = CFStudio_Blocks::get_blocks();
	$t( 'block.json + render.php in the theme folder registers a block; a broken json is skipped', isset( $blocks['cfstudio/p12demo'] ) && 'P12 Demo' === $blocks['cfstudio/p12demo']['title'] && 'star-filled' === $blocks['cfstudio/p12demo']['icon'] && 0 === count( array_filter( array_keys( $blocks ), function ( $k ) { return 'cfstudio/broken' === $k; } ) ), array_keys( $blocks ) );
	$g3 = $mk( 'P12 Block group 3', array( array( 'label' => 'D', 'name' => 'dtitle', 'type' => 'text' ) ), array( array( $rule( 'block', 'cfstudio/p12demo' ) ) ) );
	$made[] = $g3;
	$t( 'The discovered block renders its render.php with its fields', false !== strpos( CFStudio_Blocks::render( 'cfstudio/p12demo', 'cfstudio[dtitle]=Hello' ), '<p>demo:Hello</p>' ) );
	unlink( $dir . '/demo/block.json' );
	unlink( $dir . '/demo/render.php' );
	unlink( $dir . '/broken/block.json' );
	rmdir( $dir . '/demo' );
	rmdir( $dir . '/broken' );
	rmdir( $dir );

	$module = 'Blocks: editor REST form';
	$call = function ( $params ) {
		$req = new WP_REST_Request( 'POST', '/cfstudio/v1/block-form' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return rest_do_request( $req );
	};
	$res = $call( array( 'block' => $name, 'form' => $form ) );
	$d   = $res->get_data();
	$t( 'POST /cfstudio/v1/block-form returns the field form filled from the stored form string', 200 === $res->get_status() && 1 === $d['groups'] && false !== strpos( $d['html'], 'name="cfstudio[quote]"' ) && false !== strpos( $d['html'], 'Great work &amp; thanks' ) && false !== strpos( $d['html'], 'name="cfstudio[points][1][t]"' ) && false !== strpos( $d['html'], 'value="Two"' ), substr( $d['html'] ?? '', 0, 200 ) );
	$res = $call( array( 'block' => $name, 'form' => '' ) );
	$t( 'An empty form renders the defaults (a fresh block)', 200 === $res->get_status() && false !== strpos( $res->get_data()['html'], 'name="cfstudio[quote]"' ) );
	$res = $call( array( 'block' => 'cfstudio/p12evil', 'form' => '' ) );
	$t( 'A block with no field group reports zero groups (editor shows a hint)', 200 === $res->get_status() && 0 === $res->get_data()['groups'] );
	$t( 'Unknown block → 404', 404 === $call( array( 'block' => 'cfstudio/ghost' ) )->get_status() );
	$sub = wp_insert_user( array( 'user_login' => 'cfstudio_p12_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	wp_set_current_user( $sub );
	$t( 'A subscriber cannot use the block form endpoint', $call( array( 'block' => $name ) )->get_status() >= 400 );
	wp_set_current_user( 0 );
	$t( 'Anonymous visitors cannot either', $call( array( 'block' => $name ) )->get_status() >= 400 );
	wp_set_current_user( 1 );
	$t( 'REST form rendering leaves no block state behind', ! CFStudio_Blocks::in_block() && array() === CFStudio_Storage::block_values() );
	$t( 'Block editor script and settings are wired (enqueue hook, JS file)', has_action( 'enqueue_block_editor_assets', array( 'CFStudio_Blocks', 'enqueue_editor' ) ) && is_file( CFSTUDIO_PATH . 'admin/js/blocks.js' ) );

	/* ---------------------------------------------------------------- */
	$module = 'Multilingual: setup';
	$GLOBALS['cfstudio_qa_lang']    = '';
	$GLOBALS['cfstudio_qa_default'] = 'en';
	if ( ! function_exists( 'pll_current_language' ) ) {
		function pll_current_language( $field = 'slug' ) {
			return $GLOBALS['cfstudio_qa_lang'] ? $GLOBALS['cfstudio_qa_lang'] : false;
		}
		function pll_default_language( $field = 'slug' ) {
			return $GLOBALS['cfstudio_qa_default'];
		}
	}
	$t( 'A (stand-in) Polylang is detected as active', CFStudio_I18n::active() );
	$t( 'Language helpers read the plugin', 'en' === CFStudio_I18n::default_language() && '' === CFStudio_I18n::current_language() && 'fr' === ( function () {
		$GLOBALS['cfstudio_qa_lang'] = 'fr';
		$l                      = CFStudio_I18n::current_language();
		$GLOBALS['cfstudio_qa_lang'] = '';
		return $l;
	} )() );
	$t( 'Per-language options are OFF by default (no suffix even in French)', ( function () {
		$GLOBALS['cfstudio_qa_lang'] = 'fr';
		$s                      = CFStudio_I18n::options_suffix();
		$GLOBALS['cfstudio_qa_lang'] = '';
		return '' === $s;
	} )() );

	$module = 'Multilingual: options pages';
	update_option( CFStudio_I18n::OPTION, 1 );
	$suffix = function ( $lang ) {
		$GLOBALS['cfstudio_qa_lang'] = $lang;
		$s                      = CFStudio_I18n::options_suffix();
		$GLOBALS['cfstudio_qa_lang'] = '';
		return $s;
	};
	$t( 'Default language and "all languages" keep plain keys; other languages get "fr_"', '' === $suffix( 'en' ) && '' === $suffix( '' ) && 'fr_' === $suffix( 'fr' ) && 'pt-br_' === $suffix( 'pt-br' ) );
	$write = function ( $lang, $val ) {
		$GLOBALS['cfstudio_qa_lang'] = $lang;
		CFStudio_Storage::update( 'p12_slogan', $val, 'option' );
		$GLOBALS['cfstudio_qa_lang'] = '';
	};
	$read = function ( $lang ) {
		$GLOBALS['cfstudio_qa_lang'] = $lang;
		$v                      = CFStudio_Storage::get( 'p12_slogan', 'option' );
		$GLOBALS['cfstudio_qa_lang'] = '';
		return $v;
	};
	$write( 'en', 'Hello' );
	$write( 'fr', 'Bonjour' );
	$t( 'Each language stores its own value (options_ / options_fr_)', 'Hello' === get_option( 'options_p12_slogan' ) && 'Bonjour' === get_option( 'options_fr_p12_slogan' ), array( get_option( 'options_p12_slogan' ), get_option( 'options_fr_p12_slogan' ) ) );
	$t( 'Reading follows the current language; a missing translation does not leak another language', 'Hello' === $read( 'en' ) && 'Bonjour' === $read( 'fr' ) && '' === $read( 'de' ) );
	$t( 'exists / delete are per language too', ( function () {
		$GLOBALS['cfstudio_qa_lang'] = 'fr';
		$e1                     = CFStudio_Storage::exists( 'p12_slogan', 'option' );
		CFStudio_Storage::delete( 'p12_slogan', 'option' );
		$e2                     = CFStudio_Storage::exists( 'p12_slogan', 'option' );
		$GLOBALS['cfstudio_qa_lang'] = '';
		return $e1 && ! $e2 && false !== get_option( 'options_p12_slogan', false );
	} )() );
	$t( 'cfstudio_get_field(…, "option") follows the language end to end', ( function () use ( $mk, $rule, &$made ) {
		$g      = $mk( 'P12 Opt', array( array( 'label' => 'Tag', 'name' => 'p12_tag', 'type' => 'text' ) ), array( array( $rule( 'options_page', 'p12-i18n-opts' ) ) ) );
		$made[] = $g;
		CFStudio_Options_Page::add( array( 'page_title' => 'P12 i18n', 'menu_slug' => 'p12-i18n-opts' ) );
		$field = CFStudio_Field_Group::find_field( CFStudio_Field_Group::get_options_groups(), 'p12_tag' );
		CFStudio_Field_Text::save_value( $field, 'English tag', 'option' );
		$GLOBALS['cfstudio_qa_lang'] = 'fr';
		CFStudio_Field_Text::save_value( $field, 'Étiquette', 'option' );
		$fr                     = cfstudio_get_field( 'p12_tag', 'option' );
		$GLOBALS['cfstudio_qa_lang'] = '';
		$en                     = cfstudio_get_field( 'p12_tag', 'option' );
		delete_option( 'options_fr_p12_tag' );
		delete_option( 'options_fr__p12_tag' );
		delete_option( 'options_p12_tag' );
		delete_option( 'options__p12_tag' );
		return 'Étiquette' === $fr && 'English tag' === $en;
	} )() );
	update_option( CFStudio_I18n::OPTION, 0 );
	$t( 'Turning the setting off: French reads the plain keys again', 'Hello' === $read( 'fr' ) );
	add_filter( 'cfstudio/translate_options', '__return_true' );
	$t( 'The cfstudio/translate_options filter can force it on', 'fr_' === $suffix( 'fr' ) );
	remove_filter( 'cfstudio/translate_options', '__return_true' );
	delete_option( 'options_p12_slogan' );
	delete_option( 'options_fr_p12_slogan' );
	delete_option( CFStudio_I18n::OPTION );

	$module = 'Multilingual: field translation setting';
	$san = CFStudio_Field_Group::sanitize_fields(
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
	$keys = CFStudio_I18n::polylang_copy_metas( array( 'tnever', '_edit_last' ), false, $pg, 0, 'fr' );
	$t( 'Polylang: copy fields (value + reference), repeater rows and group sub values are copied', in_array( 'tcopy', $keys, true ) && in_array( '_tcopy', $keys, true ) && in_array( 'trows', $keys, true ) && in_array( 'trows_0_s', $keys, true ) && in_array( '_trows_0_s', $keys, true ) && in_array( 'tbox_x', $keys, true ), $keys );
	$t( 'Polylang: a field whose name merely starts with a copy field name is not swept in', ! in_array( 'tcopy_extra', $keys, true ) && ! in_array( '_tcopy_extra', $keys, true ), $keys );
	$t( 'Polylang: ignore fields are removed even when Polylang had them; translate fields are not added; other keys kept', ! in_array( 'tnever', $keys, true ) && ! in_array( 'town', $keys, true ) && in_array( '_edit_last', $keys, true ), $keys );
	$t( 'Polylang: posts without matching groups pass through untouched; bad ids are safe', array( 'x' ) === CFStudio_I18n::polylang_copy_metas( array( 'x' ), false, 999999, 0, 'fr' ) && array( 'x' ) === CFStudio_I18n::polylang_copy_metas( array( 'x' ), false, 0, 0, 'fr' ) );
	$t( 'The pll_copy_post_metas filter is hooked', (bool) has_filter( 'pll_copy_post_metas', array( 'CFStudio_I18n', 'polylang_copy_metas' ) ) );
	$t( 'JSON export keeps the translate setting (round trip)', ( function () use ( $lg ) {
		$exp = json_decode( CFStudio_JSON::export_json( array( $lg ) ), true );
		return 'copy' === $exp['groups'][0]['fields'][0]['translate'] && 'ignore' === $exp['groups'][0]['fields'][4]['translate'] && ! isset( $exp['groups'][0]['fields'][5]['translate'] );
	} )() );

	/* ---------------------------------------------------------------- */
	$module = 'Tools settings';
	ob_start();
	CFStudio_JSON::render_page();
	$page = ob_get_clean();
	$t( 'Tools page shows the Google Maps key and per-language options settings', false !== strpos( $page, 'id="cfs-settings-form"' ) && false !== strpos( $page, 'name="cfstudio_google_maps_api_key"' ) && false !== strpos( $page, 'name="cfstudio_translate_options"' ) );
	$t( 'Settings handler is wired to admin-post', (bool) has_action( 'admin_post_cfstudio_settings' ) );

	/* ---------------------------------------------------------------- */
	$module = 'Clean-up';
	foreach ( $made as $gid ) {
		wp_delete_post( $gid, true );
	}
	wp_delete_post( $pg, true );
	unlink( $tpl );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sub );
	delete_option( 'cfstudio_google_maps_api_key' );
	CFStudio_Field_Group::flush_cache();
	$left = array();
	foreach ( get_posts( array( 'post_type' => 'cfstudio_field_group', 'post_status' => 'any', 'numberposts' => -1 ) ) as $g ) {
		if ( 0 === strpos( $g->post_title, 'P12' ) ) {
			$left[] = $g->post_title;
		}
	}
	$t( 'Phase 12 test data removed (no leftover P12 groups, template files gone)', ! $left && ! file_exists( $tpl ) && ! file_exists( $theme . '/p12-blocks-tmp' ), $left );
}
