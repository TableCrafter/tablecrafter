<?php
/**
 * Unit Tests for TC_Export_Handler (consolidated, honest export handler).
 *
 * Proves Gap #1:
 *  - XLSX export produces a GENUINE OOXML spreadsheet (openable via ZipArchive),
 *    NOT a CSV/HTML file renamed to .xlsx.
 *  - PDF export is HONEST: a real, structurally-valid PDF document, NOT CSV/HTML
 *    mislabeled as a PDF.
 *  - There is exactly ONE export handler class; the old "enhanced" handler and
 *    its file are gone, and the frontend client-data contract routes through the
 *    single canonical handler.
 *
 * @package TableCrafter\Tests
 */

use PHPUnit\Framework\TestCase;

class ExportHandlerTest extends TestCase
{
    /** @var array */
    private $data;

    /** @var array */
    private $headers;

    /** @var array Files created during a test, removed in tearDown. */
    private $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->data = [
            ['name' => 'Alice', 'role' => 'Engineer', 'score' => '42'],
            ['name' => 'Bob',   'role' => 'Designer', 'score' => '17'],
            // Include a value with XML/PDF-significant characters to prove escaping.
            ['name' => 'C&D (Co)', 'role' => 'Sales <b>', 'score' => '8'],
        ];
        $this->headers = ['name', 'role', 'score'];
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            if ($file && file_exists($file)) {
                @unlink($file);
            }
        }
        $this->created = [];
        parent::tearDown();
    }

    private function track(array $result): array
    {
        if (isset($result['file_path'])) {
            $this->created[] = $result['file_path'];
        }
        return $result;
    }

    // ---- Single handler / consolidation -----------------------------------

    public function test_only_one_export_handler_class_exists(): void
    {
        $this->assertTrue(
            class_exists('TC_Export_Handler'),
            'Canonical TC_Export_Handler must exist.'
        );
        $this->assertFalse(
            class_exists('TC_Export_Handler_Enhanced'),
            'The dead "enhanced" handler class must be gone after consolidation.'
        );
    }

    public function test_enhanced_handler_file_is_deleted(): void
    {
        $this->assertFileDoesNotExist(
            TABLECRAFTER_PATH . 'includes/class-tc-export-handler-enhanced.php',
            'The enhanced handler file must be removed from the codebase.'
        );
    }

    // ---- CSV (sanity: still works) ----------------------------------------

    public function test_csv_export_produces_csv_with_headers(): void
    {
        $result = $this->track(TC_Export_Handler::export_data(
            $this->data,
            $this->headers,
            ['format' => 'csv', 'filename' => 'people', 'include_headers' => true]
        ));

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('people.csv', $result['filename']);
        $this->assertSame('text/csv', $result['mime_type']);

        $contents = file_get_contents($result['file_path']);
        $this->assertStringContainsString('Alice', $contents);
        $this->assertStringContainsString('name', $contents);
    }

    // ---- XLSX: must be a GENUINE OOXML package -----------------------------

    public function test_xlsx_export_is_a_real_openable_ooxml_file(): void
    {
        $result = $this->track(TC_Export_Handler::export_data(
            $this->data,
            $this->headers,
            ['format' => 'xlsx', 'filename' => 'people', 'include_headers' => true]
        ));

        $this->assertArrayNotHasKey('error', $result, 'XLSX export must not error.');
        $this->assertSame('people.xlsx', $result['filename']);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $result['mime_type']
        );

        $path = $result['file_path'];
        $this->assertFileExists($path);

        // 1. The file must begin with the ZIP local-file-header magic "PK\x03\x04".
        $magic = file_get_contents($path, false, null, 0, 4);
        $this->assertSame("PK\x03\x04", $magic, 'XLSX must be a ZIP container, not plain text/CSV/HTML.');

        // 2. It must be openable as a ZIP archive (a renamed CSV would not be).
        $this->assertTrue(class_exists('ZipArchive'), 'ZipArchive required for this assertion.');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'XLSX must open as a valid ZIP archive.');

        // 3. The required OOXML parts must be present.
        $required = [
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/worksheets/sheet1.xml',
        ];
        foreach ($required as $part) {
            $this->assertNotFalse(
                $zip->locateName($part),
                "OOXML package must contain {$part}."
            );
        }

        // 4. The worksheet must contain the actual data and be valid XML.
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertNotFalse($sheet);
        $xml = simplexml_load_string($sheet);
        $this->assertNotFalse($xml, 'Worksheet must be well-formed XML.');
        $this->assertStringContainsString('Alice', $sheet);
        $this->assertStringContainsString('Engineer', $sheet);
        // Special characters must be XML-escaped, not raw.
        $this->assertStringContainsString('C&amp;D', $sheet);
    }

    // ---- PDF: must be an HONEST, structurally-valid PDF --------------------

    public function test_pdf_export_is_an_honest_valid_pdf(): void
    {
        $result = $this->track(TC_Export_Handler::export_data(
            $this->data,
            $this->headers,
            ['format' => 'pdf', 'filename' => 'people', 'include_headers' => true]
        ));

        $this->assertArrayNotHasKey('error', $result, 'PDF export must not error.');
        $this->assertSame('people.pdf', $result['filename']);
        $this->assertSame('application/pdf', $result['mime_type']);

        $bytes = file_get_contents($result['file_path']);

        // 1. Must start with the PDF signature.
        $this->assertSame('%PDF-', substr($bytes, 0, 5), 'Output must begin with the %PDF- signature.');

        // 2. Must NOT be HTML or CSV mislabeled as PDF.
        $this->assertStringNotContainsString('<!DOCTYPE html', $bytes, 'PDF must not be HTML.');
        $this->assertStringNotContainsString('<html', $bytes, 'PDF must not be HTML.');

        // 3. Must contain a valid PDF object/trailer structure.
        $this->assertStringContainsString('/Type /Catalog', $bytes);
        $this->assertStringContainsString('/Type /Page', $bytes);
        $this->assertStringContainsString('trailer', $bytes);
        $this->assertStringContainsString('%%EOF', $bytes);

        // 4. The cross-reference offset in startxref must point at the real "xref".
        $this->assertSame(1, preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $bytes, $m), 'startxref must be present.');
        $xref_offset = (int) $m[1];
        $this->assertSame(
            'xref',
            substr($bytes, $xref_offset, 4),
            'startxref must point at the byte offset of the xref table (proves a real, valid PDF).'
        );
    }

    // ---- Client-data contract routes through the one handler --------------

    public function test_export_client_data_routes_excel_alias_to_real_xlsx(): void
    {
        $columns = [
            ['field' => 'name',  'label' => 'Full Name'],
            ['field' => 'role',  'label' => 'Job Title'],
            ['field' => 'score', 'label' => 'Score'],
        ];

        // 'excel' (the frontend label) must be accepted as an alias for xlsx.
        $result = $this->track(TC_Export_Handler::export_client_data(
            $this->data,
            $columns,
            'excel',
            'people'
        ));

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('people.xlsx', $result['filename']);

        // Header row should use the human labels, not the raw field keys.
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($result['file_path']) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringContainsString('Full Name', $sheet, 'Column labels must drive the header row.');
        $this->assertStringContainsString('Job Title', $sheet);
    }

    public function test_export_client_data_rejects_empty_input(): void
    {
        $result = TC_Export_Handler::export_client_data([], [], 'excel', 'x');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_unsupported_format_returns_error(): void
    {
        $result = TC_Export_Handler::export_data($this->data, $this->headers, ['format' => 'docx']);
        $this->assertArrayHasKey('error', $result);
    }
}
