<?php
/**
 * task_field_editor.php — Parser Config Editor for Task Viewer
 *
 * Lets you add/edit/delete display fields and critical parsing offsets
 * for any binary version.  Changes are saved to
 *   structures/tasks/{version}/parser_config.json
 * and picked up immediately by TaskDataReader on the next page load.
 */

declare(strict_types=1);
require_once __DIR__ . '/classes/TaskDataReader.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$error   = '';
$success = '';

// ── Handle POST saves ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $version = (int)($_POST['version'] ?? 0);

    // Clone an existing version into a new one
    if ($action === 'clone' && $version > 0) {
        $newVersion = (int)($_POST['new_version'] ?? 0);
        if ($newVersion <= 0) {
            $error = 'Invalid new version number.';
        } else {
            $cfg = TaskDataReader::loadConfig($version);
            $cfg['version']     = $newVersion;
            $cfg['description'] = 'Cloned from version ' . $version . '. Update fields and offsets as needed.';
            if (TaskDataReader::saveConfig($newVersion, $cfg)) {
                $success = "Created config for version $newVersion (cloned from $version).";
                $version = $newVersion;
            } else {
                $error = "Failed to write config for version $newVersion — check directory permissions.";
            }
        }
    }

    // Save full config JSON posted as text
    if ($action === 'save_json' && $version > 0) {
        $raw = $_POST['config_json'] ?? '';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $error = 'Invalid JSON: ' . json_last_error_msg();
        } else {
            $decoded['version'] = $version; // always keep version consistent
            if (TaskDataReader::saveConfig($version, $decoded)) {
                $success = "Saved config for version $version.";
                // Bust the static cache by re-requiring (PHP static cache lives per-process;
                // on next request it will re-read from disk automatically).
            } else {
                $error = "Failed to write config — check directory permissions.";
            }
        }
    }
}

// ── Determine active version ──────────────────────────────────────────────────
$versions       = TaskDataReader::listConfigVersions();
$activeVersion  = (int)($_GET['v'] ?? ($_POST['version'] ?? ($versions[0] ?? 165)));
if (!in_array($activeVersion, $versions, true)) $versions[] = $activeVersion;
sort($versions);

