<?php
/**
 * XlsxWriter — Simple Excel XLSX Generator
 * يدعم RTL + تلوين الهيدر + عرض الأعمدة التلقائي
 */
class XlsxWriter {
    private $headers = [];
    private $rows = [];
    private $title = 'Sheet1';
    private $headerColor = '1a2744'; // Navy
    private $headerTextColor = 'FFFFFF';
    
    public function __construct($title = 'Sheet1') {
        $this->title = $title;
    }
    
    public function setHeaders($headers) {
        $this->headers = $headers;
    }
    
    public function addRow($row) {
        $this->rows[] = array_values($row);
    }
    
    public function addRows($rows) {
        foreach ($rows as $row) $this->addRow($row);
    }
    
    public function setHeaderColor($bg, $text = 'FFFFFF') {
        $this->headerColor = str_replace('#', '', $bg);
        $this->headerTextColor = str_replace('#', '', $text);
    }
    
    public function save($filepath) {
        $zip = new ZipArchive();
        if ($zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) return false;
        
        $colCount = max(count($this->headers), !empty($this->rows) ? count($this->rows[0]) : 0);
        $rowCount = count($this->rows) + 1; // +1 for header
        $lastCol = $this->colLetter($colCount - 1);
        
        // [Content_Types].xml
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>');
        
        // _rels/.rels
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
        
        // xl/_rels/workbook.xml.rels
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>');
        
        // xl/workbook.xml
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<bookViews><workbookView rightToLeft="1"/></bookViews>
<sheets><sheet name="' . htmlspecialchars($this->title) . '" sheetId="1" r:id="rId1"/></sheets>
</workbook>');
        
        // Shared strings
        $strings = [];
        $stringIndex = [];
        foreach ($this->headers as $h) {
            if (!isset($stringIndex[$h])) { $stringIndex[$h] = count($strings); $strings[] = $h; }
        }
        foreach ($this->rows as $row) {
            foreach ($row as $cell) {
                $v = (string)$cell;
                if (!is_numeric($cell) && !isset($stringIndex[$v])) { $stringIndex[$v] = count($strings); $strings[] = $v; }
            }
        }
        
        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
        foreach ($strings as $s) { $ssXml .= '<si><t>' . htmlspecialchars($s) . '</t></si>'; }
        $ssXml .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $ssXml);
        
        // xl/styles.xml — with header color
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="2">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FF' . $this->headerTextColor . '"/><name val="Calibri"/></font>
</fonts>
<fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF' . $this->headerColor . '"/></patternFill></fill>
</fills>
<borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border>
</borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="3">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
</cellXfs>
</styleSheet>');
        
        // xl/worksheets/sheet1.xml
        $colWidths = '';
        for ($c = 0; $c < $colCount; $c++) {
            $maxLen = isset($this->headers[$c]) ? mb_strlen($this->headers[$c]) : 5;
            foreach ($this->rows as $row) {
                if (isset($row[$c])) $maxLen = max($maxLen, mb_strlen((string)$row[$c]));
            }
            $w = min(max($maxLen * 1.5 + 2, 8), 50);
            $colWidths .= '<col min="' . ($c+1) . '" max="' . ($c+1) . '" width="' . $w . '" customWidth="1"/>';
        }
        
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetViews><sheetView rightToLeft="1" tabSelected="1" workbookViewId="0"/></sheetViews>
<cols>' . $colWidths . '</cols>
<sheetData>';
        
        // Header row
        $sheetXml .= '<row r="1" ht="28" customHeight="1">';
        for ($c = 0; $c < count($this->headers); $c++) {
            $ref = $this->colLetter($c) . '1';
            $idx = $stringIndex[$this->headers[$c]];
            $sheetXml .= '<c r="' . $ref . '" s="1" t="s"><v>' . $idx . '</v></c>';
        }
        $sheetXml .= '</row>';
        
        // Data rows
        foreach ($this->rows as $ri => $row) {
            $r = $ri + 2;
            $sheetXml .= '<row r="' . $r . '">';
            for ($c = 0; $c < count($row); $c++) {
                $ref = $this->colLetter($c) . $r;
                $val = $row[$c];
                if (is_numeric($val) && $val !== '') {
                    $sheetXml .= '<c r="' . $ref . '" s="2"><v>' . $val . '</v></c>';
                } else {
                    $v = (string)$val;
                    $idx = $stringIndex[$v] ?? 0;
                    $sheetXml .= '<c r="' . $ref . '" s="2" t="s"><v>' . $idx . '</v></c>';
                }
            }
            $sheetXml .= '</row>';
        }
        
        $sheetXml .= '</sheetData>';
        $sheetXml .= '<autoFilter ref="A1:' . $lastCol . $rowCount . '"/>';
        $sheetXml .= '</worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        
        $zip->close();
        return true;
    }
    
    public function download($filename) {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }
    
    private function colLetter($idx) {
        $r = '';
        while ($idx >= 0) { $r = chr(65 + ($idx % 26)) . $r; $idx = intval($idx / 26) - 1; }
        return $r;
    }
}
