<?php
/**
 * Field Group post type — the admin builder for defining a group of
 * custom fields and where they should appear.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Group {

	const POST_TYPE = 'cfs_field_group';
	const NONCE     = 'cfs_field_group_nonce';

	/**
	 * Wire up all hooks for this screen.
	 */
	public function hooks() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ), 10, 2 );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Register the (admin-only, non-public) Field Group post type.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Field Groups', 'cfs' ),
					'singular_name'      => __( 'Field Group', 'cfs' ),
					'add_new_item'       => __( 'Add New Field Group', 'cfs' ),
					'edit_item'          => __( 'Edit Field Group', 'cfs' ),
					'all_items'          => __( 'Field Groups', 'cfs' ),
					'not_found'          => __( 'No field groups found. Add your first one to get started.', 'cfs' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-list-view',
				// No 'menu_position' set deliberately: position 80 collides
				// with WordPress core's own Settings menu (also at 80),
				// which caused BOTH menus to disappear. Omitting this lets
				// WP append Field Groups safely after the core menu items.
				'capability_type'     => 'page',
				'capabilities'        => array(
					// Restrict management to admins/editors, not arbitrary post authors.
					'edit_post'   => 'manage_options',
					'edit_posts'  => 'manage_options',
					'delete_post' => 'manage_options',
				),
				'map_meta_cap'        => true,
				'supports'            => array( 'title' ),
				'has_archive'         => false,
				'exclude_from_search' => true,
				'show_in_rest'        => false,
			)
		);
	}

	/**
	 * Register meta boxes on the Field Group edit screen.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'cfs_fields',
			__( 'Fields', 'cfs' ),
			array( $this, 'render_fields_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'cfs_location',
			__( 'Location Rules', 'cfs' ),
			array( $this, 'render_location_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'cfs_settings',
			__( 'Settings', 'cfs' ),
			array( $this, 'render_settings_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * The main field builder: list of field rows (draggable to reorder)
	 * plus an "Add Field" button. New rows are cloned client-side from a
	 * hidden template and re-indexed on save.
	 *
	 * @param WP_Post $post Current field group post.
	 */
	public function render_fields_meta_box( $post ) {
		wp_nonce_field( 'cfs_save_field_group', self::NONCE );

		$fields = get_post_meta( $post->ID, '_cfs_fields', true );
		$fields = is_array( $fields ) ? $fields : array();
		$types  = CFS_Field_Registry::get_choices();
		?>
		<div id="cfs-fields-wrap">
			<table class="cfs-fields-table widefat">
				<thead>
					<tr>
						<th class="cfs-col-handle"></th>
						<th><?php esc_html_e( 'Label', 'cfs' ); ?></th>
						<th><?php esc_html_e( 'Name', 'cfs' ); ?></th>
						<th><?php esc_html_e( 'Type', 'cfs' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="cfs-fields-rows">
					<?php
					if ( empty( $fields ) ) {
						// Start a brand-new field group with one empty row.
						$fields = array( self::empty_field() );
					}
					foreach ( $fields as $index => $field ) {
						$this->render_field_row( $field, $index, $types );
					}
					?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button button-primary" id="cfs-add-field"><?php esc_html_e( '+ Add Field', 'cfs' ); ?></button>
			</p>
		</div>

		<script type="text/html" id="tmpl-cfs-field-row">
			<?php $this->render_field_row( self::empty_field(), '__INDEX__', $types ); ?>
		</script>
		<?php
	}

	/**
	 * Default shape for a brand-new field row.
	 *
	 * @return array
	 */
	protected static function empty_field() {
		return array(
			'key'          => 'field_' . uniqid(),
			'label'        => '',
			'name'         => '',
			'type'         => 'text',
			'instructions' => '',
			'required'     => 0,
		);
	}

	/**
	 * Render one field row: generic settings (label/name/type/required/
	 * instructions) plus each field type's own settings, all nested
	 * inside a single <tr> so drag-reorder and add/remove only ever have
	 * to move one element per field.
	 *
	 * @param array      $field Field config.
	 * @param int|string $index Row index, or "__INDEX__" placeholder for the JS template.
	 * @param array      $types Registered type choices.
	 */
	protected function render_field_row( $field, $index, $types ) {
		$prefix = "cfs_fields[{$index}]";
		$type   = $field['type'] ?? 'text';
		?>
		<tr class="cfs-field-row" data-index="<?php echo esc_attr( $index ); ?>">
			<td class="cfs-col-handle"><span class="cfs-drag-handle dashicons dashicons-menu"></span></td>
			<td colspan="4">
				<div class="cfs-field-row-main">
					<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[key]" value="<?php echo esc_attr( $field['key'] ?? '' ); ?>" class="cfs-field-key" />
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $field['label'] ?? '' ); ?>" class="cfs-field-label" placeholder="<?php esc_attr_e( 'Field Label', 'cfs' ); ?>" />
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[name]" value="<?php echo esc_attr( $field['name'] ?? '' ); ?>" class="cfs-field-name" placeholder="<?php esc_attr_e( 'field_name', 'cfs' ); ?>" />
					<select name="<?php echo esc_attr( $prefix ); ?>[type]" class="cfs-field-type-select">
						<?php foreach ( $types as $type_key => $type_label ) : ?>
							<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $type, $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button-link cfs-toggle-field-settings"><?php esc_html_e( 'Settings', 'cfs' ); ?></button>
					<button type="button" class="button-link cfs-remove-field" aria-label="<?php esc_attr_e( 'Remove field', 'cfs' ); ?>">&times;</button>
				</div>
				<div class="cfs-field-settings-panel" style="display:none;">
					<div class="cfs-field-setting-generic">
						<label><?php esc_html_e( 'Instructions', 'cfs' ); ?></label>
						<textarea name="<?php echo esc_attr( $prefix ); ?>[instructions]" rows="2"><?php echo esc_textarea( $field['instructions'] ?? '' ); ?></textarea>
					</div>
					<div class="cfs-field-setting-generic">
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[required]" value="1" <?php checked( ! empty( $field['required'] ) ); ?> />
							<?php esc_html_e( 'Required', 'cfs' ); ?>
						</label>
					</div>
					<?php
					// Render every registered type's settings; JS shows only the
					// block matching the row's currently-selected type.
					foreach ( CFS_Field_Registry::get_all() as $type_key => $class_name ) {
						$class_name::render_settings( $field, $prefix );
					}
					?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Location rules meta box.
	 *
	 * @param WP_Post $post Current field group post.
	 */
	public function render_location_meta_box( $post ) {
		$groups = get_post_meta( $post->ID, '_cfs_location', true );
		$groups = is_array( $groups ) ? $groups : array();
		CFS_Location_Rules::render_builder( $groups );
	}

	/**
	 * Settings meta box: position, style, active toggle, menu order.
	 *
	 * @param WP_Post $post Current field group post.
	 */
	public function render_settings_meta_box( $post ) {
		$options = get_post_meta( $post->ID, '_cfs_options', true );
		$options = is_array( $options ) ? $options : array();

		$active   = array_key_exists( 'active', $options ) ? $options['active'] : 1;
		$position = $options['position'] ?? 'normal';
		$style    = $options['style'] ?? 'default';
		$order    = $options['menu_order'] ?? 0;
		?>
		<p>
			<label>
				<input type="checkbox" name="cfs_options[active]" value="1" <?php checked( $active, 1 ); ?> />
				<?php esc_html_e( 'Active', 'cfs' ); ?>
			</label>
		</p>
		<p>
			<label for="cfs-position"><?php esc_html_e( 'Position', 'cfs' ); ?></label><br />
			<select id="cfs-position" name="cfs_options[position]">
				<option value="normal" <?php selected( $position, 'normal' ); ?>><?php esc_html_e( 'Normal (after title)', 'cfs' ); ?></option>
				<option value="side" <?php selected( $position, 'side' ); ?>><?php esc_html_e( 'Side', 'cfs' ); ?></option>
			</select>
		</p>
		<p>
			<label for="cfs-style"><?php esc_html_e( 'Style', 'cfs' ); ?></label><br />
			<select id="cfs-style" name="cfs_options[style]">
				<option value="default" <?php selected( $style, 'default' ); ?>><?php esc_html_e( 'Standard (WP metabox)', 'cfs' ); ?></option>
				<option value="seamless" <?php selected( $style, 'seamless' ); ?>><?php esc_html_e( 'Seamless (no metabox styling)', 'cfs' ); ?></option>
			</select>
		</p>
		<p>
			<label for="cfs-menu-order"><?php esc_html_e( 'Order', 'cfs' ); ?></label><br />
			<input type="number" id="cfs-menu-order" name="cfs_options[menu_order]" value="<?php echo esc_attr( $order ); ?>" />
			<span class="description"><?php esc_html_e( 'Lower numbers appear first when multiple groups target the same screen.', 'cfs' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Save handler: validate nonce/capability, then sanitize and store
	 * fields, location rules, and settings as post meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'cfs_save_field_group' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// --- Fields ---
		$raw_fields   = isset( $_POST['cfs_fields'] ) ? wp_unslash( $_POST['cfs_fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$clean_fields = array();

		if ( is_array( $raw_fields ) ) {
			foreach ( $raw_fields as $raw_field ) {
				$label = isset( $raw_field['label'] ) ? sanitize_text_field( $raw_field['label'] ) : '';
				$name  = isset( $raw_field['name'] ) ? sanitize_key( $raw_field['name'] ) : '';

				// Skip fully-empty rows (e.g. an unused template row).
				if ( '' === $label && '' === $name ) {
					continue;
				}

				// Auto-derive the meta key from the label if left blank.
				if ( '' === $name && '' !== $label ) {
					$name = sanitize_key( $label );
				}

				$type       = isset( $raw_field['type'] ) ? sanitize_key( $raw_field['type'] ) : 'text';
				$type_class = CFS_Field_Registry::get_class( $type );
				if ( ! $type_class ) {
					$type       = 'text';
					$type_class = CFS_Field_Registry::get_class( 'text' );
				}

				$field = array(
					'key'          => isset( $raw_field['key'] ) ? sanitize_key( $raw_field['key'] ) : 'field_' . uniqid(),
					'label'        => $label,
					'name'         => $name,
					'type'         => $type,
					'instructions' => isset( $raw_field['instructions'] ) ? sanitize_textarea_field( $raw_field['instructions'] ) : '',
					'required'     => ! empty( $raw_field['required'] ) ? 1 : 0,
				);

				$field = array_merge( $field, $type_class::sanitize( $raw_field ) );

				$clean_fields[] = $field;
			}
		}

		update_post_meta( $post_id, '_cfs_fields', $clean_fields );

		// --- Location rules ---
		$raw_location = isset( $_POST['cfs_location'] ) ? wp_unslash( $_POST['cfs_location'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_post_meta( $post_id, '_cfs_location', CFS_Location_Rules::sanitize( $raw_location ) );

		// --- Settings ---
		$raw_options = isset( $_POST['cfs_options'] ) ? wp_unslash( $_POST['cfs_options'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$options     = array(
			'active'     => ! empty( $raw_options['active'] ) ? 1 : 0,
			'position'   => ( isset( $raw_options['position'] ) && 'side' === $raw_options['position'] ) ? 'side' : 'normal',
			'style'      => ( isset( $raw_options['style'] ) && 'seamless' === $raw_options['style'] ) ? 'seamless' : 'default',
			'menu_order' => isset( $raw_options['menu_order'] ) ? intval( $raw_options['menu_order'] ) : 0,
		);
		update_post_meta( $post_id, '_cfs_options', $options );
	}

	/**
	 * Add a "Fields" and "Location" summary column to the Field Groups list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['cfs_fields_count'] = __( 'Fields', 'cfs' );
				$new['cfs_location']     = __( 'Location', 'cfs' );
			}
		}
		return $new;
	}

	/**
	 * Render the custom list-table columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'cfs_fields_count' === $column ) {
			$fields = get_post_meta( $post_id, '_cfs_fields', true );
			echo esc_html( is_array( $fields ) ? count( $fields ) : 0 );
		}

		if ( 'cfs_location' === $column ) {
			$groups  = get_post_meta( $post_id, '_cfs_location', true );
			$summary = array();
			if ( is_array( $groups ) ) {
				foreach ( $groups as $rules ) {
					$parts = array();
					foreach ( $rules as $rule ) {
						$parts[] = sprintf(
							'%s %s %s',
							$rule['param'],
							'==' === $rule['operator'] ? '=' : '≠',
							$rule['value']
						);
					}
					if ( $parts ) {
						$summary[] = implode( ' & ', $parts );
					}
				}
			}
			echo esc_html( $summary ? implode( ' OR ', $summary ) : '—' );
		}
	}
}
