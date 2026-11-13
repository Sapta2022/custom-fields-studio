<?php
/**
 * Google Map field: an address with latitude / longitude / zoom.
 *
 * The value is always usable without a Google API key: the editor shows the
 * address, latitude and longitude as plain inputs. With an API key (Field
 * Groups → Tools, or the `cfstudio/google_maps_api_key` filter) the editor also
 * shows a map: type to search (Places autocomplete), click the map or drag
 * the marker to set the position.
 *
 * Stored as an array: address, lat, lng, zoom. On the front end the shortcode
 * renders a Google Maps embed (no API key needed).
 *
 * @package Fieldloom
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFStudio_Field_Google_Map extends CFStudio_Field_Base {

	public static $type  = 'google_map';
	public static $label = 'Google Map';

	/** The Google Maps JavaScript API key, or ''. */
	public static function api_key() {
		return (string) apply_filters( 'cfstudio/google_maps_api_key', get_option( 'cfstudio_google_maps_api_key', '' ) );
	}

	public static function defaults() {
		return array(
			'center_lat' => '',
			'center_lng' => '',
			'zoom'       => 14,
			'height'     => 300,
		);
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_input( 'google_map', $prefix, 'center_lat', __( 'Default Center — Latitude', 'fieldloom' ), $field );
		self::setting_input( 'google_map', $prefix, 'center_lng', __( 'Default Center — Longitude', 'fieldloom' ), $field );
		self::number_input( 'google_map', $prefix, 'zoom', __( 'Default Zoom (1–21)', 'fieldloom' ), $field, 14 );
		self::number_input( 'google_map', $prefix, 'height', __( 'Map Height (px)', 'fieldloom' ), $field, 300 );
	}

	/** A plain number input in a settings block of one type. */
	protected static function number_input( $type, $prefix, $key, $label, $field, $default ) {
		$value = isset( $field[ $key ] ) && '' !== $field[ $key ] ? $field[ $key ] : $default;
		?>
		<div class="cfs-field-setting" data-type="<?php echo esc_attr( $type ); ?>">
			<label><?php echo esc_html( $label ); ?></label>
			<input type="number" name="<?php echo esc_attr( $prefix . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" />
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		$lat = self::coordinate( $raw['center_lat'] ?? '', 90 );
		$lng = self::coordinate( $raw['center_lng'] ?? '', 180 );

		return array(
			'center_lat' => ( '' !== $lat && '' !== $lng ) ? $lat : '',
			'center_lng' => ( '' !== $lat && '' !== $lng ) ? $lng : '',
			'zoom'       => self::clamp_int( $raw['zoom'] ?? 14, 1, 21, 14 ),
			'height'     => self::clamp_int( $raw['height'] ?? 300, 100, 1200, 300 ),
		);
	}

	/** A latitude/longitude within ±$limit as a float, or ''. */
	protected static function coordinate( $value, $limit ) {
		if ( ! is_scalar( $value ) || ! is_numeric( trim( (string) $value ) ) ) {
			return '';
		}
		$number = (float) $value;

		return abs( $number ) <= $limit ? round( $number, 7 ) : '';
	}

	protected static function clamp_int( $value, $min, $max, $default ) {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return $default;
		}

		return max( $min, min( $max, (int) $value ) );
	}

	public static function render_input( $field, $value, $name ) {
		$value = is_array( $value ) ? $value : array();
		$lat   = $value['lat'] ?? '';
		$lng   = $value['lng'] ?? '';
		$zoom  = $value['zoom'] ?? ( $field['zoom'] ?? 14 );
		// The default centre only positions the map view; it is never saved as a value.
		?>
		<div class="cfs-gmap" data-key="<?php echo esc_attr( self::api_key() ); ?>" data-center-lat="<?php echo esc_attr( $field['center_lat'] ?? '' ); ?>" data-center-lng="<?php echo esc_attr( $field['center_lng'] ?? '' ); ?>">
			<input type="text" class="regular-text cfs-gmap-address" name="<?php echo esc_attr( $name ); ?>[address]" value="<?php echo esc_attr( $value['address'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Address (search)', 'fieldloom' ); ?>" />
			<p class="cfs-gmap-coords">
				<label><?php esc_html_e( 'Latitude', 'fieldloom' ); ?> <input type="text" class="cfs-gmap-lat" name="<?php echo esc_attr( $name ); ?>[lat]" value="<?php echo esc_attr( $lat ); ?>" size="12" /></label>
				<label><?php esc_html_e( 'Longitude', 'fieldloom' ); ?> <input type="text" class="cfs-gmap-lng" name="<?php echo esc_attr( $name ); ?>[lng]" value="<?php echo esc_attr( $lng ); ?>" size="12" /></label>
				<label><?php esc_html_e( 'Zoom', 'fieldloom' ); ?> <input type="number" min="1" max="21" class="cfs-gmap-zoom" name="<?php echo esc_attr( $name ); ?>[zoom]" value="<?php echo esc_attr( (int) $zoom ); ?>" style="width:5em" /></label>
				<button type="button" class="button-link cfs-gmap-clear"><?php esc_html_e( 'Clear', 'fieldloom' ); ?></button>
			</p>
			<div class="cfs-gmap-canvas" style="height:<?php echo esc_attr( (int) ( $field['height'] ?? 300 ) ); ?>px;display:none"></div>
		</div>
		<?php
	}

	public static function sanitize_value( $raw_value, $field ) {
		$raw = is_array( $raw_value ) ? $raw_value : array();
		$lat = self::coordinate( $raw['lat'] ?? '', 90 );
		$lng = self::coordinate( $raw['lng'] ?? '', 180 );
		if ( '' === $lat || '' === $lng ) {
			return array(); // A map without a position is empty.
		}

		return array(
			'address' => isset( $raw['address'] ) && is_scalar( $raw['address'] ) ? sanitize_text_field( $raw['address'] ) : '',
			'lat'     => $lat,
			'lng'     => $lng,
			'zoom'    => self::clamp_int( $raw['zoom'] ?? ( $field['zoom'] ?? 14 ), 1, 21, 14 ),
		);
	}

	public static function format_value( $value, $field ) {
		if ( ! is_array( $value ) || ! isset( $value['lat'], $value['lng'] ) ) {
			return null;
		}

		return array(
			'address' => (string) ( $value['address'] ?? '' ),
			'lat'     => (float) $value['lat'],
			'lng'     => (float) $value['lng'],
			'zoom'    => (int) ( $value['zoom'] ?? 14 ),
		);
	}

	/**
	 * Embed HTML for a formatted map value (used by the [cfstudio] shortcode):
	 * Google's keyless embed iframe.
	 *
	 * @param array $map    Formatted value.
	 * @param int   $height Pixel height.
	 * @return string
	 */
	public static function embed_html( $map, $height = 300 ) {
		$src = add_query_arg(
			array(
				'q'      => $map['lat'] . ',' . $map['lng'],
				'z'      => $map['zoom'],
				'output' => 'embed',
			),
			'https://www.google.com/maps'
		);

		return '<div class="cfs-out-field cfs-out-map"><iframe title="' . esc_attr( '' !== $map['address'] ? $map['address'] : __( 'Map', 'fieldloom' ) ) . '" src="' . esc_url( $src ) . '" width="100%" height="' . (int) $height . '" style="border:0" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe></div>';
	}
}
