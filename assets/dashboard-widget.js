/* Refresh the online-visitor count in the DN Burst Funnel Stats dashboard widget. */
( function ( $ ) {
	var config = window.dnBfsWidget || {};
	var $badge = $( '[data-dnbfs-online]' );

	if ( ! $badge.length || ! config.ajaxUrl ) {
		return;
	}

	function refresh() {
		$.post( config.ajaxUrl, { action: 'dn_bfs_realtime', nonce: config.nonce } ).done( function ( response ) {
			if ( response && response.success && 'number' === typeof response.data.online ) {
				$badge.text( String( response.data.online ) );
			}
		} );
	}

	window.setInterval( refresh, 30000 );
} )( jQuery );
