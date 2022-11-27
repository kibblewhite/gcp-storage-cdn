( function( $ ) {
	$( document ).ready( function() {

		$( 'div#gcp-storage-sync', document ).each(function() {

			let div_gcp_storage_sync = this;
			$( 'button#synchronise', div_gcp_storage_sync ).on( 'click', function() {
				let button_gcp_storage_sync = this;
				$( button_gcp_storage_sync ).prop( 'disabled', true );
				$( 'pre#gcp-storage-sync-results', document ).text( '' );
				$( 'span.spinner', div_gcp_storage_sync ).addClass( 'is-active' );

				var jqxhr = $.ajax( {
					type: 'POST',
					url: gcps.ajax_endpoint_sync_url,
					data: { },
					timeout: 90000
				} );

				jqxhr.done( function( data, textStatus, jqXHR ) {
					console.log( 'DONE', data, textStatus, jqXHR );
					let response = data.data;
					$( 'pre#gcp-storage-sync-results', document ).text( 'Refresh media page to view any new items: ' + JSON.stringify( response, undefined, 4 ) );
				} );

				jqxhr.fail( function( jqXHR, textStatus, errorThrown ) {
					console.log( 'FAIL', textStatus, jqXHR, errorThrown );
					$( 'pre#gcp-storage-sync-results', document ).text( textStatus );
				} );

				jqxhr.always( function( data, textStatus, jqXHR ) {
					// console.log( 'ALWAYS', data, textStatus, jqXHR );
					$( 'span.spinner', div_gcp_storage_sync ).removeClass( 'is-active' );
					$( button_gcp_storage_sync ).prop( 'disabled', false );
				} );

			} );

			$( 'button#refresh', div_gcp_storage_sync ).on( 'click', function() {
				location.reload();	// note: this doesn't workon older browsers
			} );

		} );

	} );
} )( jQuery );
