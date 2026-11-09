<?php
/**
 * Gallery field: an ordered list of image attachments. Stored as an array
 * of attachment IDs.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Gallery extends CFS_Field_Base {

	public static $type  = 'gallery';
	public static $label = 'Gallery';

	public static function defaults() {
		return array(
			'return_format' => 'array',
			'preview_size'  => 'thumbnail',
			'min'           => 0,
			'max'           => 0,
		);
	}

	public static function render_settings( $field, $prefix ) {
		$return_format = $field['return_format'] ?? 'array';
		$preview_size  = $field['preview_size'] ?? 'thumbnail';
		?>
		<div class="cfs-field-setting" data-type="gallery">
			<label><?php esc_html_e( 'Return Format', 'custom-fields-studio' ); ?></label>
			<select name="<?php echo esc_attr( $prefix ); ?>[return_format]">
				<option value="array" <?php selected( $return_format, 'array' ); ?>><?php esc_html_e( 'Image Arrays', 'custom-fields-studio' ); ?></option>
				<option value="url" <?php selected( $return_format, 'url' ); ?>><?php esc_html_e( 'Image URLs', 'custom-fields-studio' ); ?></option>
				<option value="id" <?php selected( $return_format, 'id' ); ?>><?php esc_html_e( 'Attachment IDs', 'custom-fields-studio' ); ?></option>
			</select>
		</div>
		<div class="cfs-field-setting" data-type="gallery">
			<label><?php esc_html_e( 'Preview Size', 'custom-fields-studio' ); ?></label>
			<select name="<?php echo esc_attr( $prefix ); ?>[preview_size]">
				<?php foreach ( get_intermediate_image_sizes() as $size ) : ?>
					<option value="<?php echo esc_attr( $size ); ?>" <?php selected( $preview_size, $size ); ?>><?php echo esc_html( $size ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="cfs-field-setting" data-type="gallery">
			<label><?php esc_html_e( 'Minimum / Maximum Images (0 = no limit)', 'custom-fields-studio' ); ?></label>
			<input type="number" min="0" name="<?php echo esc_attr( $prefix ); ?>[min]" value="<?php echo esc_attr( (int) ( $field['min'] ?? 0 ) ); ?>" style="width:80px" />
			<input type="number" min="0" name="<?php echo esc_attr( $prefix ); ?>[max]" value="<?php echo esc_attr( (int) ( $field['max'] ?? 0 ) ); ?>" style="width:80px" />
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		$return_format = isset( $raw['return_format'] ) && in_array( $raw['return_format'], array( 'array', 'url', 'id' ), true )
			? $raw['return_format']
			: 'array';

		return array(
			'return_format' => $return_format,
			'preview_size'  => isset( $raw['preview_size'] ) ? sanitize_key( $raw['preview_size'] ) : 'thumbnail',
			'min'           => isset( $raw['min'] ) ? absint( $raw['min'] ) : 0,
			'max'           => isset( $raw['max'] ) ? absint( $raw['max'] ) : 0,
		);
	}

	public static function render_input( $field, $value, $name ) {
		$ids          = self::clean_ids( $value, 0 );
		$preview_size = $field['preview_size'] ?? 'thumbnail';
		?>
		<div class="cfs-gallery" data-name="<?php echo esc_attr( $name ); ?>" data-max="<?php echo esc_attr( (int) ( $field['max'] ?? 0 ) ); ?>" data-preview="<?php echo esc_attr( $preview_size ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="" />
			<ul class="cfs-gallery-list">
				<?php foreach ( $ids as $id ) : ?>
					<li class="cfs-gallery-item" data-id="<?php echo esc_attr( $id ); ?>">
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $id ); ?>" />
						<?php echo wp_get_attachment_image( $id, $preview_size ); // phpcs:ignore WordPress.Security.EscapeOutput -- core escapes. ?>
						<button type="button" class="cfs-gallery-remove" aria-label="<?php esc_attr_e( 'Remove image', 'custom-fields-studio' ); ?>">&times;</button>
					</li>
				<?php endforeach; ?>
			</ul>
			<p><button type="button" class="button cfs-gallery-add"><?php esc_html_e( 'Add Images', 'custom-fields-studio' ); ?></button></p>
		</div>
		<?php
	}

	public static function sanitize_value( $raw_value, $field ) {
		return self::clean_ids( $raw_value, (int) ( $field['max'] ?? 0 ) );
	}

	public static function format_value( $value, $field ) {
		$ids = self::clean_ids( $value, 0 );
		$out = array();
		foreach ( $ids as $id ) {
			$item = CFS_Field_Image::format_value( $id, $field );
			if ( null !== $item && false !== $item ) {
				$out[] = $item;
			}
		}

		return $out ? $out : null;
	}

	/**
	 * Unique, existing image attachment IDs in order; optionally capped.
	 */
	protected static function clean_ids( $value, $max ) {
		$ids = array();
		foreach ( (array) $value as $id ) {
			$id = is_scalar( $id ) ? absint( $id ) : 0;
			if ( $id && ! in_array( $id, $ids, true ) && wp_attachment_is_image( $id ) ) {
				$ids[] = $id;
			}
		}

		return $max > 0 ? array_slice( $ids, 0, $max ) : $ids;
	}
}
