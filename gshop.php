<?php
/**
 * gshop.php — GShop (Cash Shop) Data Viewer
 *
 * Displays items from gshop.data / gshop1.data / gshop2.data with
 * category filtering, full-text search, and item detail panel.
 *
 * Reader: classes/GShopDataReader.php
 */

require __DIR__ . '/classes/GShopDataReader.php';

$dataDir = realpath(__DIR__ . '/data');

$validFiles = ['gshop.data', 'gshop1.data', 'gshop2.data'];
$selectedFile = basename($_GET['file'] ?? 'gshop.data');
if (!in_array($selectedFile, $validFiles, true)) $selectedFile = 'gshop.data';

$loadError = '';
$data = ['timestamp' => 0, 'items' => [], 'categories' => []];

$filePath = $dataDir . '/' . $selectedFile;
if ($dataDir && is_file($filePath)) {
    try {
        $data = GShopDataReader::parse($filePath);
    } catch (RuntimeException $e) {
        $loadError = $e->getMessage();
    }
} else {
    $loadError = "File not found: data/{$selectedFile}";
}

$items      = $data['items'];
$categories = $data['categories'];
$timestamp  = $data['timestamp'];
$itemCount  = count($items);

// Build category name lookup: index => ['name'=>..., 'subs'=>[...]]
$catMap = [];
foreach ($categories as $idx => $cat) {
    $catMap[$idx] = $cat;
}

