<?php
/**
 * Relationship field: pick related posts (ordered). Stored as an array of
 * post IDs. The picker searches via an AJAX endpoint registered here.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Relationship extends CFS_Field_Base {

	public static $type  = 'relationship';
	public static $label = 'Relationship';

	const AJAX_ACTION = 'cfs_relationship_search';
	const NONCE       = 'cfs_relationship';

	public static function init() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_search' ) );
	}

	public static function defaults() {
		return array(
			'post_types'    => array( 'post' ),
			'return_format' => 'object',
			'max'           => 0,
		);
	}

	/** Public post types selectable in settings (excluding attachments). */
	public static function available_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );

		return $types;
	}

	public static function render_settings( $field, $prefix ) {
		$selected      = (array) ( $field['post_types'] ?? array( 'post' ) );
		$return_format = $field['return_format'] ?? 'object';
		?>
		<div class="cfs-field-setting" data-type="relationship">
			<label><?php esc_html_e( 'Post Types', 'custom-fields-studio' ); ?></label>
			<?php foreach ( self::available_post_types() as $slug => $obj ) : ?>
				<label style="display:inline-block;margin-right:12px;">
					<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[post_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected, true ) ); ?> />
					<?php echo esc_html( $obj->labels->singular_name ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<div class="cfs-field-setting" data-type="relationship">
			<label><?php esc_html_e( 'Return Format', 'custom-fields-studio' ); ?></label>
			<select name="<?php echo esc_attr( $prefix ); ?>[return_format]">
				<option value="object" <?php selected( $return_format, 'object' ); ?>><?php esc_html_e( 'Post Objects', 'custom-fields-studio' ); ?></option>
				<option value="id" <?php selected( $return_format, 'id' ); ?>><?php esc_html_e( 'Post IDs', 'custom-fields-studio' ); ?></option>
			</select>
		</div>
		<div class="cfs-field-setting" data-type="relationship">
			<label><?php esc_html_e( 'Maximum Posts (0 = no limit)', 'custom-fields-studio' ); ?></label>
			<input type="number" min="0" name="<?php echo esc_attr( $prefix ); ?>[max]" value="<?php echo esc_attr( (int) ( $field['max'] ?? 0 ) ); ?>" style="width:80px" />
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		$valid = array_keys( self::available_post_types() );
		$types = array();
		foreach ( (array) ( $raw['post_types'] ?? array() ) as $type ) {
			$type = sanitize_key( $type );
			if ( in_array( $type, $valid, true ) ) {
				$types[] = $type;
			}
		}

		return array(
			'post_types'    => $types ? $types : array( 'post' ),
			'return_format' => ( isset( $raw['return_format'] ) && 'id' === $raw['return_format'] ) ? 'id' : 'object',
			'max'           => isset( $raw['max'] ) ? absint( $raw['max'] ) : 0,
		);
	}

	public static function render_input( $field, $value, $name ) {
		$ids   = self::clean_ids( $value, 0, $field );
		$types = (array) ( $field['post_types'] ?? array( 'post' ) );
		?>
		<div class="cfs-relationship" data-name="<?php echo esc_attr( $name ); ?>" data-types="<?php echo esc_attr( implode( ',', $types ) ); ?>" data-max="<?php echo esc_attr( (int) ( $field['max'] ?? 0 ) ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="" />
			<div class="cfs-rel-col">
				<input type="search" class="cfs-rel-search" placeholder="<?php esc_attr_e( 'Search…', 'custom-fields-studio' ); ?>" />
				<ul class="cfs-rel-results"></ul>
			</div>
			<div class="cfs-rel-col">
				<ul class="cfs-rel-selected">
					<?php foreach ( $ids as $id ) : ?>
						<li data-id="<?php echo esc_attr( $id ); ?>">
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $id ); ?>" />
							<span class="cfs-rel-title"><?php echo esc_html( get_the_title( $id ) ); ?></span>
							<button type="button" class="cfs-rel-remove" aria-label="<?php esc_attr_e( 'Remove', 'custom-fields-studio' ); ?>">&times;</button>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	public static function sanitize_value( $raw_value, $field ) {
		return self::clean_ids( $raw_value, (int) ( $field['max'] ?? 0 ), $field );
	}

	public static function format_value( $value, $field ) {
		$ids = self::clean_ids( $value, 0, $field );
		if ( ! $ids ) {
			return null;
		}
		if ( 'id' === ( $field['return_format'] ?? 'object' ) ) {
			return $ids;
		}
		$posts = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			// Never expose unpublished posts to visitors who can't read them.
			if ( $post && ( 'publish' === $post->post_status || current_user_can( 'read_post', $id ) ) ) {
				$posts[] = $post;
			}
		}

		return $posts ? $posts : null;
	}

	/**
	 * Existing post IDs of allowed types, unique, in order, optionally capped.
	 */
	protected static function clean_ids( $value, $max, $field ) {
		$types = (array) ( $field['post_types'] ?? array( 'post' ) );
		$ids   = array();
		foreach ( (array) $value as $id ) {
			$id = is_scalar( $id ) ? absint( $id ) : 0;
			if ( $id && ! in_array( $id, $ids, true ) && in_array( get_post_type( $id ), $types, true ) ) {
				$ids[] = $id;
			}
		}

		return $max > 0 ? array_slice( $ids, 0, $max ) : $ids;
	}

	/** AJAX: search posts for the picker. */
	public static function ajax_search() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( null, 403 );
		}

		$valid = array_keys( self::available_post_types() );
		$types = array_values( array_intersect( array_map( 'sanitize_key', explode( ',', (string) wp_unslash( $_POST['types'] ?? '' ) ) ), $valid ) );
		if ( ! $types ) {
			wp_send_json_success( array() );
		}

		$query = new WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => array( 'publish', 'draft', 'pending', 'private' ),
				's'                   => sanitize_text_field( wp_unslash( $_POST['s'] ?? '' ) ),
				'posts_per_page'      => 20,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);

		$out = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$obj   = get_post_type_object( $post->post_type );
			$out[] = array(
				'id'    => $post->ID,
				'title' => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'custom-fields-studio' ),
				'type'  => $obj ? $obj->labels->singular_name : $post->post_type,
			);
		}

		wp_send_json_success( $out );
	}
}

CFS_Field_Relationship::init();
