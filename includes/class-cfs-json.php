<?php
/**
 * JSON import / export and local JSON sync.
 *
 * Export format (one file can hold several groups):
 *
 *   {
 *     "cfs_version": "0.11.0",
 *     "groups": [
 *       { "key": "group_abc123", "title": "...", "fields": [...],
 *         "location": [...], "options": {...}, "modified": 1700000000 }
 *     ]
 *   }
 *
 * Groups are matched on import by their stable "key", so importing the
 * same file twice updates instead of duplicating.
 *
 * Local JSON: when a "cfs-json" folder exists in the active theme (the
 * folder can be changed with the `cfs/json_dir` filter), every saved field
 * group is also written there as {key}.json, so field groups can live in
 * version control. Files newer than the database copy (or unknown to the
 * database) are offered for sync on the Tools page.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_JSON {

	const MENU_SLUG = 'cfs-tools';

	/** @var bool While true, saves are not mirrored back to the JSON folder. */
	protected static $silent = false;

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_cfs_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_cfs_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_cfs_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_cfs_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'save_post_' . CFS_Field_Group::POST_TYPE, array( __CLASS__, 'write_file_on_save' ), 100, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_file_on_delete' ) );
		add_action( 'trashed_post', array( __CLASS__, 'delete_file_on_delete' ) );
	}

	/* ------------------------------------------------------------------
	 * Data
	 * ---------------------------------------------------------------- */

	/**
	 * One group as exportable data.
	 *
	 * @param int $group_id Field group post ID.
	 * @return array|null
	 */
	public static function export_group( $group_id ) {
		$post = get_post( $group_id );
		if ( ! $post || CFS_Field_Group::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$fields   = get_post_meta( $post->ID, '_cfs_fields', true );
		$location = get_post_meta( $post->ID, '_cfs_location', true );
		$options  = get_post_meta( $post->ID, '_cfs_options', true );

		return array(
			'key'      => CFS_Field_Group::get_group_key( $post->ID ),
			'title'    => $post->post_title,
			'fields'   => is_array( $fields ) ? $fields : array(),
			'location' => is_array( $location ) ? $location : array(),
			'options'  => CFS_Field_Group::sanitize_options( is_array( $options ) ? $options : array( 'active' => 1 ) ),
			'modified' => (int) get_post_modified_time( 'U', true, $post ),
		);
	}

	/**
	 * Every field group (any status but trash) as export data.
	 *
	 * @param array|null $only Group IDs to include, or null for all.
	 * @return array
	 */
	public static function export_groups( $only = null ) {
		$posts = get_posts(
			array(
				'post_type'   => CFS_Field_Group::POST_TYPE,
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'post__in'    => is_array( $only ) && $only ? array_map( 'absint', $only ) : null,
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			$out[] = self::export_group( $post->ID );
		}

		return $out;
	}

	/**
	 * The whole export document as a JSON string.
	 *
	 * @param array|null $only Group IDs, or null for all.
	 * @return string
	 */
	public static function export_json( $only = null ) {
		return wp_json_encode(
			array(
				'cfs_version' => CFS_VERSION,
				'groups'      => self::export_groups( $only ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Import groups from decoded JSON data. Existing groups (same key) are
	 * updated; others are created. Everything goes through the same
	 * sanitizers as the builder, so a hand-edited file cannot store
	 * anything the builder could not.
	 *
	 * @param mixed $data Decoded JSON (the export document, or a single group).
	 * @return array|WP_Error array( 'created' => n, 'updated' => n, 'ids' => int[] )
	 */
	public static function import_data( $data ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cfs_json', __( 'That is not valid JSON.', 'cfs' ) );
		}

		if ( isset( $data['groups'] ) && is_array( $data['groups'] ) ) {
			$groups = $data['groups'];
		} elseif ( isset( $data['title'] ) && isset( $data['fields'] ) ) {
			$groups = array( $data ); // A single exported group.
		} elseif ( isset( $data[0] ) && is_array( $data[0] ) ) {
			$groups = $data; // A bare list of groups.
		} else {
			return new WP_Error( 'cfs_json', __( 'No field groups were found in that file.', 'cfs' ) );
		}

		$result = array(
			'created' => 0,
			'updated' => 0,
			'ids'     => array(),
		);

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || ! isset( $group['title'] ) || ! is_scalar( $group['title'] ) ) {
				continue;
			}

			$id = self::import_group( $group, $created );
			if ( ! $id ) {
				continue;
			}

			$created ? ++$result['created'] : ++$result['updated'];
			$result['ids'][] = $id;
		}

		if ( ! $result['ids'] ) {
			return new WP_Error( 'cfs_json', __( 'No field groups were found in that file.', 'cfs' ) );
		}

		return $result;
	}

	/**
	 * Create or update one group.
	 *
	 * @param array $group   Group data.
	 * @param bool  $created Out: whether a new post was created.
	 * @return int Post ID, or 0.
	 */
	protected static function import_group( $group, &$created ) {
		$created = false;
		$was     = self::$silent;

		self::$silent = true; // The post saves below must not write a half-built file.

		$key     = isset( $group['key'] ) && is_scalar( $group['key'] ) ? sanitize_key( (string) $group['key'] ) : '';
		$title   = sanitize_text_field( (string) $group['title'] );
		$id      = $key ? self::find_group_by_key( $key ) : 0;

		if ( $id ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $title,
				)
			);
		} else {
			$id = wp_insert_post(
				array(
					'post_type'   => CFS_Field_Group::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $title,
				)
			);
			if ( ! $id || is_wp_error( $id ) ) {
				self::$silent = $was;
				return 0;
			}
			$created = true;
			update_post_meta( $id, '_cfs_group_key', $key ? $key : 'group_' . substr( md5( uniqid( (string) $id, true ) ), 0, 13 ) );
		}

		$fields = CFS_Field_Group::sanitize_fields( isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array() );
		update_post_meta( $id, '_cfs_fields', wp_slash( $fields ) );
		update_post_meta( $id, '_cfs_location', CFS_Location_Rules::sanitize( isset( $group['location'] ) ? $group['location'] : array() ) );
		update_post_meta( $id, '_cfs_options', CFS_Field_Group::sanitize_options( isset( $group['options'] ) ? $group['options'] : array( 'active' => 1 ) ) );

		CFS_Field_Group::flush_cache();

		self::$silent = $was;
		if ( ! $was ) {
			self::write_group_file( $id );
		}

		return (int) $id;
	}

	/**
	 * Post ID of the group with a key, or 0.
	 *
	 * @param string $key Group key.
	 * @return int
	 */
	public static function find_group_by_key( $key ) {
		$ids = get_posts(
			array(
				'post_type'   => CFS_Field_Group::POST_TYPE,
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_cfs_group_key', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/* ------------------------------------------------------------------
	 * Local JSON folder
	 * ---------------------------------------------------------------- */

	/**
	 * The local JSON folder, or '' when local JSON is off (the folder must
	 * exist and be writable — creating it is how you switch the feature on).
	 *
	 * @return string
	 */
	public static function json_dir() {
		$dir = (string) apply_filters( 'cfs/json_dir', trailingslashit( get_stylesheet_directory() ) . 'cfs-json' );
		$dir = untrailingslashit( $dir );

		return ( '' !== $dir && is_dir( $dir ) && is_writable( $dir ) ) ? $dir : '';
	}

	/** File path for a group key (keys are sanitize_key()ed, so safe in a path). */
	protected static function file_for( $dir, $key ) {
		return $dir . '/' . sanitize_key( $key ) . '.json';
	}

	/**
	 * Mirror a saved group to the JSON folder.
	 *
	 * @param int     $post_id Group ID.
	 * @param WP_Post $post    Group post.
	 */
	public static function write_file_on_save( $post_id, $post ) {
		if ( self::$silent || wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}

		self::write_group_file( $post_id );
	}

	/**
	 * Write one group's JSON file now.
	 *
	 * @param int $group_id Group ID.
	 * @return bool
	 */
	public static function write_group_file( $group_id ) {
		$dir = self::json_dir();
		if ( '' === $dir ) {
			return false;
		}

		$data = self::export_group( $group_id );
		if ( ! $data ) {
			return false;
		}

		$data['modified'] = time();
		update_post_meta( $group_id, '_cfs_json_modified', $data['modified'] );

		return false !== file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::file_for( $dir, $data['key'] ),
			wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * Remove the JSON file of a deleted/trashed group.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete_file_on_delete( $post_id ) {
		if ( self::$silent || CFS_Field_Group::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$dir = self::json_dir();
		$key = get_post_meta( $post_id, '_cfs_group_key', true );
		if ( '' === $dir || ! is_string( $key ) || '' === $key ) {
			return;
		}

		$file = self::file_for( $dir, $key );
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Groups in the JSON folder that the database does not have yet, or has
	 * an older copy of.
	 *
	 * @return array List of array( key, title, file, modified, status 'new'|'newer', group_id ).
	 */
	public static function pending_sync() {
		$dir = self::json_dir();
		if ( '' === $dir ) {
			return array();
		}

		$pending = array();
		foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
			$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! is_array( $data ) || empty( $data['key'] ) || ! isset( $data['title'] ) ) {
				continue;
			}

			$key      = sanitize_key( (string) $data['key'] );
			$modified = isset( $data['modified'] ) ? (int) $data['modified'] : (int) filemtime( $file );
			$id       = self::find_group_by_key( $key );
			$status   = '';

			if ( ! $id ) {
				$status = 'new';
			} elseif ( $modified > (int) get_post_meta( $id, '_cfs_json_modified', true ) ) {
				$status = 'newer';
			}

			if ( $status ) {
				$pending[] = array(
					'key'      => $key,
					'title'    => (string) $data['title'],
					'file'     => $file,
					'modified' => $modified,
					'status'   => $status,
					'group_id' => $id,
					'data'     => $data,
				);
			}
		}

		return $pending;
	}

	/**
	 * Import every pending file into the database.
	 *
	 * @return int Number of groups synced.
	 */
	public static function sync_all() {
		$count        = 0;
		self::$silent = true;

		foreach ( self::pending_sync() as $item ) {
			$id = self::import_group( $item['data'], $created );
			if ( $id ) {
				update_post_meta( $id, '_cfs_json_modified', $item['modified'] );
				++$count;
			}
		}

		self::$silent = false;

		return $count;
	}

	/* ------------------------------------------------------------------
	 * Admin: Tools page
	 * ---------------------------------------------------------------- */

	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . CFS_Field_Group::POST_TYPE,
			__( 'Tools — Import / Export', 'cfs' ),
			__( 'Tools', 'cfs' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/** URL of the Tools page. */
	public static function page_url( $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => CFS_Field_Group::POST_TYPE,
					'page'      => self::MENU_SLUG,
				),
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$groups  = get_posts(
			array(
				'post_type'   => CFS_Field_Group::POST_TYPE,
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		$pending = self::pending_sync();
		$dir     = self::json_dir();
		$msg     = isset( $_GET['cfs_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['cfs_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$err     = isset( $_GET['cfs_err'] ) ? sanitize_text_field( wp_unslash( $_GET['cfs_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap" id="cfs-tools">
			<h1><?php esc_html_e( 'Custom Fields Studio — Tools', 'cfs' ); ?></h1>

			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-success"><p id="cfs-tools-msg"><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $err ) : ?>
				<div class="notice notice-error"><p id="cfs-tools-err"><?php echo esc_html( $err ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Settings', 'cfs' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cfs-settings-form">
				<input type="hidden" name="action" value="cfs_settings" />
				<?php wp_nonce_field( 'cfs_settings' ); ?>
				<p>
					<label for="cfs-gmaps-key"><strong><?php esc_html_e( 'Google Maps API key', 'cfs' ); ?></strong></label><br />
					<input type="text" id="cfs-gmaps-key" name="cfs_google_maps_api_key" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'cfs_google_maps_api_key', '' ) ); ?>" autocomplete="off" />
					<br /><span class="description"><?php esc_html_e( 'Used by the Google Map field to show a searchable map in the editor (Maps JavaScript API + Places API). Without a key the field still works with typed latitude / longitude.', 'cfs' ); ?></span>
				</p>
				<p>
					<label>
						<input type="checkbox" id="cfs-translate-options" name="cfs_translate_options" value="1" <?php checked( (bool) get_option( CFS_I18n::OPTION, false ) ); ?> />
						<?php esc_html_e( 'Store Options Page values separately for each language (WPML / Polylang)', 'cfs' ); ?>
					</label>
					<br /><span class="description">
						<?php
						echo CFS_I18n::active() ? esc_html__( 'A multilingual plugin is active.', 'cfs' ) : esc_html__( 'No multilingual plugin is active, so this has no effect yet.', 'cfs' );
						?>
					</span>
				</p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Save settings', 'cfs' ); ?></button></p>
			</form>

			<h2><?php esc_html_e( 'Export field groups', 'cfs' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cfs-export-form">
				<input type="hidden" name="action" value="cfs_export" />
				<?php wp_nonce_field( 'cfs_export' ); ?>
				<?php if ( $groups ) : ?>
					<p>
						<?php foreach ( $groups as $group ) : ?>
							<label class="cfs-inline-check">
								<input type="checkbox" name="groups[]" value="<?php echo esc_attr( $group->ID ); ?>" checked="checked" />
								<?php echo esc_html( $group->post_title ); ?>
							</label><br />
						<?php endforeach; ?>
					</p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Download export file (.json)', 'cfs' ); ?></button></p>
				<?php else : ?>
					<p><?php esc_html_e( 'There are no field groups to export yet.', 'cfs' ); ?></p>
				<?php endif; ?>
			</form>

			<h2><?php esc_html_e( 'Import field groups', 'cfs' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="cfs-import-form">
				<input type="hidden" name="action" value="cfs_import" />
				<?php wp_nonce_field( 'cfs_import' ); ?>
				<p><input type="file" name="cfs_import_file" accept=".json,application/json" /></p>
				<p class="description"><?php esc_html_e( 'Groups are matched by their key: a group that already exists is updated, anything else is created.', 'cfs' ); ?></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'cfs' ); ?></button></p>
			</form>

			<h2><?php esc_html_e( 'Local JSON', 'cfs' ); ?></h2>
			<?php if ( '' === $dir ) : ?>
				<p id="cfs-json-off">
					<?php
					printf(
						/* translators: %s: folder path */
						esc_html__( 'Local JSON is off. Create the folder %s (writable) to save every field group as a JSON file there, so field groups can be kept in version control.', 'cfs' ),
						'<code>' . esc_html( trailingslashit( get_stylesheet_directory() ) . 'cfs-json' ) . '</code>'
					);
					?>
				</p>
			<?php else : ?>
				<p id="cfs-json-on">
					<?php
					printf(
						/* translators: %s: folder path */
						esc_html__( 'Local JSON is on. Field groups are saved to %s.', 'cfs' ),
						'<code>' . esc_html( $dir ) . '</code>'
					);
					?>
				</p>
				<?php if ( $pending ) : ?>
					<table class="widefat striped" id="cfs-sync-table" style="max-width:700px">
						<thead><tr><th><?php esc_html_e( 'Field group', 'cfs' ); ?></th><th><?php esc_html_e( 'Status', 'cfs' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $pending as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['title'] ); ?></td>
								<td><?php echo 'new' === $item['status'] ? esc_html__( 'New (not in the database)', 'cfs' ) : esc_html__( 'File is newer than the database', 'cfs' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cfs-sync-form">
						<input type="hidden" name="action" value="cfs_sync" />
						<?php wp_nonce_field( 'cfs_sync' ); ?>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Sync changes', 'cfs' ); ?></button></p>
					</form>
				<?php else : ?>
					<p id="cfs-sync-clean"><?php esc_html_e( 'Everything is in sync.', 'cfs' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** admin-post: save the Tools page settings. */
	public static function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cfs' ), 403 );
		}
		check_admin_referer( 'cfs_settings' );

		$key = isset( $_POST['cfs_google_maps_api_key'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', sanitize_text_field( wp_unslash( $_POST['cfs_google_maps_api_key'] ) ) ) : '';
		update_option( 'cfs_google_maps_api_key', $key, false );
		update_option( CFS_I18n::OPTION, ! empty( $_POST['cfs_translate_options'] ) ? 1 : 0, false );

		self::back( array( 'cfs_msg' => __( 'Settings saved.', 'cfs' ) ) );
	}

	/** admin-post: download an export file. */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cfs' ), 403 );
		}
		check_admin_referer( 'cfs_export' );

		$only = isset( $_POST['groups'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['groups'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="cfs-export-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo self::export_json( $only ? $only : null ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download.
		exit;
	}

	/** admin-post: import an uploaded file. */
	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cfs' ), 403 );
		}
		check_admin_referer( 'cfs_import' );

		$file = isset( $_FILES['cfs_import_file'] ) ? $_FILES['cfs_import_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			self::back( array( 'cfs_err' => __( 'Choose a .json file to import.', 'cfs' ) ) );
		}
		if ( 'json' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) ) {
			self::back( array( 'cfs_err' => __( 'The file must be a .json file.', 'cfs' ) ) );
		}
		if ( (int) $file['size'] > 5 * MB_IN_BYTES ) {
			self::back( array( 'cfs_err' => __( 'That file is too large.', 'cfs' ) ) );
		}

		$data   = json_decode( (string) file_get_contents( $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$result = self::import_data( $data );

		if ( is_wp_error( $result ) ) {
			self::back( array( 'cfs_err' => $result->get_error_message() ) );
		}

		self::back(
			array(
				'cfs_msg' => sprintf(
					/* translators: 1: created count, 2: updated count */
					__( 'Import finished: %1$d created, %2$d updated.', 'cfs' ),
					$result['created'],
					$result['updated']
				),
			)
		);
	}

	/** admin-post: sync the JSON folder into the database. */
	public static function handle_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cfs' ), 403 );
		}
		check_admin_referer( 'cfs_sync' );

		$count = self::sync_all();

		self::back(
			array(
				/* translators: %d: number of groups */
				'cfs_msg' => sprintf( _n( 'Synced %d field group.', 'Synced %d field groups.', $count, 'cfs' ), $count ),
			)
		);
	}

	/** Redirect back to the Tools page and stop. */
	protected static function back( $args ) {
		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}
}
