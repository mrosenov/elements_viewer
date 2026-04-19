<?php
/**
 * index.php — simple web UI for browsing elements.data files.
 *
 * Query parameters:
 *   file   = path to a .data file (relative to parent of php_reader)
 *   list   = list index to view/edit
 *   off    = row offset within the selected list (default 0)
 *   limit  = rows per page (default 100)
 *   hex    = bytes of hex dump for the selected list (default 256)
 *
 * POST parameters (for saving a list definition):
 *   action = 'save_list'
 *   version, list, name, names[], types[]
 */

require __DIR__ . '/ElementsReader.php';

// ---------------------------------------------------------------------------
// Resolve data folder (parent of php_reader)
// ---------------------------------------------------------------------------
$dataDir = realpath(__DIR__ . '/..');
$structDir = __DIR__ . '/structures';

function list_data_files($dir) {
    $out = [];
    foreach (scandir($dir) as $f) {
        if (preg_match('/^elements.*\.data$/i', $f)) $out[] = $f;
    }
    sort($out);
    return $out;
}

function load_structure($dir, $versionLabel) {
    $path = $dir . '/' . $versionLabel . '.json';
    if (!is_file($path)) return ['version' => $versionLabel, 'lists' => [], '_path' => $path];
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = ['version' => $versionLabel, 'lists' => []];
    if (!isset($data['lists']) || !is_array($data['lists'])) $data['lists'] = [];
    $data['_path'] = $path;
    return $data;
}

function save_structure($struct) {
    $path = $struct['_path'];
    $copy = $struct;
    unset($copy['_path']);
    return file_put_contents(
        $path,
        json_encode($copy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    ) !== false;
}

// ---------------------------------------------------------------------------
// Handle POST (save a list definition)
// ---------------------------------------------------------------------------
$saveMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_list') {
    $versionLabel  = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['version'] ?? '');
    $listIdx       = (int)($_POST['list'] ?? -1);
    $listName      = trim($_POST['name'] ?? '');
    $nameField     = trim($_POST['name_field'] ?? '');
    $names         = $_POST['names'] ?? [];
    $types         = $_POST['types'] ?? [];
    $refs          = $_POST['refs']  ?? [];
    if ($versionLabel && $listIdx >= 0) {
        $struct = load_structure($structDir, $versionLabel);
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
                // "3, 6,9" → [3, 6, 9]
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
            // Delete the entry entirely if the form is empty.
            unset($struct['lists'][(string)$listIdx]);
        } else {
            // Validate that $nameField is actually one of the defined fields,
            // otherwise drop it. Avoids saving stale references.
            $validNameField = '';
            if ($nameField !== '') {
                foreach ($fields as $fdef) {
                    if ($fdef['name'] === $nameField) { $validNameField = $nameField; break; }
                }
            }
            $entry = [
                'name'   => $listName !== '' ? $listName : "LIST_$listIdx",
                'fields' => $fields,
            ];
            if ($validNameField !== '') $entry['name_field'] = $validNameField;
            $struct['lists'][(string)$listIdx] = $entry;
        }
        $saveMsg = save_structure($struct)
            ? "Saved list $listIdx to {$versionLabel}.json"
            : "ERROR writing {$versionLabel}.json";
    }
    // PRG redirect
    $qs = http_build_query([
        'file'  => $_POST['file_qs'] ?? '',
        'list'  => $listIdx,
        'off'   => $_POST['off_qs'] ?? 0,
        'limit' => $_POST['limit_qs'] ?? 100,
        'hex'   => $_POST['hex_qs'] ?? 256,
        'msg'   => $saveMsg,
    ]);
    header('Location: ?' . $qs);
    exit;
}

// ---------------------------------------------------------------------------
// GET parameters
// ---------------------------------------------------------------------------
$fileName   = basename($_GET['file'] ?? '');
$selectedListIdx = isset($_GET['list']) ? (int)$_GET['list'] : -1;
$rowOffset  = max(0, (int)($_GET['off'] ?? 0));
$rowLimit   = max(1, min(1000, (int)($_GET['limit'] ?? 100)));
$hexBytes   = max(16, min(4096, (int)($_GET['hex'] ?? 256)));
$flashMsg   = $_GET['msg'] ?? $saveMsg;

// Reference lookup query: clicking a cell with refs navigates with these params.
$refVal   = $_GET['ref_val']   ?? '';       // string — parsed per target key type
$refField = $_GET['ref_field'] ?? '';
$refLists = [];
if (!empty($_GET['ref_lists'])) {
    foreach (preg_split('/[\s,]+/', (string)$_GET['ref_lists']) as $tok) {
        if ($tok !== '' && ctype_digit($tok)) $refLists[] = (int)$tok;
    }
}

// Records search: 'q' is the substring, 'qf' (optional) restricts to a field.
$searchQuery = (string)($_GET['q']  ?? '');
$searchField = (string)($_GET['qf'] ?? '');

// Hidden columns (CSV of field names to hide from the records table).
$hiddenCols = [];
if (!empty($_GET['hide'])) {
    foreach (explode(',', (string)$_GET['hide']) as $n) {
        $n = trim($n);
        if ($n !== '') $hiddenCols[$n] = true;
    }
}

// View mode: 'table' (wide, many rows) or 'record' (one record, transposed).
// Record view forces rowLimit = 1 so we only fetch/render the single record.
$view = ($_GET['view'] ?? '') === 'record' ? 'record' : 'table';
if ($view === 'record') $rowLimit = 1;

$dataFiles = list_data_files($dataDir);
if ($fileName === '' && !empty($dataFiles)) $fileName = $dataFiles[0];

