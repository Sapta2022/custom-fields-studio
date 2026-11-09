<?php
/**
 * Taxonomy field: pick terms of one taxonomy. Stored as an array of term
 * IDs; optionally also assigns the terms to the post.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Taxonomy extends CFS_Field_Base {

	public static $type  = 'taxonomy';
	public static $label = 'Taxonomy';

	public static function defaults() {
		return array(
			'taxonomy'      => 'category',
			'field_type'    => 'checkbox',
			'return_format' => 'object',
			'save_terms'    => 0,
		);
	}

	protected static function taxonomies() {
		return get_taxonomies( array( 'public' => true ), 'objects' );
	}

	public static function render_settings( $field, $prefix ) {
		$tax        = $field['taxonomy'] ?? 'category';
		$field_type = $field['field_type'] ?? 'checkbox';
		$return     = $field['return_format'] ?? 'object';
		?>
		<div class="cfs-field-setting" data-type="taxonomy">
			<label><?php esc_html_e( 'Taxonomy', 'custom-fields-studio' ); ?></label>
			<select name="<?php echo esc_attr( $prefix ); ?>[taxonomy]">
				<?php foreach ( self::taxonomies() as $slug => $obj ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $tax, $slug ); ?>><?php echo esc_html( $obj->labels->singular_name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="cfs-field-setting" data-type="taxonomy">
			<label><?php esc_html_e( 'Appearance', 'custom-fields-studio' ); ?></label>
			<select name="<?php echo esc_attr( $prefix ); ?>[field_type]">
				<option value="checkbox" <?php selected( $field_type, 'checkbox' ); ?>><?php esc_html_e( 'Checkboxes (multiple)', 'custom-fields-studio' ); ?></option>
				<option value="multi_select" <?php selected( $field_type, 'multi_select' ); ?>><?php esc_html_e( 'Multi Select', 'custom-fields-studio' ); ?></option>
				<option value="radio" <?php selected( $field_type, 'radio' ); ?>><?php esc_html_e( 'Radio (single)', 'custom-fields-studio' ); ?></option>
				<option value="select" <?php selected( $field_type, 'select' ); ?>><?php esc_html_e( 'Select (single)', 'custom-fields-studio' ); ?></option>
			</select>
		</div>
		<div class="cfs-field-setting" data-type="taxonomy">
			<label><?php esc_html_e( 'Return Format', 'custom-fields-studio' ); ?></label>
			<select name="<?php echo esc_attr( $prefix ); ?>[return_format]">
				<option value="object" <?php selected( $return, 'object' ); ?>><?php esc_html_e( 'Term Objects', 'custom-fields-studio' ); ?></option>
				<option value="id" <?php selected( $return, 'id' ); ?>><?php esc_html_e( 'Term IDs', 'custom-fields-studio' ); ?></option>
			</select>
		</div>
		<div class="cfs-field-setting" data-type="taxonomy">
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[save_terms]" value="1" <?php checked( ! empty( $field['save_terms'] ) ); ?> />
				<?php esc_html_e( 'Also assign the selected terms to the post', 'custom-fields-studio' ); ?>
			</label>
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		$tax = isset( $raw['taxonomy'] ) ? sanitize_key( $raw['taxonomy'] ) : 'category';
		if ( ! isset( self::taxonomies()[ $tax ] ) ) {
			$tax = 'category';
		}
		$ft = isset( $raw['field_type'] ) ? $raw['field_type'] : 'checkbox';

		return array(
			'taxonomy'      => $tax,
			'field_type'    => in_array( $ft, array( 'checkbox', 'multi_select', 'radio', 'select' ), true ) ? $ft : 'checkbox',
			'return_format' => ( isset( $raw['return_format'] ) && 'id' === $raw['return_format'] ) ? 'id' : 'object',
			'save_terms'    => ! empty( $raw['save_terms'] ) ? 1 : 0,
		);
	}

	protected static function is_single( $field ) {
		return in_array( $field['field_type'] ?? 'checkbox', array( 'radio', 'select' ), true );
	}

	public static function render_input( $field, $value, $name ) {
		$tax      = $field['taxonomy'] ?? 'category';
		$type     = $field['field_type'] ?? 'checkbox';
		$selected = self::clean_ids( $value, $field );
		$terms    = taxonomy_exists( $tax ) ? get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ) : array();
		$terms    = is_wp_error( $terms ) ? array() : $terms;
		?>
		<div class="cfs-taxonomy cfs-taxonomy-<?php echo esc_attr( $type ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="" />
			<?php if ( 'select' === $type || 'multi_select' === $type ) : ?>
				<select name="<?php echo esc_attr( $name ); ?>[]" <?php echo 'multi_select' === $type ? 'multiple size="6"' : ''; ?>>
					<?php if ( 'select' === $type ) : ?>
						<option value=""><?php esc_html_e( '— None —', 'custom-fields-studio' ); ?></option>
					<?php endif; ?>
					<?php foreach ( $terms as $term ) : ?>
						<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( $term->term_id, $selected, true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<ul class="cfs-taxonomy-list">
					<?php foreach ( $terms as $term ) : ?>
						<li><label>
							<input type="<?php echo 'radio' === $type ? 'radio' : 'checkbox'; ?>" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( in_array( $term->term_id, $selected, true ) ); ?> />
							<?php echo esc_html( $term->name ); ?>
						</label></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function sanitize_value( $raw_value, $field ) {
		return self::clean_ids( $raw_value, $field );
	}

	/** Store, then optionally assign terms to the post (top-level fields only). */
	public static function save_value( $field, $raw_posted, $ctx, $key = null ) {
		parent::save_value( $field, $raw_posted, $ctx, $key );

		if ( ! empty( $field['save_terms'] ) && is_int( $ctx ) && ( null === $key || $key === $field['name'] ) ) {
			wp_set_object_terms( $ctx, self::clean_ids( $raw_posted, $field ), $field['taxonomy'] ?? 'category' );
		}
	}

	public static function format_value( $value, $field ) {
		$ids = self::clean_ids( $value, $field );
		if ( ! $ids ) {
			return null;
		}

		if ( 'object' === ( $field['return_format'] ?? 'object' ) ) {
			$terms = array();
			foreach ( $ids as $id ) {
				$term = get_term( $id, $field['taxonomy'] ?? 'category' );
				if ( $term && ! is_wp_error( $term ) ) {
					$terms[] = $term;
				}
			}
			$ids = $terms;
		}

		if ( ! $ids ) {
			return null;
		}

		return self::is_single( $field ) ? $ids[0] : $ids;
	}

	/** Unique IDs of terms that exist in the field's taxonomy; one if single. */
	protected static function clean_ids( $value, $field ) {
		$tax = $field['taxonomy'] ?? 'category';
		$ids = array();
		foreach ( (array) $value as $id ) {
			$id = is_scalar( $id ) ? absint( $id ) : 0;
			if ( $id && ! in_array( $id, $ids, true ) && term_exists( $id, $tax ) ) {
				$ids[] = $id;
			}
		}

		return self::is_single( $field ) ? array_slice( $ids, 0, 1 ) : $ids;
	}
}
