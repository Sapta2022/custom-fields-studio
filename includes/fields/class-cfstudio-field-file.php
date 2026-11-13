<?php
/**
 * File field. Stores the attachment ID; picked with the media modal.
 *
 * @package Fieldloom
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFStudio_Field_File extends CFStudio_Field_Base {

	public static $type  = 'file';
	public static $label = 'File';

	public static function defaults() {
		return array(
			'return_format' => 'array',
			'mime_types'    => '',
		);
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_select(
			'file',
			$prefix,
			'return_format',
			__( 'Return Format', 'fieldloom' ),
			$field,
			array(
				'array' => __( 'File Array', 'fieldloom' ),
				'url'   => __( 'File URL', 'fieldloom' ),
				'id'    => __( 'Attachment ID', 'fieldloom' ),
			),
			'array'
		);
		self::setting_input( 'file', $prefix, 'mime_types', __( 'Allowed File Types', 'fieldloom' ), $field, 'text', __( 'Comma-separated extensions, e.g. pdf, docx, zip. Blank allows any file type WordPress accepts.', 'fieldloom' ) );
	}

	public static function sanitize( $raw ) {
		$types = array_filter( array_map( 'sanitize_key', preg_split( '/[\s,.]+/', self::text_setting( $raw, 'mime_types' ) ) ) );

		return array(
			'return_format' => self::pick( $raw, 'return_format', array( 'array', 'url', 'id' ), 'array' ),
			'mime_types'    => implode( ', ', array_unique( $types ) ),
		);
	}

	protected static function allowed( $attachment_id, $field ) {
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}
		if ( empty( $field['mime_types'] ) ) {
			return true;
		}
		$ext = strtolower( pathinfo( (string) get_attached_file( $attachment_id ), PATHINFO_EXTENSION ) );

		return in_array( $ext, array_map( 'trim', explode( ',', $field['mime_types'] ) ), true );
	}

	public static function render_input( $field, $value, $name ) {
		$id       = absint( $value );
		$has_file = self::allowed( $id, $field );
		?>
		<div class="cfs-file-field" data-mime="<?php echo esc_attr( $field['mime_types'] ?? '' ); ?>">
			<input type="hidden" class="cfs-file-value" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $has_file ? $id : '' ); ?>" />
			<p class="cfs-file-info"<?php echo $has_file ? '' : ' style="display:none;"'; ?>>
				<span class="dashicons dashicons-media-default"></span>
				<a class="cfs-file-name" href="<?php echo esc_url( $has_file ? wp_get_attachment_url( $id ) : '' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $has_file ? wp_basename( (string) get_attached_file( $id ) ) : '' ); ?></a>
			</p>
			<p>
				<button type="button" class="button cfs-file-select"<?php echo $has_file ? ' style="display:none;"' : ''; ?>><?php esc_html_e( 'Select File', 'fieldloom' ); ?></button>
				<button type="button" class="button cfs-file-remove"<?php echo $has_file ? '' : ' style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'fieldloom' ); ?></button>
			</p>
		</div>
		<?php
	}

	public static function sanitize_value( $raw_value, $field ) {
		$id = absint( $raw_value );

		return self::allowed( $id, $field ) ? $id : 0;
	}

	public static function format_value( $value, $field ) {
		$id = absint( $value );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return null;
		}
		$format = $field['return_format'] ?? 'array';
		if ( 'id' === $format ) {
			return $id;
		}
		$url = wp_get_attachment_url( $id );
		if ( 'url' === $format ) {
			return $url;
		}
		$path = get_attached_file( $id );

		return array(
			'ID'       => $id,
			'url'      => $url,
			'title'    => get_the_title( $id ),
			'filename' => $path ? wp_basename( $path ) : '',
			'filesize' => ( $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0,
			'mime'     => get_post_mime_type( $id ),
		);
	}
}