$reader = null;
$struct = null;
$readError = '';
if ($fileName !== '' && is_file($dataDir . '/' . $fileName)) {
    try {
        $reader = new ElementsReader($dataDir . '/' . $fileName);
        $reader->scan();
        $struct = load_structure($structDir, $reader->getVersionLabel());
    } catch (Throwable $e) {
        $readError = $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Build current list decode (if a list is selected)
// ---------------------------------------------------------------------------
$decoded = null;
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
                    $rowLimit,
                    $rowOffset
                );
            } catch (Throwable $e) {
                $readError = $e->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Sidebar record list: dropdown + paginated list of rows (with optional
// "name_field" preview). We decode just that one field across the page window
// so the sidebar stays cheap even on huge tables.
//
// Effective-name-field precedence:
//   1. explicit "name_field" in structures JSON, if it matches a real field
//   2. auto-detect a field literally named "name" (case-insensitive)
//   3. none — each row displays "record <N>"
// ---------------------------------------------------------------------------
$sidebarLimit     = 200;  // rows shown in the sidebar at a time
$sidebarPageStart = 0;
$sidebarPageEnd   = 0;
$sidebarNames     = [];
$sidebarNameField = '';
$sidebarNameSource = '';  // 'configured' or 'auto' — for the UI hint
if ($reader && $selectedListMeta) {
    $total = (int)$selectedListMeta['count'];
    if ($total > 0) {
        $sidebarPageStart = (int)(floor(max(0, $rowOffset) / $sidebarLimit) * $sidebarLimit);
        $sidebarPageEnd   = min($total, $sidebarPageStart + $sidebarLimit);
    }
    if ($selectedListDef && !empty($selectedListDef['fields'])) {
        // (1) honor explicit config, but only if the field still exists
        if (!empty($selectedListDef['name_field'])) {
            foreach ($selectedListDef['fields'] as $fd) {
                if ($fd['name'] === $selectedListDef['name_field']) {
                    $sidebarNameField  = $selectedListDef['name_field'];
                    $sidebarNameSource = 'configured';
                    break;
                }
            }
        }
        // (2) auto-detect a field literally named "name" (case-insensitive)
        if ($sidebarNameField === '') {
            foreach ($selectedListDef['fields'] as $fd) {
                if (strcasecmp($fd['name'], 'name') === 0) {
                    $sidebarNameField  = $fd['name'];
                    $sidebarNameSource = 'auto';
                    break;
                }
            }
        }
    }
    if ($sidebarNameField !== '' && $sidebarPageEnd > $sidebarPageStart) {
        try {
            $sidebarNames = $reader->decodeField(
                $selectedListIdx,
                $selectedListDef['fields'],
                $sidebarNameField,
                $sidebarPageStart,
                $sidebarPageEnd - $sidebarPageStart
            );
        } catch (Throwable $e) {
            // non-fatal — sidebar just falls back to "record <N>" labels
            $sidebarNames = [];
        }
    }
}

// If the user typed a search query, run it now.  It replaces the paged
// record list with up to 500 matching rows from anywhere in the list.
$searchResult = null;
if ($reader && $selectedListIdx >= 0 && $selectedListDef
    && !empty($selectedListDef['fields']) && $searchQuery !== '') {
    try {
        $fieldArg = $searchField !== '' ? $searchField : null;
        $searchResult = $reader->searchList(
            $selectedListIdx,
            $selectedListDef['fields'],
            $searchQuery,
            $fieldArg,
            500
        );
    } catch (Throwable $e) {
        $readError = $e->getMessage();
    }
}

/**
 * Resolve a reference lookup: for each referenced list, search rows where
 * the first field (the list's key) equals the supplied value, and decode
 * those matching rows using the list's schema.
 *
 * Returns [listIdx => [
 *   'name'    => string,
 *   'error'   => string|null,
 *   'matches' => [ ['row' => int, 'fields' => [...], 'data' => [...]] ]
 * ]]
 */
function resolve_references($reader, $struct, $refValue, $refLists, $perListLimit = 20)
{
    $out = [];
    foreach ($refLists as $rIdx) {
        $def = $struct['lists'][(string)$rIdx] ?? null;
        if (!$def || empty($def['fields'])) {
            $out[$rIdx] = [
                'name'  => $def['name'] ?? "LIST_$rIdx",
                'error' => 'no schema defined for this list',
                'matches' => [],
            ];
            continue;
        }
        $keyType = $def['fields'][0]['type'];
        $searchable = ['int32','int64','float','double'];
        if (!in_array($keyType, $searchable, true)) {
            $out[$rIdx] = [
                'name'  => $def['name'],
                'error' => "first-field type '$keyType' is not searchable",
                'matches' => [],
            ];
            continue;
        }
        $rows = $reader->findMatchingRows($rIdx, $keyType, $refValue, $perListLimit);
        $matches = [];
        foreach ($rows as $rowIdx) {
            try {
                $dec = $reader->decodeList($rIdx, $def['fields'], 1, $rowIdx);
                if (!empty($dec['rows'])) {
                    $matches[] = [
                        'row'    => $rowIdx,
                        'fields' => $dec['fields'],
                        'data'   => $dec['rows'][0],
                    ];
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        $out[$rIdx] = [
            'name'    => $def['name'],
            'error'   => null,
            'matches' => $matches,
        ];
    }
    return $out;
}

$refResults = null;
if ($reader && $refVal !== '' && !empty($refLists)) {
    $refResults = resolve_references($reader, $struct, $refVal, $refLists);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function page_url($params) {
    $base = [
        'file'  => $_GET['file']  ?? '',
        'list'  => $_GET['list']  ?? '',
        'off'   => $_GET['off']   ?? 0,
        'limit' => $_GET['limit'] ?? 100,
        'hex'   => $_GET['hex']   ?? 256,
        'hide'  => $_GET['hide']  ?? '',
        'view'  => $_GET['view']  ?? '',
    ];
    foreach ($params as $k => $v) $base[$k] = $v;
    return '?' . http_build_query(array_filter($base, function($v){ return $v !== '' && $v !== null; }));
}

/**
 * Return fields with a 'visible' bool added, based on $hiddenCols map.
 */
function mark_visibility($fields, $hiddenCols) {
    $out = [];
    foreach ($fields as $f) {
        $f['visible'] = !isset($hiddenCols[$f['name']]);
        $out[] = $f;
    }
    return $out;
}

function format_hex_dump($raw, $bytesPerRow = 16) {
    $out = '';
    $len = strlen($raw);
    for ($i = 0; $i < $len; $i += $bytesPerRow) {
        $slice = substr($raw, $i, $bytesPerRow);
        $hex = '';
        $ascii = '';
        for ($j = 0; $j < strlen($slice); $j++) {
            $b = ord($slice[$j]);
            $hex .= sprintf('%02x ', $b);
            $ascii .= ($b >= 0x20 && $b < 0x7F) ? chr($b) : '.';
        }
        $hex = str_pad($hex, $bytesPerRow * 3);
        $out .= sprintf("%04x  %s %s\n", $i, $hex, $ascii);
    }
    return $out;
}
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>elements.data viewer</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  /* ===== App-specific styles that Bootstrap doesn't cover directly ===== */
  body { font-size: 13px; }
  .nav-meta { color: rgba(255,255,255,.6); font-size: 12px; }
  .nav-meta-mono { font-family: var(--bs-font-monospace); }

  /* Main two-pane layout */
  .app-body { display: flex; height: calc(100vh - 56px); }
  .sidebar { width: 320px; background: #fff; display: flex; flex-direction: column; min-height: 0; }
  .sidebar .sidebar-head { flex: 0 0 auto; }
  .sidebar .record-list { flex: 1 1 auto; overflow-y: auto; min-height: 0; font-size: 12px; }
  .main-pane { flex: 1; overflow: auto; min-width: 0; padding: 1rem 1.25rem; }

  /* Record list items (sidebar) */
  .record-list-head { display: grid; grid-template-columns: 48px 1fr; gap: 8px;
                      position: sticky; top: 0; z-index: 1;
                      padding: 4px 10px; background: #e9ecef;
                      border-bottom: 1px solid var(--bs-border-color);
                      font-size: 11px; font-weight: 600; color: #495057;
                      text-transform: uppercase; letter-spacing: .03em; }
  .record-list-head .rh-idx { text-align: right; }
  .record-item { display: grid; grid-template-columns: 48px 1fr; gap: 8px;
                 padding: 4px 10px; text-decoration: none; color: inherit;
                 border-bottom: 1px solid #f1f3f5;
                 white-space: nowrap; overflow: hidden; }
  .record-item:hover { background: #f8f9fa; }
  .record-item.active { background: #cfe2ff; color: #084298; font-weight: 500; }
  .record-item .rec-idx { text-align: right; color: #6c757d;
                          font-variant-numeric: tabular-nums;
                          font-family: var(--bs-font-monospace); font-size: 11px; }
  .record-item.active .rec-idx { color: #084298; }
  .record-item .rec-name { overflow: hidden; text-overflow: ellipsis; }
  .record-item .rec-name .muted { color: #adb5bd; font-style: italic; }
  .record-item.d-none-filter { display: none !important; }

  /* Records / search / reference tables */
  .two-col { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 1rem; }
  .two-col > div { min-width: 0; }
  .rows-scroll { overflow-x: auto; max-width: 100%; border: 1px solid var(--bs-border-color); border-radius: .25rem; }
  .rows-scroll table { margin: 0; font-size: 12px; white-space: nowrap; }
  .rows-scroll thead th { position: sticky; top: 0; z-index: 1; background: #e9ecef; }

  /* Dense schema editor */
  .schema-wrap { max-height: 60vh; overflow-y: auto; border: 1px solid var(--bs-border-color); border-radius: .25rem; background: #fff; }
  .schema-wrap table { margin: 0; font-size: 11px; }
  .schema-wrap thead th { position: sticky; top: 0; z-index: 2; background: #e9ecef; padding: 4px 6px; }
  .schema-wrap tbody td { padding: 2px 4px; vertical-align: middle; }
  .schema-wrap td.idx, .schema-wrap td.off { color: #6c757d; text-align: right; font-variant-numeric: tabular-nums; }
  .schema-wrap input.form-control-xs { font-size: 11px; padding: 1px 4px; height: auto; min-width: 40px; }
  .schema-wrap tr.hidden { display: none; }
  .schema-wrap .xbtn { background: none; border: 0; color: var(--bs-danger); padding: 0 4px; font-size: 14px; line-height: 1; cursor: pointer; }
  .schema-wrap .xbtn:hover { color: #a50000; }

  /* Column picker */
  .col-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
              gap: 2px 12px; max-height: 260px; overflow-y: auto;
              padding: .5rem; border: 1px solid var(--bs-border-color); border-radius: .25rem;
              background: #fff; }
  .col-grid label { font-size: 11px; display: flex; align-items: center; gap: 4px;
                    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
                    cursor: pointer; margin: 0; padding: 2px 4px; border-radius: .125rem; }
  .col-grid label:hover { background: #f1f3f5; }
  .col-grid label.hidden { display: none; }
  .col-grid input[type=checkbox] { margin: 0; }

  /* Record view (transposed) */
  .record-view .card-body { padding: 0; }
  .record-view table { margin: 0; font-size: 12px; table-layout: fixed; }
  .record-view th { width: 30%; background: #f8f9fa; font-weight: 600; vertical-align: top;
                    word-break: break-word; }
  .record-view td.value { font-family: var(--bs-font-monospace); word-break: break-word; }
  .record-view .rv-type { font-weight: normal; color: #6c757d; font-size: 11px; margin-left: 4px; }
  .record-view .rv-off  { font-weight: normal; color: #adb5bd; font-size: 11px; }
  .record-view tr.hidden { display: none; }

  /* Hex dump */
  pre.hex { background: #212529; color: #9ec5fe; font-size: 12px; padding: 0.75rem;
            border-radius: .25rem; margin: 0.5rem 0 0 0; overflow-x: auto; }

  /* Little helpers */
  code.inline { background: #f1f3f5; padding: 1px 4px; border-radius: 2px; font-size: 12px; color: #212529; }
  .small-muted { color: #6c757d; font-size: 11px; }

  /* Rotate collapse caret when expanded */
  [data-bs-toggle="collapse"][aria-expanded="true"] .collapse-caret { transform: rotate(90deg); }
  .collapse-caret { transition: transform .15s ease-in-out; display: inline-block; }

  /* Bootstrap doesn't ship form-control-xs — we ship our own dense variant for the schema editor */
  .form-control.form-control-xs { font-size: 11px; padding: 1px 4px; height: auto; min-width: 40px; }
</style>
</head>
<body class="bg-body-tertiary">

<nav class="navbar navbar-dark bg-dark px-3 py-2" style="min-height:56px;">
  <span class="navbar-brand fs-6 mb-0 me-3">
    <i class="bi bi-database"></i> elements.data viewer
  </span>
  <form method="get" class="d-flex me-3">
    <select name="file" class="form-select form-select-sm" style="min-width: 220px;"
            onchange="this.form.submit()">
      <?php foreach ($dataFiles as $f): ?>
        <option value="<?= h($f) ?>" <?= $f === $fileName ? 'selected' : '' ?>><?= h($f) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php if ($reader): ?>
    <span class="badge text-bg-primary me-2"><?= h($reader->getVersionLabel()) ?></span>
    <span class="nav-meta nav-meta-mono me-2">0x<?= sprintf('%08X', $reader->getVersion()) ?></span>
    <span class="nav-meta me-2">lists: <?= count($reader->getLists()) ?></span>
    <?php if ($reader->getTalkProcCount() !== null): ?>
      <span class="nav-meta me-2">talk_proc: <?= (int)$reader->getTalkProcCount() ?> (skipped)</span>
    <?php endif; ?>
    <span class="nav-meta nav-meta-mono ms-auto">structures/<?= h($reader->getVersionLabel()) ?>.json</span>
  <?php endif; ?>
</nav>

<?php if ($flashMsg): ?>
  <div class="alert alert-warning rounded-0 mb-0 py-2 small d-flex align-items-center">
    <i class="bi bi-info-circle me-2"></i><?= h($flashMsg) ?>
  </div>
<?php endif; ?>
<?php if ($readError): ?>
  <div class="alert alert-danger rounded-0 mb-0 py-2 small d-flex align-items-center">
    <i class="bi bi-exclamation-triangle me-2"></i>Error: <?= h($readError) ?>
  </div>
<?php endif; ?>

<div class="app-body">
  <aside class="sidebar border-end">
    <div class="sidebar-head p-2 border-bottom">
      <label class="small-muted mb-1 d-block">Table</label>
      <select class="form-select form-select-sm" id="tablePicker"
              onchange="if(this.value) location = this.value">
        <option value="">— pick a table —</option>
        <?php if ($reader): foreach ($reader->getLists() as $L):
            $defName   = $struct['lists'][(string)$L['index']]['name'] ?? '';
            $isSel     = $L['index'] === $selectedListIdx;
            $label     = $defName !== '' ? $defName : 'LIST_' . $L['index'];
            // Selecting a table jumps to record view at row 0.
            $optUrl    = page_url([
                'list' => $L['index'],
                'off'  => 0,
                'view' => 'record',
                'q'    => null, 'qf' => null,
                'hide' => null,
                'ref_val' => null, 'ref_lists' => null, 'ref_field' => null,
            ]);
        ?>
          <option value="<?= h($optUrl) ?>" <?= $isSel ? 'selected' : '' ?>>
            #<?= (int)$L['index'] ?> · <?= h($label) ?> · <?= (int)$L['count'] ?> × <?= (int)$L['sizeof'] ?>B
          </option>
        <?php endforeach; endif; ?>
      </select>
    </div>

    <?php if ($reader && $selectedListMeta): ?>
      <?php
        $total = (int)$selectedListMeta['count'];
      ?>
      <div class="sidebar-head p-2 border-bottom">
        <div class="d-flex align-items-center gap-1 mb-1 small-muted">
          <span>
            <?php if ($total > 0): ?>
              Rows <strong><?= $sidebarPageStart ?></strong>–<strong><?= max($sidebarPageStart, $sidebarPageEnd - 1) ?></strong>
              of <strong><?= $total ?></strong>
            <?php else: ?>
              (empty)
            <?php endif; ?>
          </span>
          <div class="btn-group btn-group-sm ms-auto" role="group">
            <a class="btn btn-outline-secondary <?= $sidebarPageStart > 0 ? '' : 'disabled' ?>"
               title="previous page"
               href="<?= h(page_url(['off' => max(0, $sidebarPageStart - $sidebarLimit)])) ?>">
              <i class="bi bi-chevron-left"></i>
            </a>
            <a class="btn btn-outline-secondary <?= $sidebarPageEnd < $total ? '' : 'disabled' ?>"
               title="next page"
               href="<?= h(page_url(['off' => $sidebarPageEnd])) ?>">
              <i class="bi bi-chevron-right"></i>
            </a>
          </div>
        </div>
        <input type="search" class="form-control form-control-sm"
               placeholder="filter records…"
               oninput="filterRecList(this.value)">
        <?php if ($sidebarNameField !== ''): ?>
          <div class="small text-muted mt-1"
               title="Change this in the Schema editor ('Name field' input)">
            <i class="bi bi-tag"></i> names from
            <code class="inline"><?= h($sidebarNameField) ?></code>
            <?php if ($sidebarNameSource === 'auto'): ?>
              <span class="small-muted">(auto)</span>
            <?php endif; ?>
          </div>
        <?php elseif ($selectedListDef): ?>
          <div class="small text-muted mt-1"
               title="Set a 'Name field' in the schema editor, or add a field called 'Name' and it'll auto-detect.">
            <i class="bi bi-info-circle"></i> no name field available
          </div>
        <?php endif; ?>
      </div>

      <div class="record-list" id="recordList">
        <?php if ($total === 0): ?>
          <div class="p-3 text-muted small">List is empty.</div>
        <?php else: ?>
          <div class="record-list-head">
            <span class="rh-idx">#</span>
            <span>Name</span>
          </div>
        <?php
          for ($r = $sidebarPageStart; $r < $sidebarPageEnd; $r++):
            $active = ($r === $rowOffset && $view === 'record') ? 'active' : '';
            $rawName = $sidebarNames[$r] ?? null;
            if (is_float($rawName)) {
                $rawName = rtrim(rtrim(sprintf('%.6f', $rawName), '0'), '.');
            }
            $nameStr = $rawName === null ? '' : (string)$rawName;
            $rUrl = page_url([
                'list' => $selectedListIdx,
                'off'  => $r,
                'view' => 'record',
                'q' => null, 'qf' => null,
                'ref_val' => null, 'ref_lists' => null, 'ref_field' => null,
            ]);
        ?>
          <a class="record-item <?= $active ?>"
             data-fname="<?= h(strtolower($nameStr)) ?>"
             data-ridx="<?= (int)$r ?>"
             href="<?= h($rUrl) ?>">
            <span class="rec-idx"><?= (int)$r ?></span>
            <span class="rec-name">
              <?php if ($nameStr !== ''): ?>
                <?= h($nameStr) ?>
              <?php elseif ($sidebarNameField !== ''): ?>
                <span class="muted">(blank)</span>
              <?php else: ?>
                <span class="muted">record <?= (int)$r ?></span>
              <?php endif; ?>
            </span>
          </a>
        <?php endfor; endif; ?>
      </div>
    <?php else: ?>
      <div class="p-3 text-muted small">Pick a table above to browse its records.</div>
    <?php endif; ?>
  </aside>

  <section class="main-pane">
    <?php if (!$reader): ?>
      <div class="alert alert-info">Select an elements.data file above.</div>
    <?php elseif ($selectedListIdx < 0): ?>
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Pick a list from the sidebar to inspect.</h5>
          <dl class="row mb-0 small">
            <dt class="col-sm-3">File version</dt>
            <dd class="col-sm-9">
              <strong><?= h($reader->getVersionLabel()) ?></strong>
              <code class="inline">0x<?= sprintf('%08X', $reader->getVersion()) ?></code>
            </dd>
            <dt class="col-sm-3">Lists scanned</dt>
            <dd class="col-sm-9"><?= count($reader->getLists()) ?></dd>
            <?php if ($reader->getTalkProcCount() !== null): ?>
              <dt class="col-sm-3">talk_proc records</dt>
              <dd class="col-sm-9"><?= (int)$reader->getTalkProcCount() ?> <span class="text-muted">(skipped, not decoded)</span></dd>
            <?php endif; ?>
            <dt class="col-sm-3">Structure file</dt>
            <dd class="col-sm-9">
              <code class="inline">structures/<?= h($reader->getVersionLabel()) ?>.json</code>
              <span class="text-muted">(<?= count($struct['lists']) ?> defined)</span>
            </dd>
          </dl>
        </div>
      </div>
    <?php elseif (!$selectedListMeta): ?>
      <div class="alert alert-warning">Invalid list index.</div>
    <?php else: ?>
      <div class="d-flex align-items-baseline flex-wrap gap-3 mb-2">
        <h5 class="mb-0">
          List <?= $selectedListIdx ?>
          <?php if ($selectedListDef): ?>
            <span class="text-success">— <?= h($selectedListDef['name']) ?></span>
          <?php endif; ?>
        </h5>
        <small class="text-muted">
          sizeof <code class="inline"><?= $selectedListMeta['sizeof'] ?></code>
          · count <code class="inline"><?= $selectedListMeta['count'] ?></code>
          · body <code class="inline"><?= $selectedListMeta['body_bytes'] ?></code> B
          · offset <code class="inline">0x<?= dechex($selectedListMeta['data_offset']) ?></code>
        </small>
      </div>

      <?php if ($decoded && $decoded['warning']): ?>
        <div class="alert alert-warning py-2 small mb-2">
          <i class="bi bi-exclamation-triangle me-1"></i><?= h($decoded['warning']) ?>
        </div>
      <?php endif; ?>

      <?php if ($refResults !== null): ?>
        <div class="card border-primary mb-3">
          <div class="card-header bg-primary-subtle d-flex align-items-center">
            <span>
              References for
              <code class="inline"><?= h($refField) ?></code> =
              <strong><?= h((string)$refVal) ?></strong>
            </span>
            <a class="btn-close ms-auto" aria-label="clear"
               href="<?= h(page_url(['ref_val' => null, 'ref_lists' => null, 'ref_field' => null])) ?>"></a>
          </div>
          <div class="card-body p-2">
          <?php foreach ($refResults as $rIdx => $r): ?>
            <div class="mb-3">
              <div class="d-flex align-items-center gap-2 mb-1">
                <a class="fw-semibold text-decoration-none" href="<?= h(page_url([
                    'list' => $rIdx, 'off' => 0,
                    'ref_val' => null, 'ref_lists' => null, 'ref_field' => null
                ])) ?>">
                  List <?= (int)$rIdx ?> — <?= h($r['name']) ?>
                </a>
                <?php if ($r['error']): ?>
                  <span class="badge text-bg-warning"><?= h($r['error']) ?></span>
                <?php else: ?>
                  <span class="badge text-bg-secondary"><?= count($r['matches']) ?> match(es)</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($r['matches'])): ?>
                <div class="rows-scroll">
                <table class="table table-sm table-striped table-hover">
                  <thead>
                    <tr>
                      <th>#row</th>
                      <?php foreach ($r['matches'][0]['fields'] as $f): ?>
                        <th title="<?= h($f['type']) ?> @<?= $f['offset'] ?>"><?= h($f['name']) ?></th>
                      <?php endforeach; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($r['matches'] as $m): ?>
                      <tr>
                        <td>
                          <a href="<?= h(page_url([
                              'list' => $rIdx, 'off' => $m['row'],
                              'ref_val' => null, 'ref_lists' => null, 'ref_field' => null,
                              'view' => 'record',
                          ])) ?>"><?= (int)$m['row'] ?></a>
                        </td>
                        <?php foreach ($m['fields'] as $f):
                            $v = $m['data'][$f['name']] ?? '';
                            if (is_float($v)) $v = rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
                        ?>
                          <td><?= h((string)$v) ?></td>
                        <?php endforeach; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="two-col">
        <!-- Decoded rows -->
        <div>
          <h6 class="mb-2 fw-semibold">Records</h6>

          <?php if ($decoded):
              $allFields   = $decoded['fields'];
              $totalCols   = count($allFields);
              $hiddenCount = 0;
              foreach ($allFields as $f) if (isset($hiddenCols[$f['name']])) $hiddenCount++;
              $visibleCount = $totalCols - $hiddenCount;
          ?>
            <form method="get" class="d-flex gap-2 align-items-center mb-2 flex-wrap">
              <input type="hidden" name="file"  value="<?= h($fileName) ?>">
              <input type="hidden" name="list"  value="<?= $selectedListIdx ?>">
              <input type="hidden" name="limit" value="<?= $rowLimit ?>">
              <input type="hidden" name="hex"   value="<?= $hexBytes ?>">
              <input type="hidden" name="hide"  value="<?= h($_GET['hide'] ?? '') ?>">
              <input type="hidden" name="view"  value="<?= h($view) ?>">
              <div class="input-group input-group-sm" style="width:auto;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" name="q"
                       value="<?= h($searchQuery) ?>"
                       placeholder="search records…" style="width:200px;">
                <select name="qf" class="form-select" title="restrict search to one field"
                        style="max-width:160px;">
                  <option value="">any field</option>
                  <?php foreach ($allFields as $f): ?>
                    <option value="<?= h($f['name']) ?>" <?= $searchField === $f['name'] ? 'selected' : '' ?>>
                      <?= h($f['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Search</button>
                <?php if ($searchQuery !== ''): ?>
                  <a class="btn btn-outline-danger"
                     href="<?= h(page_url(['q' => null, 'qf' => null])) ?>">Clear</a>
                <?php endif; ?>
              </div>
              <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="view mode">
                <a class="btn btn-outline-primary <?= $view === 'table'  ? 'active' : '' ?>"
                   href="<?= h(page_url(['view' => null])) ?>">
                  <i class="bi bi-table"></i> Table
                </a>
                <a class="btn btn-outline-primary <?= $view === 'record' ? 'active' : '' ?>"
                   href="<?= h(page_url(['view' => 'record'])) ?>">
                  <i class="bi bi-card-list"></i> Record
                </a>
              </div>
            </form>

            <div class="card mb-2">
              <div class="card-header py-1 px-2 d-flex align-items-center gap-2 flex-wrap">
                <a class="text-decoration-none text-body fw-semibold small"
                   data-bs-toggle="collapse" href="#colPickerBody" role="button"
                   aria-expanded="<?= $hiddenCount > 0 ? 'true' : 'false' ?>" aria-controls="colPickerBody">
                  <i class="bi bi-chevron-right collapse-caret"></i>
                  Columns
                </a>
                <span class="badge text-bg-secondary" id="colCountPill"><?= $visibleCount ?>/<?= $totalCols ?></span>
                <?php if ($hiddenCount > 0): ?>
                  <a class="small text-decoration-none"
                     href="<?= h(page_url(['hide' => null])) ?>">show all</a>
                <?php endif; ?>
                <input type="search" id="colFilter" class="form-control form-control-sm ms-auto"
                       placeholder="filter columns…" style="max-width:200px;"
                       oninput="filterColList(this.value)">
              </div>
              <div class="collapse <?= $hiddenCount > 0 ? 'show' : '' ?>" id="colPickerBody">
                <div class="card-body p-2">
                  <form method="get" id="colForm">
                    <?php foreach (['file','list','off','limit','hex','q','qf'] as $k):
                        if (isset($_GET[$k]) && $_GET[$k] !== ''): ?>
                      <input type="hidden" name="<?= h($k) ?>" value="<?= h($_GET[$k]) ?>">
                    <?php endif; endforeach; ?>
                    <div class="col-grid" id="colGrid">
                      <?php foreach ($allFields as $f):
                          $checked = !isset($hiddenCols[$f['name']]);
                      ?>
                        <label data-fname="<?= h(strtolower($f['name'])) ?>">
                          <input type="checkbox" class="form-check-input"
                                 name="show[]" value="<?= h($f['name']) ?>"
                                 <?= $checked ? 'checked' : '' ?>>
                          <span title="<?= h($f['name'] . ' — ' . $f['type']) ?>"><?= h($f['name']) ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                    <div class="d-flex gap-1 align-items-center mt-2 flex-wrap">
                      <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary" onclick="colsAll(true)">all</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="colsAll(false)">none</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="colsInvert()">invert</button>
                      </div>
                      <button type="submit" class="btn btn-sm btn-primary">apply</button>
                      <span class="small-muted ms-2">
                        (checked = visible, tip: type a filter then click <em>none</em> to hide only those)
                      </span>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if (!$decoded): ?>
            <div class="alert alert-info py-2 small">
              <i class="bi bi-info-circle me-1"></i>
              No schema defined for this list yet. Use the form on the right to add fields.
            </div>
          <?php elseif ($searchResult !== null): /* --- search results mode --- */ ?>
            <div class="alert alert-light border py-2 px-3 small mb-2">
              <?php if (empty($searchResult['rows'])): ?>
                <i class="bi bi-search me-1"></i>No matches for
                <code class="inline"><?= h($searchQuery) ?></code>
                <?php if ($searchField): ?> in <code class="inline"><?= h($searchField) ?></code><?php endif; ?>
                (scanned <?= (int)$searchResult['scanned_rows'] ?> of
                <?= (int)$searchResult['total_rows'] ?> rows).
              <?php else: ?>
                <i class="bi bi-check2-circle me-1"></i>
                <strong><?= count($searchResult['rows']) ?></strong>
                <?= $searchResult['truncated'] ? 'first ' : '' ?>
                match(es) for
                <code class="inline"><?= h($searchQuery) ?></code>
                <?php if ($searchField): ?> in <code class="inline"><?= h($searchField) ?></code><?php endif; ?>
                <?php if ($searchResult['truncated']): ?>
                  — result capped at 500; refine the query to see more.
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <?php if (!empty($searchResult['rows'])):
                $visibleFields = array_values(array_filter($searchResult['fields'],
                    function($f) use ($hiddenCols) { return !isset($hiddenCols[$f['name']]); }));
            ?>
              <div class="rows-scroll">
              <table class="table table-sm table-striped table-hover mb-0">
                <thead>
                  <tr>
                    <th>#</th>
                    <?php foreach ($visibleFields as $f): ?>
                      <th title="<?= h($f['type']) ?> @<?= $f['offset'] ?>"><?= h($f['name']) ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($searchResult['rows'] as $m):
                    $row = $m['data'];
                ?>
                  <tr>
                    <td>
                      <a title="open in record view"
                         href="<?= h(page_url([
                             'q' => null, 'qf' => null,
                             'off' => $m['row'],
                             'view' => 'record',
                         ])) ?>"><?= (int)$m['row'] ?></a>
                    </td>
                    <?php foreach ($visibleFields as $f):
                        $v = $row[$f['name']] ?? '';
                        $fieldRefs = $selectedListDef['fields'][array_search($f['name'],
                            array_column($selectedListDef['fields'], 'name'))]['refs'] ?? [];
                        $display = is_float($v)
                            ? rtrim(rtrim(sprintf('%.6f', $v), '0'), '.')
                            : (string)$v;
                        $clickable = !empty($fieldRefs)
                            && (is_int($v) || is_float($v) || (is_string($v) && $v !== '' && is_numeric($v)));
                    ?>
                      <td>
                      <?php if ($clickable): ?>
                        <a href="<?= h(page_url([
                            'ref_val'   => $display,
                            'ref_lists' => implode(',', $fieldRefs),
                            'ref_field' => $f['name'],
                        ])) ?>"><?= h($display) ?></a>
                      <?php else: ?>
                        <?= h($display) ?>
                      <?php endif; ?>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              </div>
            <?php endif; ?>
          <?php elseif (empty($decoded['rows'])): ?>
            <div class="alert alert-secondary py-2 small">
              <i class="bi bi-inbox me-1"></i>
              List is empty (count = 0) or offset past the end.
            </div>
          <?php elseif ($view === 'record'): /* --- one record, transposed --- */ ?>
            <?php
              $rowIdx = $rowOffset;
              $row    = $decoded['rows'][0];
              // We render every defined field in record view (column-hide is a
              // wide-table concern). User can still filter with the input below.
              $visibleFields = $decoded['fields'];
              $defsByName = [];
              foreach ($selectedListDef['fields'] as $fd) $defsByName[$fd['name']] = $fd;
            ?>
            <div class="card record-view">
              <div class="card-header py-1 px-2 d-flex align-items-center gap-2 flex-wrap">
                <strong class="me-1">Record #<?= (int)$rowIdx ?></strong>
                <span class="small-muted">of <?= (int)$decoded['count'] ?></span>
                <div class="btn-group btn-group-sm ms-1" role="group">
                  <a class="btn btn-outline-secondary <?= $rowIdx > 0 ? '' : 'disabled' ?>"
                     href="<?= h(page_url(['off' => max(0, $rowIdx - 1)])) ?>"
                     title="previous record">
                    <i class="bi bi-chevron-left"></i>
                  </a>
                  <a class="btn btn-outline-secondary <?= $rowIdx + 1 < $decoded['count'] ? '' : 'disabled' ?>"
                     href="<?= h(page_url(['off' => $rowIdx + 1])) ?>"
                     title="next record">
                    <i class="bi bi-chevron-right"></i>
                  </a>
                </div>
                <form method="get" class="d-inline-flex align-items-center gap-1 mb-0">
                  <?php foreach (['file','list','limit','hex','hide','view','q','qf'] as $k):
                      if (($val = $_GET[$k] ?? '') !== ''): ?>
                    <input type="hidden" name="<?= h($k) ?>" value="<?= h($val) ?>">
                  <?php endif; endforeach; ?>
                  <label class="small-muted mb-0">go to #</label>
                  <input type="number" name="off" class="form-control form-control-sm"
                         value="<?= (int)$rowIdx ?>" min="0"
                         max="<?= max(0, (int)$decoded['count'] - 1) ?>"
                         style="width:90px;">
                </form>
                <input type="search" class="form-control form-control-sm ms-auto"
                       placeholder="filter fields…" style="max-width:180px;"
                       oninput="rvFilter(this.value)">
              </div>
              <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0" id="rvTbl">
                  <tbody>
                    <?php foreach ($visibleFields as $f):
                        $v  = $row[$f['name']] ?? '';
                        $fd = $defsByName[$f['name']] ?? null;
                        $fieldRefs = $fd['refs'] ?? [];
                        $display = is_float($v)
                            ? rtrim(rtrim(sprintf('%.6f', $v), '0'), '.')
                            : (string)$v;
                        $clickable = !empty($fieldRefs)
                            && (is_int($v) || is_float($v) || (is_string($v) && $v !== '' && is_numeric($v)));
                    ?>
                      <tr data-fname="<?= h(strtolower($f['name'])) ?>">
                        <th>
                          <?= h($f['name']) ?>
                          <span class="rv-type"><?= h($f['type']) ?></span>
                          <span class="rv-off">@<?= (int)$f['offset'] ?></span>
                        </th>
                        <td class="value">
                          <?php if ($display === ''): ?>
                            <span class="text-muted">·</span>
                          <?php elseif ($clickable): ?>
                            <a href="<?= h(page_url([
                                'ref_val'   => $display,
                                'ref_lists' => implode(',', $fieldRefs),
                                'ref_field' => $f['name'],
                            ])) ?>" title="Look up in lists: <?= h(implode(', ', $fieldRefs)) ?>"><?= h($display) ?></a>
                          <?php else: ?>
                            <?= h($display) ?>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php else: /* --- wide table view --- */ ?>
            <div class="d-flex align-items-center gap-2 mb-1 small">
              <span class="text-muted">
                Rows <strong><?= $rowOffset ?></strong>–<strong><?= $rowOffset + count($decoded['rows']) - 1 ?></strong>
                of <strong><?= $decoded['count'] ?></strong>
              </span>
              <div class="btn-group btn-group-sm ms-auto" role="group">
                <a class="btn btn-outline-secondary <?= $rowOffset > 0 ? '' : 'disabled' ?>"
                   href="<?= h(page_url(['off' => max(0, $rowOffset - $rowLimit)])) ?>">
                  <i class="bi bi-chevron-left"></i> prev
                </a>
                <a class="btn btn-outline-secondary <?= $rowOffset + $rowLimit < $decoded['count'] ? '' : 'disabled' ?>"
                   href="<?= h(page_url(['off' => $rowOffset + $rowLimit])) ?>">
                  next <i class="bi bi-chevron-right"></i>
                </a>
              </div>
            </div>
            <?php
              $visibleFields = array_values(array_filter($decoded['fields'],
                  function($f) use ($hiddenCols) { return !isset($hiddenCols[$f['name']]); }));
            ?>
            <div class="rows-scroll">
            <table class="table table-sm table-striped table-hover mb-0">
              <thead>
                <tr>
                  <th>#</th>
                  <?php foreach ($visibleFields as $f): ?>
                    <th title="<?= h($f['type']) ?> @<?= $f['offset'] ?>"><?= h($f['name']) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($decoded['rows'] as $i => $row): ?>
                <tr>
                  <td>
                    <a title="open in record view"
                       href="<?= h(page_url([
                           'off' => $rowOffset + $i, 'view' => 'record',
                       ])) ?>"><?= $rowOffset + $i ?></a>
                  </td>
                  <?php foreach ($visibleFields as $f):
                      $v = $row[$f['name']] ?? '';
                      $fieldRefs = $selectedListDef['fields'][array_search($f['name'],
                          array_column($selectedListDef['fields'], 'name'))]['refs'] ?? [];
                      $display = is_float($v)
                          ? rtrim(rtrim(sprintf('%.6f', $v), '0'), '.')
                          : (string)$v;
                      $clickable = !empty($fieldRefs)
                          && (is_int($v) || is_float($v) || (is_string($v) && $v !== '' && is_numeric($v)));
                  ?>
                    <td>
                    <?php if ($clickable): ?>
                      <a href="<?= h(page_url([
                          'ref_val'   => $display,
                          'ref_lists' => implode(',', $fieldRefs),
                          'ref_field' => $f['name'],
                      ])) ?>" title="Look up in lists: <?= h(implode(', ', $fieldRefs)) ?>"><?= h($display) ?></a>
                    <?php else: ?>
                      <?= h($display) ?>
                    <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          <?php endif; ?>
        </div>

        <!-- Schema editor + hex dump -->
        <div>
          <?php
            $fieldsArr = $selectedListDef['fields'] ?? [];
            if (empty($fieldsArr)) $fieldsArr = [['name' => 'f0', 'type' => 'int32']];
            $fieldCount = count($fieldsArr);
          ?>
          <div class="d-flex align-items-baseline gap-2 mb-2 flex-wrap">
            <h6 class="mb-0 fw-semibold">Schema</h6>
            <small class="text-muted">
              <?= $fieldCount ?> field<?= $fieldCount === 1 ? '' : 's' ?><?php if ($decoded): ?>
              · size = <code class="inline"><?= $decoded['schema_size'] ?></code>
              / sizeof = <code class="inline"><?= $decoded['actual_size'] ?></code>
              <?php endif; ?>
            </small>
          </div>

          <form method="post" id="schemaForm">
            <input type="hidden" name="action"   value="save_list">
            <input type="hidden" name="version"  value="<?= h($reader->getVersionLabel()) ?>">
            <input type="hidden" name="list"     value="<?= $selectedListIdx ?>">
            <input type="hidden" name="file_qs"  value="<?= h($fileName) ?>">
            <input type="hidden" name="off_qs"   value="<?= $rowOffset ?>">
            <input type="hidden" name="limit_qs" value="<?= $rowLimit ?>">
            <input type="hidden" name="hex_qs"   value="<?= $hexBytes ?>">

            <div class="d-flex gap-2 align-items-center mb-2 flex-wrap">
              <div class="input-group input-group-sm" style="max-width:240px;">
                <span class="input-group-text">Name</span>
                <input type="text" class="form-control" name="name"
                       value="<?= h($selectedListDef['name'] ?? '') ?>">
              </div>
              <div class="input-group input-group-sm" style="max-width:240px;"
                   title="Which field to show as the 'name' in the sidebar record list">
                <span class="input-group-text">Name field</span>
                <input type="text" class="form-control" name="name_field"
                       list="nameFieldList" autocomplete="off"
                       placeholder="(none)"
                       value="<?= h($selectedListDef['name_field'] ?? '') ?>">
              </div>
              <datalist id="nameFieldList">
                <?php foreach ($fieldsArr as $fd): ?>
                  <option value="<?= h($fd['name']) ?>">
                <?php endforeach; ?>
              </datalist>
              <input type="search" id="fieldFilter" class="form-control form-control-sm"
                     placeholder="filter fields…" style="max-width:180px;"
                     oninput="filterFields(this.value)">
              <span class="badge text-bg-secondary" id="fieldCount"><?= $fieldCount ?> shown</span>
            </div>

            <div class="schema-wrap">
            <table class="table table-sm table-hover mb-0" id="fieldsTbl">
              <colgroup>
                <col style="width:36px"><col style="width:46px">
                <col><col style="width:28%"><col style="width:22%">
                <col style="width:28px">
              </colgroup>
              <thead>
                <tr>
                  <th class="idx">#</th>
                  <th class="off" title="byte offset of this field within a record">off</th>
                  <th>Name</th><th>Type</th>
                  <th title="Comma-separated list indices this field references">Refs</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php
                $off = 0;
                foreach ($fieldsArr as $i => $f):
                    $w = ElementsReader::typeWidth($f['type']);
                    $displayOff = $off;
                    $off += $w === null ? 0 : $w;
                    $refStr = !empty($f['refs']) && is_array($f['refs'])
                        ? implode(',', $f['refs']) : '';
              ?>
                <tr data-fname="<?= h(strtolower($f['name'])) ?>">
                  <td class="idx"><?= $i ?></td>
                  <td class="off"><?= $displayOff ?></td>
                  <td><input type="text" class="form-control form-control-xs"
                             name="names[]" value="<?= h($f['name']) ?>"></td>
                  <td><input type="text" class="form-control form-control-xs"
                             name="types[]" value="<?= h($f['type']) ?>"
                             list="typelist" autocomplete="off"></td>
                  <td><input type="text" class="form-control form-control-xs"
                             name="refs[]" value="<?= h($refStr) ?>"
                             placeholder="e.g. 3,6,9"></td>
                  <td><button type="button" class="xbtn" title="remove field"
                              onclick="this.closest('tr').remove();updateFieldCount();">
                    <i class="bi bi-x-lg"></i>
                  </button></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            </div>
            <datalist id="typelist">
              <?php foreach (ElementsReader::availableTypes() as $t): ?>
                <option value="<?= h($t) ?>">
              <?php endforeach; ?>
            </datalist>
            <div class="mt-2 d-flex gap-2 flex-wrap">
              <button type="button" class="btn btn-sm btn-outline-success" onclick="addField()">
                <i class="bi bi-plus-lg"></i> add field
              </button>
              <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-save"></i> Save schema
              </button>
              <?php if ($selectedListDef): ?>
                <button type="submit" class="btn btn-sm btn-outline-danger"
                        onclick="if(!confirm('Delete schema for this list?'))return false;
                                 document.querySelectorAll('#fieldsTbl tbody tr').forEach(r=>r.remove());">
                  <i class="bi bi-trash"></i> Delete
                </button>
              <?php endif; ?>
            </div>
          </form>

          <?php $hexOpen = $selectedListMeta['sizeof'] > 0 && $selectedListMeta['count'] > 0; ?>
          <div class="card mt-3">
            <div class="card-header py-1 px-2">
              <a class="text-decoration-none text-body fw-semibold small"
                 data-bs-toggle="collapse" href="#hexDumpBody" role="button"
                 aria-expanded="<?= $hexOpen ? 'true' : 'false' ?>" aria-controls="hexDumpBody">
                <i class="bi bi-chevron-right collapse-caret"></i>
                Hex dump <span class="text-muted">(first record, <?= $hexBytes ?> bytes)</span>
              </a>
            </div>
            <div class="collapse <?= $hexOpen ? 'show' : '' ?>" id="hexDumpBody">
              <div class="card-body p-2">
                <form method="get" class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                  <?php foreach (['file','list','off','limit'] as $k):
                      if (isset($_GET[$k])): ?>
                    <input type="hidden" name="<?= h($k) ?>" value="<?= h($_GET[$k]) ?>">
                  <?php endif; endforeach; ?>
                  <div class="input-group input-group-sm" style="max-width:220px;">
                    <span class="input-group-text">Bytes</span>
                    <input type="number" class="form-control" name="hex"
                           value="<?= $hexBytes ?>" min="16" max="4096" step="16">
                    <button class="btn btn-outline-primary" type="submit">refresh</button>
                  </div>
                </form>
                <pre class="hex"><?= h(format_hex_dump($reader->hexDumpList($selectedListIdx, $hexBytes, $rowOffset))) ?></pre>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function addField() {
  var tbody = document.querySelector('#fieldsTbl tbody');
  var idx = tbody.rows.length;
  var tr = document.createElement('tr');
  tr.setAttribute('data-fname', 'f' + idx);
  tr.innerHTML =
    '<td class="idx">' + idx + '</td>' +
    '<td class="off">?</td>' +
    '<td><input type="text" class="form-control form-control-xs" name="names[]" value="f' + idx + '"></td>' +
    '<td><input type="text" class="form-control form-control-xs" name="types[]" value="int32" list="typelist" autocomplete="off"></td>' +
    '<td><input type="text" class="form-control form-control-xs" name="refs[]" value="" placeholder="e.g. 3,6,9"></td>' +
    '<td><button type="button" class="xbtn" title="remove field" ' +
        'onclick="this.closest(\'tr\').remove();updateFieldCount();">' +
        '<i class="bi bi-x-lg"></i></button></td>';
  // Keep the data-fname attribute in sync as the user types, so the live filter
  // matches against the currently-typed name rather than the original.
  var nameInput = tr.querySelector('input[name="names[]"]');
  nameInput.addEventListener('input', function () {
    tr.setAttribute('data-fname', this.value.toLowerCase());
  });
  tbody.appendChild(tr);
  updateFieldCount();
  nameInput.focus();
}

function filterFields(q) {
  q = (q || '').trim().toLowerCase();
  var rows = document.querySelectorAll('#fieldsTbl tbody tr');
  for (var i = 0; i < rows.length; i++) {
    var name = rows[i].getAttribute('data-fname') || '';
    var typeInput = rows[i].querySelector('input[name="types[]"]');
    var type = typeInput ? typeInput.value.toLowerCase() : '';
    var match = q === '' || name.indexOf(q) !== -1 || type.indexOf(q) !== -1;
    rows[i].classList.toggle('hidden', !match);
  }
  updateFieldCount();
}

function updateFieldCount() {
  var rows = document.querySelectorAll('#fieldsTbl tbody tr');
  var shown = 0;
  for (var i = 0; i < rows.length; i++) {
    if (!rows[i].classList.contains('hidden')) shown++;
  }
  var c = document.getElementById('fieldCount');
  if (c) c.textContent = shown + ' of ' + rows.length + ' shown';
}

// Record view — filter visible Field|Value rows by name/type substring.
function rvFilter(q) {
  q = (q || '').trim().toLowerCase();
  var rows = document.querySelectorAll('#rvTbl tr');
  for (var i = 0; i < rows.length; i++) {
    var name = rows[i].getAttribute('data-fname') || '';
    rows[i].classList.toggle('hidden', q !== '' && name.indexOf(q) === -1);
  }
}

// Record-view "go to #" input — jump on Enter AND on blur.
(function () {
  var form = document.querySelector('.record-view form');
  if (!form) return;
  var inp = form.querySelector('input[name=off]');
  if (!inp) return;
  inp.addEventListener('blur', function () { form.requestSubmit(); });
})();

// Column picker — convert checkbox state into a 'hide' CSV on submit.
(function () {
  var form = document.getElementById('colForm');
  if (!form) return;
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var hidden = [];
    var cbs = form.querySelectorAll('input[type=checkbox][name="show[]"]');
    for (var i = 0; i < cbs.length; i++) {
      if (!cbs[i].checked) hidden.push(cbs[i].value);
    }
    // Build URL from the form's hidden inputs + 'hide' param.
    var params = new URLSearchParams();
    var hids = form.querySelectorAll('input[type=hidden]');
    for (var j = 0; j < hids.length; j++) {
      if (hids[j].value !== '') params.set(hids[j].name, hids[j].value);
    }
    if (hidden.length) params.set('hide', hidden.join(','));
    window.location = '?' + params.toString();
  });
})();

