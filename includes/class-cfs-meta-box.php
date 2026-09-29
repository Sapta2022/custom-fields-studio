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

		$seamless = 'seamless' === ( $group['options']['style'] ?? 'default' );

		echo '<div class="cfs-field-values' . ( $seamless ? ' cfs-seamless' : '' ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed strings only.

		foreach ( $group['fields'] as $field ) {
			$this->render_one_field( $post->ID, $field );
		}

		echo '</div>';
	}

	/**
	 * Render one field's label, instructions, and input.
	 *
	 * @param int   $post_id Current post ID.
	 * @param array $field   Field config.
	 */
	protected function render_one_field( $post_id, $field ) {
		$type_class = CFS_Field_Registry::get_class( $field['type'] );
		if ( ! $type_class ) {
			return;
		}

		$value = get_post_meta( $post_id, $field['name'], true );
		// An empty string means "no value saved yet" for most meta reads;
		// pass null through so each field type can apply its own default
		// rather than every type having to special-case ''.
		if ( '' === $value ) {
			$value = null;
		}

		$name = 'cfs[' . $field['name'] . ']';
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
				$raw_value   = $posted[ $field['name'] ] ?? '';
				$clean_value = $type_class::sanitize_value( $raw_value, $field );

				update_post_meta( $post_id, $field['name'], $clean_value );
				// Reference meta pointing at this field's key, so the
				// template API can find a field's config (type + settings)
				// from its stored value alone, without knowing which group
				// it came from — the same pattern ACF uses.
				update_post_meta( $post_id, '_' . $field['name'], $field['key'] );
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

		wp_enqueue_style( 'cfs-admin', CFS_URL . 'admin/css/admin.css', array(), CFS_VERSION );

		// Needed for the Image field's "Select Image" button (wp.media()).
		wp_enqueue_media();

		wp_enqueue_script(
			'cfs-meta-box',
			CFS_URL . 'admin/js/meta-box.js',
			array( 'jquery' ),
			CFS_VERSION,
			true
		);

		wp_localize_script(
			'cfs-meta-box',
			'cfsMetaBox',
			array(
				'i18n' => array(
					'selectImage' => __( 'Select Image', 'cfs' ),
					'useImage'    => __( 'Use this image', 'cfs' ),
				),
			)
		);
	}
}
