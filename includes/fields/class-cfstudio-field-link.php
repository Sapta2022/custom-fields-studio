<?php
/**
 * Link (url + title + target) and oEmbed (a URL rendered as embedded media).
 *
 * @package Fieldloom
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFStudio_Field_Link extends CFStudio_Field_Base {

	public static $type  = 'link';
	public static $label = 'Link';

	public static function defaults() {
		return array( 'return_format' => 'array' );
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_select(
			'link',
			$prefix,
			'return_format',
			__( 'Return Format', 'fieldloom' ),
			$field,
			array(
				'array' => __( 'Link Array', 'fieldloom' ),
				'url'   => __( 'Link URL', 'fieldloom' ),
			),
			'array'
		);
	}

	public static function sanitize( $raw ) {
		return array( 'return_format' => self::pick( $raw, 'return_format', array( 'array', 'url' ), 'array' ) );
	}

	public static function render_input( $field, $value, $name ) {
		$value = is_array( $value ) ? $value : array();
		?>
		<div class="cfs-link-field">
			<input type="url" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( $value['url'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'URL', 'fieldloom' ); ?>" class="regular-text" />
			<input type="text" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $value['title'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Link text', 'fieldloom' ); ?>" class="regular-text" />
			<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[target]" value="_blank" <?php checked( '_blank', $value['target'] ?? '' ); ?> /> <?php esc_html_e( 'Open in new tab', 'fieldloom' ); ?></label>
		</div>
		<?php
	}

	public static function sanitize_value( $raw_value, $field ) {
		$raw = is_array( $raw_value ) ? $raw_value : array();
		$url = isset( $raw['url'] ) && is_scalar( $raw['url'] ) ? esc_url_raw( trim( (string) $raw['url'] ) ) : '';
		if ( '' === $url ) {
			return array();
		}

		return array(
			'url'    => $url,
			'title'  => isset( $raw['title'] ) && is_scalar( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '',
			'target' => ( isset( $raw['target'] ) && '_blank' === $raw['target'] ) ? '_blank' : '',
		);
	}

	public static function format_value( $value, $field ) {
		if ( ! is_array( $value ) || empty( $value['url'] ) ) {
			return null;
		}

		return 'url' === ( $field['return_format'] ?? 'array' ) ? $value['url'] : array(
			'url'    => $value['url'],
			'title'  => $value['title'] ?? '',
			'target' => $value['target'] ?? '',
		);
	}
}

class CFStudio_Field_Oembed extends CFStudio_Field_Base {

	public static $type  = 'oembed';
	public static $label = 'oEmbed';

	public static function defaults() {
		return array( 'placeholder' => '' );
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_input( 'oembed', $prefix, 'placeholder', __( 'Placeholder Text', 'fieldloom' ), $field );
	}

	public static function sanitize( $raw ) {
		return array( 'placeholder' => self::text_setting( $raw, 'placeholder' ) );
	}

	public static function render_input( $field, $value, $name ) {
		printf(
			'<input type="url" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" />',
			esc_attr( $name ),
			esc_attr( is_scalar( $value ) ? $value : '' ),
			esc_attr( $field['placeholder'] ?? 'https://www.youtube.com/watch?v=…' )
		);
	}

	public static function sanitize_value( $raw_value, $field ) {
		return is_scalar( $raw_value ) ? esc_url_raw( trim( (string) $raw_value ) ) : '';
	}

	/** Returns the embed HTML, or null when the URL isn't embeddable. */
	public static function format_value( $value, $field ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$html = wp_oembed_get( $value );

		return $html ? $html : null;
	}
}