function colsAll(onlyVisibleInFilter) {
  // If a column filter is active, operate only on visible rows.
  var labels = document.querySelectorAll('#colGrid label');
  for (var i = 0; i < labels.length; i++) {
    var l = labels[i];
    if (l.classList.contains('hidden')) continue;
    var cb = l.querySelector('input[type=checkbox]');
    if (cb) cb.checked = !!onlyVisibleInFilter;
  }
  updateColCountPill();
}

function colsInvert() {
  var labels = document.querySelectorAll('#colGrid label');
  for (var i = 0; i < labels.length; i++) {
    if (labels[i].classList.contains('hidden')) continue;
    var cb = labels[i].querySelector('input[type=checkbox]');
    if (cb) cb.checked = !cb.checked;
  }
  updateColCountPill();
}

function filterColList(q) {
  q = (q || '').trim().toLowerCase();
  var labels = document.querySelectorAll('#colGrid label');
  for (var i = 0; i < labels.length; i++) {
    var name = labels[i].getAttribute('data-fname') || '';
    labels[i].classList.toggle('hidden', q !== '' && name.indexOf(q) === -1);
  }
}

function updateColCountPill() {
  var cbs = document.querySelectorAll('#colGrid input[type=checkbox][name="show[]"]');
  var total = cbs.length, checked = 0;
  for (var i = 0; i < cbs.length; i++) if (cbs[i].checked) checked++;
  var p = document.getElementById('colCountPill');
  if (p) p.textContent = checked + '/' + total;
}
// Initial update + on every click inside the grid.
(function () {
  var grid = document.getElementById('colGrid');
  if (grid) grid.addEventListener('change', updateColCountPill);
  updateColCountPill();
})();

