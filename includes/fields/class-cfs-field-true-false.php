<?php
/**
 * True / False (toggle) field type.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_True_False extends CFS_Field_Base {

	public static $type  = 'true_false';
	public static $label = 'True / False';

	public static function defaults() {
		return array(
			'default_value' => 0,
			'ui_on'         => 'Yes',
			'ui_off'        => 'No',
		);
	}

	public static function render_settings( $field, $prefix ) {
		?>
		<div class="cfs-field-setting" data-type="true_false">
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[default_value]" value="1" <?php checked( ! empty( $field['default_value'] ) ); ?> />
				<?php esc_html_e( 'Default to "on"', 'cfs' ); ?>
			</label>
		</div>
		<div class="cfs-field-setting" data-type="true_false">
			<label><?php esc_html_e( 'On Text', 'cfs' ); ?></label>
			<input type="text" name="<?php echo esc_attr( $prefix ); ?>[ui_on]" value="<?php echo esc_attr( $field['ui_on'] ?? 'Yes' ); ?>" />
		</div>
		<div class="cfs-field-setting" data-type="true_false">
			<label><?php esc_html_e( 'Off Text', 'cfs' ); ?></label>
			<input type="text" name="<?php echo esc_attr( $prefix ); ?>[ui_off]" value="<?php echo esc_attr( $field['ui_off'] ?? 'No' ); ?>" />
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		return array(
			'default_value' => ! empty( $raw['default_value'] ) ? 1 : 0,
			'ui_on'         => isset( $raw['ui_on'] ) ? sanitize_text_field( wp_unslash( $raw['ui_on'] ) ) : 'Yes',
			'ui_off'        => isset( $raw['ui_off'] ) ? sanitize_text_field( wp_unslash( $raw['ui_off'] ) ) : 'No',
		);
	}
}
