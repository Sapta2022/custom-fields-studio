<?php
/**
 * Clone field: reuse the fields of other field groups.
 *
 * A clone field stores nothing itself. When field groups are loaded
 * (CFS_Field_Group::get_active_groups()) every clone field is replaced by
 * the fields it points at, so the rest of the plugin — meta boxes, saving,
 * the template API, the REST API — only ever sees ordinary fields.
 *
 *  - display "seamless": the cloned fields appear in place, as if they had
 *    been created in this group (optionally with the clone's name as a
 *    name prefix, e.g. "seo_title").
 *  - display "group": the cloned fields are wrapped in one Group field named
 *    after the clone field.
 *
 * Clones refer to field groups by group KEY (stable across JSON import/export).
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Clone extends CFS_Field_Layout_Base {

	public static $type  = 'clone';
	public static $label = 'Clone';

	public static function defaults() {
		return array(
			'clone'       => array(),
			'display'     => 'group',
			'prefix_name' => 0,
		);
	}

	public static function render_settings( $field, $prefix ) {
		$selected = isset( $field['clone'] ) && is_array( $field['clone'] ) ? $field['clone'] : array();
		$current  = (int) get_the_ID();
		$groups   = get_posts(
			array(
				'post_type'   => CFS_Field_Group::POST_TYPE,
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		?>
		<div class="cfs-field-setting" data-type="clone">
			<label><?php esc_html_e( 'Field groups to clone', 'custom-fields-studio' ); ?></label>
			<?php $shown = 0; ?>
			<?php foreach ( $groups as $group ) : ?>
				<?php
				if ( $group->ID === $current ) {
					continue;
				}
				++$shown;
				$key = CFS_Field_Group::get_group_key( $group->ID );
				?>
				<label class="cfs-inline-check">
					<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[clone][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?> />
					<?php echo esc_html( $group->post_title ); ?>
				</label><br />
			<?php endforeach; ?>
			<?php if ( ! $shown ) : ?>
				<p class="description"><?php esc_html_e( 'There are no other field groups to clone yet.', 'custom-fields-studio' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		self::setting_select(
			'clone',
			$prefix,
			'display',
			__( 'Display', 'custom-fields-studio' ),
			$field,
			array(
				'group'    => __( 'Group (cloned fields are wrapped in one group)', 'custom-fields-studio' ),
				'seamless' => __( 'Seamless (cloned fields replace this field)', 'custom-fields-studio' ),
			),
			'group'
		);
		self::setting_checkbox( 'clone', $prefix, 'prefix_name', __( 'Prefix field names with this field\'s name (seamless display)', 'custom-fields-studio' ), $field );
	}

	public static function sanitize( $raw ) {
		$keys = array();
		foreach ( (array) ( $raw['clone'] ?? array() ) as $key ) {
			if ( is_scalar( $key ) && '' !== sanitize_key( (string) $key ) ) {
				$keys[] = sanitize_key( (string) $key );
			}
		}

		return array(
			'clone'       => array_values( array_unique( $keys ) ),
			'display'     => self::pick( $raw, 'display', array( 'group', 'seamless' ), 'group' ),
			'prefix_name' => ! empty( $raw['prefix_name'] ) ? 1 : 0,
		);
	}

	public static function render_input( $field, $value, $name ) {
		echo '<p class="description">' . esc_html__( 'Clone field: its fields are shown in place of this one.', 'custom-fields-studio' ) . '</p>';
	}

	/**
	 * Replace every clone field in a field list by the fields it points at.
	 * Recurses into group / repeater / flexible content containers.
	 *
	 * @param array $fields   Field list.
	 * @param array $sources  group key => array( 'id' => int, 'fields' => array ) of EVERY group.
	 * @param array $visiting Group keys being resolved right now (cycle guard).
	 * @return array
	 */
	public static function resolve( $fields, $sources, $visiting = array() ) {
		$out = array();

		foreach ( (array) $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( 'clone' !== ( $field['type'] ?? '' ) ) {
				$out[] = self::resolve_children( $field, $sources, $visiting );
				continue;
			}

			$cloned = array();
			foreach ( (array) ( $field['clone'] ?? array() ) as $group_key ) {
				if ( ! isset( $sources[ $group_key ] ) || isset( $visiting[ $group_key ] ) ) {
					continue; // Missing group, or a group that (indirectly) clones itself.
				}
				$cloned = array_merge(
					$cloned,
					self::resolve( $sources[ $group_key ]['fields'], $sources, $visiting + array( $group_key => true ) )
				);
			}

			$seamless = 'seamless' === ( $field['display'] ?? 'group' );
			$prefix   = ( $seamless && ! empty( $field['prefix_name'] ) ) ? $field['name'] . '_' : '';

			$cloned = self::rekey( $cloned, $field['key'] ?? $field['name'], $prefix );

			if ( $seamless ) {
				$out = array_merge( $out, $cloned );
			} elseif ( $cloned ) {
				$out[] = array(
					'key'          => $field['key'] ?? $field['name'],
					'label'        => $field['label'] ?? $field['name'],
					'name'         => $field['name'],
					'type'         => 'group',
					'instructions' => $field['instructions'] ?? '',
					'required'     => 0,
					'layout'       => 'block',
					'sub_fields'   => $cloned,
				) + ( isset( $field['conditional_logic'] ) ? array( 'conditional_logic' => $field['conditional_logic'] ) : array() );
			}
		}

		return $out;
	}

	/** Resolve clones nested inside group / repeater / flexible fields. */
	protected static function resolve_children( $field, $sources, $visiting ) {
		if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			$field['sub_fields'] = self::resolve( $field['sub_fields'], $sources, $visiting );
		}

		if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
			foreach ( $field['layouts'] as $i => $layout ) {
				if ( isset( $layout['sub_fields'] ) ) {
					$field['layouts'][ $i ]['sub_fields'] = self::resolve( $layout['sub_fields'], $sources, $visiting );
				}
			}
		}

		return $field;
	}

	/**
	 * Give cloned fields keys that cannot collide with the originals' keys
	 * (the same group can be cloned twice), and optionally prefix names —
	 * keeping conditional-logic references among the cloned siblings valid.
	 */
	protected static function rekey( $fields, $clone_key, $prefix ) {
		$names = array();
		foreach ( $fields as $field ) {
			$names[ $field['name'] ] = $prefix . $field['name'];
		}

		foreach ( $fields as $i => $field ) {
			$fields[ $i ]['key']  = $clone_key . '_' . ( $field['key'] ?? $field['name'] );
			$fields[ $i ]['name'] = $names[ $field['name'] ];

			if ( ! empty( $field['conditional_logic']['rules'] ) ) {
				foreach ( $field['conditional_logic']['rules'] as $r => $rule ) {
					if ( isset( $names[ $rule['field'] ] ) ) {
						$fields[ $i ]['conditional_logic']['rules'][ $r ]['field'] = $names[ $rule['field'] ];
					}
				}
			}
		}

		return $fields;
	}
}
