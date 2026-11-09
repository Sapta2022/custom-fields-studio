<?php
/**
 * Date picker field. Stored as Ymd (e.g. 20261107) so values sort and
 * compare correctly in meta queries; edited with the browser's native
 * date input; formatted for templates with a PHP date format.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Date_Picker extends CFS_Field_Base {

	public static $type  = 'date_picker';
	public static $label = 'Date Picker';

	public static function defaults() {
		return array(
			'return_format' => 'd/m/Y',
		);
	}

	public static function render_settings( $field, $prefix ) {
		?>
		<div class="cfs-field-setting" data-type="date_picker">
			<label><?php esc_html_e( 'Return Format', 'custom-fields-studio' ); ?></label>
			<input type="text" name="<?php echo esc_attr( $prefix ); ?>[return_format]" value="<?php echo esc_attr( $field['return_format'] ?? 'd/m/Y' ); ?>" />
			<p class="description"><?php esc_html_e( 'A PHP date format, e.g. d/m/Y or F j, Y. Use Ymd for the raw stored value.', 'custom-fields-studio' ); ?></p>
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		$format = isset( $raw['return_format'] ) ? sanitize_text_field( wp_unslash( $raw['return_format'] ) ) : '';

		return array( 'return_format' => '' !== $format ? $format : 'd/m/Y' );
	}

	public static function render_input( $field, $value, $name ) {
		$date = self::parse( $value );
		?>
		<input type="date" class="cfs-date-picker" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $date ? $date->format( 'Y-m-d' ) : '' ); ?>" />
		<?php
	}

	/**
	 * Accepts Ymd or Y-m-d; returns the normalized Ymd or '' if invalid
	 * (including impossible dates like 2026-02-31).
	 */
	public static function sanitize_value( $raw_value, $field ) {
		$date = self::parse( $raw_value );

		return $date ? $date->format( 'Ymd' ) : '';
	}

	public static function format_value( $value, $field ) {
		$date = self::parse( $value );
		if ( ! $date ) {
			return null;
		}

		$format = ! empty( $field['return_format'] ) ? $field['return_format'] : 'd/m/Y';

		return 'Ymd' === $format ? $date->format( 'Ymd' ) : wp_date( $format, $date->getTimestamp(), new DateTimeZone( 'UTC' ) );
	}

	/**
	 * @return DateTimeImmutable|null Midnight UTC of the date, or null.
	 */
	protected static function parse( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );

		foreach ( array( 'Ymd', 'Y-m-d' ) as $format ) {
			$date   = DateTimeImmutable::createFromFormat( '!' . $format, $value, new DateTimeZone( 'UTC' ) );
			$errors = DateTimeImmutable::getLastErrors();
			if ( $date && ( ! $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $date;
			}
		}

		return null;
	}
}
