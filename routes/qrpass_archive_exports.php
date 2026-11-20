<?php
// QRPass archive exports: included INSIDE the protected auth:sanctum sysadmin route group.
// Uses no additional Composer dependencies. XLSX needs PHP ext-zip enabled.
Route::get('/archive/{id}/export/{format}', function (Request $request, string $id, string $format) use ($qrpassGuard) {
    $qrpassGuard($request);
    if (!Str::isUuid($id) || !in_array($format, ['xlsx', 'pdf'], true)) abort(404);
    $disk = Storage::disk('local');
    $filename = "qrpass-log-archives/{$id}.json";
    $manifestName = "qrpass-log-archives/{$id}.manifest.json";
    if (!$disk->exists($filename) || !$disk->exists($manifestName)) abort(404, 'Archive not found.');
    $raw = $disk->get($filename);
    $meta = json_decode($disk->get($manifestName), true, 512, JSON_THROW_ON_ERROR);
    if (($meta['archive_id'] ?? '') !== $id ||
        !hash_equals((string)($meta['sha256'] ?? ''), hash('sha256', $raw))) {
        abort(422, 'Archive integrity check failed.');
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $rows = $data['records'] ?? [];
    if (!is_array($rows)) abort(422, 'Invalid archive records.');
    $headers = ['ID','Date / Time','Actor','Username','Event Type','Action','Module','Description','Status','IP Address'];
    $all = [$headers];
    foreach ($rows as $record) {
        if (!is_array($record)) continue;
        $all[] = [
            (string)($record['id'] ?? ''), (string)($record['created_at'] ?? ''),
            (string)($record['actor_name'] ?? ''), (string)($record['actor_username'] ?? ''),
            (string)($record['event_type'] ?? ''), (string)($record['action'] ?? ''),
            (string)($record['module'] ?? ''), (string)($record['description'] ?? ''),
            (string)($record['status'] ?? ''), (string)($record['ip_address'] ?? ''),
        ];
    }
    $prefix = 'QRPass-Audit-Archive-' . substr($id, 0, 8);
    if ($format === 'xlsx') {
        if (!class_exists('ZipArchive')) abort(500, 'PHP Zip extension is needed for Excel export. Enable zip in XAMPP PHP.');
        $tmp = tempnam(sys_get_temp_dir(), 'qrpass_xlsx_');
        if ($tmp === false) abort(500, 'Unable to create Excel export.');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) abort(500, 'Unable to build Excel export.');
        $xmlSafe = function (string $value): string {
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
            return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        };
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($all as $index => $cells) {
            $sheet .= '<row r="'.($index+1).'">';
            foreach ($cells as $column => $value) {
                $col = ''; $n = $column + 1;
                while ($n) { $col = chr(65 + ($n-1)%26) . $col; $n = intdiv($n-1, 26); }
                // Keep every cell as text; spreadsheet formulas from log descriptions must never execute.
                $sheet .= '<c r="'.$col.($index+1).'" t="inlineStr"><is><t xml:space="preserve">'.$xmlSafe((string)$value).'</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Audit Logs" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml',$sheet);
        if (!$zip->close()) { @unlink($tmp); abort(500, 'Could not finalize Excel file.'); }
        return response()->download($tmp,$prefix.'.xlsx',['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }
    // Small self-contained PDF generator (Courier, paginated). Non-Latin text is transliterated
    // because built-in standard PDF fonts cannot embed Unicode glyphs.
    $ascii = function ($value): string {
        $v = preg_replace('/\s+/u', ' ', (string)$value);
        $v = $v === null ? '' : $v;
        $v = function_exists('iconv') ? (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v) ?: '') : preg_replace('/[^\x20-\x7E]/', '?', $v);
        return preg_replace('/[^\x20-\x7E]/', '?', $v) ?? '';
    };
    $lines = ['QRPASS - AUDIT LOG ARCHIVE', 'Archive: ' . $id, 'Records: ' . count($rows), str_repeat('-', 110)];
    foreach (array_slice($all, 1) as $cells) {
        $lines[] = substr($ascii('#'.$cells[0].'  '.$cells[1].'  '.$cells[2].'  '.$cells[5].'  ['.$cells[8].']'), 0, 118);
        $desc = $ascii($cells[7]);
        foreach (str_split($desc ?: ' ', 116) as $part) $lines[] = '  '. $part;
    }
    $chunks = array_chunk($lines, 42);
    $objects = [null, '<< /Type /Catalog /Pages 2 0 R >>', '', '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>'];
    $pages = [];
    foreach ($chunks as $pageLines) {
        $pageId = count($objects); $contentId = $pageId + 1;
        $pages[] = $pageId . ' 0 R';
        $stream = "BT /F1 8 Tf 38 550 Td 11 TL\n";
        foreach ($pageLines as $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii($line));
            $stream .= '(' . $escaped . ") Tj T*\n";
        }
        $stream .= "ET\n";
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
        $objects[] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
    }
    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pages).'] /Count '.count($pages).' >>';
    $pdf = "%PDF-1.4\n"; $offsets=[0];
    for($i=1;$i<count($objects);$i++) { $offsets[$i]=strlen($pdf); $pdf .= $i." 0 obj\n".$objects[$i]."\nendobj\n"; }
    $xref = strlen($pdf); $pdf .= 'xref' . "\n0 ".count($objects)."\n0000000000 65535 f \n";
    for($i=1;$i<count($objects);$i++) $pdf .= sprintf('%010d 00000 n ', $offsets[$i])."\n";
    $pdf .= 'trailer' . "\n<< /Size ".count($objects)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    return response($pdf, 200, ['Content-Type'=>'application/pdf', 'Content-Disposition'=>'attachment; filename="'.$prefix.'.pdf"']);
});
