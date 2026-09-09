<?php
/**
 * Image field type. Value storage/media-frame wiring lands in Phase 2;
 * here we only define its builder settings.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Image extends CFS_Field_Base {

	public static $type  = 'image';
	public static $label = 'Image';

	public static function defaults() {
		return array(
			'return_format' => 'array',
			'preview_size'  => 'medium',
		);
	}

	public static function render_settings( $field, $prefix ) {
		$return_format = $field['return_format'] ?? 'array';
		$preview_size  = $field['preview_size'] ?? 'medium';
		?>
		<div class="cfs-field-setting" data-type="image">
			<label><?php esc_html_e( 'Return Format', 'cfs' ); ?></label>
			<select name="<?php echo esc_attr( $prefix ); ?>[return_format]">
				<option value="array" <?php selected( $return_format, 'array' ); ?>><?php esc_html_e( 'Image Array', 'cfs' ); ?></option>
				<option value="url" <?php selected( $return_format, 'url' ); ?>><?php esc_html_e( 'Image URL', 'cfs' ); ?></option>
				<option value="id" <?php selected( $return_format, 'id' ); ?>><?php esc_html_e( 'Attachment ID', 'cfs' ); ?></option>
			</select>
		</div>
		<div class="cfs-field-setting" data-type="image">
			<label><?php esc_html_e( 'Preview Size', 'cfs' ); ?></label>
			<select name="<?php echo esc_attr( $prefix ); ?>[preview_size]">
				<?php foreach ( get_intermediate_image_sizes() as $size ) : ?>
					<option value="<?php echo esc_attr( $size ); ?>" <?php selected( $preview_size, $size ); ?>><?php echo esc_html( $size ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		$allowed_formats = array( 'array', 'url', 'id' );
		$return_format   = isset( $raw['return_format'] ) && in_array( $raw['return_format'], $allowed_formats, true )
			? $raw['return_format']
			: 'array';

		return array(
			'return_format' => $return_format,
			'preview_size'  => isset( $raw['preview_size'] ) ? sanitize_key( $raw['preview_size'] ) : 'medium',
		);
	}
}
