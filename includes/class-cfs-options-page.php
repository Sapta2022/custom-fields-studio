<?php
/**
 * Options Pages — admin screens that hold site-wide field values instead of
 * per-post ones.
 *
 * Pages come from two places:
 *  - code:  cfs_add_options_page( array( ... ) )  (e.g. in a theme's
 *           functions.php or a client plugin) — always wins on a clash;
 *  - the UI: Field Groups → Options Pages, stored in one option.
 *
 * A field group appears on a page through the location rule
 * "Options Page is equal to <page>". Values are stored through
 * CFS_Storage in the 'option' context (wp_options, "options_" prefix,
 * not autoloaded) and read with cfs_get_field( 'name', 'option' ).
 *
 * All options pages share one storage namespace, like ACF's, so field
 * names should be unique across them.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Options_Page {

	/** Option holding the pages defined in the UI. */
	const OPTION_PAGES = 'cfs_options_pages';

	const NONCE_FIELD  = 'cfs_options_nonce';
	const NONCE_ACTION = 'cfs_save_options_';

	/** Menu slug of the "Options Pages" manager screen. */
	const MANAGER_SLUG = 'cfs-options-pages';

	/**
	 * Pages registered from code, keyed by menu slug.
	 *
	 * @var array
	 */
	protected static $code_pages = array();

	/**
	 * Hook suffix => menu slug for every options page we added, so assets
	 * and save handlers know which page they are on.
	 *
	 * @var array
	 */
	protected static $hooks = array();

	protected static $manager_hook = '';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/* ------------------------------------------------------------------
	 * Registry
	 * ---------------------------------------------------------------- */

	/**
	 * Register a page from code. See cfs_add_options_page().
	 *
	 * @param array $args Page args.
	 * @return string|false The menu slug, or false when the args are unusable.
	 */
	public static function add( $args = array() ) {
		$page = self::normalize( (array) $args );
		if ( ! $page ) {
			return false;
		}

		self::$code_pages[ $page['menu_slug'] ] = $page;

		return $page['menu_slug'];
	}

	/**
	 * Fill in defaults and sanitize one page definition.
	 *
	 * @param array $args Raw args.
	 * @return array|null Normalized page, or null if it has no title/slug.
	 */
	public static function normalize( $args ) {
		$page = wp_parse_args(
			$args,
			array(
				'page_title'     => '',
				'menu_title'     => '',
				'menu_slug'      => '',
				'capability'     => 'manage_options',
				'parent_slug'    => '',
				'icon_url'       => 'dashicons-admin-generic',
				'position'       => null,
				'update_button'  => '',
				'updated_message' => '',
			)
		);

		$page['page_title'] = sanitize_text_field( $page['page_title'] );
		$page['menu_title'] = sanitize_text_field( $page['menu_title'] );

		if ( '' === $page['page_title'] ) {
			$page['page_title'] = $page['menu_title'];
		}
		if ( '' === $page['menu_title'] ) {
			$page['menu_title'] = $page['page_title'];
		}

		$slug = sanitize_key( $page['menu_slug'] );
		if ( '' === $slug && '' !== $page['menu_title'] ) {
			$slug = sanitize_key( sanitize_title( $page['menu_title'] ) );
		}
		if ( '' === $slug || '' === $page['page_title'] ) {
			return null;
		}

		$page['menu_slug']       = $slug;
		$page['capability']      = '' !== (string) $page['capability'] ? sanitize_key( $page['capability'] ) : 'manage_options';
		$page['parent_slug']     = self::sanitize_parent( $page['parent_slug'] );
		$page['icon_url']        = self::sanitize_icon( $page['icon_url'] );
		$page['position']        = ( null === $page['position'] || '' === $page['position'] ) ? null : (float) $page['position'];
		$page['update_button']   = sanitize_text_field( $page['update_button'] );
		$page['updated_message'] = sanitize_text_field( $page['updated_message'] );

		return $page;
	}

	/**
	 * Menu icon: a dashicon class, "none", or an http(s) image URL.
	 */
	protected static function sanitize_icon( $icon ) {
		$icon = trim( (string) $icon );

		if ( preg_match( '/^(dashicons-[a-z0-9\-]+|none)$/', $icon ) ) {
			return $icon;
		}

		if ( preg_match( '#^https?://#i', $icon ) ) {
			return esc_url_raw( $icon );
		}

		return 'dashicons-admin-generic';
	}

	/**
	 * A parent slug is a menu slug or a core admin file such as
	 * "options-general.php" / "edit.php?post_type=page" — keep those
	 * characters, drop the rest.
	 */
	protected static function sanitize_parent( $parent ) {
		return preg_replace( '/[^A-Za-z0-9_\-\.\?=&]/', '', (string) $parent );
	}

	/**
	 * Pages defined in the UI.
	 *
	 * @return array Normalized pages keyed by slug.
	 */
	public static function get_ui_pages() {
		$saved = get_option( self::OPTION_PAGES, array() );
		$pages = array();

		foreach ( is_array( $saved ) ? $saved : array() as $raw ) {
			$page = is_array( $raw ) ? self::normalize( $raw ) : null;
			if ( $page ) {
				$pages[ $page['menu_slug'] ] = $page;
			}
		}

		return $pages;
	}

	/**
	 * Every options page, code pages overriding UI pages with the same slug.
	 *
	 * @return array Normalized pages keyed by slug.
	 */
	public static function get_pages() {
		return array_merge( self::get_ui_pages(), self::$code_pages );
	}

	public static function get_page( $slug ) {
		$pages = self::get_pages();

		return $pages[ $slug ] ?? null;
	}

	/* ------------------------------------------------------------------
	 * Menus
	 * ---------------------------------------------------------------- */

	public static function register_menus() {
		$pages = self::get_pages();

		// Top-level pages first so sub pages can hang off them.
		foreach ( $pages as $slug => $page ) {
			if ( '' !== $page['parent_slug'] ) {
				continue;
			}
			$hook = add_menu_page(
				$page['page_title'],
				$page['menu_title'],
				$page['capability'],
				$slug,
				array( __CLASS__, 'render_page' ),
				$page['icon_url'],
				$page['position']
			);
			self::track( $hook, $slug );
		}

		foreach ( $pages as $slug => $page ) {
			if ( '' === $page['parent_slug'] ) {
				continue;
			}
			$hook = add_submenu_page(
				$page['parent_slug'],
				$page['page_title'],
				$page['menu_title'],
				$page['capability'],
				$slug,
				array( __CLASS__, 'render_page' ),
				$page['position']
			);
			self::track( $hook, $slug );
		}

		// The manager lives under Field Groups.
		self::$manager_hook = (string) add_submenu_page(
			'edit.php?post_type=' . CFS_Field_Group::POST_TYPE,
			__( 'Options Pages', 'cfs' ),
			__( 'Options Pages', 'cfs' ),
			'manage_options',
			self::MANAGER_SLUG,
			array( __CLASS__, 'render_manager' )
		);
		if ( self::$manager_hook ) {
			add_action( 'load-' . self::$manager_hook, array( __CLASS__, 'maybe_save_manager' ) );
		}
	}

	protected static function track( $hook, $slug ) {
		if ( ! $hook ) {
			return;
		}
		self::$hooks[ $hook ] = $slug;
		add_action(
			'load-' . $hook,
			function () use ( $slug ) {
				self::maybe_save( $slug );
			}
		);
	}

	/**
	 * Admin URL of an options page.
	 */
	public static function page_url( $slug ) {
		return html_entity_decode( menu_page_url( $slug, false ) );
	}

	/* ------------------------------------------------------------------
	 * An options page: render + save
	 * ---------------------------------------------------------------- */

	public static function render_page() {
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$page = self::get_page( $slug );
		if ( ! $page ) {
			return;
		}

		$groups = CFS_Field_Group::get_options_groups( $slug );
		$button = '' !== $page['update_button'] ? $page['update_button'] : __( 'Save Options', 'cfs' );
		$notice = '' !== $page['updated_message'] ? $page['updated_message'] : __( 'Options saved.', 'cfs' );

		echo '<div class="wrap cfs-options-page">';
		echo '<h1>' . esc_html( $page['page_title'] ) . '</h1>';

		if ( isset( $_GET['cfs-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}

		if ( empty( $groups ) ) {
			printf(
				'<p>%s</p>',
				sprintf(
					/* translators: 1: page title, 2: link to add a field group. */
					esc_html__( 'No field groups are assigned to “%1$s” yet. %2$s and set its location to “Options Page is equal to %1$s”.', 'cfs' ),
					esc_html( $page['menu_title'] ),
					'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . CFS_Field_Group::POST_TYPE ) ) . '">' . esc_html__( 'Add a field group', 'cfs' ) . '</a>'
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput -- every part is escaped above.
			echo '</div>';
			return;
		}

		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_ACTION . $slug, self::NONCE_FIELD );

		foreach ( $groups as $group ) {
			echo '<div class="postbox cfs-options-box"><div class="postbox-header"><h2 class="hndle">' . esc_html( $group['title'] ) . '</h2></div><div class="inside">';
			if ( empty( $group['fields'] ) ) {
				echo '<p>' . esc_html__( 'This field group has no fields yet.', 'cfs' ) . '</p>';
			} else {
				CFS_Meta_Box::render_group_fields( $group, 'option' );
			}
			echo '</div></div>';
		}

		submit_button( $button );
		echo '</form></div>';
	}

	/**
	 * Runs on load-{page}, before any output, so it can redirect.
	 */
	public static function maybe_save( $slug ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		$page = self::get_page( $slug );
		if ( ! $page
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION . $slug )
			|| ! current_user_can( $page['capability'] ) ) {
			return;
		}

		$posted = isset( $_POST['cfs'] ) ? wp_unslash( $_POST['cfs'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field type sanitizes its own value.

		foreach ( CFS_Field_Group::get_options_groups( $slug ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				$type_class = CFS_Field_Registry::get_class( $field['type'] );
				if ( $type_class ) {
					$type_class::save_value( $field, $posted[ $field['name'] ] ?? '', 'option' );
				}
			}
		}

		/**
		 * Fires after an options page's values were saved.
		 *
		 * @param string $slug Options page menu slug.
		 */
		do_action( 'cfs/options_saved', $slug );

		wp_safe_redirect( add_query_arg( 'cfs-updated', '1', self::page_url( $slug ) ) );
		exit;
	}

	public static function enqueue( $hook ) {
		if ( self::$manager_hook && $hook === self::$manager_hook ) {
			wp_enqueue_style( 'cfs-admin', CFS_URL . 'admin/css/admin.css', array(), CFS_VERSION );
			wp_enqueue_script( 'cfs-options-pages', CFS_URL . 'admin/js/options-pages.js', array( 'jquery' ), CFS_VERSION, true );
			return;
		}

		if ( ! isset( self::$hooks[ $hook ] ) ) {
			return;
		}

		$groups = CFS_Field_Group::get_options_groups( self::$hooks[ $hook ] );
		if ( ! empty( $groups ) ) {
			CFS_Meta_Box::enqueue_assets( $groups );
		}
	}

	/* ------------------------------------------------------------------
	 * Manager screen (Field Groups → Options Pages)
	 * ---------------------------------------------------------------- */

	/**
	 * Parent menu choices for a page.
	 *
	 * @return array slug => label
	 */
	protected static function parent_choices() {
		$choices = array(
			''                    => __( '— Top level menu —', 'cfs' ),
			'options-general.php' => __( 'Settings', 'cfs' ),
			'themes.php'          => __( 'Appearance', 'cfs' ),
			'tools.php'           => __( 'Tools', 'cfs' ),
			'edit.php'            => __( 'Posts', 'cfs' ),
			'upload.php'          => __( 'Media', 'cfs' ),
		);

		foreach ( self::get_pages() as $slug => $page ) {
			if ( '' === $page['parent_slug'] ) {
				/* translators: %s: options page menu title */
				$choices[ $slug ] = sprintf( __( 'Under “%s”', 'cfs' ), $page['menu_title'] );
			}
		}

		return $choices;
	}

	public static function render_manager() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ui    = self::get_ui_pages();
		$code  = self::$code_pages;
		$index = 0;

		echo '<div class="wrap cfs-options-manager"><h1>' . esc_html__( 'Options Pages', 'cfs' ) . '</h1>';

		if ( isset( $_GET['cfs-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Options pages saved.', 'cfs' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Create admin pages that hold site-wide values. Then add a field group whose location is “Options Page is equal to …”. Read the values with cfs_get_field( \'name\', \'option\' ).', 'cfs' ) . '</p>';

		if ( $code ) {
			echo '<h2>' . esc_html__( 'Registered in code', 'cfs' ) . '</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Title', 'cfs' ) . '</th><th>' . esc_html__( 'Slug', 'cfs' ) . '</th><th>' . esc_html__( 'Parent', 'cfs' ) . '</th></tr></thead><tbody>';
			foreach ( $code as $slug => $page ) {
				echo '<tr><td>' . esc_html( $page['menu_title'] ) . '</td><td><code>' . esc_html( $slug ) . '</code></td><td>' . esc_html( $page['parent_slug'] ?: '—' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Created here', 'cfs' ) . '</h2>';
		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_ACTION . self::MANAGER_SLUG, self::NONCE_FIELD );
		echo '<table class="widefat cfs-options-pages-table" style="max-width:1100px"><thead><tr>';
		foreach ( array( 'Page title', 'Menu title', 'Slug', 'Parent', 'Capability', 'Icon (top level)', '' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody id="cfs-options-pages-rows">';

		foreach ( $ui as $page ) {
			self::render_manager_row( $index++, $page );
		}

		echo '</tbody></table>';
		echo '<script type="text/html" id="tmpl-cfs-options-page-row">';
		self::render_manager_row( '__INDEX__', array() );
		echo '</script>';
		echo '<p><button type="button" class="button" id="cfs-add-options-page">' . esc_html__( '+ Add Options Page', 'cfs' ) . '</button></p>';
		submit_button( __( 'Save Options Pages', 'cfs' ) );
		echo '</form></div>';
	}

	protected static function render_manager_row( $index, $page ) {
		$name   = 'cfs_options_pages[' . $index . ']';
		$parent = $page['parent_slug'] ?? '';
		?>
		<tr class="cfs-options-page-row">
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[page_title]" value="<?php echo esc_attr( $page['page_title'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Site Options', 'cfs' ); ?>" /></td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[menu_title]" value="<?php echo esc_attr( $page['menu_title'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Site Options', 'cfs' ); ?>" /></td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[menu_slug]" value="<?php echo esc_attr( $page['menu_slug'] ?? '' ); ?>" placeholder="site-options" /></td>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[parent_slug]">
					<?php foreach ( self::parent_choices() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $parent, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[capability]" value="<?php echo esc_attr( $page['capability'] ?? 'manage_options' ); ?>" /></td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[icon_url]" value="<?php echo esc_attr( $page['icon_url'] ?? 'dashicons-admin-generic' ); ?>" /></td>
			<td><button type="button" class="button-link cfs-remove-options-page" aria-label="<?php esc_attr_e( 'Remove', 'cfs' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	public static function maybe_save_manager() {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION . self::MANAGER_SLUG )
			|| ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$raw   = isset( $_POST['cfs_options_pages'] ) ? wp_unslash( $_POST['cfs_options_pages'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- normalized below.
		$clean = array();
		$seen  = array();

		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			$page = is_array( $row ) ? self::normalize( $row ) : null;
			// Skip blank rows, duplicate slugs, and the manager's own slug.
			if ( ! $page || isset( $seen[ $page['menu_slug'] ] ) || self::MANAGER_SLUG === $page['menu_slug'] ) {
				continue;
			}
			$seen[ $page['menu_slug'] ] = true;
			$clean[]                    = array(
				'page_title'  => $page['page_title'],
				'menu_title'  => $page['menu_title'],
				'menu_slug'   => $page['menu_slug'],
				'parent_slug' => $page['parent_slug'],
				'capability'  => $page['capability'],
				'icon_url'    => $page['icon_url'],
			);
		}

		update_option( self::OPTION_PAGES, $clean );

		wp_safe_redirect( add_query_arg( 'cfs-updated', '1', self::page_url( self::MANAGER_SLUG ) ) );
		exit;
	}
}
