/**
 * Custom Fields Studio — post edit screen meta box behavior.
 *
 *  - Image field: wp.media() picker.
 *  - Repeater field: add / remove / reorder rows, nested repeaters,
 *    min/max limits, and WYSIWYG sub fields inside rows.
 *
 * Repeater rows are cloned from a <template> that PHP rendered with a
 * placeholder token (data-token, e.g. "__ROW0__") standing where the row
 * index goes in every input name. Cloning swaps the token for a unique
 * throwaway key. The keys never need to be sequential: PHP receives the
 * rows in DOM order and re-indexes them on save, so dragging a row needs
 * no renaming at all.
 */
/* global jQuery, wp, cfsMetaBox */
( function ( $ ) {
	'use strict';

	var rowKeyCounter = 0;
	var editorCounter = 0;

	function nextRowKey() {
		rowKeyCounter++;
		return 'n' + rowKeyCounter;
	}

	/* ------------------------------------------------------------------
	 * WYSIWYG sub fields (printed as plain <textarea.cfs-wysiwyg-lazy>)
	 * ---------------------------------------------------------------- */

	function initEditors( $scope ) {
		if ( typeof wp === 'undefined' || ! wp.editor || ! wp.editor.initialize ) {
			return;
		}

		$scope.find( 'textarea.cfs-wysiwyg-lazy' ).each( function () {
			var $textarea = $( this );

			if ( $textarea.data( 'cfsEditor' ) ) {
				return;
			}

			if ( ! this.id ) {
				editorCounter++;
				this.id = 'cfs-wysiwyg-' + editorCounter;
			}

			$textarea.data( 'cfsEditor', true );

			wp.editor.initialize( this.id, {
				tinymce:
					'basic' === $textarea.attr( 'data-toolbar' )
						? { toolbar1: 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo', toolbar2: '' }
						: true,
				quicktags: true,
				mediaButtons: '1' === $textarea.attr( 'data-media' ),
			} );
		} );
	}

	/**
	 * Tear editors down before their DOM is moved or removed (moving a
	 * TinyMCE iframe in the DOM reloads it and loses its content). The
	 * textarea keeps the content; initEditors() rebuilds the editor.
	 */
	function destroyEditors( $scope ) {
		if ( typeof wp === 'undefined' || ! wp.editor || ! wp.editor.remove ) {
			return;
		}

		$scope.find( 'textarea.cfs-wysiwyg-lazy' ).each( function () {
			var $textarea = $( this );

			if ( ! $textarea.data( 'cfsEditor' ) ) {
				return;
			}

			if ( window.tinymce && window.tinymce.get( this.id ) ) {
				window.tinymce.get( this.id ).save();
			}

			wp.editor.remove( this.id );
			$textarea.removeData( 'cfsEditor' );
		} );
	}

	/* ------------------------------------------------------------------
	 * Repeater
	 * ---------------------------------------------------------------- */

	function limits( $repeater ) {
		return {
			min: parseInt( $repeater.attr( 'data-min' ), 10 ) || 0,
			max: parseInt( $repeater.attr( 'data-max' ), 10 ) || 0,
		};
	}

	function rowsOf( $repeater ) {
		return $repeater.children( '.cfs-repeater-rows' ).children( '.cfs-repeater-row' );
	}

	/** Disable "add" at the maximum and "remove" at the minimum. */
	function refreshRepeater( $repeater ) {
		var l = limits( $repeater );
		var count = rowsOf( $repeater ).length;

		$repeater
			.children( '.cfs-repeater-footer' )
			.find( '.cfs-repeater-add' )
			.prop( 'disabled', l.max > 0 && count >= l.max );

		rowsOf( $repeater )
			.children( '.cfs-repeater-row-actions' )
			.find( '.cfs-repeater-remove' )
			.prop( 'disabled', count <= l.min );
	}

	/* ------------------------------------------------------------------
	 * Flexible Content (same row model as the repeater, but each row is
	 * cloned from the <template> of the layout the editor picked)
	 * ---------------------------------------------------------------- */

	function flexRowsOf( $flex ) {
		return $flex.children( '.cfs-flex-rows' ).children( '.cfs-flex-row' );
	}

	function refreshFlex( $flex ) {
		var l = limits( $flex );
		var count = flexRowsOf( $flex ).length;

		$flex
			.children( '.cfs-flex-footer' )
			.find( '.cfs-flex-add' )
			.prop( 'disabled', l.max > 0 && count >= l.max );

		flexRowsOf( $flex )
			.children( '.cfs-flex-row-actions' )
			.find( '.cfs-flex-remove' )
			.prop( 'disabled', count <= l.min );
	}

	function addFlexRow( $flex, layout ) {
		var l = limits( $flex );

		if ( l.max > 0 && flexRowsOf( $flex ).length >= l.max ) {
			return;
		}

		var template = $flex
			.children( 'template.cfs-flex-template' )
			.filter( function () {
				return this.getAttribute( 'data-layout' ) === layout;
			} )[ 0 ];

		if ( ! template ) {
			return;
		}

		var html = template.innerHTML.split( $flex.attr( 'data-token' ) ).join( nextRowKey() );
		var $row = $( $.parseHTML( html ) ).filter( '.cfs-flex-row' );

		$flex.children( '.cfs-flex-rows' ).append( $row );

		// A new row can contain nested repeaters / flexible fields / editors.
		initRepeaters( $row );
		refreshFlex( $flex );
	}

	function removeFlexRow( $row ) {
		var $flex = $row.closest( '.cfs-flex' );

		if ( flexRowsOf( $flex ).length <= limits( $flex ).min ) {
			return;
		}

		destroyEditors( $row );
		$row.remove();
		refreshFlex( $flex );
	}

	/** Make rows draggable, start editors, and sync button state. */
	function initRepeaters( $scope ) {
		$scope
			.find( '.cfs-flex-rows' )
			.addBack( '.cfs-flex-rows' )
			.not( '.ui-sortable' )
			.sortable( {
				items: '> .cfs-flex-row',
				handle: '> .cfs-flex-row-handle > .cfs-drag-handle',
				axis: 'y',
				tolerance: 'pointer',
				start: function ( e, ui ) {
					destroyEditors( ui.item );
				},
				stop: function ( e, ui ) {
					initEditors( ui.item );
				},
			} );

		$scope
			.find( '.cfs-flex' )
			.addBack( '.cfs-flex' )
			.each( function () {
				refreshFlex( $( this ) );
			} );

		$scope
			.find( '.cfs-repeater-rows' )
			.addBack( '.cfs-repeater-rows' )
			.not( '.ui-sortable' )
			.sortable( {
				items: '> .cfs-repeater-row',
				handle: '> .cfs-repeater-row-handle > .cfs-drag-handle',
				axis: 'y',
				tolerance: 'pointer',
				start: function ( e, ui ) {
					destroyEditors( ui.item );
				},
				stop: function ( e, ui ) {
					initEditors( ui.item );
				},
			} );

		initEditors( $scope );

		$scope
			.find( '.cfs-gallery-list' )
			.addBack( '.cfs-gallery-list' )
			.not( '.ui-sortable' )
			.sortable( { tolerance: 'pointer', items: '> .cfs-gallery-item' } );

		$scope
			.find( '.cfs-repeater' )
			.addBack( '.cfs-repeater' )
			.each( function () {
				refreshRepeater( $( this ) );
			} );
	}

	function addRow( $repeater ) {
		var l = limits( $repeater );
		var template = $repeater.children( 'template.cfs-repeater-template' )[ 0 ];

		if ( ! template || ( l.max > 0 && rowsOf( $repeater ).length >= l.max ) ) {
			return;
		}

		var html = template.innerHTML.split( $repeater.attr( 'data-token' ) ).join( nextRowKey() );
		var $row = $( $.parseHTML( html ) ).filter( '.cfs-repeater-row' );

		$repeater.children( '.cfs-repeater-rows' ).append( $row );

		// A new row can contain its own (nested) repeaters and editors.
		initRepeaters( $row );
		refreshRepeater( $repeater );
	}

	function removeRow( $row ) {
		var $repeater = $row.closest( '.cfs-repeater' );

		if ( rowsOf( $repeater ).length <= limits( $repeater ).min ) {
			return;
		}

		destroyEditors( $row );
		$row.remove();
		refreshRepeater( $repeater );
	}

	/* ------------------------------------------------------------------
	 * Image field
	 * ---------------------------------------------------------------- */

	function bindImageField() {
		if ( typeof wp === 'undefined' || ! wp.media ) {
			return;
		}

		// Delegated from the document so it also works for fields inside
		// repeater rows that are cloned in after page load.
		$( document ).on( 'click', '.cfs-field-values .cfs-image-select', function ( e ) {
			e.preventDefault();

			var $wrap = $( this ).closest( '.cfs-image-field' );
			var $input = $wrap.find( '.cfs-image-value' );
			var $preview = $wrap.find( '.cfs-image-preview' );
			var $selectBtn = $wrap.find( '.cfs-image-select' );
			var $removeBtn = $wrap.find( '.cfs-image-remove' );

			var frame = wp.media( {
				title: cfsMetaBox.i18n.selectImage,
				library: { type: 'image' },
				multiple: false,
				button: { text: cfsMetaBox.i18n.useImage },
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var previewUrl =
					( attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url ) ||
					attachment.url;

				$input.val( attachment.id );
				$preview.empty().append( $( '<img>', { src: previewUrl, alt: '' } ) ).show();
				$selectBtn.hide();
				$removeBtn.show();
			} );

			frame.open();
		} );

		$( document ).on( 'click', '.cfs-field-values .cfs-image-remove', function ( e ) {
			e.preventDefault();

			var $wrap = $( this ).closest( '.cfs-image-field' );

			$wrap.find( '.cfs-image-value' ).val( '' );
			$wrap.find( '.cfs-image-preview' ).empty().hide();
			$wrap.find( '.cfs-image-select' ).show();
			$( this ).hide();
		} );
	}


	/** File, color, range, choice helpers (delegated; cloned rows just work). */
	function bindPhase9Fields() {
		var cfg = window.cfsMetaBox || {};
		var i18n = cfg.i18n || {};

		$( document ).on( 'click', '.cfs-field-values .cfs-file-select', function ( e ) {
			e.preventDefault();
			if ( typeof wp === 'undefined' || ! wp.media ) {
				return;
			}
			var $wrap = $( this ).closest( '.cfs-file-field' );
			var frame = wp.media( {
				title: i18n.selectFile || 'Select file',
				multiple: false,
				button: { text: i18n.useFile || 'Use this file' },
			} );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				$wrap.find( '.cfs-file-value' ).val( a.id );
				$wrap.find( '.cfs-file-name' ).attr( 'href', a.url ).text( a.filename || a.title );
				$wrap.find( '.cfs-file-info' ).show();
				$wrap.find( '.cfs-file-select' ).hide();
				$wrap.find( '.cfs-file-remove' ).show();
			} );
			frame.open();
		} );

		$( document ).on( 'click', '.cfs-field-values .cfs-file-remove', function ( e ) {
			e.preventDefault();
			var $wrap = $( this ).closest( '.cfs-file-field' );
			$wrap.find( '.cfs-file-value' ).val( '' );
			$wrap.find( '.cfs-file-info' ).hide();
			$wrap.find( '.cfs-file-select' ).show();
			$( this ).hide();
		} );

		// Colour: the native swatch and the text box stay in sync.
		$( document ).on( 'input change', '.cfs-color-field .cfs-color-pick', function () {
			$( this ).siblings( '.cfs-color-text' ).val( this.value );
		} );
		$( document ).on( 'input change', '.cfs-color-field .cfs-color-text', function () {
			if ( /^#[0-9a-f]{6}$/i.test( this.value ) ) {
				$( this ).siblings( '.cfs-color-pick' ).val( this.value );
			}
		} );

		$( document ).on( 'input change', '.cfs-range', function () {
			$( this ).siblings( '.cfs-range-out' ).text( this.value );
		} );

		$( document ).on( 'click', '.cfs-choice-clear', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.cfs-choice-list' ).find( 'input[type=radio]' ).prop( 'checked', false );
		} );

		$( document ).on( 'change', '.cfs-button-group input[type=radio]', function () {
			var $g = $( this ).closest( '.cfs-button-group' );
			$g.find( 'label' ).removeClass( 'cfs-active' );
			$g.find( 'input:checked' ).closest( 'label' ).addClass( 'cfs-active' );
		} );
	}

	/** Gallery + Relationship (delegated, so cloned rows just work). */
	function bindGalleryAndRelationship() {
		var cfg = window.cfsMetaBox || {};
		var i18n = cfg.i18n || {};

		$( document ).on( 'click', '.cfs-field-values .cfs-gallery-add', function ( e ) {
			e.preventDefault();
			if ( typeof wp === 'undefined' || ! wp.media ) {
				return;
			}
			var $wrap = $( this ).closest( '.cfs-gallery' );
			var frame = wp.media( {
				title: i18n.addImages || 'Add images',
				button: { text: i18n.addImages || 'Add images' },
				library: { type: 'image' },
				multiple: 'add',
			} );
			frame.on( 'select', function () {
				var max = parseInt( $wrap.data( 'max' ), 10 ) || 0;
				var name = $wrap.attr( 'data-name' );
				frame.state().get( 'selection' ).each( function ( att ) {
					var $list = $wrap.find( '.cfs-gallery-list' );
					var id = att.get( 'id' );
					if ( max && $list.children().length >= max ) {
						return;
					}
					if ( $list.children( '[data-id="' + id + '"]' ).length ) {
						return;
					}
					var sizes = att.get( 'sizes' ) || {};
					var size = sizes.thumbnail || sizes.full || { url: att.get( 'url' ) };
					var $li = $( '<li class="cfs-gallery-item"></li>' ).attr( 'data-id', id );
					$( '<input type="hidden" />' ).attr( 'name', name + '[]' ).val( id ).appendTo( $li );
					$( '<img alt="" />' ).attr( 'src', size.url ).appendTo( $li );
					$( '<button type="button" class="cfs-gallery-remove">&times;</button>' ).appendTo( $li );
					$list.append( $li );
				} );
			} );
			frame.open();
		} );

		$( document ).on( 'click', '.cfs-field-values .cfs-gallery-remove', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.cfs-gallery-item' ).remove();
		} );

		var timer;
		function search( $rel ) {
			var $results = $rel.find( '.cfs-rel-results' );
			$.post( cfg.ajaxUrl, {
				action: 'cfs_relationship_search',
				nonce: cfg.relationshipNonce,
				types: $rel.attr( 'data-types' ),
				s: $rel.find( '.cfs-rel-search' ).val(),
			} ).done( function ( res ) {
				$results.empty();
				var items = res && res.success ? res.data : [];
				if ( ! items.length ) {
					$( '<li class="cfs-rel-empty"></li>' ).text( i18n.noResults || 'No results' ).appendTo( $results );
					return;
				}
				$.each( items, function ( i, item ) {
					$( '<li class="cfs-rel-result"></li>' )
						.attr( 'data-id', item.id )
						.text( item.title + ' (' + item.type + ')' )
						.data( 'title', item.title )
						.appendTo( $results );
				} );
			} );
		}

		$( document ).on( 'focus input', '.cfs-field-values .cfs-rel-search', function () {
			var $rel = $( this ).closest( '.cfs-relationship' );
			clearTimeout( timer );
			timer = setTimeout( function () {
				search( $rel );
			}, 250 );
		} );
		$( document ).on( 'keydown', '.cfs-field-values .cfs-rel-search', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault(); // Don't submit the post form.
			}
		} );

		$( document ).on( 'click', '.cfs-field-values .cfs-rel-result', function () {
			var $rel = $( this ).closest( '.cfs-relationship' );
			var $sel = $rel.find( '.cfs-rel-selected' );
			var id = $( this ).attr( 'data-id' );
			var max = parseInt( $rel.attr( 'data-max' ), 10 ) || 0;
			if ( $sel.children( '[data-id="' + id + '"]' ).length || ( max && $sel.children().length >= max ) ) {
				return;
			}
			var $li = $( '<li></li>' ).attr( 'data-id', id );
			$( '<input type="hidden" />' ).attr( 'name', $rel.attr( 'data-name' ) + '[]' ).val( id ).appendTo( $li );
			$( '<span class="cfs-rel-title"></span>' ).text( $( this ).data( 'title' ) ).appendTo( $li );
			$( '<button type="button" class="cfs-rel-remove">&times;</button>' ).appendTo( $li );
			$sel.append( $li );
		} );

		$( document ).on( 'click', '.cfs-field-values .cfs-rel-remove', function () {
			$( this ).closest( 'li' ).remove();
		} );

		$( document ).on( 'mouseenter', '.cfs-field-values .cfs-rel-selected:not(.ui-sortable)', function () {
			$( this ).sortable( { tolerance: 'pointer' } );
		} );
	}

	$( function () {
		bindGalleryAndRelationship();
		bindImageField();
		bindPhase9Fields();

		$( document ).on( 'click', '.cfs-repeater-add', function ( e ) {
			e.preventDefault();
			addRow( $( this ).closest( '.cfs-repeater' ) );
		} );

		$( document ).on( 'click', '.cfs-flex-add', function ( e ) {
			e.preventDefault();
			e.stopPropagation();

			var $flex = $( this ).closest( '.cfs-flex' );
			var $menu = $( this ).siblings( '.cfs-flex-menu' );
			var $choices = $menu.children( '.cfs-flex-add-layout' );

			// One layout: nothing to choose, add it straight away.
			if ( 1 === $choices.length ) {
				addFlexRow( $flex, $choices.attr( 'data-layout' ) );
				return;
			}

			var wasHidden = $menu.prop( 'hidden' );
			$( '.cfs-flex-menu' ).prop( 'hidden', true );
			$menu.prop( 'hidden', ! wasHidden );
		} );

		$( document ).on( 'click', '.cfs-flex-add-layout', function ( e ) {
			e.preventDefault();

			$( this ).parent().prop( 'hidden', true );
			addFlexRow( $( this ).closest( '.cfs-flex' ), $( this ).attr( 'data-layout' ) );
		} );

		$( document ).on( 'click', function () {
			$( '.cfs-flex-menu' ).prop( 'hidden', true );
		} );

		$( document ).on( 'click', '.cfs-flex-remove', function ( e ) {
			e.preventDefault();
			removeFlexRow( $( this ).closest( '.cfs-flex-row' ) );
		} );

		$( document ).on( 'click', '.cfs-repeater-remove', function ( e ) {
			e.preventDefault();
			removeRow( $( this ).closest( '.cfs-repeater-row' ) );
		} );

		initRepeaters( $( '.cfs-field-values' ) );
	} );
} )( jQuery );
