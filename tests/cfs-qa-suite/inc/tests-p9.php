<?php
/**
 * Phase 9 checks: the 14 added field types, run through the same API a
 * real save uses (sanitize / format / render / settings round trip).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cfstudio_qa_run_p9( $t_in, &$phase, &$module, $P1 ) {
	$t     = $t_in;
	$phase = 'P9';
	$f     = function ( $type, $extra = array() ) {
		return array_merge( CFStudio_Field_Registry::get_class( $type )::defaults(), array( 'type' => $type, 'name' => 'x', 'key' => 'field_x' ), $extra );
	};
	$types = array( 'number', 'range', 'email', 'url', 'password', 'checkbox', 'radio', 'button_group', 'file', 'color_picker', 'time_picker', 'date_time_picker', 'link', 'oembed' );

	$module = 'Registry & builder settings';
	foreach ( $types as $ty ) {
		$cls = CFStudio_Field_Registry::get_class( $ty );
		$t( "Type '$ty' registered", (bool) $cls );
		if ( ! $cls ) {
			continue;
		}
		ob_start();
		$cls::render_settings( $f( $ty ), 'cfstudio_fields[0]' );
		$html = ob_get_clean();
		$t( "'$ty' settings render without notices", false !== strpos( $html, 'data-type="' . $ty . '"' ) || 'password' === $ty && false !== strpos( $html, 'password' ), substr( $html, 0, 80 ) );
		$clean = $cls::sanitize( array() );
		$t( "'$ty' sanitize() handles an empty post", is_array( $clean ) );
		$clean = $cls::sanitize( array( 'default_value' => '<b>x</b>', 'return_format' => 'evil', 'layout' => 'evil', 'min' => 'abc', 'step' => '-3' ) );
		$t( "'$ty' sanitize() tolerates junk settings", is_array( $clean ) && ( ! isset( $clean['return_format'] ) || in_array( $ty, array( 'time_picker', 'date_time_picker' ), true ) || 'evil' !== $clean['return_format'] ) && ( ! isset( $clean['layout'] ) || 'evil' !== $clean['layout'] ), $clean );
	}

	$module = 'Number';
	$n = $f( 'number', array( 'min' => 5, 'max' => 10 ) );
	$t( 'Number: valid kept', 7 === CFStudio_Field_Number::sanitize_value( '7', $n ) );
	$t( 'Number: below min clamped', 5 === CFStudio_Field_Number::sanitize_value( '1', $n ) );
	$t( 'Number: above max clamped', 10 === CFStudio_Field_Number::sanitize_value( '99', $n ) );
	$t( 'Number: decimal kept', 7.25 === CFStudio_Field_Number::sanitize_value( '7.25', $n ) );
	$t( 'Number: junk / array rejected', '' === CFStudio_Field_Number::sanitize_value( 'abc', $n ) && '' === CFStudio_Field_Number::sanitize_value( array( 1 ), $n ) && '' === CFStudio_Field_Number::sanitize_value( '1e999x', $n ) );
	$t( 'Number: zero is a real value', 0 === CFStudio_Field_Number::sanitize_value( '0', $f( 'number' ) ) );
	$t( 'Number: blank -> null on read', null === CFStudio_Field_Number::format_value( '', $n ) && 7 === CFStudio_Field_Number::format_value( '7', $n ) );
	$s = CFStudio_Field_Number::sanitize( array( 'min' => '2', 'max' => '9.5', 'step' => '0', 'prepend' => '<i>£</i>', 'append' => 'kg' ) );
	$t( 'Number: settings sanitised (step 0 dropped, tags stripped)', '' === $s['step'] && 2 === $s['min'] && 9.5 === $s['max'] && '£' === $s['prepend'], $s );
	ob_start();
	CFStudio_Field_Number::render_input( $f( 'number', array( 'prepend' => '£', 'append' => 'kg' ) ), '3', 'cfs[n]' );
	$html = ob_get_clean();
	$t( 'Number: renders prepend/append and type=number', false !== strpos( $html, 'type="number"' ) && false !== strpos( $html, '£' ) && false !== strpos( $html, 'kg' ) );

	$module = 'Range';
	$r = $f( 'range', array( 'min' => 10, 'max' => 20 ) );
	$t( 'Range: clamps to bounds', 20 === CFStudio_Field_Range::sanitize_value( '500', $r ) && 10 === CFStudio_Field_Range::sanitize_value( '-5', $r ) && 15 === CFStudio_Field_Range::sanitize_value( '15', $r ) );
	$s = CFStudio_Field_Range::sanitize( array( 'min' => '50', 'max' => '10' ) );
	$t( 'Range: max <= min is repaired', $s['max'] > $s['min'], $s );
	ob_start();
	CFStudio_Field_Range::render_input( $r, null, 'cfs[r]' );
	$html = ob_get_clean();
	$t( 'Range: renders slider + output, defaults to min', false !== strpos( $html, 'type="range"' ) && false !== strpos( $html, 'cfs-range-out' ) && false !== strpos( $html, 'value="10"' ) );

	$module = 'Email / URL / Password';
	$t( 'Email: valid kept, lower-case untouched', 'a.b@example.com' === CFStudio_Field_Email::sanitize_value( ' a.b@example.com ', array() ) );
	$t( 'Email: invalid rejected', '' === CFStudio_Field_Email::sanitize_value( 'not-an-email', array() ) && '' === CFStudio_Field_Email::sanitize_value( 'a@b', array() ) && '' === CFStudio_Field_Email::sanitize_value( array( 'a@b.com' ), array() ) );
	$t( 'URL: https kept', 'https://example.com/a?b=1' === CFStudio_Field_Url::sanitize_value( 'https://example.com/a?b=1', array() ) );
	$t( 'URL: javascript: and data: rejected', '' === CFStudio_Field_Url::sanitize_value( 'javascript:alert(1)', array() ) && '' === CFStudio_Field_Url::sanitize_value( 'data:text/html;base64,AAAA', array() ) );
	$t( 'URL: scheme-less becomes https-safe or empty (no raw script)', false === stripos( CFStudio_Field_Url::sanitize_value( 'x"><script>', array() ), '<script' ) );
	$t( 'Password: tags stripped, spaces inside kept', 'p@ss w0rd' === CFStudio_Field_Password::sanitize_value( '<b>p@ss w0rd</b>', array() ) );
	ob_start();
	CFStudio_Field_Password::render_input( $f( 'password' ), 's3cret"x', 'cfs[p]' );
	$html = ob_get_clean();
	$t( 'Password: input type=password and value escaped', false !== strpos( $html, 'type="password"' ) && false === strpos( $html, 's3cret"x' ) );

	$module = 'Checkbox / Radio / Button group';
	$choices = "red : Red\nblue : Blue\ngreen : Green";
	$cb      = $f( 'checkbox', array( 'choices' => $choices, 'return_format' => 'array' ) );
	$t( 'Checkbox: keeps valid, drops invalid, de-duplicates', array( 'red', 'green' ) === CFStudio_Field_Checkbox::sanitize_value( array( 'red', 'purple', 'green', 'red' ), $cb ) );
	$t( 'Checkbox: non-array becomes empty array', array() === CFStudio_Field_Checkbox::sanitize_value( 'red', $cb ) );
	$fmt = CFStudio_Field_Checkbox::format_value( array( 'red', 'green' ), $cb );
	$t( 'Checkbox: return array gives value+label', array( array( 'value' => 'red', 'label' => 'Red' ), array( 'value' => 'green', 'label' => 'Green' ) ) === $fmt, $fmt );
	$t( 'Checkbox: return label', array( 'Red' ) === CFStudio_Field_Checkbox::format_value( array( 'red' ), $f( 'checkbox', array( 'choices' => $choices, 'return_format' => 'label' ) ) ) );
	$t( 'Checkbox: return value', array( 'red' ) === CFStudio_Field_Checkbox::format_value( array( 'red' ), $f( 'checkbox', array( 'choices' => $choices ) ) ) );
	ob_start();
	CFStudio_Field_Checkbox::render_input( $f( 'checkbox', array( 'choices' => $choices, 'default_value' => 'red, blue', 'layout' => 'horizontal' ) ), null, 'cfs[c]' );
	$html = ob_get_clean();
	$t( 'Checkbox: defaults pre-ticked, horizontal layout, hidden empty field', 2 === substr_count( $html, "checked='checked'" ) && false !== strpos( $html, 'cfs-layout-horizontal' ) && false !== strpos( $html, 'type="hidden"' ), $html );
	ob_start();
	CFStudio_Field_Checkbox::render_input( $f( 'checkbox', array( 'choices' => $choices, 'default_value' => 'red' ) ), array(), 'cfs[c]' );
	$html = ob_get_clean();
	$t( 'Checkbox: a saved empty selection does not fall back to the default', 0 === substr_count( $html, "checked='checked'" ) );

	$rd = $f( 'radio', array( 'choices' => $choices, 'allow_null' => 1 ) );
	$t( 'Radio: valid kept, invalid dropped', 'blue' === CFStudio_Field_Radio::sanitize_value( 'blue', $rd ) && '' === CFStudio_Field_Radio::sanitize_value( 'pink', $rd ) && '' === CFStudio_Field_Radio::sanitize_value( array( 'blue' ), $rd ) );
	$t( 'Radio: format value / label / array', 'blue' === CFStudio_Field_Radio::format_value( 'blue', $rd ) && 'Blue' === CFStudio_Field_Radio::format_value( 'blue', $f( 'radio', array( 'choices' => $choices, 'return_format' => 'label' ) ) ) && array( 'value' => 'blue', 'label' => 'Blue' ) === CFStudio_Field_Radio::format_value( 'blue', $f( 'radio', array( 'choices' => $choices, 'return_format' => 'array' ) ) ) );
	$t( 'Radio: empty -> null', null === CFStudio_Field_Radio::format_value( '', $rd ) );
	ob_start();
	CFStudio_Field_Radio::render_input( $rd, 'blue', 'cfs[rd]' );
	$html = ob_get_clean();
	$t( 'Radio: one checked, Clear link when allow_null', 1 === substr_count( $html, "checked='checked'" ) && false !== strpos( $html, 'cfs-choice-clear' ) );

	$bg = $f( 'button_group', array( 'choices' => $choices ) );
	ob_start();
	CFStudio_Field_Button_Group::render_input( $bg, 'green', 'cfs[bg]' );
	$html = ob_get_clean();
	$t( 'Button group: renders buttons with the active one marked', 3 === substr_count( $html, 'class="button' ) && 1 === substr_count( $html, 'cfs-active' ) );
	$t( 'Button group: sanitises like radio', 'red' === CFStudio_Field_Button_Group::sanitize_value( 'red', $bg ) && '' === CFStudio_Field_Button_Group::sanitize_value( 'x', $bg ) );

	$module = 'File';
	$up = wp_upload_bits( 'cfs-qa-p9.pdf', null, "%PDF-1.4\n%QA\n" );
	$fid = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'QA pdf', 'post_status' => 'inherit' ), $up['file'] );
	update_post_meta( $fid, '_cfstudio_qa', 1 );
	$img = (int) get_posts( array( 'post_type' => 'attachment', 'meta_key' => '_cfstudio_qa', 'posts_per_page' => 1, 'fields' => 'ids' ) )[0];
	$ff  = $f( 'file' );
	$t( 'File: real attachment accepted', $fid === CFStudio_Field_File::sanitize_value( (string) $fid, $ff ) );
	$t( 'File: post id / junk / missing rejected', 0 === CFStudio_Field_File::sanitize_value( (string) $P1, $ff ) && 0 === CFStudio_Field_File::sanitize_value( 'abc', $ff ) && 0 === CFStudio_Field_File::sanitize_value( '999999', $ff ) );
	$t( 'File: extension allow-list enforced', $fid === CFStudio_Field_File::sanitize_value( $fid, $f( 'file', array( 'mime_types' => 'pdf, docx' ) ) ) && 0 === CFStudio_Field_File::sanitize_value( $fid, $f( 'file', array( 'mime_types' => 'zip' ) ) ) );
	$s = CFStudio_Field_File::sanitize( array( 'mime_types' => ' .PDF, docx ,, PDF;<b>' ) );
	$t( 'File: allowed types normalised', 'pdf, docx, b' === $s['mime_types'] || 'pdf, docx' === $s['mime_types'] || false === strpos( $s['mime_types'], '<' ), $s );
	$arr = CFStudio_Field_File::format_value( $fid, $ff );
	$t( 'File: array format has url, filename, filesize, mime', is_array( $arr ) && 'cfs-qa-p9.pdf' === $arr['filename'] && $arr['filesize'] > 0 && 'application/pdf' === $arr['mime'] && ! empty( $arr['url'] ), $arr );
	$t( 'File: url / id formats', is_string( CFStudio_Field_File::format_value( $fid, $f( 'file', array( 'return_format' => 'url' ) ) ) ) && $fid === CFStudio_Field_File::format_value( $fid, $f( 'file', array( 'return_format' => 'id' ) ) ) );
	$t( 'File: nothing stored -> null', null === CFStudio_Field_File::format_value( '', $ff ) && null === CFStudio_Field_File::format_value( 0, $ff ) );
	ob_start();
	CFStudio_Field_File::render_input( $ff, $fid, 'cfs[f]' );
	$html = ob_get_clean();
	$t( 'File: renders filename + remove button', false !== strpos( $html, 'cfs-qa-p9.pdf' ) && false !== strpos( $html, 'cfs-file-remove' ) );

	$module = 'Colour';
	$c = $f( 'color_picker' );
	$t( 'Color: #rgb and #rrggbb accepted, lower-cased', '#ff6600' === CFStudio_Field_Color_Picker::sanitize_value( '#FF6600', $c ) && '#f60' === CFStudio_Field_Color_Picker::sanitize_value( '#F60', $c ) );
	$t( 'Color: junk rejected', '' === CFStudio_Field_Color_Picker::sanitize_value( 'red', $c ) && '' === CFStudio_Field_Color_Picker::sanitize_value( '#12345', $c ) && '' === CFStudio_Field_Color_Picker::sanitize_value( '#ggg', $c ) && '' === CFStudio_Field_Color_Picker::sanitize_value( 'url(x)', $c ) && '' === CFStudio_Field_Color_Picker::sanitize_value( '"><script>', $c ) );
	$t( 'Color: rgba rejected unless opacity is enabled', '' === CFStudio_Field_Color_Picker::sanitize_value( 'rgba(1,2,3,0.5)', $c ) && 'rgba(1,2,3,0.5)' === CFStudio_Field_Color_Picker::sanitize_value( 'rgba(1, 2, 3, 0.5)', $f( 'color_picker', array( 'enable_alpha' => 1 ) ) ) );
	$t( 'Color: rgba out of range rejected', '' === CFStudio_Field_Color_Picker::sanitize_value( 'rgb(300,2,3)', $f( 'color_picker', array( 'enable_alpha' => 1 ) ) ) );
	ob_start();
	CFStudio_Field_Color_Picker::render_input( $c, '#336699', 'cfs[c]' );
	$html = ob_get_clean();
	$t( 'Color: swatch and text box rendered in sync', false !== strpos( $html, 'type="color"' ) && false !== strpos( $html, 'value="#336699"' ) );

	$module = 'Time / Date-time';
	$tp = $f( 'time_picker' );
	$t( 'Time: H:i and H:i:s normalised', '09:05:00' === CFStudio_Field_Time_Picker::sanitize_value( '09:05', $tp ) && '23:59:59' === CFStudio_Field_Time_Picker::sanitize_value( '23:59:59', $tp ) );
	$t( 'Time: out of range / junk rejected', '' === CFStudio_Field_Time_Picker::sanitize_value( '24:00', $tp ) && '' === CFStudio_Field_Time_Picker::sanitize_value( '12:60', $tp ) && '' === CFStudio_Field_Time_Picker::sanitize_value( 'noon', $tp ) && '' === CFStudio_Field_Time_Picker::sanitize_value( array(), $tp ) );
	$t( 'Time: formats', '2:30 pm' === CFStudio_Field_Time_Picker::format_value( '14:30:00', $tp ) && '14:30' === CFStudio_Field_Time_Picker::format_value( '14:30:00', $f( 'time_picker', array( 'return_format' => 'H:i' ) ) ) && '14:30:00' === CFStudio_Field_Time_Picker::format_value( '14:30:00', $f( 'time_picker', array( 'return_format' => 'H:i:s' ) ) ), CFStudio_Field_Time_Picker::format_value( '14:30:00', $tp ) );
	$t( 'Time: empty -> null', null === CFStudio_Field_Time_Picker::format_value( '', $tp ) );
	$dt = $f( 'date_time_picker' );
	$t( 'Date-time: datetime-local value normalised', '2026-11-07 14:30:00' === CFStudio_Field_Date_Time_Picker::sanitize_value( '2026-11-07T14:30', $dt ) && '2026-11-07 14:30:05' === CFStudio_Field_Date_Time_Picker::sanitize_value( '2026-11-07T14:30:05', $dt ) );
	$t( 'Date-time: impossible date/time rejected', '' === CFStudio_Field_Date_Time_Picker::sanitize_value( '2026-02-31T10:00', $dt ) && '' === CFStudio_Field_Date_Time_Picker::sanitize_value( '2026-11-07T25:00', $dt ) && '' === CFStudio_Field_Date_Time_Picker::sanitize_value( 'garbage', $dt ) );
	$t( 'Date-time: formats', '07/11/2026 2:30 pm' === CFStudio_Field_Date_Time_Picker::format_value( '2026-11-07 14:30:00', $dt ), CFStudio_Field_Date_Time_Picker::format_value( '2026-11-07 14:30:00', $dt ) );
	$t( 'Date-time: raw format & empty', '2026-11-07 14:30:00' === CFStudio_Field_Date_Time_Picker::format_value( '2026-11-07 14:30:00', $f( 'date_time_picker', array( 'return_format' => 'Y-m-d H:i:s' ) ) ) && null === CFStudio_Field_Date_Time_Picker::format_value( '', $dt ) );
	ob_start();
	CFStudio_Field_Date_Time_Picker::render_input( $dt, '2026-11-07 14:30:00', 'cfs[d]' );
	$html = ob_get_clean();
	$t( 'Date-time: renders datetime-local with the T value', false !== strpos( $html, 'datetime-local' ) && false !== strpos( $html, '2026-11-07T14:30:00' ) );

	$module = 'Link / oEmbed';
	$l = $f( 'link' );
	$t( 'Link: url + title + target kept', array( 'url' => 'https://a.test/x', 'title' => 'Go', 'target' => '_blank' ) === CFStudio_Field_Link::sanitize_value( array( 'url' => 'https://a.test/x', 'title' => '<b>Go</b>', 'target' => '_blank' ), $l ) );
	$t( 'Link: bad target ignored, empty url -> empty', '' === CFStudio_Field_Link::sanitize_value( array( 'url' => 'https://a.test', 'target' => '_top' ), $l )['target'] && array() === CFStudio_Field_Link::sanitize_value( array( 'url' => '', 'title' => 'x' ), $l ) && array() === CFStudio_Field_Link::sanitize_value( 'junk', $l ) );
	$t( 'Link: javascript: url rejected', array() === CFStudio_Field_Link::sanitize_value( array( 'url' => 'javascript:alert(1)', 'title' => 'x' ), $l ) );
	$t( 'Link: return array / url', 'https://a.test' === CFStudio_Field_Link::format_value( array( 'url' => 'https://a.test', 'title' => 't' ), $f( 'link', array( 'return_format' => 'url' ) ) ) && is_array( CFStudio_Field_Link::format_value( array( 'url' => 'https://a.test' ), $l ) ) && null === CFStudio_Field_Link::format_value( array(), $l ) );
	$t( 'oEmbed: url sanitised, junk dropped', 'https://www.youtube.com/watch?v=abc' === CFStudio_Field_Oembed::sanitize_value( 'https://www.youtube.com/watch?v=abc', array() ) && '' === CFStudio_Field_Oembed::sanitize_value( 'javascript:x', array() ) );
	$t( 'oEmbed: empty value -> null (no remote call)', null === CFStudio_Field_Oembed::format_value( '', array() ) );

	$module = 'Shortcode output';
	$sc = function ( $type, $value, $extra = array() ) use ( $f ) {
		return CFStudio_Shortcode::render_field( $f( $type, $extra ), $value );
	};
	$t( 'Shortcode: email is a mailto link, escaped', false !== strpos( $sc( 'email', 'a@b.com' ), 'href="mailto:a@b.com"' ) );
	$t( 'Shortcode: url link escaped', false === strpos( $sc( 'url', 'https://x.test/?a="><script>' ), '<script>' ) );
	$t( 'Shortcode: link opens in new tab with noopener', false !== strpos( $sc( 'link', array( 'url' => 'https://a.test', 'title' => 'Go', 'target' => '_blank' ) ), 'rel="noopener"' ) );
	$t( 'Shortcode: link title falls back to url', false !== strpos( $sc( 'link', array( 'url' => 'https://a.test' ) ), '>https://a.test<' ) );
	$t( 'Shortcode: checkbox lists labels', false !== strpos( $sc( 'checkbox', array( array( 'value' => 'r', 'label' => 'Red' ), array( 'value' => 'b', 'label' => 'Blue' ) ) ), 'Red, Blue' ) );
	$t( 'Shortcode: colour swatch with escaped style', false !== strpos( $sc( 'color_picker', '#ff6600' ), 'background:#ff6600' ) );
	$t( 'Shortcode: file link', false !== strpos( $sc( 'file', array( 'url' => 'https://a.test/x.pdf', 'filename' => 'x.pdf' ) ), 'x.pdf</a>' ) );
	$t( 'Shortcode: number 0 still renders', false !== strpos( $sc( 'number', 0 ), '>0<' ) );

	$module = 'Save round trip (post meta)';
	$defs = array(
		'p9_number' => array( 'number', array( 'min' => 1, 'max' => 9 ), '50', 9 ),
		'p9_email'  => array( 'email', array(), 'q@z.io', 'q@z.io' ),
		'p9_check'  => array( 'checkbox', array( 'choices' => $choices ), array( 'red', 'x', 'blue' ), array( 'red', 'blue' ) ),
		'p9_radio'  => array( 'radio', array( 'choices' => $choices ), 'green', 'green' ),
		'p9_color'  => array( 'color_picker', array(), '#ABCDEF', '#abcdef' ),
		'p9_time'   => array( 'time_picker', array( 'return_format' => 'H:i' ), '07:08', '07:08' ),
		'p9_link'   => array( 'link', array(), array( 'url' => 'https://a.test', 'title' => 'T', 'target' => '_blank' ), array( 'url' => 'https://a.test', 'title' => 'T', 'target' => '_blank' ) ),
	);
	foreach ( $defs as $name => $d ) {
		$field = $f( $d[0], array_merge( $d[1], array( 'name' => $name, 'key' => 'field_' . $name ) ) );
		$cls   = CFStudio_Field_Registry::get_class( $d[0] );
		$cls::save_value( $field, $d[2], $P1 );
		$got = CFStudio_Storage::get( $name, $P1 );
		$fmt = $cls::format_value( $got, $field );
		$t( "Saved $d[0] reads back through format_value", $d[3] === $fmt, array( 'got' => $got, 'fmt' => $fmt ) );
		$t( "Saved $d[0] stores its reference key", 'field_' . $name === CFStudio_Storage::get( '_' . $name, $P1 ) );
		$cls::delete_value( $field, $P1 );
		$t( "Deleted $d[0] leaves nothing behind", '' === CFStudio_Storage::get( $name, $P1 ) || array() === CFStudio_Storage::get( $name, $P1 ) );
	}
}
