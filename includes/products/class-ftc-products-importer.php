<?php
/**
 * Products importer and mapper.
 *
 * @package Ferk_Topten_Connector\Includes\Products
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-logger.php';

/**
 * Handles TopTen product fetch and SKU mapping.
 */
class FTC_Products_Importer {
    const MAX_RESPONSE_ROWS = 500;

    /**
     * Hard limit to avoid endless pagination.
     */
    const HARD_PAGE_LIMIT = 100;

    /**
     * Cached client instance.
     *
     * @var FTC_Client|null
     */
    protected $client = null;

    /**
     * Constructor.
     *
     * @param FTC_Client|null $client Optional client dependency.
     */
    public function __construct( ?FTC_Client $client = null ) {
        $this->client = $client;
    }

    /**
     * Map TopTen products by SKU and optionally persist id_topten meta.
     *
     * @param array $args Arguments: apply_changes, overwrite, strategy, palabra_clave, max_pages.
     *
     * @return array
     */
    public function map_by_sku( array $args ) : array {
        $defaults = array(
            'apply_changes' => false,
            'overwrite'     => false,
            'strategy'      => 'case_insensitive_trim',
            'palabra_clave' => null,
            'max_pages'     => 10,
        );

        $args = wp_parse_args( $args, $defaults );

        $apply_changes = ! empty( $args['apply_changes'] );
        $overwrite     = ! empty( $args['overwrite'] );
        $strategy      = in_array( $args['strategy'], array( 'case_insensitive_trim', 'exact' ), true ) ? $args['strategy'] : 'case_insensitive_trim';

        $keyword = isset( $args['palabra_clave'] ) && '' !== trim( (string) $args['palabra_clave'] ) ? sanitize_text_field( $args['palabra_clave'] ) : null;
        $max_pages = max( 0, (int) $args['max_pages'] );

        $summary = array(
            'totalTopTen'      => 0,
            'totalWooMatched'  => 0,
            'saved'            => 0,
            'skipped'          => 0,
            'already_set'      => 0,
            'conflicts'        => 0,
            'pagesProcessed'   => 0,
        );

        $rows      = array();
        $truncated = false;

        $entity_id = (int) get_option( 'ftc_auth_enti_id', FTC_Utils::FTCTOPTEN_ENTITY_ID );
        if ( $entity_id <= 0 ) {
            $entity_id = FTC_Utils::FTCTOPTEN_ENTITY_ID;
        }

        $logger     = FTC_Logger::instance();
        $page_limit = (int) apply_filters( 'ftc_products_map_max_pages', self::HARD_PAGE_LIMIT, $args );
        if ( $page_limit <= 0 ) {
            $page_limit = self::HARD_PAGE_LIMIT;
        }

        $page = 1;

        while ( $page <= $page_limit ) {
            if ( $max_pages > 0 && $page > $max_pages ) {
                break;
            }

            try {
                $products_page = $this->fetch_page( $page, $keyword, $entity_id );
            } catch ( Exception $exception ) {
                $logger->error(
                    'products-map',
                    'Fetch products failed',
                    array(
                        'page'    => $page,
                        'message' => $exception->getMessage(),
                    )
                );
                throw $exception;
            }

            $summary['pagesProcessed']++;

            if ( empty( $products_page ) ) {
                break;
            }

            foreach ( $products_page as $product_payload ) {
                $summary['totalTopTen']++;

                $topten_id = (int) FTC_Utils::array_get( $product_payload, 'InfoProducto.Producto.Prod_Id', 0 );
                if ( $topten_id <= 0 ) {
                    continue;
                }

                $primary_sku = (string) FTC_Utils::array_get( $product_payload, 'InfoProducto.Producto.Prod_Sku', '' );
                $terms       = FTC_Utils::array_get( $product_payload, 'InfoProducto.TerminosList', array() );

                $candidates = array();
                if ( '' !== $primary_sku ) {
                    $candidates[] = array(
                        'sku'    => $primary_sku,
                        'source' => 'Producto',
                    );
                }

                if ( is_array( $terms ) ) {
                    foreach ( $terms as $term ) {
                        $term_sku = (string) FTC_Utils::array_get( $term, 'SkuPropio', '' );
                        if ( '' !== $term_sku ) {
                            $candidates[] = array(
                                'sku'    => $term_sku,
                                'source' => 'TerminosList',
                            );
                        }
                    }
                }

                if ( empty( $candidates ) ) {
                    continue;
                }

                $seen_candidate_norms = array();
                $processed_wc_ids     = array();

                foreach ( $candidates as $candidate ) {
                    $normalized_candidate = $this->normalize_sku( $candidate['sku'], $strategy );
                    if ( '' === $normalized_candidate ) {
                        continue;
                    }

                    if ( isset( $seen_candidate_norms[ $normalized_candidate ] ) ) {
                        continue;
                    }

                    $seen_candidate_norms[ $normalized_candidate ] = true;

                    $matched_products = $this->find_wc_products_by_sku( $candidate['sku'], $strategy );
                    if ( empty( $matched_products ) ) {
                        continue;
                    }

                    $matched_products = array_filter(
                        $matched_products,
                        function ( $product ) use ( $processed_wc_ids ) {
                            return ! in_array( $product->get_id(), $processed_wc_ids, true );
                        }
                    );

                    if ( empty( $matched_products ) ) {
                        continue;
                    }

                    if ( count( $matched_products ) > 1 ) {
                        foreach ( $matched_products as $wc_product ) {
                            $processed_wc_ids[] = $wc_product->get_id();
                            $summary['totalWooMatched']++;
                            $summary['conflicts']++;
                            $this->append_row(
                                $rows,
                                $truncated,
                                array(
                                    'wc_product_id'  => (int) $wc_product->get_id(),
                                    'wc_parent_id'   => $wc_product->is_type( 'variation' ) ? (int) $wc_product->get_parent_id() : 0,
                                    'wc_product_name'=> $wc_product->get_name(),
                                    'sku_woo'        => (string) $wc_product->get_sku(),
                                    'topten_id'      => $topten_id,
                                    'topten_sku'     => $candidate['sku'],
                                    'source'         => $candidate['source'],
                                    'action'         => 'conflict',
                                    'notes'          => 'multiple_matches',
                                )
                            );
                        }
                        continue;
                    }

                    /** @var WC_Product $wc_product */
                    $wc_product = array_shift( $matched_products );
                    $processed_wc_ids[] = $wc_product->get_id();
                    $summary['totalWooMatched']++;

                    $current_meta = get_post_meta( $wc_product->get_id(), 'id_topten', true );
                    $current_id   = is_numeric( $current_meta ) ? (int) $current_meta : 0;
                    $had_existing = $current_id > 0;

                    $row_notes = array();
                    $action    = 'skip';

                    if ( $apply_changes ) {
                        if ( $had_existing && ! $overwrite ) {
                            $summary['already_set']++;
                            $summary['skipped']++;
                            $row_notes[] = 'already_set';
                            $action      = 'skip';
                        } else {
                            if ( $had_existing ) {
                                $summary['already_set']++;
                            }

                            update_post_meta( $wc_product->get_id(), 'id_topten', (int) $topten_id );
                            $summary['saved']++;
                            $action = 'saved';

                            if ( $wc_product->is_type( 'variation' ) ) {
                                $this->maybe_update_parent_meta( $wc_product, $topten_id, $overwrite );
                            }

                            if ( $had_existing && $overwrite ) {
                                $row_notes[] = 'overwritten';
                            }
                        }
                    } else {
                        if ( $had_existing ) {
                            $summary['already_set']++;
                            $row_notes[] = 'already_set';
                        }

                        $summary['skipped']++;
                        $row_notes[] = 'dry_run';
                    }

                    $this->append_row(
                        $rows,
                        $truncated,
                        array(
                            'wc_product_id'   => (int) $wc_product->get_id(),
                            'wc_parent_id'    => $wc_product->is_type( 'variation' ) ? (int) $wc_product->get_parent_id() : 0,
                            'wc_product_name' => $wc_product->get_name(),
                            'sku_woo'         => (string) $wc_product->get_sku(),
                            'topten_id'       => $topten_id,
                            'topten_sku'      => $candidate['sku'],
                            'source'          => $candidate['source'],
                            'action'          => $action,
                            'notes'           => implode( ',', array_unique( $row_notes ) ),
                        )
                    );
                }

                if ( $truncated ) {
                    break;
                }
            }

            if ( $truncated ) {
                break;
            }

            $page++;
        }

        $logger->info(
            'products-map',
            'Products map completed',
            array(
                'summary' => $summary,
                'args'    => array(
                    'apply_changes' => $apply_changes ? 'yes' : 'no',
                    'overwrite'     => $overwrite ? 'yes' : 'no',
                    'strategy'      => $strategy,
                    'has_keyword'   => $keyword ? 'yes' : 'no',
                    'max_pages'     => $max_pages,
                    'entity_id'     => $entity_id,
                ),
                'truncated' => $truncated ? 'yes' : 'no',
            )
        );

        return array(
            'summary'   => $summary,
            'rows'      => $rows,
            'truncated' => $truncated,
        );
    }

