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
		// Keep the per-request group cache honest (after the meta is saved).
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'flush_cache' ), 99 );
		foreach ( array( 'trashed_post', 'untrashed_post', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_cache' ) );
		}

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
					// Only override capabilities WP checks WITHOUT a specific
					// post object (plural/generic ones). Restricting 'edit_posts'
					// to manage_options is enough to hide the whole menu and
					// screen from non-admins.
					//
					// Deliberately NOT overriding 'edit_post' / 'delete_post'
					// here: those are per-object ("meta") capabilities that
					// map_meta_cap() expects to check against a specific post.
					// Overriding them directly caused WordPress core itself to
					// call current_user_can('delete_post') generically (no
					// post ID) somewhere during admin-menu building, which
					// trips a "doing it wrong" notice. On this host that
					// notice's output was sent early enough to corrupt the
					// rest of the admin page output — which is what was
					// breaking the Settings and Field Groups menus together.
					'edit_posts'  => 'manage_options',
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
	 * The main field builder meta box.
	 *
	 * @param WP_Post $post Current field group post.
	 */
	public function render_fields_meta_box( $post ) {
		wp_nonce_field( 'cfs_save_field_group', self::NONCE );

		$fields = get_post_meta( $post->ID, '_cfs_fields', true );
		$fields = is_array( $fields ) ? $fields : array();

		if ( empty( $fields ) ) {
			// Start a brand-new field group with one empty row.
			$fields = array( self::empty_field() );
		}

		self::render_fields_editor( $fields, 'cfs_fields', true );
		?>

		<?php
		/*
		 * ONE row template for the whole page, shared by the top-level
		 * "Add Field" button and every repeater's "Add Sub Field" button.
		 * __PREFIX__ is swapped for the real name prefix (e.g.
		 * "cfs_fields[n3]" or "cfs_fields[2][sub_fields][n4]") when a row
		 * is cloned. A single shared template is what keeps a repeater
		 * inside a repeater from recursing forever at render time.
		 */
		?>
		<script type="text/html" id="tmpl-cfs-field-row">
			<?php self::render_field_row( self::empty_field(), '__PREFIX__' ); ?>
		</script>
		<?php
		/*
		 * Same idea for Flexible Content layouts: one empty layout block,
		 * cloned by every "Add Layout" button with __PREFIX__ replaced by
		 * e.g. "cfs_fields[2][layouts][n5]".
		 */
		?>
		<script type="text/html" id="tmpl-cfs-layout">
			<?php CFS_Field_Flexible::render_layout_editor( array(), '__PREFIX__' ); ?>
		</script>
		<?php
	}

	/**
	 * Render a list of field rows plus its "add" button. Used for the
	 * top-level field list and, nested, for a repeater's sub fields.
	 *
	 * Rows are keyed by an index that never changes after a row is created
	 * (new rows get a unique "n<counter>" key). Reordering therefore needs
	 * no renaming: PHP receives POST arrays in DOM order, and the save
	 * handler only cares about order, not keys.
	 *
	 * @param array  $fields Field configs to render.
	 * @param string $base   Name prefix for this list, e.g. "cfs_fields" or
	 *                       "cfs_fields[2][sub_fields]".
	 * @param bool   $top    True for the main list (adds a header row and
	 *                       the primary button style).
	 */
	public static function render_fields_editor( $fields, $base, $top = false ) {
		?>
		<div class="cfs-fields-editor<?php echo $top ? ' cfs-fields-editor-top' : ''; ?>">
			<?php if ( $top ) : ?>
				<p class="cfs-builder-toolbar">
					<button type="button" class="button-link cfs-expand-all"><?php esc_html_e( 'Expand all', 'cfs' ); ?></button>
					&middot;
					<button type="button" class="button-link cfs-collapse-all"><?php esc_html_e( 'Collapse all', 'cfs' ); ?></button>
				</p>
				<div class="notice notice-error inline cfs-builder-errors" style="display:none;"><p></p></div>
			<?php endif; ?>
			<table class="cfs-fields-table widefat">
				<?php if ( $top ) : ?>
					<thead>
						<tr>
							<th class="cfs-col-handle"></th>
							<th><?php esc_html_e( 'Label  /  Name  /  Type', 'cfs' ); ?></th>
						</tr>
					</thead>
				<?php endif; ?>
				<tbody class="cfs-rows"<?php echo $top ? ' id="cfs-fields-rows"' : ''; ?> data-base="<?php echo esc_attr( $base ); ?>">
					<?php
					foreach ( $fields as $index => $field ) {
						self::render_field_row( $field, $base . '[' . $index . ']' );
					}
					?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button<?php echo $top ? ' button-primary' : ''; ?> cfs-add-field">
					<?php echo $top ? esc_html__( '+ Add Field', 'cfs' ) : esc_html__( '+ Add Sub Field', 'cfs' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * A fresh, unique field key ("field_" + 12 hex chars).
	 *
	 * @return string
	 */
	public static function new_key() {
		return 'field_' . bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Default shape for a brand-new field row.
	 *
	 * @return array
	 */
	protected static function empty_field() {
		return array(
			'key'          => self::new_key(),
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
	 * @param array  $field  Field config.
	 * @param string $prefix Full name prefix for this row's inputs, e.g.
	 *                       "cfs_fields[0]", or the "__PREFIX__" placeholder
	 *                       for the JS template.
	 */
	public static function render_field_row( $field, $prefix ) {
		$types = CFS_Field_Registry::get_choices();
		$type  = $field['type'] ?? 'text';
		?>
		<tr class="cfs-field-row">
			<td class="cfs-col-handle"><span class="cfs-drag-handle dashicons dashicons-menu"></span></td>
			<td>
				<div class="cfs-field-row-main">
					<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[key]" value="<?php echo esc_attr( $field['key'] ?? '' ); ?>" class="cfs-field-key" />
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $field['label'] ?? '' ); ?>" class="cfs-field-label" placeholder="<?php esc_attr_e( 'Field Label', 'cfs' ); ?>" />
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[name]" value="<?php echo esc_attr( $field['name'] ?? '' ); ?>" class="cfs-field-name" placeholder="<?php esc_attr_e( 'field_name', 'cfs' ); ?>" />
					<select name="<?php echo esc_attr( $prefix ); ?>[type]" class="cfs-field-type-select">
						<?php foreach ( $types as $type_key => $type_label ) : ?>
							<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $type, $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button-link cfs-toggle-field-settings" aria-expanded="false"><?php esc_html_e( 'Settings', 'cfs' ); ?></button>
					<button type="button" class="button-link cfs-duplicate-field"><?php esc_html_e( 'Duplicate', 'cfs' ); ?></button>
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
					<?php CFS_Conditional::render_builder( $field, $prefix ); ?>
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
		$in_rest  = ! empty( $options['show_in_rest'] );
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
			<label>
				<input type="checkbox" name="cfs_options[show_in_rest]" value="1" <?php checked( $in_rest ); ?> />
				<?php esc_html_e( 'Show in REST API', 'cfs' ); ?>
			</label><br />
			<span class="description"><?php esc_html_e( 'Exposes this group\'s values as the "cfs" field on posts and at /wp-json/cfs/v1/.', 'cfs' ); ?></span>
		</p>
		<p>
			<label for="cfs-menu-order"><?php esc_html_e( 'Order', 'cfs' ); ?></label><br />
			<input type="number" id="cfs-menu-order" name="cfs_options[menu_order]" value="<?php echo esc_attr( $order ); ?>" />
			<span class="description"><?php esc_html_e( 'Lower numbers appear first when multiple groups target the same screen.', 'cfs' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Sanitize a posted list of field configs. Recursive: a repeater's
	 * sanitize() calls this again for its own sub_fields.
	 *
	 * @param mixed $raw_fields Raw (unslashed) posted list of field rows.
	 * @return array Clean, re-indexed list of field configs.
	 */
	public static function sanitize_fields( $raw_fields ) {
		$clean_fields = array();

		if ( ! is_array( $raw_fields ) ) {
			return $clean_fields;
		}

		// Keys must be unique across the whole group, sub fields included,
		// so the set of keys already used is shared with the recursive calls
		// a repeater makes for its sub fields. The outermost call owns it.
		$owns_seen_keys = ( null === self::$seen_keys );
		if ( $owns_seen_keys ) {
			self::$seen_keys = array();
		}

		$used_names = array();

		foreach ( $raw_fields as $raw_field ) {
			if ( ! is_array( $raw_field ) ) {
				continue;
			}

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

			// Two fields of one list can't share a name (they'd overwrite each
			// other's stored value): suffix repeats with _2, _3, ...
			$base_name = $name;
			$suffix    = 2;
			while ( in_array( $name, $used_names, true ) ) {
				$name = $base_name . '_' . $suffix++;
			}
			$used_names[] = $name;

			$type       = isset( $raw_field['type'] ) ? sanitize_key( $raw_field['type'] ) : 'text';
			$type_class = CFS_Field_Registry::get_class( $type );
			if ( ! $type_class ) {
				$type       = 'text';
				$type_class = CFS_Field_Registry::get_class( 'text' );
			}

			$field = array(
				'key'          => self::unique_key( $raw_field['key'] ?? '' ),
				'label'        => $label,
				'name'         => $name,
				'type'         => $type,
				'instructions' => isset( $raw_field['instructions'] ) ? sanitize_textarea_field( $raw_field['instructions'] ) : '',
				'required'     => ! empty( $raw_field['required'] ) ? 1 : 0,
			);

			$logic = CFS_Conditional::sanitize( $raw_field['conditional_logic'] ?? array() );
			if ( $logic ) {
				$field['conditional_logic'] = $logic;
			}

			$clean_fields[] = array_merge( $field, $type_class::sanitize( $raw_field ) );
		}

		if ( $owns_seen_keys ) {
			self::$seen_keys = null;
		}

		return $clean_fields;
	}

	/**
	 * Keys already handed out during the current sanitize_fields() run
	 * (null when no run is in progress).
	 *
	 * @var array|null
	 */
	protected static $seen_keys = null;

	/**
	 * Return the posted key if it is present and not used yet in this save,
	 * otherwise a fresh one. Repairs field groups saved while every new row
	 * was cloned with the same key.
	 *
	 * @param mixed $posted_key Raw posted key.
	 * @return string
	 */
	protected static function unique_key( $posted_key ) {
		$key = is_string( $posted_key ) ? sanitize_key( $posted_key ) : '';

		if ( '' === $key || isset( self::$seen_keys[ $key ] ) ) {
			$key = self::new_key();
		}

		self::$seen_keys[ $key ] = true;

		return $key;
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
		$raw_fields = isset( $_POST['cfs_fields'] ) ? wp_unslash( $_POST['cfs_fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// update_post_meta() expects slashed data, so slash on the way in;
		// otherwise a backslash in a label/choice is silently dropped.
		update_post_meta( $post_id, '_cfs_fields', wp_slash( self::sanitize_fields( $raw_fields ) ) );

		// --- Location rules ---
		$raw_location = isset( $_POST['cfs_location'] ) ? wp_unslash( $_POST['cfs_location'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_post_meta( $post_id, '_cfs_location', CFS_Location_Rules::sanitize( $raw_location ) );

		// --- Settings ---
		$raw_options = isset( $_POST['cfs_options'] ) ? wp_unslash( $_POST['cfs_options'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$options = self::sanitize_options( $raw_options );
		update_post_meta( $post_id, '_cfs_options', $options );

		// Make sure the group has its stable key (used by JSON and clone fields).
		self::get_group_key( $post_id );
	}

	/**
	 * Clean a posted/imported settings array.
	 *
	 * @param mixed $raw_options Raw settings.
	 * @return array
	 */
	public static function sanitize_options( $raw_options ) {
		$raw_options = is_array( $raw_options ) ? $raw_options : array();

		return array(
			'active'       => ! empty( $raw_options['active'] ) ? 1 : 0,
			'position'     => ( isset( $raw_options['position'] ) && 'side' === $raw_options['position'] ) ? 'side' : 'normal',
			'style'        => ( isset( $raw_options['style'] ) && 'seamless' === $raw_options['style'] ) ? 'seamless' : 'default',
			'menu_order'   => isset( $raw_options['menu_order'] ) ? intval( $raw_options['menu_order'] ) : 0,
			'show_in_rest' => ! empty( $raw_options['show_in_rest'] ) ? 1 : 0,
		);
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

	/**
	 * All active field groups whose location rules match the given post
	 * type, sorted by their configured display order. Used by the meta
	 * box (Phase 2) to decide what to render/save on a post edit screen,
	 * and by the template API to resolve a field selector to its config.
	 *
	 * Cached per request per post type — this can be called several times
	 * on one screen (register meta boxes, save, enqueue) and each call
	 * would otherwise re-run the same query.
	 *
	 * @param string $post_type Post type to match against.
	 * @return array List of array( id, title, fields, options ).
	 */
	public static function get_matching_groups( $post_type ) {
		if ( ! isset( self::$matching_cache[ $post_type ] ) ) {
			self::$matching_cache[ $post_type ] = array_values(
				array_filter(
					self::get_active_groups(),
					function ( $group ) use ( $post_type ) {
						return CFS_Location_Rules::matches_post_type( $group['location'], $post_type );
					}
				)
			);
		}

		return self::$matching_cache[ $post_type ];
	}

	/**
	 * The stable key of a field group ("group_xxxxxxxxxxxxx"), created on
	 * first use. JSON import/export and clone fields refer to groups by it.
	 *
	 * @param int $group_id Field group post ID.
	 * @return string
	 */
	public static function get_group_key( $group_id ) {
		$key = get_post_meta( $group_id, '_cfs_group_key', true );

		if ( ! is_string( $key ) || '' === $key ) {
			$key = 'group_' . substr( md5( uniqid( (string) $group_id, true ) ), 0, 13 );
			update_post_meta( $group_id, '_cfs_group_key', $key );
		}

		return $key;
	}

	/**
	 * Active groups for one post: location rules checked against the post
	 * itself (type, id, status, page template, categories/terms) and the
	 * current user's role.
	 *
	 * @param WP_Post|int $post Post.
	 * @return array
	 */
	public static function get_groups_for_post( $post ) {
		$context = CFS_Location_Rules::post_context( $post );
		if ( ! $context ) {
			return array();
		}

		return array_values(
			array_filter(
				self::get_active_groups(),
				function ( $group ) use ( $context ) {
					return CFS_Location_Rules::matches( $group['location'], 'post', $context );
				}
			)
		);
	}

	/**
	 * Groups whose fields should be saved for a post: the groups that match
	 * it now, plus groups that were on the edit screen when it was opened but
	 * stopped matching because of what this very save changed (publishing a
	 * draft, picking another page template or category). Those are only
	 * accepted when the post still passes their other rules.
	 *
	 * @param WP_Post $post     Post being saved.
	 * @param array   $rendered Group IDs the edit screen reported showing.
	 * @return array
	 */
	public static function get_groups_for_save( $post, $rendered = array() ) {
		$groups = self::get_groups_for_post( $post );
		$have   = wp_list_pluck( $groups, 'id' );
		$rendered = array_map( 'absint', (array) $rendered );

		if ( ! $rendered ) {
			return $groups;
		}

		$context = CFS_Location_Rules::post_context( $post );
		foreach ( self::get_active_groups() as $group ) {
			if ( in_array( $group['id'], $have, true ) || ! in_array( $group['id'], $rendered, true ) ) {
				continue;
			}
			if ( CFS_Location_Rules::matches( $group['location'], 'post', $context, array( 'post_status', 'page_template', 'post_category', 'post_taxonomy' ) ) ) {
				$groups[] = $group;
			}
		}

		return $groups;
	}

	/**
	 * Active groups for a user profile screen.
	 *
	 * @param int $user_id User being edited.
	 * @return array
	 */
	public static function get_groups_for_user( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}

		$context = array( 'user_role' => (array) $user->roles );

		return array_values(
			array_filter(
				self::get_active_groups(),
				function ( $group ) use ( $context ) {
					return CFS_Location_Rules::matches( $group['location'], 'user', $context );
				}
			)
		);
	}

	/**
	 * Active groups for a taxonomy term screen.
	 *
	 * @param WP_Term|int $term Term object or ID.
	 * @return array
	 */
	public static function get_groups_for_term( $term ) {
		$term = get_term( $term );
		if ( ! $term || is_wp_error( $term ) ) {
			return array();
		}

		$context = array( 'taxonomy' => $term->taxonomy );

		return array_values(
			array_filter(
				self::get_active_groups(),
				function ( $group ) use ( $context ) {
					return CFS_Location_Rules::matches( $group['location'], 'term', $context );
				}
			)
		);
	}

	/**
	 * Every active group (cached); public for the REST/JSON layers.
	 *
	 * @return array
	 */
	public static function all_active() {
		return self::get_active_groups();
	}

	/** @var array|null Request cache of every active group. */
	protected static $groups_cache = null;

	/** @var array Request cache of matching groups per post type. */
	protected static $matching_cache = array();

	/**
	 * Forget the cached groups. Called automatically whenever a field
	 * group is saved, trashed, restored or deleted, so a group created or
	 * changed earlier in the same request (imports, tests, code that
	 * programmatically saves groups) is seen by later lookups.
	 */
	public static function flush_cache() {
		self::$groups_cache   = null;
		self::$matching_cache = array();
	}

	/**
	 * Active groups shown on one options page (or, with null, on any
	 * registered options page — the 'option' context of the template API,
	 * where every options page shares one storage namespace).
	 *
	 * @param string|null $slug Options page menu slug, or null for all.
	 * @return array Same shape as get_matching_groups().
	 */
	public static function get_options_groups( $slug = null ) {
		$slugs = ( null === $slug )
			? wp_list_pluck( CFS_Options_Page::get_pages(), 'menu_slug' )
			: array( $slug );

		return array_values(
			array_filter(
				self::get_active_groups(),
				function ( $group ) use ( $slugs ) {
					foreach ( $slugs as $one ) {
						if ( CFS_Location_Rules::matches_options_page( $group['location'], $one ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * All published, active field groups (menu-order sorted), each with its
	 * location rules. Cached for the request.
	 *
	 * @return array List of array( id, title, fields, options, location ).
	 */
	protected static function get_active_groups() {
		if ( null !== self::$groups_cache ) {
			return self::$groups_cache;
		}

		$group_posts = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);

		$groups = array();

		// Every group's raw fields by group key (active or not), so clone
		// fields can pull fields from any group.
		$sources = array();
		foreach ( get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts' => -1,
			)
		) as $source_post ) {
			$source_fields = get_post_meta( $source_post->ID, '_cfs_fields', true );

			$sources[ self::get_group_key( $source_post->ID ) ] = array(
				'id'     => $source_post->ID,
				'fields' => is_array( $source_fields ) ? $source_fields : array(),
			);
		}

		foreach ( $group_posts as $group_post ) {
			$options = get_post_meta( $group_post->ID, '_cfs_options', true );
			$options = is_array( $options ) ? $options : array();
			$options = wp_parse_args(
				$options,
				array(
					'active'     => 1,
					'position'   => 'normal',
					'style'      => 'default',
					'menu_order' => 0,
					'show_in_rest' => 0,
				)
			);

			if ( empty( $options['active'] ) ) {
				continue;
			}

			$location = get_post_meta( $group_post->ID, '_cfs_location', true );
			$fields   = get_post_meta( $group_post->ID, '_cfs_fields', true );

			$groups[] = array(
				'id'       => $group_post->ID,
				'key'      => self::get_group_key( $group_post->ID ),
				'title'    => $group_post->post_title,
				'fields'   => CFS_Field_Clone::resolve( is_array( $fields ) ? $fields : array(), $sources ),
				'options'  => $options,
				'location' => is_array( $location ) ? $location : array(),
			);
		}

		usort(
			$groups,
			function ( $a, $b ) {
				return $a['options']['menu_order'] <=> $b['options']['menu_order'];
			}
		);

		self::$groups_cache = $groups;

		return $groups;
	}

	/**
	 * Find one field's config by name or key across a list of groups (as
	 * returned by get_matching_groups()). Used by the template API to
	 * resolve cfs_get_field('my_field') to the settings needed to format
	 * its stored value.
	 *
	 * @param array  $groups   Groups as returned by get_matching_groups().
	 * @param string $selector Field name or field key.
	 * @return array|null
	 */
	public static function find_field( $groups, $selector ) {
		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( $field['name'] === $selector || $field['key'] === $selector ) {
					return $field;
				}
			}
		}

		return null;
	}
}
