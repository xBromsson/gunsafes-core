<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gscore_category_tree_to_bool' ) ) {
	function gscore_category_tree_to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}
}

if ( ! function_exists( 'gscore_category_tree_sort_terms' ) ) {
	function gscore_category_tree_sort_terms( $terms, $order_meta_key = '' ) {
		if ( ! is_array( $terms ) ) {
			return $terms;
		}

		usort(
			$terms,
			function( $a, $b ) use ( $order_meta_key ) {
				if ( $order_meta_key ) {
					$order_a = (int) get_term_meta( $a->term_id, $order_meta_key, true );
					$order_b = (int) get_term_meta( $b->term_id, $order_meta_key, true );

					if ( $order_a !== $order_b ) {
						return $order_a <=> $order_b;
					}
				}

				return strcasecmp( $a->name, $b->name );
			}
		);

		return $terms;
	}
}

if ( ! function_exists( 'gscore_category_tree_build_branch' ) ) {
	function gscore_category_tree_build_branch( $parent_id, $config, $current_term_id, $tree_id, $depth = 0 ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $config['taxonomy'],
				'parent'     => (int) $parent_id,
				'hide_empty' => false, // Manual filtering below preserves non-empty branches.
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array(
				'html'             => '',
				'contains_current' => false,
			);
		}

		$terms = gscore_category_tree_sort_terms( $terms, $config['order_meta_key'] );

		$items_html       = '';
		$contains_current = false;

		foreach ( $terms as $term ) {
			if ( $config['hide_meta_key'] ) {
				$hidden = get_term_meta( $term->term_id, $config['hide_meta_key'], true );
				if ( gscore_category_tree_to_bool( $hidden ) ) {
					continue;
				}
			}

			$child = gscore_category_tree_build_branch(
				$term->term_id,
				$config,
				$current_term_id,
				$tree_id,
				$depth + 1
			);

			$has_children = ( '' !== $child['html'] );
			$has_products = ( (int) $term->count ) > 0;

			// Hide only empty leaves; keep empty parents if descendants are visible.
			if ( $config['hide_empty'] && ! $has_products && ! $has_children ) {
				continue;
			}

			$is_current = ( (int) $term->term_id === (int) $current_term_id );
			$is_open    = ( $config['open_current'] && ( $is_current || $child['contains_current'] ) );

			$classes = array( 'gsct-item' );
			if ( $is_current ) {
				$classes[] = 'is-current';
			}
			if ( $has_children ) {
				$classes[] = 'has-children';
			}
			if ( $is_open ) {
				$classes[] = 'is-open';
			}

			$panel_id = sprintf(
				'%s-panel-%d-%d',
				$tree_id,
				(int) $term->term_id,
				(int) $depth
			);

			$items_html .= '<li class="' . esc_attr( implode( ' ', $classes ) ) . '">';
			$items_html .= '<div class="gsct-row">';

			$term_link = get_term_link( $term );
			if ( ! is_wp_error( $term_link ) ) {
				$items_html .= '<a class="gsct-link" href="' . esc_url( $term_link ) . '">' . esc_html( $term->name ) . '</a>';
			} else {
				$items_html .= '<span class="gsct-link">' . esc_html( $term->name ) . '</span>';
			}

			if ( $config['show_counts'] ) {
				$items_html .= '<span class="gsct-count">(' . (int) $term->count . ')</span>';
			}

			if ( $has_children ) {
				$items_html .= '<button type="button" class="gsct-toggle" aria-expanded="' . ( $is_open ? 'true' : 'false' ) . '" aria-controls="' . esc_attr( $panel_id ) . '" data-panel-id="' . esc_attr( $panel_id ) . '"><span class="gsct-icon">' . ( $is_open ? '-' : '+' ) . '</span></button>';
			}

			$items_html .= '</div>';

			if ( $has_children ) {
				$items_html .= '<div id="' . esc_attr( $panel_id ) . '" class="gsct-children"' . ( $is_open ? '' : ' hidden' ) . '>';
				$items_html .= $child['html'];
				$items_html .= '</div>';
			}

			$items_html .= '</li>';

			if ( $is_current || $child['contains_current'] ) {
				$contains_current = true;
			}
		}

		if ( '' === $items_html ) {
			return array(
				'html'             => '',
				'contains_current' => false,
			);
		}

		return array(
			'html'             => '<ul class="gsct-level gsct-depth-' . (int) $depth . '">' . $items_html . '</ul>',
			'contains_current' => $contains_current,
		);
	}
}

