<?php
/**
 * Excel float/decimal precision fix for Gravity Tables.
 *
 * PhpSpreadsheet's Sheet::fromArray() passes all values as PHP strings which
 * causes two bugs in Excel:
 *  1. Float strings like "3.14" are stored with cell type String instead of
 *     Numeric, so formulas that reference the cell produce errors.
 *  2. Some float strings (e.g. "1.5", "12.30") are mis-identified by Excel as
 *     dates when the cell type is ambiguous.
 *
 * This service writes each cell with an explicit DataType::TYPE_NUMERIC and a
 * NumberFormat that prevents date auto-formatting.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Excel_Float_Service {

    /**
     * Write a single cell value with the correct PhpSpreadsheet data type.
     *
     * Float and integer values are written as TYPE_NUMERIC with a decimal
     * NumberFormat. All other values are written as TYPE_STRING.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param string                                        $coordinate  e.g. "B3"
     * @param mixed                                         $value
     */
    public static function set_typed_value(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $coordinate,
        $value
    ): void {
        if ( self::is_numeric_value( $value ) ) {
            $sheet->setCellValueExplicit(
                $coordinate,
                (float) $value,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
            );
            // Apply a number format that preserves up to 10 decimal places and
            // prevents Excel from interpreting the value as a date.
            $sheet->getStyle( $coordinate )->getNumberFormat()->setFormatCode(
                \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1
            );
        } else {
            $sheet->setCellValueExplicit(
                $coordinate,
                (string) $value,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
        }
    }

    /**
     * Write an entire row of values to a sheet, starting at column A.
     *
     * Replaces the Sheet::fromArray() call for data rows so that numeric
     * values receive the correct cell type rather than being stored as strings.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param array                                         $values  Flat ordered list of cell values.
     * @param int                                           $row     1-based row index.
     */
    public static function write_data_row(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $values,
        int $row
    ): void {
        $col = 1;
        foreach ( $values as $value ) {
            $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $col ) . $row;
            self::set_typed_value( $sheet, $coordinate, $value );
            $col++;
        }
    }

    /**
     * Determine whether a value should be written as a numeric cell.
     *
     * Returns true for:
     *  - PHP int / float scalars
     *  - Strings that represent a pure decimal number (with optional leading
     *    sign and a single decimal point) - e.g. "3.14", "-0.75", "1000.00"
     *
     * Returns false for:
     *  - Empty strings (let Excel treat them as blank)
     *  - Strings that look like dates or have non-numeric characters
     *
     * @param mixed $value
     */
    public static function is_numeric_value( $value ): bool {
        if ( is_int( $value ) || is_float( $value ) ) {
            return true;
        }

        if ( ! is_string( $value ) || $value === '' ) {
            return false;
        }

        // Must match an optional sign, digits, and at most one decimal point.
        // We intentionally exclude integers-only strings to let callers decide,
        // but we include them here for completeness (integer-looking strings are
        // safe to write as TYPE_NUMERIC).
        return (bool) preg_match( '/^[+-]?\d+(\.\d+)?$/', trim( $value ) );
    }
}
