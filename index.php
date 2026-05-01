<?php
/**
 * index.php — ElementsViewer v2 UI for browsing elements.data files.
 *
 * Query parameters:
 *   file = path to a .data file (relative to ./data)
 *   list = list index to view/edit
 *   off  = record offset within the selected list (default 0)
 *   msg  = flash message rendered as a toast
 *
 * POST parameters (saving a list definition):
 *   action = 'save_list'
 *   version, list, name, names[], types[], refs[]
 *
 * Schema storage:
 *   structures/{version}/list_{idx}.json — one file per list (lazy migration
 *   from the old structures/{version}.json single-file layout, which gets
 *   renamed to *.bak after the first split).
 */

require __DIR__ . '/ElementsReader.php';

// ---------------------------------------------------------------------------
// Resolve folders
// ---------------------------------------------------------------------------
$dataDir   = realpath(__DIR__ . '/data');
$structDir = __DIR__ . '/structures';

function list_data_files($dir) {
    $out = [];
    if ($dir && is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if (preg_match('/^elements.*\.data$/i', $f)) $out[] = $f;
        }
    }
    sort($out);
    return $out;
}

function structure_folder($dir, $versionLabel) {
    return $dir . '/' . $versionLabel;
}

function list_file_path($dir, $versionLabel, $listIdx) {
    return structure_folder($dir, $versionLabel) . '/list_' . (int)$listIdx . '.json';
}

/**
 * One-time migration: if a legacy structures/{version}.json exists but the
 * per-list folder doesn't, split the old file into per-list JSON files and
 * rename the original to *.bak so the migration is reversible and doesn't
 * re-run.
 */
function maybe_migrate_legacy($dir, $versionLabel) {
    $folder = structure_folder($dir, $versionLabel);
    $legacy = $dir . '/' . $versionLabel . '.json';
    if (is_dir($folder) || !is_file($legacy)) return;

    $raw  = file_get_contents($legacy);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['lists']) || !is_array($data['lists'])) return;

    if (!@mkdir($folder, 0777, true) && !is_dir($folder)) return;
    foreach ($data['lists'] as $idx => $entry) {
        if (!is_array($entry) || !ctype_digit((string)$idx)) continue;
        @file_put_contents(
            list_file_path($dir, $versionLabel, (int)$idx),
            json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
    @rename($legacy, $legacy . '.bak');
}

/**
 * Load every per-list schema for a version.
 * Returns ['version' => ..., 'lists' => [idx => entry], '_folder' => path].
 */
function load_structure($dir, $versionLabel) {
    maybe_migrate_legacy($dir, $versionLabel);
    $folder = structure_folder($dir, $versionLabel);
    $lists  = [];
    if (is_dir($folder)) {
        foreach (scandir($folder) as $f) {
            if (!preg_match('/^list_(\d+)\.json$/', $f, $m)) continue;
            $raw   = file_get_contents($folder . '/' . $f);
            $entry = json_decode($raw, true);
            if (is_array($entry)) $lists[(string)(int)$m[1]] = $entry;
        }
    }
    return ['version' => $versionLabel, 'lists' => $lists, '_folder' => $folder];
}

/**
 * Persist a single list schema. Pass $entry = null to delete the file.
 */
function save_list_entry($dir, $versionLabel, $listIdx, $entry) {
    $folder = structure_folder($dir, $versionLabel);
    $path   = list_file_path($dir, $versionLabel, $listIdx);
    if ($entry === null) {
        return !is_file($path) || @unlink($path);
    }
    if (!is_dir($folder) && !@mkdir($folder, 0777, true) && !is_dir($folder)) return false;
    return file_put_contents(
        $path,
        json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    ) !== false;
}

// ---------------------------------------------------------------------------
// AJAX endpoint: diff list sizes between two .data files
// ---------------------------------------------------------------------------
//   GET ?action=diff_lists&file_a=...&file_b=...
// Returns JSON describing per-list (sizeof, count) for both files. Used by
// the Compare modal to surface struct-layout changes between game versions.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'diff_lists') {
    header('Content-Type: application/json');
    $fileA = basename($_GET['file_a'] ?? '');
    $fileB = basename($_GET['file_b'] ?? '');
    $pathA = $dataDir ? $dataDir . '/' . $fileA : '';
    $pathB = $dataDir ? $dataDir . '/' . $fileB : '';
    if (!$dataDir || !is_file($pathA) || !is_file($pathB)) {
        echo json_encode(['error' => 'One or both files not found in data/.']);
        exit;
    }
    if ($fileA === $fileB) {
        echo json_encode(['error' => 'Pick two different files to compare.']);
        exit;
    }
    try {
        $rA = (new ElementsReader($pathA))->scan();
        $rB = (new ElementsReader($pathB))->scan();
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
    // Look up schema names from A's version (so each row has a human label).
    $structA    = load_structure($structDir, $rA->getVersionLabel());
    $namesByIdx = [];
    foreach (($structA['lists'] ?? []) as $key => $entry) {
        $namesByIdx[(int)$key] = is_array($entry) ? ($entry['name'] ?? '') : '';
    }
    $byIdxA = $byIdxB = [];
    foreach ($rA->getLists() as $L) $byIdxA[(int)$L['index']] = $L;
    foreach ($rB->getLists() as $L) $byIdxB[(int)$L['index']] = $L;
    $maxIdx = max(
        empty($byIdxA) ? -1 : max(array_keys($byIdxA)),
        empty($byIdxB) ? -1 : max(array_keys($byIdxB))
    );
    $rows = [];
    $sumChangedSize = 0; $sumChangedCount = 0; $sumOnlyA = 0; $sumOnlyB = 0;
    for ($i = 0; $i <= $maxIdx; $i++) {
        $a = $byIdxA[$i] ?? null;
        $b = $byIdxB[$i] ?? null;
        if (!$a && !$b) continue;
        $sa = $a ? (int)$a['sizeof'] : null;
        $sb = $b ? (int)$b['sizeof'] : null;
        $ca = $a ? (int)$a['count']  : null;
        $cb = $b ? (int)$b['count']  : null;
        $sizeChanged  = ($a && $b && $sa !== $sb);
        $countChanged = ($a && $b && $ca !== $cb);
        $onlyInA      = ($a && !$b);
        $onlyInB      = (!$a && $b);
        if ($sizeChanged)  $sumChangedSize++;
        if ($countChanged) $sumChangedCount++;
        if ($onlyInA)      $sumOnlyA++;
        if ($onlyInB)      $sumOnlyB++;
        $rows[] = [
            'idx'           => $i,
            'name'          => $namesByIdx[$i] ?? '',
            'sizeof_a'      => $sa,
            'sizeof_b'      => $sb,
            'count_a'       => $ca,
            'count_b'       => $cb,
            'size_changed'  => $sizeChanged,
            'count_changed' => $countChanged,
            'only_in_a'     => $onlyInA,
            'only_in_b'     => $onlyInB,
        ];
    }
    echo json_encode([
        'file_a'    => $fileA,
        'file_b'    => $fileB,
        'version_a' => $rA->getVersionLabel(),
        'version_b' => $rB->getVersionLabel(),
        'rows'      => $rows,
        'totals'    => [
            'rows'          => count($rows),
            'changed_size'  => $sumChangedSize,
            'changed_count' => $sumChangedCount,
            'only_a'        => $sumOnlyA,
            'only_b'        => $sumOnlyB,
        ],
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// Endpoint: download a .cfg file describing every list in the current .data
// ---------------------------------------------------------------------------
//   GET ?action=export_cfg&file=<fileName>
// Format (one record per list):
//   <total_lists_incl_talk_proc>
//   <total_lists_excl_talk_proc>
//   <blank>
//   <idx> - <NAME>
//   <offset>                       (4=default; 8 for first list = file header;
//                                   4 + marker bytes for lists preceded by
//                                   md5/exporter_meta/tag_block markers)
//   <Field1;Field2;...>
//   <type1;type2;...>
//   <blank>
//   ...
//   <talk_idx> - TALK_PROC
//   0
//   RAW
//   byte:AUTO
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'export_cfg') {
    $fileN = basename($_GET['file'] ?? '');
    $path  = $dataDir ? $dataDir . '/' . $fileN : '';
    if (!$dataDir || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File not found in data/.';
        exit;
    }
    try {
        $r = (new ElementsReader($path))->scan();
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Scan error: ' . $e->getMessage();
        exit;
    }
    $structure = load_structure($structDir, $r->getVersionLabel());
    $byIdx     = [];
    foreach (($structure['lists'] ?? []) as $key => $entry) {
        if (is_array($entry)) $byIdx[(int)$key] = $entry;
    }
    $lists                 = $r->getLists();
    $hasTalkProc           = ($r->getTalkProcCount() !== null);
    $totalExclTalkProc     = count($lists);
    $totalInclTalkProc     = $totalExclTalkProc + ($hasTalkProc ? 1 : 0);

    $lines   = [];
    $lines[] = (string)$totalInclTalkProc;
    $lines[] = (string)$totalExclTalkProc;
    $lines[] = '';

    $missingSchema = 0;
    for ($i = 0; $i < $totalExclTalkProc; $i++) {
        $L           = $lists[$i];
        $headerStart = (int)$L['data_offset'] - 8;     // 8 = sizeof + count
        if ($i === 0) {
            // First list: offset = bytes from file start to this header
            // (file header = 8 + any leading markers).
            $offset = $headerStart;
        } else {
            $prev        = $lists[$i - 1];
            $prevBodyEnd = (int)$prev['data_offset'] + (int)$prev['body_bytes'];
            $gap         = $headerStart - $prevBodyEnd;
            // Default per-list editor overhead is 4 bytes (the sizeof field
            // it skips); add any marker bytes (md5, exporter_meta, etc.).
            $offset      = 4 + max(0, $gap);
        }

        $idx   = (int)$L['index'];
        $entry = $byIdx[$idx] ?? null;
        if ($entry && !empty($entry['fields'])) {
            $name  = $entry['name'] ?? ('UNNAMED_' . $idx);
            $names = [];
            $types = [];
            foreach ($entry['fields'] as $f) {
                $names[] = $f['name'] ?? '';
                $types[] = $f['type'] ?? 'byte:1';
            }
        } else {
            // No schema yet — emit a single opaque blob of the right width so
            // the .cfg still loads. The user can refine later.
            $missingSchema++;
            $name  = 'UNKNOWN_' . $idx;
            $names = ['Data'];
            $types = ['byte:' . (int)$L['sizeof']];
        }

        $lines[] = $idx . ' - ' . $name;
        $lines[] = (string)$offset;
        $lines[] = implode(';', $names);
        $lines[] = implode(';', $types);
        $lines[] = '';
    }

    if ($hasTalkProc) {
        $lines[] = $totalExclTalkProc . ' - TALK_PROC';
        $lines[] = '0';
        $lines[] = 'RAW';
        $lines[] = 'byte:AUTO';
    }

    // CRLF — the editor lives on Windows.
    $cfg = implode("\r\n", $lines) . "\r\n";

    $stem         = preg_replace('/\.data$/i', '', $fileN);
    $downloadName = $stem . '_' . $r->getVersionLabel() . '.cfg';

    header('Content-Type: text/plain; charset=ascii');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . strlen($cfg));
    header('X-Cfg-Lists: ' . $totalInclTalkProc);
    header('X-Cfg-Missing-Schema: ' . $missingSchema);
    echo $cfg;
    exit;
}

// ---------------------------------------------------------------------------
// AJAX: compare two records from the same list side-by-side
// ---------------------------------------------------------------------------
//   GET ?action=compare_records&file=X&list=Y&row_a=A&row_b=B
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'compare_records') {
    header('Content-Type: application/json');
    $fileN   = basename($_GET['file'] ?? '');
    $listIdx = (int)($_GET['list'] ?? -1);
    $rowA    = max(0, (int)($_GET['row_a'] ?? 0));
    $rowB    = max(0, (int)($_GET['row_b'] ?? 0));
    $path    = $dataDir ? $dataDir . '/' . $fileN : '';
    if (!$dataDir || !is_file($path)) { echo json_encode(['error' => 'File not found']); exit; }
    try {
        $cmpReader = (new ElementsReader($path))->scan();
    } catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); exit; }
    $cmpStruct = load_structure($structDir, $cmpReader->getVersionLabel());
    $cmpLists  = $cmpReader->getLists();
    if ($listIdx < 0 || !isset($cmpLists[$listIdx])) { echo json_encode(['error' => 'List not found']); exit; }
    $cmpMeta   = $cmpLists[$listIdx];
    $cmpDef    = $cmpStruct['lists'][(string)$listIdx] ?? null;
    $cmpCount  = (int)$cmpMeta['count'];
    if (!$cmpDef || empty($cmpDef['fields'])) {
        echo json_encode(['error' => 'No schema defined for this list']); exit;
    }
    if ($rowA >= $cmpCount || $rowB >= $cmpCount) {
        echo json_encode(['error' => "Row index out of range (list has $cmpCount records)"]); exit;
    }
    $cmpSchema = $cmpDef['fields'];
    try {
        $decA = $cmpReader->decodeList($listIdx, $cmpSchema, 1, $rowA);
        $decB = $cmpReader->decodeList($listIdx, $cmpSchema, 1, $rowB);
    } catch (Throwable $e) { echo json_encode(['error' => 'Decode: ' . $e->getMessage()]); exit; }
    $fmtA = $fmtB = [];
    if (!empty($decA['rows'])) foreach ($decA['rows'][0] as $k => $v) $fmtA[$k] = format_value($v);
    if (!empty($decB['rows'])) foreach ($decB['rows'][0] as $k => $v) $fmtB[$k] = format_value($v);
    echo json_encode([
        'fields'     => $decA['fields'],
        'record_a'   => ['row' => $rowA, 'data' => $fmtA],
        'record_b'   => ['row' => $rowB, 'data' => $fmtB],
        'list_name'  => $cmpDef['name'] ?? ('LIST_' . $listIdx),
        'list_idx'   => $listIdx,
        'id_field'   => $cmpDef['id_field']   ?? '',
        'name_field' => $cmpDef['name_field'] ?? '',
        'count'      => $cmpCount,
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// AJAX/download: export all records of a list as CSV, JSON, or TSV
// ---------------------------------------------------------------------------
//   GET ?action=export_list&file=X&list=Y&format=csv|json|tsv
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'export_list') {
    $fileN   = basename($_GET['file']   ?? '');
    $listIdx = (int)($_GET['list']      ?? -1);
    $format  = strtolower($_GET['format'] ?? 'csv');
    if (!in_array($format, ['csv', 'json', 'tsv'], true)) $format = 'csv';

    $path = $dataDir ? $dataDir . '/' . $fileN : '';
    if (!$dataDir || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File not found in data/.';
        exit;
    }
    try {
        $expReader = (new ElementsReader($path))->scan();
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Scan error: ' . $e->getMessage();
        exit;
    }
    $expStruct = load_structure($structDir, $expReader->getVersionLabel());
    $expLists  = $expReader->getLists();
    if ($listIdx < 0 || !isset($expLists[$listIdx])) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'List not found.';
        exit;
    }
    $expMeta   = $expLists[$listIdx];
    $expDef    = $expStruct['lists'][(string)$listIdx] ?? null;
    $expCount  = (int)$expMeta['count'];
    if (!$expDef || empty($expDef['fields'])) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No schema defined for this list. Define field names/types first.';
        exit;
    }
    $expSchema    = $expDef['fields'];
    $safeName     = preg_replace('/[^a-zA-Z0-9_]/', '_', $expDef['name'] ?? ('list_' . $listIdx));
    $stem         = preg_replace('/\.data$/i', '', $fileN);
    $downloadName = $stem . '_' . $safeName . '.' . $format;

    $mime = match($format) {
        'json'  => 'application/json',
        'tsv'   => 'text/tab-separated-values',
        default => 'text/csv',
    };
    header('Content-Type: ' . $mime . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('X-Accel-Buffering: no');
    if (ob_get_level()) ob_end_clean();

    $chunk = 500;

    if ($format === 'json') {
        echo '[';
        $first = true;
        for ($off = 0; $off < $expCount; $off += $chunk) {
            $dec = $expReader->decodeList($listIdx, $expSchema, $chunk, $off);
            foreach ($dec['rows'] ?? [] as $row) {
                $obj = [];
                foreach ($expSchema as $f) {
                    $k = $f['name'];
                    $obj[$k] = isset($row[$k]) ? format_value($row[$k]) : '';
                }
                echo ($first ? "\n" : ",\n") . json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $first = false;
            }
            flush();
        }
        echo "\n]\n";
    } else {
        // CSV or TSV — header row
        $sep   = $format === 'tsv' ? "\t" : ',';
        $cells = [];
        foreach ($expSchema as $f) $cells[] = export_cell($f['name'], $sep);
        echo implode($sep, $cells) . "\r\n";

        for ($off = 0; $off < $expCount; $off += $chunk) {
            $dec = $expReader->decodeList($listIdx, $expSchema, $chunk, $off);
            foreach ($dec['rows'] ?? [] as $row) {
                $cells = [];
                foreach ($expSchema as $f) {
                    $k = $f['name'];
                    $cells[] = export_cell(isset($row[$k]) ? format_value($row[$k]) : '', $sep);
                }
                echo implode($sep, $cells) . "\r\n";
            }
            flush();
        }
    }
    exit;
}

