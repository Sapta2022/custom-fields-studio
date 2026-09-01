<?php
/**
 * Location rules — decide which admin screens a field group appears on.
 *
 * Rules are stored as an array of OR-groups, each containing AND-rules,
 * mirroring ACF's structure so it stays familiar and is easy to extend
 * with more params (page template, options page, taxonomy, ...) later:
 *
 * array(
 *   array( // Group 1 (AND within, OR between groups)
 *     array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ),
 *   ),
 *   array( // Group 2
 *     array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ),
 *   ),
 * )
 *
 * Phase 1 only supports the `post_type` param.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Location_Rules {

	/**
	 * Params available in the location rule builder. Keyed by param key,
	 * value is the label. Extend this array in later phases.
	 *
	 * @return array
	 */
	public static function get_params() {
		return array(
			'post_type' => __( 'Post Type', 'cfs' ),
		);
	}

	/**
	 * Values available for a given param, e.g. all public post types for
	 * the `post_type` param.
	 *
	 * @param string $param Param key.
	 * @return array value => label
	 */
	public static function get_values_for_param( $param ) {
		if ( 'post_type' === $param ) {
			$post_types = get_post_types( array( 'public' => true ), 'objects' );
			$values     = array();
			foreach ( $post_types as $post_type ) {
				if ( 'attachment' === $post_type->name ) {
					continue;
				}
				$values[ $post_type->name ] = $post_type->labels->singular_name;
			}
			return $values;
		}

		return array();
	}

	/**
	 * Render the location rules builder UI (groups of AND rules, OR'd
	 * together) for the field group edit screen.
	 *
	 * @param array $groups Saved rule groups.
	 */
	public static function render_builder( $groups ) {
		if ( empty( $groups ) ) {
			$groups = array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'post',
					),
				),
			);
		}
		?>
		<div id="cfs-location-rules" class="cfs-location-rules">
			<?php foreach ( $groups as $group_index => $rules ) : ?>
				<?php if ( $group_index > 0 ) : ?>
					<div class="cfs-location-or"><?php esc_html_e( 'OR', 'cfs' ); ?></div>
				<?php endif; ?>
				<div class="cfs-location-group" data-group="<?php echo esc_attr( $group_index ); ?>">
					<table class="cfs-location-rules-table">
						<tbody>
						<?php foreach ( $rules as $rule_index => $rule ) : ?>
							<?php self::render_rule_row( $group_index, $rule_index, $rule ); ?>
						<?php endforeach; ?>
						</tbody>
					</table>
					<button type="button" class="button cfs-add-rule"><?php esc_html_e( '+ Add Rule (AND)', 'cfs' ); ?></button>
				</div>
			<?php endforeach; ?>
			<p>
				<button type="button" class="button cfs-add-rule-group"><?php esc_html_e( '+ Add Rule Group (OR)', 'cfs' ); ?></button>
			</p>
		</div>

		<script type="text/html" id="tmpl-cfs-location-rule-row">
			<?php self::render_rule_row( '__GROUP__', '__RULE__', array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ); ?>
		</script>
		<?php
	}

	/**
	 * Render one AND-rule row: [param] [operator] [value].
	 *
	 * @param int|string $group_index Group index (or placeholder token for the JS template).
	 * @param int|string $rule_index  Rule index within the group (or placeholder token).
	 * @param array      $rule        Rule data: param, operator, value.
	 */
	protected static function render_rule_row( $group_index, $rule_index, $rule ) {
		$name_base = "cfs_location[{$group_index}][{$rule_index}]";
		$params    = self::get_params();
		$param     = $rule['param'] ?? 'post_type';
		$operator  = $rule['operator'] ?? '==';
		$value     = $rule['value'] ?? '';
		$values    = self::get_values_for_param( $param );
		?>
		<tr class="cfs-location-rule">
			<td>
				<select name="<?php echo esc_attr( $name_base ); ?>[param]" class="cfs-location-param">
					<?php foreach ( $params as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $param, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<select name="<?php echo esc_attr( $name_base ); ?>[operator]" class="cfs-location-operator">
					<option value="==" <?php selected( $operator, '==' ); ?>><?php esc_html_e( 'is equal to', 'cfs' ); ?></option>
					<option value="!=" <?php selected( $operator, '!=' ); ?>><?php esc_html_e( 'is not equal to', 'cfs' ); ?></option>
				</select>
			</td>
			<td>
				<select name="<?php echo esc_attr( $name_base ); ?>[value]" class="cfs-location-value">
					<?php foreach ( $values as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<button type="button" class="button-link cfs-remove-rule" aria-label="<?php esc_attr_e( 'Remove rule', 'cfs' ); ?>">&times;</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Sanitize posted location rule data.
	 *
	 * @param array $raw Raw $_POST['cfs_location'].
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$clean_groups = array();

		if ( ! is_array( $raw ) ) {
			return $clean_groups;
		}

		foreach ( $raw as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$clean_rules = array();
			foreach ( $group as $rule ) {
				if ( empty( $rule['param'] ) || empty( $rule['value'] ) ) {
					continue;
				}
				$clean_rules[] = array(
					'param'    => sanitize_key( $rule['param'] ),
					'operator' => '!=' === ( $rule['operator'] ?? '==' ) ? '!=' : '==',
					'value'    => sanitize_text_field( wp_unslash( $rule['value'] ) ),
				);
			}
			if ( ! empty( $clean_rules ) ) {
				$clean_groups[] = $clean_rules;
			}
		}

		return $clean_groups;
	}

	/**
	 * Whether a set of location rule groups matches the given post type.
	 * (Phase 2 will call this when deciding whether to show a field
	 * group's meta box on a post edit screen.)
	 *
	 * @param array  $groups    Saved rule groups.
	 * @param string $post_type Post type to test against.
	 * @return bool
	 */
	public static function matches_post_type( $groups, $post_type ) {
		if ( empty( $groups ) ) {
			return false;
		}

		foreach ( $groups as $rules ) {
			$group_matches = true;
			foreach ( $rules as $rule ) {
				if ( 'post_type' !== $rule['param'] ) {
					continue;
				}
				$is_equal = ( $rule['value'] === $post_type );
				$passes   = ( '==' === $rule['operator'] ) ? $is_equal : ! $is_equal;
				if ( ! $passes ) {
					$group_matches = false;
					break;
				}
			}
			if ( $group_matches ) {
				return true; // Any matching OR-group is enough.
			}
		}

		return false;
	}
}
