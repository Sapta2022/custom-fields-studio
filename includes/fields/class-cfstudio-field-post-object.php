<?php
/**
 * Post Object and Page Link: pick one (or several) posts with the same
 * search picker the Relationship field uses.
 *
 * Stored as a post ID (single) or an array of IDs (multiple).
 *
 * @package Fieldloom
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFStudio_Field_Post_Object extends CFStudio_Field_Relationship {

	public static $type  = 'post_object';
	public static $label = 'Post Object';

	/** Page Link overrides this to return permalinks. */
	protected static $links = false;

	public static function defaults() {
		return array(
			'post_types'    => array( 'post' ),
			'return_format' => 'object',
			'multiple'      => 0,
		);
	}

	public static function render_settings( $field, $prefix ) {
		$type     = static::$type;
		$selected = (array) ( $field['post_types'] ?? static::defaults()['post_types'] );
		?>
		<div class="cfs-field-setting" data-type="<?php echo esc_attr( $type ); ?>">
			<label><?php esc_html_e( 'Post Types', 'fieldloom' ); ?></label>
			<?php foreach ( self::available_post_types() as $slug => $obj ) : ?>
				<label style="display:inline-block;margin-right:12px;">
					<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[post_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected, true ) ); ?> />
					<?php echo esc_html( $obj->labels->singular_name ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
		if ( ! static::$links ) {
			self::setting_select(
				$type,
				$prefix,
				'return_format',
				__( 'Return Format', 'fieldloom' ),
				$field,
				array(
					'object' => __( 'Post Object', 'fieldloom' ),
					'id'     => __( 'Post ID', 'fieldloom' ),
				),
				'object'
			);
		}
		self::setting_checkbox( $type, $prefix, 'multiple', __( 'Allow multiple values', 'fieldloom' ), $field );
	}

	public static function sanitize( $raw ) {
		$valid = array_keys( self::available_post_types() );
		$types = array();
		foreach ( (array) ( $raw['post_types'] ?? array() ) as $one ) {
			$one = is_scalar( $one ) ? sanitize_key( $one ) : '';
			if ( in_array( $one, $valid, true ) ) {
				$types[] = $one;
			}
		}
		$out = array(
			'post_types' => $types ? $types : static::defaults()['post_types'],
			'multiple'   => ! empty( $raw['multiple'] ) ? 1 : 0,
		);
		if ( ! static::$links ) {
			$out['return_format'] = self::pick( $raw, 'return_format', array( 'object', 'id' ), 'object' );
		}

		return $out;
	}

	public static function render_input( $field, $value, $name ) {
		$field['max'] = empty( $field['multiple'] ) ? 1 : 0;
		parent::render_input( $field, (array) $value, $name );
	}

	public static function sanitize_value( $raw_value, $field ) {
		$multiple = ! empty( $field['multiple'] );
		$ids      = self::clean_ids( (array) $raw_value, $multiple ? 0 : 1, $field );

		return $multiple ? $ids : ( $ids ? $ids[0] : '' );
	}

	public static function format_value( $value, $field ) {
		$ids = self::clean_ids( (array) $value, 0, $field );
		$out = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			// Unpublished posts are not shown to visitors who can't read them.
			if ( ! $post || ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $id ) ) ) {
				continue;
			}
			if ( static::$links ) {
				$out[] = get_permalink( $id );
			} else {
				$out[] = ( 'id' === ( $field['return_format'] ?? 'object' ) ) ? $id : $post;
			}
		}
		if ( ! $out ) {
			return null;
		}

		return empty( $field['multiple'] ) ? $out[0] : $out;
	}
}

class CFStudio_Field_Page_Link extends CFStudio_Field_Post_Object {

	public static $type  = 'page_link';
	public static $label = 'Page Link';

	protected static $links = true;

	public static function defaults() {
		return array(
			'post_types' => array( 'page' ),
			'multiple'   => 0,
		);
	}
}
