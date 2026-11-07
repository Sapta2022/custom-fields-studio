<?php
/**
 * [cfs] shortcode — render a field's value on the front end without
 * writing PHP. Meant for quick pages and for checking that a field group
 * works; real themes should use the template functions for custom markup.
 *
 *   [cfs field="sections"]                 Current post's field.
 *   [cfs field="sections" post_id="21"]    A specific post.
 *   [cfs field="phone" post_id="option"]   A value from an Options Page.
 *
 * Output is plain, class-hooked HTML (cfs-out-*) so a theme can style it.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Shortcode {

	public static function init() {
		add_shortcode( 'cfs', array( __CLASS__, 'render_shortcode' ) );
	}

	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'field'   => '',
				'post_id' => null,
			),
			$atts,
			'cfs'
		);

		if ( '' === $atts['field'] ) {
			return '';
		}

		$post_id = cfs_resolve_post_id( $atts['post_id'] );
		if ( ! $post_id ) {
			return '';
		}

		// Respect post visibility: don't leak fields of drafts/private posts.
		// (Options page values are site-wide and always public.)
		if ( 'option' !== $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post_id ) ) ) {
				return '';
			}
		}

		$field = CFS_Field_Group::find_field(
			cfs_groups_for_context( $post_id ),
			sanitize_key( $atts['field'] )
		);
		if ( ! $field ) {
			return '';
		}

		$value = cfs_get_field( $field['name'], $post_id );

		return self::render_field( $field, $value );
	}

	/**
	 * Render one formatted value according to its field config.
	 *
	 * @param array $field Field config.
	 * @param mixed $value Value as returned by cfs_get_field().
	 * @return string HTML (already escaped).
	 */
	public static function render_field( $field, $value ) {
		if ( null === $value || '' === $value || array() === $value ) {
			return '';
		}

		$type = $field['type'] ?? 'text';

		switch ( $type ) {
			case 'repeater':
				return self::render_rows( $field['sub_fields'] ?? array(), $value, 'cfs-out-repeater' );

			case 'flexible_content':
				$html = '<div class="cfs-out cfs-out-flexible">';
				foreach ( (array) $value as $row ) {
					$layout = null;
					foreach ( (array) ( $field['layouts'] ?? array() ) as $candidate ) {
						if ( ( $candidate['name'] ?? '' ) === ( $row[ CFS_Field_Flexible::LAYOUT_KEY ] ?? '' ) ) {
							$layout = $candidate;
							break;
						}
					}
					if ( ! $layout ) {
						continue;
					}
					$html .= '<section class="cfs-out-row cfs-out-layout-' . esc_attr( $layout['name'] ) . '">';
					$html .= '<h3 class="cfs-out-layout-title">' . esc_html( $layout['label'] ?? $layout['name'] ) . '</h3>';
					$html .= self::render_cells( $layout['sub_fields'] ?? array(), $row );
					$html .= '</section>';
				}
				return $html . '</div>';

			case 'wysiwyg':
				return '<div class="cfs-out-field cfs-out-wysiwyg">' . wpautop( wp_kses_post( (string) $value ) ) . '</div>';

			case 'image':
				if ( is_array( $value ) && ! empty( $value['url'] ) ) {
					return '<img class="cfs-out-image" src="' . esc_url( $value['sizes']['large'] ?? $value['url'] ) . '" alt="' . esc_attr( $value['alt'] ?? '' ) . '" />';
				}
				if ( is_string( $value ) && '' !== $value ) {
					return '<img class="cfs-out-image" src="' . esc_url( $value ) . '" alt="" />';
				}
				return is_scalar( $value ) ? '<span class="cfs-out-field">' . esc_html( (string) $value ) . '</span>' : '';

			case 'gallery':
				$html = '<div class="cfs-out-field cfs-out-gallery">';
				foreach ( (array) $value as $img ) {
					$src = is_array( $img ) ? ( $img['sizes']['medium'] ?? $img['url'] ?? '' ) : ( is_numeric( $img ) ? wp_get_attachment_image_url( (int) $img, 'medium' ) : $img );
					$alt = is_array( $img ) ? ( $img['alt'] ?? '' ) : '';
					if ( $src ) {
						$html .= '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" /> ';
					}
				}
				return $html . '</div>';

			case 'relationship':
				$html = '<ul class="cfs-out-field cfs-out-relationship">';
				foreach ( (array) $value as $item ) {
					$id = is_object( $item ) ? $item->ID : absint( $item );
					if ( $id ) {
						$html .= '<li><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></li>';
					}
				}
				return $html . '</ul>';

			case 'taxonomy':
				$terms = is_array( $value ) ? $value : array( $value );
				$html  = '<ul class="cfs-out-field cfs-out-taxonomy">';
				foreach ( $terms as $term ) {
					$term = is_object( $term ) ? $term : get_term( absint( $term ), $field['taxonomy'] ?? 'category' );
					if ( $term && ! is_wp_error( $term ) ) {
						$link  = get_term_link( $term );
						$html .= '<li>' . ( is_wp_error( $link ) ? esc_html( $term->name ) : '<a href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . '</a>' ) . '</li>';
					}
				}
				return $html . '</ul>';

			case 'true_false':
				return '<span class="cfs-out-field">' . ( $value ? esc_html__( 'Yes', 'cfs' ) : esc_html__( 'No', 'cfs' ) ) . '</span>';

			case 'textarea':
				return '<p class="cfs-out-field">' . nl2br( esc_html( (string) $value ) ) . '</p>';

			default:
				if ( is_array( $value ) ) {
					return '<span class="cfs-out-field">' . esc_html( implode( ', ', array_map( 'strval', $value ) ) ) . '</span>';
				}
				return '<span class="cfs-out-field">' . esc_html( (string) $value ) . '</span>';
		}
	}

	/**
	 * Rows of a repeater as a list of <div>s.
	 */
	protected static function render_rows( $sub_fields, $rows, $class ) {
		$html = '<div class="cfs-out ' . esc_attr( $class ) . '">';
		foreach ( (array) $rows as $row ) {
			$html .= '<div class="cfs-out-row">' . self::render_cells( $sub_fields, (array) $row ) . '</div>';
		}
		return $html . '</div>';
	}

	/**
	 * The labelled cells of one row.
	 */
	protected static function render_cells( $sub_fields, $row ) {
		$html = '';
		foreach ( (array) $sub_fields as $sub ) {
			if ( empty( $sub['name'] ) ) {
				continue;
			}
			$cell = self::render_field( $sub, $row[ $sub['name'] ] ?? null );
			if ( '' === $cell ) {
				continue;
			}
			$html .= '<div class="cfs-out-cell cfs-out-cell-' . esc_attr( $sub['name'] ) . '">'
				. '<strong class="cfs-out-label">' . esc_html( $sub['label'] ?? $sub['name'] ) . ':</strong> '
				. $cell . '</div>';
		}
		return $html;
	}
}

CFS_Shortcode::init();
