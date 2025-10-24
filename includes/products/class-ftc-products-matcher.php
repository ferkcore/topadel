<?php
/**
 * Products matcher via spreadsheet upload.
 *
 * @package Ferk_Topten_Connector\Includes\Products
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-utils.php';
require_once FTC_PLUGIN_DIR . 'includes/helpers/class-ftc-logger.php';

/**
 * Handles manual TopTen product mapping via spreadsheet uploads.
 */
class FTC_Products_Matcher {
    const MAX_FILE_SIZE = 5242880; // 5 MB.

    const MAX_ROWS = 5000;

    /**
     * Process uploaded spreadsheet and update id_topten meta.
     *
     * @param string $file_path Absolute path to uploaded file.
     * @param string $extension File extension.
     * @param string $mime_type Mime type.
     *
     * @return array
     *
     * @throws Exception When parsing fails.
     */
    public function process_file( string $file_path, string $extension = '', string $mime_type = '' ) : array {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            throw new Exception( __( 'No se pudo leer el archivo subido.', 'ferk-topten-connector' ) );
        }

        $extension = strtolower( $extension );
        if ( '' === $extension ) {
            $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        }

        if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
            throw new Exception( __( 'Formato de archivo no soportado. Usa CSV o XLSX.', 'ferk-topten-connector' ) );
        }

        $file_size = filesize( $file_path );
        if ( false !== $file_size && $file_size > self::MAX_FILE_SIZE ) {
            throw new Exception( __( 'El archivo es demasiado grande. Límite: 5MB.', 'ferk-topten-connector' ) );
        }

        $rows = 'csv' === $extension
            ? $this->parse_csv( $file_path )
            : $this->parse_xlsx( $file_path );

        if ( empty( $rows ) || ! is_array( $rows ) ) {
            throw new Exception( __( 'El archivo no contiene filas para procesar.', 'ferk-topten-connector' ) );
        }

        $header = array_shift( $rows );
        if ( empty( $header ) || ! is_array( $header ) ) {
            throw new Exception( __( 'El archivo no tiene encabezados válidos.', 'ferk-topten-connector' ) );
        }

        $columns = $this->map_header_columns( $header );
        if ( empty( $columns['sku'] ) || empty( $columns['topten_id'] ) ) {
            throw new Exception( __( 'No se encontraron columnas de SKU e ID.', 'ferk-topten-connector' ) );
        }

        return $this->match_rows( $rows, $columns );
    }

    /**
     * Parse CSV file.
     *
     * @param string $file_path File path.
     *
     * @return array
     */
    protected function parse_csv( string $file_path ) : array {
        $handle = fopen( $file_path, 'r' );
        if ( false === $handle ) {
            throw new Exception( __( 'No se pudo abrir el CSV.', 'ferk-topten-connector' ) );
        }

        $rows      = array();
        $row_count = 0;

        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            $rows[] = array_map( array( $this, 'normalize_cell' ), $data );
            $row_count++;

            if ( $row_count > self::MAX_ROWS ) {
                break;
            }
        }

        fclose( $handle );

        return $rows;
    }

    /**
     * Parse XLSX file using ZipArchive.
     *
     * @param string $file_path File path.
     *
     * @return array
     */
    protected function parse_xlsx( string $file_path ) : array {
        if ( ! class_exists( 'ZipArchive' ) ) {
            throw new Exception( __( 'La extensión ZipArchive no está disponible en el servidor.', 'ferk-topten-connector' ) );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $file_path ) ) {
            throw new Exception( __( 'No se pudo abrir el archivo XLSX.', 'ferk-topten-connector' ) );
        }

        $previous_libxml = libxml_use_internal_errors( true );

        $shared_strings = array();
        $shared_xml     = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( false !== $shared_xml && '' !== $shared_xml ) {
            $shared_doc = simplexml_load_string( $shared_xml );
            libxml_clear_errors();
            if ( $shared_doc && isset( $shared_doc->si ) ) {
                foreach ( $shared_doc->si as $si ) {
                    if ( isset( $si->t ) ) {
                        $shared_strings[] = $this->normalize_cell( (string) $si->t );
                    } elseif ( isset( $si->r ) ) {
                        $text = '';
                        foreach ( $si->r as $run ) {
                            if ( isset( $run->t ) ) {
                                $text .= (string) $run->t;
                            }
                        }
                        $shared_strings[] = $this->normalize_cell( $text );
                    } else {
                        $shared_strings[] = '';
                    }
                }
            }
        }

        $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        if ( false === $sheet_xml ) {
            $zip->close();
            throw new Exception( __( 'No se encontró la hoja principal en el XLSX.', 'ferk-topten-connector' ) );
        }

        $zip->close();

        $sheet_doc = simplexml_load_string( $sheet_xml );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_libxml );
        if ( ! $sheet_doc ) {
            throw new Exception( __( 'No se pudo leer la hoja del XLSX.', 'ferk-topten-connector' ) );
        }

        $sheet_doc->registerXPathNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

        $rows      = array();
        $row_count = 0;

        foreach ( $sheet_doc->sheetData->row as $row ) {
            $cells          = array();
            $current_index  = 0;
            foreach ( $row->c as $cell ) {
                $ref = (string) $cell['r'];
                if ( '' !== $ref ) {
                    $cell_index = $this->column_index_from_cell_reference( $ref );
                    while ( $current_index < $cell_index ) {
                        $cells[] = '';
                        $current_index++;
                    }
                }

                $cells[] = $this->read_xlsx_cell( $cell, $shared_strings );
                $current_index++;
            }

            $rows[] = $cells;
            $row_count++;

            if ( $row_count > self::MAX_ROWS ) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Read XLSX cell value.
     *
     * @param SimpleXMLElement $cell Cell element.
     * @param array            $shared_strings Shared strings table.
     *
     * @return string
     */
    protected function read_xlsx_cell( SimpleXMLElement $cell, array $shared_strings ) : string {
        $type  = isset( $cell['t'] ) ? (string) $cell['t'] : '';
        $value = '';

        if ( 'inlineStr' === $type && isset( $cell->is ) ) {
            if ( isset( $cell->is->t ) ) {
                $value = (string) $cell->is->t;
            } elseif ( isset( $cell->is->r ) ) {
                $buffer = '';
                foreach ( $cell->is->r as $run ) {
                    if ( isset( $run->t ) ) {
                        $buffer .= (string) $run->t;
                    }
                }
                $value = $buffer;
            }
        } elseif ( 's' === $type && isset( $cell->v ) ) {
            $index = (int) $cell->v;
            $value = isset( $shared_strings[ $index ] ) ? $shared_strings[ $index ] : '';
        } elseif ( isset( $cell->v ) ) {
            $value = (string) $cell->v;
        }

        return $this->normalize_cell( $value );
    }

    /**
     * Convert Excel column reference to zero-based index.
     *
     * @param string $reference Cell reference (e.g. A1).
     *
     * @return int
     */
    protected function column_index_from_cell_reference( string $reference ) : int {
        $letters = preg_replace( '/[^A-Z]/i', '', strtoupper( $reference ) );
        $length  = strlen( $letters );
        $index   = 0;

        for ( $i = 0; $i < $length; $i++ ) {
            $index *= 26;
            $index += ord( $letters[ $i ] ) - ord( 'A' ) + 1;
        }

        return max( 0, $index - 1 );
    }

    /**
     * Normalize header columns to canonical keys.
     *
     * @param array $header Header row.
     *
     * @return array
     */
    protected function map_header_columns( array $header ) : array {
        $map = array();

        foreach ( $header as $index => $value ) {
            $key = $this->normalize_header_key( $value );
            if ( '' === $key ) {
                continue;
            }

            if ( in_array( $key, array( 'sku', 'product_sku', 'codigo', 'codigopropio', 'sku_woo', 'prod_sku' ), true ) && ! isset( $map['sku'] ) ) {
                $map['sku'] = (int) $index;
                continue;
            }

            if ( in_array( $key, array( 'id', 'topten_id', 'prod_id', 'producto_id', 'id_topten', 'idproducto' ), true ) && ! isset( $map['topten_id'] ) ) {
                $map['topten_id'] = (int) $index;
                continue;
            }
        }

        return $map;
    }

    /**
     * Normalize header value for matching.
     *
     * @param string $value Header value.
     *
     * @return string
     */
    protected function normalize_header_key( $value ) : string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $normalized = strtolower( trim( (string) $value ) );
        $normalized = preg_replace( '/[^a-z0-9]+/i', '_', $normalized );
        $normalized = trim( $normalized, '_' );

        return $normalized;
    }

    /**
     * Normalize cell value as text.
     *
     * @param mixed $value Cell value.
     *
     * @return string
     */
    protected function normalize_cell( $value ) : string {
        if ( null === $value ) {
            return '';
        }

        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }

        if ( is_scalar( $value ) ) {
            return trim( (string) $value );
        }

        return '';
    }

    /**
     * Process rows and update WooCommerce products.
     *
     * @param array $rows Rows without header.
     * @param array $columns Column map.
     *
     * @return array
     */
    protected function match_rows( array $rows, array $columns ) : array {
        $summary = array(
            'totalRows' => 0,
            'processed' => 0,
            'updated'   => 0,
            'unchanged' => 0,
            'skipped'   => 0,
            'notFound'  => 0,
            'errors'    => 0,
        );

        $items   = array();
        $logger  = FTC_Logger::instance();
        $row_num = 1; // Data rows start after header.

        foreach ( $rows as $row ) {
            $summary['totalRows']++;
            $row_num++;

            $sku_index      = isset( $columns['sku'] ) ? (int) $columns['sku'] : -1;
            $topten_index   = isset( $columns['topten_id'] ) ? (int) $columns['topten_id'] : -1;
            $sku_value      = $this->normalize_cell( $this->get_column_value( $row, $sku_index ) );
            $topten_raw     = $this->normalize_cell( $this->get_column_value( $row, $topten_index ) );
            $topten_numeric = $this->parse_topten_id( $topten_raw );

            if ( '' === $sku_value || null === $topten_numeric ) {
                $summary['skipped']++;
                $items[] = array(
                    'row'        => $row_num,
                    'sku'        => $sku_value,
                    'topten_id'  => $topten_raw,
                    'status'     => 'skipped',
                    'message'    => __( 'Fila sin SKU o ID válido.', 'ferk-topten-connector' ),
                );
                continue;
            }

            $summary['processed']++;

            try {
                $product_id = wc_get_product_id_by_sku( $sku_value );
            } catch ( Exception $exception ) {
                $product_id = 0;
            }

            if ( ! $product_id ) {
                $summary['notFound']++;
                $items[] = array(
                    'row'        => $row_num,
                    'sku'        => $sku_value,
                    'topten_id'  => $topten_numeric,
                    'status'     => 'not_found',
                    'message'    => __( 'No se encontró un producto con este SKU.', 'ferk-topten-connector' ),
                );
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                $summary['notFound']++;
                $items[] = array(
                    'row'        => $row_num,
                    'sku'        => $sku_value,
                    'topten_id'  => $topten_numeric,
                    'status'     => 'not_found',
                    'message'    => __( 'No se pudo cargar el producto de WooCommerce.', 'ferk-topten-connector' ),
                );
                continue;
            }

            $previous_meta = get_post_meta( $product_id, 'id_topten', true );
            $previous_id   = is_numeric( $previous_meta ) ? (int) $previous_meta : 0;

            if ( $previous_id === $topten_numeric ) {
                $summary['unchanged']++;
                $items[] = array(
                    'row'               => $row_num,
                    'sku'               => $sku_value,
                    'topten_id'         => $topten_numeric,
                    'status'            => 'unchanged',
                    'message'           => __( 'El id_topten ya tenía este valor.', 'ferk-topten-connector' ),
                    'product_id'        => $product_id,
                    'product_name'      => $product->get_name(),
                    'product_parent_id' => $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0,
                    'previous_id'       => $previous_id,
                );
                continue;
            }

            $updated = update_post_meta( $product_id, 'id_topten', $topten_numeric );

            if ( false === $updated ) {
                $summary['errors']++;
                $logger->error(
                    'products-matcher',
                    'Meta update failed.',
                    array(
                        'product_id' => $product_id,
                        'sku'        => $sku_value,
                        'topten_id'  => $topten_numeric,
                    )
                );
                $items[] = array(
                    'row'               => $row_num,
                    'sku'               => $sku_value,
                    'topten_id'         => $topten_numeric,
                    'status'            => 'error',
                    'message'           => __( 'No se pudo actualizar el meta id_topten.', 'ferk-topten-connector' ),
                    'product_id'        => $product_id,
                    'product_name'      => $product->get_name(),
                    'product_parent_id' => $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0,
                    'previous_id'       => $previous_id,
                );
                continue;
            }

            $summary['updated']++;
            $items[] = array(
                'row'               => $row_num,
                'sku'               => $sku_value,
                'topten_id'         => $topten_numeric,
                'status'            => 'updated',
                'message'           => __( 'id_topten actualizado correctamente.', 'ferk-topten-connector' ),
                'product_id'        => $product_id,
                'product_name'      => $product->get_name(),
                'product_parent_id' => $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0,
                'previous_id'       => $previous_id,
            );
        }

        return array(
            'summary' => $summary,
            'items'   => $items,
        );
    }

    /**
     * Retrieve column value from row.
     *
     * @param array $row Row data.
     * @param int   $index Column index.
     *
     * @return mixed
     */
    protected function get_column_value( array $row, int $index ) {
        return isset( $row[ $index ] ) ? $row[ $index ] : '';
    }

    /**
     * Parse TopTen ID to integer.
     *
     * @param string $value Raw value.
     *
     * @return int|null
     */
    protected function parse_topten_id( string $value ) : ?int {
        if ( '' === $value ) {
            return null;
        }

        if ( ! is_numeric( $value ) ) {
            $value = preg_replace( '/[^0-9]/', '', $value );
            if ( '' === $value ) {
                return null;
            }
        }

        $number = (int) $value;
        if ( $number <= 0 ) {
            return null;
        }

        return $number;
    }
}
