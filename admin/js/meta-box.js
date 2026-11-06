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

	/** Make rows draggable, start editors, and sync button state. */
	function initRepeaters( $scope ) {
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

	$( function () {
		bindImageField();

		$( document ).on( 'click', '.cfs-repeater-add', function ( e ) {
			e.preventDefault();
			addRow( $( this ).closest( '.cfs-repeater' ) );
		} );

		$( document ).on( 'click', '.cfs-repeater-remove', function ( e ) {
			e.preventDefault();
			removeRow( $( this ).closest( '.cfs-repeater-row' ) );
		} );

		initRepeaters( $( '.cfs-field-values' ) );
	} );
} )( jQuery );
