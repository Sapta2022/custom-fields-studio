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

class CFStudio_Meta_Box {

	const NONCE_ACTION = 'cfstudio_save_values';
	const NONCE_FIELD  = 'cfstudio_values_nonce';

	/**
	 * Wire up hooks.
	 */
	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		// User profile screens.
		add_action( 'show_user_profile', array( $this, 'render_user_groups' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_groups' ) );
		add_action( 'personal_options_update', array( $this, 'save_user' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user' ) );

		// Taxonomy term edit screens.
		add_action( 'admin_init', array( $this, 'hook_term_screens' ) );
		add_action( 'edited_term', array( $this, 'save_term' ), 10, 3 );
	}

	/**
	 * Print the groups that apply to the user being edited.
	 *
	 * @param WP_User $user User.
	 */
	public function render_user_groups( $user ) {
		$groups = CFStudio_Field_Group::get_groups_for_user( $user->ID );
		if ( ! $groups ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		foreach ( $groups as $group ) {
			echo '<h2>' . esc_html( $group['title'] ) . '</h2><div class="cfs-screen-group" id="cfstudio_group_' . (int) $group['id'] . '">';
			self::render_group_fields( $group, 'user_' . $user->ID );
			echo '</div>';
		}
	}

	/**
	 * Save the user profile screen's values.
	 *
	 * @param int $user_id User ID.
	 */
	public function save_user( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! self::nonce_ok() ) {
			return;
		}

		self::save_groups( CFStudio_Field_Group::get_groups_for_user( $user_id ), 'user_' . $user_id );
	}

	/** Add the edit-form hook for every public taxonomy. */
	public function hook_term_screens() {
		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_term_groups' ), 10, 2 );
		}
	}

	/**
	 * Print the groups that apply to the term being edited.
	 *
	 * @param WP_Term $term Term.
	 */
	public function render_term_groups( $term ) {
		$groups = CFStudio_Field_Group::get_groups_for_term( $term );
		if ( ! $groups ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		foreach ( $groups as $group ) {
			echo '<tr class="form-field"><td colspan="2"><h2>' . esc_html( $group['title'] ) . '</h2><div class="cfs-screen-group" id="cfstudio_group_' . (int) $group['id'] . '">';
			self::render_group_fields( $group, 'term_' . $term->term_id );
			echo '</div></td></tr>';
		}
	}

	/**
	 * Save the term edit screen's values.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function save_term( $term_id, $tt_id = 0, $taxonomy = '' ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! current_user_can( $tax->cap->edit_terms ) || ! self::nonce_ok() ) {
			return;
		}

		self::save_groups( CFStudio_Field_Group::get_groups_for_term( $term_id ), 'term_' . $term_id );
	}

	/** Whether the shared value-save nonce was posted and is valid. */
	protected static function nonce_ok() {
		return isset( $_POST[ self::NONCE_FIELD ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION );
	}

	/**
	 * Save the posted values of a list of groups into a context. The posted
	 * data is read from $_POST['cfs'] (or $posted when given).
	 *
	 * @param array      $groups Groups.
	 * @param int|string $ctx    Storage context.
	 * @param array|null $posted Unslashed posted values, or null for $_POST.
	 */
	public static function save_groups( $groups, $ctx, $posted = null ) {
		if ( null === $posted ) {
			$posted = isset( $_POST['cfs'] ) ? wp_unslash( $_POST['cfs'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- callers verify the nonce first; each field type sanitizes its own value.
		}

		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				$type_class = CFStudio_Field_Registry::get_class( $field['type'] );
				if ( ! $type_class ) {
					continue;
				}

				$type_class::save_value( $field, $posted[ $field['name'] ] ?? '', $ctx );
			}
		}
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

		$current = get_post();
		$groups  = $current ? CFStudio_Field_Group::get_groups_for_post( $current ) : array();

		foreach ( $groups as $group ) {
			add_meta_box(
				'cfstudio_group_' . $group['id'],
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

		// Tell save() which groups were on screen (see get_groups_for_save()).
		echo '<input type="hidden" name="cfstudio_rendered[]" value="' . (int) $group['id'] . '" />';

		if ( empty( $group['fields'] ) ) {
			echo '<p>' . esc_html__( 'This field group has no fields yet.', 'custom-fields-studio' ) . '</p>';
			return;
		}

		self::render_group_fields( $group, $post->ID );
	}

	/**
	 * Print every field of a group, loaded from the given context.
	 * Shared by post meta boxes and Options Pages.
	 *
	 * @param array      $group Group from CFStudio_Field_Group.
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
		$type_class = CFStudio_Field_Registry::get_class( $field['type'] );
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
		if ( '' === $value && ! CFStudio_Storage::exists( '_' . $field['name'], $ctx ) ) {
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
		$type_class = CFStudio_Field_Registry::get_class( $field['type'] );
		if ( ! $type_class ) {
			return;
		}
		if ( ! $type_class::has_wrapper() ) {
			// Tabs and accordions draw only their own marker.
			$type_class::render_input( $field, $value, $name );
			return;
		}
		$cond = CFStudio_Conditional::data_attr( $field );
		?>
		<div class="cfs-field cfs-field-type-<?php echo esc_attr( $field['type'] ); ?>"<?php echo $cond ? ' data-cfs-cond="' . esc_attr( $cond ) . '"' : ''; ?>>
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
					<span class="cfs-required" title="<?php esc_attr_e( 'Required', 'custom-fields-studio' ); ?>">*</span>
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

		$rendered = isset( $_POST['cfstudio_rendered'] ) ? (array) wp_unslash( $_POST['cfstudio_rendered'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to ints in get_groups_for_save().
		$groups   = CFStudio_Field_Group::get_groups_for_save( $post, $rendered );
		if ( empty( $groups ) ) {
			return;
		}

		$posted = isset( $_POST['cfs'] ) ? wp_unslash( $_POST['cfs'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field type sanitizes its own value below.

		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				$type_class = CFStudio_Field_Registry::get_class( $field['type'] );
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
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'profile.php', 'user-edit.php', 'term.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		if ( 'post' === $screen->base ) {
			$current = get_post();
			$groups  = $current ? CFStudio_Field_Group::get_groups_for_post( $current ) : array();
		} else {
			// User profile / term screens: every active group is a
			// candidate; the screen itself decides which render.
			$groups = CFStudio_Field_Group::all_active();
		}

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
		wp_enqueue_style( 'cfs-admin', CFSTUDIO_URL . 'admin/css/admin.css', array(), CFSTUDIO_VERSION );

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
			CFSTUDIO_URL . 'admin/js/meta-box.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			CFSTUDIO_VERSION,
			true
		);

		wp_localize_script(
			'cfs-meta-box',
			'cfsMetaBox',
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'relationshipNonce' => wp_create_nonce( CFStudio_Field_Relationship::NONCE ),
				'i18n'              => array(
					'addImages'   => __( 'Add images', 'custom-fields-studio' ),
					'noResults'   => __( 'No results', 'custom-fields-studio' ),
					'selectImage' => __( 'Select Image', 'custom-fields-studio' ),
					'useImage'    => __( 'Use this image', 'custom-fields-studio' ),
					'selectFile'  => __( 'Select File', 'custom-fields-studio' ),
					'useFile'     => __( 'Use this file', 'custom-fields-studio' ),
				),
			)
		);
	}

	/**
	 * Whether any field in these groups (including nested sub fields) is
	 * of the given type.
	 *
	 * @param array  $groups Groups from CFStudio_Field_Group::get_matching_groups().
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