if ( ! function_exists( 'gscore_category_tree_enqueue_assets' ) ) {
	function gscore_category_tree_enqueue_assets() {
		static $enqueued = false;

		if ( $enqueued ) {
			return;
		}

		$enqueued = true;

		$css_rel_path = 'assets/css/category-tree.css';
		$js_rel_path  = 'assets/js/category-tree.js';
		$css_path     = GUNSAFES_CORE_PATH . $css_rel_path;
		$js_path      = GUNSAFES_CORE_PATH . $js_rel_path;
		$css_ver      = file_exists( $css_path ) ? (string) filemtime( $css_path ) : GUNSAFES_CORE_VER;
		$js_ver       = file_exists( $js_path ) ? (string) filemtime( $js_path ) : GUNSAFES_CORE_VER;

		wp_enqueue_style(
			'gscore-category-tree',
			GUNSAFES_CORE_URL . $css_rel_path,
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'gscore-category-tree',
			GUNSAFES_CORE_URL . $js_rel_path,
			array(),
			$js_ver,
			true
		);
	}
}

if ( ! function_exists( 'gscore_category_tree_shortcode' ) ) {
	function gscore_category_tree_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'taxonomy'       => 'product_cat',
				'root'           => '0',
				'root_slug'      => '',
				'hide_empty'     => '1',
				'open_current'   => '1', // keep active branch expanded by default
				'hide_meta_key'  => '',
				'order_meta_key' => '',
				'show_counts'    => '0',
			),
			$atts,
			'gs_category_tree'
		);

		$taxonomy       = sanitize_key( $atts['taxonomy'] );
		$root           = (int) $atts['root'];
		$root_slug      = sanitize_title( $atts['root_slug'] );
		$hide_empty     = gscore_category_tree_to_bool( $atts['hide_empty'] );
		$open_current   = gscore_category_tree_to_bool( $atts['open_current'] );
		$hide_meta_key  = sanitize_key( $atts['hide_meta_key'] );
		$order_meta_key = sanitize_key( $atts['order_meta_key'] );
		$show_counts    = gscore_category_tree_to_bool( $atts['show_counts'] );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		if ( $root_slug ) {
			$root_term = get_term_by( 'slug', $root_slug, $taxonomy );
			if ( $root_term && ! is_wp_error( $root_term ) ) {
				$root = (int) $root_term->term_id;
			}
		}

		$current_term_id = 0;
		$queried_object  = get_queried_object();
		if ( $queried_object instanceof WP_Term && $queried_object->taxonomy === $taxonomy ) {
			$current_term_id = (int) $queried_object->term_id;
		}

		static $tree_index = 0;
		$tree_index++;
		$tree_id = 'gsct-tree-' . $tree_index;

		$config = array(
			'taxonomy'       => $taxonomy,
			'hide_empty'     => $hide_empty,
			'open_current'   => $open_current,
			'hide_meta_key'  => $hide_meta_key,
			'order_meta_key' => $order_meta_key,
			'show_counts'    => $show_counts,
		);

		$tree = gscore_category_tree_build_branch(
			$root,
			$config,
			$current_term_id,
			$tree_id,
			0
		);

		if ( empty( $tree['html'] ) ) {
			return '';
		}

		gscore_category_tree_enqueue_assets();

		return '<nav id="' . esc_attr( $tree_id ) . '" class="gsct-tree" aria-label="Category navigation">' . $tree['html'] . '</nav>';
	}

	add_shortcode( 'gs_category_tree', 'gscore_category_tree_shortcode' );
}
