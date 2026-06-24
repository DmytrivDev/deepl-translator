/* deepl-translator / dt-admin.js */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '#dt-translate-btn', function () {
		var $btn    = $( this );
		var $status = $( '#dt-status' );

		var postId     = $btn.data( 'post-id' );
		var originalId = $btn.data( 'original-id' );
		var nonce      = $btn.data( 'nonce' );
		var isNewDraft = $btn.data( 'is-new-draft' ) === 1 || $btn.data( 'is-new-draft' ) === '1';

		$btn.prop( 'disabled', true );

		function runTranslation() {
			$status.css( 'color', '#555' ).text( DT.i18n.translating );

			// After savePost() the post has a real ID — read it from the store
			// so we use the correct (possibly updated) post_id.
			if ( window.wp && wp.data && wp.data.select ) {
				var currentId = wp.data.select( 'core/editor' ).getCurrentPostId();
				if ( currentId ) {
					postId = currentId;
				}
			}

			$.post(
				DT.ajaxUrl,
				{
					action      : DT.action,
					nonce       : nonce,
					post_id     : postId,
					original_id : originalId,
				},
				function ( response ) {
					if ( response.success ) {
						$status.css( 'color', '#0a0' ).text( DT.i18n.success );
						setTimeout( function () {
							window.location.reload();
						}, 1200 );
					} else {
						$status
							.css( 'color', '#c00' )
							.text( DT.i18n.error + ( response.data || '' ) );
						$btn.prop( 'disabled', false );
					}
				}
			).fail( function ( xhr ) {
				$status
					.css( 'color', '#c00' )
					.text( DT.i18n.error + xhr.statusText );
				$btn.prop( 'disabled', false );
			} );
		}

		// New unsaved draft: save via Gutenberg store first, then translate.
		if ( isNewDraft && window.wp && wp.data && wp.data.dispatch ) {
			$status.css( 'color', '#555' ).text( DT.i18n.saving );

			wp.data.dispatch( 'core/editor' ).savePost()
				.then( function () {
					runTranslation();
				} )
				.catch( function ( err ) {
					$status
						.css( 'color', '#c00' )
						.text( DT.i18n.error + ( err && err.message ? err.message : 'Save failed.' ) );
					$btn.prop( 'disabled', false );
				} );

			return;
		}

		runTranslation();
	} );

} )( jQuery );