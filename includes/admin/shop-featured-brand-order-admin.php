<?php
/**
 * Admin UI for choosing and ordering featured brands on the shop options page.
 *
 * @package Gunsafes_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'GScore_Shop_Featured_Brand_Order_Admin' ) ) {
	class GScore_Shop_Featured_Brand_Order_Admin {
		private const PAGE_SLUG       = 'shop-page-options';
		private const TAXONOMY        = 'product_brand';
		private const FIELD_NAME      = 'featured_brand_order_ids';
		private const LEGACY_FIELD    = 'shop-page-options_featured-brands';

		public function __construct() {
			add_filter( 'jet-engine/options-pages/raw-fields', array( $this, 'inject_fields' ), 20, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		public function inject_fields( array $fields, $page_factory ): array {
			if ( ! is_object( $page_factory ) || empty( $page_factory->slug ) || self::PAGE_SLUG !== $page_factory->slug ) {
				return $fields;
			}

			$fields[] = array(
				'title'       => '',
				'name'        => 'gscore_featured_brand_order_help',
				'object_type' => 'field',
				'width'       => '100%',
				'type'        => 'html',
				'html'        => $this->get_field_markup(),
			);

			$fields[] = array(
				'title'       => '',
				'name'        => self::FIELD_NAME,
				'object_type' => 'field',
				'width'       => '100%',
				'type'        => 'hidden',
			);

			return $fields;
		}

		public function enqueue_assets( $hook ): void {
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			if ( self::PAGE_SLUG !== $page ) {
				return;
			}

			wp_enqueue_script( 'jquery-ui-sortable' );

			$css_rel_path = 'assets/css/admin.css';
			$js_rel_path  = 'assets/js/shop-options-brand-order.js';
			$css_path     = GUNSAFES_CORE_PATH . $css_rel_path;
			$js_path      = GUNSAFES_CORE_PATH . $js_rel_path;
			$css_ver      = file_exists( $css_path ) ? (string) filemtime( $css_path ) : GUNSAFES_CORE_VER;
			$js_ver       = file_exists( $js_path ) ? (string) filemtime( $js_path ) : GUNSAFES_CORE_VER;

			wp_enqueue_style(
				'gunsafes-core-admin',
				GUNSAFES_CORE_URL . $css_rel_path,
				array(),
				$css_ver
			);

			wp_enqueue_script(
				'gunsafes-core-shop-options-brand-order',
				GUNSAFES_CORE_URL . $js_rel_path,
				array( 'jquery', 'jquery-ui-sortable' ),
				$js_ver,
				true
			);
		}

		private function get_field_markup(): string {
			$brands      = $this->get_brands();
			$saved_order = $this->get_saved_brand_ids();
			$selected    = array_fill_keys( $saved_order, true );

			$brands = $this->sort_brands_for_display( $brands, $saved_order );

			ob_start();
			?>
			<div
				class="gscore-featured-brand-sorter"
				data-input-name="<?php echo esc_attr( self::FIELD_NAME ); ?>"
			>
				<label class="gscore-featured-brand-sorter__label">
					<?php esc_html_e( 'Featured Brand Order', 'gunsafes-core' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Check the brands you want featured on the shop page, then drag the checked brands into the order the listing should use. This ordered ID list is what the featured brands query will follow.', 'gunsafes-core' ); ?>
				</p>

				<div class="gscore-term-sorter gscore-term-sorter--featured-brands">
					<div class="gscore-term-sorter__toolbar">
						<input
							type="search"
							class="regular-text gscore-term-sorter__search"
							placeholder="<?php esc_attr_e( 'Filter brands…', 'gunsafes-core' ); ?>"
						/>
						<button type="button" class="button button-secondary gscore-featured-brand-sorter__clear">
							<?php esc_html_e( 'Clear Selected', 'gunsafes-core' ); ?>
						</button>
					</div>

					<ul class="gscore-sortable-term-list gscore-sortable-term-list--featured-brands">
						<?php foreach ( $brands as $brand ) : ?>
							<?php $is_selected = ! empty( $selected[ (int) $brand->term_id ] ); ?>
							<li
								class="gscore-sortable-term-list__item gscore-sortable-term-list__item--feature-toggle<?php echo $is_selected ? ' is-selected' : ''; ?>"
								data-term-id="<?php echo esc_attr( $brand->term_id ); ?>"
								data-selected="<?php echo $is_selected ? '1' : '0'; ?>"
							>
								<label class="gscore-featured-brand-sorter__checkbox">
									<input
										type="checkbox"
										class="gscore-featured-brand-sorter__toggle"
										<?php checked( $is_selected ); ?>
									/>
									<span class="gscore-sortable-term-list__name"><?php echo esc_html( $brand->name ); ?></span>
								</label>
								<span class="gscore-sortable-term-list__handle" aria-hidden="true">⋮⋮</span>
								<span class="gscore-sortable-term-list__meta">
									<?php
									printf(
										/* translators: 1: term slug, 2: product count */
										esc_html__( 'Slug: %1$s | Products: %2$d', 'gunsafes-core' ),
										$brand->slug,
										(int) $brand->count
									);
									?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>

					<p class="description gscore-featured-brand-sorter__note">
						<?php esc_html_e( 'Unchecked brands stay available below for quick selection. Only checked brands are saved into the ordered featured-brand list.', 'gunsafes-core' ); ?>
					</p>
				</div>
			</div>
			<?php

			return (string) ob_get_clean();
		}

		private function get_brands(): array {
			$brands = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $brands ) || empty( $brands ) ) {
				return array();
			}

			usort(
				$brands,
				static function ( $a, $b ) {
					return strcasecmp( $a->name, $b->name );
				}
			);

			return $brands;
		}

		private function get_saved_brand_ids(): array {
			$option_key = self::PAGE_SLUG . '_' . self::FIELD_NAME;
			$raw_value  = get_option( $option_key, '' );
			$ids        = $this->normalize_ids( $raw_value );

			if ( ! empty( $ids ) ) {
				return $ids;
			}

			$legacy_value = get_option( self::LEGACY_FIELD, array() );
			if ( ! is_array( $legacy_value ) || empty( $legacy_value ) ) {
				return array();
			}

			$selected_names = array();

			foreach ( $legacy_value as $brand_name => $enabled ) {
				if ( filter_var( $enabled, FILTER_VALIDATE_BOOLEAN ) ) {
					$selected_names[] = (string) $brand_name;
				}
			}

			if ( empty( $selected_names ) ) {
				return array();
			}

			$selected_lookup = array_fill_keys( $selected_names, true );
			$terms           = $this->get_brands();
			$ids             = array();

			foreach ( $terms as $term ) {
				if ( isset( $selected_lookup[ $term->name ] ) ) {
					$ids[] = (int) $term->term_id;
				}
			}

			return $ids;
		}

		private function normalize_ids( $raw_value ): array {
			$ids = array();

			if ( is_array( $raw_value ) ) {
				$values = $raw_value;
			} elseif ( is_string( $raw_value ) && '' !== trim( $raw_value ) ) {
				$values = explode( ',', $raw_value );
			} else {
				$values = array();
			}

			foreach ( $values as $value ) {
				$id = (int) $value;
				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}

			return $ids;
		}

		private function sort_brands_for_display( array $brands, array $saved_order ): array {
			if ( empty( $brands ) ) {
				return array();
			}

			$by_id = array();

			foreach ( $brands as $brand ) {
				$by_id[ (int) $brand->term_id ] = $brand;
			}

			$ordered = array();

			foreach ( $saved_order as $brand_id ) {
				if ( isset( $by_id[ $brand_id ] ) ) {
					$ordered[] = $by_id[ $brand_id ];
					unset( $by_id[ $brand_id ] );
				}
			}

			foreach ( $by_id as $brand ) {
				$ordered[] = $brand;
			}

			return $ordered;
		}
	}
}
