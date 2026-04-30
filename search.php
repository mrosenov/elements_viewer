<?php
/**
 * search.php — Advanced Search for ElementsViewer
 *
 * AJAX endpoints (GET):
 *   ?action=get_lists&file=X          → JSON list of all lists in the file
 *   ?action=get_fields&file=X&list=Y  → JSON schema for a specific list
 *
 * AJAX endpoint (POST):
 *   ?action=run_search                → JSON search results
 *   Body: { file, list, conditions:[{field,operator,value}], logic:"AND"|"OR", limit }
 */

require __DIR__ . '/ElementsReader.php';

$dataDir   = realpath(__DIR__ . '/data');
$structDir = __DIR__ . '/structures';

// ---------------------------------------------------------------------------
// Helpers (mirrors index.php equivalents)
// ---------------------------------------------------------------------------

function s_list_data_files($dir) {
    $out = [];
    if ($dir && is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if (preg_match('/^elements.*\.data$/i', $f)) $out[] = $f;
        }
    }
    sort($out);
    return $out;
}

function s_structure_folder($dir, $v) { return $dir . '/' . $v; }

function s_load_structure($dir, $versionLabel) {
    $folder = s_structure_folder($dir, $versionLabel);
    $lists  = [];
    if (is_dir($folder)) {
        foreach (scandir($folder) as $f) {
            if (!preg_match('/^list_(\d+)\.json$/', $f, $m)) continue;
            $raw   = file_get_contents($folder . '/' . $f);
            $entry = json_decode($raw, true);
            if (is_array($entry)) $lists[(string)(int)$m[1]] = $entry;
        }
    }
    // Legacy single-file migration (read-only — index.php handles the write)
    $legacy = $dir . '/' . $versionLabel . '.json';
    if (empty($lists) && is_file($legacy)) {
        $data = json_decode(file_get_contents($legacy), true);
        if (is_array($data) && isset($data['lists'])) {
            foreach ($data['lists'] as $idx => $entry) {
                if (is_array($entry)) $lists[(string)(int)$idx] = $entry;
            }
        }
    }
    return ['version' => $versionLabel, 'lists' => $lists];
}

function s_format_value($v) {
    if (is_float($v)) return rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    return (string)$v;
}

/**
 * Evaluate a single condition against a decoded field value.
 * Returns true if the condition matches.
 */
function s_eval_condition($fieldValue, $operator, $condValue, $fieldType) {
    if (strncmp($fieldType, 'wstring:', 8) === 0) {
        $fv = mb_strtolower((string)$fieldValue, 'UTF-8');
        $cv = mb_strtolower((string)$condValue, 'UTF-8');
        switch ($operator) {
            case '=':         return $fv === $cv;
            case '!=':        return $fv !== $cv;
            case 'contains':  return strpos($fv, $cv) !== false;
            case '!contains': return strpos($fv, $cv) === false;
            case 'starts':    return substr($fv, 0, strlen($cv)) === $cv;
            case 'ends':      return $cv === '' || substr($fv, -strlen($cv)) === $cv;
            default:          return false;
        }
    } elseif ($fieldType === 'int32' || $fieldType === 'int64') {
        $fv = intval($fieldValue);
        $cv = intval($condValue);
        switch ($operator) {
            case '=':  return $fv === $cv;
            case '!=': return $fv !== $cv;
            case '>':  return $fv > $cv;
            case '>=': return $fv >= $cv;
            case '<':  return $fv < $cv;
            case '<=': return $fv <= $cv;
            default:   return false;
        }
    } elseif ($fieldType === 'float' || $fieldType === 'double') {
        $fv = floatval($fieldValue);
        $cv = floatval($condValue);
        switch ($operator) {
            case '=':  return abs($fv - $cv) < 1e-9;
            case '!=': return abs($fv - $cv) >= 1e-9;
            case '>':  return $fv > $cv;
            case '>=': return $fv >= $cv;
            case '<':  return $fv < $cv;
            case '<=': return $fv <= $cv;
            default:   return false;
        }
    } else { // byte:N
        switch ($operator) {
            case '=':  return (string)$fieldValue === (string)$condValue;
            case '!=': return (string)$fieldValue !== (string)$condValue;
            default:   return false;
        }
    }
}

// ---------------------------------------------------------------------------
// AJAX: get_lists  →  list of all lists in the file
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_lists') {
    header('Content-Type: application/json');
    $fileN = basename($_GET['file'] ?? '');
    $path  = $dataDir ? $dataDir . '/' . $fileN : '';
    if (!$dataDir || !is_file($path)) { echo json_encode(['error' => 'File not found']); exit; }
    try {
        $reader = (new ElementsReader($path))->scan();
    } catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); exit; }
    $struct = s_load_structure($structDir, $reader->getVersionLabel());
    $out = [];
    foreach ($reader->getLists() as $L) {
        $def = $struct['lists'][(string)$L['index']] ?? null;
        $out[] = [
            'index'      => $L['index'],
            'name'       => $def['name'] ?? ('LIST_' . $L['index']),
            'count'      => $L['count'],
            'has_schema' => ($def !== null && !empty($def['fields'])),
        ];
    }
    echo json_encode(['lists' => $out, 'version' => $reader->getVersionLabel()]);
    exit;
}

