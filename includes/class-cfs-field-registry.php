<?php
/**
 * Registry of available field types.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Registry {

	/**
	 * Map of type key => fully qualified class name.
	 *
	 * @var array
	 */
	protected static $types = array();

	/**
	 * Register the built-in Phase 1 field types.
	 * Later phases (repeater, flexible_content, gallery, relationship,
	 * taxonomy, date_picker) add themselves here the same way.
	 */
	public static function register_defaults() {
		self::register( 'CFS_Field_Text' );
		self::register( 'CFS_Field_Textarea' );
		self::register( 'CFS_Field_Select' );
		self::register( 'CFS_Field_True_False' );
		self::register( 'CFS_Field_Wysiwyg' );
		self::register( 'CFS_Field_Image' );

		/**
		 * Allow future phases / add-ons to register additional field types.
		 *
		 * @param string $registry_class This class name, for calling ::register() statically.
		 */
		do_action( 'cfs/register_field_types', __CLASS__ );
	}

	/**
	 * Register one field type class. The class must extend CFS_Field_Base
	 * and declare static $type / $label.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function register( $class_name ) {
		if ( ! class_exists( $class_name ) ) {
			return;
		}
		$type = $class_name::$type;
		if ( '' === $type ) {
			return;
		}
		self::$types[ $type ] = $class_name;
	}

	/**
	 * Get the class name for a given type key, or null if unknown.
	 *
	 * @param string $type Field type key.
	 * @return string|null
	 */
	public static function get_class( $type ) {
		return self::$types[ $type ] ?? null;
	}

	/**
	 * All registered types as type => label, for the builder's dropdown.
	 *
	 * @return array
	 */
	public static function get_choices() {
		$choices = array();
		foreach ( self::$types as $type => $class_name ) {
			$choices[ $type ] = $class_name::$label;
		}
		return $choices;
	}

	/**
	 * Get every registered type's class name, keyed by type.
	 *
	 * @return array
	 */
	public static function get_all() {
		return self::$types;
	}
}