    /**
     * Fetch TopTen products page.
     *
     * @param int         $page      Page number.
     * @param string|null $keyword   Optional keyword.
     * @param int         $entity_id Entity identifier.
     *
     * @return array
     */
    public function fetch_page( int $page, ?string $keyword, int $entity_id ) : array {
        $payload = array(
            'Enti_Id' => $entity_id,
            'Pagina'  => max( 1, $page ),
        );

        if ( $keyword ) {
            $payload['PalabraClave'] = $keyword;
        }

        $response = $this->get_client()->get_products_detail( $payload, array( 'use_token' => true ) );

        $products = isset( $response['Productos'] ) && is_array( $response['Productos'] ) ? $response['Productos'] : array();

        return $products;
    }

    /**
     * Normalise SKU according to strategy.
     *
     * @param string $sku      Raw SKU.
     * @param string $strategy Strategy.
     *
     * @return string
     */
    public function normalize_sku( $sku, $strategy ) : string {
        if ( ! is_scalar( $sku ) ) {
            return '';
        }

        $value = (string) $sku;

        if ( 'case_insensitive_trim' === $strategy ) {
            return strtolower( trim( $value ) );
        }

        return trim( $value );
    }

    /**
     * Find WooCommerce products by SKU.
     *
     * @param string $sku      SKU to search.
     * @param string $strategy Strategy (case_insensitive_trim|exact).
     *
     * @return WC_Product[]
     */
    protected function find_wc_products_by_sku( string $sku, string $strategy ) : array {
        global $wpdb;

        $normalized = $this->normalize_sku( $sku, $strategy );
        if ( '' === $normalized ) {
            return array();
        }

        if ( 'exact' === $strategy ) {
            $query = $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                '_sku',
                $sku
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND TRIM(LOWER(meta_value)) = %s",
                '_sku',
                $normalized
            );
        }

