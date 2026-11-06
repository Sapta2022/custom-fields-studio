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

	public static function render_input( $field, $value, $name ) {
		// wp_editor() prints its own <textarea name="..."> for us, so it
		// needs a unique, valid HTML id — derive one from $name since field
		// names are already unique within a post's field set.
		$editor_id = 'cfs_editor_' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( $name ) );

		wp_editor(
			(string) $value,
			$editor_id,
			array(
				'textarea_name' => $name,
				'textarea_rows' => 8,
				'teeny'         => ( 'basic' === ( $field['toolbar'] ?? 'full' ) ),
				'media_buttons' => ! empty( $field['media_upload'] ),
			)
		);
	}

	public static function sanitize_value( $raw_value, $field ) {
		// wp_editor() content is rich HTML — sanitize as post content, same
		// as WordPress does for the main post editor, rather than stripping
		// tags with sanitize_text_field().
		return wp_kses_post( $raw_value );
	}
}
