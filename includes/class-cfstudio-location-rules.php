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
 * Supported params, by the screen they apply to:
 *  - post screens:   post_type, post_id, post_status, page_template,
 *                    post_category, post_taxonomy
 *  - user profiles:  user_role (the role of the user being edited)
 *  - term screens:   taxonomy
 *  - options pages:  options_page
 *  - custom blocks:  block
 *  - any screen:     current_user_role (the role of whoever is editing)
 *
 * A group whose rules mix params of different screens never matches
 * anywhere: a screen is exactly one kind. A group made only of
 * current_user_role rules has no screen to anchor to, so it never matches.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFStudio_Location_Rules {

	/**
	 * Params available in the location rule builder. Keyed by param key,
	 * value is the label. Extend this array in later phases.
	 *
	 * @return array
	 */
	public static function get_params() {
		return array(
			'post_type'         => __( 'Post Type', 'custom-fields-studio' ),
			'post_id'           => __( 'Post', 'custom-fields-studio' ),
			'post_status'       => __( 'Post Status', 'custom-fields-studio' ),
			'page_template'     => __( 'Page Template', 'custom-fields-studio' ),
			'post_category'     => __( 'Post Category', 'custom-fields-studio' ),
			'post_taxonomy'     => __( 'Post Taxonomy Term', 'custom-fields-studio' ),
			'current_user_role' => __( 'Current User Role', 'custom-fields-studio' ),
			'user_role'         => __( 'User Form (edited user role)', 'custom-fields-studio' ),
			'taxonomy'          => __( 'Taxonomy Term Screen', 'custom-fields-studio' ),
			'options_page'      => __( 'Options Page', 'custom-fields-studio' ),
			'block'             => __( 'Block', 'custom-fields-studio' ),
		);
	}

	/** Params that belong to each kind of admin screen. */
	const SCREEN_PARAMS = array(
		'post'    => array( 'post_type', 'post_id', 'post_status', 'page_template', 'post_category', 'post_taxonomy' ),
		'user'    => array( 'user_role' ),
		'term'    => array( 'taxonomy' ),
		'options' => array( 'options_page' ),
		'block'   => array( 'block' ),
	);

	/** Params that apply on any screen. */
	const NEUTRAL_PARAMS = array( 'current_user_role' );

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

		if ( 'options_page' === $param ) {
			$values = array();
			foreach ( CFStudio_Options_Page::get_pages() as $page ) {
				$values[ $page['menu_slug'] ] = $page['menu_title'];
			}
			return $values;
		}

		if ( 'post_id' === $param ) {
			$values = array();
			$posts  = get_posts(
				array(
					'post_type'   => array_keys( self::get_values_for_param( 'post_type' ) ),
					'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'numberposts' => 200,
					'orderby'     => 'title',
					'order'       => 'ASC',
				)
			);
			foreach ( $posts as $one ) {
				$values[ (string) $one->ID ] = ( '' !== $one->post_title ? $one->post_title : __( '(no title)', 'custom-fields-studio' ) ) . ' (#' . $one->ID . ')';
			}
			return $values;
		}

		if ( 'post_status' === $param ) {
			return array(
				'publish' => __( 'Published', 'custom-fields-studio' ),
				'draft'   => __( 'Draft', 'custom-fields-studio' ),
				'pending' => __( 'Pending Review', 'custom-fields-studio' ),
				'private' => __( 'Private', 'custom-fields-studio' ),
				'future'  => __( 'Scheduled', 'custom-fields-studio' ),
			);
		}

		if ( 'page_template' === $param ) {
			$values = array( 'default' => __( 'Default Template', 'custom-fields-studio' ) );
			if ( function_exists( 'wp_get_theme' ) ) {
				foreach ( wp_get_theme()->get_page_templates( null, 'page' ) as $file => $name ) {
					$values[ $file ] = $name;
				}
			}
			return $values;
		}

		if ( 'post_category' === $param ) {
			$values = array();
			$terms  = get_terms(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => false,
					'number'     => 200,
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$values[ (string) $term->term_id ] = $term->name;
				}
			}
			return $values;
		}

		if ( 'post_taxonomy' === $param ) {
			$values = array();
			foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $tax->name,
						'hide_empty' => false,
						'number'     => 100,
					)
				);
				if ( is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term ) {
					$values[ $tax->name . ':' . $term->slug ] = $tax->labels->singular_name . ': ' . $term->name;
				}
			}
			return $values;
		}

		if ( 'current_user_role' === $param || 'user_role' === $param ) {
			$values = array();
			if ( 'current_user_role' === $param ) {
				$values['super_admin'] = __( 'Super Admin', 'custom-fields-studio' );
			} else {
				$values['all'] = __( 'All roles', 'custom-fields-studio' );
			}
			foreach ( wp_roles()->get_names() as $slug => $name ) {
				$values[ $slug ] = translate_user_role( $name );
			}
			return $values;
		}

		if ( 'block' === $param ) {
			$values = array();
			foreach ( CFStudio_Blocks::get_blocks() as $name => $block ) {
				$values[ $name ] = $block['title'];
			}
			return $values;
		}

		if ( 'taxonomy' === $param ) {
			$values = array( 'all' => __( 'All taxonomies', 'custom-fields-studio' ) );
			foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
				$values[ $tax->name ] = $tax->labels->singular_name;
			}
			return $values;
		}

		return array();
	}

	/**
	 * Every param's values, for the builder's JS (to refill the value
	 * dropdown when a rule's param changes).
	 *
	 * @return array param => ( value => label )
	 */
	public static function get_all_values() {
		$all = array();
		foreach ( array_keys( self::get_params() ) as $param ) {
			$all[ $param ] = self::get_values_for_param( $param );
		}
		return $all;
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
					<div class="cfs-location-or"><?php esc_html_e( 'OR', 'custom-fields-studio' ); ?></div>
				<?php endif; ?>
				<div class="cfs-location-group" data-group="<?php echo esc_attr( $group_index ); ?>">
					<table class="cfs-location-rules-table">
						<tbody>
						<?php foreach ( $rules as $rule_index => $rule ) : ?>
							<?php self::render_rule_row( $group_index, $rule_index, $rule ); ?>
						<?php endforeach; ?>
						</tbody>
					</table>
					<button type="button" class="button cfs-add-rule"><?php esc_html_e( '+ Add Rule (AND)', 'custom-fields-studio' ); ?></button>
				</div>
			<?php endforeach; ?>
			<p>
				<button type="button" class="button cfs-add-rule-group"><?php esc_html_e( '+ Add Rule Group (OR)', 'custom-fields-studio' ); ?></button>
			</p>
		</div>

		<script type="text/html" id="tmpl-cfs-location-rule-row">
			<?php
			self::render_rule_row(
				'__GROUP__',
				'__RULE__',
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'post',
				)
			);
			?>
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
		$name_base = "cfstudio_location[{$group_index}][{$rule_index}]";
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
					<option value="==" <?php selected( $operator, '==' ); ?>><?php esc_html_e( 'is equal to', 'custom-fields-studio' ); ?></option>
					<option value="!=" <?php selected( $operator, '!=' ); ?>><?php esc_html_e( 'is not equal to', 'custom-fields-studio' ); ?></option>
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
				<button type="button" class="button-link cfs-remove-rule" aria-label="<?php esc_attr_e( 'Remove rule', 'custom-fields-studio' ); ?>">&times;</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Sanitize posted location rule data.
	 *
	 * @param array $raw Raw $_POST['cfstudio_location'].
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
	 * Whether a set of location rule groups matches a screen.
	 *
	 * @param array  $groups  Saved rule groups.
	 * @param string $screen  'post', 'user', 'term' or 'options'.
	 * @param array  $context Facts about the screen, keyed by param (see
	 *                        self::post_context() and friends). Array values
	 *                        (e.g. post_category) match when ANY item equals.
	 * @param array  $ignore  Params to treat as passing (used when saving, for
	 *                        facts the same save may change, like post status).
	 * @return bool
	 */
	public static function matches( $groups, $screen, $context, $ignore = array() ) {
		if ( empty( $groups ) || ! isset( self::SCREEN_PARAMS[ $screen ] ) ) {
			return false;
		}

		if ( ! isset( $context['current_user_role'] ) ) {
			$context['current_user_role'] = self::current_user_roles();
		}

		foreach ( $groups as $rules ) {
			$anchored      = false;
			$group_matches = ! empty( $rules );

			foreach ( (array) $rules as $rule ) {
				$param = $rule['param'] ?? '';

				if ( in_array( $param, self::SCREEN_PARAMS[ $screen ], true ) ) {
					$anchored = true;
				} elseif ( ! in_array( $param, self::NEUTRAL_PARAMS, true ) ) {
					$group_matches = false; // A param of another screen.
					break;
				}

				if ( in_array( $param, $ignore, true ) ) {
					continue;
				}

				if ( ! array_key_exists( $param, $context ) ) {
					$group_matches = false; // Nothing known about this fact.
					break;
				}

				$have     = $context[ $param ];
				$have     = array_map( 'strval', (array) $have );
				$want     = (string) ( $rule['value'] ?? '' );
				$is_equal = in_array( $want, $have, true );

				// "all" is a wildcard for user_role / taxonomy rules.
				if ( 'all' === $want && in_array( $param, array( 'user_role', 'taxonomy' ), true ) ) {
					$is_equal = true;
				}

				$passes = ( '!=' === ( $rule['operator'] ?? '==' ) ) ? ! $is_equal : $is_equal;
				if ( ! $passes ) {
					$group_matches = false;
					break;
				}
			}

			if ( $group_matches && $anchored ) {
				return true; // Any matching OR-group is enough.
			}
		}

		return false;
	}

	/**
	 * Roles of the user doing the editing, plus 'super_admin' on multisite.
	 *
	 * @return array
	 */
	public static function current_user_roles() {
		$user  = wp_get_current_user();
		$roles = $user && $user->exists() ? (array) $user->roles : array();

		if ( is_multisite() && is_super_admin() ) {
			$roles[] = 'super_admin';
		}

		return $roles;
	}

	/**
	 * Context for a post edit screen / REST request / template lookup.
	 *
	 * @param WP_Post|int $post Post.
	 * @return array
	 */
	public static function post_context( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return array();
		}

		$template = get_page_template_slug( $post );
		$ctx      = array(
			'post_type'     => $post->post_type,
			'post_id'       => (string) $post->ID,
			'post_status'   => 'auto-draft' === $post->post_status ? 'draft' : $post->post_status,
			'page_template' => $template ? $template : 'default',
			'post_category' => array(),
			'post_taxonomy' => array(),
		);

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$ctx['post_taxonomy'][] = $taxonomy . ':' . $term->slug;
				if ( 'category' === $taxonomy ) {
					$ctx['post_category'][] = (string) $term->term_id;
				}
			}
		}

		return $ctx;
	}

	/**
	 * Whether a set of location rule groups matches the given post type.
	 * Kept for callers that only know the post type; rules about a specific
	 * post (id, status, template, terms) never match here.
	 *
	 * @param array  $groups    Saved rule groups.
	 * @param string $post_type Post type to test against.
	 * @return bool
	 */
	public static function matches_post_type( $groups, $post_type ) {
		return self::matches( $groups, 'post', array( 'post_type' => $post_type ) );
	}

	/**
	 * Whether a set of location rule groups matches the given options page.
	 *
	 * @param array  $groups Saved rule groups.
	 * @param string $slug   Options page menu slug.
	 * @return bool
	 */
	public static function matches_options_page( $groups, $slug ) {
		return self::matches( $groups, 'options', array( 'options_page' => $slug ) );
	}
}
