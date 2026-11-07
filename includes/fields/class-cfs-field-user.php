<?php
/**
 * User field: pick one or more users, optionally limited to a role.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_User extends CFS_Field_Base {

	public static $type  = 'user';
	public static $label = 'User';

	/** Most users listed in the dropdown. */
	const LIMIT = 1000;

	public static function defaults() {
		return array(
			'role'          => '',
			'multiple'      => 0,
			'allow_null'    => 0,
			'return_format' => 'array',
		);
	}

	protected static function roles() {
		$roles = array( '' => __( 'All roles', 'cfs' ) );
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$roles[ $slug ] = translate_user_role( $name );
		}

		return $roles;
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_select( 'user', $prefix, 'role', __( 'Filter by Role', 'cfs' ), $field, self::roles(), '' );
		self::setting_checkbox( 'user', $prefix, 'multiple', __( 'Select multiple values', 'cfs' ), $field );
		self::setting_checkbox( 'user', $prefix, 'allow_null', __( 'Allow Null (adds an empty first choice)', 'cfs' ), $field );
		self::setting_select(
			'user',
			$prefix,
			'return_format',
			__( 'Return Format', 'cfs' ),
			$field,
			array(
				'array'  => __( 'User Array', 'cfs' ),
				'object' => __( 'User Object', 'cfs' ),
				'id'     => __( 'User ID', 'cfs' ),
			),
			'array'
		);
	}

	public static function sanitize( $raw ) {
		$role = self::text_setting( $raw, 'role' );

		return array(
			'role'          => array_key_exists( $role, self::roles() ) ? $role : '',
			'multiple'      => ! empty( $raw['multiple'] ) ? 1 : 0,
			'allow_null'    => ! empty( $raw['allow_null'] ) ? 1 : 0,
			'return_format' => self::pick( $raw, 'return_format', array( 'array', 'object', 'id' ), 'array' ),
		);
	}

	public static function render_input( $field, $value, $name ) {
		$multiple = ! empty( $field['multiple'] );
		$selected = array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) );
		$users    = get_users(
			array(
				'role'    => $field['role'] ?? '',
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'number'  => self::LIMIT,
				'fields'  => array( 'ID', 'display_name' ),
			)
		);
		if ( $multiple ) {
			printf( '<input type="hidden" name="%s[]" value="" />', esc_attr( $name ) );
		}
		printf( '<select name="%1$s"%2$s>', esc_attr( $multiple ? $name . '[]' : $name ), $multiple ? ' multiple="multiple" size="6"' : '' );
		if ( ! $multiple && ! empty( $field['allow_null'] ) ) {
			echo '<option value="">' . esc_html__( '— Select —', 'cfs' ) . '</option>';
		}
		foreach ( $users as $user ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $user->ID,
				selected( in_array( (string) $user->ID, $selected, true ), true, false ),
				esc_html( $user->display_name )
			);
		}
		echo '</select>';
	}

	/** Existing users (of the allowed role), unique, in order. */
	protected static function clean_ids( $value, $field, $max = 0 ) {
		$ids = array();
		foreach ( (array) $value as $id ) {
			$id = is_scalar( $id ) ? absint( $id ) : 0;
			if ( ! $id || in_array( $id, $ids, true ) ) {
				continue;
			}
			$user = get_userdata( $id );
			if ( ! $user ) {
				continue;
			}
			if ( ! empty( $field['role'] ) && ! in_array( $field['role'], (array) $user->roles, true ) ) {
				continue;
			}
			$ids[] = $id;
		}

		return $max > 0 ? array_slice( $ids, 0, $max ) : $ids;
	}

	public static function sanitize_value( $raw_value, $field ) {
		$multiple = ! empty( $field['multiple'] );
		$ids      = self::clean_ids( $raw_value, $field, $multiple ? 0 : 1 );

		return $multiple ? $ids : ( $ids ? $ids[0] : '' );
	}

	public static function format_value( $value, $field ) {
		$format = $field['return_format'] ?? 'array';
		$out    = array();
		foreach ( self::clean_ids( $value, $field ) as $id ) {
			if ( 'id' === $format ) {
				$out[] = $id;
				continue;
			}
			$user = get_userdata( $id );
			if ( 'object' === $format ) {
				$out[] = $user;
				continue;
			}
			// No email address here: it would end up on public pages.
			$out[] = array(
				'ID'              => $id,
				'display_name'    => $user->display_name,
				'nickname'        => $user->nickname,
				'user_nicename'   => $user->user_nicename,
				'user_url'        => $user->user_url,
				'user_firstname'  => $user->first_name,
				'user_lastname'   => $user->last_name,
				'user_description' => $user->description,
			);
		}
		if ( ! $out ) {
			return null;
		}

		return empty( $field['multiple'] ) ? $out[0] : $out;
	}
}