// ---------------------------------------------------------------------------
// Handle POST (save a list definition)
// ---------------------------------------------------------------------------
$saveMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_list') {
    $versionLabel = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['version'] ?? '');
    $listIdx      = (int)($_POST['list'] ?? -1);
    $listName     = trim($_POST['name'] ?? '');
    // Prefer the JSON-bundled payload (used by the JS submit handler) so we
    // don't lose rows past PHP's max_input_vars cap. Fall back to per-row
    // arrays for backward compatibility / no-JS clients.
    $names = $types = $refs = [];
    $bundled = $_POST['fields_json'] ?? '';
    if ($bundled !== '') {
        $decoded = json_decode($bundled, true);
        if (is_array($decoded)) {
            foreach ($decoded as $row) {
                $names[] = $row['name'] ?? '';
                $types[] = $row['type'] ?? '';
                $refs[]  = $row['refs'] ?? '';
            }
        }
    } else {
        $names = $_POST['names'] ?? [];
        $types = $_POST['types'] ?? [];
        $refs  = $_POST['refs']  ?? [];
    }
    // Preserve existing name_field / id_field (the template UI doesn't expose them).
    $preservedNameField = trim($_POST['name_field'] ?? '');
    $preservedIdField   = trim($_POST['id_field']   ?? '');

    if ($versionLabel && $listIdx >= 0) {
        $fields = [];
        for ($i = 0; $i < count($names); $i++) {
            $n = trim($names[$i]);
            $t = trim($types[$i] ?? '');
            $r = trim($refs[$i]  ?? '');
            if ($n === '' && $t === '' && $r === '') continue;
            if ($n === '') $n = 'f' . $i;
            if ($t === '') $t = 'int32';
            $entry = ['name' => $n, 'type' => $t];
            if ($r !== '') {
                $parsed = [];
                foreach (preg_split('/[\s,]+/', $r) as $tok) {
                    if ($tok === '' || !ctype_digit($tok)) continue;
                    $parsed[] = (int)$tok;
                }
                if (!empty($parsed)) $entry['refs'] = array_values(array_unique($parsed));
            }
            $fields[] = $entry;
        }
        if (empty($fields)) {
            // Truncate → delete the per-list file.
            $ok = save_list_entry($structDir, $versionLabel, $listIdx, null);
            $saveMsg = $ok
                ? "Schema for list $listIdx truncated (file removed)."
                : "ERROR removing list_{$listIdx}.json";
        } else {
            $validate = function ($wanted) use ($fields) {
                if ($wanted === '') return '';
                foreach ($fields as $fdef) if ($fdef['name'] === $wanted) return $wanted;
                return '';
            };
            $validNameField = $validate($preservedNameField);
            $validIdField   = $validate($preservedIdField);
            $entry = [
                'name'   => $listName !== '' ? $listName : "LIST_$listIdx",
                'fields' => $fields,
            ];
            if ($validNameField !== '') $entry['name_field'] = $validNameField;
            if ($validIdField   !== '') $entry['id_field']   = $validIdField;
            $ok = save_list_entry($structDir, $versionLabel, $listIdx, $entry);
            $saveMsg = $ok
                ? "Saved list $listIdx — " . count($fields) . " field(s) → {$versionLabel}/list_{$listIdx}.json"
                : "ERROR writing {$versionLabel}/list_{$listIdx}.json";
        }
    }
    $qs = http_build_query([
        'file' => $_POST['file_qs'] ?? '',
        'list' => $listIdx,
        'off'  => $_POST['off_qs'] ?? 0,
        'msg'  => $saveMsg,
    ]);
    header('Location: ?' . $qs);
    exit;
}

// ---------------------------------------------------------------------------
// GET parameters
// ---------------------------------------------------------------------------
$fileName        = basename($_GET['file'] ?? '');
$selectedListIdx = isset($_GET['list']) ? (int)$_GET['list'] : -1;
$rowOffset       = max(0, (int)($_GET['off'] ?? 0));
$flashMsg        = (string)($_GET['msg'] ?? '');

$dataFiles = list_data_files($dataDir);
if ($fileName === '' && !empty($dataFiles)) $fileName = $dataFiles[0];

