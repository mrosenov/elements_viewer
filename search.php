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
    $validConds = [];
    foreach ($conditions as $c) {
        $fn = trim($c['field']    ?? '');
        $op = trim($c['operator'] ?? '');
        $cv = $c['value'] ?? '';
        if ($fn === '' || $op === '' || !isset($fieldTypes[$fn])) continue;
        $validConds[] = ['field' => $fn, 'operator' => $op, 'value' => $cv, 'type' => $fieldTypes[$fn]];
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
                $fv = $row[$cond['field']] ?? null;
                if ($fv === null) {
                    if ($logic === 'AND') { $rowMatch = false; break; }
                    continue;
                }
                $hit = s_eval_condition($fv, $cond['operator'], $cond['value'], $cond['type']);
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

<!-- ░░ JAVASCRIPT ░░ -->
<script>
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
  hiddenCols:  new Set(),
  lastResults: null,  // last search response
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

function getOps(type) {
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
  const firstField = STATE.fields[0];
  STATE.conditions.push({
    id, field: firstField.name, operator: '=', value: ''
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
  const ft      = STATE.fields.find(f => f.name === val);
  const ops     = getOps(ft ? ft.type : '');
  cond.operator = ops[0].v;
  // Re-render just the operator select
  const opSel = document.getElementById(`cond-op-${id}`);
  if (opSel) {
    opSel.innerHTML = ops.map(o => `<option value="${o.v}">${esc(o.l)}</option>`).join('');
    opSel.value = cond.operator;
  }
  // Update type badge
  const badge = document.getElementById(`cond-type-${id}`);
  if (badge) {
    badge.textContent = ft ? ft.type : '';
    badge.className = typeBadgeClass(ft ? ft.type : '') + ' px-1.5 py-0.5 rounded text-[10px] mono border shrink-0';
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

  const fieldOptions = STATE.fields.map(f =>
    `<option value="${esc(f.name)}">${esc(f.name)}</option>`
  ).join('');

  let html = '';
  STATE.conditions.forEach((cond, i) => {
    const ft   = STATE.fields.find(f => f.name === cond.field);
    const ops  = getOps(ft ? ft.type : '');
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
        <span id="cond-type-${cond.id}" class="${typeBadgeClass(ft?ft.type:'')} px-1.5 py-0.5 rounded text-[10px] mono border shrink-0">
          ${esc(ft ? ft.type : '')}
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
    <span class="ml-auto text-[10px] text-slate-600">Click a row to open in Viewer</span>
  `;
}

function renderResultsTable(data) {
  const thead = document.getElementById('results-thead');
  const tbody = document.getElementById('results-tbody');

  const condFields = new Set(STATE.conditions.map(c => c.field));

  // Build header
  let thHtml = '<tr>';
  // Row# (sticky)
  thHtml += `<th class="px-3 py-2 text-left whitespace-nowrap sticky left-0 bg-[#0d1117] border-r border-slate-800">#</th>`;
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
    tbHtml += `<tr class="result-row cursor-pointer" onclick="window.open('${viewerUrl}','_blank')" title="Open record #${rec.row} in Viewer">`;
    tbHtml += `<td class="px-3 py-1.5 text-slate-500 whitespace-nowrap border-r border-slate-800/50 text-[10px]">${rec.row}</td>`;
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
  const tbody   = document.getElementById('results-tbody');
  const condFields = new Set(STATE.conditions.map(c => c.field));
  let tbHtml = '';
  for (const rec of STATE.lastResults.matched) {
    const viewerUrl = `index.php?file=${encodeURIComponent(STATE.file)}&list=${STATE.listIdx}&off=${rec.row}`;
    tbHtml += `<tr class="result-row cursor-pointer" onclick="window.open('${viewerUrl}','_blank')">`;
    tbHtml += `<td class="px-3 py-1.5 text-slate-500 whitespace-nowrap border-r border-slate-800/50 text-[10px]">${rec.row}</td>`;
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