// Sidebar record list — filter by row index or name substring.
function filterRecList(q) {
  q = (q || '').trim().toLowerCase();
  var items = document.querySelectorAll('#recordList .record-item');
  for (var i = 0; i < items.length; i++) {
    var el    = items[i];
    var name  = el.getAttribute('data-fname') || '';
    var ridx  = el.getAttribute('data-ridx') || '';
    var match = q === ''
             || name.indexOf(q) !== -1
             || ridx.indexOf(q) !== -1
             || ('#' + ridx).indexOf(q) !== -1;
    el.classList.toggle('d-none-filter', !match);
  }
}

// Scroll the active record into view in the sidebar on page load.
(function () {
  var active = document.querySelector('#recordList .record-item.active');
  if (active && typeof active.scrollIntoView === 'function') {
    // nearest = don't jump main pane; block:center so context rows remain visible
    active.scrollIntoView({ block: 'center' });
  }
})();

// Keep data-fname synced for pre-rendered rows too.
(function () {
  var rows = document.querySelectorAll('#fieldsTbl tbody tr');
  for (var i = 0; i < rows.length; i++) {
    var inp = rows[i].querySelector('input[name="names[]"]');
    if (!inp) continue;
    (function (row, el) {
      el.addEventListener('input', function () {
        row.setAttribute('data-fname', this.value.toLowerCase());
      });
    })(rows[i], inp);
  }
  updateFieldCount();
})();
</script>
</body>
</html>
