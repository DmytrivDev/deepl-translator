/* deepl-translator / dt-terms-admin.js */
( function ( $ ) {
	'use strict';

	// ── On page load: auto-translate after new term was saved ───────────────────
	$( function () {
		var pending;
		try {
			var raw = sessionStorage.getItem( 'dt_term_auto_translate' );
			if ( raw ) {
				pending = JSON.parse( raw );
				sessionStorage.removeItem( 'dt_term_auto_translate' );
			}
		} catch ( e ) {}

		if ( ! pending ) {
			return;
		}

		var $btn = $( '#dt-term-translate-btn' );
		if ( ! $btn.length ) {
			return;
		}

		var termId     = parseInt( $btn.data( 'term-id' ), 10 );
		var originalId = pending.original_id;
		var nonce      = $btn.data( 'nonce' );
		var $status    = $( '#dt-term-status' );

		if ( ! termId || ! originalId ) {
			return;
		}

		$btn.prop( 'disabled', true );
		runTranslation( termId, originalId, nonce, $btn, $status );
	} );

	// ── Button click ─────────────────────────────────────────────────────────────
	$( document ).on( 'click', '#dt-term-translate-btn', function () {
		var $btn    = $( this );
		var $status = $( '#dt-term-status' );

		var termId     = parseInt( $btn.data( 'term-id' ), 10 );
		var originalId = $btn.data( 'original-id' );
		var nonce      = $btn.data( 'nonce' );
		var isNewTerm  = $btn.data( 'is-new-term' ) === 1 || $btn.data( 'is-new-term' ) === '1';
		var newLang    = $btn.data( 'new-lang' )  || '';
		var taxonomy   = $btn.data( 'taxonomy' )  || '';

		$btn.prop( 'disabled', true );

		// Existing saved term — translate directly.
		if ( ! isNewTerm ) {
			runTranslation( termId, originalId, nonce, $btn, $status );
			return;
		}

		// New term: create via AJAX first, then translate, then redirect to edit screen.
		$status.css( 'color', '#555' ).text( DT_TERMS.i18n.saving );

		$.post(
			DT_TERMS.ajaxUrl,
			{
				action      : 'dt_save_new_term',
				nonce       : nonce,
				taxonomy    : taxonomy,
				new_lang    : newLang,
				original_id : originalId,
			},
			function ( response ) {
				if ( ! response.success ) {
					$status
						.css( 'color', '#c00' )
						.text( DT_TERMS.i18n.error + ( response.data || '' ) );
					$btn.prop( 'disabled', false );
					return;
				}

				var newTermId = response.data.term_id;

				// Store original_id so the edit screen picks it up after redirect.
				try {
					sessionStorage.setItem( 'dt_term_auto_translate', JSON.stringify( {
						original_id : originalId,
					} ) );
				} catch ( e ) {}

				// Translate, then redirect to the term edit screen.
				$status.css( 'color', '#555' ).text( DT_TERMS.i18n.translating );

				$.post(
					DT_TERMS.ajaxUrl,
					{
						action      : DT_TERMS.action,
						nonce       : nonce,
						term_id     : newTermId,
						original_id : originalId,
					},
					function ( transResponse ) {
						// Remove sessionStorage — we handled translation inline.
						try { sessionStorage.removeItem( 'dt_term_auto_translate' ); } catch ( e ) {}

						if ( transResponse.success ) {
							$status.css( 'color', '#0a0' ).text( DT_TERMS.i18n.success );
						} else {
							$status
								.css( 'color', '#c00' )
								.text( DT_TERMS.i18n.error + ( transResponse.data || '' ) );
						}

						// Redirect to edit screen regardless — term was created.
						setTimeout( function () {
							window.location.href =
								DT_TERMS.termEditUrl
								+ '&tag_ID=' + newTermId
								+ '&taxonomy=' + encodeURIComponent( taxonomy );
						}, 1200 );
					}
				).fail( function ( xhr ) {
					try { sessionStorage.removeItem( 'dt_term_auto_translate' ); } catch ( e ) {}
					$status
						.css( 'color', '#c00' )
						.text( DT_TERMS.i18n.error + xhr.statusText );
					setTimeout( function () {
						window.location.href =
							DT_TERMS.termEditUrl
							+ '&tag_ID=' + newTermId
							+ '&taxonomy=' + encodeURIComponent( taxonomy );
					}, 1500 );
				} );
			}
		).fail( function ( xhr ) {
			$status
				.css( 'color', '#c00' )
				.text( DT_TERMS.i18n.error + xhr.statusText );
			$btn.prop( 'disabled', false );
		} );
	} );

	function runTranslation( termId, originalId, nonce, $btn, $status ) {
		$status.css( 'color', '#555' ).text( DT_TERMS.i18n.translating );

		$.post(
			DT_TERMS.ajaxUrl,
			{
				action      : DT_TERMS.action,
				nonce       : nonce,
				term_id     : termId,
				original_id : originalId,
			},
			function ( response ) {
				if ( response.success ) {
					$status.css( 'color', '#0a0' ).text( DT_TERMS.i18n.success );
					setTimeout( function () { window.location.reload(); }, 1200 );
				} else {
					$status
						.css( 'color', '#c00' )
						.text( DT_TERMS.i18n.error + ( response.data || '' ) );
					$btn.prop( 'disabled', false );
				}
			}
		).fail( function ( xhr ) {
			$status
				.css( 'color', '#c00' )
				.text( DT_TERMS.i18n.error + xhr.statusText );
			$btn.prop( 'disabled', false );
		} );
	}

} )( jQuery );