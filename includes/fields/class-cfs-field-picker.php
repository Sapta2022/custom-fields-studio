<?php
/**
 * Pickers: color, time, date-time.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Color. Accepts #rgb, #rrggbb, and rgb()/rgba() when opacity is enabled. */
class CFS_Field_Color_Picker extends CFS_Field_Base {

	public static $type  = 'color_picker';
	public static $label = 'Color Picker';

	public static function defaults() {
		return array(
			'default_value' => '',
			'enable_alpha'  => 0,
		);
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_input( 'color_picker', $prefix, 'default_value', __( 'Default Value', 'custom-fields-studio' ), $field, 'text', __( 'e.g. #ff6600', 'custom-fields-studio' ) );
		self::setting_checkbox( 'color_picker', $prefix, 'enable_alpha', __( 'Allow rgba() values (opacity)', 'custom-fields-studio' ), $field );
	}

	public static function sanitize( $raw ) {
		$alpha = ! empty( $raw['enable_alpha'] ) ? 1 : 0;

		return array(
			'default_value' => self::clean( self::text_setting( $raw, 'default_value' ), $alpha ),
			'enable_alpha'  => $alpha,
		);
	}

	protected static function clean( $color, $alpha ) {
		$color = trim( (string) $color );
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ) {
			return strtolower( $color );
		}
		if ( $alpha && preg_match( '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(0|1|0?\.\d+)\s*)?\)$/i', $color, $m ) && max( $m[1], $m[2], $m[3] ) <= 255 ) {
			return preg_replace( '/\s+/', '', strtolower( $color ) );
		}

		return '';
	}

	public static function render_input( $field, $value, $name ) {
		if ( null === $value ) {
			$value = $field['default_value'] ?? '';
		}
		$hex = ( is_string( $value ) && preg_match( '/^#[0-9a-f]{6}$/i', $value ) ) ? $value : '#000000';
		?>
		<span class="cfs-color-field">
			<input type="color" class="cfs-color-pick" value="<?php echo esc_attr( $hex ); ?>" />
			<input type="text" class="cfs-color-text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" placeholder="#000000" size="14" />
		</span>
		<?php
	}

	public static function sanitize_value( $raw_value, $field ) {
		return is_scalar( $raw_value ) ? self::clean( $raw_value, ! empty( $field['enable_alpha'] ) ) : '';
	}
}

/** Time of day. Stored as H:i:s. */
class CFS_Field_Time_Picker extends CFS_Field_Base {

	public static $type  = 'time_picker';
	public static $label = 'Time Picker';

	public static function defaults() {
		return array( 'return_format' => 'g:i a' );
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_input( 'time_picker', $prefix, 'return_format', __( 'Return Format', 'custom-fields-studio' ), $field, 'text', __( 'A PHP date format, e.g. g:i a or H:i. Use H:i:s for the raw stored value.', 'custom-fields-studio' ), 'g:i a' );
	}

	public static function sanitize( $raw ) {
		$format = self::text_setting( $raw, 'return_format' );

		return array( 'return_format' => '' !== $format ? $format : 'g:i a' );
	}

	public static function render_input( $field, $value, $name ) {
		$t = self::parse( $value );
		printf( '<input type="time" step="1" class="cfs-time-picker" name="%1$s" value="%2$s" />', esc_attr( $name ), esc_attr( $t ? $t->format( 'H:i:s' ) : '' ) );
	}

	protected static function parse( $value ) {
		if ( ! is_scalar( $value ) || ! preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim( (string) $value ), $m ) ) {
			return null;
		}
		if ( $m[1] > 23 || $m[2] > 59 || ( isset( $m[3] ) && $m[3] > 59 ) ) {
			return null;
		}

		return DateTimeImmutable::createFromFormat( '!H:i:s', sprintf( '%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0 ), new DateTimeZone( 'UTC' ) );
	}

	public static function sanitize_value( $raw_value, $field ) {
		$t = self::parse( $raw_value );

		return $t ? $t->format( 'H:i:s' ) : '';
	}

	public static function format_value( $value, $field ) {
		$t = self::parse( $value );
		if ( ! $t ) {
			return null;
		}
		$format = ! empty( $field['return_format'] ) ? $field['return_format'] : 'g:i a';

		return 'H:i:s' === $format ? $t->format( 'H:i:s' ) : wp_date( $format, $t->getTimestamp(), new DateTimeZone( 'UTC' ) );
	}
}

/** Date and time. Stored as "Y-m-d H:i:s". */
class CFS_Field_Date_Time_Picker extends CFS_Field_Base {

	public static $type  = 'date_time_picker';
	public static $label = 'Date Time Picker';

	public static function defaults() {
		return array( 'return_format' => 'd/m/Y g:i a' );
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_input( 'date_time_picker', $prefix, 'return_format', __( 'Return Format', 'custom-fields-studio' ), $field, 'text', __( 'A PHP date format, e.g. d/m/Y g:i a. Use Y-m-d H:i:s for the raw stored value.', 'custom-fields-studio' ), 'd/m/Y g:i a' );
	}

	public static function sanitize( $raw ) {
		$format = self::text_setting( $raw, 'return_format' );

		return array( 'return_format' => '' !== $format ? $format : 'd/m/Y g:i a' );
	}

	public static function render_input( $field, $value, $name ) {
		$d = self::parse( $value );
		printf( '<input type="datetime-local" step="1" class="cfs-datetime-picker" name="%1$s" value="%2$s" />', esc_attr( $name ), esc_attr( $d ? $d->format( 'Y-m-d\TH:i:s' ) : '' ) );
	}

	protected static function parse( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = str_replace( 'T', ' ', trim( (string) $value ) );
		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d H:i' ) as $format ) {
			$d      = DateTimeImmutable::createFromFormat( '!' . $format, $value, new DateTimeZone( 'UTC' ) );
			$errors = DateTimeImmutable::getLastErrors();
			if ( $d && ( ! $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $d;
			}
		}

		return null;
	}

	public static function sanitize_value( $raw_value, $field ) {
		$d = self::parse( $raw_value );

		return $d ? $d->format( 'Y-m-d H:i:s' ) : '';
	}

	public static function format_value( $value, $field ) {
		$d = self::parse( $value );
		if ( ! $d ) {
			return null;
		}
		$format = ! empty( $field['return_format'] ) ? $field['return_format'] : 'd/m/Y g:i a';

		return 'Y-m-d H:i:s' === $format ? $d->format( 'Y-m-d H:i:s' ) : wp_date( $format, $d->getTimestamp(), new DateTimeZone( 'UTC' ) );
	}
}