        $ids = $wpdb->get_col( $query );

        if ( empty( $ids ) ) {
            return array();
        }

        $products = array();

        foreach ( $ids as $id ) {
            $product = wc_get_product( (int) $id );
            if ( ! $product ) {
                continue;
            }

            $product_sku = $product->get_sku();

            if ( 'exact' === $strategy ) {
                if ( (string) $product_sku !== $sku ) {
                    continue;
                }
            } else {
                if ( $this->normalize_sku( $product_sku, $strategy ) !== $normalized ) {
                    continue;
                }
            }

            $products[ $product->get_id() ] = $product;
        }

        if ( empty( $products ) ) {
            return array();
        }

        $has_variations = false;
        foreach ( $products as $product ) {
            if ( $product->is_type( 'variation' ) ) {
                $has_variations = true;
                break;
            }
        }

        if ( $has_variations ) {
            foreach ( $products as $id => $product ) {
                if ( ! $product->is_type( 'variation' ) ) {
                    unset( $products[ $id ] );
                }
            }
        }

        return array_values( $products );
    }

    /**
     * Maybe update parent meta when dealing with variations.
     *
     * @param WC_Product $variation Variation product.
     * @param int        $topten_id TopTen identifier.
     * @param bool       $overwrite Allow overwriting.
     */
    protected function maybe_update_parent_meta( WC_Product $variation, int $topten_id, bool $overwrite ) {
        if ( ! $variation->is_type( 'variation' ) ) {
            return;
        }

        $parent_id = (int) $variation->get_parent_id();
        if ( $parent_id <= 0 ) {
            return;
        }

        $current_parent = get_post_meta( $parent_id, 'id_topten', true );
        $current_parent_id = is_numeric( $current_parent ) ? (int) $current_parent : 0;

        if ( $current_parent_id > 0 && ! $overwrite ) {
            return;
        }

        update_post_meta( $parent_id, 'id_topten', (int) $topten_id );
    }

    /**
     * Append result row respecting truncation.
     *
     * @param array $rows      Rows array (by reference).
     * @param bool  $truncated Truncated flag (by reference).
     * @param array $row       Row data.
     */
    protected function append_row( array &$rows, bool &$truncated, array $row ) {
        if ( $truncated ) {
            return;
        }

        if ( count( $rows ) >= self::MAX_RESPONSE_ROWS ) {
            $truncated = true;
            return;
        }

        $rows[] = array(
            'wc_product_id'   => isset( $row['wc_product_id'] ) ? (int) $row['wc_product_id'] : 0,
            'wc_parent_id'    => isset( $row['wc_parent_id'] ) ? (int) $row['wc_parent_id'] : 0,
            'wc_product_name' => isset( $row['wc_product_name'] ) ? sanitize_text_field( $row['wc_product_name'] ) : '',
            'sku_woo'         => isset( $row['sku_woo'] ) ? (string) $row['sku_woo'] : '',
            'topten_id'       => isset( $row['topten_id'] ) ? (int) $row['topten_id'] : 0,
            'topten_sku'      => isset( $row['topten_sku'] ) ? (string) $row['topten_sku'] : '',
            'source'          => isset( $row['source'] ) ? (string) $row['source'] : '',
            'action'          => isset( $row['action'] ) ? (string) $row['action'] : '',
            'notes'           => isset( $row['notes'] ) ? (string) $row['notes'] : '',
        );
    }

    /**
     * Lazy client getter.
     *
     * @return FTC_Client
     */
    protected function get_client() : FTC_Client {
        if ( null === $this->client ) {
            $this->client = FTC_Plugin::instance()->client();
        }

        return $this->client;
    }
}
