<?php
/**
 * Textarea field type.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Textarea extends CFS_Field_Base {

	public static $type  = 'textarea';
	public static $label = 'Text Area';

	public static function defaults() {
		return array(
			'default_value' => '',
			'placeholder'   => '',
			'rows'          => 4,
		);
	}

	public static function render_settings( $field, $prefix ) {
		?>
		<div class="cfs-field-setting" data-type="textarea">
			<label><?php esc_html_e( 'Default Value', 'cfs' ); ?></label>
			<textarea name="<?php echo esc_attr( $prefix ); ?>[default_value]" rows="2"><?php echo esc_textarea( $field['default_value'] ?? '' ); ?></textarea>
		</div>
		<div class="cfs-field-setting" data-type="textarea">
			<label><?php esc_html_e( 'Placeholder Text', 'cfs' ); ?></label>
			<input type="text" name="<?php echo esc_attr( $prefix ); ?>[placeholder]" value="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>" />
		</div>
		<div class="cfs-field-setting" data-type="textarea">
			<label><?php esc_html_e( 'Rows', 'cfs' ); ?></label>
			<input type="number" min="1" name="<?php echo esc_attr( $prefix ); ?>[rows]" value="<?php echo esc_attr( $field['rows'] ?? 4 ); ?>" />
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		return array(
			'default_value' => isset( $raw['default_value'] ) ? sanitize_textarea_field( wp_unslash( $raw['default_value'] ) ) : '',
			'placeholder'   => isset( $raw['placeholder'] ) ? sanitize_text_field( wp_unslash( $raw['placeholder'] ) ) : '',
			'rows'          => isset( $raw['rows'] ) ? absint( $raw['rows'] ) : 4,
		);
	}
}
