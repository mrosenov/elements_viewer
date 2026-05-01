<?php
/**
 * tasks.php — Jade Dynasty Task (Quest) Viewer
 * Reads tasks.data / tasks.data1–45 binary files and displays them
 * in a searchable, filterable table with a detail panel.
 */

declare(strict_types=1);
require_once __DIR__ . '/classes/TaskDataReader.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$dataDir = __DIR__ . '/data';

// ── Index ─────────────────────────────────────────────────────────────────────
$indexInfo = null;
try { $indexInfo = TaskDataReader::parseIndex($dataDir . '/tasks.data'); } catch (Exception $e) {}

$packCount  = $indexInfo['pack_count'] ?? 45;
$totalItems = $indexInfo['item_count'] ?? 0;

// ── Valid packs ───────────────────────────────────────────────────────────────
$validPacks = [];
for ($p = 1; $p <= $packCount; $p++) {
    if (file_exists($dataDir . '/tasks.data' . $p)) $validPacks[] = $p;
}

$selectedPack = isset($_GET['pack']) ? (int)$_GET['pack'] : ($validPacks[0] ?? 1);
if (!in_array($selectedPack, $validPacks, true)) $selectedPack = $validPacks[0] ?? 1;

// ── Parse selected pack ───────────────────────────────────────────────────────
$parseError = '';
$tasks = [];
try {
    $binaryVersion = (int)($indexInfo['version'] ?? 0);
    $packData = TaskDataReader::parsePack($dataDir . '/tasks.data' . $selectedPack, $selectedPack, true, $binaryVersion);
    $tasks    = $packData['tasks'];
} catch (Exception $e) {
    $parseError = $e->getMessage();
}

$taskCount   = count($tasks);
$highlightId = isset($_GET['highlight']) ? (int)$_GET['highlight'] : 0;

// ── Field groups ──────────────────────────────────────────────────────────────
$groupLabels = [
    'identity'   => 'Identity',
    'timing'     => 'Timing',
    'flags'      => 'Flags',
    'npc'        => 'NPC / Delivery',
    'prereq'     => 'Prerequisites',
    'team'       => 'Team',
    'master'     => 'Master / Prentice',
    'completion' => 'Completion',
    'award'      => 'Awards',
    'tree'       => 'Task Tree',
];
$fieldsByGroup = [];
foreach (TaskDataReader::FIELDS as [$off, $type, $key, $group, $desc]) {
    $fieldsByGroup[$group][] = ['key' => $key, 'type' => $type, 'desc' => $desc, 'off' => $off];
}

// ── JS data (safe for inline <script>) ───────────────────────────────────────
$jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
$jsTasks = json_encode($tasks, $jsFlags);

$jsGroupLabels = json_encode($groupLabels, $jsFlags);