// Per-category item counts
$countByMain = [];
$countBySub  = [];
foreach ($items as $item) {
    $m = $item['main_type'];
    $s = $item['sub_type'];
    $countByMain[$m] = ($countByMain[$m] ?? 0) + 1;
    $countBySub[$m][$s] = ($countBySub[$m][$s] ?? 0) + 1;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>GShop Viewer — ElementsViewer</title>
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
  .hidden-row{display:none!important}
  .cat-active{background:rgba(251,191,36,0.08)!important;border-left-color:#fbbf24!important;color:#fbbf24!important}
  .sub-active{background:rgba(251,191,36,0.05)!important;color:#fbbf24!important}
  .row-selected{background:rgba(251,191,36,0.07)!important;outline:1px solid rgba(251,191,36,0.2)!important;outline-offset:-1px}
</style>
</head>
<body class="flex flex-col h-screen overflow-hidden">

<!-- ░░ TOPBAR ░░ -->
<header class="h-11 shrink-0 bg-[#0d1117] border-b border-slate-800 flex items-center px-4 gap-3 z-50 relative">
  <!-- Logo -->
  <div class="flex items-center gap-2 mr-1">
    <div class="w-5 h-5 rounded bg-amber-500/15 border border-amber-500/40 flex items-center justify-center">
      <svg class="w-3 h-3 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
    </div>
    <span class="mono font-semibold text-[13px] text-slate-100 tracking-tight">ElementsViewer</span>
    <span class="mono text-[11px] text-slate-400 border border-amber-800/50 bg-amber-950/30 rounded px-1.5">GShop Viewer</span>
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
      <div class="border-t border-slate-800 my-1"></div>
      <a href="gshop.php" class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-amber-400 bg-amber-950/20 hover:bg-slate-800 transition-colors">
        <div class="w-4 h-4 rounded bg-amber-500/15 border border-amber-500/40 flex items-center justify-center shrink-0"><svg class="w-2.5 h-2.5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg></div>
        GShop Viewer
        <span class="ml-auto text-amber-700 text-[9px] mono uppercase tracking-widest">current</span>
      </a>
      <a href="tasks.php" class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-violet-400 transition-colors">
        <div class="w-4 h-4 rounded bg-violet-500/15 border border-violet-500/40 flex items-center justify-center shrink-0"><svg class="w-2.5 h-2.5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div>
        Task Viewer
      </a>
    </div>
  </div>
  <div class="w-px h-4 bg-slate-800"></div>

  <!-- File picker -->
  <div class="relative" id="dd-file">
    <button type="button" onclick="toggleDD('dd-file')"
      class="flex items-center gap-2 px-2.5 py-1 rounded border text-[11px] mono bg-slate-800/50 hover:bg-slate-800 border-slate-700/50 hover:border-slate-600 text-slate-300 transition-colors">
      <span class="text-slate-600">file</span>
      <span class="text-amber-400 font-semibold"><?= h($selectedFile) ?></span>
      <svg class="w-3 h-3 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div class="dd-menu hidden absolute top-full mt-1 left-0 bg-slate-900 border border-slate-700 rounded-md shadow-2xl z-50 min-w-[11rem] py-0.5">
      <?php foreach ($validFiles as $f):
        $isSel = $f === $selectedFile;
        $fsize = is_file($dataDir . '/' . $f) ? number_format(filesize($dataDir . '/' . $f) / 1024, 0) . ' KB' : 'missing';
      ?>
        <a href="?file=<?= h(urlencode($f)) ?>"
           class="flex items-center justify-between px-3 py-1.5 text-[11px] mono <?= $isSel ? 'text-amber-400' : 'text-slate-300' ?> hover:bg-slate-800">
          <span><?= h($f) ?></span>
          <span class="text-slate-600 text-[10px] ml-3"><?= h($fsize) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Stats -->
  <?php if (!$loadError): ?>
  <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-slate-900 text-slate-300 border-slate-700">
    <?= $itemCount ?> items
  </span>
  <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-slate-900 text-slate-500 border-slate-700">
    <?= count($categories) ?> categories
  </span>
  <?php endif; ?>

  <!-- Timestamp (right) -->
  <div class="ml-auto flex items-center gap-2 px-2.5 py-1 rounded border text-[11px] mono bg-slate-800/40 border-slate-700/40 text-slate-500">
    <?php if ($timestamp > 0): ?>
      <span class="text-slate-600">exported</span>
      <span class="text-slate-400"><?= gmdate('Y-m-d H:i', $timestamp) ?> UTC</span>
    <?php endif; ?>
  </div>
</header>

<?php if ($loadError): ?>
<div class="flex-1 flex items-center justify-center">
  <div class="bg-[#0d1117] border border-red-900/50 rounded-md p-6 max-w-lg w-full mx-4 text-center">
    <svg class="w-8 h-8 text-red-400 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <p class="text-[12px] mono text-red-300 font-semibold mb-1">Failed to load <?= h($selectedFile) ?></p>
    <p class="text-[11px] mono text-slate-500"><?= h($loadError) ?></p>
  </div>
</div>
<?php else: ?>

<!-- ░░ MAIN LAYOUT ░░ -->
<div class="flex flex-1 overflow-hidden">

  <!-- ░░ CATEGORY SIDEBAR ░░ -->
  <aside class="w-52 shrink-0 bg-[#0d1117] border-r border-slate-800 flex flex-col overflow-hidden">
    <div class="px-3 py-2 border-b border-slate-800 shrink-0">
      <span class="text-[10px] mono uppercase tracking-widest text-slate-500">Categories</span>
    </div>
    <div class="flex-1 overflow-y-auto py-1" id="cat-tree">
      <!-- All -->
      <button type="button" data-main="-1" data-sub="-1" onclick="selectCat(-1,-1,this)"
        class="cat-active w-full flex items-center justify-between px-3 py-1.5 text-[11px] mono border-l-2 border-amber-500 text-left transition-colors">
        <span>All items</span>
        <span class="text-[10px] text-amber-600/70"><?= $itemCount ?></span>
      </button>

      <?php foreach ($categories as $mIdx => $cat): ?>
        <?php $mCount = $countByMain[$mIdx] ?? 0; ?>
        <div class="mt-0.5">
          <button type="button" data-main="<?= $mIdx ?>" data-sub="-1" onclick="selectCat(<?= $mIdx ?>,-1,this)"
            class="w-full flex items-center justify-between px-3 py-1.5 text-[11px] mono border-l-2 border-transparent hover:bg-slate-800/50 hover:border-slate-600 text-left transition-colors text-slate-300">
            <span class="font-medium"><?= h($cat['name']) ?></span>
            <span class="text-[10px] text-slate-600"><?= $mCount ?></span>
          </button>
          <?php foreach ($cat['subs'] as $sIdx => $sub): ?>
            <?php $sCount = $countBySub[$mIdx][$sIdx] ?? 0; ?>
            <button type="button" data-main="<?= $mIdx ?>" data-sub="<?= $sIdx ?>" onclick="selectCat(<?= $mIdx ?>,<?= $sIdx ?>,this)"
              class="w-full flex items-center justify-between pl-6 pr-3 py-1 text-[11px] mono border-l-2 border-transparent hover:bg-slate-800/50 hover:border-slate-700 text-left transition-colors text-slate-500 hover:text-slate-300">
              <span><?= h($sub) ?></span>
              <span class="text-[10px] text-slate-700"><?= $sCount ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </aside>

  <!-- ░░ CENTER: search + table ░░ -->
  <div class="flex-1 flex flex-col overflow-hidden">

    <!-- Filter bar -->
    <div class="shrink-0 bg-[#090c12] border-b border-slate-800 px-4 py-2 flex items-center gap-3">
      <svg class="w-3.5 h-3.5 text-slate-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      <input type="text" id="filter-input" placeholder="Search name, ID, description, search key…"
        oninput="applyFilter()"
        class="flex-1 bg-transparent border-none outline-none text-[12px] mono text-slate-200 placeholder-slate-600"/>
      <span id="filter-count" class="text-[11px] mono text-slate-600 shrink-0"></span>
      <button type="button" id="clear-btn" onclick="clearFilter()"
        class="hidden items-center gap-1 px-2 py-0.5 rounded border text-[10px] mono text-slate-400 border-slate-700/50 hover:border-slate-600 hover:text-slate-200 bg-slate-800/40 hover:bg-slate-800 transition-colors">
        clear
      </button>
    </div>

    <!-- Items table -->
    <div class="flex-1 overflow-auto" id="table-scroll">
      <table class="w-full text-[11px] mono" id="items-table">
        <thead class="sticky top-0 bg-[#0d1117] border-b border-slate-800 z-10">
          <tr>
            <th class="px-2 py-2 w-10"></th>
            <th class="px-3 py-2 text-left text-[10px] uppercase tracking-widest text-slate-500 font-medium w-20">ID</th>
            <th class="px-3 py-2 text-left text-[10px] uppercase tracking-widest text-slate-500 font-medium">Name</th>
            <th class="px-3 py-2 text-right text-[10px] uppercase tracking-widest text-slate-500 font-medium w-16">Qty</th>
            <th class="px-3 py-2 text-right text-[10px] uppercase tracking-widest text-slate-500 font-medium w-24">Price (GJ)</th>
            <th class="px-3 py-2 text-left text-[10px] uppercase tracking-widest text-slate-500 font-medium w-28">Duration</th>
            <th class="px-3 py-2 text-left text-[10px] uppercase tracking-widest text-slate-500 font-medium w-20">Category</th>
            <th class="px-3 py-2 text-center text-[10px] uppercase tracking-widest text-slate-500 font-medium w-14">Bonus</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/40" id="items-tbody">
        <?php foreach ($items as $idx => $item):
          $mainName = $catMap[$item['main_type']]['name'] ?? '?';
          $subName  = $catMap[$item['main_type']]['subs'][$item['sub_type']] ?? '';
          $dur      = GShopDataReader::formatDuration($item['duration']);
          $isPerma  = $item['duration'] === 0;
        ?>
          <tr class="hover:bg-slate-800/30 cursor-pointer transition-colors"
              data-idx="<?= $idx ?>"
              data-main="<?= $item['main_type'] ?>"
              data-sub="<?= $item['sub_type'] ?>"
              onclick="selectItem(<?= $idx ?>)"
              data-search="<?= h(strtolower($item['name'] . ' ' . $item['id'] . ' ' . $item['desc'] . ' ' . $item['search_key'])) ?>">
            <td class="px-2 py-1 w-10">
              <img src="gshop_icon.php?p=<?= h(urlencode($item['icon'])) ?>"
                   alt="" width="32" height="32"
                   class="rounded bg-slate-800/60 object-contain"
                   loading="lazy" onerror="this.style.opacity='0'"/>
            </td>
            <td class="px-3 py-1.5 text-amber-400/80 tabular-nums"><?= $item['id'] ?></td>
            <td class="px-3 py-1.5 text-slate-200 max-w-0">
              <span class="block truncate"><?= h($item['name']) ?></span>
            </td>
            <td class="px-3 py-1.5 text-slate-400 tabular-nums text-right"><?= $item['num'] ?></td>
            <td class="px-3 py-1.5 text-emerald-400 tabular-nums text-right font-medium"><?= number_format($item['price'] / 100, 2) ?> GJ</td>
            <td class="px-3 py-1.5 <?= $isPerma ? 'text-slate-600' : 'text-cyan-400' ?>">
              <?= h($dur) ?>
            </td>
            <td class="px-3 py-1.5 text-slate-500 truncate max-w-0">
              <span class="truncate"><?= h($mainName) ?><?= $subName ? ' / ' . h($subName) : '' ?></span>
            </td>
            <td class="px-3 py-1.5 text-center">
              <?php if ($item['has_present']): ?>
                <span class="inline-flex items-center px-1 py-0.5 rounded text-[9px] bg-amber-950/60 text-amber-400 border border-amber-800/50">+gift</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ░░ DETAIL PANEL ░░ -->
  <aside id="detail-panel" class="hidden w-80 shrink-0 bg-[#0d1117] border-l border-slate-800 flex flex-col overflow-hidden">
    <div class="px-3 py-2 border-b border-slate-800 flex items-center justify-between shrink-0">
      <span class="text-[10px] mono uppercase tracking-widest text-slate-500">Item Detail</span>
      <button type="button" onclick="closeDetail()"
        class="w-5 h-5 flex items-center justify-center rounded text-slate-600 hover:text-slate-300 hover:bg-slate-800 transition-colors">
        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="flex-1 overflow-y-auto" id="detail-body"></div>
  </aside>
</div>

<!-- ░░ ITEM DATA as JSON for the detail panel ░░ -->
<script>
const ITEMS = <?php
$jsItems = [];
foreach ($items as $idx => $item) {
    $mainName = $catMap[$item['main_type']]['name'] ?? '';
    $subName  = $catMap[$item['main_type']]['subs'][$item['sub_type']] ?? '';
    $jsItems[$idx] = array_merge($item, [
        'main_name'    => $mainName,
        'sub_name'     => $subName,
        'dur_fmt'      => GShopDataReader::formatDuration($item['duration']),
        'pdur_fmt'     => GShopDataReader::formatDuration($item['present_duration']),
        'vt_start_fmt' => GShopDataReader::formatTimestamp($item['vt_start']),
        'vt_end_fmt'   => GShopDataReader::formatTimestamp($item['vt_end']),
    ]);
}
echo json_encode(array_values($jsItems), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
?>;

// ── Dropdowns ────────────────────────────────────────────────────────────────
function toggleDD(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const menu = el.querySelector('.dd-menu');
  const open = !menu.classList.contains('hidden');
  closeAllDD();
  if (!open) menu.classList.remove('hidden');
}
function closeAllDD() {
  document.querySelectorAll('.dd-menu').forEach(m => m.classList.add('hidden'));
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('[id^="dd-"]')) closeAllDD();
});

// ── Category filter ───────────────────────────────────────────────────────────
let activeMain = -1, activeSub = -1;

function selectCat(main, sub, btn) {
  activeMain = main; activeSub = sub;
  document.querySelectorAll('#cat-tree button').forEach(b => {
    b.classList.remove('cat-active','sub-active');
    b.classList.add('border-transparent');
    b.style.borderLeftColor = '';
  });
  btn.classList.add(sub === -1 ? 'cat-active' : 'sub-active');
  applyFilter();
}

// ── Text filter ───────────────────────────────────────────────────────────────
function applyFilter() {
  const q = document.getElementById('filter-input').value.trim().toLowerCase();
  const rows = document.querySelectorAll('#items-tbody tr');
  const clearBtn = document.getElementById('clear-btn');
  const countEl  = document.getElementById('filter-count');
  let visible = 0;

  rows.forEach(row => {
    const main = parseInt(row.dataset.main);
    const sub  = parseInt(row.dataset.sub);
    const catOk = activeMain === -1
      || (activeSub === -1 ? main === activeMain : main === activeMain && sub === activeSub);
    const txtOk = q === '' || (row.dataset.search || '').includes(q);
    const show = catOk && txtOk;
    row.classList.toggle('hidden-row', !show);
    if (show) visible++;
  });

  const total = rows.length;
  countEl.textContent = q !== '' || activeMain !== -1
    ? `${visible} of ${total}`
    : `${total} items`;
  clearBtn.classList.toggle('hidden', q === '');
  clearBtn.classList.toggle('flex', q !== '');
}

function clearFilter() {
  document.getElementById('filter-input').value = '';
  applyFilter();
}

// ── Item detail panel ─────────────────────────────────────────────────────────
let selectedIdx = -1;

function selectItem(idx) {
  if (selectedIdx === idx) { closeDetail(); return; }
  selectedIdx = idx;

  document.querySelectorAll('#items-tbody tr').forEach(r => r.classList.remove('row-selected'));
  const row = document.querySelector(`#items-tbody tr[data-idx="${idx}"]`);
  if (row) row.classList.add('row-selected');

  const panel = document.getElementById('detail-panel');
  panel.classList.remove('hidden');
  panel.classList.add('flex');
  renderDetail(ITEMS[idx]);
}

function closeDetail() {
  selectedIdx = -1;
  document.querySelectorAll('#items-tbody tr').forEach(r => r.classList.remove('row-selected'));
  const panel = document.getElementById('detail-panel');
  panel.classList.add('hidden');
  panel.classList.remove('flex');
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function row(label, val, cls='text-slate-300') {
  if (val === '' || val === null || val === undefined) return '';
  return `<div class="flex gap-2 py-1.5 border-b border-slate-800/50">
    <span class="text-[10px] mono text-slate-600 uppercase tracking-widest w-28 shrink-0 pt-0.5">${escHtml(label)}</span>
    <span class="text-[11px] mono ${cls} break-all leading-relaxed">${escHtml(val)}</span>
  </div>`;
}

function badge(text, cls) {
  return `<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] mono border ${cls}">${escHtml(text)}</span>`;
}

function renderDetail(item) {
  const vtLabels = ['Always', 'Timed (UTC)', 'Weekly', 'Monthly'];
  const vtType   = vtLabels[item.vt_type] || `type ${item.vt_type}`;

  let html = `<div class="p-3 space-y-0.5">`;

  // Header with icon
  const iconUrl = 'gshop_icon.php?p=' + encodeURIComponent(item.icon);
  html += `<div class="pb-3 mb-1 border-b border-slate-800 flex gap-3 items-start">
    <img src="${iconUrl}" alt="" width="64" height="64"
         class="rounded bg-slate-800/60 object-contain shrink-0 border border-slate-700/50"
         onerror="this.style.display='none'"/>
    <div class="min-w-0">
      <div class="text-[13px] mono font-semibold text-amber-300 leading-tight">${escHtml(item.name)}</div>
      <div class="text-[10px] mono text-slate-500 mt-0.5">${escHtml(item.main_name)}${item.sub_name ? ' / ' + escHtml(item.sub_name) : ''}</div>
      <div class="mt-1.5 flex items-center gap-2">
        <span class="text-[11px] mono text-emerald-400 font-semibold">${(item.price / 100).toFixed(2)} GJ</span>
        ${item.duration ? `<span class="text-[10px] mono text-cyan-400">${escHtml(item.dur_fmt)}</span>` : '<span class="text-[10px] mono text-slate-600">Permanent</span>'}
        ${item.has_present ? '<span class="inline-flex items-center px-1 py-0.5 rounded text-[9px] bg-amber-950/60 text-amber-400 border border-amber-800/50">+gift</span>' : ''}
      </div>
    </div>
  </div>`;

  // General
  html += `<div class="text-[9px] mono text-slate-600 uppercase tracking-widest pt-1 pb-0.5">General</div>`;
  html += row('Item ID',    item.id,  'text-amber-400');
  html += row('Amount',     item.num);
  html += row('Local ID',   item.local_id, 'text-slate-500');
  html += row('Price',      (item.price / 100).toFixed(2) + ' Gold Jaden', 'text-emerald-400');
  html += row('Duration',   item.dur_fmt,  item.duration ? 'text-cyan-400' : 'text-slate-600');
  html += row('Discount',   item.discount !== 100 ? item.discount + '%' : '');
  html += row('Bonus',      item.bonus     ? item.bonus + '%' : '');
  html += row('Props',      '0x' + item.props.toString(16).toUpperCase().padStart(8,'0'), 'text-slate-500');

  // Description
  if (item.desc) {
    html += `<div class="text-[9px] mono text-slate-600 uppercase tracking-widest pt-2 pb-0.5">Description</div>
    <div class="text-[11px] mono text-slate-400 leading-relaxed whitespace-pre-wrap py-1">${escHtml(item.desc)}</div>`;
  }

  // Icon
  html += `<div class="text-[9px] mono text-slate-600 uppercase tracking-widest pt-2 pb-0.5">Icon</div>`;
  html += row('Path', item.icon, 'text-slate-500');

  // Valid time
  html += `<div class="text-[9px] mono text-slate-600 uppercase tracking-widest pt-2 pb-0.5">Availability</div>`;
  html += row('Type', vtType);
  if (item.vt_start_fmt) html += row('Start', item.vt_start_fmt + ' UTC', 'text-cyan-400');
  if (item.vt_end_fmt)   html += row('End',   item.vt_end_fmt   + ' UTC', 'text-red-400');
  if (item.vt_param)     html += row('Param', item.vt_param);

  // Search key
  if (item.search_key) html += row('Keywords', item.search_key, 'text-slate-500');

  // Bonus gift
  if (item.has_present) {
    const giftIconUrl = item.present_icon ? 'gshop_icon.php?p=' + encodeURIComponent(item.present_icon) : '';
    html += `<div class="border-t border-slate-800 mt-2 pt-2">
      <div class="text-[9px] mono text-slate-600 uppercase tracking-widest pb-1.5 flex items-center gap-2">
        Bonus Gift
        <span class="inline-flex items-center px-1 py-0 rounded text-[9px] bg-amber-950/60 text-amber-400 border border-amber-800/50">+gift</span>
      </div>
      <div class="flex gap-3 items-start mb-1">
        ${giftIconUrl ? `<img src="${giftIconUrl}" alt="" width="48" height="48"
             class="rounded bg-slate-800/60 object-contain shrink-0 border border-slate-700/50"
             onerror="this.style.display='none'"/>` : ''}
        <div class="min-w-0 space-y-0.5">
          <div class="text-[12px] mono font-semibold text-amber-300">${escHtml(item.present_name)}</div>
          <div class="text-[11px] mono text-slate-400">ID: <span class="text-amber-400">${item.present_id}</span>
            &nbsp;×&nbsp;<span class="text-slate-300">${item.present_count}</span></div>
          <div class="text-[11px] mono ${item.present_duration ? 'text-cyan-400' : 'text-slate-600'}">${escHtml(item.pdur_fmt)}</div>
          ${item.present_bind ? '<div class="text-[10px] mono text-red-400">Bound on obtain</div>' : ''}
        </div>
      </div>`;
    if (item.present_desc) {
      html += `<div class="text-[11px] mono text-slate-400 leading-relaxed whitespace-pre-wrap pt-1">${escHtml(item.present_desc)}</div>`;
    }
    html += `</div>`;
  }

  html += `</div>`;
  document.getElementById('detail-body').innerHTML = html;
}

// ── Init ──────────────────────────────────────────────────────────────────────
applyFilter();
</script>

<?php endif; ?>
</body>
</html>
