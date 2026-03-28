<?php
/**
 * Simple XLSX Reader — Pure PHP, no dependencies
 * Reads .xlsx files without PhpSpreadsheet or shell_exec
 */
class XlsxReader {
    
    public static function read($filePath, $maxRows = 500) {
        $rows = [];
        
        if (!file_exists($filePath)) return $rows;
        
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) return $rows;
        
        // Read shared strings
        $strings = [];
        $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsXml) {
            $xml = simplexml_load_string($stringsXml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $strings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $text = '';
                        foreach ($si->r as $r) {
                            $text .= (string)$r->t;
                        }
                        $strings[] = $text;
                    }
                }
            }
        }
        
        // Read first sheet
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) {
            $zip->close();
            return $rows;
        }
        
        $xml = simplexml_load_string($sheetXml);
        if (!$xml) {
            $zip->close();
            return $rows;
        }
        
        $rowCount = 0;
        foreach ($xml->sheetData->row as $row) {
            $rowCount++;
            if ($rowCount > $maxRows + 1) break; // +1 for header
            
            $rowData = [];
            $maxCol = 0;
            
            foreach ($row->c as $cell) {
                // Get column index
                $ref = (string)$cell['r'];
                $col = self::colIndex($ref);
                if ($col > $maxCol) $maxCol = $col;
                
                // Get value
                $value = '';
                $type = (string)$cell['t'];
                
                if ($type === 's') {
                    // Shared string
                    $idx = intval((string)$cell->v);
                    $value = $strings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string)$cell->is->t;
                } else {
                    $value = (string)$cell->v;
                }
                
                // Pad array to column position
                while (count($rowData) < $col) $rowData[] = '';
                $rowData[$col] = $value;
            }
            
            // Pad to 14 columns minimum
            while (count($rowData) < 14) $rowData[] = '';
            
            $rows[] = $rowData;
        }
        
        $zip->close();
        return $rows;
    }
    
    private static function colIndex($cellRef) {
        preg_match('/^([A-Z]+)/', $cellRef, $matches);
        $letters = $matches[1] ?? 'A';
        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $index - 1; // 0-based
    }
}