// ---------------------------------------------------------------------------
// AJAX: get_fields  →  schema + meta for one list
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_fields') {
    header('Content-Type: application/json');
    $fileN   = basename($_GET['file'] ?? '');
    $listIdx = (int)($_GET['list'] ?? -1);
    $path    = $dataDir ? $dataDir . '/' . $fileN : '';
    if (!$dataDir || !is_file($path)) { echo json_encode(['error' => 'File not found']); exit; }
    try {
        $reader = (new ElementsReader($path))->scan();
    } catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); exit; }
    $struct  = s_load_structure($structDir, $reader->getVersionLabel());
    $lists   = $reader->getLists();
    if ($listIdx < 0 || !isset($lists[$listIdx])) { echo json_encode(['error' => 'List not found']); exit; }
    $meta    = $lists[$listIdx];
    $def     = $struct['lists'][(string)$listIdx] ?? null;
    echo json_encode([
        'list_idx'   => $listIdx,
        'list_name'  => $def['name'] ?? ('LIST_' . $listIdx),
        'sizeof'     => $meta['sizeof'],
        'count'      => $meta['count'],
        'id_field'   => $def['id_field']   ?? '',
        'name_field' => $def['name_field'] ?? '',
        'fields'     => $def['fields']     ?? [],
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// AJAX: compare_records  →  decode two rows and return both for side-by-side diff
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
    try { $cmpRdr = (new ElementsReader($path))->scan(); }
    catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); exit; }
    $cmpSt  = s_load_structure($structDir, $cmpRdr->getVersionLabel());
    $cmpLs  = $cmpRdr->getLists();
    if ($listIdx < 0 || !isset($cmpLs[$listIdx])) { echo json_encode(['error' => 'List not found']); exit; }
    $cmpMt  = $cmpLs[$listIdx];
    $cmpDf  = $cmpSt['lists'][(string)$listIdx] ?? null;
    $cmpCnt = (int)$cmpMt['count'];
    if (!$cmpDf || empty($cmpDf['fields'])) { echo json_encode(['error' => 'No schema for this list']); exit; }
    if ($rowA >= $cmpCnt || $rowB >= $cmpCnt) {
        echo json_encode(['error' => "Row out of range (list has $cmpCnt records)"]); exit;
    }
    $cmpSch = $cmpDf['fields'];
    try {
        $dA = $cmpRdr->decodeList($listIdx, $cmpSch, 1, $rowA);
        $dB = $cmpRdr->decodeList($listIdx, $cmpSch, 1, $rowB);
    } catch (Throwable $e) { echo json_encode(['error' => 'Decode: ' . $e->getMessage()]); exit; }
    $fA = $fB = [];
    if (!empty($dA['rows'])) foreach ($dA['rows'][0] as $k => $v) $fA[$k] = s_format_value($v);
    if (!empty($dB['rows'])) foreach ($dB['rows'][0] as $k => $v) $fB[$k] = s_format_value($v);
    echo json_encode([
        'fields'   => $dA['fields'],
        'record_a' => ['row' => $rowA, 'data' => $fA],
        'record_b' => ['row' => $rowB, 'data' => $fB],
        'list_name' => $cmpDf['name'] ?? ('LIST_' . $listIdx),
        'list_idx'  => $listIdx,
        'id_field'  => $cmpDf['id_field']   ?? '',
        'name_field'=> $cmpDf['name_field'] ?? '',
        'count'     => $cmpCnt,
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// AJAX: run_search  →  execute multi-condition search, return matching rows
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'run_search') {
    header('Content-Type: application/json');
    @set_time_limit(120);
    @ini_set('memory_limit', '256M');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { echo json_encode(['error' => 'Invalid JSON body']); exit; }

    $fileN      = basename($body['file'] ?? '');
    $listIdx    = (int)($body['list'] ?? -1);
    $conditions = $body['conditions'] ?? [];
    $logic      = (($body['logic'] ?? 'AND') === 'OR') ? 'OR' : 'AND';
    $limit      = min(max(1, (int)($body['limit'] ?? 2000)), 10000);

    $path = $dataDir ? $dataDir . '/' . $fileN : '';
    if (!$dataDir || !is_file($path)) { echo json_encode(['error' => 'File not found']); exit; }
    try {
        $reader = (new ElementsReader($path))->scan();
    } catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); exit; }

    $struct  = s_load_structure($structDir, $reader->getVersionLabel());
    $lists   = $reader->getLists();
    if ($listIdx < 0 || !isset($lists[$listIdx])) { echo json_encode(['error' => 'Invalid list index']); exit; }

    $meta    = $lists[$listIdx];
    $def     = $struct['lists'][(string)$listIdx] ?? null;
    if (!$def || empty($def['fields'])) {
        echo json_encode(['error' => 'No schema defined for list_' . $listIdx . '. Open it in the main Viewer and save a schema first.']);
        exit;
    }

    $schema     = $def['fields'];
    $totalCount = (int)$meta['count'];

    // Build field-type lookup
    $fieldTypes = [];
    foreach ($schema as $f) $fieldTypes[$f['name']] = $f['type'];

    if (!is_array($conditions) || count($conditions) === 0) {
        echo json_encode(['error' => 'No conditions specified. Add at least one condition.']);
        exit;
    }

    // Validate conditions
    // Special field value '__any__' means "search across all fields"
    $validConds = [];
    foreach ($conditions as $c) {
        $fn = trim($c['field']    ?? '');
        $op = trim($c['operator'] ?? '');
        $cv = $c['value'] ?? '';
        if ($fn === '' || $op === '') continue;
        if ($fn !== '__any__' && !isset($fieldTypes[$fn])) continue;
        $validConds[] = [
            'field'    => $fn,
            'operator' => $op,
            'value'    => $cv,
            'type'     => ($fn === '__any__') ? '__any__' : $fieldTypes[$fn],
        ];
    }
    if (empty($validConds)) {
        echo json_encode(['error' => 'No valid conditions after validation. Check field names.']);
        exit;
    }

    // Scan all records in chunks of 2000
    $CHUNK   = 2000;
    $matched = [];
    $scanned = 0;
    $truncated = false;

    for ($off = 0; $off < $totalCount && !$truncated; $off += $CHUNK) {
        try {
            $decoded = $reader->decodeList($listIdx, $schema, min($CHUNK, $totalCount - $off), $off);
        } catch (Throwable $e) {
            echo json_encode(['error' => 'Decode error at offset ' . $off . ': ' . $e->getMessage()]);
            exit;
        }

        foreach ($decoded['rows'] as $ri => $row) {
            $rowIdx = $off + $ri;
            $scanned++;

            // Evaluate conditions
            $rowMatch = ($logic === 'AND'); // AND=all must match, OR=any must match
            foreach ($validConds as $cond) {
                if ($cond['field'] === '__any__') {
                    // "Any field" — check every field in the record until one matches
                    $hit = false;
                    foreach ($row as $fn => $fv) {
                        $ft = $fieldTypes[$fn] ?? 'int32';
                        // For any-field, map the operator:
                        //   contains / !contains → string substring on formatted value
                        //   =  / !=              → exact string match on formatted value
                        //   >  / >= / < / <=     → numeric comparison (skip non-numeric fields)
                        $op = $cond['operator'];
                        $cv = $cond['value'];
                        if (in_array($op, ['>', '>=', '<', '<='], true)) {
                            // Only compare numeric fields
                            if (!in_array($ft, ['int32','int64','float','double'], true)) continue;
                            if (s_eval_condition($fv, $op, $cv, $ft)) { $hit = true; break; }
                        } else {
                            // For = / != / contains / !contains: compare against string representation
                            $strVal = s_format_value($fv);
                            if (s_eval_condition($strVal, $op, $cv, 'wstring:1')) { $hit = true; break; }
                        }
                    }
                } else {
                    $fv = $row[$cond['field']] ?? null;
                    if ($fv === null) {
                        if ($logic === 'AND') { $rowMatch = false; break; }
                        continue;
                    }
                    $hit = s_eval_condition($fv, $cond['operator'], $cond['value'], $cond['type']);
                }
                if ($logic === 'AND') {
                    if (!$hit) { $rowMatch = false; break; }
                } else {
                    if ($hit) { $rowMatch = true; break; }
                }
            }

            if ($rowMatch) {
                // Format all values as strings for JSON transport
                $fmt = [];
                foreach ($row as $k => $v) $fmt[$k] = s_format_value($v);
                $matched[] = ['row' => $rowIdx, 'data' => $fmt];
                if (count($matched) >= $limit) { $truncated = true; break; }
            }
        }
    }

    echo json_encode([
        'matched'    => $matched,
        'count'      => count($matched),
        'scanned'    => $scanned,
        'total'      => $totalCount,
        'truncated'  => $truncated,
        'fields'     => $schema,
        'id_field'   => $def['id_field']   ?? '',
        'name_field' => $def['name_field'] ?? '',
        'list_name'  => $def['name'] ?? ('LIST_' . $listIdx),
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------
$dataFiles        = s_list_data_files($dataDir);
$fileName         = basename($_GET['file'] ?? '');
if ($fileName === '' && !empty($dataFiles)) $fileName = $dataFiles[0];
$preselectedList  = isset($_GET['list']) ? (int)$_GET['list'] : -1;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>ElementsViewer — Advanced Search</title>
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
  .dd-open .dd-menu{display:block!important}
  .cond-row:hover .cond-remove{opacity:1}
  .cond-remove{opacity:0;transition:opacity .15s}
  .result-row:hover td{background:rgba(168,85,247,0.06)!important}
  .result-row td:first-child{position:sticky;left:0;background:#0d1117;z-index:1}
  .result-row:hover td:first-child{background:#0f1420!important}
  .col-hidden{display:none!important}
  .spin{animation:spin 1s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body class="flex flex-col h-screen">

<!-- ░░ TOAST CONTAINER ░░ -->
<div id="toast-ct" class="fixed top-3 right-3 z-[200] flex flex-col gap-2 pointer-events-none"></div>

<!-- ░░ TOPBAR ░░ -->
<header class="h-11 shrink-0 bg-[#0d1117] border-b border-slate-800 flex items-center px-4 gap-3 z-50 relative">
  <!-- Logo -->
  <div class="flex items-center gap-2 mr-1">
    <div class="w-5 h-5 rounded bg-purple-500/15 border border-purple-500/40 flex items-center justify-center">
      <svg class="w-3 h-3 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
    </div>
    <span class="mono font-semibold text-[13px] text-slate-100 tracking-tight">ElementsViewer</span>
    <span class="mono text-[11px] text-slate-400 border border-purple-800/50 bg-purple-950/30 rounded px-1.5">Advanced Search</span>
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
      <a href="index.php<?= $fileName ? '?file='.urlencode($fileName) : '' ?>"
         class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-cyan-400 transition-colors">
        <div class="w-4 h-4 rounded bg-cyan-500/15 border border-cyan-500/40 flex items-center justify-center shrink-0">
          <div class="w-1.5 h-1.5 rounded-sm bg-cyan-400"></div>
        </div>
        Elements Viewer
      </a>
      <a href="search.php<?= $fileName ? '?file='.urlencode($fileName) : '' ?>"
         class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-purple-400 bg-purple-950/20 hover:bg-slate-800 transition-colors">
        <div class="w-4 h-4 rounded bg-purple-500/15 border border-purple-500/40 flex items-center justify-center shrink-0">
          <svg class="w-2.5 h-2.5 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>
        Advanced Search
        <span class="ml-auto text-purple-700 text-[9px] mono uppercase tracking-widest">current</span>
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
    </div>
  </div>
  <div class="w-px h-4 bg-slate-800"></div>

  <!-- File picker -->
  <div class="relative" id="dd-file">
    <button type="button" onclick="toggleDD('dd-file')"
      class="flex items-center gap-2 px-2.5 py-1 rounded border text-[11px] mono bg-slate-800/50 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors">
      <span class="text-slate-600">file</span>
      <span id="file-label" class="text-cyan-400 font-semibold"><?= h($fileName ?: '(none)') ?></span>
      <svg class="w-3 h-3 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div class="dd-menu hidden absolute top-full mt-1 left-0 bg-slate-900 border border-slate-700 rounded-md shadow-2xl z-50 min-w-[14rem] py-0.5">
      <?php if (empty($dataFiles)): ?>
        <div class="px-3 py-1.5 text-[11px] mono text-slate-500">no files in data/</div>
      <?php else: foreach ($dataFiles as $df): ?>
        <a href="#" data-file="<?= h($df) ?>" onclick="selectFile(this.dataset.file);return false;"
           class="block px-3 py-1.5 text-[11px] mono <?= $df===$fileName?'text-cyan-400':'text-slate-300' ?> hover:bg-slate-800 hover:text-cyan-400"><?= h($df) ?></a>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <span id="version-badge" class="hidden px-1.5 py-0.5 rounded text-[11px] mono border bg-purple-950 text-purple-300 border-purple-800"></span>

  <!-- Column visibility button -->
  <div class="relative" id="dd-cols">
    <button type="button" id="col-toggle-btn" onclick="toggleDD('dd-cols')"
      class="hidden items-center gap-1.5 px-2.5 py-1 rounded border text-[11px] mono bg-slate-800/50 hover:bg-slate-800 border-slate-700/50 text-slate-400 transition-colors">
      <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7"/></svg>
      Columns
    </button>
    <div class="dd-menu hidden absolute top-full mt-1 right-0 bg-slate-900 border border-slate-700 rounded-md shadow-2xl z-50 w-64 max-h-80 overflow-y-auto py-1" id="col-dd-body">
    </div>
  </div>

  <!-- Back to viewer -->
  <a id="back-link" href="index.php<?= $fileName ? '?file='.urlencode($fileName) : '' ?>"
     class="ml-auto flex items-center gap-1.5 px-2.5 py-1 rounded border text-[11px] mono uppercase tracking-widest bg-slate-800/40 hover:bg-slate-800 border-slate-700/40 hover:border-slate-600 text-slate-300 transition-colors">
    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    Viewer
  </a>
</header>

<!-- ░░ SEARCH BUILDER ░░ -->
<div class="shrink-0 bg-[#0d1117] border-b border-slate-800 px-4 py-3 space-y-2.5">

  <!-- Control row -->
  <div class="flex items-center gap-2.5 flex-wrap">

    <!-- "Search in" label + list dropdown -->
    <span class="mono text-[11px] text-slate-500">Search in</span>
    <div class="relative" id="dd-list">
      <button type="button" onclick="toggleDD('dd-list')"
        class="flex items-center gap-2 px-2.5 py-1.5 rounded border text-[11px] mono bg-slate-800/50 hover:bg-slate-800 border-slate-700/50 text-slate-300 min-w-[220px] justify-between transition-colors">
        <span id="dd-list-label" class="text-purple-300 font-semibold truncate">— select a list —</span>
        <svg class="w-3 h-3 text-slate-600 shrink-0 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
      </button>
      <div class="dd-menu hidden absolute top-full mt-0.5 left-0 bg-slate-900 border border-slate-700 rounded-md shadow-2xl z-40 w-80">
        <div class="p-1.5 border-b border-slate-800">
          <input id="list-filter-input" oninput="filterListDD(this.value)" placeholder="filter lists…"
            class="w-full bg-slate-800 border border-slate-700 focus:border-purple-700 rounded px-2 py-1 text-[11px] mono text-slate-300 placeholder-slate-600 outline-none"/>
        </div>
        <div id="list-dd-options" class="max-h-64 overflow-y-auto pb-0.5">
          <div class="px-3 py-2 text-[11px] mono text-slate-600">Select a file first…</div>
        </div>
      </div>
    </div>

    <span class="text-slate-700">·</span>

    <!-- Logic toggle -->
    <div class="flex rounded overflow-hidden border border-slate-700 text-[11px] mono shrink-0">
      <button type="button" id="btn-and" onclick="setLogic('AND')"
        class="px-3 py-1 bg-cyan-950/60 text-cyan-300 border-r border-slate-700 transition-colors">AND</button>
      <button type="button" id="btn-or"  onclick="setLogic('OR')"
        class="px-3 py-1 text-slate-500 hover:text-slate-300 transition-colors">OR</button>
    </div>

    <!-- Add condition -->
    <button type="button" id="btn-add-cond" onclick="addCondition()" disabled
      class="flex items-center gap-1.5 px-2.5 py-1.5 rounded border text-[11px] mono bg-slate-800/40 hover:bg-slate-800 border-slate-700/40 hover:border-slate-600 text-slate-400 hover:text-slate-200 transition-colors disabled:opacity-30 disabled:cursor-not-allowed">
      <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
      Add Condition
    </button>

    <!-- Run search -->
    <button type="button" id="btn-run" onclick="runSearch()" disabled
      class="flex items-center gap-1.5 px-4 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-purple-950/40 hover:bg-purple-950/70 border-purple-800/50 hover:border-purple-700 text-purple-300 transition-colors disabled:opacity-30 disabled:cursor-not-allowed">
      <svg class="w-3 h-3" id="run-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      Search
    </button>

    <!-- Clear -->
    <button type="button" onclick="clearAll()"
      class="flex items-center gap-1.5 px-2.5 py-1.5 rounded border text-[11px] mono bg-slate-800/20 hover:bg-slate-800 border-slate-700/20 hover:border-slate-600 text-slate-600 hover:text-slate-400 transition-colors">
      <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      Clear
    </button>

    <!-- List info -->
    <span id="list-info" class="mono text-[11px] text-slate-600"></span>
  </div>

  <!-- Conditions rows -->
  <div id="conditions-wrap" class="space-y-1.5"></div>
</div>

<!-- ░░ RESULTS AREA ░░ -->
<div class="flex-1 overflow-hidden flex flex-col min-h-0">

  <!-- Status bar -->
  <div id="results-status" class="hidden shrink-0 px-4 py-1.5 bg-[#090c12] border-b border-slate-800 flex items-center gap-3 text-[11px] mono flex-wrap"></div>

  <!-- Compare selection bar (appears when 1–2 rows are checked) -->
  <div id="cmp-sel-bar" class="hidden shrink-0 px-4 py-1.5 bg-purple-950/20 border-b border-purple-900/40 flex items-center gap-3 text-[11px] mono">
    <svg class="w-3.5 h-3.5 text-purple-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 0a2 2 0 012-2h2a2 2 0 012 2v10a2 2 0 01-2 2h-2a2 2 0 01-2-2"/></svg>
    <span id="cmp-sel-text" class="text-purple-300"></span>
    <button type="button" id="cmp-sel-btn" onclick="openSearchCompare()" disabled
      class="flex items-center gap-1.5 px-3 py-1 rounded border text-[10px] mono uppercase tracking-widest bg-purple-950/50 hover:bg-purple-950/80 border-purple-800/50 hover:border-purple-700 text-purple-300 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
      Compare
    </button>
    <button type="button" onclick="clearCmpSel()" class="text-slate-600 hover:text-slate-400 text-[10px] mono transition-colors">clear</button>
  </div>

  <!-- Loading -->
  <div id="loading" class="hidden flex-1 flex items-center justify-center">
    <div class="flex flex-col items-center gap-3">
      <svg class="w-7 h-7 text-purple-500 spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
      <span class="text-[11px] mono text-slate-500">Searching records…</span>
    </div>
  </div>

  <!-- Empty state -->
  <div id="empty-state" class="flex-1 flex items-center justify-center">
    <div class="text-center select-none">
      <div class="w-16 h-16 rounded-full bg-purple-950/20 border border-purple-900/30 flex items-center justify-center mx-auto mb-4">
        <svg class="w-7 h-7 text-purple-800" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      </div>
      <p class="text-[13px] mono text-slate-600">Select a list, build conditions, hit Search</p>
      <p class="text-[11px] mono text-slate-700 mt-1.5">e.g. list_3 where <span class="text-purple-700">proc_type</span> <span class="text-cyan-800">&gt;</span> <span class="text-emerald-800">1</span></p>
    </div>
  </div>

  <!-- No results -->
  <div id="no-results" class="hidden flex-1 flex items-center justify-center">
    <div class="text-center">
      <p class="text-[13px] mono text-slate-500">No records matched the conditions.</p>
      <p class="text-[11px] mono text-slate-600 mt-1">Try relaxing the conditions or switching AND → OR.</p>
    </div>
  </div>

  <!-- Results table -->
  <div id="results-wrap" class="hidden flex-1 overflow-auto">
    <table class="text-[11px] mono w-max min-w-full border-collapse">
      <thead id="results-thead" class="sticky top-0 z-10 bg-[#0d1117] border-b border-slate-700 text-slate-500 text-[10px] uppercase tracking-widest"></thead>
      <tbody id="results-tbody" class="divide-y divide-slate-800/40"></tbody>
    </table>
  </div>
</div>

<!-- ░░ COMPARE RECORDS MODAL (search.php) ░░ -->
<div id="cmp-modal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-[150] items-center justify-center p-4"
     onclick="if(event.target===this)closeCmpModal()">
  <div class="bg-[#0d1117] border border-slate-700 rounded-md shadow-2xl w-full max-w-6xl flex flex-col max-h-[92vh]">

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

    <div class="px-4 py-2.5 border-b border-slate-800 flex items-center gap-3 flex-wrap shrink-0 bg-[#090c12]">
      <div class="flex items-center gap-2">
        <span class="text-[11px] mono text-yellow-400 font-semibold">A</span>
        <span class="text-[11px] mono text-slate-400">Record #</span>
        <input type="number" id="cmp-row-a" min="0" value="0"
          class="bg-slate-900 border border-slate-700 focus:border-purple-600 text-slate-200 rounded px-2 py-1 text-[11px] mono outline-none w-24"/>
      </div>
      <span class="text-slate-600 mono text-[11px]">vs</span>
      <div class="flex items-center gap-2">
        <span class="text-[11px] mono text-cyan-400 font-semibold">B</span>
        <span class="text-[11px] mono text-slate-400">Record #</span>
        <input type="number" id="cmp-row-b" min="0" value="1"
          class="bg-slate-900 border border-slate-700 focus:border-purple-600 text-slate-200 rounded px-2 py-1 text-[11px] mono outline-none w-24"/>
      </div>
      <button type="button" onclick="runCmp()"
        class="flex items-center gap-1.5 px-3 py-1 rounded border text-[11px] mono uppercase tracking-widest bg-purple-950/40 hover:bg-purple-950/70 border-purple-800/50 hover:border-purple-700 text-purple-300 transition-colors">
        Compare
      </button>
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

    <div class="flex-1 overflow-auto">
      <div id="cmp-empty" class="p-8 text-center">
        <p class="text-[12px] mono text-slate-500">Loading…</p>
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

    <div class="px-4 py-2.5 border-t border-slate-800 flex items-center gap-4 text-[10px] mono shrink-0 bg-[#090c12]">
      <span id="cmp-stats" class="text-slate-500"></span>
      <div class="flex items-center gap-3 ml-auto">
        <span class="flex items-center gap-1.5"><span class="inline-block w-2.5 h-2.5 rounded-sm bg-orange-950 border border-orange-800"></span>changed</span>
        <span class="flex items-center gap-1.5"><span class="inline-block w-2.5 h-2.5 rounded-sm bg-slate-800/60 border border-slate-700/40"></span>same</span>
        <button type="button" onclick="closeCmpModal()"
          class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-slate-800/60 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors">
          Close
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ░░ JAVASCRIPT ░░ -->
<script>
// =============================================================================
// Compare state (shared across both pages via this file)
// =============================================================================
let _cmpData   = null;
let _cmpFilter = 'all';

// =============================================================================
// State
// =============================================================================
let STATE = {
  file:        <?= json_encode($fileName) ?>,
  listIdx:     <?= json_encode($preselectedList >= 0 ? $preselectedList : null) ?>,
  listName:    '',
  listCount:   0,
  fields:      [],   // [{name, type}]
  idField:     '',
  nameField:   '',
  logic:       'AND',
  conditions:  [],   // [{id, field, operator, value}]
  condIdSeq:   0,
  // Column visibility: Set of hidden field names
  hiddenCols:    new Set(),
  lastResults:   null,   // last search response
  cmpSelected:   new Set(), // row indices selected for comparison (max 2)
};

// Operator definitions
const NUM_OPS = [
  { v: '=',  l: '= equals'           },
  { v: '!=', l: '≠ not equals'       },
  { v: '>',  l: '> greater than'     },
  { v: '>=', l: '≥ ≥ or equal'       },
  { v: '<',  l: '< less than'        },
  { v: '<=', l: '≤ ≤ or equal'       },
];
const STR_OPS = [
  { v: 'contains',  l: 'contains'         },
  { v: '!contains', l: 'does not contain' },
  { v: '=',         l: '= exact match'    },
  { v: '!=',        l: '≠ not equal'      },
  { v: 'starts',    l: 'starts with'      },
  { v: 'ends',      l: 'ends with'        },
];
const BYTE_OPS = [
  { v: '=',  l: '= equals'   },
  { v: '!=', l: '≠ not equal'},
];
// Operators available when "Any Field" is selected.
// > / >= / < / <= only test numeric fields; = / != / contains test string repr.
const ANY_OPS = [
  { v: 'contains',  l: 'any field contains'     },
  { v: '!contains', l: 'no field contains'       },
  { v: '=',         l: 'any field = (exact)'     },
  { v: '!=',        l: 'no field = (exact)'      },
  { v: '>',         l: 'any numeric field >'     },
  { v: '>=',        l: 'any numeric field ≥'     },
  { v: '<',         l: 'any numeric field <'     },
  { v: '<=',        l: 'any numeric field ≤'     },
];

function getOps(type) {
  if (type === '__any__')              return ANY_OPS;
  if (type && type.startsWith('wstring:')) return STR_OPS;
  if (type && type.startsWith('byte:'))    return BYTE_OPS;
  return NUM_OPS;
}

// =============================================================================
// Dropdown helper
// =============================================================================
function toggleDD(id) {
  const el = document.getElementById(id);
  const open = el.classList.toggle('dd-open');
  if (open) {
    const closeOther = (e) => {
      if (!el.contains(e.target)) {
        el.classList.remove('dd-open');
        document.removeEventListener('click', closeOther);
      }
    };
    setTimeout(() => document.addEventListener('click', closeOther), 0);
  }
}
function closeDD(id) {
  document.getElementById(id)?.classList.remove('dd-open');
}

// =============================================================================
// File selection
// =============================================================================
function selectFile(file) {
  closeDD('dd-file');
  document.getElementById('file-label').textContent = file;
  document.getElementById('back-link').href = 'index.php?file=' + encodeURIComponent(file);
  STATE.file    = file;
  STATE.listIdx = null;
  STATE.fields  = [];
  STATE.conditions = [];
  STATE.hiddenCols = new Set();
  clearResults();
  renderConditions();
  document.getElementById('list-info').textContent = '';
  document.getElementById('dd-list-label').textContent = '— select a list —';
  document.getElementById('version-badge').classList.add('hidden');
  document.getElementById('btn-add-cond').disabled = true;
  document.getElementById('btn-run').disabled      = true;
  loadLists(file);
}

async function loadLists(file) {
  const opts = document.getElementById('list-dd-options');
  opts.innerHTML = '<div class="px-3 py-2 text-[11px] mono text-slate-600">Loading…</div>';
  try {
    const res  = await fetch('search.php?action=get_lists&file=' + encodeURIComponent(file));
    const data = await res.json();
    if (data.error) { opts.innerHTML = `<div class="px-3 py-2 text-[11px] mono text-red-400">${esc(data.error)}</div>`; return; }

    const badge = document.getElementById('version-badge');
    badge.textContent = data.version;
    badge.classList.remove('hidden');

    opts.dataset.lists = JSON.stringify(data.lists);
    renderListOptions(data.lists, '');
  } catch(e) {
    opts.innerHTML = '<div class="px-3 py-2 text-[11px] mono text-red-400">Network error</div>';
  }
}

function renderListOptions(lists, filter) {
  const opts  = document.getElementById('list-dd-options');
  const lower = filter.toLowerCase();
  let html    = '';
  for (const L of lists) {
    const label = `#${L.index} · ${L.name}`;
    if (lower && !label.toLowerCase().includes(lower)) continue;
    const noSchema = !L.has_schema;
    html += `<a href="#" data-idx="${L.index}" onclick="selectList(${L.index});return false;"
      class="flex items-center justify-between px-3 py-1.5 text-[11px] mono ${L.index===STATE.listIdx?'text-purple-300':'text-slate-300'} hover:bg-slate-800 hover:text-purple-300 gap-2 ${noSchema?'opacity-50':''}">
      <span class="truncate">${esc(label)}</span>
      <span class="shrink-0 text-[10px] text-slate-600">${L.count.toLocaleString()}</span>
      ${noSchema?'<span class="shrink-0 text-[9px] border border-slate-700 rounded px-1 text-slate-600">no schema</span>':''}
    </a>`;
  }
  opts.innerHTML = html || '<div class="px-3 py-2 text-[11px] mono text-slate-600">no matches</div>';
}

function filterListDD(q) {
  const opts  = document.getElementById('list-dd-options');
  const lists = JSON.parse(opts.dataset.lists || '[]');
  renderListOptions(lists, q);
}

// =============================================================================
// List selection
// =============================================================================
async function selectList(idx) {
  closeDD('dd-list');
  STATE.listIdx    = idx;
  STATE.conditions = [];
  STATE.hiddenCols = new Set();
  clearResults();
  renderConditions();
  document.getElementById('dd-list-label').textContent = `#${idx} — loading…`;
  document.getElementById('list-info').textContent     = '';
  document.getElementById('btn-add-cond').disabled     = true;
  document.getElementById('btn-run').disabled          = true;

  try {
    const res  = await fetch(`search.php?action=get_fields&file=${encodeURIComponent(STATE.file)}&list=${idx}`);
    const data = await res.json();
    if (data.error) { toast(data.error, 'error'); document.getElementById('dd-list-label').textContent = '— error —'; return; }

    STATE.fields    = data.fields;
    STATE.idField   = data.id_field;
    STATE.nameField = data.name_field;
    STATE.listName  = data.list_name;
    STATE.listCount = data.count;

    document.getElementById('dd-list-label').textContent = `#${idx} · ${data.list_name}`;
    document.getElementById('list-info').textContent =
      `${data.count.toLocaleString()} records · ${data.fields.length} fields · ${data.sizeof}B/record`;

    document.getElementById('btn-add-cond').disabled = (data.fields.length === 0);
    if (data.fields.length === 0) {
      toast('This list has no schema yet. Open it in the Viewer to add one.', 'warning');
    }
  } catch(e) {
    toast('Network error loading fields', 'error');
    document.getElementById('dd-list-label').textContent = '— error —';
  }
}

// =============================================================================
// Logic toggle
// =============================================================================
function setLogic(v) {
  STATE.logic = v;
  document.getElementById('btn-and').className =
    v === 'AND'
      ? 'px-3 py-1 bg-cyan-950/60 text-cyan-300 border-r border-slate-700 transition-colors'
      : 'px-3 py-1 text-slate-500 hover:text-slate-300 border-r border-slate-700 transition-colors';
  document.getElementById('btn-or').className =
    v === 'OR'
      ? 'px-3 py-1 bg-orange-950/50 text-orange-300 transition-colors'
      : 'px-3 py-1 text-slate-500 hover:text-slate-300 transition-colors';
}

// =============================================================================
// Conditions builder
// =============================================================================
function addCondition() {
  if (!STATE.fields.length) return;
  const id = ++STATE.condIdSeq;
  // Default new conditions to "Any Field / contains" for quick global searches
  STATE.conditions.push({
    id, field: '__any__', operator: 'contains', value: ''
  });
  renderConditions();
  // Focus the value input of the new row
  setTimeout(() => {
    const inp = document.querySelector(`#cond-val-${id}`);
    if (inp) inp.focus();
  }, 50);
}

function removeCondition(id) {
  STATE.conditions = STATE.conditions.filter(c => c.id !== id);
  renderConditions();
  updateRunBtn();
}

function onCondFieldChange(id, val) {
  const cond = STATE.conditions.find(c => c.id === id);
  if (!cond) return;
  cond.field    = val;
  // Reset operator to first valid one for the new type
  const isAny   = (val === '__any__');
  const ft      = isAny ? null : STATE.fields.find(f => f.name === val);
  const ops     = getOps(isAny ? '__any__' : (ft ? ft.type : ''));
  cond.operator = ops[0].v;
  // Re-render the operator select
  const opSel = document.getElementById(`cond-op-${id}`);
  if (opSel) {
    opSel.innerHTML = ops.map(o => `<option value="${o.v}">${esc(o.l)}</option>`).join('');
    opSel.value = cond.operator;
  }
  // Update type badge
  const badge = document.getElementById(`cond-type-${id}`);
  if (badge) {
    if (isAny) {
      badge.textContent = 'any';
      badge.className   = 'bg-slate-800 text-slate-400 border-slate-600 px-1.5 py-0.5 rounded text-[10px] mono border shrink-0';
    } else {
      badge.textContent = ft ? ft.type : '';
      badge.className   = typeBadgeClass(ft ? ft.type : '') + ' px-1.5 py-0.5 rounded text-[10px] mono border shrink-0';
    }
  }
}

function onCondOpChange(id, val) {
  const cond = STATE.conditions.find(c => c.id === id);
  if (cond) cond.operator = val;
}

function onCondValChange(id, val) {
  const cond = STATE.conditions.find(c => c.id === id);
  if (cond) { cond.value = val; updateRunBtn(); }
}

function updateRunBtn() {
  const hasValid = STATE.conditions.some(c => c.value.trim() !== '' || c.operator === '=' || c.operator === '!=');
  document.getElementById('btn-run').disabled = !(STATE.fields.length > 0 && STATE.conditions.length > 0);
}

function typeBadgeClass(t) {
  if (!t) return 'bg-slate-800 text-slate-500 border-slate-700';
  if (t.startsWith('int'))    return 'bg-blue-950 text-blue-300 border-blue-800';
  if (t==='float'||t==='double') return 'bg-purple-950 text-purple-300 border-purple-800';
  if (t.startsWith('wstring:')) return 'bg-emerald-950 text-emerald-300 border-emerald-800';
  if (t.startsWith('byte:'))    return 'bg-orange-950 text-orange-300 border-orange-800';
  return 'bg-slate-800 text-slate-500 border-slate-700';
}

function renderConditions() {
  const wrap = document.getElementById('conditions-wrap');
  if (!STATE.conditions.length) {
    wrap.innerHTML = '';
    document.getElementById('btn-run').disabled = true;
    return;
  }

  const fieldOptions =
    `<option value="__any__">— Any Field —</option>` +
    `<option disabled>──────────────</option>` +
    STATE.fields.map(f =>
      `<option value="${esc(f.name)}">${esc(f.name)}</option>`
    ).join('');

  let html = '';
  STATE.conditions.forEach((cond, i) => {
    const isAny = (cond.field === '__any__');
    const ft    = isAny ? null : STATE.fields.find(f => f.name === cond.field);
    const ops   = getOps(isAny ? '__any__' : (ft ? ft.type : ''));
    const opOptions = ops.map(o =>
      `<option value="${o.v}" ${o.v===cond.operator?'selected':''}>${esc(o.l)}</option>`
    ).join('');

    const isStr = ft && ft.type.startsWith('wstring:');
    const inputType = isStr ? 'text' : 'text';

    // Logic pill between conditions
    const logicPill = i > 0
      ? `<div class="flex items-center pl-1 pt-0.5">
           <span class="text-[10px] mono px-2 py-0.5 rounded border ${STATE.logic==='AND'?'bg-cyan-950/40 text-cyan-600 border-cyan-900':'bg-orange-950/40 text-orange-600 border-orange-900'}">${STATE.logic}</span>
         </div>`
      : '';

    html += `
      ${logicPill}
      <div class="cond-row flex items-center gap-2 group" data-id="${cond.id}">
        <span class="text-[10px] mono text-slate-700 w-4 text-right shrink-0">${i+1}</span>

        <!-- Field selector -->
        <select id="cond-field-${cond.id}" onchange="onCondFieldChange(${cond.id},this.value)"
          class="bg-slate-900 border border-slate-700 hover:border-purple-700 focus:border-purple-600 text-slate-200 rounded px-2 py-1 text-[11px] mono outline-none transition-colors max-w-[200px]">
          ${fieldOptions}
        </select>

        <!-- Type badge -->
        <span id="cond-type-${cond.id}" class="${isAny ? 'bg-slate-800 text-slate-400 border-slate-600' : typeBadgeClass(ft?ft.type:'')} px-1.5 py-0.5 rounded text-[10px] mono border shrink-0">
          ${isAny ? 'any' : esc(ft ? ft.type : '')}
        </span>

        <!-- Operator selector -->
        <select id="cond-op-${cond.id}" onchange="onCondOpChange(${cond.id},this.value)"
          class="bg-slate-900 border border-slate-700 hover:border-purple-700 focus:border-purple-600 text-slate-200 rounded px-2 py-1 text-[11px] mono outline-none transition-colors">
          ${opOptions}
        </select>

        <!-- Value input -->
        <input type="${inputType}" id="cond-val-${cond.id}" value="${esc(cond.value)}"
          oninput="onCondValChange(${cond.id},this.value)"
          onkeydown="if(event.key==='Enter')runSearch()"
          placeholder="${isStr ? 'text…' : '0'}"
          class="bg-slate-900 border border-slate-700 hover:border-purple-700 focus:border-purple-600 text-slate-200 rounded px-2 py-1 text-[11px] mono outline-none placeholder-slate-700 w-36 transition-colors"/>

        <!-- Remove button -->
        <button type="button" onclick="removeCondition(${cond.id})" class="cond-remove w-5 h-5 flex items-center justify-center rounded text-slate-600 hover:text-red-400 hover:bg-red-950/30 transition-colors shrink-0">
          <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>`;
  });

  wrap.innerHTML = html;

  // Restore selected values in <select> elements
  STATE.conditions.forEach(cond => {
    const fSel = document.getElementById(`cond-field-${cond.id}`);
    if (fSel) fSel.value = cond.field;
    const oSel = document.getElementById(`cond-op-${cond.id}`);
    if (oSel) oSel.value = cond.operator;
  });

  updateRunBtn();
}

// =============================================================================
// Search execution
// =============================================================================
async function runSearch() {
  if (!STATE.fields.length || !STATE.conditions.length) return;
  if (document.getElementById('btn-run').disabled) return;

  showLoading(true);
  clearResults();

  const payload = {
    file:       STATE.file,
    list:       STATE.listIdx,
    conditions: STATE.conditions.map(c => ({
      field:    c.field,
      operator: c.operator,
      value:    c.value,
    })),
    logic:  STATE.logic,
    limit:  2000,
  };

  try {
    const res  = await fetch('search.php?action=run_search', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    });
    const data = await res.json();
    showLoading(false);
    if (data.error) { toast(data.error, 'error'); showEmpty(); return; }
    STATE.lastResults = data;
    renderResults(data);
  } catch(e) {
    showLoading(false);
    toast('Network error: ' + e.message, 'error');
    showEmpty();
  }
}

// =============================================================================
// Results rendering
// =============================================================================
function renderResults(data) {
  if (!data.matched || data.matched.length === 0) {
    showNoResults();
    renderStatusBar(data);
    return;
  }

  renderStatusBar(data);
  renderResultsTable(data);
  buildColumnToggle(data.fields);
  document.getElementById('col-toggle-btn').classList.remove('hidden');
  document.getElementById('col-toggle-btn').classList.add('flex');
}

function renderStatusBar(data) {
  const bar = document.getElementById('results-status');
  bar.classList.remove('hidden');

  const truncWarn = data.truncated
    ? `<span class="text-yellow-400">⚠ Showing first 2,000 of more matches — refine conditions to narrow down</span>`
    : '';

  bar.innerHTML = `
    <span class="text-purple-400 font-semibold">${data.count.toLocaleString()} match${data.count===1?'':'es'}</span>
    <span class="text-slate-600">in</span>
    <span class="text-slate-300">${esc(STATE.listName)}</span>
    <span class="text-slate-600">·</span>
    <span class="text-slate-500">scanned ${data.scanned.toLocaleString()} / ${data.total.toLocaleString()} records</span>
    ${truncWarn}
    <span class="ml-auto flex items-center gap-2">
      <span class="text-[10px] text-slate-600">Click a row to open in Viewer</span>
      <div class="relative" id="exp-res-wrap">
        <button type="button" onclick="toggleResExportMenu(event)"
          class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] mono border bg-emerald-950 text-emerald-300 border-emerald-700 hover:bg-emerald-900 transition-colors select-none cursor-pointer">
          <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 4v11"/></svg>
          Export
          <svg class="w-2 h-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div id="exp-res-menu"
          class="hidden absolute right-0 top-full mt-1 z-50 bg-[#0d1117] border border-slate-700 rounded shadow-xl min-w-[120px] py-1">
          <button type="button" onclick="exportResultsCSV()" class="w-full text-left flex items-center gap-2 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-emerald-300 transition-colors">
            <span class="text-emerald-500 font-bold">CSV</span> comma-separated
          </button>
          <button type="button" onclick="exportResultsTSV()" class="w-full text-left flex items-center gap-2 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-emerald-300 transition-colors">
            <span class="text-cyan-500 font-bold">TSV</span> tab-separated
          </button>
          <button type="button" onclick="exportResultsJSON()" class="w-full text-left flex items-center gap-2 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-emerald-300 transition-colors">
            <span class="text-purple-400 font-bold">JSON</span> object array
          </button>
        </div>
      </div>
    </span>
  `;
}

function toggleResExportMenu(e) {
  e.stopPropagation();
  const menu = document.getElementById('exp-res-menu');
  if (menu) menu.classList.toggle('hidden');
}
document.addEventListener('click', function() {
  const m = document.getElementById('exp-res-menu');
  if (m) m.classList.add('hidden');
});

function _buildExportData(sep) {
  const d = STATE.lastResults;
  if (!d || !d.matched || d.matched.length === 0) { toast('No results to export', 'warning'); return null; }
  const nl = '\r\n';
  const cellFn = sep === '\t'
    ? v => String(v).replace(/[\t\r\n]/g, ' ')
    : v => { const s = String(v); return (s.includes(',') || s.includes('"') || s.includes('\n') || s.includes('\r')) ? '"' + s.replace(/"/g, '""') + '"' : s; };
  let out = d.fields.map(f => cellFn(f.name)).join(sep) + nl;
  for (const rec of d.matched) {
    out += d.fields.map(f => cellFn(rec.data[f.name] ?? '')).join(sep) + nl;
  }
  return out;
}

function _triggerDownload(content, filename, mime) {
  const blob = new Blob([content], { type: mime });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  setTimeout(() => URL.revokeObjectURL(url), 5000);
  const m = document.getElementById('exp-res-menu');
  if (m) m.classList.add('hidden');
}

function exportResultsCSV() {
  const csv = _buildExportData(',');
  if (!csv) return;
  const name = (STATE.listName || 'results').replace(/[^a-z0-9_]/gi, '_');
  _triggerDownload(csv, `search_${name}.csv`, 'text/csv;charset=utf-8');
}

function exportResultsTSV() {
  const tsv = _buildExportData('\t');
  if (!tsv) return;
  const name = (STATE.listName || 'results').replace(/[^a-z0-9_]/gi, '_');
  _triggerDownload(tsv, `search_${name}.tsv`, 'text/tab-separated-values;charset=utf-8');
}

function exportResultsJSON() {
  const d = STATE.lastResults;
  if (!d || !d.matched || d.matched.length === 0) { toast('No results to export', 'warning'); return; }
  const arr = d.matched.map(rec => {
    const obj = {};
    for (const f of d.fields) obj[f.name] = rec.data[f.name] ?? '';
    return obj;
  });
  const name = (STATE.listName || 'results').replace(/[^a-z0-9_]/gi, '_');
  _triggerDownload(JSON.stringify(arr, null, 2), `search_${name}.json`, 'application/json');
}

function renderResultsTable(data) {
  const thead = document.getElementById('results-thead');
  const tbody = document.getElementById('results-tbody');

  // '__any__' isn't a real column name — exclude it from the highlight set
  const condFields = new Set(STATE.conditions.map(c => c.field).filter(f => f !== '__any__'));

  // Reset compare selection on new results
  STATE.cmpSelected.clear();
  updateCmpSelBar();

  // Build header
  let thHtml = '<tr>';
  // Checkbox (for compare selection) + Row# (sticky)
  thHtml += `<th class="px-3 py-2 text-left whitespace-nowrap sticky left-0 bg-[#0d1117] border-r border-slate-800 w-12">
    <span class="text-[9px] text-slate-600 uppercase tracking-widest">cmp</span>
  </th>`;
  // All schema fields
  for (const f of data.fields) {
    const isCond = condFields.has(f.name);
    const isId   = f.name === data.id_field;
    const isName = f.name === data.name_field;
    const accent = isCond ? 'text-purple-300' : (isId||isName ? 'text-cyan-400' : 'text-slate-500');
    thHtml += `<th data-col="${esc(f.name)}"
      class="px-3 py-2 text-left whitespace-nowrap ${accent} font-medium cursor-pointer hover:text-slate-200 select-none"
      onclick="sortByCol('${esc(f.name)}')" title="${esc(f.type)}">
      ${esc(f.name)}
      ${isCond ? '<span class="text-purple-700 ml-0.5">●</span>' : ''}
    </th>`;
  }
  thHtml += '</tr>';
  thead.innerHTML = thHtml;

  // Build rows
  let tbHtml = '';
  for (const rec of data.matched) {
    const viewerUrl = `index.php?file=${encodeURIComponent(STATE.file)}&list=${STATE.listIdx}&off=${rec.row}`;
    tbHtml += `<tr class="result-row" data-row="${rec.row}" title="Record #${rec.row}">`;
    // Checkbox + row# cell (sticky)
    tbHtml += `<td class="px-2 py-1.5 whitespace-nowrap border-r border-slate-800/50 sticky left-0 bg-[#0d1117]">
      <div class="flex items-center gap-1.5">
        <input type="checkbox" data-cmp-row="${rec.row}" onchange="toggleCmpSel(${rec.row},this.checked)"
          class="accent-purple-500 w-3 h-3 cursor-pointer" title="Select for comparison"/>
        <a href="${viewerUrl}" target="_blank" class="text-slate-600 hover:text-cyan-400 text-[10px] transition-colors">${rec.row}</a>
      </div>
    </td>`;
    for (const f of data.fields) {
      const val     = rec.data[f.name] ?? '';
      const isCond  = condFields.has(f.name);
      const isId    = f.name === data.id_field;
      const isName  = f.name === data.name_field;
      const color   = isCond ? 'text-purple-200' : (isId ? 'text-cyan-300' : isName ? 'text-emerald-300' : 'text-slate-400');
      // Truncate long strings in table view
      const display = val.length > 48 ? val.slice(0, 48) + '…' : val;
      tbHtml += `<td data-col="${esc(f.name)}" class="px-3 py-1.5 whitespace-nowrap ${color}" title="${esc(val)}">${esc(display)}</td>`;
    }
    tbHtml += '</tr>';
  }
  tbody.innerHTML = tbHtml;

  // Apply hidden columns
  applyHiddenCols();

  document.getElementById('results-wrap').classList.remove('hidden');
  document.getElementById('empty-state').classList.add('hidden');
  document.getElementById('no-results').classList.add('hidden');
}

// ---------------------------------------------------------------------------
// Column visibility
// ---------------------------------------------------------------------------
function buildColumnToggle(fields) {
  const body = document.getElementById('col-dd-body');
  let html = `
    <div class="px-3 py-1.5 border-b border-slate-800 flex items-center justify-between">
      <span class="text-[10px] mono text-slate-500 uppercase tracking-widest">Columns</span>
      <div class="flex gap-2">
        <a href="#" onclick="setAllCols(true);return false;" class="text-[10px] mono text-cyan-400 hover:text-cyan-300">show all</a>
        <a href="#" onclick="setAllCols(false);return false;" class="text-[10px] mono text-slate-500 hover:text-slate-300">hide all</a>
      </div>
    </div>`;
  for (const f of fields) {
    const hidden  = STATE.hiddenCols.has(f.name);
    const isCond  = STATE.conditions.some(c => c.field === f.name);
    const accent  = isCond ? 'text-purple-300' : 'text-slate-300';
    html += `<label class="flex items-center gap-2 px-3 py-1 hover:bg-slate-800 cursor-pointer">
      <input type="checkbox" ${hidden?'':'checked'} onchange="toggleCol('${esc(f.name)}',this.checked)"
        class="accent-purple-500 w-3 h-3"/>
      <span class="text-[11px] mono ${accent} truncate">${esc(f.name)}</span>
      <span class="ml-auto text-[9px] mono ${typeBadgeClass(f.type)} px-1 rounded border shrink-0">${esc(f.type)}</span>
    </label>`;
  }
  body.innerHTML = html;
}

function toggleCol(name, visible) {
  if (visible) STATE.hiddenCols.delete(name);
  else         STATE.hiddenCols.add(name);
  applyHiddenCols();
}

function setAllCols(visible) {
  if (visible) {
    STATE.hiddenCols.clear();
  } else {
    if (STATE.lastResults) {
      for (const f of STATE.lastResults.fields) STATE.hiddenCols.add(f.name);
      // Always keep condition columns visible
      for (const c of STATE.conditions) STATE.hiddenCols.delete(c.field);
    }
  }
  // Rebuild checkboxes
  if (STATE.lastResults) buildColumnToggle(STATE.lastResults.fields);
  applyHiddenCols();
}

function applyHiddenCols() {
  document.querySelectorAll('[data-col]').forEach(el => {
    if (STATE.hiddenCols.has(el.dataset.col)) el.classList.add('col-hidden');
    else el.classList.remove('col-hidden');
  });
}

// ---------------------------------------------------------------------------
// Sort by column (client-side)
// ---------------------------------------------------------------------------
let _sortCol = null, _sortAsc = true;
function sortByCol(col) {
  if (_sortCol === col) _sortAsc = !_sortAsc;
  else { _sortCol = col; _sortAsc = true; }

  if (!STATE.lastResults) return;

  const ft = STATE.lastResults.fields.find(f => f.name === col);
  const isNum = ft && (ft.type==='int32'||ft.type==='int64'||ft.type==='float'||ft.type==='double');

  STATE.lastResults.matched.sort((a, b) => {
    const av = a.data[col] ?? '';
    const bv = b.data[col] ?? '';
    let cmp;
    if (isNum) cmp = parseFloat(av||0) - parseFloat(bv||0);
    else        cmp = av.localeCompare(bv, undefined, {numeric:true});
    return _sortAsc ? cmp : -cmp;
  });

  // Update header arrow
  document.querySelectorAll('#results-thead th[data-col]').forEach(th => {
    th.querySelector('.sort-arrow')?.remove();
    if (th.dataset.col === col) {
      const arrow = document.createElement('span');
      arrow.className = 'sort-arrow ml-1 text-purple-400';
      arrow.textContent = _sortAsc ? '↑' : '↓';
      th.appendChild(arrow);
    }
  });

  // Re-render tbody
  const tbody      = document.getElementById('results-tbody');
  const condFields = new Set(STATE.conditions.map(c => c.field).filter(f => f !== '__any__'));
  let tbHtml = '';
  for (const rec of STATE.lastResults.matched) {
    const viewerUrl = `index.php?file=${encodeURIComponent(STATE.file)}&list=${STATE.listIdx}&off=${rec.row}`;
    const isSel = STATE.cmpSelected.has(rec.row);
    tbHtml += `<tr class="result-row" data-row="${rec.row}">`;
    tbHtml += `<td class="px-2 py-1.5 whitespace-nowrap border-r border-slate-800/50 sticky left-0 bg-[#0d1117]">
      <div class="flex items-center gap-1.5">
        <input type="checkbox" data-cmp-row="${rec.row}" ${isSel?'checked':''} onchange="toggleCmpSel(${rec.row},this.checked)"
          class="accent-purple-500 w-3 h-3 cursor-pointer"/>
        <a href="${viewerUrl}" target="_blank" class="text-slate-600 hover:text-cyan-400 text-[10px] transition-colors">${rec.row}</a>
      </div>
    </td>`;
    for (const f of STATE.lastResults.fields) {
      const val    = rec.data[f.name] ?? '';
      const isCond = condFields.has(f.name);
      const isId   = f.name === STATE.lastResults.id_field;
      const isName = f.name === STATE.lastResults.name_field;
      const color  = isCond ? 'text-purple-200' : (isId ? 'text-cyan-300' : isName ? 'text-emerald-300' : 'text-slate-400');
      const display = val.length > 48 ? val.slice(0, 48) + '…' : val;
      tbHtml += `<td data-col="${esc(f.name)}" class="px-3 py-1.5 whitespace-nowrap ${color}" title="${esc(val)}">${esc(display)}</td>`;
    }
    tbHtml += '</tr>';
  }
  tbody.innerHTML = tbHtml;
  applyHiddenCols();
}

// =============================================================================
// UI state helpers
// =============================================================================
function clearResults() {
  document.getElementById('results-status').classList.add('hidden');
  document.getElementById('results-wrap').classList.add('hidden');
  document.getElementById('no-results').classList.add('hidden');
  document.getElementById('col-toggle-btn').classList.add('hidden');
  document.getElementById('col-toggle-btn').classList.remove('flex');
  document.getElementById('results-thead').innerHTML = '';
  document.getElementById('results-tbody').innerHTML = '';
  document.getElementById('empty-state').classList.remove('hidden');
  _sortCol = null;
}

function showEmpty() {
  document.getElementById('empty-state').classList.remove('hidden');
  document.getElementById('no-results').classList.add('hidden');
  document.getElementById('results-wrap').classList.add('hidden');
}

function showNoResults() {
  document.getElementById('no-results').classList.remove('hidden');
  document.getElementById('empty-state').classList.add('hidden');
  document.getElementById('results-wrap').classList.add('hidden');
}

function showLoading(show) {
  document.getElementById('loading').classList.toggle('hidden', !show);
  document.getElementById('empty-state').classList.toggle('hidden', show);
  document.getElementById('btn-run').disabled = show;
}

function clearAll() {
  STATE.conditions = [];
  STATE.listIdx    = null;
  STATE.fields     = [];
  STATE.hiddenCols = new Set();
  STATE.lastResults = null;
  document.getElementById('dd-list-label').textContent = '— select a list —';
  document.getElementById('list-info').textContent     = '';
  document.getElementById('btn-add-cond').disabled     = true;
  document.getElementById('btn-run').disabled          = true;
  renderConditions();
  clearResults();
}

// =============================================================================
// Toast
// =============================================================================
function toast(msg, type='info') {
  const ct  = document.getElementById('toast-ct');
  const el  = document.createElement('div');
  const colors = {
    info:    'bg-slate-800 border-slate-600 text-slate-200',
    success: 'bg-emerald-950 border-emerald-700 text-emerald-200',
    warning: 'bg-yellow-950 border-yellow-700 text-yellow-200',
    error:   'bg-red-950 border-red-700 text-red-200',
  };
  el.className = `pointer-events-auto max-w-sm rounded border px-3 py-2 text-[11px] mono shadow-xl ${colors[type]||colors.info}`;
  el.textContent = msg;
  ct.appendChild(el);
  setTimeout(() => el.remove(), 5000);
}

// =============================================================================
// Escape helper
// =============================================================================
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// =============================================================================
// Compare Records (search.php)
// =============================================================================

function toggleCmpSel(rowIdx, checked) {
  if (checked) {
    if (STATE.cmpSelected.size >= 2) {
      // Bump the oldest selection out
      const oldest = STATE.cmpSelected.values().next().value;
      STATE.cmpSelected.delete(oldest);
      const old = document.querySelector(`input[data-cmp-row="${oldest}"]`);
      if (old) old.checked = false;
    }
    STATE.cmpSelected.add(rowIdx);
  } else {
    STATE.cmpSelected.delete(rowIdx);
  }
  updateCmpSelBar();
}

function clearCmpSel() {
  STATE.cmpSelected.forEach(r => {
    const cb = document.querySelector(`input[data-cmp-row="${r}"]`);
    if (cb) cb.checked = false;
  });
  STATE.cmpSelected.clear();
  updateCmpSelBar();
}

function updateCmpSelBar() {
  const bar  = document.getElementById('cmp-sel-bar');
  const text = document.getElementById('cmp-sel-text');
  const btn  = document.getElementById('cmp-sel-btn');
  if (!bar) return;
  const n = STATE.cmpSelected.size;
  if (n === 0) {
    bar.classList.add('hidden');
    return;
  }
  bar.classList.remove('hidden');
  const rows = [...STATE.cmpSelected].sort((a,b)=>a-b);
  if (n === 1) {
    text.textContent = `Record #${rows[0]} selected — check one more to compare`;
    btn.disabled = true;
  } else {
    text.textContent = `Records #${rows[0]} and #${rows[1]} selected`;
    btn.disabled = false;
  }
}

async function openSearchCompare() {
  if (STATE.cmpSelected.size !== 2) return;
  const [rowA, rowB] = [...STATE.cmpSelected].sort((a,b)=>a-b);
  // Pre-fill and open modal
  document.getElementById('cmp-row-a').value = rowA;
  document.getElementById('cmp-row-b').value = rowB;
  document.getElementById('cmp-meta').textContent = '';
  document.getElementById('cmp-empty').innerHTML = '<p class="text-[12px] mono text-slate-500">Loading…</p>';
  document.getElementById('cmp-empty').classList.remove('hidden');
  document.getElementById('cmp-table').classList.add('hidden');
  document.getElementById('cmp-stats').textContent = '';
  const modal = document.getElementById('cmp-modal');
  modal.classList.remove('hidden');
  modal.classList.add('flex');
  setCmpFilter('all', false);
  await runCmp();
}

function closeCmpModal() {
  const modal = document.getElementById('cmp-modal');
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}

async function runCmp() {
  const rowA = parseInt(document.getElementById('cmp-row-a').value, 10);
  const rowB = parseInt(document.getElementById('cmp-row-b').value, 10);
  if (isNaN(rowA) || isNaN(rowB)) { toast('Enter valid row numbers', 'warning'); return; }
  if (rowA === rowB)               { toast('Pick two different records', 'warning'); return; }
  if (STATE.listIdx === null)      { toast('No list selected', 'warning'); return; }

  document.getElementById('cmp-empty').innerHTML =
    '<div class="flex items-center justify-center gap-2 text-[11px] mono text-slate-500">' +
    '<svg class="w-4 h-4 spin" fill="none" viewBox="0 0 24 24" stroke="currentColor">' +
    '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>Loading…</div>';
  document.getElementById('cmp-empty').classList.remove('hidden');
  document.getElementById('cmp-table').classList.add('hidden');

  try {
    const url  = `search.php?action=compare_records&file=${encodeURIComponent(STATE.file)}&list=${STATE.listIdx}&row_a=${rowA}&row_b=${rowB}`;
    const res  = await fetch(url);
    const data = await res.json();
    if (data.error) {
      document.getElementById('cmp-empty').innerHTML =
        `<p class="text-[12px] mono text-red-400">${esc(data.error)}</p>`;
      return;
    }
    _cmpData = data;
    document.getElementById('cmp-meta').textContent =
      `${data.list_name} · ${data.count.toLocaleString()} records`;
    document.getElementById('cmp-th-a').textContent = `Record A  (#${rowA})`;
    document.getElementById('cmp-th-b').textContent = `Record B  (#${rowB})`;
    renderCmpTable();
  } catch(e) {
    document.getElementById('cmp-empty').innerHTML =
      `<p class="text-[12px] mono text-red-400">Network error: ${esc(e.message)}</p>`;
  }
}

function setCmpFilter(f, doRender = true) {
  _cmpFilter = f;
  ['all','diff','same'].forEach(x => {
    const btn = document.getElementById('cmpf-' + x);
    if (!btn) return;
    const border = x === 'all' ? '' : ' border-l border-slate-700';
    btn.className = (f === x
      ? 'px-2.5 py-1 bg-purple-950/60 text-purple-300'
      : 'px-2.5 py-1 text-slate-500 hover:text-slate-300') + border;
  });
  if (doRender) renderCmpTable();
}

function renderCmpTable() {
  if (!_cmpData) return;
  const search = (document.getElementById('cmp-search')?.value || '').toLowerCase();
  const tbody  = document.getElementById('cmp-tbody');
  let nDiff = 0, nSame = 0, nShown = 0, html = '';

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

    let delta = '', deltaColor = 'text-slate-700';
    if (diff) {
      if (f.type === 'int32' || f.type === 'int64') {
        const d = parseInt(vb,10) - parseInt(va,10);
        delta = (d > 0 ? '+' : '') + d.toLocaleString();
        deltaColor = d > 0 ? 'text-emerald-400' : 'text-red-400';
      } else if (f.type === 'float' || f.type === 'double') {
        const d = parseFloat(vb) - parseFloat(va);
        delta = (d > 0 ? '+' : '') + d.toFixed(4);
        deltaColor = d > 0 ? 'text-emerald-400' : 'text-red-400';
      } else { delta = '≠'; deltaColor = 'text-orange-500'; }
    } else { delta = '='; }

    const rowBg  = diff ? 'bg-orange-950/15 hover:bg-orange-950/25' : 'hover:bg-slate-800/20';
    const badge  = typeBadgeClass(f.type);
    const vaDisp = va.length > 52 ? va.slice(0,52) + '…' : va;
    const vbDisp = vb.length > 52 ? vb.slice(0,52) + '…' : vb;

    html += `<tr class="${rowBg} transition-colors">
      <td class="px-3 py-1.5 text-slate-300 whitespace-nowrap font-medium">${esc(f.name)}</td>
      <td class="px-2 py-1.5"><span class="inline-flex px-1 py-0.5 rounded text-[9px] border ${badge}">${esc(f.type)}</span></td>
      <td class="px-3 py-1.5 ${diff?'text-yellow-300':'text-slate-400'} max-w-[220px]"><span class="block truncate" title="${esc(va)}">${esc(vaDisp)}</span></td>
      <td class="px-3 py-1.5 ${diff?'text-cyan-300':'text-slate-400'} max-w-[220px]"><span class="block truncate" title="${esc(vb)}">${esc(vbDisp)}</span></td>
      <td class="px-3 py-1.5 text-right ${deltaColor} whitespace-nowrap">${esc(delta)}</td>
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

// =============================================================================
// Init
// =============================================================================
(function init() {
  const file    = <?= json_encode($fileName) ?>;
  const listIdx = <?= json_encode($preselectedList >= 0 ? $preselectedList : null) ?>;

  // Close dropdowns on outside click (global)
  document.addEventListener('click', e => {
    ['dd-file','dd-list','dd-cols'].forEach(id => {
      const el = document.getElementById(id);
      if (el && !el.contains(e.target)) el.classList.remove('dd-open');
    });
  });

  if (file) {
    loadLists(file).then(() => {
      if (listIdx !== null) {
        selectList(listIdx);
      }
    });
  }
})();
</script>
</body>
</html>