$jsFBG = [];
foreach ($fieldsByGroup as $grp => $flds) {
    foreach ($flds as $f) $jsFBG[$grp][] = ['key' => $f['key'], 'type' => $f['type'], 'desc' => $f['desc'], 'off' => $f['off']];
}
$jsFieldsByGroup  = json_encode($jsFBG, $jsFlags);
$jsTypeNames      = json_encode(TaskDataReader::TASK_TYPES, $jsFlags);
$jsMethodNames    = json_encode(TaskDataReader::METHODS, $jsFlags);
$jsFinishTypes    = json_encode(TaskDataReader::FINISH_TYPES, $jsFlags);
$jsAwardTypes     = json_encode(TaskDataReader::AWARD_TYPES, $jsFlags);
?>
<!doctype html>
<html lang="en" class="dark">
<head>
  <meta charset="utf-8"/>
  <title>Task Viewer — ZX Elements</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com/3.4.1"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { background: #0b0f18; color: #cbd5e1; font-family: 'Inter', sans-serif; }
    .mono { font-family: 'JetBrains Mono', monospace; }
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: #0f172a; }
    ::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
    ::-webkit-scrollbar-thumb:hover { background: #475569; }
    .dd-menu { display: none; }
    .dd-menu.open { display: block; }
    .hidden-row { display: none !important; }
    .row-selected td { background: #1e3a5f !important; }
    tr.task-row:hover td { background: #1e293b; }
    tr.task-row { cursor: pointer; }
    /* ── Quest tree ── */
    .tree-row { display:flex; align-items:center; gap:3px; padding:2px 4px 2px 0; cursor:pointer; border-radius:2px; white-space:nowrap; overflow:hidden; }
    .tree-row:hover { background:#1e293b; }
    .tree-row.t-sel { background:rgba(139,92,246,0.18); }
    .tree-row.t-sel .t-name { color:#a78bfa; }
    .tree-row.t-sel .t-id   { color:#c4b5fd; }
    .tree-tog { display:inline-flex; align-items:center; justify-content:center; width:12px; height:12px; flex-shrink:0; transition:transform 0.12s; color:#475569; font-size:8px; }
    .tree-tog.open { transform:rotate(90deg); }
    .t-id   { color:#7c3aed; font-size:10px; flex-shrink:0; }
    .t-sep  { color:#334155; font-size:10px; flex-shrink:0; }
    .t-name { color:#94a3b8; font-size:10px; overflow:hidden; text-overflow:ellipsis; }
  </style>
</head>
<body class="min-h-screen flex flex-col">

<!-- ── TOP BAR ───────────────────────────────────────────────────────────── -->
<header class="sticky top-0 z-40 flex items-center gap-3 px-4 py-2 bg-[#0d1117] border-b border-slate-800 shadow-lg">

  <!-- Logo -->
  <div class="flex items-center gap-2 shrink-0">
    <div class="w-6 h-6 rounded bg-violet-500/20 border border-violet-500/40 flex items-center justify-center">
      <svg class="w-3.5 h-3.5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
    </div>
    <span class="mono text-[11px] text-slate-400 border border-violet-800/50 bg-violet-950/30 rounded px-1.5">Task Viewer</span>
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
    <div class="dd-menu absolute top-full mt-1 left-0 bg-slate-900 border border-slate-700 rounded-md shadow-2xl z-50 min-w-[13rem] py-1">
      <a href="index.php" class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-cyan-400 transition-colors">
        <div class="w-4 h-4 rounded bg-cyan-500/15 border border-cyan-500/40 flex items-center justify-center shrink-0"><div class="w-1.5 h-1.5 rounded-sm bg-cyan-400"></div></div>
        Elements Viewer
      </a>
      <a href="search.php" class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-purple-400 transition-colors">
        <div class="w-4 h-4 rounded bg-purple-500/15 border border-purple-500/40 flex items-center justify-center shrink-0"><svg class="w-2.5 h-2.5 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg></div>
        Advanced Search
      </a>
      <a href="paths.php" class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-emerald-400 transition-colors">
        <div class="w-4 h-4 rounded bg-emerald-500/15 border border-emerald-500/40 flex items-center justify-center shrink-0"><svg class="w-2.5 h-2.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg></div>
        Path Viewer
      </a>
      <a href="gshop.php" class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-amber-400 transition-colors">
        <div class="w-4 h-4 rounded bg-amber-500/15 border border-amber-500/40 flex items-center justify-center shrink-0"><svg class="w-2.5 h-2.5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg></div>
        GShop Viewer
      </a>
      <div class="border-t border-slate-800 my-1"></div>
      <a href="tasks.php" class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-violet-400 bg-violet-950/20 hover:bg-slate-800 transition-colors">
        <div class="w-4 h-4 rounded bg-violet-500/15 border border-violet-500/40 flex items-center justify-center shrink-0"><svg class="w-2.5 h-2.5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div>
        Task Viewer
        <span class="ml-auto text-violet-700 text-[9px] mono uppercase tracking-widest">current</span>
      </a>
      <a href="task_field_editor.php" class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-orange-400 transition-colors">
        <div class="w-4 h-4 rounded bg-orange-500/15 border border-orange-500/40 flex items-center justify-center shrink-0"><svg class="w-2.5 h-2.5 text-orange-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></div>
        Field Editor
      </a>
    </div>
  </div>
  <div class="w-px h-4 bg-slate-800"></div>

  <!-- Pack picker -->
  <div class="relative" id="dd-pack">
    <button type="button" onclick="toggleDD('dd-pack')"
      class="flex items-center gap-2 px-2.5 py-1 rounded border text-[11px] mono bg-slate-800/50 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors">
      <span class="text-slate-600">pack</span>
      <span class="text-violet-400 font-semibold">tasks.data<?= $selectedPack ?></span>
      <svg class="w-3 h-3 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div class="dd-menu absolute top-full mt-1 left-0 bg-slate-900 border border-slate-700 rounded-md shadow-2xl z-50 min-w-[13rem] py-0.5 max-h-80 overflow-y-auto">
      <?php foreach ($validPacks as $p):
        $isSel = ($p === $selectedPack);
        $fsize = number_format(filesize($dataDir . '/tasks.data' . $p) / 1024, 0) . ' KB';
      ?>
      <a href="?pack=<?= $p ?>"
         class="flex items-center justify-between px-3 py-1.5 text-[11px] mono <?= $isSel ? 'text-violet-400' : 'text-slate-300' ?> hover:bg-slate-800 transition-colors">
        <span>tasks.data<?= $p ?></span>
        <span class="text-slate-600"><?= $fsize ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="w-px h-4 bg-slate-800"></div>

  <!-- Stats -->
  <div class="flex items-center gap-3 text-[11px] mono text-slate-500">
    <span><span class="text-slate-300"><?= number_format($taskCount) ?></span> tasks in pack</span>
    <span class="text-slate-700">|</span>
    <span><span class="text-slate-300"><?= number_format($totalItems) ?></span> total</span>
    <span class="text-slate-700">|</span>
    <span>version <span class="text-violet-400"><?= $indexInfo['version'] ?? '?' ?></span></span>
  </div>

  <!-- Global search button -->
  <div class="ml-auto">
    <button onclick="openGlobalSearch()"
      class="flex items-center gap-1.5 px-2.5 py-1 rounded border text-[11px] mono bg-violet-900/30 hover:bg-violet-800/40 border-violet-700/50 hover:border-violet-600 text-violet-300 transition-colors">
      <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      Search all packs
      <span class="text-violet-700 text-[10px]">Ctrl+G</span>
    </button>
  </div>
</header>

<!-- ── GLOBAL SEARCH MODAL ────────────────────────────────────────────────── -->
<div id="gs-overlay" style="display:none" class="fixed inset-0 z-50 bg-black/70 flex items-start justify-center pt-20">
  <div class="w-full max-w-2xl bg-[#0d1117] border border-slate-700 rounded-lg shadow-2xl flex flex-col" style="max-height:70vh">
    <!-- Search input -->
    <div class="flex items-center gap-2 px-4 py-3 border-b border-slate-800">
      <svg class="w-4 h-4 text-violet-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      <input id="gs-input" type="text" placeholder="Search by name or ID across all packs…"
        class="flex-1 bg-transparent text-slate-200 text-[13px] mono placeholder-slate-600 focus:outline-none"
        oninput="gsDebounce()" onkeydown="gsKeydown(event)"/>
      <span id="gs-status" class="text-[10px] mono text-slate-600 shrink-0"></span>
      <button onclick="closeGlobalSearch()" class="text-slate-600 hover:text-slate-300 ml-1">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <!-- Results -->
    <div id="gs-results" class="flex-1 overflow-y-auto"></div>
    <!-- Detail -->
    <div id="gs-detail" style="display:none" class="border-t border-slate-800 overflow-y-auto p-4 text-[11px] mono" style="max-height:40vh"></div>
  </div>
</div>

<!-- ── MAIN LAYOUT ────────────────────────────────────────────────────────── -->
<div class="flex flex-1 overflow-hidden" style="height: calc(100vh - 41px)">

  <!-- ── SIDEBAR : quest tree ─────────────────────────────────────────────── -->
  <aside class="w-72 shrink-0 border-r border-slate-800 overflow-hidden bg-[#0d1117] flex flex-col">
    <div class="px-3 py-2 border-b border-slate-800 flex items-center gap-2 shrink-0">
      <span class="text-[10px] mono text-slate-600 uppercase tracking-widest shrink-0">Quests</span>
      <input id="tree-search" type="text" placeholder="Filter…"
        class="flex-1 min-w-0 bg-transparent text-[11px] mono text-slate-300 placeholder-slate-600 focus:outline-none"
        oninput="treeFilter()"/>
    </div>
    <div id="tree-container" class="flex-1 overflow-y-auto py-1 mono"></div>
  </aside>

  <!-- ── RECORD VIEWER ──────────────────────────────────────────────────── -->
  <div class="flex flex-col flex-1 overflow-hidden">

    <!-- Record header (visible once a task is selected) -->
    <div id="record-header" style="display:none" class="shrink-0 px-4 py-2 border-b border-slate-800 bg-[#0d1117] flex items-center gap-2 text-[11px] mono"></div>

    <!-- Record body -->
    <div class="flex-1 overflow-auto" id="record-body">
      <div id="record-empty" class="flex h-full items-center justify-center text-slate-600 mono text-[11px]">
        &#8592; Select a quest from the tree
        <?php if ($parseError): ?><br/><span class="text-red-400 mt-2"><?= h($parseError) ?></span><?php endif; ?>
      </div>
      <div id="record-content" style="display:none"></div>
    </div>

  </div>
</div>

<script>
var TASKS         = <?= $jsTasks ?>;
var GROUP_LABELS  = <?= $jsGroupLabels ?>;
var FBG           = <?= $jsFieldsByGroup ?>;
var TYPE_NAMES    = <?= $jsTypeNames ?>;
var METHOD_NAMES  = <?= $jsMethodNames ?>;
var FINISH_TYPES  = <?= $jsFinishTypes ?>;
var AWARD_TYPES   = <?= $jsAwardTypes ?>;
var HIGHLIGHT_ID  = <?= $highlightId ?>;
var CURRENT_PACK  = <?= $selectedPack ?>;

// ── Variable-struct field metadata ───────────────────────────────────────────
var MONSTER_FIELDS = [
  { key: 'monster_id',    type: 'uint32', off: 0,  desc: 'Monster template ID to kill' },
  { key: 'monster_num',   type: 'uint32', off: 4,  desc: 'Number to kill' },
  { key: 'drop_item_id',  type: 'uint32', off: 8,  desc: 'Drop item template ID' },
  { key: 'drop_item_num', type: 'uint32', off: 12, desc: 'Drop item count required' },
  { key: 'drop_cmn_item', type: 'bool',   off: 16, desc: 'Drop item is a common (non-task) item' },
  { key: 'drop_prob',     type: 'float',  off: 17, desc: 'Drop probability [0.0–1.0]', fmt: 'pct' },
  { key: 'killer_lev',    type: 'bool',   off: 21, desc: 'Apply level-based kill restrictions' },
];
var ITEM_FIELDS = [
  { key: 'item_id',  type: 'uint32', off: 0,  desc: 'Item template ID' },
  { key: 'common',   type: 'bool',   off: 4,  desc: 'Common (non-task) item' },
  { key: 'item_num', type: 'uint32', off: 5,  desc: 'Stack count required or awarded' },
  { key: 'prob',     type: 'float',  off: 9,  desc: 'Drop/award probability (0 = always)', fmt: 'pct' },
  { key: 'bind',     type: 'bool',   off: 13, desc: 'Item is bound to character on award' },
];
var INTEROBJ_FIELDS = [
  { key: 'obj_id',  type: 'uint32', off: 0, desc: 'Interactive object template ID' },
  { key: 'obj_num', type: 'uint32', off: 4, desc: 'Number of interactions required' },
];

// ── Quest tree ───────────────────────────────────────────────────────────────
var TASK_MAP     = {};   // id → {task, idx}
var treeExpanded = {};   // id → bool
var treeSelId    = 0;

(function buildMap() {
  for (var i = 0; i < TASKS.length; i++) {
    TASK_MAP[TASKS[i].id] = { task: TASKS[i], idx: i };
  }
})();

function treeChildIds(parentId) {
  var entry = TASK_MAP[parentId];
  if (!entry) return [];
  var out = [], visited = {}, cid = entry.task.first_child;
  while (cid && cid > 0 && !visited[cid]) {
    visited[cid] = true;
    out.push(cid);
    var ce = TASK_MAP[cid];
    if (!ce) break;
    cid = ce.task.next_sibling;
  }
  return out;
}

function treeRoots() {
  // roots = tasks whose parent is 0 or points outside this pack
  return TASKS.filter(function(t) {
    return !t.parent || t.parent === 0 || !TASK_MAP[t.parent];
  }).map(function(t) { return t.id; });
}

function treeNodeHTML(id, depth) {
  var entry = TASK_MAP[id];
  if (!entry) return '';
  var t        = entry.task;
  var kids     = treeChildIds(id);
  var hasKids  = kids.length > 0;
  var isOpen   = !!treeExpanded[id];
  var isSel    = (treeSelId === id);
  var indent   = (depth * 14 + 4) + 'px';

  var rowCls = 'tree-row' + (isSel ? ' t-sel' : '');

  var tog = hasKids
    ? '<span class="tree-tog' + (isOpen ? ' open' : '') + '" onclick="treeToggle(event,' + id + ')">&#9654;</span>'
    : '<span style="display:inline-block;width:12px;flex-shrink:0"></span>';

  var html = '<div class="tree-node" data-id="' + id + '">'
    + '<div class="' + rowCls + '" style="padding-left:' + indent + '" onclick="treeClick(' + id + ')" title="' + esc(t.name || '') + '">'
    + tog
    + '<span class="t-id">' + id + '</span>'
    + '<span class="t-sep">&#8211;</span>'
    + '<span class="t-name">' + esc(t.name || '<unnamed>') + '</span>'
    + '</div>';

  if (hasKids) {
    html += '<div id="tc-' + id + '"' + (isOpen ? '' : ' style="display:none"') + '>';
    for (var i = 0; i < kids.length; i++) html += treeNodeHTML(kids[i], depth + 1);
    html += '</div>';
  }

  return html + '</div>';
}

function renderTree(filterQ) {
  var container = document.getElementById('tree-container');
  filterQ = (filterQ || '').toLowerCase().trim();
  var html = '';

  if (filterQ) {
    // Flat filtered list — show all matches without hierarchy
    for (var i = 0; i < TASKS.length; i++) {
      var t = TASKS[i];
      if (String(t.id).indexOf(filterQ) === -1 && (t.name || '').toLowerCase().indexOf(filterQ) === -1) continue;
      var isSel = (treeSelId === t.id);
      html += '<div class="tree-node" data-id="' + t.id + '">'
        + '<div class="tree-row' + (isSel ? ' t-sel' : '') + '" style="padding-left:6px" onclick="treeClick(' + t.id + ')" title="' + esc(t.name || '') + '">'
        + '<span style="display:inline-block;width:12px;flex-shrink:0"></span>'
        + '<span class="t-id">' + t.id + '</span>'
        + '<span class="t-sep">&#8211;</span>'
        + '<span class="t-name">' + esc(t.name || '<unnamed>') + '</span>'
        + '</div></div>';
    }
  } else {
    var roots = treeRoots();
    for (var r = 0; r < roots.length; r++) html += treeNodeHTML(roots[r], 0);
  }

  container.innerHTML = html || '<div class="px-3 py-2 text-[10px] text-slate-600">No tasks</div>';
}

function treeClick(id) {
  treeSelId = id;
  document.querySelectorAll('.tree-row').forEach(function(r) {
    var nid = parseInt(r.closest('.tree-node').dataset.id);
    r.classList.toggle('t-sel', nid === id);
  });
  var entry = TASK_MAP[id];
  if (entry) renderDetail(entry.task);
}

function treeToggle(e, id) {
  e.stopPropagation();
  treeExpanded[id] = !treeExpanded[id];
  var tc  = document.getElementById('tc-' + id);
  var tog = document.querySelector('.tree-node[data-id="' + id + '"] > .tree-row .tree-tog');
  if (tc)  tc.style.display = treeExpanded[id] ? '' : 'none';
  if (tog) tog.classList.toggle('open', treeExpanded[id]);
}

// Expand ancestors of a given id so it's visible in the tree
function treeReveal(id) {
  var entry = TASK_MAP[id];
  if (!entry) return;
  var pid = entry.task.parent;
  while (pid && pid > 0 && TASK_MAP[pid]) {
    if (!treeExpanded[pid]) {
      treeExpanded[pid] = true;
      var tc  = document.getElementById('tc-' + pid);
      var tog = document.querySelector('.tree-node[data-id="' + pid + '"] > .tree-row .tree-tog');
      if (tc)  tc.style.display = '';
      if (tog) tog.classList.add('open');
    }
    pid = TASK_MAP[pid].task.parent;
  }
  // Scroll the tree node into view
  var node = document.querySelector('#tree-container .tree-node[data-id="' + id + '"] > .tree-row');
  if (node) node.scrollIntoView({ block: 'nearest' });
}

var treeFilterTimer = null;
function treeFilter() {
  clearTimeout(treeFilterTimer);
  treeFilterTimer = setTimeout(function() {
    renderTree(document.getElementById('tree-search').value);
  }, 150);
}

// ── Type badge colour ────────────────────────────────────────────────────────
function typeColor(type) {
  switch (type) {
    case 'uint32':  return 'bg-blue-500/10 text-blue-400 border-blue-500/30';
    case 'int32':   return 'bg-indigo-500/10 text-indigo-400 border-indigo-500/30';
    case 'float':   return 'bg-sky-500/10 text-sky-400 border-sky-500/30';
    case 'bool':    return 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30';
    case 'wstr30':
    case 'wstr30b': return 'bg-violet-500/10 text-violet-400 border-violet-500/30';
    default:        return 'bg-slate-800 text-slate-400 border-slate-700';
  }
}

// ── Format a single field value ──────────────────────────────────────────────
function formatValue(f, v) {
  if (f.fmt === 'pct') {
    var pct = parseFloat(v);
    if (pct === 0) return '<span class="text-slate-700">—</span>';
    return '<span class="text-sky-300">' + (pct * 100).toFixed(1) + '%</span>';
  }
  if (f.type === 'bool') {
    return v ? '<span class="text-emerald-400">true</span>'
             : '<span class="text-slate-700">false</span>';
  }
  if (f.key === 'type') {
    return '<span class="text-violet-400">' + v + '</span>'
         + ' <span class="text-slate-500">(' + esc(TYPE_NAMES[v] || 'unknown') + ')</span>';
  }
  if (f.key === 'method') {
    return '<span class="text-cyan-400">' + v + '</span>'
         + ' <span class="text-slate-500">(' + esc(METHOD_NAMES[v] || 'unknown') + ')</span>';
  }
  if (f.key === 'finish_type') {
    return '<span class="text-cyan-400">' + v + '</span>'
         + ' <span class="text-slate-500">(' + esc(FINISH_TYPES[v] || 'unknown') + ')</span>';
  }
  if (f.key === 'award_type_s' || f.key === 'award_type_f') {
    return '<span class="text-amber-400">' + v + '</span>'
         + ' <span class="text-slate-500">(' + esc(AWARD_TYPES[v] || 'unknown') + ')</span>';
  }
  if (f.key === 'parent' || f.key === 'first_child' || f.key === 'prev_sibling' || f.key === 'next_sibling') {
    return v > 0
      ? '<span class="text-violet-400 cursor-pointer underline underline-offset-2" onclick="jumpToTask(' + v + ')">' + v + '</span>'
      : '<span class="text-slate-700">—</span>';
  }
  if (f.key === 'delv_npc' || f.key === 'award_npc') {
    return v > 0 ? '<span class="text-amber-400">' + v + '</span>'
                 : '<span class="text-slate-700">—</span>';
  }
  if (f.key === 'name') {
    return '<span class="text-slate-200">' + (esc(v) || '—') + '</span>';
  }
  if (f.type === 'float') {
    return '<span class="text-sky-300">' + parseFloat(v).toFixed(4) + '</span>';
  }
  if (typeof v === 'number') {
    return '<span class="text-amber-300">' + v + '</span>';
  }
  return '<span class="text-slate-300">' + esc(String(v)) + '</span>';
}

// ── Variable-array section renderer ─────────────────────────────────────────
// Renders a titled section of array entries as expanded two-column field rows.
function renderArraySection(title, items, fieldDefs) {
  if (!items || items.length === 0) return '';
  var html = '<tr><td colspan="2" class="px-3 pt-4 pb-1.5 border-b border-slate-800/60">'
           + '<span class="text-[9px] text-slate-600 uppercase tracking-widest font-semibold">' + esc(title) + '</span>'
           + '</td></tr>';
  for (var i = 0; i < items.length; i++) {
    var item = items[i];
    html += '<tr class="border-b border-slate-800/50">'
          + '<td colspan="2" class="px-3 pt-2 pb-0.5 bg-slate-900/50">'
          + '<span class="text-[10px] text-slate-500 mono">[' + i + ']</span>'
          + '</td></tr>';
    for (var fi = 0; fi < fieldDefs.length; fi++) {
      var f = fieldDefs[fi];
      var v = item[f.key];
      if (v === null || v === undefined) continue;
      // Skip zero/false defaults unless it's a count field
      if (f.type === 'bool' && !v) continue;
      if (f.fmt !== 'pct' && (f.type === 'uint32' || f.type === 'int32') && v === 0) continue;
      if (f.fmt === 'pct' && parseFloat(v) === 0) continue;

      var hexOff = '0x' + (f.off >>> 0).toString(16).toUpperCase().padStart(2, '0');
      var tbadge = typeColor(f.type);
      var disp   = formatValue(f, v);

      html += '<tr class="border-b border-slate-800/40 hover:bg-slate-800/20 transition-colors">'
            + '<td class="px-3 py-1.5 align-top pl-7" style="width:220px">'
            +   '<div class="text-slate-300 mb-1" title="' + esc(f.desc) + '">' + esc(f.key) + '</div>'
            +   '<div class="flex gap-1 flex-wrap">'
            +     '<span class="inline-flex px-1 py-0.5 rounded text-[9px] border ' + tbadge + '">' + esc(f.type) + '</span>'
            +     '<span class="inline-flex px-1 py-0.5 rounded text-[9px] bg-slate-800 text-slate-500 border border-slate-700">+0x' + hexOff.slice(2) + '</span>'
            +   '</div>'
            + '</td>'
            + '<td class="px-3 py-1.5 align-top">' + disp + '</td>'
            + '</tr>';
    }
  }
  return html;
}

// ── Record viewer ────────────────────────────────────────────────────────────
function renderDetail(t) {
  // Update header bar
  var ttype  = TYPE_NAMES[t.type] || ('Type-' + t.type);
  var header = document.getElementById('record-header');
  header.innerHTML =
      '<span class="text-slate-200 font-medium">' + esc(t.name || '(unnamed)') + '</span>'
    + '<span class="px-1.5 py-0.5 rounded text-[10px] bg-violet-500/15 border border-violet-500/30 text-violet-400">' + esc(ttype) + '</span>'
    + '<span class="px-1.5 py-0.5 rounded text-[10px] bg-slate-800 border border-slate-700 text-slate-400">ID ' + t.id + '</span>'
    + '<span class="px-1.5 py-0.5 rounded text-[10px] bg-slate-800 border border-slate-700 text-slate-500">Pack ' + t.pack + '</span>';
  header.style.display = 'flex';

  // Build two-column record table
  var html = '<table class="w-full text-[11px] mono border-collapse">'
    + '<thead class="sticky top-0 bg-[#0d1117] z-10">'
    + '<tr class="border-b border-slate-800">'
    + '<th class="text-left px-3 py-1.5 font-normal text-slate-500" style="width:220px">Field</th>'
    + '<th class="text-left px-3 py-1.5 font-normal text-slate-500">Value</th>'
    + '</tr></thead><tbody>';

  var grpEntries = Object.entries(GROUP_LABELS);
  for (var gi = 0; gi < grpEntries.length; gi++) {
    var grp    = grpEntries[gi][0];
    var label  = grpEntries[gi][1];
    var fields = FBG[grp] || [];

    // Decide whether group has anything to show
    var always = (grp === 'identity' || grp === 'completion');
    var hasValue = always;
    if (!hasValue) {
      for (var fi = 0; fi < fields.length; fi++) {
        var fv = t[fields[fi].key];
        if (fv === null || fv === undefined) continue;
        if (fields[fi].type === 'bool' && fv) { hasValue = true; break; }
        if (typeof fv === 'number' && fv !== 0) { hasValue = true; break; }
        if (typeof fv === 'string' && fv !== '') { hasValue = true; break; }
      }
    }
    if (!hasValue) continue;

    // Group header row
    html += '<tr><td colspan="2" class="px-3 pt-4 pb-1.5 border-b border-slate-800/60">'
          + '<span class="text-[9px] text-slate-600 uppercase tracking-widest font-semibold">' + esc(label) + '</span>'
          + '</td></tr>';

    for (var fi2 = 0; fi2 < fields.length; fi2++) {
      var f = fields[fi2];
      var v = t[f.key];
      if (v === null || v === undefined) continue;
      if (!always) {
        if (f.type === 'bool'   && !v)   continue;
        if (typeof v === 'number' && v === 0) continue;
        if (typeof v === 'string' && v === '') continue;
      }

      var hexOff = '0x' + (f.off >>> 0).toString(16).toUpperCase().padStart(4, '0');
      var tbadge = typeColor(f.type);
      var disp   = formatValue(f, v);

      html += '<tr class="border-b border-slate-800/40 hover:bg-slate-800/20 transition-colors">'
            + '<td class="px-3 py-2 align-top" style="width:220px">'
            +   '<div class="text-slate-300 mb-1" title="' + esc(f.desc) + '">' + esc(f.key) + '</div>'
            +   '<div class="flex gap-1 flex-wrap">'
            +     '<span class="inline-flex px-1 py-0.5 rounded text-[9px] border ' + tbadge + '">' + esc(f.type) + '</span>'
            +     '<span class="inline-flex px-1 py-0.5 rounded text-[9px] bg-slate-800 text-slate-500 border border-slate-700">' + hexOff + '</span>'
            +   '</div>'
            + '</td>'
            + '<td class="px-3 py-2 align-top">' + disp + '</td>'
            + '</tr>';
    }
  }

  // ── Variable data arrays ─────────────────────────────────────────────────
  html += renderArraySection('Monsters to Kill',     t.monsters_wanted, MONSTER_FIELDS);
  html += renderArraySection('Items to Collect',     t.items_wanted,    ITEM_FIELDS);
  html += renderArraySection('Objects to Interact',  t.interobj_wanted, INTEROBJ_FIELDS);

  html += '</tbody></table>';

  var content = document.getElementById('record-content');
  content.innerHTML = html;
  content.style.display = '';
  document.getElementById('record-empty').style.display = 'none';
  document.getElementById('record-body').scrollTop = 0;
}

// ── Jump to task (cross-pack aware) ─────────────────────────────────────────
function jumpToTask(id) {
  // Try current pack's TASK_MAP first
  var entry = TASK_MAP[id];
  if (entry) {
    treeSelId = id;
    document.querySelectorAll('.tree-row').forEach(function(r) {
      var nid = parseInt(r.closest('.tree-node').dataset.id);
      r.classList.toggle('t-sel', nid === id);
    });
    treeReveal(id);
    renderDetail(entry.task);
    return;
  }
  // Not in current pack — fetch from API
  var empty = document.getElementById('record-empty');
  document.getElementById('record-content').style.display = 'none';
  empty.textContent = 'Loading task #' + id + '…';
  empty.style.display = '';
  document.getElementById('record-header').style.display = 'none';
  fetchTask(id, function(t) {
    if (!t) {
      empty.textContent = 'Task #' + id + ' not found.';
      return;
    }
    renderDetail(t);
  });
}

function fetchTask(id, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', 'task_api.php?action=find&id=' + id, true);
  xhr.onload = function() {
    if (xhr.status === 200) {
      try { cb(JSON.parse(xhr.responseText)); } catch(e) { cb(null); }
    } else { cb(null); }
  };
  xhr.onerror = function() { cb(null); };
  xhr.send();
}

// ── Highlight on load (from ?highlight=ID) ───────────────────────────────────
if (HIGHLIGHT_ID > 0) {
  var hlEntry = TASK_MAP[HIGHLIGHT_ID];
  if (hlEntry) {
    treeSelId = HIGHLIGHT_ID;
    renderDetail(hlEntry.task);
    treeReveal(HIGHLIGHT_ID);
    document.querySelectorAll('.tree-row').forEach(function(r) {
      var nid = parseInt(r.closest('.tree-node').dataset.id);
      r.classList.toggle('t-sel', nid === HIGHLIGHT_ID);
    });
  } else {
    fetchTask(HIGHLIGHT_ID, function(t) { if (t) renderDetail(t); });
  }
}

// ── Global search modal ──────────────────────────────────────────────────────
var gsTimer = null;
var gsXhr   = null;

function openGlobalSearch() {
  document.getElementById('gs-overlay').style.display = 'flex';
  document.getElementById('gs-input').focus();
}

function closeGlobalSearch() {
  document.getElementById('gs-overlay').style.display = 'none';
  if (gsXhr) { gsXhr.abort(); gsXhr = null; }
  clearTimeout(gsTimer);
}

function gsKeydown(e) {
  if (e.key === 'Escape') closeGlobalSearch();
}

function gsDebounce() {
  clearTimeout(gsTimer);
  gsTimer = setTimeout(runGlobalSearch, 280);
}

function runGlobalSearch() {
  var q = document.getElementById('gs-input').value.trim();
  var status = document.getElementById('gs-status');
  var results = document.getElementById('gs-results');
  if (q.length < 1) { results.innerHTML = ''; status.textContent = ''; return; }

  status.textContent = 'searching…';
  results.innerHTML = '';

  if (gsXhr) gsXhr.abort();
  gsXhr = new XMLHttpRequest();
  gsXhr.open('GET', 'task_api.php?action=search&q=' + encodeURIComponent(q) + '&limit=120', true);
  gsXhr.onload = function() {
    if (gsXhr.status !== 200) { status.textContent = 'error'; return; }
    var data;
    try { data = JSON.parse(gsXhr.responseText); } catch(e) { status.textContent = 'parse error'; return; }
    status.textContent = data.length + ' result' + (data.length === 1 ? '' : 's');
    renderSearchResults(data);
  };
  gsXhr.onerror = function() { status.textContent = 'request failed'; };
  gsXhr.send();
}

function renderSearchResults(tasks) {
  var el = document.getElementById('gs-results');
  if (tasks.length === 0) {
    el.innerHTML = '<div class="p-4 text-slate-600 text-[11px] mono text-center">No tasks found</div>';
    return;
  }
  var html = '';
  for (var i = 0; i < tasks.length; i++) {
    var t = tasks[i];
    var typeName = TYPE_NAMES[t.type] || ('Type-' + t.type);
    var levMin = t.prem_lev_min > 0 ? t.prem_lev_min : '';
    var levMax = t.prem_lev_max > 0 ? t.prem_lev_max : '';
    var lev = levMin ? (levMax ? levMin + '–' + levMax : levMin + '+') : '';
    var viewUrl = 'tasks.php?pack=' + t.pack + '&highlight=' + t.id;
    html += '<div class="gs-row flex items-center gap-2 px-3 py-2 border-b border-slate-800/50 hover:bg-slate-800/60 cursor-pointer transition-colors"'
          + ' onclick="gsSelectTask(' + i + ')">'
          + '<span class="shrink-0 px-1.5 py-0.5 rounded text-[9px] mono bg-slate-800 border border-slate-700 text-slate-500">P' + t.pack + '</span>'
          + '<span class="text-violet-400 mono text-[11px] shrink-0 w-14">' + t.id + '</span>'
          + '<span class="text-slate-200 text-[11px] flex-1 truncate">' + esc(t.name || '(unnamed)') + '</span>'
          + '<span class="text-slate-600 text-[10px] shrink-0">' + esc(typeName) + '</span>'
          + (lev ? '<span class="text-slate-600 text-[10px] shrink-0 ml-1">Lv' + lev + '</span>' : '')
          + '<a href="' + viewUrl + '" onclick="event.stopPropagation()" title="Open in pack"'
          + '   class="shrink-0 text-slate-700 hover:text-violet-400 ml-1 transition-colors">'
          + '<svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>'
          + '</a>'
          + '</div>';
  }
  el.innerHTML = html;
  // store tasks on element for gsSelectTask
  el._tasks = tasks;
}

function gsSelectTask(idx) {
  var tasks = document.getElementById('gs-results')._tasks;
  if (!tasks || !tasks[idx]) return;
  var t = tasks[idx];

  // Highlight selected row
  var rows = document.querySelectorAll('.gs-row');
  for (var i = 0; i < rows.length; i++) rows[i].classList.remove('bg-slate-800');
  rows[idx].classList.add('bg-slate-800');

  // If task has full data (from search with varData=false, monsters_wanted may be absent)
  // Fetch full data
  var detail = document.getElementById('gs-detail');
  detail.style.display = 'block';
  detail.innerHTML = '<span class="text-slate-600">Loading…</span>';

  if (t.monsters_wanted !== undefined) {
    renderGsDetail(t);
  } else {
    fetchTask(t.id, function(full) {
      renderGsDetail(full || t);
    });
  }
}

function renderGsDetail(t) {
  var detail = document.getElementById('gs-detail');
  var typeName = TYPE_NAMES[t.type] || ('Type-' + t.type);
  var viewUrl  = 'tasks.php?pack=' + t.pack + '&highlight=' + t.id;

  var html = '<div class="mb-2 pb-2 border-b border-slate-800 flex items-start justify-between gap-2">'
           + '<div>'
           + '<div class="text-slate-200 text-[13px] font-medium">' + esc(t.name || '(unnamed)') + '</div>'
           + '<div class="flex gap-1 mt-1">'
           + '<span class="px-1.5 py-0.5 rounded text-[9px] bg-violet-500/15 border border-violet-500/30 text-violet-400">' + esc(typeName) + '</span>'
           + '<span class="px-1.5 py-0.5 rounded text-[9px] bg-slate-800 border border-slate-700 text-slate-400">ID ' + t.id + '</span>'
           + '<span class="px-1.5 py-0.5 rounded text-[9px] bg-slate-800 border border-slate-700 text-slate-500">Pack ' + t.pack + '</span>'
           + '</div></div>'
           + '<a href="' + viewUrl + '" class="shrink-0 flex items-center gap-1 px-2 py-1 rounded border border-violet-700/50 bg-violet-900/20 text-violet-400 hover:bg-violet-800/30 text-[10px] mono transition-colors">'
           + 'Open in pack <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>'
           + '</a>'
           + '</div>';

  // Chain info
  var chain = [];
  if (t.parent > 0) chain.push('Parent: <span class="text-violet-400 cursor-pointer underline" onclick="closeGlobalSearch();jumpToTask(' + t.parent + ')">' + t.parent + '</span>');
  if (t.first_child > 0) chain.push('First child: <span class="text-violet-400 cursor-pointer underline" onclick="closeGlobalSearch();jumpToTask(' + t.first_child + ')">' + t.first_child + '</span>');
  if (t.prev_sibling > 0) chain.push('Prev sibling: <span class="text-violet-400 cursor-pointer underline" onclick="closeGlobalSearch();jumpToTask(' + t.prev_sibling + ')">' + t.prev_sibling + '</span>');
  if (t.next_sibling > 0) chain.push('Next sibling: <span class="text-violet-400 cursor-pointer underline" onclick="closeGlobalSearch();jumpToTask(' + t.next_sibling + ')">' + t.next_sibling + '</span>');
  if (chain.length > 0) {
    html += '<div class="mb-2 text-[10px] mono flex flex-wrap gap-x-3 gap-y-0.5 text-slate-500">' + chain.join('  ') + '</div>';
  }

  // Completion
  var methName = METHOD_NAMES[t.method] || ('Method-' + t.method);
  html += '<div class="text-[10px] text-slate-600 mb-1">Method: <span class="text-slate-400">' + esc(methName) + '</span>';
  if (t.prem_lev_min > 0) html += '  Level: <span class="text-slate-400">' + t.prem_lev_min + (t.prem_lev_max > 0 ? '–' + t.prem_lev_max : '+') + '</span>';
  html += '</div>';

  if (t.monsters_wanted && t.monsters_wanted.length > 0) {
    html += '<div class="text-[10px] text-slate-600 mb-0.5">Monsters:</div><div class="flex flex-wrap gap-1 mb-2">';
    for (var mi = 0; mi < t.monsters_wanted.length; mi++) {
      var m = t.monsters_wanted[mi];
      html += '<span class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-amber-400 text-[10px]">ID ' + m.monster_id + ' ×' + m.monster_num + '</span>';
    }
    html += '</div>';
  }
  if (t.items_wanted && t.items_wanted.length > 0) {
    html += '<div class="text-[10px] text-slate-600 mb-0.5">Items:</div><div class="flex flex-wrap gap-1 mb-2">';
    for (var ii = 0; ii < t.items_wanted.length; ii++) {
      var it = t.items_wanted[ii];
      html += '<span class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-cyan-400 text-[10px]">ID ' + it.item_id + ' ×' + it.item_num + '</span>';
    }
    html += '</div>';
  }

  detail.innerHTML = html;
}

// Close modal on overlay click
document.getElementById('gs-overlay').addEventListener('click', function(e) {
  if (e.target === this) closeGlobalSearch();
});

// Ctrl+G shortcut
document.addEventListener('keydown', function(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 'g') { e.preventDefault(); openGlobalSearch(); }
  if (e.key === 'Escape') closeGlobalSearch();
});

// ── Init tree ────────────────────────────────────────────────────────────────
renderTree('');

// ── Utilities ────────────────────────────────────────────────────────────────
function esc(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Dropdowns ────────────────────────────────────────────────────────────────
function toggleDD(id) {
  var el   = document.getElementById(id).querySelector('.dd-menu');
  var open = el.classList.contains('open');
  document.querySelectorAll('.dd-menu').forEach(function(m){ m.classList.remove('open'); });
  if (!open) el.classList.add('open');
}

document.addEventListener('click', function(e) {
  if (!e.target.closest('[id^="dd-"]')) {
    document.querySelectorAll('.dd-menu').forEach(function(m){ m.classList.remove('open'); });
  }
});
</script>
</body>
</html>
