/**
 * Fieldloom — field group builder.
 *
 * Handles: adding/removing/reordering field rows (top level AND a
 * repeater's nested sub-field rows), showing only the settings block
 * matching a row's selected type, and the location-rules AND/OR builder.
 *
 * Rows are never renamed after they're created. A new row gets a unique
 * key in its input names ("cfstudio_fields[n3][label]"), and PHP only looks at
 * the order the rows arrive in, which is DOM order — so drag-reordering
 * needs no re-indexing, and nesting can't get its indices out of step.
 */
/* global jQuery, cfstudioBuilder */
( function ( $ ) {
	'use strict';

	var ruleGroupIndex = $( '#cfs-location-rules .cfs-location-group' ).length;
	var rowKeyCounter = 0;

	function nextRowKey() {
		rowKeyCounter++;
		return 'n' + rowKeyCounter;
	}

	/** A random unique field key, same shape as PHP's CFStudio_Field_Group::new_key(). */
	function newFieldKey() {
		var bytes = new Uint8Array( 6 );
		window.crypto.getRandomValues( bytes );

		return (
			'field_' +
			Array.prototype.map
				.call( bytes, function ( b ) {
					return ( '0' + b.toString( 16 ) ).slice( -2 );
				} )
				.join( '' )
		);
	}

	/**
	 * The settings panel belonging to exactly this row (not to a nested
	 * sub-field row inside it).
	 */
	function panelOf( $row ) {
		return $row.children( 'td' ).children( '.cfs-field-settings-panel' );
	}

	/**
	 * Show only the settings block(s) matching a row's current type.
	 * Scoped to the row's own panel's direct children, so a repeater's
	 * nested rows keep their own state.
	 *
	 * @param {jQuery} $row The .cfs-field-row row.
	 */
	function syncFieldTypeVisibility( $row ) {
		// This row first, then rows nested inside it, so an inner row's own
		// type decides what is enabled inside an enabled outer block.
		$row.find( '.cfs-field-row' )
			.addBack()
			.each( function () {
				var $r = $( this );
				var type = $r.children( 'td' ).children( '.cfs-field-row-main' ).find( '.cfs-field-type-select' ).val();

				panelOf( $r )
					.children( '.cfs-field-setting' )
					.each( function () {
						var $setting = $( this );
						var match = String( $setting.data( 'type' ) ).split( ' ' ).indexOf( type ) > -1;
						$setting.toggle( match );
						// Several field types use the same input names
						// (default_value, return_format, max, ...). Inputs of
						// a type that isn't selected must not be submitted, or
						// they overwrite the real settings.
						$setting.find( ':input' ).prop( 'disabled', ! match );
					} );
			} );

		// A block that isn't selected stays fully disabled, nested rows
		// included (the pass above may have re-enabled some of those).
		$row.find( '.cfs-field-row' )
			.addBack()
			.each( function () {
				var $r = $( this );
				var type = $r.children( 'td' ).children( '.cfs-field-row-main' ).find( '.cfs-field-type-select' ).val();
				panelOf( $r )
					.children( '.cfs-field-setting' )
					.each( function () {
						if ( String( $( this ).data( 'type' ) ).split( ' ' ).indexOf( type ) === -1 ) {
							$( this ).find( ':input' ).prop( 'disabled', true );
						}
					} );
			} );
	}

	/**
	 * Make every row list sortable (top level and nested). Safe to call
	 * repeatedly: lists that already are sortable are skipped.
	 */
	function initSortables() {
		$( '.cfs-fields-editor-top tbody.cfs-rows' )
			.not( '.ui-sortable' )
			.sortable( {
				items: '> tr.cfs-field-row',
				// Direct handle only, so dragging a nested row never also
				// drags the repeater row that contains it.
				handle: '> td.cfs-col-handle > .cfs-drag-handle',
				axis: 'y',
				tolerance: 'pointer',
				forcePlaceholderSize: true,
				// Keep column widths while dragging a table row.
				helper: function ( e, $tr ) {
					$tr.children().each( function () {
						$( this ).width( $( this ).width() );
					} );
					return $tr;
				},
				placeholder: {
					element: function () {
						return $( '<tr class="cfs-sort-placeholder"><td colspan="2"></td></tr>' );
					},
					update: function () {},
				},
			} );
	}

	/** Lower-case snake_case slug from a label. */
	function slugify( text ) {
		return text
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' );
	}

	/** Set a row's settings panel open/closed (own panel only). */
	function setPanel( $row, open ) {
		var $panel = panelOf( $row );
		$panel.toggle( open );
		$row.children( 'td' ).children( '.cfs-field-row-main' ).find( '.cfs-toggle-field-settings' ).attr( 'aria-expanded', open ? 'true' : 'false' );
	}

	/**
	 * Duplicate a row (and everything nested in it) right below itself,
	 * with fresh field keys and a new unique input-name prefix.
	 */
	function duplicateRow( $row ) {
		var oldPrefix = $row.find( '.cfs-field-key' ).first().attr( 'name' ).replace( /\[key\]$/, '' );
		var base = oldPrefix.replace( /\[[^\]]*\]$/, '' );
		var newPrefix = base + '[' + nextRowKey() + ']';
		var $clone = $row.clone();

		// clone() doesn't carry over live select / textarea values.
		var $srcFields = $row.find( 'select, textarea' );
		$clone.find( 'select, textarea' ).each( function ( i ) {
			$( this ).val( $srcFields.eq( i ).val() );
		} );

		$clone.find( '[name]' ).each( function () {
			if ( 0 === this.name.indexOf( oldPrefix ) ) {
				this.name = newPrefix + this.name.slice( oldPrefix.length );
			}
		} );
		$clone.find( '.cfs-field-key' ).each( function () {
			$( this ).val( newFieldKey() );
		} );
		$clone.find( '.ui-sortable' ).removeClass( 'ui-sortable' );
		$clone.find( '.ui-sortable-handle' ).removeClass( 'ui-sortable-handle' );

		var $name = $clone.find( '.cfs-field-name' ).first();
		var $label = $clone.find( '.cfs-field-label' ).first();
		if ( $name.val() ) {
			$name.val( $name.val() + '_copy' );
		}
		$name.data( 'touched', true );
		$label.val( $label.val() + cfstudioBuilder.i18n.copySuffix );

		$row.after( $clone );
		$clone.find( '.cfs-field-row' ).addBack().each( function () {
			syncFieldTypeVisibility( $( this ) );
		} );
		initSortables();
	}

	/**
	 * Block saving when a list has two fields with the same name.
	 *
	 * @return {boolean} True when everything is fine.
	 */
	function validateNames() {
		var dupes = [];

		$( '.cfs-fields-editor-top tbody.cfs-rows' ).each( function () {
			var seen = {};
			$( this )
				.children( 'tr.cfs-field-row' )
				.each( function () {
					var $input = $( this ).children( 'td' ).children( '.cfs-field-row-main' ).find( '.cfs-field-name' );
					var $label = $( this ).children( 'td' ).children( '.cfs-field-row-main' ).find( '.cfs-field-label' );
					var name = $input.val() || slugify( $label.val() || '' );

					$input.removeClass( 'cfs-invalid' );
					if ( ! name ) {
						return;
					}
					if ( seen[ name ] ) {
						$input.addClass( 'cfs-invalid' );
						seen[ name ].addClass( 'cfs-invalid' );
						if ( -1 === dupes.indexOf( name ) ) {
							dupes.push( name );
						}
					} else {
						seen[ name ] = $input;
					}
				} );
		} );

		var $err = $( '.cfs-builder-errors' );
		if ( dupes.length ) {
			$err.find( 'p' ).text( cfstudioBuilder.i18n.duplicateName.replace( '%s', dupes.join( ', ' ) ) );
			$err.show();
			$err[ 0 ].scrollIntoView( { block: 'center' } );
			return false;
		}
		$err.hide();
		return true;
	}

	/**
	 * Clone the shared row template into a row list.
	 *
	 * @param {jQuery} $tbody The tbody.cfs-rows to add to.
	 */
	function addField( $tbody ) {
		var prefix = $tbody.attr( 'data-base' ) + '[' + nextRowKey() + ']';
		var html = $( '#tmpl-cfs-field-row' ).html().replace( /__PREFIX__/g, prefix );
		var $row = $( '<table><tbody>' + html + '</tbody></table>' ).find( 'tbody' ).first().children( 'tr.cfs-field-row' );

		// The template is rendered once by PHP, so every clone would carry
		// the same field key. Give each new row its own.
		$row.find( '.cfs-field-key' ).first().val( newFieldKey() );

		$tbody.append( $row );
		syncFieldTypeVisibility( $row );
		initSortables();
		$row.find( '.cfs-field-label' ).first().trigger( 'focus' );
	}

	/**
	 * Re-index location rule groups/rules after add/remove.
	 */
	function reindexLocationRules() {
		$( '#cfs-location-rules .cfs-location-group' ).each( function ( groupIndex ) {
			$( this )
				.attr( 'data-group', groupIndex )
				.find( '.cfs-location-rule' )
				.each( function ( ruleIndex ) {
					$( this )
						.find( '[name*="cfstudio_location["]' )
						.each( function () {
							this.name = this.name.replace(
								/cfstudio_location\[[^\]]*\]\[[^\]]*\]/,
								'cfstudio_location[' + groupIndex + '][' + ruleIndex + ']'
							);
						} );
				} );
		} );
	}

	$( function () {
		var $editor = $( '.cfs-fields-editor-top' );
		var $locationWrap = $( '#cfs-location-rules' );

		// Initial visibility sync for rows already on the page, nested ones too.
		$editor.find( '.cfs-field-row' ).each( function () {
			syncFieldTypeVisibility( $( this ) );
		} );

		initSortables();

		// One delegated set of handlers covers top-level rows and every
		// nested sub-field list, including ones added later.
		$editor.on( 'click', '.cfs-add-field', function () {
			addField( $( this ).closest( '.cfs-fields-editor' ).children( 'table' ).children( 'tbody.cfs-rows' ) );
		} );

		$editor.on( 'change', '.cfs-field-type-select', function () {
			syncFieldTypeVisibility( $( this ).closest( '.cfs-field-row' ) );
		} );

		$editor.on( 'click', '.cfs-toggle-field-settings', function () {
			var $row = $( this ).closest( '.cfs-field-row' );
			setPanel( $row, ! panelOf( $row ).is( ':visible' ) );
		} );

		$editor.on( 'click', '.cfs-duplicate-field', function () {
			duplicateRow( $( this ).closest( '.cfs-field-row' ) );
		} );

		$editor.on( 'click', '.cfs-expand-all, .cfs-collapse-all', function () {
			var open = $( this ).hasClass( 'cfs-expand-all' );
			$editor.find( '.cfs-field-row' ).each( function () {
				setPanel( $( this ), open );
			} );
		} );

		// Label -> name auto-slug, until the name has been edited by hand.
		// Rows that already have a saved name are never rewritten.
		$editor.find( '.cfs-field-name' ).each( function () {
			if ( $( this ).val() ) {
				$( this ).data( 'touched', true );
			}
		} );
		$editor.on( 'input', '.cfs-field-name', function () {
			$( this ).data( 'touched', true ).removeClass( 'cfs-invalid' );
		} );
		$editor.on( 'blur', '.cfs-field-name', function () {
			$( this ).val( slugify( $( this ).val() ) );
		} );
		$editor.on( 'input', '.cfs-field-label', function () {
			var $name = $( this ).siblings( '.cfs-field-name' );
			if ( ! $name.data( 'touched' ) ) {
				$name.val( slugify( $( this ).val() ) );
			}
		} );

		$( '#post' ).on( 'submit', function ( e ) {
			if ( ! validateNames() ) {
				e.preventDefault();
				$( '#publish, #save-post' ).removeClass( 'disabled' );
				$( '.spinner' ).removeClass( 'is-active' );
			}
		} );

		// Flexible Content layouts: clone the shared layout template, with
		// the same never-renamed unique-key scheme as field rows.
		$editor.on( 'click', '.cfs-add-layout', function () {
			var $wrap = $( this ).closest( '.cfs-layouts' );
			var prefix = $wrap.attr( 'data-base' ) + '[' + nextRowKey() + ']';
			var html = $( '#tmpl-cfs-layout' ).html().replace( /__PREFIX__/g, prefix );

			$wrap.children( '.cfs-layouts-list' ).append( html );
			initSortables();
		} );

		$editor.on( 'click', '.cfs-remove-layout', function () {
			// eslint-disable-next-line no-alert
			if ( window.confirm( cfstudioBuilder.i18n.confirmRemove ) ) {
				$( this ).closest( '.cfs-layout-block' ).remove();
			}
		} );


		/* ---- conditional logic editor (per field row) ---- */
		function condPrefix( $cond ) {
			return $cond.find( '.cfs-cond-enabled' ).attr( 'name' ).replace( /\[enabled\]$/, '' );
		}
		function siblingNames( $row ) {
			var names = [];
			var own = $row.find( '.cfs-field-name' ).first().val();
			$row.parent().children( '.cfs-field-row' ).each( function () {
				var n = $( this ).find( '.cfs-field-name' ).first().val();
				if ( n && n !== own ) {
					names.push( n );
				}
			} );
			return names;
		}
		function fillCondFields( $select ) {
			var current = $select.val();
			var $row = $select.closest( '.cfs-field-row' );
			var names = siblingNames( $row );
			if ( current && names.indexOf( current ) === -1 ) {
				names.unshift( current );
			}
			$select.empty();
			$select.append( $( '<option></option>' ).val( '' ).text( '—' ) );
			$.each( names, function ( i, n ) {
				$select.append( $( '<option></option>' ).val( n ).text( n ) );
			} );
			$select.val( current );
		}
		$editor.on( 'change', '.cfs-cond-enabled', function () {
			$( this ).closest( '.cfs-cond' ).find( '.cfs-cond-body' ).first().toggle( this.checked );
			if ( this.checked && ! $( this ).closest( '.cfs-cond' ).find( '.cfs-cond-rule' ).length ) {
				$( this ).closest( '.cfs-cond' ).find( '.cfs-cond-add' ).first().trigger( 'click' );
			}
		} );
		$editor.on( 'focus mousedown', '.cfs-cond-field', function () {
			fillCondFields( $( this ) );
		} );
		$editor.on( 'change', '.cfs-cond-op', function () {
			$( this ).siblings( '.cfs-cond-value' ).toggle( 'empty' !== this.value && '!empty' !== this.value );
		} );
		$editor.on( 'click', '.cfs-cond-remove', function () {
			$( this ).closest( '.cfs-cond-rule' ).remove();
		} );
		$editor.on( 'click', '.cfs-cond-add', function () {
			var $cond = $( this ).closest( '.cfs-cond' );
			var i = parseInt( $cond.attr( 'data-next' ), 10 ) || 0;
			$cond.attr( 'data-next', i + 1 );
			var base = condPrefix( $cond ) + '[rules][' + i + ']';
			var $rule = $( '<div class="cfs-cond-rule"></div>' );
			var $f = $( '<select class="cfs-cond-field"></select>' ).attr( 'name', base + '[field]' );
			var $o = $( '<select class="cfs-cond-op"></select>' ).attr( 'name', base + '[operator]' );
			$.each( cfstudioBuilder.condOps || {}, function ( k, label ) {
				$o.append( $( '<option></option>' ).val( k ).text( label ) );
			} );
			var $v = $( '<input type="text" class="cfs-cond-value" />' ).attr( 'name', base + '[value]' );
			var $x = $( '<button type="button" class="button-link cfs-cond-remove">&times;</button>' );
			$rule.append( $f, $o, $v, $x );
			$cond.find( '.cfs-cond-rules' ).first().append( $rule );
			fillCondFields( $f );
		} );

		$editor.on( 'click', '.cfs-remove-field', function () {
			var $row = $( this ).closest( '.cfs-field-row' );

			// The top-level list always keeps one row so the builder never
			// looks empty; a repeater's sub-field list may be emptied.
			if ( $row.parent().is( '#cfs-fields-rows' ) && $row.siblings( '.cfs-field-row' ).length < 1 ) {
				return;
			}

			// eslint-disable-next-line no-alert
			if ( ! window.confirm( cfstudioBuilder.i18n.confirmRemove ) ) {
				return;
			}

			$row.remove();
		} );

		// --- Location rules builder ---

		// A rule's param decides which values the value dropdown offers.
		$locationWrap.on( 'change', '.cfs-location-param', function () {
			var values = ( cfstudioBuilder.locationValues || {} )[ $( this ).val() ] || {};
			var $select = $( this ).closest( '.cfs-location-rule' ).find( '.cfs-location-value' ).empty();
			var keys = Object.keys( values );

			if ( ! keys.length ) {
				$select.append( $( '<option>', { value: '', text: cfstudioBuilder.i18n.noValues } ) );
				return;
			}

			keys.forEach( function ( key ) {
				$select.append( $( '<option>', { value: key, text: values[ key ] } ) );
			} );
		} );

		$locationWrap.on( 'click', '.cfs-add-rule', function () {
			var $group = $( this ).closest( '.cfs-location-group' );
			var groupIndex = $group.data( 'group' );
			var ruleIndex = $group.find( '.cfs-location-rule' ).length;
			var template = $( '#tmpl-cfs-location-rule-row' )
				.html()
				.replace( /__GROUP__/g, groupIndex )
				.replace( /__RULE__/g, ruleIndex );

			$group.find( 'tbody' ).append( template );
		} );

		$locationWrap.on( 'click', '.cfs-remove-rule', function () {
			var $group = $( this ).closest( '.cfs-location-group' );

			if ( $group.find( '.cfs-location-rule' ).length <= 1 ) {
				// A group needs at least one rule, or remove the whole group instead.
				if ( $locationWrap.find( '.cfs-location-group' ).length > 1 ) {
					$group.prev( '.cfs-location-or' ).remove();
					$group.remove();
					reindexLocationRules();
				}
				return;
			}

			$( this ).closest( '.cfs-location-rule' ).remove();
			reindexLocationRules();
		} );

		$locationWrap.parent().on( 'click', '.cfs-add-rule-group', function () {
			var template = $( '#tmpl-cfs-location-rule-row' )
				.html()
				.replace( /__GROUP__/g, ruleGroupIndex )
				.replace( /__RULE__/g, 0 );

			var $newGroup = $(
				'<div class="cfs-location-or">OR</div>' +
					'<div class="cfs-location-group" data-group="' +
					ruleGroupIndex +
					'">' +
					'<table class="cfs-location-rules-table"><tbody>' +
					template +
					'</tbody></table>' +
					'<button type="button" class="button cfs-add-rule">+ Add Rule (AND)</button>' +
					'</div>'
			);

			$( this ).before( $newGroup );
			ruleGroupIndex++;
		} );
	} );
} )( jQuery );
