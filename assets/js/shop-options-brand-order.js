jQuery( function( $ ) {
	var $sorters = $( '.gscore-featured-brand-sorter' );

	if ( ! $sorters.length ) {
		return;
	}

	$sorters.each( function() {
		var $sorter = $( this );
		var inputName = String( $sorter.data( 'input-name' ) || '' );
		var $hiddenInput = inputName ? $( '[name="' + inputName + '"]' ) : $();
		var $list = $sorter.find( '.gscore-sortable-term-list--featured-brands' );
		var $search = $sorter.find( '.gscore-term-sorter__search' );

		if ( ! $list.length || ! $hiddenInput.length ) {
			return;
		}

		function selectedItems() {
			return $list.children( '.gscore-sortable-term-list__item' ).filter( function() {
				return $( this ).attr( 'data-selected' ) === '1';
			} );
		}

		function unselectedItems() {
			return $list.children( '.gscore-sortable-term-list__item' ).filter( function() {
				return $( this ).attr( 'data-selected' ) !== '1';
			} );
		}

		function syncHiddenInput() {
			var ids = selectedItems().map( function() {
				return $( this ).data( 'term-id' );
			} ).get();

			$hiddenInput.val( ids.join( ',' ) );
		}

		function sortUncheckedAlphabetically() {
			var unchecked = unselectedItems().get();

			unchecked.sort( function( a, b ) {
				var aName = $( a ).find( '.gscore-sortable-term-list__name' ).text().toLowerCase();
				var bName = $( b ).find( '.gscore-sortable-term-list__name' ).text().toLowerCase();

				if ( aName < bName ) {
					return -1;
				}

				if ( aName > bName ) {
					return 1;
				}

				return 0;
			} );

			unchecked.forEach( function( item ) {
				$list.append( item );
			} );
		}

		function syncSelectionState( $item, isSelected ) {
			$item.attr( 'data-selected', isSelected ? '1' : '0' );
			$item.toggleClass( 'is-selected', isSelected );
			$item.find( '.gscore-sortable-term-list__handle' ).toggle( isSelected );
		}

		function moveItemToSelectedArea( $item ) {
			var $selected = selectedItems().not( $item );

			if ( $selected.length ) {
				$selected.last().after( $item );
			} else {
				$list.prepend( $item );
			}
		}

		function refreshOrder() {
			sortUncheckedAlphabetically();
			syncHiddenInput();
		}

		$list.sortable( {
			axis: 'y',
			items: '.gscore-sortable-term-list__item[data-selected="1"]',
			handle: '.gscore-sortable-term-list__handle',
			update: syncHiddenInput
		} );

		$list.on( 'change', '.gscore-featured-brand-sorter__toggle', function() {
			var $checkbox = $( this );
			var $item = $checkbox.closest( '.gscore-sortable-term-list__item' );
			var isSelected = $checkbox.is( ':checked' );

			syncSelectionState( $item, isSelected );

			if ( isSelected ) {
				moveItemToSelectedArea( $item );
			}

			refreshOrder();
		} );

		$sorter.find( '.gscore-featured-brand-sorter__clear' ).on( 'click', function() {
			$list.find( '.gscore-featured-brand-sorter__toggle:checked' ).each( function() {
				$( this ).prop( 'checked', false ).trigger( 'change' );
			} );
		} );

		$search.on( 'input', function() {
			var needle = String( $( this ).val() || '' ).toLowerCase().trim();

			$list.children( '.gscore-sortable-term-list__item' ).each( function() {
				var $item = $( this );
				var text = $item.text().toLowerCase();
				$item.toggle( needle === '' || text.indexOf( needle ) !== -1 );
			} );
		} );

		$list.children( '.gscore-sortable-term-list__item' ).each( function() {
			var $item = $( this );
			syncSelectionState( $item, $item.attr( 'data-selected' ) === '1' );
		} );

		refreshOrder();
	} );
} );
