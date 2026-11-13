<?php
/**
 * REST API exposure.
 *
 * A field group with "Show in REST API" ticked is exposed as:
 *
 *  - a `cfstudio` field on posts, pages and other REST-enabled post types, users
 *    and taxonomy terms (GET: the values; POST/PUT: update the values you
 *    send, by field name). Values are the stored, "raw" values — what you
 *    read is what you can write back. Add `?cfstudio_format=1` to a GET to get
 *    the formatted values the template functions return instead.
 *  - GET/POST /wp-json/cfstudio/v1/options  — Options Page values (reading is
 *    public, like the values on the front end; writing needs manage_options).
 *  - GET /wp-json/cfstudio/v1/groups        — the exposed groups' field schema
 *    (needs edit_posts).
 *
 * Writes go through each field type's own sanitizer, exactly like saving
 * in the admin.
 *
 * @package Fieldloom
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFStudio_REST {

	const NAMESPACE_V1 = 'cfstudio/v1';

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		$args = array(
			'get_callback'    => array( __CLASS__, 'get_callback' ),
			'update_callback' => array( __CLASS__, 'update_callback' ),
			'schema'          => array(
				'description'          => __( 'Fieldloom values, keyed by field name.', 'fieldloom' ),
				'type'                 => 'object',
				'additionalProperties' => true,
				'context'              => array( 'view', 'edit' ),
			),
		);

		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $post_type ) {
			register_rest_field( $post_type, 'cfstudio', $args );
		}
		register_rest_field( 'user', 'cfstudio', $args );
		foreach ( get_taxonomies( array( 'show_in_rest' => true ) ) as $taxonomy ) {
			register_rest_field( $taxonomy, 'cfstudio', $args );
		}

		register_rest_route(
			self::NAMESPACE_V1,
			'/options',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_options' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_options' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/groups',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_groups' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/* ------------------------------------------------------------------
	 * The `cfstudio` field on posts / users / terms
	 * ---------------------------------------------------------------- */

	/** Storage context for a REST object array + object type. */
	protected static function context_for( $object, $object_type ) {
		$id = isset( $object['id'] ) ? (int) $object['id'] : 0;
		if ( ! $id ) {
			return '';
		}

		if ( 'user' === $object_type ) {
			return 'user_' . $id;
		}

		if ( taxonomy_exists( $object_type ) && ! post_type_exists( $object_type ) ) {
			return 'term_' . $id;
		}

		return $id;
	}

	public static function get_callback( $object, $field_name, $request, $object_type ) {
		$ctx = self::context_for( $object, $object_type );
		if ( '' === $ctx ) {
			return null;
		}

		// Private user meta is only for people who may edit that user.
		if ( 'user' === $object_type && ! current_user_can( 'edit_user', (int) $object['id'] ) ) {
			return null;
		}

		$format = $request instanceof WP_REST_Request && '' !== (string) $request->get_param( 'cfstudio_format' ) && '0' !== (string) $request->get_param( 'cfstudio_format' );

		return self::read( cfstudio_groups_for_context( $ctx ), $ctx, $format );
	}

	public static function update_callback( $value, $object, $field_name, $request, $object_type ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'cfstudio_rest_invalid', __( 'The cfstudio value must be an object of field values.', 'fieldloom' ), array( 'status' => 400 ) );
		}

		if ( 'user' === $object_type ) {
			$ctx = 'user_' . $object->ID;
			$ok  = current_user_can( 'edit_user', $object->ID );
		} elseif ( $object instanceof WP_Term ) {
			$ctx = 'term_' . $object->term_id;
			$tax = get_taxonomy( $object->taxonomy );
			$ok  = $tax && current_user_can( $tax->cap->edit_terms );
		} else {
			$ctx = (int) $object->ID;
			$ok  = current_user_can( 'edit_post', $ctx );
		}

		if ( ! $ok ) {
			return new WP_Error( 'cfstudio_rest_forbidden', __( 'You are not allowed to edit these fields.', 'fieldloom' ), array( 'status' => rest_authorization_required_code() ) );
		}

		self::write( cfstudio_groups_for_context( $ctx ), $ctx, $value );

		return true;
	}

	/* ------------------------------------------------------------------
	 * Options
	 * ---------------------------------------------------------------- */

	public static function get_options( $request ) {
		$format = '' !== (string) $request->get_param( 'cfstudio_format' ) && '0' !== (string) $request->get_param( 'cfstudio_format' );

		return rest_ensure_response( self::read( CFStudio_Field_Group::get_options_groups(), 'option', $format ) );
	}

	public static function update_options( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'cfstudio_rest_invalid', __( 'Send a JSON object of field values.', 'fieldloom' ), array( 'status' => 400 ) );
		}

		self::write( CFStudio_Field_Group::get_options_groups(), 'option', $body );

		return rest_ensure_response( self::read( CFStudio_Field_Group::get_options_groups(), 'option', false ) );
	}

	/* ------------------------------------------------------------------
	 * Group schema
	 * ---------------------------------------------------------------- */

	public static function get_groups() {
		$out = array();

		foreach ( CFStudio_Field_Group::all_active() as $group ) {
			if ( empty( $group['options']['show_in_rest'] ) ) {
				continue;
			}
			$out[] = array(
				'key'      => $group['key'],
				'title'    => $group['title'],
				'location' => $group['location'],
				'fields'   => self::describe_fields( $group['fields'] ),
			);
		}

		return rest_ensure_response( $out );
	}

	/** Field list reduced to what an API client needs. */
	protected static function describe_fields( $fields ) {
		$out = array();
		foreach ( $fields as $field ) {
			$class = CFStudio_Field_Registry::get_class( $field['type'] );
			if ( ! $class || ! $class::stores_value() ) {
				continue;
			}
			$item = array(
				'name'     => $field['name'],
				'label'    => $field['label'],
				'type'     => $field['type'],
				'required' => ! empty( $field['required'] ),
			);
			if ( isset( $field['choices'] ) ) {
				$item['choices'] = $field['choices'];
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$item['sub_fields'] = self::describe_fields( $field['sub_fields'] );
			}
			if ( ! empty( $field['layouts'] ) ) {
				$item['layouts'] = array();
				foreach ( $field['layouts'] as $layout ) {
					$item['layouts'][] = array(
						'name'       => $layout['name'] ?? '',
						'label'      => $layout['label'] ?? '',
						'sub_fields' => self::describe_fields( $layout['sub_fields'] ?? array() ),
					);
				}
			}
			$out[] = $item;
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Read / write helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Values of every REST-exposed group's fields.
	 *
	 * @param array      $groups Groups for the context.
	 * @param int|string $ctx    Storage context.
	 * @param bool       $format Formatted (template-style) values instead of raw.
	 * @return array|object Field name => value (an object when empty, so it encodes as {}).
	 */
	public static function read( $groups, $ctx, $format = false ) {
		$values = array();

		foreach ( $groups as $group ) {
			if ( empty( $group['options']['show_in_rest'] ) ) {
				continue;
			}
			foreach ( $group['fields'] as $field ) {
				$class = CFStudio_Field_Registry::get_class( $field['type'] );
				if ( ! $class || ! $class::stores_value() ) {
					continue;
				}

				$value = $class::load_value( $field, $ctx );
				if ( '' === $value || null === $value ) {
					$values[ $field['name'] ] = null;
					continue;
				}

				$values[ $field['name'] ] = $format ? self::plain( $class::format_value( $value, $field ) ) : self::plain( $value );
			}
		}

		return $values ? $values : (object) array();
	}

	/**
	 * Save the values sent for exposed fields. Fields that are not in
	 * $values are left alone; unknown names are ignored.
	 *
	 * @param array      $groups Groups for the context.
	 * @param int|string $ctx    Storage context.
	 * @param array      $values Field name => value.
	 * @return int Number of fields written.
	 */
	public static function write( $groups, $ctx, $values ) {
		$written = 0;

		foreach ( $groups as $group ) {
			if ( empty( $group['options']['show_in_rest'] ) ) {
				continue;
			}
			foreach ( $group['fields'] as $field ) {
				$class = CFStudio_Field_Registry::get_class( $field['type'] );
				if ( ! $class || ! $class::stores_value() || ! array_key_exists( $field['name'], $values ) ) {
					continue;
				}

				// null means "no value": clearing something that has no value is
				// a no-op, so reading a record and writing it back changes nothing.
				if ( null === $values[ $field['name'] ] ) {
					$current = $class::load_value( $field, $ctx );
					if ( '' === $current || null === $current ) {
						continue;
					}
				}

				$class::save_value( $field, null === $values[ $field['name'] ] ? '' : $values[ $field['name'] ], $ctx );
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Turn a value into something that encodes cleanly as JSON: WordPress
	 * objects become small arrays, everything else recurses.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function plain( $value ) {
		if ( $value instanceof WP_Post ) {
			return array(
				'id'        => $value->ID,
				'title'     => get_the_title( $value ),
				'type'      => $value->post_type,
				'permalink' => get_permalink( $value ),
			);
		}
		if ( $value instanceof WP_User ) {
			return array(
				'id'           => $value->ID,
				'display_name' => $value->display_name,
			);
		}
		if ( $value instanceof WP_Term ) {
			return array(
				'id'   => $value->term_id,
				'name' => $value->name,
				'slug' => $value->slug,
			);
		}
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'plain' ), $value );
		}
		if ( is_object( $value ) ) {
			return self::plain( (array) $value );
		}

		return $value;
	}
}
