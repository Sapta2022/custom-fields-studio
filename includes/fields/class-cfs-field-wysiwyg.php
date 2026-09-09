<?php
/**
 * WYSIWYG (rich text editor) field type.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Wysiwyg extends CFS_Field_Base {

	public static $type  = 'wysiwyg';
	public static $label = 'WYSIWYG Editor';

	public static function defaults() {
		return array(
			'toolbar'      => 'full',
			'media_upload' => 1,
		);
	}

	public static function render_settings( $field, $prefix ) {
		$toolbar = $field['toolbar'] ?? 'full';
		?>
		<div class="cfs-field-setting" data-type="wysiwyg">
			<label><?php esc_html_e( 'Toolbar', 'cfs' ); ?></label>
			<select name="<?php echo esc_attr( $prefix ); ?>[toolbar]">
				<option value="full" <?php selected( $toolbar, 'full' ); ?>><?php esc_html_e( 'Full', 'cfs' ); ?></option>
				<option value="basic" <?php selected( $toolbar, 'basic' ); ?>><?php esc_html_e( 'Basic', 'cfs' ); ?></option>
			</select>
		</div>
		<div class="cfs-field-setting" data-type="wysiwyg">
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[media_upload]" value="1" <?php checked( ! empty( $field['media_upload'] ) ); ?> />
				<?php esc_html_e( 'Allow Media Upload Button', 'cfs' ); ?>
			</label>
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		$toolbar = isset( $raw['toolbar'] ) && 'basic' === $raw['toolbar'] ? 'basic' : 'full';

		return array(
			'toolbar'      => $toolbar,
			'media_upload' => ! empty( $raw['media_upload'] ) ? 1 : 0,
		);
	}
}
