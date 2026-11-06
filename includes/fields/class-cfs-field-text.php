<?php
/**
 * Text field type.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Text extends CFS_Field_Base {

	public static $type  = 'text';
	public static $label = 'Text';

	public static function defaults() {
		return array(
			'default_value' => '',
			'placeholder'   => '',
			'maxlength'     => '',
		);
	}

	public static function render_settings( $field, $prefix ) {
		?>
		<div class="cfs-field-setting" data-type="text">
			<label><?php esc_html_e( 'Default Value', 'cfs' ); ?></label>
			<input type="text" name="<?php echo esc_attr( $prefix ); ?>[default_value]" value="<?php echo esc_attr( $field['default_value'] ?? '' ); ?>" />
		</div>
		<div class="cfs-field-setting" data-type="text">
			<label><?php esc_html_e( 'Placeholder Text', 'cfs' ); ?></label>
			<input type="text" name="<?php echo esc_attr( $prefix ); ?>[placeholder]" value="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>" />
		</div>
		<div class="cfs-field-setting" data-type="text">
			<label><?php esc_html_e( 'Character Limit', 'cfs' ); ?></label>
			<input type="number" min="0" name="<?php echo esc_attr( $prefix ); ?>[maxlength]" value="<?php echo esc_attr( $field['maxlength'] ?? '' ); ?>" />
			<p class="description"><?php esc_html_e( 'Leave blank for no limit.', 'cfs' ); ?></p>
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		return array(
			'default_value' => isset( $raw['default_value'] ) ? sanitize_text_field( wp_unslash( $raw['default_value'] ) ) : '',
			'placeholder'   => isset( $raw['placeholder'] ) ? sanitize_text_field( wp_unslash( $raw['placeholder'] ) ) : '',
			'maxlength'     => isset( $raw['maxlength'] ) ? absint( $raw['maxlength'] ) : '',
		);
	}

	public static function render_input( $field, $value, $name ) {
		if ( null === $value || '' === $value ) {
			$value = $field['default_value'] ?? '';
		}
		$maxlength = ! empty( $field['maxlength'] ) ? ' maxlength="' . absint( $field['maxlength'] ) . '"' : '';
		printf(
			'<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s"%4$s />',
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $field['placeholder'] ?? '' ),
			$maxlength // phpcs:ignore WordPress.Security.EscapeOutput -- built from absint() above, not raw input.
		);
	}

	public static function sanitize_value( $raw_value, $field ) {
		$value = sanitize_text_field( $raw_value );
		if ( ! empty( $field['maxlength'] ) ) {
			$value = mb_substr( $value, 0, absint( $field['maxlength'] ) );
		}
		return $value;
	}
}
