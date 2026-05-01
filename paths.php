<?php
/**
 * paths.php — Path.data viewer interface.
 *
 * Displays all entries from path.data in a searchable table.
 * Data reading is handled by classes/PathDataReader.php.
 */

require __DIR__ . '/classes/PathDataReader.php';

$isCli = (PHP_SAPI === 'cli');

try {
    $entries = readPathData(PATH_DATA_FILE);
    $count   = count($entries);

    if ($isCli) {
        echo "path.data — {$count} entries\n";
        echo str_repeat('-', 60) . "\n";
        foreach ($entries as $entry) {
            printf("  [%10u]  %s\n", $entry['id'], $entry['path']);
        }
        exit(0);
    }

} catch (RuntimeException $e) {
    if ($isCli) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $entries = [];
    $count   = 0;
    $loadError = $e->getMessage();
}

$loadError = $loadError ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Path Viewer — ElementsViewer</title>
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
  .hidden-row{display:none !important}
</style>
</head>
<body class="flex flex-col h-screen overflow-hidden">

<!-- ░░ TOPBAR ░░ -->
<header class="h-11 shrink-0 bg-[#0d1117] border-b border-slate-800 flex items-center px-4 gap-3 z-50 relative">
  <!-- Logo -->
  <div class="flex items-center gap-2 mr-1">
    <div class="w-5 h-5 rounded bg-emerald-500/15 border border-emerald-500/40 flex items-center justify-center">
      <svg class="w-3 h-3 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
    </div>
    <span class="mono font-semibold text-[13px] text-slate-100 tracking-tight">ElementsViewer</span>
    <span class="mono text-[11px] text-slate-400 border border-emerald-800/50 bg-emerald-950/30 rounded px-1.5">Path Viewer</span>
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
         class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-cyan-400 transition-colors">
        <div class="w-4 h-4 rounded bg-cyan-500/15 border border-cyan-500/40 flex items-center justify-center shrink-0">
          <div class="w-1.5 h-1.5 rounded-sm bg-cyan-400"></div>
        </div>
        Elements Viewer
      </a>
      <a href="search.php"
         class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-slate-300 hover:bg-slate-800 hover:text-purple-400 transition-colors">
        <div class="w-4 h-4 rounded bg-purple-500/15 border border-purple-500/40 flex items-center justify-center shrink-0">
          <svg class="w-2.5 h-2.5 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>
        Advanced Search
      </a>
      <div class="border-t border-slate-800 my-1"></div>
      <a href="paths.php"
         class="flex items-center gap-2.5 px-3 py-1.5 text-[11px] mono text-emerald-400 bg-emerald-950/20 hover:bg-slate-800 transition-colors">
        <div class="w-4 h-4 rounded bg-emerald-500/15 border border-emerald-500/40 flex items-center justify-center shrink-0">
          <svg class="w-2.5 h-2.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
        </div>
        Path Viewer
        <span class="ml-auto text-emerald-600 text-[9px] mono uppercase tracking-widest">current</span>
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

  <!-- Stats (right side) -->
  <div class="ml-auto flex items-center gap-3">
    <?php if ($loadError): ?>
      <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-red-950 text-red-300 border-red-800">error</span>
    <?php else: ?>
      <div class="flex items-center gap-1.5">
        <span class="text-[11px] mono text-slate-400">Entries</span>
        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-slate-900 text-slate-300 border-slate-700"><?= $count ?></span>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="text-[11px] mono text-slate-500">source</span>
        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] mono border bg-emerald-950/40 text-emerald-400 border-emerald-800/50">path.data</span>
      </div>
    <?php endif; ?>
  </div>
</header>

<?php if ($loadError): ?>
<!-- Error state -->
<div class="flex-1 flex items-center justify-center">
  <div class="bg-[#0d1117] border border-red-900/50 rounded-md p-6 max-w-lg w-full mx-4 text-center">
    <svg class="w-8 h-8 text-red-400 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <p class="text-[12px] mono text-red-300 font-semibold mb-1">Failed to load path.data</p>
    <p class="text-[11px] mono text-slate-500"><?= htmlspecialchars($loadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
  </div>
</div>
<?php else: ?>

<!-- ░░ FILTER BAR ░░ -->
<div class="shrink-0 bg-[#090c12] border-b border-slate-800 px-4 py-2 flex items-center gap-3">
  <svg class="w-3.5 h-3.5 text-slate-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
  <input type="text" id="filter-input"
    placeholder="Filter by ID or path…"
    oninput="filterTable(this.value)"
    class="flex-1 bg-transparent border-none outline-none text-[12px] mono text-slate-200 placeholder-slate-600"/>
  <span id="filter-count" class="text-[11px] mono text-slate-600 shrink-0"><?= $count ?> entries</span>
  <button type="button" onclick="clearFilter()"
    id="clear-btn"
    class="hidden items-center gap-1 px-2 py-0.5 rounded border text-[10px] mono text-slate-400 border-slate-700/50 hover:border-slate-600 hover:text-slate-200 bg-slate-800/40 hover:bg-slate-800 transition-colors">
    clear
  </button>
</div>

<!-- ░░ TABLE ░░ -->
<div class="flex-1 overflow-auto">
  <table class="w-full text-[12px] mono" id="paths-table">
    <thead class="sticky top-0 bg-[#0d1117] border-b border-slate-800 z-10">
      <tr>
        <th class="px-4 py-2.5 text-left text-[10px] uppercase tracking-widest text-slate-500 font-medium w-36">Path ID</th>
        <th class="px-4 py-2.5 text-left text-[10px] uppercase tracking-widest text-slate-500 font-medium">Path</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-800/50" id="paths-tbody">
    <?php foreach ($entries as $entry): ?>
      <tr class="hover:bg-slate-800/30 transition-colors">
        <td class="px-4 py-1.5 text-orange-400 tabular-nums"><?= $entry['id'] ?></td>
        <td class="px-4 py-1.5 text-slate-300"><?= htmlspecialchars($entry['path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($count === 0): ?>
  <div class="py-16 text-center">
    <p class="text-[12px] mono text-slate-500">No entries found in path.data.</p>
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<script>
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

// ── Filter ───────────────────────────────────────────────────────────────────
let totalCount = <?= $count ?>;

function filterTable(q) {
  q = q.trim().toLowerCase();
  const rows  = document.querySelectorAll('#paths-tbody tr');
  const clearBtn = document.getElementById('clear-btn');
  const countEl  = document.getElementById('filter-count');
  let visible = 0;
  rows.forEach(function(row) {
    const match = q === '' || row.textContent.toLowerCase().includes(q);
    row.classList.toggle('hidden-row', !match);
    if (match) visible++;
  });
  countEl.textContent = q === '' ? totalCount + ' entries' : visible + ' of ' + totalCount;
  clearBtn.classList.toggle('hidden', q === '');
  clearBtn.classList.toggle('flex',   q !== '');
}

function clearFilter() {
  const input = document.getElementById('filter-input');
  input.value = '';
  filterTable('');
  input.focus();
}
</script>
</body>
</html>
