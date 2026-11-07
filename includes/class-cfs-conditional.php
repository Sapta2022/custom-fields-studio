<?php
/**
 * Conditional logic: show a field only when other fields (of the same
 * level) have certain values.
 *
 * Stored on each field as:
 *   conditional_logic = array(
 *     'enabled' => 1,
 *     'match'   => 'all' | 'any',
 *     'rules'   => array( array( 'field' => 'other_name', 'operator' => '==', 'value' => 'x' ), ... ),
 *   )
 *
 * The browser evaluates it live (admin/js/meta-box.js). A hidden field is
 * only hidden, not disabled: whatever is in it is still saved.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Conditional {

	/** operator => label. */
	public static function operators() {
		return array(
			'=='       => __( 'is equal to', 'cfs' ),
			'!='       => __( 'is not equal to', 'cfs' ),
			'empty'    => __( 'is empty', 'cfs' ),
			'!empty'   => __( 'is not empty', 'cfs' ),
			'contains' => __( 'contains', 'cfs' ),
			'>'        => __( 'is greater than', 'cfs' ),
			'<'        => __( 'is less than', 'cfs' ),
		);
	}

	/** Clean the posted conditional_logic of one field. Empty array = none. */
	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		if ( empty( $raw['enabled'] ) || empty( $raw['rules'] ) || ! is_array( $raw['rules'] ) ) {
			return array();
		}

		$rules = array();
		foreach ( $raw['rules'] as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$field = isset( $rule['field'] ) && is_scalar( $rule['field'] ) ? sanitize_key( $rule['field'] ) : '';
			$op    = isset( $rule['operator'] ) && is_scalar( $rule['operator'] ) ? (string) $rule['operator'] : '==';
			if ( '' === $field ) {
				continue;
			}
			$rules[] = array(
				'field'    => $field,
				'operator' => array_key_exists( $op, self::operators() ) ? $op : '==',
				'value'    => isset( $rule['value'] ) && is_scalar( $rule['value'] ) ? sanitize_text_field( $rule['value'] ) : '',
			);
		}

		if ( ! $rules ) {
			return array();
		}

		return array(
			'enabled' => 1,
			'match'   => ( isset( $raw['match'] ) && 'any' === $raw['match'] ) ? 'any' : 'all',
			'rules'   => $rules,
		);
	}

	/** JSON for the data attribute on a field wrapper, or '' when none. */
	public static function data_attr( $field ) {
		$logic = $field['conditional_logic'] ?? array();
		if ( empty( $logic['enabled'] ) || empty( $logic['rules'] ) ) {
			return '';
		}

		return wp_json_encode(
			array(
				'match' => $logic['match'] ?? 'all',
				'rules' => $logic['rules'],
			)
		);
	}

	/** Builder UI for one field row (inside its generic settings). */
	public static function render_builder( $field, $prefix ) {
		$logic = $field['conditional_logic'] ?? array();
		$rules = isset( $logic['rules'] ) && is_array( $logic['rules'] ) ? array_values( $logic['rules'] ) : array();
		$name  = $prefix . '[conditional_logic]';
		?>
		<div class="cfs-field-setting-generic cfs-cond" data-next="<?php echo esc_attr( count( $rules ) ); ?>">
			<label>
				<input type="checkbox" class="cfs-cond-enabled" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( ! empty( $logic['enabled'] ) ); ?> />
				<?php esc_html_e( 'Conditional logic', 'cfs' ); ?>
			</label>
			<div class="cfs-cond-body"<?php echo ! empty( $logic['enabled'] ) ? '' : ' style="display:none;"'; ?>>
				<p>
					<?php esc_html_e( 'Show this field if', 'cfs' ); ?>
					<select name="<?php echo esc_attr( $name ); ?>[match]" class="cfs-cond-match">
						<option value="all" <?php selected( $logic['match'] ?? 'all', 'all' ); ?>><?php esc_html_e( 'all', 'cfs' ); ?></option>
						<option value="any" <?php selected( $logic['match'] ?? 'all', 'any' ); ?>><?php esc_html_e( 'any', 'cfs' ); ?></option>
					</select>
					<?php esc_html_e( 'of these rules match:', 'cfs' ); ?>
				</p>
				<div class="cfs-cond-rules">
					<?php foreach ( $rules as $i => $rule ) : ?>
						<?php self::render_rule( $name . '[rules][' . $i . ']', $rule ); ?>
					<?php endforeach; ?>
				</div>
				<p><button type="button" class="button cfs-cond-add"><?php esc_html_e( 'Add rule', 'cfs' ); ?></button></p>
			</div>
		</div>
		<?php
	}

	/** One rule row (also the shape the builder JS builds for new rules). */
	public static function render_rule( $name, $rule ) {
		$op = $rule['operator'] ?? '==';
		?>
		<div class="cfs-cond-rule">
			<select class="cfs-cond-field" name="<?php echo esc_attr( $name ); ?>[field]">
				<option value="<?php echo esc_attr( $rule['field'] ?? '' ); ?>"><?php echo esc_html( $rule['field'] ?? '' ); ?></option>
			</select>
			<select class="cfs-cond-op" name="<?php echo esc_attr( $name ); ?>[operator]">
				<?php foreach ( self::operators() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $op, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" class="cfs-cond-value" name="<?php echo esc_attr( $name ); ?>[value]" value="<?php echo esc_attr( $rule['value'] ?? '' ); ?>"<?php echo in_array( $op, array( 'empty', '!empty' ), true ) ? ' style="display:none;"' : ''; ?> />
			<button type="button" class="button-link cfs-cond-remove" aria-label="<?php esc_attr_e( 'Remove rule', 'cfs' ); ?>">&times;</button>
		</div>
		<?php
	}
}
