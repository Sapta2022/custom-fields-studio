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

	/**
	 * Stored value is always the attachment ID (a string in the hidden
	 * input, cast to int on save). Renders a CFS-media-field widget: a
	 * preview thumbnail, Select/Remove buttons, and a hidden ID input.
	 * The click behavior is wired up by admin/js/meta-box.js, which opens
	 * wp.media() and fills the hidden input + preview on selection.
	 *
	 * @param array  $field Field config.
	 * @param mixed  $value Stored attachment ID, or empty.
	 * @param string $name  HTML name attribute for the hidden input.
	 */
	public static function render_input( $field, $value, $name ) {
		$attachment_id = absint( $value );
		$preview_size  = $field['preview_size'] ?? 'medium';
		$image_html    = '';

		if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
			$image_html = wp_get_attachment_image( $attachment_id, $preview_size );
		}
		?>
		<div class="cfs-image-field">
			<input type="hidden" class="cfs-image-value" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $attachment_id ); ?>" />
			<div class="cfs-image-preview"<?php echo $image_html ? '' : ' style="display:none;"'; ?>>
				<?php echo $image_html; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image() output is already escaped by core. ?>
			</div>
			<p class="cfs-image-actions">
				<button type="button" class="button cfs-image-select" <?php echo $image_html ? 'style="display:none;"' : ''; ?>><?php esc_html_e( 'Select Image', 'cfs' ); ?></button>
				<button type="button" class="button cfs-image-remove" <?php echo $image_html ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'cfs' ); ?></button>
			</p>
		</div>
		<?php
	}

	public static function sanitize_value( $raw_value, $field ) {
		$attachment_id = absint( $raw_value );
		// Only store IDs that actually point at a real image attachment —
		// guards against a stale/deleted ID or a tampered hidden field.
		return ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) ? $attachment_id : 0;
	}

	public static function format_value( $value, $field ) {
		$attachment_id = absint( $value );
		if ( ! $attachment_id ) {
			return null;
		}

		$return_format = $field['return_format'] ?? 'array';

		if ( 'id' === $return_format ) {
			return $attachment_id;
		}

		if ( 'url' === $return_format ) {
			return wp_get_attachment_url( $attachment_id );
		}

		// 'array' — an ACF-style shape: familiar to anyone used to ACF's
		// image field, easy to pull url/alt/sizes from in a template.
		$image_src = wp_get_attachment_image_src( $attachment_id, 'full' );
		if ( ! $image_src ) {
			return null;
		}

		return array(
			'ID'    => $attachment_id,
			'url'   => $image_src[0],
			'width' => $image_src[1],
			'height' => $image_src[2],
			'alt'   => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'title' => get_the_title( $attachment_id ),
			'sizes' => array(
				'thumbnail' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
				'medium'    => wp_get_attachment_image_url( $attachment_id, 'medium' ),
				'large'     => wp_get_attachment_image_url( $attachment_id, 'large' ),
			),
		);
	}
}
