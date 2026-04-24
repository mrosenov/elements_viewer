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
// Handle POST (save a list definition)
// ---------------------------------------------------------------------------
$saveMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_list') {
    $versionLabel = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['version'] ?? '');
    $listIdx      = (int)($_POST['list'] ?? -1);
    $listName     = trim($_POST['name'] ?? '');
    $names        = $_POST['names'] ?? [];
    $types        = $_POST['types'] ?? [];
    $refs         = $_POST['refs']  ?? [];
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
               class="w-full bg-slate-800/50 border border-slate-700/50 focus:border-cyan-700/60 rounded pl-6 pr-7 py-1.5 text-[11px] mono text-slate-300 placeholder-slate-600 outline-none transition-colors"/>
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
      <div class="grid grid-cols-[2.5rem_5rem_1fr] px-2 py-1 border-b border-slate-800 text-[10px] mono text-slate-600 uppercase tracking-widest shrink-0">
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
          <div class="flex items-center gap-1.5"><span class="text-[11px] mono text-slate-300">records sum</span><span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-slate-900 text-slate-400 border-slate-700"><?= number_format($listCount) ?></span></div>
          <div class="flex items-center gap-1.5"><span class="text-[11px] mono text-slate-300">body_size</span><span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-cyan-950 text-cyan-300 border-cyan-800"><?= number_format($listBody) ?> bytes</span></div>
          <div class="flex items-center gap-1.5"><span class="text-[11px] mono text-slate-300">list_offset</span><span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-orange-950 text-orange-300 border-orange-800">0x<?= str_pad(strtoupper(dechex($listOffset)), 8, '0', STR_PAD_LEFT) ?></span></div>
        </div>
      </div>

      <?php if ($decoded && $decoded['warning']): ?>
        <div class="shrink-0 bg-yellow-950/30 border-b border-yellow-800/50 px-4 py-1.5 text-[11px] mono text-yellow-300 flex items-center gap-2">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
          <?= h($decoded['warning']) ?>
        </div>
      <?php endif; ?>

      <!-- Middle: fields + schema -->
      <div class="flex flex-1 overflow-hidden min-w-0">

        <!-- Fields table -->
        <div class="w-[50rem] shrink-0 border-r border-slate-800 flex flex-col overflow-hidden">
          <div class="px-3 py-2 border-b border-slate-800 bg-[#0d1117]">
            <span class="text-[10px] mono text-slate-600 uppercase tracking-widest">Fields</span>
            <?php if ($decoded && !empty($decoded['rows'])): ?>
              <span class="text-[10px] mono text-slate-500 ml-1">— record #<?= (int)$rowOffset ?></span>
            <?php endif; ?>
          </div>
          <div class="flex-1 overflow-y-auto">
            <?php if ($decoded && !empty($decoded['rows'])):
              $row = $decoded['rows'][0];
            ?>
              <table class="w-full text-[11px] mono">
                <thead class="sticky top-0 bg-[#0d1117]">
                  <tr class="border-b border-slate-800">
                    <th class="text-left px-3 py-1.5 font-normal text-slate-600">Label</th>
                    <th class="text-left px-3 py-1.5 font-normal text-slate-600">Value</th>
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
          <form method="post" id="schemaForm" class="flex-1 flex flex-col overflow-hidden min-w-0">
            <input type="hidden" name="action"   value="save_list">
            <input type="hidden" name="version"  value="<?= h($reader->getVersionLabel()) ?>">
            <input type="hidden" name="list"     value="<?= (int)$selectedListIdx ?>">
            <input type="hidden" name="file_qs"  value="<?= h($fileName) ?>">
            <input type="hidden" name="off_qs"   value="<?= (int)$rowOffset ?>">
            <input type="hidden" name="name_field" value="<?= h($preservedNameField) ?>">
            <input type="hidden" name="id_field"   value="<?= h($preservedIdField) ?>">

            <!-- Header -->
            <div class="shrink-0 bg-[#0d1117] border-b border-slate-800 px-4 py-2 flex items-center gap-2 flex-wrap">
              <span class="text-[10px] mono text-slate-600 uppercase tracking-widest mr-1">Schema Editor</span>
              <span id="badge-fields" class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-slate-900 text-slate-400 border-slate-700"><?= count($schemaFields) ?> fields</span>
              <span id="badge-size"   class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-cyan-950 text-cyan-300 border-cyan-800">size = <?= $schemaSize ?></span>
              <span id="badge-sizeof" class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-purple-950 text-purple-300 border-purple-800">sizeof = <?= $listSizeof ?></span>
            </div>

            <!-- Name input -->
            <div class="shrink-0 border-b border-slate-800 px-4 py-2 flex items-center gap-3">
              <span class="text-[11px] mono text-slate-500 shrink-0">List name</span>
              <input type="text" name="name" value="<?= h($selectedListDef['name'] ?? ('LIST_' . $selectedListIdx)) ?>"
                     class="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-700/70 focus:bg-slate-800 rounded px-2.5 py-1 text-[11px] mono text-cyan-300 outline-none transition-colors w-64"/>
            </div>

            <!-- Schema table -->
            <div class="flex-1 overflow-y-auto">
              <table class="w-full text-[11px] mono border-collapse" id="schemaTbl">
                <thead class="sticky top-0 bg-[#0d1117] z-10">
                  <tr class="border-b border-slate-800 text-slate-600 text-[10px] uppercase tracking-widest">
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
              <button type="button" onclick="addSchemaRow()"
                class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-slate-800/60 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors">
                Add Field
              </button>
              <button type="submit"
                class="px-3 py-1.5 rounded border text-[11px] mono uppercase tracking-widest bg-cyan-950/30 hover:bg-cyan-950/60 border-cyan-800/40 hover:border-cyan-700/60 text-cyan-300 transition-colors">
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
              <span class="text-[10px] mono text-slate-600 uppercase tracking-widest">Hex</span>
              <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-orange-950 text-orange-300 border-orange-800">0x<?= str_pad(strtoupper(dechex($hexAddrBase)), 8, '0', STR_PAD_LEFT) ?></span>
              <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-slate-900 text-slate-400 border-slate-700 ml-1"><?= count($hexBytesArr) ?> bytes</span>
              <span class="ml-auto text-[10px] mono text-slate-300">record #<?= (int)$rowOffset ?></span>
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
function addSchemaRow(){
  const tbody=document.getElementById('schema-tbody');
  if(!tbody) return;
  const types=<?= json_encode($availableTypes) ?>;
  const idx=tbody.querySelectorAll('tr').length;
  const tr=document.createElement('tr');
  tr.className='border-b border-slate-800/40 hover:bg-slate-800/20 group transition-colors';
  let opts='';
  for(let i=0;i<types.length;i++) opts+='<option value="'+esc(types[i])+'"'+(types[i]==='int32'?' selected':'')+'>'+esc(types[i])+'</option>';
  tr.innerHTML=
    '<td class="px-3 py-1.5 text-slate-700">'+idx+'</td>'+
    '<td class="px-3 py-1.5"><span class="text-slate-500">0x0000</span></td>'+
    '<td class="px-3 py-1.5"><input type="text" name="names[]" value="f'+idx+'" class="w-full bg-transparent border border-transparent hover:border-slate-700 focus:border-cyan-700 focus:bg-slate-800/60 rounded px-1.5 py-0.5 text-slate-200 outline-none transition-colors text-[11px] mono"/></td>'+
    '<td class="px-3 py-1.5"><select name="types[]" class="bg-transparent border border-transparent hover:border-slate-700 focus:border-cyan-700 focus:bg-slate-900 rounded px-1.5 py-0.5 outline-none cursor-pointer transition-colors text-[11px] mono text-blue-300">'+opts+'</select></td>'+
    '<td class="px-3 py-1.5"><input type="text" name="refs[]" value="" placeholder="—" class="w-full bg-transparent border border-transparent hover:border-slate-700 focus:border-cyan-700 focus:bg-slate-800/60 rounded px-1.5 py-0.5 text-slate-500 placeholder-slate-700 outline-none transition-colors text-[11px] mono"/></td>'+
    '<td class="px-2 py-1.5"><button type="button" onclick="removeSchemaRow(this)" class="w-5 h-5 flex items-center justify-center rounded hover:bg-red-900/40 text-slate-700 hover:text-red-400 transition-colors opacity-0 group-hover:opacity-100">'+
    '<svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></td>';
  tbody.appendChild(tr);
  const sel=tr.querySelector('select[name="types[]"]');
  if(sel) sel.addEventListener('change',recomputeSchemaBadges);
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
