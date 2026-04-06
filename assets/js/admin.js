jQuery( function( $ ) {
	var $sorters = $( '.gscore-term-sorter' );

	if ( ! $sorters.length ) {
		return;
	}

	$sorters.each( function() {
		var $sorter = $( this );
		var $list = $sorter.find( '.gscore-sortable-term-list' );
		var $hiddenInput = $sorter.find( 'input[type="hidden"]' );
		var $search = $sorter.find( '.gscore-term-sorter__search' );
		var defaultOrder = String( $sorter.data( 'default-order' ) || '' ).split( ',' ).filter( Boolean );

		function syncOrder() {
			var ids = $list.children( '.gscore-sortable-term-list__item' ).map( function() {
				return $( this ).data( 'term-id' );
			} ).get();

			$hiddenInput.val( ids.join( ',' ) );
		}

		function sortToOrder( orderedIds ) {
			var itemMap = {};

			$list.children( '.gscore-sortable-term-list__item' ).each( function() {
				var $item = $( this );
				itemMap[ String( $item.data( 'term-id' ) ) ] = $item;
			} );

			orderedIds.forEach( function( id ) {
				if ( itemMap[ id ] ) {
					$list.append( itemMap[ id ] );
				}
			} );

			syncOrder();
		}

		$list.sortable( {
			axis: 'y',
			handle: '.gscore-sortable-term-list__handle',
			update: syncOrder
		} );

		$sorter.find( '.gscore-term-sorter__reset' ).on( 'click', function() {
			sortToOrder( defaultOrder );
		} );

		$search.on( 'input', function() {
			var needle = String( $( this ).val() || '' ).toLowerCase().trim();

			$list.children( '.gscore-sortable-term-list__item' ).each( function() {
				var $item = $( this );
				var text = $item.text().toLowerCase();
				$item.toggle( needle === '' || text.indexOf( needle ) !== -1 );
			} );
		} );

		syncOrder();
	} );
} );
