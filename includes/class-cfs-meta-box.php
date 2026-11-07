<?php
/**
 * Renders matching field groups as meta boxes on post edit screens, and
 * saves the posted values as post meta.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Meta_Box {

	const NONCE_ACTION = 'cfs_save_values';
	const NONCE_FIELD  = 'cfs_values_nonce';

	/**
	 * Wire up hooks.
	 */
	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Register one meta box per matching, active field group on this
	 * screen's post type.
	 */
	public function register() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		$groups = CFS_Field_Group::get_matching_groups( $screen->post_type );

		foreach ( $groups as $group ) {
			add_meta_box(
				'cfs_group_' . $group['id'],
				$group['title'],
				array( $this, 'render' ),
				$screen->post_type,
				in_array( $group['options']['position'], array( 'normal', 'side' ), true ) ? $group['options']['position'] : 'normal',
				'default',
				array( 'group' => $group )
			);
		}
	}

	/**
	 * Whether the shared value-save nonce has already been printed on this
	 * page. All CFS meta boxes on one screen save through the same nonce
	 * (save() only needs to verify it once), so it's only printed with the
	 * first box rather than once per box.
	 *
	 * @var bool
	 */
	protected static $nonce_printed = false;

	/**
	 * Render one field group's meta box: nonce (once per page, shared
	 * across all of this screen's CFS boxes) plus each field's
	 * label/instructions/input.
	 *
	 * @param WP_Post $post    Current post.
	 * @param array   $metabox Meta box args, carries 'group' in ['args'].
	 */
	public function render( $post, $metabox ) {
		$group = $metabox['args']['group'];

		if ( ! self::$nonce_printed ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
			self::$nonce_printed = true;
		}

		if ( empty( $group['fields'] ) ) {
			echo '<p>' . esc_html__( 'This field group has no fields yet.', 'cfs' ) . '</p>';
			return;
		}

		self::render_group_fields( $group, $post->ID );
	}

	/**
	 * Print every field of a group, loaded from the given context.
	 * Shared by post meta boxes and Options Pages.
	 *
	 * @param array      $group Group from CFS_Field_Group.
	 * @param int|string $ctx   Post ID or 'option'.
	 */
	public static function render_group_fields( $group, $ctx ) {
		$seamless = 'seamless' === ( $group['options']['style'] ?? 'default' );

		echo '<div class="cfs-field-values' . ( $seamless ? ' cfs-seamless' : '' ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed strings only.

		foreach ( $group['fields'] as $field ) {
			self::render_one_field( $ctx, $field );
		}

		echo '</div>';
	}

	/**
	 * Render one field's label, instructions, and input.
	 *
	 * @param int|string $ctx   Post ID or 'option'.
	 * @param array      $field Field config.
	 */
	protected static function render_one_field( $ctx, $field ) {
		$type_class = CFS_Field_Registry::get_class( $field['type'] );
		if ( ! $type_class ) {
			return;
		}

		// load_value() lets composite fields (repeater) reassemble their
		// value from several keys; simple fields just read one.
		$value = $type_class::load_value( $field, $ctx );
		// An empty string means "no value saved yet" for most meta reads;
		// pass null through so each field type can apply its own default
		// rather than every type having to special-case ''.
		// ...but only when nothing was ever saved: the reference key
		// ("_name") is written on every save, so if it exists an empty value
		// is the editor's deliberate choice and a default must not return.
		if ( '' === $value && ! CFS_Storage::exists( '_' . $field['name'], $ctx ) ) {
			$value = null;
		}

		self::render_field( $field, $value, 'cfs[' . $field['name'] . ']' );
	}

	/**
	 * Render one field's wrapper: label, required marker, instructions and
	 * the type's own input. Public + static because the repeater renders
	 * each sub field through this same markup.
	 *
	 * @param array  $field Field config.
	 * @param mixed  $value Raw stored value, or null for "none yet".
	 * @param string $name  Full HTML name attribute for the input.
	 */
	public static function render_field( $field, $value, $name ) {
		$type_class = CFS_Field_Registry::get_class( $field['type'] );
		if ( ! $type_class ) {
			return;
		}
		?>
		<div class="cfs-field cfs-field-type-<?php echo esc_attr( $field['type'] ); ?>">
			<?php
			/**
			 * Deliberately no for="" here: field types render their own
			 * input markup and don't uniformly expose a matching id (e.g.
			 * WYSIWYG generates its own editor id, Image's real control is
			 * a button, not the hidden input) — a for="" pointing at
			 * nothing is worse than a plain label, so this stays a visual
			 * label rather than a programmatic one for now.
			 */
			?>
			<label class="cfs-field-label">
				<?php echo esc_html( $field['label'] ); ?>
				<?php if ( ! empty( $field['required'] ) ) : ?>
					<span class="cfs-required" title="<?php esc_attr_e( 'Required', 'cfs' ); ?>">*</span>
				<?php endif; ?>
			</label>
			<?php if ( ! empty( $field['instructions'] ) ) : ?>
				<p class="cfs-field-instructions"><?php echo esc_html( $field['instructions'] ); ?></p>
			<?php endif; ?>
			<div class="cfs-field-input">
				<?php $type_class::render_input( $field, $value, $name ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Save handler: verify nonce/capability once, then sanitize and store
	 * every field from every group matching this post's post type.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$groups = CFS_Field_Group::get_matching_groups( $post->post_type );
		if ( empty( $groups ) ) {
			return;
		}

		$posted = isset( $_POST['cfs'] ) ? wp_unslash( $_POST['cfs'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field type sanitizes its own value below.

		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				$type_class = CFS_Field_Registry::get_class( $field['type'] );
				if ( ! $type_class ) {
					continue;
				}

				// A field with no matching $_POST entry (e.g. an unchecked
				// True/False checkbox) still needs to run through
				// sanitize_value() rather than being skipped, so unchecking
				// a box actually saves the "off" state instead of leaving
				// the previous "on" value in place.
				// save_value() sanitizes, stores, and writes the reference
				// key ("_name" => field key) that lets the template API map
				// a stored value back to its field config — the same
				// pattern ACF uses. Composite fields (repeater) override it
				// to store their rows under compound keys.
				$type_class::save_value( $field, $posted[ $field['name'] ] ?? '', $post_id );
			}
		}
	}

	/**
	 * Enqueue the meta-box JS/CSS only on post edit screens that actually
	 * have at least one matching field group.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$groups = CFS_Field_Group::get_matching_groups( $screen->post_type );
		if ( empty( $groups ) ) {
			return;
		}

		self::enqueue_assets( $groups );
	}

	/**
	 * Load the field-editing CSS/JS for these groups. Used by post edit
	 * screens and Options Pages alike.
	 *
	 * @param array $groups Groups that will be rendered on the screen.
	 */
	public static function enqueue_assets( $groups ) {
		wp_enqueue_style( 'cfs-admin', CFS_URL . 'admin/css/admin.css', array(), CFS_VERSION );

		// Needed for the Image field's "Select Image" button (wp.media()).
		wp_enqueue_media();

		// Repeater rows are reorderable (jQuery UI Sortable) and may hold
		// WYSIWYG sub fields, which are initialized in JS for rows that
		// are cloned in the browser — that needs the editor assets loaded
		// even when the post type has no classic editor on screen.
		if ( self::groups_have_type( $groups, 'repeater' ) || self::groups_have_type( $groups, 'flexible_content' ) ) {
			wp_enqueue_editor();
		}

		wp_enqueue_script(
			'cfs-meta-box',
			CFS_URL . 'admin/js/meta-box.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			CFS_VERSION,
			true
		);

		wp_localize_script(
			'cfs-meta-box',
			'cfsMetaBox',
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'relationshipNonce' => wp_create_nonce( CFS_Field_Relationship::NONCE ),
				'i18n'              => array(
					'addImages'   => __( 'Add images', 'cfs' ),
					'noResults'   => __( 'No results', 'cfs' ),
					'selectImage' => __( 'Select Image', 'cfs' ),
					'useImage'    => __( 'Use this image', 'cfs' ),
					'selectFile'  => __( 'Select File', 'cfs' ),
					'useFile'     => __( 'Use this file', 'cfs' ),
				),
			)
		);
	}

	/**
	 * Whether any field in these groups (including nested sub fields) is
	 * of the given type.
	 *
	 * @param array  $groups Groups from CFS_Field_Group::get_matching_groups().
	 * @param string $type   Field type key.
	 * @return bool
	 */
	protected static function groups_have_type( $groups, $type ) {
		foreach ( $groups as $group ) {
			if ( self::fields_have_type( $group['fields'], $type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursive helper for groups_have_type().
	 *
	 * @param array  $fields Field configs.
	 * @param string $type   Field type key.
	 * @return bool
	 */
	protected static function fields_have_type( $fields, $type ) {
		foreach ( $fields as $field ) {
			if ( ( $field['type'] ?? '' ) === $type ) {
				return true;
			}
			if ( ! empty( $field['sub_fields'] ) && self::fields_have_type( $field['sub_fields'], $type ) ) {
				return true;
			}
			// Flexible content: sub fields live inside each layout.
			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				if ( ! empty( $layout['sub_fields'] ) && self::fields_have_type( $layout['sub_fields'], $type ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
