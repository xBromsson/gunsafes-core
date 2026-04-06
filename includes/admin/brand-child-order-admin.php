<?php
/**
 * Admin UI for manually ordering direct child brands on parent brand terms.
 *
 * @package Gunsafes_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'GScore_Brand_Child_Order_Admin' ) ) {
	class GScore_Brand_Child_Order_Admin {
		private const TAXONOMY = 'product_brand';
		private const META_KEY = 'gscore_child_brand_order';
		private const NONCE_ACTION = 'gscore_save_brand_child_order';
		private const NONCE_NAME = 'gscore_brand_child_order_nonce';

		public function __construct() {
			add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_edit_field' ), 20, 2 );
			add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_term' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		public function enqueue_assets( $hook ): void {
			if ( 'term.php' !== $hook ) {
				return;
			}

			$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
			if ( self::TAXONOMY !== $taxonomy ) {
				return;
			}

			wp_enqueue_script( 'jquery-ui-sortable' );

			$css_rel_path = 'assets/css/admin.css';
			$js_rel_path  = 'assets/js/admin.js';
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
				'gunsafes-core-admin',
				GUNSAFES_CORE_URL . $js_rel_path,
				array( 'jquery', 'jquery-ui-sortable' ),
				$js_ver,
				true
			);
		}

		public function render_edit_field( $term, $taxonomy ): void {
			if ( self::TAXONOMY !== $taxonomy || ! $term instanceof WP_Term ) {
				return;
			}

			$children = $this->get_ordered_direct_children( $term->term_id );
			?>
			<tr class="form-field gscore-term-order-field">
				<th scope="row">
					<label for="gscore-brand-child-order"><?php esc_html_e( 'Direct Child Brand Order', 'gunsafes-core' ); ?></label>
				</th>
				<td>
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

					<?php if ( empty( $children ) ) : ?>
						<p class="description">
							<?php esc_html_e( 'This brand does not currently have any direct child brands to order.', 'gunsafes-core' ); ?>
						</p>
					<?php else : ?>
						<p class="description">
							<?php esc_html_e( 'Drag and drop the direct child brands into the order you want the child brand grid to use on this parent brand archive.', 'gunsafes-core' ); ?>
						</p>

						<div class="gscore-term-sorter" data-default-order="<?php echo esc_attr( $this->get_alphabetical_order_csv( $children ) ); ?>">
							<div class="gscore-term-sorter__toolbar">
								<input
									type="search"
									class="regular-text gscore-term-sorter__search"
									placeholder="<?php esc_attr_e( 'Filter child brands…', 'gunsafes-core' ); ?>"
								/>
								<button type="button" class="button button-secondary gscore-term-sorter__reset">
									<?php esc_html_e( 'Reset to A-Z', 'gunsafes-core' ); ?>
								</button>
							</div>

							<ul class="gscore-sortable-term-list">
								<?php foreach ( $children as $child ) : ?>
									<li class="gscore-sortable-term-list__item" data-term-id="<?php echo esc_attr( $child->term_id ); ?>">
										<span class="gscore-sortable-term-list__handle" aria-hidden="true">⋮⋮</span>
										<span class="gscore-sortable-term-list__name"><?php echo esc_html( $child->name ); ?></span>
										<span class="gscore-sortable-term-list__meta">
											<?php
											printf(
												/* translators: 1: term slug, 2: product count */
												esc_html__( 'Slug: %1$s | Products: %2$d', 'gunsafes-core' ),
												$child->slug,
												(int) $child->count
											);
											?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>

							<input
								type="hidden"
								id="gscore-brand-child-order"
								name="gscore_brand_child_order"
								value="<?php echo esc_attr( $this->get_order_csv( $children ) ); ?>"
							/>
						</div>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}

		public function save_term( $term_id ): void {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return;
			}

			$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';
			if ( ! $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				return;
			}

			$current_children = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'parent'     => (int) $term_id,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

			if ( is_wp_error( $current_children ) || empty( $current_children ) ) {
				delete_term_meta( $term_id, self::META_KEY );
				return;
			}

			$current_children = array_map( 'intval', $current_children );
			$current_lookup   = array_fill_keys( $current_children, true );

			$raw_order = isset( $_POST['gscore_brand_child_order'] ) ? wp_unslash( $_POST['gscore_brand_child_order'] ) : '';
			$ordered   = array();

			if ( is_string( $raw_order ) && '' !== trim( $raw_order ) ) {
				foreach ( explode( ',', $raw_order ) as $term_id_value ) {
					$child_id = (int) trim( $term_id_value );
					if ( $child_id > 0 && isset( $current_lookup[ $child_id ] ) && ! in_array( $child_id, $ordered, true ) ) {
						$ordered[] = $child_id;
					}
				}
			}

			$missing_children = array_diff( $current_children, $ordered );
			if ( ! empty( $missing_children ) ) {
				$missing_terms = get_terms(
					array(
						'taxonomy'   => self::TAXONOMY,
						'include'    => $missing_children,
						'hide_empty' => false,
					)
				);

				if ( ! is_wp_error( $missing_terms ) && ! empty( $missing_terms ) ) {
					usort(
						$missing_terms,
						static function ( $a, $b ) {
							return strcasecmp( $a->name, $b->name );
						}
					);

					foreach ( $missing_terms as $missing_term ) {
						$ordered[] = (int) $missing_term->term_id;
					}
				}
			}

			update_term_meta( $term_id, self::META_KEY, array_values( $ordered ) );
		}

		private function get_ordered_direct_children( int $parent_term_id ): array {
			$children = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'parent'     => $parent_term_id,
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $children ) || empty( $children ) ) {
				return array();
			}

			usort(
				$children,
				static function ( $a, $b ) {
					return strcasecmp( $a->name, $b->name );
				}
			);

			$saved_order = get_term_meta( $parent_term_id, self::META_KEY, true );
			if ( ! is_array( $saved_order ) || empty( $saved_order ) ) {
				return $children;
			}

			$saved_order = array_map( 'intval', $saved_order );
			$by_id       = array();

			foreach ( $children as $child ) {
				$by_id[ (int) $child->term_id ] = $child;
			}

			$ordered = array();
			foreach ( $saved_order as $child_id ) {
				if ( isset( $by_id[ $child_id ] ) ) {
					$ordered[] = $by_id[ $child_id ];
					unset( $by_id[ $child_id ] );
				}
			}

			foreach ( $by_id as $remaining_child ) {
				$ordered[] = $remaining_child;
			}

			return $ordered;
		}

		private function get_order_csv( array $terms ): string {
			return implode(
				',',
				array_map(
					static function ( $term ) {
						return (int) $term->term_id;
					},
					$terms
				)
			);
		}

		private function get_alphabetical_order_csv( array $terms ): string {
			$alphabetical = $terms;

			usort(
				$alphabetical,
				static function ( $a, $b ) {
					return strcasecmp( $a->name, $b->name );
				}
			);

			return $this->get_order_csv( $alphabetical );
		}
	}
}