$cfg     = TaskDataReader::loadConfig($activeVersion);
$cfgJson = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Group labels for display
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
$fieldTypes = ['uint32','int32','float','bool','wstr30','wstr30b'];
?>
<!doctype html>
<html lang="en" class="dark">
<head>
  <meta charset="utf-8"/>
  <title>Task Field Editor — ZX Elements</title>
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
    input, select, textarea {
      background: #0f172a; border: 1px solid #334155; color: #cbd5e1;
      border-radius: 4px; outline: none; transition: border-color 0.15s;
    }
    input:focus, select:focus, textarea:focus { border-color: #7c3aed; }
    .btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 12px; border-radius: 4px; font-size: 11px;
      font-family: 'JetBrains Mono', monospace; cursor: pointer;
      border: 1px solid; transition: all 0.15s;
    }
    .btn-violet { background: rgba(124,58,237,0.15); border-color: rgba(124,58,237,0.4); color: #a78bfa; }
    .btn-violet:hover { background: rgba(124,58,237,0.28); }
    .btn-slate  { background: rgba(51,65,85,0.4);  border-color: #334155; color: #94a3b8; }
    .btn-slate:hover  { background: rgba(51,65,85,0.7); }
    .btn-green  { background: rgba(22,163,74,0.15); border-color: rgba(22,163,74,0.4); color: #4ade80; }
    .btn-green:hover  { background: rgba(22,163,74,0.28); }
    .btn-red    { background: rgba(220,38,38,0.15); border-color: rgba(220,38,38,0.4); color: #f87171; }
    .btn-red:hover    { background: rgba(220,38,38,0.28); }
    .badge { display:inline-flex; padding:1px 5px; border-radius:3px; font-size:9px; border:1px solid; }
    .badge-uint32  { background:rgba(59,130,246,.1); color:#60a5fa; border-color:rgba(59,130,246,.3); }
    .badge-int32   { background:rgba(99,102,241,.1); color:#818cf8; border-color:rgba(99,102,241,.3); }
    .badge-float   { background:rgba(14,165,233,.1); color:#38bdf8; border-color:rgba(14,165,233,.3); }
    .badge-bool    { background:rgba(16,185,129,.1); color:#34d399; border-color:rgba(16,185,129,.3); }
    .badge-wstr30  { background:rgba(139,92,246,.1); color:#a78bfa; border-color:rgba(139,92,246,.3); }
    .badge-wstr30b { background:rgba(139,92,246,.1); color:#a78bfa; border-color:rgba(139,92,246,.3); }
    .tab-active { border-bottom: 2px solid #7c3aed; color: #a78bfa; }
  </style>
</head>
<body class="min-h-screen flex flex-col">

<!-- ── TOP BAR ───────────────────────────────────────────────────────────── -->
<header class="sticky top-0 z-40 flex items-center gap-3 px-4 py-2 bg-[#0d1117] border-b border-slate-800 shadow-lg">
  <div class="flex items-center gap-2 shrink-0">
    <div class="w-6 h-6 rounded bg-violet-500/20 border border-violet-500/40 flex items-center justify-center">
      <svg class="w-3.5 h-3.5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
      </svg>
    </div>
    <span class="mono text-[11px] text-slate-400 border border-violet-800/50 bg-violet-950/30 rounded px-1.5">Field Editor</span>
  </div>
  <div class="w-px h-4 bg-slate-800"></div>
  <a href="tasks.php" class="btn btn-slate">← Task Viewer</a>
  <div class="w-px h-4 bg-slate-800"></div>

  <!-- Version picker -->
  <div class="flex items-center gap-2 text-[11px] mono">
    <span class="text-slate-600">Version</span>
    <div class="flex gap-1">
      <?php foreach ($versions as $v): ?>
      <a href="?v=<?= $v ?>"
         class="btn <?= $v === $activeVersion ? 'btn-violet' : 'btn-slate' ?>">
        <?= $v ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Clone form -->
  <form method="post" class="flex items-center gap-1.5 ml-auto">
    <input type="hidden" name="action"  value="clone"/>
    <input type="hidden" name="version" value="<?= $activeVersion ?>"/>
    <input type="number" name="new_version" placeholder="New version…"
      class="w-28 px-2 py-0.5 text-[11px] mono" min="1"/>
    <button type="submit" class="btn btn-slate">Clone →</button>
  </form>
</header>

<?php if ($error): ?>
<div class="mx-4 mt-3 px-3 py-2 rounded border border-red-500/40 bg-red-500/10 text-red-400 text-[11px] mono"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="mx-4 mt-3 px-3 py-2 rounded border border-green-500/40 bg-green-500/10 text-green-400 text-[11px] mono"><?= h($success) ?></div>
<?php endif; ?>

<!-- ── MAIN ───────────────────────────────────────────────────────────────── -->
<div class="flex flex-1 overflow-hidden" style="height: calc(100vh - 41px)">

  <!-- ── LEFT : field table ─────────────────────────────────────────────── -->
  <div class="flex flex-col flex-1 overflow-hidden">

    <!-- Tabs -->
    <div class="flex gap-0 border-b border-slate-800 shrink-0 px-4 text-[11px] mono bg-[#0d1117]">
      <button onclick="showTab('fields')"   id="tab-fields"   class="px-3 py-2 text-slate-400 hover:text-slate-200 tab-active transition-colors">Fields</button>
      <button onclick="showTab('offsets')"  id="tab-offsets"  class="px-3 py-2 text-slate-400 hover:text-slate-200 transition-colors">Critical Offsets</button>
      <button onclick="showTab('json')"     id="tab-json"     class="px-3 py-2 text-slate-400 hover:text-slate-200 transition-colors">Raw JSON</button>
      <div class="ml-auto flex items-center gap-2 py-1">
        <span class="text-slate-600">v<?= $activeVersion ?></span>
        <span class="text-slate-700">·</span>
        <span class="text-slate-500">fixed_size = <span class="text-violet-400"><?= $cfg['fixed_size'] ?? '?' ?></span></span>
      </div>
    </div>

    <!-- ── TAB: FIELDS ─────────────────────────────────────────────────── -->
    <div id="tab-fields-panel" class="flex-1 overflow-auto">
      <table class="w-full border-collapse text-[11px] mono" id="fields-table">
        <thead class="sticky top-0 bg-[#0d1117] z-10">
          <tr class="border-b border-slate-800">
            <th class="text-left px-3 py-1.5 font-normal text-slate-500 w-16">Offset</th>
            <th class="text-left px-3 py-1.5 font-normal text-slate-500 w-20">Type</th>
            <th class="text-left px-3 py-1.5 font-normal text-slate-500 w-40">Key</th>
            <th class="text-left px-3 py-1.5 font-normal text-slate-500 w-28">Group</th>
            <th class="text-left px-3 py-1.5 font-normal text-slate-500">Description</th>
            <th class="text-left px-3 py-1.5 font-normal text-slate-500 w-16">Actions</th>
          </tr>
        </thead>
        <tbody id="fields-tbody">
          <?php
          $fields = $cfg['fields'] ?? [];
          $groups = array_keys($groupLabels);
          foreach ($fields as $i => $f):
            $ftype = h($f['type'] ?? 'uint32');
          ?>
          <tr class="border-b border-slate-800/50 hover:bg-slate-800/20 transition-colors field-row" data-idx="<?= $i ?>">
            <td class="px-3 py-1.5">
              <input type="number" value="<?= (int)$f['offset'] ?>" min="0" max="9999"
                class="w-16 px-1.5 py-0.5 text-amber-300 text-[11px]"
                onchange="fieldChanged(<?= $i ?>, 'offset', parseInt(this.value))"/>
            </td>
            <td class="px-3 py-1.5">
              <select onchange="fieldChanged(<?= $i ?>, 'type', this.value)"
                class="px-1 py-0.5 text-[11px]">
                <?php foreach ($fieldTypes as $ft): ?>
                <option value="<?= $ft ?>" <?= ($f['type'] ?? '') === $ft ? 'selected' : '' ?>><?= $ft ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="px-3 py-1.5">
              <input type="text" value="<?= h($f['key'] ?? '') ?>"
                class="w-36 px-1.5 py-0.5 text-sky-300 text-[11px]"
                onchange="fieldChanged(<?= $i ?>, 'key', this.value)"/>
            </td>
            <td class="px-3 py-1.5">
              <select onchange="fieldChanged(<?= $i ?>, 'group', this.value)"
                class="px-1 py-0.5 text-[11px]">
                <?php foreach ($groups as $g): ?>
                <option value="<?= $g ?>" <?= ($f['group'] ?? '') === $g ? 'selected' : '' ?>><?= $groupLabels[$g] ?? $g ?></option>
                <?php endforeach; ?>
                <option value="custom" <?= !isset($groupLabels[$f['group'] ?? '']) ? 'selected' : '' ?>>custom</option>
              </select>
            </td>
            <td class="px-3 py-1.5">
              <input type="text" value="<?= h($f['desc'] ?? '') ?>"
                class="w-full px-1.5 py-0.5 text-slate-300 text-[11px]"
                onchange="fieldChanged(<?= $i ?>, 'desc', this.value)"/>
            </td>
            <td class="px-3 py-1.5">
              <button onclick="deleteField(<?= $i ?>)" class="btn btn-red py-0.5 px-1.5">✕</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Add field bar -->
      <div class="sticky bottom-0 border-t border-slate-800 bg-[#0d1117] px-3 py-2 flex items-center gap-2">
        <input id="new-offset" type="number" placeholder="Offset" min="0" max="9999"
          class="w-20 px-2 py-1 text-[11px]"/>
        <select id="new-type" class="px-2 py-1 text-[11px]">
          <?php foreach ($fieldTypes as $ft): ?>
          <option value="<?= $ft ?>"><?= $ft ?></option>
          <?php endforeach; ?>
        </select>
        <input id="new-key"   type="text" placeholder="key_name"
          class="w-36 px-2 py-1 text-[11px]"/>
        <select id="new-group" class="px-2 py-1 text-[11px]">
          <?php foreach ($groups as $g): ?>
          <option value="<?= $g ?>"><?= $groupLabels[$g] ?? $g ?></option>
          <?php endforeach; ?>
        </select>
        <input id="new-desc"  type="text" placeholder="Description…"
          class="flex-1 px-2 py-1 text-[11px]"/>
        <button onclick="addField()" class="btn btn-green">+ Add Field</button>
        <button onclick="saveAll()" class="btn btn-violet ml-2">💾 Save</button>
      </div>
    </div>

    <!-- ── TAB: CRITICAL OFFSETS ──────────────────────────────────────── -->
    <div id="tab-offsets-panel" class="flex-1 overflow-auto hidden">
      <div class="p-4 max-w-3xl">
        <p class="text-slate-500 text-[11px] mb-4 leading-relaxed">
          These byte positions inside the <span class="text-violet-400"><?= $cfg['fixed_size'] ?? 2490 ?>-byte</span>
          ATaskTemplFixedData block drive the variable-section skip/read logic.
          When the game devs insert new fields before any of these positions the offset must be updated
          here to match the new struct layout.
        </p>

        <div class="mb-6">
          <div class="text-[10px] text-slate-600 uppercase tracking-widest mb-2">Fixed-data offsets</div>
          <table class="w-full border-collapse text-[11px] mono">
            <thead><tr class="border-b border-slate-800">
              <th class="text-left px-3 py-1.5 font-normal text-slate-500 w-52">Name</th>
              <th class="text-left px-3 py-1.5 font-normal text-slate-500 w-28">Offset</th>
              <th class="text-left px-3 py-1.5 font-normal text-slate-500">Notes</th>
            </tr></thead>
            <tbody>
            <?php
            $foDesc = [
                'has_sign'             => 'bool — task has a signature string',
                'timetable'            => 'uint32 — number of timetable slots',
                'change_key_size'      => 'uint32 — abase::vector _cur_size for ChangeKeyArr',
                'prem_items'           => 'uint32 — count of prerequisite ITEM_WANTED (31 B each)',
                'prem_monster_summoned'=> 'uint32 — count of MONSTER_SUMMONED (29 B each)',
                'given_items'          => 'uint32 — count of given ITEM_WANTED (31 B each)',
                'prem_title_count'     => 'uint32 — count of prerequisite title IDs (2 B each)',
                'teamwork'             => 'bool — teamwork flag (gates team_mems_wanted read)',
                'team_mems_wanted'     => 'uint32 — count of TEAM_MEM_WANTED (37 B each)',
                'prem_need_comp'       => 'bool — prerequisite comparison expression present',
                'prem_exp1_left_size'  => 'uint32 — PremCompExp1.strExpLeft._cur_size (char count)',
                'prem_exp1_right_size' => 'uint32 — PremCompExp1.strExpRight._cur_size',
                'prem_exp2_left_size'  => 'uint32 — PremCompExp2.strExpLeft._cur_size',
                'prem_exp2_right_size' => 'uint32 — PremCompExp2.strExpRight._cur_size',
                'monster_wanted_cnt'   => 'uint32 — count of MONSTER_WANTED (22 B each) ← READ',
                'items_wanted_cnt'     => 'uint32 — count of ITEM_WANTED (31 B each) ← READ',
                'interobj_wanted_cnt'  => 'uint32 — count of INTEROBJ_WANTED (8 B each) ← READ',
                'fin_need_comp'        => 'bool — finish comparison expression present',
                'fin_exp1_left_size'   => 'uint32 — FinCompExp1.strExpLeft._cur_size',
                'fin_exp1_right_size'  => 'uint32 — FinCompExp1.strExpRight._cur_size',
                'fin_exp2_left_size'   => 'uint32 — FinCompExp2.strExpLeft._cur_size',
                'fin_exp2_right_size'  => 'uint32 — FinCompExp2.strExpRight._cur_size',
            ];
            $fo = $cfg['fixed_offsets'] ?? [];
            foreach ($foDesc as $k => $notes):
            ?>
            <tr class="border-b border-slate-800/50 hover:bg-slate-800/20">
              <td class="px-3 py-1.5 text-sky-300"><?= h($k) ?></td>
              <td class="px-3 py-1.5">
                <input type="number" value="<?= (int)($fo[$k] ?? 0) ?>" min="0" max="9999"
                  class="w-20 px-1.5 py-0.5 text-amber-300 text-[11px]"
                  onchange="offsetChanged('fixed_offsets','<?= h($k) ?>',parseInt(this.value))"/>
              </td>
              <td class="px-3 py-1.5 text-slate-500"><?= h($notes) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="mb-4">
          <div class="text-[10px] text-slate-600 uppercase tracking-widest mb-2">Award-data offsets
            <span class="text-slate-700 ml-2 normal-case">(inside the <?= $cfg['award_data_fixed_size'] ?? 957 ?>-byte AWARD_DATA block)</span>
          </div>
          <table class="w-full border-collapse text-[11px] mono">
            <thead><tr class="border-b border-slate-800">
              <th class="text-left px-3 py-1.5 font-normal text-slate-500 w-52">Name</th>
              <th class="text-left px-3 py-1.5 font-normal text-slate-500 w-28">Offset</th>
              <th class="text-left px-3 py-1.5 font-normal text-slate-500">Notes</th>
            </tr></thead>
            <tbody>
            <?php
            $aoDesc = [
                'cand_items'           => 'uint32 — count of CandItem LoadAwardCandBin blocks',
                'award_specify_role'   => 'bool — AwardSpecifyRole flag (gates recursive AWARD_DATA)',
                'role_selected'        => 'uint32 — which role was selected',
                'para_exp_size'        => 'uint32 — size of ParaExp byte block',
                'change_key_size'      => 'uint32 — abase::vector _cur_size for ChangeKeyArr',
                'faction_extra_cand'   => 'uint32 — count of FactionExtraCandItems blocks',
                'extra_cand_items'     => 'uint32 — count of ExtraCandItems blocks',
                'check_global_compare' => 'bool — GlobalCompareExpression present',
                'global_exp_left_size' => 'uint32 — GlobalCompareExpression.strExpLeft._cur_size',
                'global_exp_right_size'=> 'uint32 — GlobalCompareExpression.strExpRight._cur_size',
            ];
            $ao = $cfg['award_data_offsets'] ?? [];
            foreach ($aoDesc as $k => $notes):
            ?>
            <tr class="border-b border-slate-800/50 hover:bg-slate-800/20">
              <td class="px-3 py-1.5 text-sky-300"><?= h($k) ?></td>
              <td class="px-3 py-1.5">
                <input type="number" value="<?= (int)($ao[$k] ?? 0) ?>" min="0" max="9999"
                  class="w-20 px-1.5 py-0.5 text-amber-300 text-[11px]"
                  onchange="offsetChanged('award_data_offsets','<?= h($k) ?>',parseInt(this.value))"/>
              </td>
              <td class="px-3 py-1.5 text-slate-500"><?= h($notes) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Struct sizes -->
        <div class="flex gap-4 mt-4">
          <div>
            <label class="block text-[10px] text-slate-600 mb-1">fixed_size (ATaskTemplFixedData)</label>
            <input type="number" value="<?= (int)($cfg['fixed_size'] ?? 2490) ?>" min="1"
              class="w-28 px-2 py-1 text-[11px] text-violet-400"
              onchange="topChanged('fixed_size', parseInt(this.value))"/>
          </div>
          <div>
            <label class="block text-[10px] text-slate-600 mb-1">award_data_fixed_size (AWARD_DATA)</label>
            <input type="number" value="<?= (int)($cfg['award_data_fixed_size'] ?? 957) ?>" min="1"
              class="w-28 px-2 py-1 text-[11px] text-violet-400"
              onchange="topChanged('award_data_fixed_size', parseInt(this.value))"/>
          </div>
        </div>

        <div class="mt-4">
          <button onclick="saveAll()" class="btn btn-violet">💾 Save All Changes</button>
        </div>
      </div>
    </div>

    <!-- ── TAB: RAW JSON ──────────────────────────────────────────────── -->
    <div id="tab-json-panel" class="flex-1 overflow-auto hidden flex flex-col">
      <div class="p-3 text-[11px] text-slate-500 mono border-b border-slate-800 shrink-0">
        Edit the raw JSON directly. Changes here override the visual editor on save.
      </div>
      <form method="post" class="flex flex-col flex-1">
        <input type="hidden" name="action"  value="save_json"/>
        <input type="hidden" name="version" value="<?= $activeVersion ?>"/>
        <textarea name="config_json" id="json-textarea"
          class="flex-1 p-3 text-[11px] mono text-slate-300 resize-none"
          style="min-height:400px"><?= h($cfgJson) ?></textarea>
        <div class="p-3 border-t border-slate-800 shrink-0">
          <button type="submit" class="btn btn-violet">💾 Save JSON</button>
        </div>
      </form>
    </div>

  </div>

  <!-- ── RIGHT : info panel ─────────────────────────────────────────────── -->
  <aside class="w-72 shrink-0 border-l border-slate-800 bg-[#0d1117] overflow-y-auto p-4 text-[11px] mono">
    <div class="text-[9px] text-slate-600 uppercase tracking-widest mb-3">How it works</div>

    <div class="space-y-3 text-slate-500 leading-relaxed">
      <div>
        <span class="text-slate-300">Fields tab</span> — each row is one display field in the task record viewer.
        Change offset/type/key to add newly discovered fields. Use <em>key</em> as the identifier referenced in
        JavaScript (must be unique and snake_case).
      </div>
      <div>
        <span class="text-slate-300">Critical Offsets tab</span> — these drive the binary parser.
        When the game ships a new version and inserts fields into the struct, every offset below
        the insertion point shifts. Update the affected values here and the parser will immediately
        use the new positions.
      </div>
      <div>
        <span class="text-slate-300">Clone</span> — copies an existing version config into a new one.
        Start here when a new binary version ships: clone the previous version, update
        <code class="text-violet-400">fixed_size</code> to the new struct size, then hunt for the
        changed offsets using the struct diff.
      </div>
      <div>
        <span class="text-slate-300">Raw JSON tab</span> — for bulk edits or pasting configs
        from another source. The file is written to
        <code class="text-slate-400">structures/tasks/{version}/parser_config.json</code>.
      </div>
    </div>

    <div class="mt-6 pt-4 border-t border-slate-800">
      <div class="text-[9px] text-slate-600 uppercase tracking-widest mb-2">Field types</div>
      <div class="space-y-1">
        <?php foreach ([
          'uint32'  => ['badge-uint32',  '4-byte unsigned int'],
          'int32'   => ['badge-int32',   '4-byte signed int'],
          'float'   => ['badge-float',   '4-byte IEEE float'],
          'bool'    => ['badge-bool',    '1-byte bool (0/1)'],
          'wstr30'  => ['badge-wstr30',  'UTF-16LE fixed 30-char string (60 bytes)'],
          'wstr30b' => ['badge-wstr30b', 'Second wide-string field variant'],
        ] as $t => [$cls, $tdesc]): ?>
        <div class="flex items-center gap-2">
          <span class="badge <?= $cls ?>"><?= $t ?></span>
          <span class="text-slate-600"><?= $tdesc ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="mt-4 pt-4 border-t border-slate-800">
      <div class="text-[9px] text-slate-600 uppercase tracking-widest mb-2">Stat</div>
      <div class="text-slate-500">
        <span class="text-slate-300"><?= count($cfg['fields'] ?? []) ?></span> display fields<br/>
        <span class="text-slate-300"><?= count($cfg['fixed_offsets'] ?? []) ?></span> fixed offsets<br/>
        <span class="text-slate-300"><?= count($cfg['award_data_offsets'] ?? []) ?></span> award offsets
      </div>
    </div>
  </aside>

</div>

<script>
// ── In-memory config mirror ──────────────────────────────────────────────────
var config = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE) ?>;
var dirty  = false;

function fieldChanged(idx, prop, value) {
  config.fields[idx][prop] = value;
  dirty = true;
}

function offsetChanged(section, key, value) {
  if (!config[section]) config[section] = {};
  config[section][key] = value;
  dirty = true;
}

function topChanged(key, value) {
  config[key] = value;
  dirty = true;
}

function deleteField(idx) {
  if (!confirm('Delete field "' + config.fields[idx].key + '"?')) return;
  config.fields.splice(idx, 1);
  dirty = true;
  rebuildTable();
}

function addField() {
  var off   = parseInt(document.getElementById('new-offset').value) || 0;
  var type  = document.getElementById('new-type').value;
  var key   = document.getElementById('new-key').value.trim();
  var group = document.getElementById('new-group').value;
  var desc  = document.getElementById('new-desc').value.trim();

  if (!key) { alert('Key is required.'); return; }
  if (config.fields.some(function(f){ return f.key === key; })) {
    alert('Key "' + key + '" already exists.'); return;
  }

  config.fields.push({ offset: off, type: type, key: key, group: group, desc: desc });
  // Sort by offset so table stays tidy
  config.fields.sort(function(a,b){ return a.offset - b.offset; });
  dirty = true;
  rebuildTable();

  // Clear inputs
  document.getElementById('new-offset').value = '';
  document.getElementById('new-key').value    = '';
  document.getElementById('new-desc').value   = '';
}

function saveAll() {
  var form = document.createElement('form');
  form.method = 'POST';
  form.style.display = 'none';
  function addInput(n, v) {
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = n; i.value = v;
    form.appendChild(i);
  }
  addInput('action',      'save_json');
  addInput('version',     <?= $activeVersion ?>);
  addInput('config_json', JSON.stringify(config, null, 2));
  document.body.appendChild(form);
  form.submit();
}

// Rebuild the fields table from the in-memory config
function rebuildTable() {
  var types  = <?= json_encode($fieldTypes) ?>;
  var groups = <?= json_encode(array_merge(array_keys($groupLabels), ['custom'])) ?>;
  var glbls  = <?= json_encode($groupLabels) ?>;

  var tbody = document.getElementById('fields-tbody');
  tbody.innerHTML = '';
  config.fields.forEach(function(f, i) {
    var tr = document.createElement('tr');
    tr.className = 'border-b border-slate-800/50 hover:bg-slate-800/20 transition-colors field-row';
    tr.dataset.idx = i;

    var typeOpts  = types.map(function(t)  { return '<option value="'+t+'"'+(f.type===t?' selected':'')+'>'+t+'</option>'; }).join('');
    var groupOpts = groups.map(function(g) { return '<option value="'+g+'"'+(f.group===g?' selected':'')+'>'+(glbls[g]||g)+'</option>'; }).join('');

    tr.innerHTML =
      '<td class="px-3 py-1.5">' +
        '<input type="number" value="'+f.offset+'" min="0" max="9999" class="w-16 px-1.5 py-0.5 text-amber-300 text-[11px]" onchange="fieldChanged('+i+',\'offset\',parseInt(this.value))"/>' +
      '</td>' +
      '<td class="px-3 py-1.5"><select class="px-1 py-0.5 text-[11px]" onchange="fieldChanged('+i+',\'type\',this.value)">'+typeOpts+'</select></td>' +
      '<td class="px-3 py-1.5"><input type="text" value="'+esc(f.key)+'" class="w-36 px-1.5 py-0.5 text-sky-300 text-[11px]" onchange="fieldChanged('+i+',\'key\',this.value)"/></td>' +
      '<td class="px-3 py-1.5"><select class="px-1 py-0.5 text-[11px]" onchange="fieldChanged('+i+',\'group\',this.value)">'+groupOpts+'</select></td>' +
      '<td class="px-3 py-1.5"><input type="text" value="'+esc(f.desc)+'" class="w-full px-1.5 py-0.5 text-slate-300 text-[11px]" onchange="fieldChanged('+i+',\'desc\',this.value)"/></td>' +
      '<td class="px-3 py-1.5"><button onclick="deleteField('+i+')" class="btn btn-red py-0.5 px-1.5">✕</button></td>';
    tbody.appendChild(tr);
  });
}

// ── Tabs ─────────────────────────────────────────────────────────────────────
function showTab(name) {
  ['fields','offsets','json'].forEach(function(t) {
    document.getElementById('tab-'+t+'-panel').classList.toggle('hidden', t !== name);
    document.getElementById('tab-'+t).classList.toggle('tab-active', t === name);
  });
  if (name === 'json') {
    // Sync in-memory config into the textarea
    document.getElementById('json-textarea').value = JSON.stringify(config, null, 2);
  }
}

// Keep JSON textarea in sync when user switches back
document.getElementById('json-textarea').addEventListener('input', function() {
  try {
    var parsed = JSON.parse(this.value);
    config = parsed;
    dirty  = true;
  } catch(e) { /* invalid JSON while typing – ignore */ }
});

// Warn on navigation with unsaved changes
window.addEventListener('beforeunload', function(e) {
  if (dirty) { e.preventDefault(); e.returnValue = ''; }
});

function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
