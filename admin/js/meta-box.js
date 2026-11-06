/**
 * Custom Fields Studio — post edit screen meta box behavior.
 *
 * Currently only the Image field needs JS (the WP media uploader). Other
 * Phase 2 field types (text, textarea, select, true/false, WYSIWYG) are
 * plain form controls with no JS required.
 */
/* global jQuery, wp, cfsMetaBox */
( function ( $ ) {
	'use strict';

	if ( typeof wp === 'undefined' || ! wp.media ) {
		return;
	}

	$( function () {
		$( '.cfs-field-values' ).on( 'click', '.cfs-image-select', function ( e ) {
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
				$preview.html( '<img src="' + previewUrl + '" alt="" />' ).show();
				$selectBtn.hide();
				$removeBtn.show();
			} );

			frame.open();
		} );

		$( '.cfs-field-values' ).on( 'click', '.cfs-image-remove', function ( e ) {
			e.preventDefault();

			var $wrap = $( this ).closest( '.cfs-image-field' );

			$wrap.find( '.cfs-image-value' ).val( '' );
			$wrap.find( '.cfs-image-preview' ).empty().hide();
			$wrap.find( '.cfs-image-select' ).show();
			$( this ).hide();
		} );
	} );
} )( jQuery );
