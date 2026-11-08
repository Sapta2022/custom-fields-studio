<?php
/**
 * Custom Gutenberg blocks with fields.
 *
 * Register a block in PHP:
 *
 *   cfs_register_block( array(
 *       'name'            => 'testimonial',          // becomes "cfs/testimonial"
 *       'title'           => 'Testimonial',
 *       'description'     => 'A quote with an author.',
 *       'category'        => 'text',
 *       'icon'            => 'format-quote',          // a dashicon
 *       'keywords'        => array( 'quote' ),
 *       'supports'        => array( 'align' => true ),
 *       'render_template' => get_theme_file_path( 'blocks/testimonial.php' ),
 *       // or 'render_callback' => function ( $block, $is_preview ) { ... },
 *   ) );
 *
 * ...or drop a folder into the theme: cfs-blocks/{slug}/block.json (name,
 * title, description, category, icon, keywords, supports) + render.php.
 *
 * Then make a field group whose location rule is  Block == Testimonial.
 * Inside the template, cfs_get_field( 'quote' ), cfs_have_rows(), the loop
 * functions etc. read the block's own values. The block's values live in
 * its attributes inside the post content (as a form-encoded string that is
 * re-sanitised through the field types every time the block renders), so
 * nothing is written to post meta.
 *
 * In the editor the block shows a live preview; the toolbar button switches
 * to the field form. (WYSIWYG fields appear as plain textareas in blocks.)
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Blocks {

	const MAX_FORM = 204800; // Bytes of form data a block may carry.

	/** @var array Registered blocks, full name => settings. */
	protected static $blocks = array();

	/** @var array Names of the blocks being rendered (innermost last). */
	protected static $stack = array();

	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'discover' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_all' ), 30 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register a block.
	 *
	 * @param array $args See the file header.
	 * @return string|WP_Error The full block name.
	 */
	public static function register( $args ) {
		$slug = isset( $args['name'] ) ? sanitize_key( str_replace( 'cfs/', '', (string) $args['name'] ) ) : '';
		if ( '' === $slug ) {
			return new WP_Error( 'cfs_block', __( 'A block needs a name.', 'cfs' ) );
		}

		$name = 'cfs/' . $slug;
		$args = wp_parse_args(
			$args,
			array(
				'title'           => ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
				'description'     => '',
				'category'        => 'widgets',
				'icon'            => 'screenoptions',
				'keywords'        => array(),
				'supports'        => array(),
				'render_template' => '',
				'render_callback' => null,
			)
		);

		self::$blocks[ $name ] = array(
			'name'            => $name,
			'title'           => sanitize_text_field( $args['title'] ),
			'description'     => sanitize_text_field( $args['description'] ),
			'category'        => sanitize_key( $args['category'] ),
			'icon'            => sanitize_key( $args['icon'] ),
			'keywords'        => array_map( 'sanitize_text_field', (array) $args['keywords'] ),
			'supports'        => is_array( $args['supports'] ) ? $args['supports'] : array(),
			'render_template' => is_string( $args['render_template'] ) ? $args['render_template'] : '',
			'render_callback' => is_callable( $args['render_callback'] ) ? $args['render_callback'] : null,
		);

		return $name;
	}

	/** @return array Registered blocks, full name => settings. */
	public static function get_blocks() {
		return self::$blocks;
	}

	/** Name of the block being rendered right now, or ''. */
	public static function current() {
		return self::$stack ? (string) end( self::$stack ) : '';
	}

	/** Whether a block is rendering. */
	public static function in_block() {
		return (bool) self::$stack;
	}

	/** Register blocks defined by a theme's cfs-blocks/{slug}/block.json. */
	public static function discover() {
		$dir = (string) apply_filters( 'cfs/blocks_dir', trailingslashit( get_stylesheet_directory() ) . 'cfs-blocks' );
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( trailingslashit( $dir ) . '*/block.json' ) as $file ) {
			$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! is_array( $json ) ) {
				continue;
			}

			$folder = dirname( $file );
			$json['name'] = isset( $json['name'] ) ? $json['name'] : basename( $folder );
			if ( empty( $json['render_template'] ) && is_file( $folder . '/render.php' ) ) {
				$json['render_template'] = $folder . '/render.php';
			} elseif ( ! empty( $json['render_template'] ) ) {
				$json['render_template'] = $folder . '/' . ltrim( (string) $json['render_template'], '/\\' );
			}

			self::register( $json );
		}
	}

	/** Register every block with WordPress (server side render). */
	public static function register_all() {
		foreach ( self::$blocks as $name => $block ) {
			if ( WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				continue;
			}

			register_block_type(
				$name,
				array(
					'api_version'     => 2,
					'title'           => $block['title'],
					'description'     => $block['description'],
					'category'        => $block['category'],
					'icon'            => $block['icon'],
					'keywords'        => $block['keywords'],
					'supports'        => array_merge( array( 'html' => false ), $block['supports'] ),
					'attributes'      => array(
						'form'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'align' => array( 'type' => 'string' ),
					),
					'render_callback' => array( __CLASS__, 'render_callback' ),
				)
			);
		}
	}

	/** register_block_type() render callback. */
	public static function render_callback( $attributes, $content = '', $block = null ) {
		$name = $block instanceof WP_Block ? $block->name : '';
		if ( '' === $name || ! isset( self::$blocks[ $name ] ) ) {
			return '';
		}

		return self::render( $name, isset( $attributes['form'] ) ? (string) $attributes['form'] : '', $attributes );
	}

	/**
	 * Load a block's posted form into the 'block' storage context,
	 * sanitising every value through its field type.
	 *
	 * @param string $name Block name.
	 * @param string $form application/x-www-form-urlencoded string.
	 * @return array The groups for the block.
	 */
	protected static function fill( $name, $form ) {
		$groups = CFS_Field_Group::get_groups_for_block( $name );
		$posted = array();

		if ( '' !== $form && strlen( $form ) <= self::MAX_FORM ) {
			parse_str( $form, $parsed );
			$posted = isset( $parsed['cfs'] ) && is_array( $parsed['cfs'] ) ? $parsed['cfs'] : array();
		}

		CFS_Meta_Box::save_groups( $groups, 'block', $posted );

		return $groups;
	}

	/**
	 * Render a block's front-end HTML.
	 *
	 * @param string $name       Block name.
	 * @param string $form       The block's stored form string.
	 * @param array  $attributes Block attributes.
	 * @param bool   $is_preview Rendering the editor preview.
	 * @return string
	 */
	public static function render( $name, $form, $attributes = array(), $is_preview = false ) {
		if ( ! isset( self::$blocks[ $name ] ) ) {
			return '';
		}

		$def      = self::$blocks[ $name ];
		$previous = CFS_Storage::swap_block_values( array() );
		self::$stack[] = $name;

		self::fill( $name, $form );

		$block = array(
			'name'       => $name,
			'title'      => $def['title'],
			'align'      => isset( $attributes['align'] ) ? sanitize_key( $attributes['align'] ) : '',
			'className'  => 'wp-block-' . str_replace( '/', '-', $name ),
			'is_preview' => $is_preview,
		);

		ob_start();
		if ( $def['render_callback'] ) {
			call_user_func( $def['render_callback'], $block, $is_preview );
		} elseif ( '' !== $def['render_template'] ) {
			$template = self::safe_template( $def['render_template'] );
			if ( $template ) {
				include $template; // $block and $is_preview are in scope.
			}
		}
		$html = ob_get_clean();

		array_pop( self::$stack );
		CFS_Storage::swap_block_values( $previous );

		$wrapper = 'cfs-block ' . $block['className'] . ( '' !== $block['align'] ? ' align' . $block['align'] : '' );

		return '<div class="' . esc_attr( $wrapper ) . '">' . $html . '</div>';
	}

	/**
	 * A template path, only when it is a real PHP file inside the active
	 * (child) theme, parent theme or a plugin/mu-plugin folder — never an
	 * arbitrary path.
	 */
	protected static function safe_template( $path ) {
		$real = realpath( $path );
		if ( ! $real || 'php' !== strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ) ) {
			return '';
		}

		$roots = array( get_stylesheet_directory(), get_template_directory(), WP_PLUGIN_DIR, WPMU_PLUGIN_DIR );
		foreach ( (array) apply_filters( 'cfs/block_template_roots', $roots ) as $root ) {
			$root = realpath( $root );
			if ( $root && 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
				return $real;
			}
		}

		return '';
	}

	/* ------------------------------------------------------------------
	 * Editor
	 * ---------------------------------------------------------------- */

	public static function enqueue_editor() {
		if ( ! self::$blocks ) {
			return;
		}

		CFS_Meta_Box::enqueue_assets( CFS_Field_Group::all_active() );

		wp_enqueue_script(
			'cfs-blocks',
			CFS_URL . 'admin/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-api-fetch', 'jquery', 'cfs-meta-box' ),
			CFS_VERSION,
			true
		);

		wp_localize_script(
			'cfs-blocks',
			'cfsBlocks',
			array(
				'blocks' => array_values( self::$blocks ),
				'i18n'   => array(
					'editFields' => __( 'Edit fields', 'cfs' ),
					'preview'    => __( 'Preview', 'cfs' ),
					'loading'    => __( 'Loading fields…', 'cfs' ),
					'noFields'   => __( 'No field group is assigned to this block yet. Create a field group with the location rule Block == this block.', 'cfs' ),
				),
			)
		);
	}

	public static function register_routes() {
		register_rest_route(
			CFS_REST::NAMESPACE_V1,
			'/block-form',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_form' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'block' => array( 'type' => 'string', 'required' => true ),
					'form'  => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
	}

	/** REST: the field form HTML for a block, filled from its stored form string. */
	public static function rest_form( $request ) {
		$name = (string) $request->get_param( 'block' );
		if ( ! isset( self::$blocks[ $name ] ) ) {
			return new WP_Error( 'cfs_block', __( 'Unknown block.', 'cfs' ), array( 'status' => 404 ) );
		}

		$previous      = CFS_Storage::swap_block_values( array() );
		self::$stack[] = $name;
		$groups        = self::fill( $name, (string) $request->get_param( 'form' ) );

		ob_start();
		foreach ( $groups as $group ) {
			CFS_Meta_Box::render_group_fields( $group, 'block' );
		}
		$html = ob_get_clean();

		array_pop( self::$stack );
		CFS_Storage::swap_block_values( $previous );

		return rest_ensure_response(
			array(
				'html'   => $html,
				'groups' => count( $groups ),
			)
		);
	}
}