$reader    = null;
$struct    = null;
$readError = '';
if ($fileName !== '' && $dataDir && is_file($dataDir . '/' . $fileName)) {
    try {
        $reader = new ElementsReader($dataDir . '/' . $fileName);
        $reader->scan();
        $struct = load_structure($structDir, $reader->getVersionLabel());
    } catch (Throwable $e) {
        $readError = $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Selected list + decoded record
// ---------------------------------------------------------------------------
$decoded          = null;
$selectedListMeta = null;
$selectedListDef  = null;
if ($reader && $selectedListIdx >= 0) {
    $lists = $reader->getLists();
    if (isset($lists[$selectedListIdx])) {
        $selectedListMeta = $lists[$selectedListIdx];
        $selectedListDef  = $struct['lists'][(string)$selectedListIdx] ?? null;
        if ($selectedListDef && !empty($selectedListDef['fields'])) {
            try {
                $decoded = $reader->decodeList(
                    $selectedListIdx,
                    $selectedListDef['fields'],
                    1,
                    $rowOffset
                );
            } catch (Throwable $e) {
                $readError = $e->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Sidebar pagination + ID/Name preview
//
// Resolve a "preview field" by:
//   1. Honoring an explicit *_field key in the schema JSON, if it points to a
//      field that still exists.
//   2. Auto-detecting a field literally named the slot's name (case-insensitive)
//      — e.g. a field called "id" / "ID" populates the ID column.
// ---------------------------------------------------------------------------
function resolve_preview_field($listDef, $configKey, $autoName) {
    if (empty($listDef['fields'])) return '';
    if (!empty($listDef[$configKey])) {
        foreach ($listDef['fields'] as $fd) {
            if ($fd['name'] === $listDef[$configKey]) return $listDef[$configKey];
        }
    }
    foreach ($listDef['fields'] as $fd) {
        if (strcasecmp($fd['name'], $autoName) === 0) return $fd['name'];
    }
    return '';
}

$sidebarLimit      = 200;
$sidebarPageStart  = 0;
$sidebarPageEnd    = 0;
$sidebarIds        = [];
$sidebarNames      = [];
$sidebarIdField    = '';
$sidebarNameField  = '';
if ($reader && $selectedListMeta) {
    $total = (int)$selectedListMeta['count'];
    if ($total > 0) {
        $sidebarPageStart = (int)(floor(max(0, $rowOffset) / $sidebarLimit) * $sidebarLimit);
        $sidebarPageEnd   = min($total, $sidebarPageStart + $sidebarLimit);
    }
    if ($selectedListDef) {
        $sidebarIdField   = resolve_preview_field($selectedListDef, 'id_field',   'id');
        $sidebarNameField = resolve_preview_field($selectedListDef, 'name_field', 'name');
    }
    $pageRows = $sidebarPageEnd - $sidebarPageStart;
    if ($pageRows > 0) {
        foreach ([
            ['field' => $sidebarIdField,   'out' => &$sidebarIds],
            ['field' => $sidebarNameField, 'out' => &$sidebarNames],
        ] as $job) {
            if ($job['field'] === '') continue;
            try {
                $job['out'] = $reader->decodeField(
                    $selectedListIdx,
                    $selectedListDef['fields'],
                    $job['field'],
                    $sidebarPageStart,
                    $pageRows
                );
            } catch (Throwable $e) {
                $job['out'] = [];
            }
        }
        unset($job);
    }
}

// ---------------------------------------------------------------------------
// Full-list search (overrides sidebar pagination when active)
//
// When ?q=… is set, we decode the *entire* selected list's ID + Name fields,
// match each row's index/ID/Name against the needle, and render a flat list
// of hits in the sidebar (capped to $searchLimit so very common substrings
// don't blow up the DOM).
// ---------------------------------------------------------------------------
$searchQ      = trim((string)($_GET['q'] ?? ''));
$searchActive = false;
$searchLimit  = 500;
$searchTotal  = 0;
$searchHits   = [];
if ($searchQ !== '' && $reader && $selectedListMeta && (int)$selectedListMeta['count'] > 0) {
    $needle = strtolower($searchQ);
    $total  = (int)$selectedListMeta['count'];
    $allIds = [];
    $allNms = [];
    if ($selectedListDef) {
        try {
            if ($sidebarIdField !== '')   $allIds = $reader->decodeField($selectedListIdx, $selectedListDef['fields'], $sidebarIdField,   0, $total);
            if ($sidebarNameField !== '') $allNms = $reader->decodeField($selectedListIdx, $selectedListDef['fields'], $sidebarNameField, 0, $total);
        } catch (Throwable $e) {
            // fall through with empty arrays
        }
    }
    for ($i = 0; $i < $total; $i++) {
        $rawId   = $allIds[$i] ?? null;
        $rawName = $allNms[$i] ?? null;
        $idStr   = $rawId   === null ? '' : format_value($rawId);
        $nameStr = $rawName === null ? '' : format_value($rawName);
        if (
            strpos((string)$i, $needle) !== false
            || strpos('#' . $i, $needle) !== false
            || ($idStr   !== '' && strpos(strtolower($idStr),   $needle) !== false)
            || ($nameStr !== '' && strpos(strtolower($nameStr), $needle) !== false)
        ) {
            $searchTotal++;
            if (count($searchHits) < $searchLimit) {
                $searchHits[] = ['idx' => $i, 'id' => $idStr, 'name' => $nameStr];
            }
        }
    }
    $searchActive = true;
} else {
    $searchActive = false;
}

// ---------------------------------------------------------------------------
// Hex bytes for the selected record (sized to record size, capped 512)
// ---------------------------------------------------------------------------
$hexBytesArr = [];
$hexAddrBase = 0;
if ($reader && $selectedListMeta && (int)$selectedListMeta['count'] > 0) {
    $sz = (int)$selectedListMeta['sizeof'];
    if ($sz > 0) {
        $hexLen = min(max(48, $sz), 512);
        try {
            $raw = $reader->hexDumpList($selectedListIdx, $hexLen, $rowOffset);
            for ($i = 0; $i < strlen($raw); $i++) $hexBytesArr[] = ord($raw[$i]);
            $hexAddrBase = (int)$selectedListMeta['data_offset'] + (int)$rowOffset * $sz;
        } catch (Throwable $e) {
            // ignore
        }
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function page_url($params) {
    $base = [
        'file' => $_GET['file'] ?? '',
        'list' => $_GET['list'] ?? '',
        'off'  => $_GET['off']  ?? 0,
        'q'    => $_GET['q']    ?? '',
    ];
    foreach ($params as $k => $v) $base[$k] = $v;
    return '?' . http_build_query(array_filter($base, function ($v) {
        return $v !== '' && $v !== null;
    }));
}

function format_value($v) {
    if (is_float($v)) return rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    return (string)$v;
}

/** Quote a cell value for CSV/TSV export. */
function export_cell(string $v, string $sep): string {
    if ($sep === "\t") {
        // TSV: strip control characters that would break the format
        return str_replace(["\t", "\r", "\n"], [' ', ' ', ' '], $v);
    }
    // CSV (RFC 4180): wrap in double-quotes if value contains comma, quote, or newline
    if (strpos($v, ',') !== false || strpos($v, '"') !== false ||
        strpos($v, "\n") !== false || strpos($v, "\r") !== false) {
        return '"' . str_replace('"', '""', $v) . '"';
    }
    return $v;
}

// Pretty type-color class buckets matching template's typeColor() palette.
function type_color_class($t) {
    if (preg_match('/^int/', $t))                   return 'bg-blue-950 text-blue-300 border-blue-800';
    if ($t === 'float' || $t === 'double')          return 'bg-purple-950 text-purple-300 border-purple-800';
    if (strncmp($t, 'wstring:', 8) === 0)           return 'bg-emerald-950 text-emerald-300 border-emerald-800';
    if (strncmp($t, 'byte:', 5) === 0)              return 'bg-orange-950 text-orange-300 border-orange-800';
    return 'bg-slate-800 text-slate-400 border-slate-700';
}

// ---------------------------------------------------------------------------
// Pre-compute info-bar values & schema rows for the template below
// ---------------------------------------------------------------------------
$listLabel = '';
$listSizeof = 0;
$listCount  = 0;
$listBody   = 0;
$listOffset = 0;
if ($selectedListMeta) {
    $listLabel  = ($selectedListDef['name'] ?? '') !== ''
                    ? $selectedListDef['name']
                    : 'LIST_' . $selectedListIdx;
    $listSizeof = (int)$selectedListMeta['sizeof'];
    $listCount  = (int)$selectedListMeta['count'];
    $listBody   = (int)$selectedListMeta['body_bytes'];
    $listOffset = (int)$selectedListMeta['data_offset'];
}

$schemaFields = $selectedListDef['fields'] ?? [];
$schemaSize   = 0;
foreach ($schemaFields as $fd) {
    $w = ElementsReader::typeWidth($fd['type']);
    $schemaSize += $w === null ? 0 : $w;
}

$availableTypes = ElementsReader::availableTypes();
$preservedNameField = $selectedListDef['name_field'] ?? '';
$preservedIdField   = $selectedListDef['id_field']   ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>ElementsViewer v2</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
<style type="text/tailwindcss">
  @import "tailwindcss";
  body { font-family:'Inter',sans-serif; background:#0b0f18; color:#cbd5e1; overflow:hidden; }
  .mono { font-family:'JetBrains Mono',monospace !important; }
  ::-webkit-scrollbar{width:5px;height:5px}
  ::-webkit-scrollbar-track{background:#0d1117}
  ::-webkit-scrollbar-thumb{background:#1e2d3d;border-radius:4px}
  ::-webkit-scrollbar-thumb:hover{background:#2d3f52}
  select option{background:#0f172a;color:#cbd5e1}
  .dropdown-open .dd-menu{display:block}
  .row-active{background:rgba(8,145,178,0.08)!important;border-left-color:#06b6d4!important}
  .hidden-row{display:none !important}
  /* Hide native number-input spinner arrows on the page-jump field */
  #page-jump::-webkit-outer-spin-button,
  #page-jump::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
  #page-jump{-moz-appearance:textfield;appearance:textfield}
</style>
</head>
<body class="flex flex-col h-screen overflow-hidden">

<!-- ░░ TOASTS ░░ -->
<div id="toast-container" class="fixed top-3 right-3 z-[200] flex flex-col gap-2 pointer-events-none"></div>

<!-- ░░ COMPARE LIST SIZES MODAL ░░ -->
<div id="diff-modal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-[150] items-center justify-center p-4"
     onclick="if(event.target===this)closeDiffModal()">
  <div class="bg-[#0d1117] border border-slate-700 rounded-md shadow-2xl w-full max-w-5xl flex flex-col max-h-[90vh]">
    <!-- Header -->
    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-2 shrink-0">
      <div class="flex items-center gap-2 min-w-0">
        <svg class="w-4 h-4 text-orange-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
        <span class="mono text-[12px] font-semibold text-slate-100 truncate">Compare list sizes</span>
        <span id="diff-meta" class="mono text-[10px] text-slate-500 truncate"></span>
      </div>
      <button type="button" onclick="closeDiffModal()"
        class="w-6 h-6 flex items-center justify-center rounded text-slate-500 hover:text-slate-200 hover:bg-slate-800 transition-colors">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <!-- Controls -->
    <div class="px-4 py-2.5 border-b border-slate-800 flex items-center gap-2 flex-wrap shrink-0 bg-[#090c12]">
      <span class="text-[11px] mono text-slate-400">A:</span>
      <span id="diff-file-a" class="text-[11px] mono text-cyan-300"><?= h($fileName) ?></span>
      <span class="text-slate-600 mx-1">vs</span>
      <span class="text-[11px] mono text-slate-400">B:</span>
      <select id="diff-file-b"
        class="bg-slate-900 border border-slate-700 text-slate-200 rounded px-2 py-1 text-[11px] mono focus:border-orange-700 outline-none">
        <option value="">— pick file —</option>
        <?php foreach ($dataFiles as $f): if ($f === $fileName) continue; ?>
          <option value="<?= h($f) ?>"><?= h($f) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" onclick="runDiff()"
        class="px-3 py-1 rounded border text-[11px] mono uppercase tracking-widest bg-orange-950/30 hover:bg-orange-950/60 border-orange-800/40 hover:border-orange-700/60 text-orange-300 transition-colors">
        Run
      </button>
      <span class="ml-auto flex items-center gap-3">
        <label class="flex items-center gap-1.5 text-[11px] mono text-slate-300 cursor-pointer">
          <input type="checkbox" id="diff-only-size" onchange="renderDiffTable()" class="accent-red-500"> only sizeof Δ
        </label>
        <label class="flex items-center gap-1.5 text-[11px] mono text-slate-300 cursor-pointer">
          <input type="checkbox" id="diff-only-count" onchange="renderDiffTable()" class="accent-yellow-500"> only count Δ
        </label>
        <input type="text" id="diff-search" oninput="renderDiffTable()" placeholder="filter by # or name…"
          class="bg-slate-900 border border-slate-700 text-slate-200 placeholder-slate-600 rounded px-2 py-1 text-[11px] mono focus:border-orange-700 outline-none w-44"/>
      </span>
    </div>
    <!-- Body / table -->
    <div class="flex-1 overflow-auto">
      <div id="diff-empty" class="p-8 text-center">
        <p class="text-[12px] mono text-slate-500">Pick a second file from the dropdown and click <span class="text-orange-300">Run</span>.</p>
      </div>
      <table id="diff-table" class="hidden w-full text-[11px] mono">
        <thead class="sticky top-0 bg-[#0d1117] border-b border-slate-800 text-slate-400 text-[10px] uppercase tracking-widest">
          <tr>
            <th class="px-3 py-2 text-right">#</th>
            <th class="px-3 py-2 text-left">Name (A)</th>
            <th class="px-3 py-2 text-right">sizeof A</th>
            <th class="px-3 py-2 text-right">sizeof B</th>
            <th class="px-3 py-2 text-right">Δ size</th>
            <th class="px-3 py-2 text-right">count A</th>
            <th class="px-3 py-2 text-right">count B</th>
            <th class="px-3 py-2 text-right">Δ count</th>
            <th class="px-3 py-2 text-left">Status</th>
          </tr>
        </thead>
        <tbody id="diff-tbody" class="divide-y divide-slate-800/60"></tbody>
      </table>
    </div>
    <!-- Footer -->
    <div class="px-4 py-2.5 border-t border-slate-800 flex items-center gap-3 text-[10px] mono shrink-0 bg-[#090c12]">
      <span id="diff-totals" class="text-slate-500"></span>
      <span class="ml-auto text-slate-600">
        <span class="inline-block w-2 h-2 rounded-sm bg-red-500/70 align-middle mr-1"></span>sizeof changed (struct layout shift)
        <span class="inline-block w-2 h-2 rounded-sm bg-yellow-500/70 align-middle ml-3 mr-1"></span>count changed
        <span class="inline-block w-2 h-2 rounded-sm bg-cyan-500/70 align-middle ml-3 mr-1"></span>only in A
        <span class="inline-block w-2 h-2 rounded-sm bg-purple-500/70 align-middle ml-3 mr-1"></span>only in B
      </span>
    </div>
  </div>
</div>

<!-- ░░ IMPORT .CFG MODAL ░░ -->
<div id="cfg-modal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-[150] items-center justify-center p-4"
     onclick="if(event.target===this)closeCfgModal()">
  <div class="bg-[#0d1117] border border-slate-700 rounded-md shadow-2xl w-full max-w-xl flex flex-col max-h-[85vh]">
    <!-- Header -->
    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
        <span class="mono text-[12px] font-semibold text-slate-100">Import schema from .cfg</span>
      </div>
      <button type="button" onclick="closeCfgModal()"
        class="w-6 h-6 flex items-center justify-center rounded text-slate-500 hover:text-slate-200 hover:bg-slate-800 transition-colors">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <!-- Body -->
    <div class="px-4 py-3 space-y-2 overflow-y-auto">
      <p class="text-[11px] mono text-slate-400 leading-relaxed">
        Paste 3 non-blank lines (semicolon-separated):
      </p>
      <ol class="text-[11px] mono text-slate-500 list-decimal list-inside space-y-0.5 pl-1">
        <li><span class="text-cyan-300">List name</span></li>
        <li><span class="text-cyan-300">Field names</span> — <span class="text-slate-600">ID;Name;…</span></li>
        <li><span class="text-cyan-300">Field types</span> — <span class="text-slate-600">int32;wstring:64;…</span></li>
      </ol>
      <textarea id="cfg-textarea" rows="9"
        placeholder="GIFT_PACK_ITEM_ESSENCE&#10;ID;Name;Model_Path_ID;Icon_Path_ID;…&#10;int32;wstring:64;int32;int32;…"
        spellcheck="false"
        class="w-full bg-slate-900/80 border border-slate-700/60 focus:border-cyan-700 rounded px-2 py-1.5 text-[11px] mono text-slate-200 placeholder-slate-700 outline-none transition-colors resize-y"></textarea>
      <p class="text-[10px] mono text-slate-600 leading-relaxed">
        Trailing semicolons are ignored. Importing replaces all current rows in the editor — you must still click
        <span class="text-emerald-300">Save Schema</span> afterwards to persist to
        <span id="cfg-target" class="text-cyan-300"></span>.
      </p>
    </div>
    <!-- Footer -->
    <div class="px-4 py-3 border-t border-slate-800 flex gap-2 justify-end shrink-0">
      <button type="button" onclick="closeCfgModal()"
        class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-slate-800/60 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors">
        Cancel
      </button>
      <button type="button" onclick="importCfgFromText()"
        class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-cyan-950/40 hover:bg-cyan-950/70 border-cyan-800/50 hover:border-cyan-700/70 text-cyan-300 transition-colors">
        Import
      </button>
    </div>
  </div>
</div>

<!-- ░░ IMPORT C++ STRUCT MODAL ░░ -->
<div id="cpp-import-modal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-[150] items-center justify-center p-4"
     onclick="if(event.target===this)closeCppImportModal()">
  <div class="bg-[#0d1117] border border-slate-700 rounded-md shadow-2xl w-full max-w-2xl flex flex-col max-h-[85vh]">
    <!-- Header -->
    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
        <span class="mono text-[12px] font-semibold text-slate-100">Import schema from C++ struct</span>
      </div>
      <button type="button" onclick="closeCppImportModal()"
        class="w-6 h-6 flex items-center justify-center rounded text-slate-500 hover:text-slate-200 hover:bg-slate-800 transition-colors">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <!-- Body -->
    <div class="px-4 py-3 space-y-2 overflow-y-auto">
      <p class="text-[11px] mono text-slate-400 leading-relaxed">
        Paste a C++ <span class="text-yellow-300">struct</span> definition. Nested anonymous structs are flattened into prefixed field names (e.g. <span class="text-slate-500">pages_1_goods_1_id_goods</span>).
      </p>
      <ul class="text-[11px] mono text-slate-500 list-disc list-inside space-y-0.5 pl-1">
        <li><span class="text-yellow-300">int</span> / <span class="text-yellow-300">unsigned int</span> → <span class="text-emerald-300">int32</span></li>
        <li><span class="text-yellow-300">__int64</span> → <span class="text-emerald-300">int64</span> · <span class="text-yellow-300">float</span> · <span class="text-yellow-300">double</span></li>
        <li><span class="text-yellow-300">namechar X[N]</span> → <span class="text-emerald-300">wstring:(N*2)</span> (each namechar = 2 bytes)</li>
        <li><span class="text-yellow-300">unsigned char X[N]</span> → <span class="text-emerald-300">byte:N</span></li>
        <li><span class="text-yellow-300">int X[N]</span> (non-namechar/byte arrays) → expanded to <span class="text-slate-600">X_1, X_2, …</span></li>
      </ul>
      <textarea id="cpp-import-textarea" rows="14"
        placeholder="struct ITEM_TRADE_CONFIG&#10;{&#10;    unsigned int    id;&#10;    namechar        name[32];&#10;    struct {&#10;        namechar    page_title[8];&#10;        struct {&#10;            unsigned int id_goods;&#10;            unsigned int goods_num;&#10;        } goods[48];&#10;    } pages[4];&#10;    unsigned int    id_dialog;&#10;};"
        spellcheck="false"
        class="w-full bg-slate-900/80 border border-slate-700/60 focus:border-yellow-700 rounded px-2 py-1.5 text-[11px] mono text-slate-200 placeholder-slate-700 outline-none transition-colors resize-y"></textarea>
      <p class="text-[10px] mono text-slate-600 leading-relaxed">
        Comments (<span class="text-slate-500">// …</span> and <span class="text-slate-500">/* … */</span>) are stripped.
        Importing replaces all current rows — click <span class="text-emerald-300">Save Schema</span> afterwards to persist to
        <span id="cpp-import-target" class="text-yellow-300"></span>.
      </p>
    </div>
    <!-- Footer -->
    <div class="px-4 py-3 border-t border-slate-800 flex gap-2 justify-end shrink-0">
      <button type="button" onclick="closeCppImportModal()"
        class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-slate-800/60 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors">
        Cancel
      </button>
      <button type="button" onclick="importCppFromText()"
        class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-yellow-950/40 hover:bg-yellow-950/70 border-yellow-800/50 hover:border-yellow-700/70 text-yellow-300 transition-colors">
        Parse & Import
      </button>
    </div>
  </div>
</div>

<!-- ░░ EXPORT C++ STRUCT MODAL ░░ -->
<div id="cpp-modal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-[150] items-center justify-center p-4"
     onclick="if(event.target===this)closeCppModal()">
  <div class="bg-[#0d1117] border border-slate-700 rounded-md shadow-2xl w-full max-w-3xl flex flex-col max-h-[85vh]">
    <!-- Header -->
    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-2 shrink-0">
      <div class="flex items-center gap-2 min-w-0">
        <svg class="w-4 h-4 text-purple-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12v1a3 3 0 01-3 3H7a3 3 0 01-3-3v-1m4-4l4 4m0 0l4-4m-4 4V4"/></svg>
        <span class="mono text-[12px] font-semibold text-slate-100 truncate">Export C++ struct</span>
        <span id="cpp-meta" class="mono text-[10px] text-slate-500 truncate"></span>
      </div>
      <div class="flex items-center gap-1 shrink-0">
        <button type="button" onclick="copyCppStruct()"
          class="px-2.5 py-1 rounded border text-[10px] mono uppercase tracking-widest bg-slate-800/60 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors flex items-center gap-1">
          <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
          Copy
        </button>
        <button type="button" onclick="closeCppModal()"
          class="w-6 h-6 flex items-center justify-center rounded text-slate-500 hover:text-slate-200 hover:bg-slate-800 transition-colors">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </div>
    <!-- Body -->
    <div class="overflow-auto px-4 py-3 bg-[#090c12]">
      <pre id="cpp-output" class="mono text-[11px] text-slate-200 leading-relaxed whitespace-pre"></pre>
    </div>
    <!-- Footer -->
    <div class="px-4 py-3 border-t border-slate-800 flex justify-between items-center gap-2 shrink-0">
      <p class="text-[10px] mono text-slate-600">
        <span class="text-slate-500">wstring:N</span> → <span class="text-emerald-300">namechar[N/2]</span> ·
        <span class="text-slate-500">int32</span> → <span class="text-emerald-300">unsigned int</span>
      </p>
      <button type="button" onclick="closeCppModal()"
        class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-slate-800/60 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors">
        Close
      </button>
    </div>
  </div>
</div>

<!-- ░░ COMPARE RECORDS MODAL ░░ -->
<div id="cmp-modal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-[150] items-center justify-center p-4"
     onclick="if(event.target===this)closeCmpModal()">
  <div class="bg-[#0d1117] border border-slate-700 rounded-md shadow-2xl w-full max-w-6xl flex flex-col max-h-[92vh]">

    <!-- Header -->
    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-2 shrink-0">
      <div class="flex items-center gap-2 min-w-0">
        <svg class="w-4 h-4 text-purple-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 0a2 2 0 012-2h2a2 2 0 012 2v10a2 2 0 01-2 2h-2a2 2 0 01-2-2"/></svg>
        <span class="mono text-[12px] font-semibold text-slate-100">Compare Records</span>
        <span id="cmp-meta" class="mono text-[10px] text-slate-500 truncate"></span>
      </div>
      <button type="button" onclick="closeCmpModal()"
        class="w-6 h-6 flex items-center justify-center rounded text-slate-500 hover:text-slate-200 hover:bg-slate-800 transition-colors">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <!-- Controls -->
    <div class="px-4 py-2.5 border-b border-slate-800 flex items-center gap-3 flex-wrap shrink-0 bg-[#090c12]">
      <!-- Record pickers -->
      <div class="flex items-center gap-2">
        <span class="text-[11px] mono text-yellow-400 font-semibold">A</span>
        <span class="text-[11px] mono text-slate-400">Record #</span>
        <input type="number" id="cmp-row-a" min="0" value="0"
          class="bg-slate-900 border border-slate-700 focus:border-purple-600 text-slate-200 rounded px-2 py-1 text-[11px] mono outline-none w-24 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"/>
      </div>
      <span class="text-slate-600 mono text-[11px]">vs</span>
      <div class="flex items-center gap-2">
        <span class="text-[11px] mono text-cyan-400 font-semibold">B</span>
        <span class="text-[11px] mono text-slate-400">Record #</span>
        <input type="number" id="cmp-row-b" min="0" value="1"
          class="bg-slate-900 border border-slate-700 focus:border-purple-600 text-slate-200 rounded px-2 py-1 text-[11px] mono outline-none w-24 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"/>
      </div>
      <button type="button" onclick="runCmp()"
        class="flex items-center gap-1.5 px-3 py-1 rounded border text-[11px] mono uppercase tracking-widest bg-purple-950/40 hover:bg-purple-950/70 border-purple-800/50 hover:border-purple-700 text-purple-300 transition-colors">
        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 0a2 2 0 012-2h2a2 2 0 012 2v10a2 2 0 01-2 2h-2a2 2 0 01-2-2"/></svg>
        Compare
      </button>

      <!-- Filter pills -->
      <div class="ml-auto flex items-center gap-2 flex-wrap">
        <div class="flex rounded overflow-hidden border border-slate-700 text-[10px] mono">
          <button type="button" id="cmpf-all"  onclick="setCmpFilter('all')"  class="px-2.5 py-1 bg-slate-700 text-slate-200">All</button>
          <button type="button" id="cmpf-diff" onclick="setCmpFilter('diff')" class="px-2.5 py-1 text-slate-500 hover:text-slate-300 border-l border-slate-700">Changed</button>
          <button type="button" id="cmpf-same" onclick="setCmpFilter('same')" class="px-2.5 py-1 text-slate-500 hover:text-slate-300 border-l border-slate-700">Same</button>
        </div>
        <input type="text" id="cmp-search" oninput="renderCmpTable()" placeholder="filter fields…"
          class="bg-slate-900 border border-slate-700 text-slate-200 placeholder-slate-600 rounded px-2 py-1 text-[11px] mono focus:border-purple-700 outline-none w-40"/>
      </div>
    </div>

    <!-- Body -->
    <div class="flex-1 overflow-auto">
      <div id="cmp-empty" class="p-8 text-center">
        <p class="text-[12px] mono text-slate-500">Enter two row numbers and click <span class="text-purple-300">Compare</span>.</p>
      </div>
      <table id="cmp-table" class="hidden w-full text-[11px] mono">
        <thead class="sticky top-0 bg-[#0d1117] border-b border-slate-800 text-[10px] uppercase tracking-widest">
          <tr>
            <th class="px-3 py-2 text-left text-slate-400 font-medium">Field</th>
            <th class="px-2 py-2 text-left text-slate-500 font-medium w-28">Type</th>
            <th class="px-3 py-2 text-left text-yellow-500 font-medium" id="cmp-th-a">Record A</th>
            <th class="px-3 py-2 text-left text-cyan-500 font-medium"   id="cmp-th-b">Record B</th>
            <th class="px-3 py-2 text-right text-slate-500 font-medium w-28">Δ Delta</th>
          </tr>
        </thead>
        <tbody id="cmp-tbody" class="divide-y divide-slate-800/40"></tbody>
      </table>
    </div>

    <!-- Footer -->
    <div class="px-4 py-2.5 border-t border-slate-800 flex items-center gap-4 text-[10px] mono shrink-0 bg-[#090c12]">
      <span id="cmp-stats" class="text-slate-500"></span>
      <div class="flex items-center gap-3 ml-auto">
        <span class="flex items-center gap-1.5"><span class="inline-block w-2.5 h-2.5 rounded-sm bg-orange-950 border border-orange-800"></span>value changed</span>
        <span class="flex items-center gap-1.5"><span class="inline-block w-2.5 h-2.5 rounded-sm bg-slate-800/60 border border-slate-700/40"></span>same</span>
        <button type="button" onclick="closeCmpModal()"
          class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-slate-800/60 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors">
          Close
        </button>
      </div>
    </div>

  </div>
</div>

<!-- ░░ TOPBAR ░░ -->
<header class="h-11 shrink-0 bg-[#0d1117] border-b border-slate-800 flex items-center px-4 gap-3 z-50 relative">
  <!-- Logo -->
  <div class="flex items-center gap-2 mr-1">
    <div class="w-5 h-5 rounded bg-cyan-500/15 border border-cyan-500/40 flex items-center justify-center">
      <div class="w-2 h-2 rounded-sm bg-cyan-400"></div>
    </div>
    <span class="mono font-semibold text-[13px] text-slate-100 tracking-tight">ElementsViewer</span>
    <span class="mono text-[11px] text-slate-300">v2</span>
  </div>
  <div class="w-px h-4 bg-slate-800"></div>

  <!-- Tools dropdown -->
  <div class="relative" id="dd-tools">
    <button type="button" onclick="toggleDD('dd-tools')"
      class="flex items-center gap-1.5 px-2.5 py-1 rounded border text-[11px] mono bg-slate-800/50 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors">
      <svg class="w-3 h-3 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      Tools
      <svg class="w-3 h-3 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div class="dd-menu hidden absolute top-full mt-1 left-0 bg-slate-900 border border-slate-700 rounded-md shadow-2xl z-50 min-w-[13rem] py-1">
      <a href="index.php"
         class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-cyan-400 bg-cyan-950/20 hover:bg-slate-800 transition-colors">
        <div class="w-4 h-4 rounded bg-cyan-500/15 border border-cyan-500/40 flex items-center justify-center shrink-0">
          <div class="w-1.5 h-1.5 rounded-sm bg-cyan-400"></div>
        </div>
        Elements Viewer
        <span class="ml-auto text-cyan-700 text-[9px] mono uppercase tracking-widest">current</span>
      </a>
      <a href="search.php<?= $fileName ? '?file='.urlencode($fileName) : '' ?>"
         class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-purple-400 transition-colors">
        <div class="w-4 h-4 rounded bg-purple-500/15 border border-purple-500/40 flex items-center justify-center shrink-0">
          <svg class="w-2.5 h-2.5 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>
        Advanced Search
      </a>
      <div class="border-t border-slate-800 my-1"></div>
      <a href="paths.php"
         class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-emerald-400 transition-colors">
        <div class="w-4 h-4 rounded bg-emerald-500/15 border border-emerald-500/40 flex items-center justify-center shrink-0">
          <svg class="w-2.5 h-2.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
        </div>
        Path Viewer
      </a>
      <a href="gshop.php"
         class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-amber-400 transition-colors">
        <div class="w-4 h-4 rounded bg-amber-500/15 border border-amber-500/40 flex items-center justify-center shrink-0">
          <svg class="w-2.5 h-2.5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
        </div>
        GShop Viewer
      </a>
      <a href="tasks.php" class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-violet-400 transition-colors">
        <div class="w-4 h-4 rounded bg-violet-500/15 border border-violet-500/40 flex items-center justify-center shrink-0"><svg class="w-2.5 h-2.5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div>
        Task Viewer
      </a>
    </div>
  </div>
  <div class="w-px h-4 bg-slate-800"></div>

  <!-- File picker dropdown -->
  <div class="relative" id="dd-file">
    <button type="button" onclick="toggleDD('dd-file')"
      class="flex items-center gap-2 px-2.5 py-1 rounded border text-[11px] mono bg-slate-800/50 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors">
      <span class="text-slate-600">elements.data</span>
      <span class="text-cyan-400 font-semibold"><?= h($fileName !== '' ? $fileName : '(none)') ?></span>
      <svg class="w-3 h-3 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div class="dd-menu hidden absolute top-full mt-1 left-0 bg-slate-900 border border-slate-700 rounded-md shadow-2xl z-50 min-w-[14rem] py-0.5 max-h-72 overflow-y-auto">
      <?php if (empty($dataFiles)): ?>
        <div class="px-3 py-1.5 text-[11px] mono text-slate-500">no files in data/</div>
      <?php else: foreach ($dataFiles as $f):
        $isSel = $f === $fileName;
        $url   = '?' . http_build_query(['file' => $f]);
      ?>
        <a href="<?= h($url) ?>"
           class="block px-3 py-1.5 text-[11px] mono <?= $isSel ? 'text-cyan-400' : 'text-slate-300' ?> hover:bg-slate-800"><?= h($f) ?></a>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <?php if ($reader): ?>
    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-cyan-950 text-cyan-300 border-cyan-800"><?= h($reader->getVersionLabel()) ?></span>
    <div class="flex items-center gap-1.5">
      <span class="text-[11px] mono text-slate-300">Lists Count</span>
      <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-slate-900 text-slate-400 border-slate-700"><?= count($reader->getLists()) ?></span>
    </div>
    <button type="button" onclick="openDiffModal()"
      class="flex items-center gap-1.5 px-2.5 py-1 rounded border text-[11px] mono uppercase tracking-widest bg-orange-950/20 hover:bg-orange-950/50 border-orange-800/30 hover:border-orange-700/50 text-orange-300 transition-colors"
      title="Compare list sizes against another elements.data file">
      <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
      Compare
    </button>
    <a href="?action=export_cfg&amp;file=<?= h(urlencode($fileName)) ?>"
       class="flex items-center gap-1.5 px-2.5 py-1 rounded border text-[11px] mono uppercase tracking-widest bg-emerald-950/20 hover:bg-emerald-950/50 border-emerald-800/30 hover:border-emerald-700/50 text-emerald-300 transition-colors"
       title="Download a .cfg file for the elements.data editor (uses every list's saved schema; lists without a schema fall back to a single byte:N blob)">
      <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12v1a3 3 0 01-3 3H7a3 3 0 01-3-3v-1m4-4l4 4m0 0l4-4m-4 4V4"/></svg>
      Export .cfg
    </a>
    <a href="search.php?file=<?= h(urlencode($fileName)) ?><?= $selectedListIdx >= 0 ? '&list=' . $selectedListIdx : '' ?>"
       class="flex items-center gap-1.5 px-2.5 py-1 rounded border text-[11px] mono uppercase tracking-widest bg-purple-950/20 hover:bg-purple-950/50 border-purple-800/30 hover:border-purple-700/50 text-purple-300 transition-colors"
       title="Open Advanced Search — query any list by field values with operators like >, <, contains, etc.">
      <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      Search
    </a>
    <?php if ($reader->getTalkProcCount() !== null): ?>
      <div class="flex items-center gap-1.5">
        <span class="text-[11px] mono text-slate-300">Talk Proc Records</span>
        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-orange-950 text-orange-300 border-orange-800"><?= (int)$reader->getTalkProcCount() ?></span>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Struct folder label (right-aligned, read-only) -->
  <?php if ($reader):
      $structCount = count($struct['lists'] ?? []);
  ?>
    <div class="ml-auto flex items-center gap-2 px-2.5 py-1 rounded border text-[11px] mono bg-slate-800/40 border-slate-700/40 text-slate-300"
         title="Per-list schema files in this folder">
      <span class="text-slate-600">struct</span>
      <span class="text-emerald-400"><?= h($reader->getVersionLabel()) ?>/</span>
      <span class="text-slate-600"><?= (int)$structCount ?> file<?= $structCount === 1 ? '' : 's' ?></span>
    </div>
  <?php endif; ?>
</header>

<?php if ($readError): ?>
  <div class="shrink-0 bg-red-950/40 border-b border-red-800 px-4 py-1.5 text-[11px] mono text-red-300 flex items-center gap-2">
    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
    <?= h($readError) ?>
  </div>
<?php endif; ?>

<!-- ░░ BODY ░░ -->
<div class="flex flex-1 overflow-hidden">

  <!-- ░░ SIDEBAR ░░ -->
  <aside class="w-100 shrink-0 bg-[#0d1117] border-r border-slate-800 flex flex-col overflow-hidden">

    <div class="p-2 border-b border-slate-800 space-y-1.5">
      <!-- List picker dropdown -->
      <div class="relative" id="dd-listtype">
        <button type="button" onclick="toggleDD('dd-listtype')"
          class="w-full flex items-center justify-between px-2.5 py-1.5 rounded border text-[11px] mono bg-slate-800/50 hover:bg-slate-800 border-slate-700/50 text-slate-300 transition-colors">
          <span class="text-cyan-400 font-semibold truncate">
            <?php if ($selectedListMeta): ?>
              #<?= (int)$selectedListIdx ?> · <?= h($listLabel) ?>
            <?php else: ?>
              — pick a list —
            <?php endif; ?>
          </span>
          <svg class="w-3 h-3 text-slate-600 shrink-0 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div class="dd-menu hidden absolute top-full mt-0.5 left-0 right-0 bg-slate-900 border border-slate-700 rounded-md shadow-2xl z-40">
          <div class="p-1.5">
            <input id="listtype-search" oninput="filterListPicker(this.value)" placeholder="search lists…"
              class="w-full bg-slate-800 border border-slate-700 focus:border-cyan-700 rounded px-2 py-1 text-[11px] mono text-slate-300 placeholder-slate-600 outline-none"/>
          </div>
          <div id="listtype-options" class="max-h-72 overflow-y-auto pb-0.5">
            <?php if ($reader): foreach ($reader->getLists() as $L):
              $defName = $struct['lists'][(string)$L['index']]['name'] ?? '';
              $label   = $defName !== '' ? $defName : 'LIST_' . $L['index'];
              $isSel   = $L['index'] === $selectedListIdx;
              $url     = '?' . http_build_query([
                  'file' => $fileName,
                  'list' => $L['index'],
                  'off'  => 0,
              ]);
              $search  = strtolower('#' . $L['index'] . ' ' . $label);
            ?>
              <a href="<?= h($url) ?>"
                 data-search="<?= h($search) ?>"
                 class="dd-list-item block px-3 py-1.5 text-[11px] mono <?= $isSel ? 'text-cyan-400' : 'text-slate-300' ?> hover:bg-slate-800 truncate"
                 title="<?= h($label) ?> — count <?= (int)$L['count'] ?>, sizeof <?= (int)$L['sizeof'] ?>B">
                #<?= (int)$L['index'] ?> · <?= h($label) ?>
                <span class="text-slate-600"><?= (int)$L['count'] ?> × <?= (int)$L['sizeof'] ?>B</span>
              </a>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

      <!-- Search records in selected list (full-list, server-side) -->
      <div class="relative">
        <svg class="absolute left-2 top-1/2 -translate-y-1/2 w-3 h-3 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
        <input id="sidebar-search" type="text" autocomplete="off"
               value="<?= h($searchQ) ?>"
               oninput="onSearchInput(this.value)"
               onkeydown="onSearchKey(event)"
               placeholder="filter by #, ID or name…"
               class="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-700/60 rounded pl-6 pr-7 py-1.5 text-[11px] mono text-slate-300 placeholder-slate-300 outline-none transition-colors"/>
        <?php if ($searchQ !== ''): ?>
          <a href="<?= h(page_url(['q' => null])) ?>"
             title="Clear search (Esc)"
             class="absolute right-1.5 top-1/2 -translate-y-1/2 w-4 h-4 flex items-center justify-center rounded text-slate-600 hover:text-slate-300 hover:bg-slate-700/40">
            <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
          </a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($selectedListMeta && $listCount > 0): ?>
      <?php if ($searchActive): ?>
        <!-- Search info bar (full-list scan) -->
        <div class="px-3 py-1.5 border-b border-slate-800 flex items-center justify-between shrink-0 bg-cyan-950/10">
          <span class="text-[11px] mono text-slate-500 truncate">
            <span class="text-slate-300"><?= number_format($searchTotal) ?></span>
            match<?= $searchTotal === 1 ? '' : 'es' ?>
            for <span class="text-cyan-300">"<?= h($searchQ) ?>"</span>
            <?php if ($searchTotal > $searchLimit): ?>
              <span class="text-yellow-400/90">(first <?= number_format($searchLimit) ?>)</span>
            <?php endif; ?>
          </span>
          <a href="<?= h(page_url(['q' => null])) ?>"
             class="text-[10px] mono uppercase tracking-widest text-slate-500 hover:text-cyan-300 transition-colors">clear</a>
        </div>
      <?php else:
        // First / prev / input / next / last pagination
        $totalPages = max(1, (int)ceil($listCount / $sidebarLimit));
        $currentPg  = (int)floor($sidebarPageStart / $sidebarLimit) + 1;
        $lastStart  = ($totalPages - 1) * $sidebarLimit;
        $isFirst    = $sidebarPageStart === 0;
        $isLast     = $sidebarPageStart >= $lastStart;
      ?>
        <!-- Pagination bar -->
        <div class="px-2 py-1.5 border-b border-slate-800 flex items-center justify-between gap-1.5 shrink-0">
          <span class="text-[11px] mono text-slate-300 truncate">
            <span class="text-slate-400"><?= number_format($sidebarPageStart) ?>–<?= number_format(max($sidebarPageStart, $sidebarPageEnd - 1)) ?></span>
            <span class="text-slate-600">/</span>
            <span class="text-slate-300"><?= number_format($listCount) ?></span>
          </span>
          <div class="flex items-center gap-0.5 shrink-0">
            <!-- First -->
            <a href="<?= h(page_url(['off' => 0])) ?>"
               title="First page"
               class="w-5 h-5 flex items-center justify-center rounded hover:bg-slate-800 text-slate-500 hover:text-cyan-300 transition-colors <?= $isFirst ? 'opacity-20 pointer-events-none' : '' ?>">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
            </a>
            <!-- Prev -->
            <a href="<?= h(page_url(['off' => max(0, $sidebarPageStart - $sidebarLimit)])) ?>"
               title="Previous page"
               class="w-5 h-5 flex items-center justify-center rounded hover:bg-slate-800 text-slate-500 hover:text-cyan-300 transition-colors <?= $isFirst ? 'opacity-20 pointer-events-none' : '' ?>">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <!-- Page input -->
            <input id="page-jump" type="number" min="1" max="<?= (int)$totalPages ?>" value="<?= (int)$currentPg ?>"
                   onchange="jumpToPage(this.value)"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();jumpToPage(this.value);}"
                   onfocus="this.select()"
                   title="Jump to page"
                   class="w-15 text-center bg-slate-800/60 border border-slate-700/60 focus:border-cyan-700 rounded text-[11px] mono text-cyan-300 px-1 py-0.5 outline-none transition-colors"/>
            <span class="text-[10px] mono text-slate-600 px-0.5">/ <?= number_format($totalPages) ?></span>
            <!-- Next -->
            <a href="<?= h(page_url(['off' => $sidebarPageEnd])) ?>"
               title="Next page"
               class="w-5 h-5 flex items-center justify-center rounded hover:bg-slate-800 text-slate-500 hover:text-cyan-300 transition-colors <?= $isLast ? 'opacity-20 pointer-events-none' : '' ?>">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
            <!-- Last -->
            <a href="<?= h(page_url(['off' => $lastStart])) ?>"
               title="Last page"
               class="w-5 h-5 flex items-center justify-center rounded hover:bg-slate-800 text-slate-500 hover:text-cyan-300 transition-colors <?= $isLast ? 'opacity-20 pointer-events-none' : '' ?>">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg>
            </a>
          </div>
        </div>
      <?php endif; ?>

      <!-- List header -->
      <div class="grid grid-cols-[2.5rem_5rem_1fr] px-2 py-1 border-b border-slate-800 text-[10px] mono text-slate-300 uppercase tracking-widest shrink-0">
        <span>#</span><span>ID</span><span>Name</span>
      </div>

      <!-- List body -->
      <div id="sidebar-list" class="flex-1 overflow-y-auto">
        <?php
          // Build a uniform row list for either pagination or search mode.
          $sidebarRows = [];
          if ($searchActive) {
              foreach ($searchHits as $hit) $sidebarRows[] = $hit;
          } else {
              for ($r = $sidebarPageStart; $r < $sidebarPageEnd; $r++) {
                  $rawId   = $sidebarIds[$r]   ?? null;
                  $rawName = $sidebarNames[$r] ?? null;
                  $sidebarRows[] = [
                      'idx'  => $r,
                      'id'   => $rawId   === null ? '' : format_value($rawId),
                      'name' => $rawName === null ? '' : format_value($rawName),
                  ];
              }
          }
        ?>
        <?php if (empty($sidebarRows) && $searchActive): ?>
          <div class="p-3 text-[11px] mono text-slate-600">No records match "<?= h($searchQ) ?>".</div>
        <?php else: ?>
          <?php foreach ($sidebarRows as $row):
            $r       = (int)$row['idx'];
            $idStr   = $row['id'];
            $nameStr = $row['name'];
            $active  = ($r === $rowOffset);
            $rUrl    = page_url(['list' => $selectedListIdx, 'off' => $r]);
          ?>
            <a href="<?= h($rUrl) ?>"
               data-fname="<?= h(strtolower($nameStr)) ?>"
               data-fid="<?= h(strtolower($idStr)) ?>"
               data-ridx="<?= (int)$r ?>"
               class="rec-item w-full grid px-2 py-[5px] text-[11px] mono text-left hover:bg-slate-800/50 transition-colors border-l-2 <?= $active ? 'bg-cyan-950/30 border-cyan-500' : 'border-transparent' ?>"
               style="grid-template-columns:2.5rem 5rem 1fr;">
              <span class="text-slate-700"><?= (int)$r ?></span>
              <?php if ($idStr !== ''): ?>
                <span class="truncate <?= $active ? 'text-cyan-400/80' : 'text-slate-500' ?>" title="<?= h($idStr) ?>"><?= h($idStr) ?></span>
              <?php else: ?>
                <span class="truncate text-slate-700">—</span>
              <?php endif; ?>
              <?php if ($nameStr !== ''): ?>
                <span class="truncate <?= $active ? 'text-cyan-300' : 'text-slate-300' ?>"><?= h($nameStr) ?></span>
              <?php else: ?>
                <span class="truncate text-slate-600 italic">record <?= (int)$r ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="p-3 text-[11px] mono text-slate-300">
        <?php if (!$reader): ?>
          Pick a data file above to begin.
        <?php elseif ($selectedListIdx < 0): ?>
          Pick a list from the dropdown.
        <?php else: ?>
          List is empty.
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </aside>

  <!-- ░░ MAIN ░░ -->
  <div class="flex flex-col flex-1 overflow-hidden min-w-0">

    <?php if (!$reader): ?>
      <div class="flex-1 flex items-center justify-center text-slate-600 mono text-[12px]">
        Select an elements.data file from the top bar.
      </div>
    <?php elseif (!$selectedListMeta): ?>
      <div class="flex-1 flex items-center justify-center text-slate-600 mono text-[12px]">
        Pick a list from the sidebar to inspect.
      </div>
    <?php else: ?>

      <!-- Top info bar -->
      <div class="shrink-0 bg-[#0d1117] border-b border-slate-800 px-4 py-2 flex items-center gap-3 flex-wrap">
        <div class="flex items-center gap-2">
          <span class="text-[11px] mono text-slate-300">LIST_#</span>
          <span class="mono text-sm font-semibold text-slate-100"><?= (int)$selectedListIdx ?></span>
        </div>
        <div class="w-px h-4 bg-slate-800"></div>
        <div class="flex items-center gap-2">
          <span class="text-[11px] mono text-slate-300">LIST_Name</span>
          <span class="mono text-sm font-semibold text-cyan-300"><?= h($listLabel) ?></span>
        </div>
        <div class="flex items-center gap-2 ml-auto flex-wrap">
          <div class="flex items-center gap-1.5"><span class="text-[11px] mono text-slate-300">sizeof</span><span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-purple-950 text-purple-300 border-purple-800"><?= $listSizeof ?> bytes</span></div>
          <div class="flex items-center gap-1.5"><span class="text-[11px] mono text-slate-300">Records</span><span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-slate-900 text-slate-400 border-slate-700"><?= number_format($listCount) ?></span></div>
          <div class="flex items-center gap-1.5"><span class="text-[11px] mono text-slate-300">Body Size</span><span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-cyan-950 text-cyan-300 border-cyan-800"><?= number_format($listBody) ?> bytes</span></div>
          <div class="flex items-center gap-1.5"><span class="text-[11px] mono text-slate-300">List Offset</span><span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-orange-950 text-orange-300 border-orange-800">0x<?= str_pad(strtoupper(dechex($listOffset)), 8, '0', STR_PAD_LEFT) ?></span></div>
          <?php if ($selectedListDef && !empty($selectedListDef['fields'])): ?>
          <div class="relative" id="export-dropdown-wrap">
            <button type="button" onclick="toggleExportMenu(event)"
              class="inline-flex items-center gap-1 px-2 py-1 rounded text-[11px] mono border bg-emerald-950 text-emerald-300 border-emerald-700 hover:bg-emerald-900 transition-colors select-none cursor-pointer">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 4v11"/></svg>
              Export
              <svg class="w-2.5 h-2.5 ml-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div id="export-menu"
              class="hidden absolute right-0 top-full mt-1 z-50 bg-[#0d1117] border border-slate-700 rounded shadow-xl min-w-[120px] py-1">
              <?php
                $expBase = '?action=export_list&file=' . urlencode($fileName) . '&list=' . (int)$selectedListIdx . '&format=';
              ?>
              <a href="<?= $expBase ?>csv" class="flex items-center gap-2 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-emerald-300 transition-colors">
                <span class="text-emerald-500 font-bold">CSV</span> comma-separated
              </a>
              <a href="<?= $expBase ?>tsv" class="flex items-center gap-2 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-emerald-300 transition-colors">
                <span class="text-cyan-500 font-bold">TSV</span> tab-separated
              </a>
              <a href="<?= $expBase ?>json" class="flex items-center gap-2 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-emerald-300 transition-colors">
                <span class="text-purple-400 font-bold">JSON</span> object array
              </a>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($decoded && $decoded['warning']): ?>
        <div class="shrink-0 bg-yellow-950/30 border border-yellow-800/50 px-4 py-1.5 text-[11px] mono text-yellow-300 flex items-center gap-2">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
          <?= h($decoded['warning']) ?>
        </div>
      <?php endif; ?>

      <!-- Middle: fields + schema -->
      <div class="flex flex-1 overflow-hidden min-w-0">

        <!-- Fields table -->
        <div class="w-[50rem] shrink-0 border-r border-slate-800 flex flex-col overflow-hidden">
          <div class="px-3 py-2 border-b border-slate-800 bg-[#0d1117] flex items-center justify-between gap-2">
            <div>
              <span class="text-[10px] mono text-slate-300 uppercase tracking-widest">Fields</span>
              <?php if ($decoded && !empty($decoded['rows'])): ?>
                <span class="text-[10px] mono text-slate-300 ml-1">— Record #<?= (int)$rowOffset ?></span>
              <?php endif; ?>
            </div>
            <?php if ($reader && $selectedListIdx >= 0 && $selectedListDef && !empty($selectedListDef['fields'])): ?>
              <button type="button"
                onclick="openCmpModal(<?= (int)$rowOffset ?>)"
                class="flex items-center gap-1.5 px-2 py-1 rounded border text-[10px] mono bg-slate-800/50 hover:bg-slate-800 border-slate-700/50 hover:border-purple-700/70 text-slate-500 hover:text-purple-300 transition-colors"
                title="Compare this record with any other record in <?= h($listLabel) ?>">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 0a2 2 0 012-2h2a2 2 0 012 2v10a2 2 0 01-2 2h-2a2 2 0 01-2-2"/></svg>
                Compare
              </button>
            <?php endif; ?>
          </div>
          <div class="flex-1 overflow-y-auto">
            <?php if ($decoded && !empty($decoded['rows'])):
              $row = $decoded['rows'][0];
            ?>
              <table class="w-full text-[11px] mono">
                <thead class="sticky top-0 bg-[#0d1117]">
                  <tr class="border-b border-slate-800">
                    <th class="text-left px-3 py-1.5 font-normal text-slate-300">Label</th>
                    <th class="text-left px-3 py-1.5 font-normal text-slate-300">Value</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($decoded['fields'] as $f):
                    $val = $row[$f['name']] ?? '';
                    $disp = format_value($val);
                    $tc   = type_color_class($f['type']);
                    $offHex = '0x' . str_pad(strtoupper(dechex((int)$f['offset'])), 4, '0', STR_PAD_LEFT);
                  ?>
                    <tr class="border-b border-slate-800/50 hover:bg-slate-800/25 transition-colors">
                      <td class="px-3 py-2 w-50">
                        <div class="text-slate-300 mb-1"><?= h($f['name']) ?></div>
                        <div class="flex gap-1 flex-wrap">
                          <span class="inline-flex px-1 py-0.5 rounded text-[9px] border <?= $tc ?>"><?= h($f['type']) ?></span>
                          <span class="inline-flex px-1 py-0.5 rounded text-[9px] bg-slate-800 text-slate-500 border border-slate-700"><?= h($offHex) ?></span>
                        </div>
                      </td>
                      <td class="px-3 py-2 text-slate-200 max-w-0"><span class="block truncate" title="<?= h($disp) ?>"><?= h($disp) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php elseif (!$selectedListDef): ?>
              <div class="p-3 text-[11px] mono text-slate-300">No schema defined yet — use the editor to add fields.</div>
            <?php elseif ($listCount === 0): ?>
              <div class="p-3 text-[11px] mono text-slate-300">List is empty.</div>
            <?php else: ?>
              <div class="p-3 text-[11px] mono text-slate-300">No record loaded.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Schema Editor -->
        <div class="flex-1 flex flex-col overflow-hidden min-w-0">
          <form method="post" id="schemaForm" onsubmit="return prepareSchemaSubmit(this)" class="flex-1 flex flex-col overflow-hidden min-w-0">
            <input type="hidden" name="action"   value="save_list">
            <input type="hidden" name="version"  value="<?= h($reader->getVersionLabel()) ?>">
            <input type="hidden" name="list"     value="<?= (int)$selectedListIdx ?>">
            <input type="hidden" name="file_qs"  value="<?= h($fileName) ?>">
            <input type="hidden" name="off_qs"   value="<?= (int)$rowOffset ?>">
            <input type="hidden" name="name_field" value="<?= h($preservedNameField) ?>">
            <input type="hidden" name="id_field"   value="<?= h($preservedIdField) ?>">
            <!-- Bundled row payload — sidesteps PHP's max_input_vars cap (default 1000)
                 which otherwise truncates names[]/types[]/refs[] past ~331 rows. -->
            <input type="hidden" name="fields_json" id="fields-json" value="">

            <!-- Header -->
            <div class="shrink-0 bg-[#0d1117] border-b border-slate-800 px-4 py-2 flex items-center gap-2 flex-wrap">
              <span class="text-[10px] mono text-slate-300 uppercase tracking-widest mr-1">Schema Editor</span>
              <span id="badge-fields" class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-slate-900 text-slate-400 border-slate-700"><?= count($schemaFields) ?> fields</span>
              <span id="badge-size"   class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-cyan-950 text-cyan-300 border-cyan-800">size = <?= $schemaSize ?></span>
              <span id="badge-sizeof" class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-purple-950 text-purple-300 border-purple-800">sizeof = <?= $listSizeof ?></span>
            </div>

            <!-- Name input -->
            <div class="shrink-0 border-b border-slate-800 px-4 py-2 flex items-center gap-3">
              <span class="text-[11px] mono text-slate-300 shrink-0">List name</span>
              <input type="text" name="name" value="<?= h($selectedListDef['name'] ?? ('LIST_' . $selectedListIdx)) ?>"
                     class="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-700/70 focus:bg-slate-800 rounded px-2.5 py-1 text-[11px] mono text-cyan-300 outline-none transition-colors w-64"/>
            </div>

            <!-- Schema table -->
            <div class="flex-1 overflow-y-auto">
              <table class="w-full text-[11px] mono border-collapse" id="schemaTbl">
                <thead class="sticky top-0 bg-[#0d1117] z-10">
                  <tr class="border-b border-slate-800 text-slate-300 text-[10px] uppercase tracking-widest">
                    <th class="text-left px-3 py-2 font-normal w-8">#</th>
                    <th class="text-left px-3 py-2 font-normal w-24">Offset</th>
                    <th class="text-left px-3 py-2 font-normal">Name</th>
                    <th class="text-left px-3 py-2 font-normal w-40">Type</th>
                    <th class="text-left px-3 py-2 font-normal w-32">Reference</th>
                    <th class="w-8 px-2 py-2"></th>
                  </tr>
                </thead>
                <tbody id="schema-tbody">
                  <?php
                    $off = 0;
                    foreach ($schemaFields as $i => $f):
                      $w = ElementsReader::typeWidth($f['type']);
                      $offHex = '0x' . str_pad(strtoupper(dechex($off)), 4, '0', STR_PAD_LEFT);
                      $off += $w === null ? 0 : $w;
                      $refStr = !empty($f['refs']) && is_array($f['refs']) ? implode(',', $f['refs']) : '';
                      $tc = type_color_class($f['type']);
                      // strip non-text classes
                      $textClass = '';
                      foreach (explode(' ', $tc) as $c) if (strpos($c, 'text-') === 0) $textClass .= ' ' . $c;
                  ?>
                    <tr class="border-b border-slate-800/40 hover:bg-slate-800/20 group transition-colors">
                      <td class="px-3 py-1.5 text-slate-700"><?= $i ?></td>
                      <td class="px-3 py-1.5">
                        <span class="text-slate-500"><?= h($offHex) ?></span>
                      </td>
                      <td class="px-3 py-1.5">
                        <input type="text" name="names[]" value="<?= h($f['name']) ?>"
                          class="w-full bg-transparent border border-transparent hover:border-slate-700 focus:border-cyan-700 focus:bg-slate-800/60 rounded px-1.5 py-0.5 text-slate-200 outline-none transition-colors text-[11px] mono"/>
                      </td>
                      <td class="px-3 py-1.5">
                        <select name="types[]"
                          class="bg-transparent border border-transparent hover:border-slate-700 focus:border-cyan-700 focus:bg-slate-900 rounded px-1.5 py-0.5 outline-none cursor-pointer transition-colors text-[11px] mono <?= trim($textClass) ?>">
                          <?php foreach ($availableTypes as $t): ?>
                            <option value="<?= h($t) ?>" <?= $t === $f['type'] ? 'selected' : '' ?>><?= h($t) ?></option>
                          <?php endforeach; ?>
                          <?php if (!in_array($f['type'], $availableTypes, true)): ?>
                            <option value="<?= h($f['type']) ?>" selected><?= h($f['type']) ?></option>
                          <?php endif; ?>
                        </select>
                      </td>
                      <td class="px-3 py-1.5">
                        <input type="text" name="refs[]" value="<?= h($refStr) ?>" placeholder="—"
                          class="w-full bg-transparent border border-transparent hover:border-slate-700 focus:border-cyan-700 focus:bg-slate-800/60 rounded px-1.5 py-0.5 text-slate-500 placeholder-slate-700 outline-none transition-colors text-[11px] mono"/>
                      </td>
                      <td class="px-2 py-1.5">
                        <button type="button" onclick="removeSchemaRow(this)"
                          class="w-5 h-5 flex items-center justify-center rounded hover:bg-red-900/40 text-slate-700 hover:text-red-400 transition-colors opacity-0 group-hover:opacity-100">
                          <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <!-- Action buttons -->
            <div class="shrink-0 border-t border-slate-800 px-4 py-2.5 flex gap-2 items-center bg-[#0d1117]">
              <button type="button" onclick="addSchemaRow()" class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-slate-800/60 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors">
                Add Field
              </button>
              <button type="button" onclick="openCfgModal()"
                class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-cyan-950/20 hover:bg-cyan-950/50 border-cyan-800/30 hover:border-cyan-700/50 text-cyan-300 transition-colors flex items-center gap-1.5">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Import .cfg
              </button>
              <button type="button" onclick="openCppImportModal()"
                class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-yellow-950/20 hover:bg-yellow-950/50 border-yellow-800/30 hover:border-yellow-700/50 text-yellow-300 transition-colors flex items-center gap-1.5">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Import C++
              </button>
              <button type="button" onclick="openCppModal()"
                class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-purple-950/20 hover:bg-purple-950/50 border-purple-800/30 hover:border-purple-700/50 text-purple-300 transition-colors flex items-center gap-1.5">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12v1a3 3 0 01-3 3H7a3 3 0 01-3-3v-1m4-4l4 4m0 0l4-4m-4 4V4"/></svg>
                Export C++
              </button>
              <button type="submit" class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-emerald-950/30 hover:bg-emerald-950/60 border-emerald-800/40 hover:border-emerald-700/60 text-emerald-300 transition-colors">
                Save Schema
              </button>
              <button type="button" onclick="truncateSchema()"
                class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-red-950/20 hover:bg-red-950/40 border-red-900/30 hover:border-red-800/50 text-red-400 transition-colors ml-auto">
                Truncate Schema
              </button>
            </div>
          </form>

          <!-- Hex viewer -->
          <div class="shrink-0 border-t border-slate-800 bg-[#090c12]" style="max-height:240px">
            <div class="px-4 py-2 border-b border-slate-800 flex items-center gap-2">
              <span class="text-[10px] mono text-slate-300 uppercase tracking-widest">Hex</span>
              <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-orange-950 text-orange-300 border-orange-800">0x<?= str_pad(strtoupper(dechex($hexAddrBase)), 8, '0', STR_PAD_LEFT) ?></span>
              <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-slate-900 text-slate-400 border-slate-700 ml-1"><?= count($hexBytesArr) ?> bytes</span>
              <span class="ml-auto text-[10px] mono text-slate-300">Record #<?= (int)$rowOffset ?></span>
            </div>
            <div class="px-4 py-3 overflow-auto" style="max-height:190px">
              <div id="hex-viewer" class="mono text-[11px] leading-5 select-all min-w-max"></div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
// ── Hex bytes (server-rendered) ──────────────────────────────────────────────
const HEX_BYTES = <?= json_encode($hexBytesArr) ?>;
const HEX_ADDR_BASE = <?= (int)$hexAddrBase ?>;
const FLASH_MSG = <?= json_encode($flashMsg) ?>;
const SIDEBAR_PAGE_SIZE  = <?= (int)$sidebarLimit ?>;
const SIDEBAR_TOTAL_PAGES = <?= isset($totalPages) ? (int)$totalPages : 1 ?>;

// ── Helpers ──────────────────────────────────────────────────────────────────
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── Toasts ───────────────────────────────────────────────────────────────────
const TOAST_CFG={
  success:{bar:'bg-emerald-500',icon:'bg-emerald-950 border-emerald-700',ic:'text-emerald-400',title:'Success',tc:'text-emerald-300',path:'M5 13l4 4L19 7'},
  error:  {bar:'bg-red-500',   icon:'bg-red-950 border-red-700',       ic:'text-red-400',   title:'Error',   tc:'text-red-300',   path:'M6 18L18 6M6 6l12 12'},
  warning:{bar:'bg-yellow-500',icon:'bg-yellow-950 border-yellow-700', ic:'text-yellow-400',title:'Warning', tc:'text-yellow-300',path:'M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z'},
  info:   {bar:'bg-cyan-500',  icon:'bg-cyan-950 border-cyan-700',     ic:'text-cyan-400',  title:'Info',    tc:'text-cyan-300',  path:'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'},
};
function toast(msg, type){
  type = type || 'success';
  const c=TOAST_CFG[type]||TOAST_CFG.success;
  const el=document.createElement('div');
  el.className='pointer-events-auto w-72 bg-[#0d1117] border border-slate-700/80 rounded-md shadow-2xl overflow-hidden cursor-pointer hover:border-slate-600 transition-colors';
  el.style.cssText='opacity:0;transform:translateX(1.5rem);transition:opacity 180ms ease,transform 180ms ease';
  el.innerHTML='<div class="h-0.5 '+c.bar+'"></div>'+
'<div class="flex items-start gap-3 px-3 py-3">'+
'<div class="w-7 h-7 rounded border '+c.icon+' flex items-center justify-center shrink-0 mt-0.5">'+
'<svg class="w-3.5 h-3.5 '+c.ic+'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">'+
'<path stroke-linecap="round" stroke-linejoin="round" d="'+c.path+'"/></svg></div>'+
'<div class="flex-1 min-w-0">'+
'<div class="text-[11px] mono font-semibold uppercase tracking-widest mb-0.5 '+c.tc+'">'+c.title+'</div>'+
'<div class="text-[12px] text-slate-300 leading-snug">'+esc(msg)+'</div>'+
'</div>'+
'<svg class="w-3.5 h-3.5 text-slate-600 hover:text-slate-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'+
'<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></div>';
  el.onclick=function(){dismissToast(el);};
  document.getElementById('toast-container').appendChild(el);
  requestAnimationFrame(function(){requestAnimationFrame(function(){el.style.opacity='1';el.style.transform='translateX(0)';});});
  setTimeout(function(){dismissToast(el);},3800);
}
function dismissToast(el){
  el.style.opacity='0';el.style.transform='translateX(1.5rem)';
  setTimeout(function(){el.remove();},200);
}

// ── Dropdowns ────────────────────────────────────────────────────────────────
function toggleDD(id){
  const el=document.getElementById(id);
  if(!el) return;
  const menu=el.querySelector('.dd-menu');
  const open=!menu.classList.contains('hidden');
  closeAllDD();
  if(!open){
    menu.classList.remove('hidden');
    const search=el.querySelector('input[type=text],input:not([type])');
    if(search && id==='dd-listtype') setTimeout(function(){search.focus();},10);
  }
}
function closeAllDD(){
  document.querySelectorAll('.dd-menu').forEach(function(m){m.classList.add('hidden');});
}
document.addEventListener('click',function(e){
  if(!e.target.closest('[id^="dd-"]')) closeAllDD();
});

// ── Sidebar: full-list search via ?q= (server-side, debounced) ────────────────
let _searchTimer=null;
function _navigateSearch(v){
  const u=new URL(window.location.href);
  const trimmed=(v||'').trim();
  if(trimmed===''){ u.searchParams.delete('q'); }
  else { u.searchParams.set('q', trimmed); }
  // strip transient flash
  u.searchParams.delete('msg');
  if(u.toString()!==window.location.href) window.location.href=u.toString();
}
function onSearchInput(v){
  if(_searchTimer) clearTimeout(_searchTimer);
  _searchTimer=setTimeout(function(){ _navigateSearch(v); }, 280);
}
function onSearchKey(e){
  if(e.key==='Enter'){
    e.preventDefault();
    if(_searchTimer) clearTimeout(_searchTimer);
    _navigateSearch(e.target.value);
  } else if(e.key==='Escape' && e.target.value){
    e.preventDefault();
    e.target.value='';
    if(_searchTimer) clearTimeout(_searchTimer);
    _navigateSearch('');
  }
}
// Restore focus + caret-to-end after page reload while searching
(function(){
  const inp=document.getElementById('sidebar-search');
  if(inp && inp.value){
    setTimeout(function(){
      try { inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); } catch(_){}
    },0);
  }
})();

// ── Sidebar pagination: jump-to-page input ───────────────────────────────────
function jumpToPage(p){
  let n=parseInt(p,10);
  if(!isFinite(n) || n<1) n=1;
  if(n>SIDEBAR_TOTAL_PAGES) n=SIDEBAR_TOTAL_PAGES;
  const off=(n-1)*SIDEBAR_PAGE_SIZE;
  const u=new URL(window.location.href);
  u.searchParams.set('off', String(off));
  u.searchParams.delete('msg');
  if(u.toString()!==window.location.href) window.location.href=u.toString();
}

// ── List picker: filter dropdown options ─────────────────────────────────────
function filterListPicker(q){
  q=(q||'').trim().toLowerCase();
  const items=document.querySelectorAll('#listtype-options .dd-list-item');
  for(let i=0;i<items.length;i++){
    const el=items[i];
    const s=el.getAttribute('data-search')||'';
    el.classList.toggle('hidden-row', q!=='' && s.indexOf(q)===-1);
  }
}

// ── Schema editor: add/remove rows + badges ──────────────────────────────────
function typeWidth(t){
  const widths={int32:4,int64:8,float:4,double:8};
  if(widths[t]) return widths[t];
  if(t.indexOf('wstring:')===0){const n=parseInt(t.slice(8),10);return n>0?n:0;}
  if(t.indexOf('byte:')===0){const tail=t.slice(5);if(tail==='AUTO')return 0;const n=parseInt(tail,10);return n>0?n:0;}
  return 0;
}
function recomputeSchemaBadges(){
  const tbody=document.getElementById('schema-tbody');
  if(!tbody) return;
  const rows=tbody.querySelectorAll('tr');
  let total=0, off=0;
  rows.forEach(function(tr,i){
    const tSel=tr.querySelector('select[name="types[]"]');
    const t=tSel?tSel.value:'int32';
    const w=typeWidth(t);
    // update # cell
    const idxCell=tr.children[0];
    if(idxCell) idxCell.textContent=i;
    // update offset cell
    const offCell=tr.children[1];
    if(offCell){
      const span=offCell.querySelector('span');
      const hex='0x'+off.toString(16).toUpperCase().padStart(4,'0');
      if(span) span.textContent=hex;
    }
    off+=w;
    total+=w;
  });
  const bf=document.getElementById('badge-fields');
  const bs=document.getElementById('badge-size');
  if(bf) bf.textContent=rows.length+' fields';
  if(bs) bs.textContent='size = '+total;
}
function removeSchemaRow(btn){
  const tr=btn.closest('tr');
  const nameInp=tr.querySelector('input[name="names[]"]');
  const fname=nameInp?nameInp.value:'(unnamed)';
  tr.remove();
  recomputeSchemaBadges();
  toast('Field "'+fname+'" removed.','warning');
}
// Type list shared between Add Field and Import .cfg.
const AVAILABLE_TYPES = <?= json_encode($availableTypes) ?>;

// Build a schema-editor row with the given values. Returns the <tr> element
// (caller is responsible for inserting it into #schema-tbody).
function _buildSchemaRow(idx, name, type, refs){
  const tr=document.createElement('tr');
  tr.className='border-b border-slate-800/40 hover:bg-slate-800/20 group transition-colors';
  let opts='';
  let inAvail=false;
  for(let i=0;i<AVAILABLE_TYPES.length;i++){
    if(AVAILABLE_TYPES[i]===type) inAvail=true;
    opts+='<option value="'+esc(AVAILABLE_TYPES[i])+'"'+(AVAILABLE_TYPES[i]===type?' selected':'')+'>'+esc(AVAILABLE_TYPES[i])+'</option>';
  }
  // If the requested type isn't in the standard list, expose it as a custom option so it round-trips on save.
  if(!inAvail && type) opts+='<option value="'+esc(type)+'" selected>'+esc(type)+'</option>';
  tr.innerHTML=
    '<td class="px-3 py-1.5 text-slate-700">'+idx+'</td>'+
    '<td class="px-3 py-1.5"><span class="text-slate-500">0x0000</span></td>'+
    '<td class="px-3 py-1.5"><input type="text" name="names[]" value="'+esc(name)+'" class="w-full bg-transparent border border-transparent hover:border-slate-700 focus:border-cyan-700 focus:bg-slate-800/60 rounded px-1.5 py-0.5 text-slate-200 outline-none transition-colors text-[11px] mono"/></td>'+
    '<td class="px-3 py-1.5"><select name="types[]" class="bg-transparent border border-transparent hover:border-slate-700 focus:border-cyan-700 focus:bg-slate-900 rounded px-1.5 py-0.5 outline-none cursor-pointer transition-colors text-[11px] mono text-blue-300">'+opts+'</select></td>'+
    '<td class="px-3 py-1.5"><input type="text" name="refs[]" value="'+esc(refs)+'" placeholder="—" class="w-full bg-transparent border border-transparent hover:border-slate-700 focus:border-cyan-700 focus:bg-slate-800/60 rounded px-1.5 py-0.5 text-slate-500 placeholder-slate-700 outline-none transition-colors text-[11px] mono"/></td>'+
    '<td class="px-2 py-1.5"><button type="button" onclick="removeSchemaRow(this)" class="w-5 h-5 flex items-center justify-center rounded hover:bg-red-900/40 text-slate-700 hover:text-red-400 transition-colors opacity-0 group-hover:opacity-100">'+
    '<svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></td>';
  const sel=tr.querySelector('select[name="types[]"]');
  if(sel) sel.addEventListener('change',recomputeSchemaBadges);
  return tr;
}
function addSchemaRow(){
  const tbody=document.getElementById('schema-tbody');
  if(!tbody) return;
  const idx=tbody.querySelectorAll('tr').length;
  const tr=_buildSchemaRow(idx, 'f'+idx, 'int32', '');
  tbody.appendChild(tr);
  recomputeSchemaBadges();
  const nm=tr.querySelector('input[name="names[]"]');
  if(nm){nm.focus();nm.select();}
  toast('New field added.','info');
}
function truncateSchema(){
  const tbody=document.getElementById('schema-tbody');
  if(!tbody) return;
  const n=tbody.querySelectorAll('tr').length;
  if(n===0){toast('Schema is already empty.','warning');return;}
  if(!confirm('Remove all '+n+' fields from this schema?')) return;
  tbody.innerHTML='';
  recomputeSchemaBadges();
  toast('All '+n+' fields removed. Click "Save Schema" to persist.','error');
}
// Bundle every schema row into a single JSON hidden field, then strip the
// per-row inputs from submission (by clearing their `name` attr, since
// `disabled` would also visually grey them out). PHP reads `fields_json`
// when present and falls back to names[]/types[]/refs[] otherwise. This
// avoids PHP's max_input_vars=1000 cap that truncated saves past ~331 rows.
function prepareSchemaSubmit(form){
  const tbody=document.getElementById('schema-tbody');
  const out=[];
  if(tbody){
    const rows=tbody.querySelectorAll('tr');
    for(let i=0;i<rows.length;i++){
      const tr=rows[i];
      const nameEl=tr.querySelector('input[name="names[]"], input[data-orig-name="names[]"]');
      const typeEl=tr.querySelector('select[name="types[]"], select[data-orig-name="types[]"]');
      const refsEl=tr.querySelector('input[name="refs[]"], input[data-orig-name="refs[]"]');
      out.push({
        name: nameEl ? nameEl.value : '',
        type: typeEl ? typeEl.value : '',
        refs: refsEl ? refsEl.value : '',
      });
      // Strip name so the browser doesn't include this field in the POST.
      // Stash the original on a data attr so we can restore on validation failure.
      if(nameEl){ nameEl.setAttribute('data-orig-name','names[]'); nameEl.removeAttribute('name'); }
      if(typeEl){ typeEl.setAttribute('data-orig-name','types[]'); typeEl.removeAttribute('name'); }
      if(refsEl){ refsEl.setAttribute('data-orig-name','refs[]'); refsEl.removeAttribute('name'); }
    }
  }
  const json=document.getElementById('fields-json');
  if(json) json.value=JSON.stringify(out);
  return true;
}

// ── Import .cfg modal ───────────────────────────────────────────────────────
function _parseCfg(text){
  // Split into trimmed non-empty lines so the source's blank separator lines
  // (the typical .cfg format has blanks between the 3 chunks) don't break us.
  const lines=text.split(/\r?\n/).map(function(l){return l.trim();}).filter(function(l){return l.length>0;});
  if(lines.length<3) throw new Error('Need 3 non-empty lines: list name, field names, field types.');
  if(lines.length>3) throw new Error('Got '+lines.length+' non-empty lines; expected exactly 3.');
  const splitClean=function(s){
    return s.split(';').map(function(p){return p.trim();}).filter(function(p){return p.length>0;});
  };
  const listName=lines[0];
  const fields=splitClean(lines[1]);
  const types =splitClean(lines[2]);
  if(!listName) throw new Error('List name is empty.');
  if(fields.length===0) throw new Error('No field names parsed from line 2.');
  if(fields.length!==types.length) throw new Error('Field count ('+fields.length+') does not match type count ('+types.length+').');
  return {name:listName, fields:fields, types:types};
}
function openCfgModal(){
  const m=document.getElementById('cfg-modal');
  if(!m) return;
  // Surface the target file the import will eventually write to.
  const tgt=document.getElementById('cfg-target');
  const verEl=document.querySelector('input[name="version"]');
  const lstEl=document.querySelector('input[name="list"]');
  if(tgt && verEl && lstEl){
    tgt.textContent='structures/'+verEl.value+'/list_'+lstEl.value+'.json';
  }
  m.classList.remove('hidden');
  m.classList.add('flex');
  const ta=document.getElementById('cfg-textarea');
  if(ta) setTimeout(function(){ta.focus();},10);
}
function closeCfgModal(){
  const m=document.getElementById('cfg-modal');
  if(!m) return;
  m.classList.add('hidden');
  m.classList.remove('flex');
}
function importCfgFromText(){
  const ta=document.getElementById('cfg-textarea');
  if(!ta) return;
  let parsed;
  try { parsed=_parseCfg(ta.value); }
  catch(e){ toast(e.message,'error'); return; }
  // Replace list-name input
  const nameInp=document.querySelector('#schemaForm input[name="name"]');
  if(nameInp) nameInp.value=parsed.name;
  // Wipe + repopulate schema rows
  const tbody=document.getElementById('schema-tbody');
  if(!tbody){ toast('Schema editor not available.','error'); return; }
  tbody.innerHTML='';
  for(let i=0;i<parsed.fields.length;i++){
    tbody.appendChild(_buildSchemaRow(i, parsed.fields[i], parsed.types[i], ''));
  }
  recomputeSchemaBadges();
  closeCfgModal();
  toast('Imported '+parsed.fields.length+' field'+(parsed.fields.length===1?'':'s')+' into "'+parsed.name+'". Click Save Schema to persist.','success');
}

// ── Import C++ struct modal ─────────────────────────────────────────────────
// Strip /* ... */ blocks and // line comments. Done first so the tokenizer
// doesn't have to know about comment syntax.
function _stripCppComments(src){
  src = src.replace(/\/\*[\s\S]*?\*\//g, ' ');
  src = src.replace(/\/\/[^\n]*/g, ' ');
  return src;
}
// Tokenize into {kind, val} where kind ∈ {'id','num','punct'}.
function _tokenizeCpp(src){
  src = _stripCppComments(src);
  const out=[];
  // __int64 has leading underscores so the identifier regex covers it.
  const re = /([A-Za-z_][A-Za-z_0-9]*)|(\d+)|([{}()\[\];,])/g;
  let m;
  while((m = re.exec(src)) !== null){
    if(m[1])      out.push({kind:'id',    val:m[1]});
    else if(m[2]) out.push({kind:'num',   val:parseInt(m[2],10)});
    else          out.push({kind:'punct', val:m[3]});
  }
  return out;
}
// Recursive-descent parser for the constrained subset:
//   struct [TAG] { field+ } [;]
//   field = TYPE NAME [ '[' N ']' ] ';'
//         | struct [TAG] { field+ } NAME [ '[' N ']' ] ';'
// TYPE is one or more identifier tokens, the LAST id before '[' or ';' is
// the field name.
function _parseCppStruct(text){
  const toks = _tokenizeCpp(text);
  let pos = 0;
  const peek = (off=0)=>toks[pos+off];
  const isPunct = (p,off=0)=>{const t=peek(off); return t && t.kind==='punct' && t.val===p;};
  const isId    = (s,off=0)=>{const t=peek(off); return t && t.kind==='id'    && t.val===s;};
  const eatPunct = (p)=>{
    if(!isPunct(p)) throw new Error('Expected "'+p+'" near token '+pos+' ('+JSON.stringify(peek())+')');
    pos++;
  };
  function parseStructBody(){
    // Caller has already consumed `struct` and an optional tag and the `{`.
    const fields=[];
    while(peek() && !isPunct('}')){
      fields.push(parseField());
    }
    eatPunct('}');
    return fields;
  }
  function parseField(){
    if(isId('struct')){
      pos++;
      // Optional tag (identifier before '{').
      if(peek() && peek().kind==='id' && isPunct('{',1)) pos++;
      eatPunct('{');
      const inner = parseStructBody();
      // Now: NAME [ '[' N ']' ] ';'
      if(!peek() || peek().kind!=='id') throw new Error('Expected field name after struct body near token '+pos);
      const fname = peek().val; pos++;
      let arr = 1;
      if(isPunct('[')){
        pos++;
        if(!peek() || peek().kind!=='num') throw new Error('Expected number inside [] for "'+fname+'"');
        arr = peek().val; pos++;
        eatPunct(']');
      }
      eatPunct(';');
      return {kind:'struct', name:fname, fields:inner, arraySize:arr};
    }
    // Plain field. Collect identifier tokens up to '[' or ';'. Last id = name.
    const ids=[];
    while(peek() && peek().kind==='id'){
      ids.push(peek().val); pos++;
    }
    if(ids.length < 2) throw new Error('Expected "type name;" near token '+pos+', got '+JSON.stringify(ids));
    const fname = ids.pop();
    const typeStr = ids.join(' ');
    let arr = null;
    if(isPunct('[')){
      pos++;
      if(!peek() || peek().kind!=='num') throw new Error('Expected number inside [] for "'+fname+'"');
      arr = peek().val; pos++;
      eatPunct(']');
    }
    eatPunct(';');
    return {kind:'leaf', name:fname, typeStr:typeStr, arraySize:arr};
  }
  // Top-level: locate the first 'struct' keyword and parse from there.
  while(peek() && !isId('struct')) pos++;
  if(!peek()) throw new Error('No "struct" keyword found.');
  pos++;
  let topName = null;
  if(peek() && peek().kind==='id' && isPunct('{',1)){
    topName = peek().val; pos++;
  } else if(peek() && peek().kind==='id' && !isPunct('{',1)){
    // Tag-only declaration like `struct FOO;` — not supported.
    topName = peek().val; pos++;
  }
  eatPunct('{');
  const fields = parseStructBody();
  if(isPunct(';')) pos++;
  return {name: topName || 'UNNAMED_STRUCT', fields: fields};
}
// Map a single C++ leaf field to a schema {name, type} (or array of them
// if the field is a non-namechar/byte array — those expand to N copies).
function _cppLeafToSchema(prefix, leaf){
  const t   = leaf.typeStr.replace(/\s+/g,' ').trim();
  const arr = leaf.arraySize;
  const fname = prefix + leaf.name;
  // Width-grouped type lookup. Both signed and unsigned collapse to the
  // same schema type since the binary layout is identical.
  let scalarType = null;
  if(t==='int' || t==='unsigned int' || t==='signed int' || t==='unsigned' || t==='signed' ||
     t==='long' || t==='unsigned long' || t==='signed long' ||
     t==='DWORD' || t==='int32_t' || t==='uint32_t'){
    scalarType = 'int32';
  } else if(t==='short' || t==='unsigned short' || t==='signed short' ||
            t==='WORD' || t==='int16_t' || t==='uint16_t'){
    // 16-bit isn't a primary schema type — store as byte:2 to preserve width.
    scalarType = 'byte:2';
  } else if(t==='__int64' || t==='unsigned __int64' || t==='signed __int64' ||
            t==='long long' || t==='unsigned long long' || t==='signed long long' ||
            t==='int64_t' || t==='uint64_t' ||
            t==='Int64' || t==='UInt64'){
    scalarType = 'int64';
  } else if(t==='float'){
    scalarType = 'float';
  } else if(t==='double'){
    scalarType = 'double';
  } else if(t==='namechar' || t==='wchar_t'){
    if(arr == null || arr <= 0) throw new Error('"'+fname+'": '+t+' must be an array (e.g. '+leaf.name+'[N])');
    return [{name: fname, type: 'wstring:'+(arr*2)}];
  } else if(t==='char' || t==='signed char' || t==='unsigned char' || t==='byte' || t==='BYTE' || t==='uint8_t' || t==='int8_t'){
    return [{name: fname, type: arr != null ? 'byte:'+arr : 'byte:1'}];
  } else {
    throw new Error('"'+fname+'": unrecognized C++ type "'+t+'"');
  }
  // Scalar (non-byte/wstring): if arrayed, expand into N suffix-numbered copies.
  if(arr == null) return [{name: fname, type: scalarType}];
  const out=[];
  for(let i=1;i<=arr;i++) out.push({name: fname+'_'+i, type: scalarType});
  return out;
}
// Walk the parsed tree and produce the flat field list. Nested struct arrays
// expand into prefixed names: pages_1_…, pages_2_…, etc. Round-trips through
// the Export C++ generator since its parser detects `_<digit>_` as a path
// index when followed by more tokens.
function _flattenCppToSchema(parsed){
  const fields=[];
  function walk(prefix, kids){
    for(const f of kids){
      if(f.kind==='leaf'){
        const items = _cppLeafToSchema(prefix, f);
        for(const it of items) fields.push(it);
      } else {
        const n = f.arraySize > 0 ? f.arraySize : 1;
        for(let i=1;i<=n;i++){
          walk(prefix + f.name + '_' + i + '_', f.fields);
        }
      }
    }
  }
  walk('', parsed.fields);
  return fields;
}
function openCppImportModal(){
  const m=document.getElementById('cpp-import-modal');
  if(!m) return;
  // Surface the destination file so the user knows where Save Schema will write.
  const tgt=document.getElementById('cpp-import-target');
  const verEl=document.querySelector('input[name="version"]');
  const lstEl=document.querySelector('input[name="list"]');
  if(tgt && verEl && lstEl){
    tgt.textContent = verEl.value+'/list_'+lstEl.value+'.json';
  }
  m.classList.remove('hidden'); m.classList.add('flex');
  setTimeout(function(){
    const ta=document.getElementById('cpp-import-textarea');
    if(ta) ta.focus();
  }, 50);
}
function closeCppImportModal(){
  const m=document.getElementById('cpp-import-modal');
  if(m){m.classList.add('hidden'); m.classList.remove('flex');}
}
function importCppFromText(){
  const ta=document.getElementById('cpp-import-textarea');
  if(!ta) return;
  const src = (ta.value||'').trim();
  if(!src){ toast('Paste a C++ struct first.','warning'); return; }
  let parsed, flat;
  try { parsed = _parseCppStruct(src); }
  catch(e){ toast('Parse error: '+e.message,'error'); return; }
  try { flat = _flattenCppToSchema(parsed); }
  catch(e){ toast('Flatten error: '+e.message,'error'); return; }
  if(!flat.length){ toast('No fields produced from struct.','warning'); return; }
  // Replace list-name input
  const nameInp=document.querySelector('#schemaForm input[name="name"]');
  if(nameInp) nameInp.value=parsed.name;
  // Wipe + repopulate schema rows
  const tbody=document.getElementById('schema-tbody');
  if(!tbody){ toast('Schema editor not available.','error'); return; }
  tbody.innerHTML='';
  for(let i=0;i<flat.length;i++){
    tbody.appendChild(_buildSchemaRow(i, flat[i].name, flat[i].type, ''));
  }
  recomputeSchemaBadges();
  closeCppImportModal();
  toast('Parsed "'+parsed.name+'": '+flat.length+' flat field'+(flat.length===1?'':'s')+'. Click Save Schema to persist.','success');
}

// ── Compare list sizes modal ────────────────────────────────────────────────
// Holds the most recent diff response so the filter checkboxes can re-render
// without re-hitting the server.
var _diffData = null;
function openDiffModal(){
  const m=document.getElementById('diff-modal');
  if(!m) return;
  m.classList.remove('hidden'); m.classList.add('flex');
  setTimeout(function(){
    const sel=document.getElementById('diff-file-b');
    if(sel) sel.focus();
  }, 50);
}
function closeDiffModal(){
  const m=document.getElementById('diff-modal');
  if(m){m.classList.add('hidden'); m.classList.remove('flex');}
}
function runDiff(){
  const fileA=document.getElementById('diff-file-a').textContent.trim();
  const fileB=document.getElementById('diff-file-b').value;
  if(!fileA){ toast('No A file loaded.','error'); return; }
  if(!fileB){ toast('Pick a second file from the dropdown.','warning'); return; }
  const meta=document.getElementById('diff-meta');
  if(meta) meta.textContent='— loading…';
  const tbody=document.getElementById('diff-tbody');
  if(tbody) tbody.innerHTML='';
  const tbl=document.getElementById('diff-table');
  const empty=document.getElementById('diff-empty');
  if(tbl) tbl.classList.add('hidden');
  if(empty){ empty.classList.remove('hidden'); empty.querySelector('p').textContent='Loading…'; }
  const url='?action=diff_lists&file_a='+encodeURIComponent(fileA)+'&file_b='+encodeURIComponent(fileB);
  fetch(url, {credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(data){
      if(data.error){
        if(meta) meta.textContent='';
        if(empty) empty.querySelector('p').textContent='Error: '+data.error;
        toast('Diff failed: '+data.error,'error');
        return;
      }
      _diffData = data;
      if(meta) meta.textContent='— '+data.version_a+' vs '+data.version_b;
      renderDiffTable();
    })
    .catch(function(e){
      if(meta) meta.textContent='';
      if(empty) empty.querySelector('p').textContent='Network error: '+e;
      toast('Diff request failed: '+e,'error');
    });
}
function renderDiffTable(){
  if(!_diffData) return;
  const tbody=document.getElementById('diff-tbody');
  const tbl=document.getElementById('diff-table');
  const empty=document.getElementById('diff-empty');
  const onlySize=document.getElementById('diff-only-size').checked;
  const onlyCount=document.getElementById('diff-only-count').checked;
  const search=(document.getElementById('diff-search').value||'').trim().toLowerCase();
  const totals=document.getElementById('diff-totals');
  let shown=0;
  let html='';
  for(const r of _diffData.rows){
    if(onlySize && !r.size_changed) continue;
    if(onlyCount && !r.count_changed) continue;
    if(search){
      const hay = ('#'+r.idx+' '+(r.name||'')).toLowerCase();
      if(hay.indexOf(search) === -1) continue;
    }
    shown++;
    // Pick the dominant accent: only-A > only-B > size > count > none
    let rowCls='hover:bg-slate-800/40';
    let badge='';
    if(r.only_in_a){
      rowCls='bg-cyan-950/30 hover:bg-cyan-950/50';
      badge='<span class="px-1.5 py-0.5 rounded bg-cyan-900/60 border border-cyan-700/50 text-cyan-200 text-[10px]">only in A</span>';
    } else if(r.only_in_b){
      rowCls='bg-purple-950/30 hover:bg-purple-950/50';
      badge='<span class="px-1.5 py-0.5 rounded bg-purple-900/60 border border-purple-700/50 text-purple-200 text-[10px]">only in B</span>';
    } else if(r.size_changed){
      rowCls='bg-red-950/30 hover:bg-red-950/50';
      badge='<span class="px-1.5 py-0.5 rounded bg-red-900/60 border border-red-700/50 text-red-200 text-[10px]">struct changed</span>';
      if(r.count_changed){
        badge += ' <span class="px-1.5 py-0.5 rounded bg-yellow-900/40 border border-yellow-700/40 text-yellow-300 text-[10px] ml-1">count Δ</span>';
      }
    } else if(r.count_changed){
      rowCls='bg-yellow-950/20 hover:bg-yellow-950/40';
      badge='<span class="px-1.5 py-0.5 rounded bg-yellow-900/40 border border-yellow-700/40 text-yellow-300 text-[10px]">count Δ</span>';
    } else {
      badge='<span class="text-slate-600 text-[10px]">—</span>';
    }
    const dSize = (r.sizeof_a!=null && r.sizeof_b!=null) ? (r.sizeof_b - r.sizeof_a) : null;
    const dCnt  = (r.count_a !=null && r.count_b !=null) ? (r.count_b  - r.count_a)  : null;
    const fmt = function(v){return v==null ? '<span class="text-slate-700">—</span>' : v.toLocaleString();};
    const dfmt = function(d, hot){
      if(d===null) return '<span class="text-slate-700">—</span>';
      if(d===0)    return '<span class="text-slate-600">0</span>';
      const cls = hot ? (d>0 ? 'text-red-300' : 'text-red-300') : (d>0 ? 'text-emerald-300' : 'text-orange-300');
      return '<span class="'+cls+'">'+(d>0?'+':'')+d.toLocaleString()+'</span>';
    };
    html += '<tr class="'+rowCls+' transition-colors">'+
      '<td class="px-3 py-1.5 text-right text-slate-400">'+r.idx+'</td>'+
      '<td class="px-3 py-1.5 text-slate-200">'+(r.name ? esc(r.name) : '<span class="text-slate-700">(no schema)</span>')+'</td>'+
      '<td class="px-3 py-1.5 text-right text-slate-300">'+fmt(r.sizeof_a)+'</td>'+
      '<td class="px-3 py-1.5 text-right text-slate-300">'+fmt(r.sizeof_b)+'</td>'+
      '<td class="px-3 py-1.5 text-right">'+dfmt(dSize, true)+'</td>'+
      '<td class="px-3 py-1.5 text-right text-slate-400">'+fmt(r.count_a)+'</td>'+
      '<td class="px-3 py-1.5 text-right text-slate-400">'+fmt(r.count_b)+'</td>'+
      '<td class="px-3 py-1.5 text-right">'+dfmt(dCnt, false)+'</td>'+
      '<td class="px-3 py-1.5">'+badge+'</td>'+
      '</tr>';
  }
  tbody.innerHTML = html || '<tr><td colspan="9" class="px-3 py-6 text-center text-slate-500">No rows match the current filter.</td></tr>';
  if(tbl) tbl.classList.remove('hidden');
  if(empty) empty.classList.add('hidden');
  if(totals){
    const t=_diffData.totals||{};
    totals.textContent =
      'Total '+t.rows+' lists  ·  '+
      t.changed_size+' sizeof Δ  ·  '+
      t.changed_count+' count Δ  ·  '+
      t.only_a+' only in A  ·  '+
      t.only_b+' only in B  ·  showing '+shown;
  }
}

// ── Export C++ struct modal ─────────────────────────────────────────────────
// Map a stored schema type to a C++ declaration. Returns {decl, suffix} where
// `decl` is the type token (placed before the field name) and `suffix` is
// appended after the field name (used for arrays).
function _cppForType(t){
  if(t==='int32')   return {decl:'unsigned int', suffix:''};
  if(t==='int64')   return {decl:'__int64',      suffix:''};
  if(t==='float')   return {decl:'float',        suffix:''};
  if(t==='double')  return {decl:'double',       suffix:''};
  if(t.indexOf('wstring:')===0){
    const n=parseInt(t.slice(8),10);
    const chars=isFinite(n) && n>0 ? Math.floor(n/2) : 0;
    return {decl:'namechar', suffix:'['+chars+']'};
  }
  if(t.indexOf('byte:')===0){
    const tail=t.slice(5);
    if(tail==='AUTO') return {decl:'unsigned char', suffix:'[/* AUTO */]'};
    const n=parseInt(tail,10);
    return {decl:'unsigned char', suffix:'['+(isFinite(n) && n>0 ? n : 0)+']'};
  }
  return {decl:t, suffix:''};
}
// Tab-align: advance from `fromCol` to `toCol` using `T`-wide tab stops.
// Returns a run of '\t' characters (≥1 if from<to).
function _alignTabs(fromCol, toCol, T){
  let out='', col=fromCol;
  while(col < toCol){
    col = col + T - (col % T);
    out += '\t';
  }
  return out;
}
// Parse a flat field name like `Page_1_Goods_1_Item_Req_1_ID` into a path of
// (struct-name, index) pairs plus a final leaf name. A numeric token is
// treated as a path index ONLY when more tokens follow it; a trailing number
// (e.g. `Unknown_2`) is kept as part of the leaf name.
function _parseFieldPath(name){
  const parts=name.split('_');
  const path=[];
  let buf=[];
  for(let i=0;i<parts.length;i++){
    const tok=parts[i];
    if(/^\d+$/.test(tok) && i < parts.length-1 && buf.length>0){
      path.push({name: buf.join('_'), idx: parseInt(tok,10)});
      buf=[];
    } else {
      buf.push(tok);
    }
  }
  return {path: path, leaf: buf.join('_')};
}
// Build a nested tree from parsed field paths. Each node has:
//   ordered  – [{kind:'leaf'|'struct', key}] in first-appearance order
//   leaves   – {key: {name, type}}
//   structs  – {key: {maxIdx, node}}
function _buildCppTree(fields){
  const root={ordered:[], leaves:{}, structs:{}};
  for(const f of fields){
    const parsed=_parseFieldPath(f.name);
    let node=root;
    for(const step of parsed.path){
      if(!(step.name in node.structs)){
        node.structs[step.name]={maxIdx: step.idx, node:{ordered:[], leaves:{}, structs:{}}};
        node.ordered.push({kind:'struct', key: step.name});
      } else if(step.idx > node.structs[step.name].maxIdx){
        node.structs[step.name].maxIdx = step.idx;
      }
      node = node.structs[step.name].node;
    }
    const leafKey = parsed.leaf || ('field_'+node.ordered.length);
    if(!(leafKey in node.leaves)){
      node.leaves[leafKey] = {name: leafKey, type: f.type};
      node.ordered.push({kind:'leaf', key: leafKey});
    }
  }
  return root;
}
// Render a tree node recursively. `indent` is the number of leading tabs for
// fields at this level. The name column is computed per-level using only the
// leaf type-decl widths (struct lines are multi-line and skip alignment).
function _renderCppNode(node, indent, T){
  // First pass — gather entries and find the longest leaf decl at this level.
  let maxDecl=0;
  const entries=[];
  for(const e of node.ordered){
    if(e.kind==='leaf'){
      const leaf=node.leaves[e.key];
      const cpp=_cppForType(leaf.type);
      entries.push({kind:'leaf', decl: cpp.decl, lhs: leaf.name + cpp.suffix + ';'});
      if(cpp.decl.length > maxDecl) maxDecl = cpp.decl.length;
    } else {
      const s=node.structs[e.key];
      entries.push({kind:'struct', name: e.key, maxIdx: s.maxIdx, child: s.node});
    }
  }
  const endOfLongest = indent*T + maxDecl;
  const nameCol      = maxDecl > 0 ? endOfLongest + T - (endOfLongest % T) : 0;
  const lead         = '\t'.repeat(indent);
  let out='';
  for(const it of entries){
    if(it.kind==='leaf'){
      const startCol = indent*T + it.decl.length;
      const pad      = _alignTabs(startCol, nameCol, T);
      out += lead + it.decl + pad + it.lhs + '\n';
    } else {
      out += lead + 'struct\n';
      out += lead + '{\n';
      out += _renderCppNode(it.child, indent+1, T);
      out += lead + '} ' + it.name + '[' + it.maxIdx + '];\n';
    }
  }
  return out;
}
function _generateCppStruct(){
  const tbody=document.getElementById('schema-tbody');
  const nameInp=document.querySelector('#schemaForm input[name="name"]');
  const structName=(nameInp && nameInp.value.trim()) ? nameInp.value.trim() : 'UNNAMED_STRUCT';
  if(!tbody) return {text:'struct '+structName+'\n{\n};\n', total:0, fields:0, structName:structName};
  const T=4;
  const rows=tbody.querySelectorAll('tr');
  const fields=[];
  let totalBytes=0;
  for(let i=0;i<rows.length;i++){
    const tr=rows[i];
    const nameEl=tr.querySelector('input[name="names[]"]');
    const typeEl=tr.querySelector('select[name="types[]"]');
    const fname=nameEl ? nameEl.value.trim() : '';
    const ftype=typeEl ? typeEl.value : 'int32';
    totalBytes += typeWidth(ftype);
    if(!fname && !ftype) continue;
    fields.push({name: fname || ('field_'+i), type: ftype});
  }
  if(fields.length===0){
    return {text:'struct '+structName+'\n{\n};\n', total:totalBytes, fields:0, structName:structName};
  }
  const tree=_buildCppTree(fields);
  let out='struct '+structName+'\n{\n';
  out += _renderCppNode(tree, 1, T);
  out += '};\n';
  return {text:out, total:totalBytes, fields:fields.length, structName:structName};
}
function openCppModal(){
  const r=_generateCppStruct();
  const pre=document.getElementById('cpp-output');
  if(pre) pre.textContent=r.text;
  const meta=document.getElementById('cpp-meta');
  if(meta) meta.textContent='— '+r.fields+' field'+(r.fields===1?'':'s')+', '+r.total+' bytes';
  const m=document.getElementById('cpp-modal');
  if(m){m.classList.remove('hidden'); m.classList.add('flex');}
}
function closeCppModal(){
  const m=document.getElementById('cpp-modal');
  if(m){m.classList.add('hidden'); m.classList.remove('flex');}
}
function copyCppStruct(){
  const pre=document.getElementById('cpp-output');
  if(!pre) return;
  const txt=pre.textContent;
  const done=function(ok){ toast(ok?'C++ struct copied to clipboard.':'Copy failed.', ok?'success':'error'); };
  if(navigator.clipboard && navigator.clipboard.writeText){
    navigator.clipboard.writeText(txt).then(function(){done(true);},function(){done(false);});
  } else {
    // Legacy fallback
    try {
      const ta=document.createElement('textarea');
      ta.value=txt; ta.style.position='fixed'; ta.style.opacity='0';
      document.body.appendChild(ta); ta.select();
      const ok=document.execCommand('copy');
      document.body.removeChild(ta);
      done(ok);
    } catch(_){ done(false); }
  }
}

// Esc closes whichever modal is open.
document.addEventListener('keydown',function(e){
  if(e.key!=='Escape') return;
  const diff=document.getElementById('diff-modal');
  if(diff && !diff.classList.contains('hidden')){ closeDiffModal(); return; }
  const cppi=document.getElementById('cpp-import-modal');
  if(cppi && !cppi.classList.contains('hidden')){ closeCppImportModal(); return; }
  const cfg=document.getElementById('cfg-modal');
  if(cfg && !cfg.classList.contains('hidden')){ closeCfgModal(); return; }
  const cpp=document.getElementById('cpp-modal');
  if(cpp && !cpp.classList.contains('hidden')){ closeCppModal(); return; }
});

// Recompute badges on every type change (initial wiring)
(function(){
  const tbody=document.getElementById('schema-tbody');
  if(!tbody) return;
  tbody.querySelectorAll('select[name="types[]"]').forEach(function(sel){
    sel.addEventListener('change',recomputeSchemaBadges);
  });
})();

// ── Hex viewer ───────────────────────────────────────────────────────────────
function renderHex(){
  const host=document.getElementById('hex-viewer');
  if(!host) return;
  const bytes=HEX_BYTES;
  let html='<div class="flex gap-3 text-slate-700 mb-1"><span class="w-[5.5rem]">OFFSET</span><span class="w-[11.5rem]">00 01 02 03 04 05 06 07</span><span class="w-[11.5rem]">08 09 0A 0B 0C 0D 0E 0F</span><span>ASCII</span></div>';
  for(let i=0;i<bytes.length;i+=16){
    const chunk=bytes.slice(i,i+16);
    const hex=chunk.map(function(b){return b.toString(16).toUpperCase().padStart(2,'0');});
    const asc=chunk.map(function(b){return b>=0x20&&b<0x7F?String.fromCharCode(b):'·';});
    const addr=(HEX_ADDR_BASE+i).toString(16).toUpperCase().padStart(8,'0');
    html+='<div class="flex gap-3 py-0.5 hover:bg-slate-800/50 rounded group">'+
      '<span class="w-[5.5rem] text-slate-600 group-hover:text-cyan-700">'+addr+':</span>'+
      '<span class="w-[11.5rem] text-slate-300 tracking-wide">'+hex.slice(0,8).join(' ')+'</span>'+
      '<span class="w-[11.5rem] text-slate-300 tracking-wide">'+hex.slice(8).join(' ')+'</span>'+
      '<span class="text-slate-600 tracking-wider">'+esc(asc.join(''))+'</span>'+
    '</div>';
  }
  host.innerHTML=html;
}
renderHex();

// ── Scroll active sidebar row into view ──────────────────────────────────────
(function(){
  const active=document.querySelector('#sidebar-list .rec-item.bg-cyan-950\\/30');
  if(active && active.scrollIntoView) active.scrollIntoView({block:'center'});
})();

// ── Compare Records ──────────────────────────────────────────────────────────
const CMP_FILE  = <?= json_encode($fileName) ?>;
const CMP_LIST  = <?= (int)$selectedListIdx ?>;
const CMP_COUNT = <?= (int)$listCount ?>;

let _cmpData   = null;
let _cmpFilter = 'all';

function cmpTypeBadge(t) {
  if (!t) return 'bg-slate-800 text-slate-500 border-slate-700';
  if (/^int/.test(t))    return 'bg-blue-950 text-blue-300 border-blue-800';
  if (t==='float'||t==='double') return 'bg-purple-950 text-purple-300 border-purple-800';
  if (t.startsWith('wstring:')) return 'bg-emerald-950 text-emerald-300 border-emerald-800';
  if (t.startsWith('byte:'))    return 'bg-orange-950 text-orange-300 border-orange-800';
  return 'bg-slate-800 text-slate-500 border-slate-700';
}

// ── Export dropdown ──────────────────────────────────────────────────────────
function toggleExportMenu(e) {
  e.stopPropagation();
  const menu = document.getElementById('export-menu');
  if (!menu) return;
  menu.classList.toggle('hidden');
}
document.addEventListener('click', function() {
  const menu = document.getElementById('export-menu');
  if (menu) menu.classList.add('hidden');
});

function openCmpModal(rowA) {
  if (CMP_LIST < 0) { toast('No list selected', 'warning'); return; }
  document.getElementById('cmp-row-a').value = rowA;
  const rowB = rowA > 0 ? rowA - 1 : 1;
  document.getElementById('cmp-row-b').value = rowB;
  document.getElementById('cmp-meta').textContent = '';
  document.getElementById('cmp-empty').classList.remove('hidden');
  document.getElementById('cmp-table').classList.add('hidden');
  document.getElementById('cmp-stats').textContent = '';
  const modal = document.getElementById('cmp-modal');
  modal.classList.remove('hidden');
  modal.classList.add('flex');
  setCmpFilter('all', false);
  runCmp();
}

function closeCmpModal() {
  const modal = document.getElementById('cmp-modal');
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}

async function runCmp() {
  const rowA = parseInt(document.getElementById('cmp-row-a').value, 10);
  const rowB = parseInt(document.getElementById('cmp-row-b').value, 10);
  if (isNaN(rowA) || isNaN(rowB))       { toast('Enter valid row numbers', 'warning'); return; }
  if (rowA === rowB)                     { toast('Pick two different records', 'warning'); return; }
  if (CMP_COUNT > 0 && (rowA >= CMP_COUNT || rowB >= CMP_COUNT)) {
    toast(`Row out of range (max ${CMP_COUNT - 1})`, 'warning'); return;
  }
  // Loading state
  document.getElementById('cmp-empty').innerHTML =
    '<div class="flex items-center justify-center gap-2 text-[11px] mono text-slate-500"><svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>Loading…</div>';
  document.getElementById('cmp-empty').classList.remove('hidden');
  document.getElementById('cmp-table').classList.add('hidden');
  try {
    const url = `?action=compare_records&file=${encodeURIComponent(CMP_FILE)}&list=${CMP_LIST}&row_a=${rowA}&row_b=${rowB}`;
    const res  = await fetch(url);
    const data = await res.json();
    if (data.error) {
      document.getElementById('cmp-empty').innerHTML = `<p class="text-[12px] mono text-red-400">${escHtml(data.error)}</p>`;
      return;
    }
    _cmpData = data;
    document.getElementById('cmp-meta').textContent =
      `${data.list_name} · ${data.count.toLocaleString()} records`;
    document.getElementById('cmp-th-a').textContent = `Record A  (#${rowA})`;
    document.getElementById('cmp-th-b').textContent = `Record B  (#${rowB})`;
    renderCmpTable();
  } catch(e) {
    document.getElementById('cmp-empty').innerHTML = `<p class="text-[12px] mono text-red-400">Network error: ${escHtml(e.message)}</p>`;
  }
}

function setCmpFilter(f, doRender = true) {
  _cmpFilter = f;
  const styles = {
    active:   'px-2.5 py-1 bg-purple-950/60 text-purple-300 border-l border-slate-700',
    inactive: 'px-2.5 py-1 text-slate-500 hover:text-slate-300 border-l border-slate-700',
  };
  ['all','diff','same'].forEach(x => {
    const btn = document.getElementById('cmpf-' + x);
    if (btn) {
      // Remove border-l from first item
      const base = x === 'all' ? '' : ' border-l border-slate-700';
      btn.className = (f === x
        ? 'px-2.5 py-1 bg-purple-950/60 text-purple-300'
        : 'px-2.5 py-1 text-slate-500 hover:text-slate-300') + base;
    }
  });
  if (doRender) renderCmpTable();
}

function renderCmpTable() {
  if (!_cmpData) return;
  const search = (document.getElementById('cmp-search')?.value || '').toLowerCase();
  const tbody  = document.getElementById('cmp-tbody');

  let nDiff = 0, nSame = 0, nShown = 0;
  let html = '';

  for (const f of _cmpData.fields) {
    const va   = String(_cmpData.record_a.data[f.name] ?? '');
    const vb   = String(_cmpData.record_b.data[f.name] ?? '');
    const diff = (va !== vb);
    if (diff) nDiff++; else nSame++;

    if (_cmpFilter === 'diff' && !diff) continue;
    if (_cmpFilter === 'same' && diff)  continue;
    if (search && !f.name.toLowerCase().includes(search)
               && !va.toLowerCase().includes(search)
               && !vb.toLowerCase().includes(search)) continue;
    nShown++;

    // Compute delta for numeric types
    let delta = '', deltaColor = 'text-slate-600';
    if (diff) {
      const isInt   = (f.type === 'int32' || f.type === 'int64');
      const isFloat = (f.type === 'float' || f.type === 'double');
      if (isInt) {
        const d = parseInt(vb, 10) - parseInt(va, 10);
        delta = (d > 0 ? '+' : '') + d.toLocaleString();
        deltaColor = d > 0 ? 'text-emerald-400' : 'text-red-400';
      } else if (isFloat) {
        const d = parseFloat(vb) - parseFloat(va);
        delta = (d > 0 ? '+' : '') + d.toFixed(4);
        deltaColor = d > 0 ? 'text-emerald-400' : 'text-red-400';
      } else {
        delta = '≠';
        deltaColor = 'text-orange-500';
      }
    } else {
      delta = '=';
      deltaColor = 'text-slate-700';
    }

    const rowBg  = diff ? 'bg-orange-950/15 hover:bg-orange-950/25' : 'hover:bg-slate-800/20';
    const vaDisp = va.length > 52 ? va.slice(0, 52) + '…' : va;
    const vbDisp = vb.length > 52 ? vb.slice(0, 52) + '…' : vb;
    const badge  = cmpTypeBadge(f.type);

    html += `<tr class="${rowBg} transition-colors">
      <td class="px-3 py-1.5 text-slate-300 whitespace-nowrap font-medium">${escHtml(f.name)}</td>
      <td class="px-2 py-1.5"><span class="inline-flex px-1 py-0.5 rounded text-[9px] border ${badge}">${escHtml(f.type)}</span></td>
      <td class="px-3 py-1.5 ${diff?'text-yellow-300':'text-slate-400'} max-w-[220px]"><span class="block truncate" title="${escHtml(va)}">${escHtml(vaDisp)}</span></td>
      <td class="px-3 py-1.5 ${diff?'text-cyan-300':'text-slate-400'} max-w-[220px]"><span class="block truncate" title="${escHtml(vb)}">${escHtml(vbDisp)}</span></td>
      <td class="px-3 py-1.5 text-right ${deltaColor} whitespace-nowrap">${escHtml(delta)}</td>
    </tr>`;
  }

  tbody.innerHTML = html;
  document.getElementById('cmp-table').classList.remove('hidden');
  document.getElementById('cmp-empty').classList.add('hidden');

  const pct = _cmpData.fields.length > 0
    ? Math.round(nDiff / _cmpData.fields.length * 100) : 0;
  document.getElementById('cmp-stats').innerHTML =
    `<span class="text-orange-400">${nDiff} changed</span> · ` +
    `<span class="text-slate-500">${nSame} identical</span> · ` +
    `${_cmpData.fields.length} total fields · ` +
    `<span class="${nDiff > 0 ? 'text-orange-500' : 'text-emerald-600'}">${pct}% differ</span>` +
    (nShown < nDiff + nSame ? ` · showing ${nShown}` : '');
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Flash message → toast ────────────────────────────────────────────────────
if(FLASH_MSG){
  let t='success';
  const m=FLASH_MSG.toLowerCase();
  if(m.indexOf('error')!==-1) t='error';
  else if(m.indexOf('truncat')!==-1) t='warning';
  toast(FLASH_MSG, t);
  // strip msg from URL
  if(window.history && window.history.replaceState){
    const u=new URL(window.location.href);
    u.searchParams.delete('msg');
    window.history.replaceState({},'',u.toString());
  }
}
</script>
</body>
</html>
