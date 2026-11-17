<?php

namespace App\Support\Export;

use RuntimeException;
use ZipArchive;

/**
 * Writes a table to CSV or to an Excel workbook (.xlsx).
 *
 * The .xlsx is the minimal Office Open XML package (one sheet, inline
 * strings, numbers as numbers) built with PHP's zip extension, so no
 * spreadsheet library is needed. Amounts stay exact: they are written as the
 * decimal strings the reports produce, never through floats.
 */
class TableExporter
{
    private const NUMERIC = ['int', 'qty', 'money', 'percent'];

    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $header  lines written above the table (title, filters)
     */
    public function csv(array $columns, array $rows, array $header = []): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₱ and ñ correctly
        foreach ($header as $line) {
            fputcsv($out, [$this->csvCell($line)], escape: '');
        }
        if ($header !== []) {
            fputcsv($out, [], escape: '');
        }
        fputcsv($out, array_map(fn ($l) => $this->csvCell($l), array_column($columns, 'label')), escape: '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($c) => $this->csvCell($this->plain($row[$c['key']] ?? null)), $columns), escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $header
     */
    public function xlsx(array $columns, array $rows, array $header = [], string $sheetName = 'Report'): string
    {
        $sheetRows = [];
        $r = 1;
        foreach ($header as $line) {
            $sheetRows[] = $this->row($r++, [['t' => 's', 'v' => $line, 'bold' => $r === 2]]);
        }
        if ($header !== []) {
            $r++;
        }
        $sheetRows[] = $this->row($r++, array_map(fn ($c) => ['t' => 's', 'v' => $c['label'], 'bold' => true], $columns));
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $c) {
                $v = $row[$c['key']] ?? null;
                $numeric = in_array($c['type'], self::NUMERIC, true) && $v !== null && $v !== '' && is_numeric($v);
                $cells[] = $numeric ? ['t' => 'n', 'v' => (string) $v, 'fmt' => $c['type']] : ['t' => 's', 'v' => $this->plain($v)];
            }
            $sheetRows[] = $this->row($r++, $cells);
        }

        $cols = '';
        foreach ($columns as $i => $c) {
            $width = in_array($c['type'], self::NUMERIC, true) ? 14 : max(12, min(40, mb_strlen($c['label']) + 6));
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$width.'" customWidth="1"/>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<cols>'.$cols.'</cols><sheetData>'.implode('', $sheetRows).'</sheetData></worksheet>';

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the Excel file.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->xml(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sheetName) ?? 'Report', 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        // Styles: 0 default, 1 bold, 2 #,##0.00 (money), 3 #,##0.000 (qty), 4 #,##0 (int), 5 0.0 (percent)
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.000"/><numFmt numFmtId="165" formatCode="0.0"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="6">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * @param  list<array{t: string, v: string, bold?: bool, fmt?: string}>  $cells
     */
    private function row(int $r, array $cells): string
    {
        $xml = '<row r="'.$r.'">';
        foreach ($cells as $i => $cell) {
            $ref = $this->column($i).$r;
            if ($cell['t'] === 'n') {
                $style = ['money' => 2, 'qty' => 3, 'int' => 4, 'percent' => 5][$cell['fmt'] ?? ''] ?? 0;
                $xml .= '<c r="'.$ref.'" s="'.$style.'"><v>'.$cell['v'].'</v></c>';
            } elseif ($cell['v'] !== '') {
                $xml .= '<c r="'.$ref.'" t="inlineStr"'.(! empty($cell['bold']) ? ' s="1"' : '').'><is><t xml:space="preserve">'.$this->xml($cell['v']).'</t></is></c>';
            }
        }

        return $xml.'</row>';
    }

    private function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + (($n - 1) % 26)).$name;
        }

        return $name;
    }

    /**
     * CSV formula injection guard: text that a spreadsheet would run as a
     * formula (= + - @ tab CR) gets a leading apostrophe. Numbers stay numbers.
     */
    private function csvCell(string $v): string
    {
        return $v !== '' && ! is_numeric($v) && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }

    private function plain(mixed $v): string
    {
        return $v === null ? '' : (string) $v;
    }

    private function xml(string $s): string
    {
        // Drop characters XML 1.0 cannot hold, then escape.
        $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s);

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
