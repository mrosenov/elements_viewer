<?php
/**
 * TaskDataReader.php
 *
 * Parses the binary tasks.data / tasks.data1 … tasks.data45 files used by the
 * Jade Dynasty (ZX) client to store the task (quest) catalogue.
 *
 * ── Index file (tasks.data) ────────────────────────────────────────────────
 *
 *   [4]  DWORD  magic          0x69340304
 *   [4]  DWORD  version        current = 165
 *   [4]  DWORD  export_version
 *   [4]  DWORD  item_count     total tasks across all packs
 *   [4]  DWORD  pack_count     number of pack files (typically 45)
 *   [pack_count × 16]          MD5 checksums (task_md5 structs)
 *
 * ── Pack file (tasks.dataN) ────────────────────────────────────────────────
 *
 *   [4]  DWORD  magic          0x06934554
 *   [4]  DWORD  item_count     tasks in this pack
 *   [item_count × 4]           absolute file-offset table (uint32 each)
 *   [variable]                 task records (ATaskTempl binary data)
 *
 * ── Task record layout ─────────────────────────────────────────────────────
 *
 *   [sizeof(ATaskTemplFixedData) = 2490 bytes]  fixed fields (fread'd directly)
 *   [variable]  signature, timetable, change-key arrays, prem/given items,
 *               monster summoned, prem titles, team members, expressions,
 *               completion targets, award data, …
 *   [6 × (size_t + task_char[])]  description, okText, noText, tribute,
 *                                  hintText, canDeliverText (UTF-16LE)
 *   [5 × talk_proc]   dialogue sequences
 *   [4]  int  sub_count        recursive sub-tasks follow
 *
 * Only the fixed 2490-byte block is parsed here (sufficient for all list/detail
 * field display).  Text-string extraction can be layered on via parseTexts().
 *
 * Source reference:
 *   ZElement/ZElementClient/Task/TaskTempl.h   (struct ATaskTemplFixedData)
 *   ZElement/ZElementClient/Task/TaskTempl.cpp  (LoadFixedDataFromBinFile,
 *                                                LoadBinary)
 */

class TaskDataReader
{
    // ── Binary constants (version 165 built-in fallback) ─────────────────────
    const FIXED_SIZE     = 2490;   // sizeof(ATaskTemplFixedData) – verified
    const INDEX_MAGIC    = 0x69340304;
    const PACK_MAGIC     = 0x06934554;

    // ── Active parse config (set at the start of each parsePack call) ─────────
    // Stores fixed_offsets, award_data_offsets, award_data_fixed_size, fields,
    // and fixed_size loaded from structures/tasks/{version}/parser_config.json.
    // Using a static property avoids threading the config through every private
    // method while keeping PHP's single-threaded request model safe.
    private static array $cfg = [];

    // ── Config loader ─────────────────────────────────────────────────────────

    /**
     * Load (and cache) the parser config for a given binary version.
     * Falls back to the built-in hardcoded constants when no JSON exists.
     */
    public static function loadConfig(int $version): array
    {
        static $cache = [];
        if (isset($cache[$version])) return $cache[$version];

        $path = __DIR__ . '/../structures/tasks/' . $version . '/parser_config.json';
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data) && !empty($data['fields'])) {
                return $cache[$version] = $data;
            }
        }
        return $cache[$version] = self::builtinConfig();
    }

    /**
     * Save a (possibly edited) config back to its JSON file.
     * Creates the directory if it does not exist.
     */
    public static function saveConfig(int $version, array $cfg): bool
    {
        $dir  = __DIR__ . '/../structures/tasks/' . $version;
        $path = $dir . '/parser_config.json';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents($path, $json) !== false;
    }

    /**
     * List all version directories that have a parser_config.json.
     * Returns array of ints sorted ascending.
     */
    public static function listConfigVersions(): array
    {
        $base = __DIR__ . '/../structures/tasks/';
        if (!is_dir($base)) return [];
        $versions = [];
        foreach (scandir($base) as $entry) {
            if (!is_numeric($entry)) continue;
            if (file_exists($base . $entry . '/parser_config.json')) {
                $versions[] = (int)$entry;
            }
        }
        sort($versions);
        return $versions;
    }

    /**
     * Build the config array from the hardcoded PHP constants.
     * This is the authoritative fallback for version 165.
     */
    private static function builtinConfig(): array
    {
        $fields = [];
        foreach (self::FIELDS as [$off, $type, $key, $group, $desc]) {
            $fields[] = ['offset' => $off, 'type' => $type, 'key' => $key,
                         'group' => $group, 'desc' => $desc];
        }
        return [
            'version'              => 165,
            'fixed_size'           => self::FIXED_SIZE,
            'task_char_bytes'      => 2,
            'fixed_offsets'        => [
                'has_sign'             => 64,   'timetable'            => 78,
                'change_key_size'      => 497,  'prem_items'           => 759,
                'prem_monster_summoned'=> 769,  'given_items'          => 782,
                'prem_title_count'     => 802,  'teamwork'             => 1318,
                'team_mems_wanted'     => 1347, 'prem_need_comp'       => 1645,
                'prem_exp1_left_size'  => 1662, 'prem_exp1_right_size' => 1698,
                'prem_exp2_left_size'  => 1730, 'prem_exp2_right_size' => 1766,
                'monster_wanted_cnt'   => 1945, 'items_wanted_cnt'     => 1954,
                'interobj_wanted_cnt'  => 1991, 'fin_need_comp'        => 2277,
                'fin_exp1_left_size'   => 2294, 'fin_exp1_right_size'  => 2330,
                'fin_exp2_left_size'   => 2362, 'fin_exp2_right_size'  => 2398,
            ],
            'award_data_fixed_size' => 957,
            'award_data_offsets'   => [
                'cand_items'           => 430,  'award_specify_role'   => 446,
                'role_selected'        => 447,  'para_exp_size'        => 470,
                'change_key_size'      => 498,  'faction_extra_cand'   => 652,
                'extra_cand_items'     => 660,  'check_global_compare' => 759,
                'global_exp_left_size' => 772,  'global_exp_right_size'=> 808,
            ],
            'fields' => $fields,
        ];
    }

    /** Short-hand: fixed-data offset by name. */
    private static function fo(string $key): int
    {
        return self::$cfg['fixed_offsets'][$key] ?? 0;
    }

    /** Short-hand: award-data offset by name. */
    private static function ao(string $key): int
    {
        return self::$cfg['award_data_offsets'][$key] ?? 0;
    }

    // ── Task type names ──────────────────────────────────────────────────────
    const TASK_TYPES = [
         0 => 'Normal',
         1 => 'Daily',
         2 => 'Weekly',
         3 => 'Faction',
         4 => 'Marriage',
         5 => 'Prentice',
         6 => 'Story',
         7 => 'Chain',
         8 => 'Special',
         9 => 'Sub-task',
        10 => 'Arena',
        11 => 'Boss',
        12 => 'Achievement',
        13 => 'Challenge',
        14 => 'Instance',
        15 => 'Quest-Refresh',
        16 => 'Family',
        17 => 'Nation',
        18 => 'Collect',
        19 => 'Escort',
        20 => 'Custom',
    ];

    // ── Completion method names ───────────────────────────────────────────────
    const METHODS = [
        0 => 'Talk to NPC',
        1 => 'Kill Monster',
        2 => 'Collect Item',
        3 => 'Reach Location',
        4 => 'Protect NPC',
        5 => 'NPC Move',
        6 => 'Leave Location',
        7 => 'InteractObject',
        8 => 'Build',
        9 => 'Finish Achievement',
        10 => 'Have Friends',
        11 => 'Reach Level',
        12 => 'Title',
        13 => 'Script',
    ];

    // ── Finish type names ─────────────────────────────────────────────────────
    const FINISH_TYPES = [
        0 => 'Direct',
        1 => 'NPC',
    ];

    // ── Award type names ──────────────────────────────────────────────────────
    const AWARD_TYPES = [
        0 => 'Normal',
        1 => 'Per-Kill',
        2 => 'Timed Scale',
        3 => 'Item-Count Scale',
        4 => 'Finish-Count Scale',
    ];

    // ── Field definitions (extensible) ───────────────────────────────────────
    //
    // Each entry: [ offset, type, label, group, description ]
    //
    // Supported types:
    //   uint32  – 4-byte unsigned int
    //   int32   – 4-byte signed int
    //   float   – 4-byte IEEE float
    //   bool    – 1-byte bool (0/1)
    //   wstr30  – task_char[30] UTF-16LE (60 bytes) → string
    //   wstr30b – task_char[30] second name field (m_szAutoMoveDestPosName)
    //
    // To add a new field, append a row here. The parser will pick it up
    // automatically in decodeItem().
    //
    const FIELDS = [
        // ── Identity ─────────────────────────────────────────────────────────
        [  0, 'uint32', 'id',                 'identity', 'Template ID'],
        [  4, 'wstr30', 'name',               'identity', 'Display name (UTF-16LE)'],
        [ 69, 'uint32', 'type',               'identity', 'Task type index'],
        [348, 'uint32', 'rank',               'identity', 'Difficulty rank'],
        [717, 'uint32', 'display_type',       'identity', 'UI display type'],
        [721, 'uint32', 'recommend_type',     'identity', 'Recommended-task category'],
        [725, 'uint32', 'tiny_game_id',       'identity', 'Mini-game ID'],
        [344, 'float',  'storage_weight',     'identity', 'Storage weight (task pool weight)'],

        // ── Timing ───────────────────────────────────────────────────────────
        [ 73, 'uint32', 'time_limit',         'timing',   'Time limit in seconds (0=none)'],
        [ 77, 'bool',   'abs_time',           'timing',   'Absolute time mode'],
        [ 78, 'uint32', 'timetable',          'timing',   'Timetable repeat count'],
        [102, 'int32',  'avail_frequency',    'timing',   'Available frequency'],
        [106, 'int32',  'time_interval',      'timing',   'Time interval'],
        [357, 'uint32', 'max_finish_count',   'timing',   'Max finish count'],
        [385, 'int32',  'dyn_finish_clear',   'timing',   'Dynamic clear time (global var id)'],
        [389, 'int32',  'finish_time_type',   'timing',   'Finish count clear type'],

        // ── Flags ────────────────────────────────────────────────────────────
        [ 64, 'bool',   'has_sign',           'flags',    'Has signature'],
        [110, 'bool',   'choose_one',         'flags',    'Run only one child'],
        [111, 'bool',   'rand_one',           'flags',    'Randomly pick one child'],
        [112, 'bool',   'exe_in_order',       'flags',    'Execute children in order'],
        [113, 'bool',   'parent_also_fail',   'flags',    'Parent fails when this fails'],
        [114, 'bool',   'parent_also_succ',   'flags',    'Parent succeeds when this succeeds'],
        [115, 'bool',   'can_give_up',        'flags',    'Player can abandon'],
        [116, 'bool',   'can_redo',           'flags',    'Can be repeated'],
        [117, 'bool',   'can_redo_after_fail','flags',    'Can redo after failure'],
        [118, 'bool',   'clear_as_give_up',   'flags',    'Abandon clears progress'],
        [119, 'bool',   'need_record',        'flags',    'Stored in history'],
        [120, 'bool',   'fail_as_die',        'flags',    'Fail when player dies'],
        [321, 'bool',   'auto_deliver',       'flags',    'Auto-delivered to player'],
        [323, 'bool',   'death_trig',         'flags',    'Triggered by death'],
        [324, 'bool',   'manual_trig',        'flags',    'Manual trigger'],
        [325, 'bool',   'must_shown',         'flags',    'Must be shown in task log'],
        [326, 'bool',   'clear_acquired',     'flags',    'Clear acquired items on give-up'],
        [331, 'bool',   'show_prompt',        'flags',    'Show prompt dialog'],
        [332, 'bool',   'key_task',           'flags',    'Key/important task'],
        [341, 'bool',   'skill_task',         'flags',    'Skill-based task'],
        [342, 'bool',   'can_seek_out',       'flags',    'Trackable on map'],
        [343, 'bool',   'show_direction',     'flags',    'Show direction arrow'],
        [352, 'bool',   'marriage',           'flags',    'Requires marriage status'],
        [353, 'bool',   'faction',            'flags',    'Faction task'],
        [354, 'bool',   'shared_family',      'flags',    'Shared by family members'],
        [355, 'bool',   'rec_finish_count',   'flags',    'Record personal finish count'],
        [356, 'bool',   'rec_finish_global',  'flags',    'Record global finish count'],
        [393, 'bool',   'life_again_reset',   'flags',    'Reset on reincarnation'],
        [394, 'bool',   'fail_after_logout',  'flags',    'Fail after logout'],
        [424, 'bool',   'prentice_task',      'flags',    'Apprentice task'],
        [425, 'bool',   'hidden',             'flags',    'Hidden from task log'],
        [426, 'bool',   'out_zone_fail',      'flags',    'Fail if player leaves zone'],
        [455, 'bool',   'enter_zone_fail',    'flags',    'Fail if player enters zone'],
        [484, 'bool',   'clear_illegal',      'flags',    'Clear illegal states on start'],
        [533, 'bool',   'kill_monster_fail',  'flags',    'Fail on kill specific monster'],
        [570, 'bool',   'have_item_fail',     'flags',    'Fail if player has specific item'],
        [640, 'bool',   'not_have_item_fail', 'flags',    'Fail if player lacks specific item'],
        [729, 'bool',   'clear_xp_cd',        'flags',    'Clear XP cooldown on start'],
        [741, 'bool',   'clear_xp_cd2',       'flags',    'Clear XP CD (alt flag)'],

        // ── Delivery / Award NPCs ─────────────────────────────────────────────
        [133, 'bool',   'delv_in_zone',       'npc',      'Must be in zone to deliver'],
        [134, 'uint32', 'delv_world',         'npc',      'Delivery world ID'],
        [162, 'bool',   'trans_to',           'npc',      'Teleport player on start'],
        [163, 'uint32', 'trans_world_id',     'npc',      'Teleport world ID'],
        [333, 'uint32', 'delv_npc',           'npc',      'Delivery NPC template ID'],
        [337, 'uint32', 'award_npc',          'npc',      'Award NPC template ID'],
        [121, 'uint32', 'max_receiver',       'npc',      'Max simultaneous receivers'],

        // ── Prerequisites ─────────────────────────────────────────────────────
        [742, 'uint32', 'prem_lev_min',       'prereq',   'Min level requirement'],
        [746, 'uint32', 'prem_lev_max',       'prereq',   'Max level requirement (0=any)'],
        [751, 'int32',  'talisman_min',       'prereq',   'Min talisman value'],
        [755, 'int32',  'talisman_max',       'prereq',   'Max talisman value'],
        [759, 'uint32', 'prem_items_cnt',     'prereq',   'Number of required items'],
        [769, 'uint32', 'prem_monster_cnt',   'prereq',   'Number of summoned monsters required'],
        [782, 'uint32', 'given_items_cnt',    'prereq',   'Number of given items'],
        [806, 'uint32', 'prem_deposit',       'prereq',   'Spirit deposit requirement'],
        [811, 'int32',  'prem_reputation',    'prereq',   'Reputation requirement'],
        [817, 'int32',  'prem_contribution',  'prereq',   'Contribution requirement'],
        [974, 'uint32', 'prem_task_cnt',      'prereq',   'Number of prerequisite tasks'],
        [978, 'uint32', 'prem_task_0',        'prereq',   'Prerequisite task ID [0]'],
        [982, 'uint32', 'prem_task_1',        'prereq',   'Prerequisite task ID [1]'],
        [986, 'uint32', 'prem_task_2',        'prereq',   'Prerequisite task ID [2]'],
        [990, 'uint32', 'prem_task_3',        'prereq',   'Prerequisite task ID [3]'],
        [994, 'uint32', 'prem_task_4',        'prereq',   'Prerequisite task ID [4]'],
        [1041,'uint32', 'prem_period',        'prereq',   'Time period requirement'],
        [1046,'uint32', 'prem_faction',       'prereq',   'Faction requirement'],
        [1052,'uint32', 'gender',             'prereq',   'Gender requirement (0=any,1=M,2=F)'],
        [1057,'uint32', 'occupations_cnt',    'prereq',   'Occupation count restriction'],
        [1252,'uint32', 'mutex_task_cnt',     'prereq',   'Number of mutex (blocking) tasks'],
        [1256,'uint32', 'mutex_task_0',       'prereq',   'Mutex task ID [0]'],
        [1260,'uint32', 'mutex_task_1',       'prereq',   'Mutex task ID [1]'],
        [1264,'uint32', 'mutex_task_2',       'prereq',   'Mutex task ID [2]'],
        [1268,'uint32', 'mutex_task_3',       'prereq',   'Mutex task ID [3]'],
        [1272,'uint32', 'mutex_task_4',       'prereq',   'Mutex task ID [4]'],
        [1309,'int32',  'pk_value_min',       'prereq',   'PK value min'],
        [1313,'int32',  'pk_value_max',       'prereq',   'PK value max'],
        [1317,'bool',   'prem_gm',            'prereq',   'GM only task'],

        // ── Team ─────────────────────────────────────────────────────────────
        [1318,'bool',   'teamwork',           'team',     'Requires party'],
        [1319,'bool',   'rcv_by_team',        'team',     'Only party leader can accept'],
        [1320,'bool',   'shared_task',        'team',     'Shared across party members'],
        [1321,'bool',   'shared_achieved',    'team',     'Share kill/collect progress'],
        [1347,'uint32', 'team_mem_cnt',       'team',     'Team member conditions count'],
        [1355,'bool',   'show_by_team',       'team',     'Only visible in party'],
        [1356,'bool',   'share_work',         'team',     'Cooperative work task'],

        // ── Master/Prentice ───────────────────────────────────────────────────
        [1357,'bool',   'master',             'master',   'Master task'],
        [1358,'bool',   'prentice',           'master',   'Prentice task'],
        [1359,'int32',  'master_moral',       'master',   'Morality requirement'],
        [1363,'bool',   'mp_task',            'master',   'Master-prentice shared task'],
        [1364,'bool',   'out_master_task',    'master',   'Graduated prentice task'],
        [1365,'uint32', 'mp_task_cnt',        'master',   'MP task conditions count'],

        // ── Completion method ─────────────────────────────────────────────────
        [1937,'uint32', 'method',             'completion','Completion method'],
        [1941,'uint32', 'finish_type',        'completion','Finish type (Direct/NPC)'],
        [1945,'uint32', 'monster_cnt',        'completion','Kill monster count'],
        [1954,'uint32', 'items_cnt',          'completion','Collect item count'],
        [1962,'uint32', 'gold_wanted',        'completion','Gold required'],
        [1991,'uint32', 'interobj_cnt',       'completion','Interactive object count'],
        [2035,'uint32', 'npc_to_protect',     'completion','NPC to protect (template ID)'],
        [2039,'uint32', 'npc_moving',         'completion','NPC to escort (template ID)'],
        [2055,'uint32', 'title_wanted_cnt',   'completion','Title count required'],
        [2059,'uint32', 'finish_achievement', 'completion','Achievement to reach'],
        [2063,'uint32', 'friend_cnt',         'completion','Friends count required'],
        [2064,'uint32', 'finish_level',       'completion','Level to reach'],
        [2249,'int32',  'fixed_type',         'completion','Fixed time type'],
        [2184,'uint32', 'reach_site_id',      'completion','Reach-site world ID'],
        [2193,'uint32', 'leave_site_id',      'completion','Leave-site world ID'],
        [2188,'uint32', 'wait_time',          'completion','Wait time at location (s)'],

        // ── Awards ────────────────────────────────────────────────────────────
        [2434,'uint32', 'award_type_s',       'award',    'Success award type'],
        [2438,'uint32', 'award_type_f',       'award',    'Failure award type'],

        // ── Tree structure ────────────────────────────────────────────────────
        [2474,'uint32', 'parent',             'tree',     'Parent task ID'],
        [2478,'uint32', 'prev_sibling',       'tree',     'Previous sibling task ID'],
        [2482,'uint32', 'next_sibling',       'tree',     'Next sibling task ID'],
        [2486,'uint32', 'first_child',        'tree',     'First child task ID'],
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Parse the index file (tasks.data) and return summary info.
     *
     * @return array ['magic', 'version', 'export_version', 'item_count', 'pack_count', 'md5s']
     */
    public static function parseIndex(string $filePath): array
    {
        $fp = fopen($filePath, 'rb');
        if (!$fp) throw new RuntimeException("Cannot open: $filePath");
        try {
            $magic          = self::readU32($fp);
            $version        = self::readU32($fp);
            $export_version = self::readU32($fp);
            $item_count     = self::readU32($fp);
            $pack_count     = self::readU32($fp);
            // skip MD5 checksums (16 bytes × pack_count)
            return compact('magic','version','export_version','item_count','pack_count');
        } finally { fclose($fp); }
    }

    /**
     * Parse one pack file (tasks.dataN) and return all tasks.
     *
     * @return array ['magic', 'item_count', 'tasks' => [TASK, …]]
     *   Each TASK is an associative array built from FIELDS plus 'pack'
     *   and 'pack_index'.
     */
    /**
     * @param bool $varData  When false, skip variable-data parsing (faster, for search/listing).
     * @param int  $version  Binary version from the index file. 0 = use built-in fallback.
     */
    public static function parsePack(string $filePath, int $packNum = 0, bool $varData = true, int $version = 0): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: $filePath");
        }
        // Activate the correct config for this parse run.
        self::$cfg = $version > 0 ? self::loadConfig($version) : self::builtinConfig();
        $fixedSize  = self::$cfg['fixed_size'] ?? self::FIXED_SIZE;

        $fp = fopen($filePath, 'rb');
        if (!$fp) throw new RuntimeException("Cannot open: $filePath");
        try {
            $magic      = self::readU32($fp);
            $item_count = self::readU32($fp);

            // Read absolute file-offset table
            $offsets = [];
            for ($i = 0; $i < $item_count; $i++) {
                $offsets[] = self::readU32($fp);
            }

            $tasks = [];
            for ($i = 0; $i < $item_count; $i++) {
                fseek($fp, $offsets[$i]);
                $raw = fread($fp, $fixedSize);
                if ($raw === false || strlen($raw) < $fixedSize) continue;
                $task = self::decodeFixed($raw);
                if ($task['id'] === 0) continue;
                if ($varData) {
                    // fp is right after fixed block — read variable data
                    $task = array_merge($task, self::readVariableData($raw, $fp));
                }
                $task['pack']       = $packNum;
                $task['pack_index'] = $i;
                $tasks[] = $task;
                if ($varData) {
                    // Extract embedded sub-tasks (quest chains) — parent is added first
                    $subTasks = self::extractSubTasks($raw, $fp, $packNum);
                    foreach ($subTasks as $st) {
                        $tasks[] = $st;
                    }
                }
            }

            return compact('magic','item_count','tasks');
        } finally { fclose($fp); }
    }

    /**
     * Parse all packs found in $dataDir.
     * Returns a flat array of all task records.
     */
    public static function parseAll(string $dataDir): array
    {
        $index = self::parseIndex($dataDir . '/tasks.data');
        $all   = [];
        for ($p = 1; $p <= $index['pack_count']; $p++) {
            $path = $dataDir . '/tasks.data' . $p;
            if (!file_exists($path)) continue;
            $pack = self::parsePack($path, $p);
            foreach ($pack['tasks'] as $t) $all[] = $t;
        }
        return $all;
    }

    /**
     * Return a task type label for a numeric type value.
     */
    public static function typeName(int $type): string
    {
        return self::TASK_TYPES[$type] ?? "Type-$type";
    }

    /**
     * Return method label.
     */
    public static function methodName(int $m): string
    {
        return self::METHODS[$m] ?? "Method-$m";
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Decode fixed block using the active config's fields array.
     * Falls back to the built-in FIELDS const when no config is loaded.
     */
    private static function decodeFixed(string $raw): array
    {
        $out    = [];
        $fields = self::$cfg['fields'] ?? [];
        if (empty($fields)) {
            // fallback: use hardcoded FIELDS const
            foreach (self::FIELDS as [$off, $type, $key]) {
                $out[$key] = self::readField($raw, $off, $type);
            }
        } else {
            foreach ($fields as $f) {
                $out[$f['key']] = self::readField($raw, (int)$f['offset'], $f['type']);
            }
        }
        return $out;
    }

    /** Extract one field from a raw string at a given offset. */
    private static function readField(string $raw, int $off, string $type)
    {
        switch ($type) {
            case 'uint32': return unpack('V', substr($raw, $off, 4))[1];
            case 'int32':  return unpack('l', substr($raw, $off, 4))[1];
            case 'float':
                $v = unpack('f', substr($raw, $off, 4))[1];
                return round($v, 6);
            case 'bool':   return ord($raw[$off]) !== 0;
            case 'wstr30': return self::wstr($raw, $off, 30);
            case 'wstr30b':return self::wstr($raw, $off, 30);
            default:       return null;
        }
    }

    /** Decode UTF-16LE wide string (maxChars WORDs) from raw bytes. */
    private static function wstr(string $raw, int $off, int $maxChars): string
    {
        $bytes = substr($raw, $off, $maxChars * 2);
        $text  = @iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes);
        if ($text === false) return '';
        $nul = strpos($text, "\x00");
        if ($nul !== false) $text = substr($text, 0, $nul);
        return $text;
    }

    private static function readU32($fp): int
    {
        $d = fread($fp, 4);
        if (strlen($d) < 4) throw new RuntimeException('Unexpected EOF reading uint32.');
        return unpack('V', $d)[1];
    }

    // ── Variable data (after fixed block) ────────────────────────────────────
    //
    // After fread(this, sizeof(*this)) the C++ loader reads several variable-
    // length sections in this order (LoadFixedDataFromBinFile):
    //
    //  1. Signature       if m_bHasSign:         30 × task_char (60 bytes)
    //  2. Timetable       m_ulTimetable × 48     (two task_tm of 24 bytes)
    //  3. Change-key arrs m_lChangeKeyArr.size() × (4+4+1) bytes
    //  4. Prem items      m_ulPremItems           × ITEM_WANTED (31 bytes)
    //  5. Prem monsters   m_ulPremMonsterSummoned × MONSTER_SUMMONED (29 bytes)
    //  6. Prem titles     m_ulPremTitleCount      × short (2 bytes)
    //  7. Given items     m_ulGivenItems          × ITEM_WANTED (31 bytes)
    //  8. Team members    if m_bTeamwork: m_ulTeamMemsWanted × TEAM_MEM_WANTED (37 bytes)
    //  9. Prem expr strs  if m_bPremNeedComp: 4 variable char[] reads
    // 10. Monster wanted  m_ulMonsterWanted       × MONSTER_WANTED (22 bytes)  ← READ
    // 11. Items wanted    m_ulItemsWanted         × ITEM_WANTED (31 bytes)     ← READ
    // 12. Interobj wanted m_ulInterObjWanted      × INTEROBJ_WANTED (8 bytes)  ← READ
    // ─── (readVariableData() returns here; fp positioned after step 12) ────
    // 13. Fin expr strs   if m_bFinNeedComp: 4 variable char[] reads
    // 14-21. Award data   LoadAwardDataBin×2 + RatioScale×2 + ItemsScale×2 + CountScale×2
    // ─── LoadBinary() then calls ─────────────────────────────────────────────
    // 22. 6 text strings  (desc, okText, noText, tribute, hint, canDeliver)
    // 23. 5 talk_procs
    // 24. m_nSubCount (int32), then recursive sub-tasks
    //
    // Key fixed-data offsets (all verified against ATaskTemplFixedData struct):
    //   64   m_bHasSign
    //   78   m_ulTimetable
    //  497   m_lChangeKeyArr._cur_size  (abase::vector _cur_size = field 4 = off+12)
    //  759   m_ulPremItems
    //  769   m_ulPremMonsterSummoned
    //  782   m_ulGivenItems
    //  802   m_ulPremTitleCount
    // 1318   m_bTeamwork
    // 1347   m_ulTeamMemsWanted
    // 1645   m_bPremNeedComp
    // 1662   m_PremCompExp1.strExpLeft._cur_size   (1650 + 12)
    // 1698   m_PremCompExp1.strExpRight._cur_size  (1650 + 48)
    // 1730   m_PremCompExp2.strExpLeft._cur_size   (1718 + 12)
    // 1766   m_PremCompExp2.strExpRight._cur_size  (1718 + 48)
    // 1945   m_ulMonsterWanted
    // 1954   m_ulItemsWanted
    // 1991   m_ulInterObjWanted
    // 2277   m_bFinNeedComp
    // 2294   m_FinCompExp1.strExpLeft._cur_size    (2282 + 12)
    // 2330   m_FinCompExp1.strExpRight._cur_size   (2282 + 48)
    // 2362   m_FinCompExp2.strExpLeft._cur_size    (2350 + 12)
    // 2398   m_FinCompExp2.strExpRight._cur_size   (2350 + 48)

    private static function readVariableData(string $raw, $fp): array
    {
        $out = ['monsters_wanted' => [], 'items_wanted' => [], 'interobj_wanted' => []];
        try {
            // 1. Signature (task_char = uint16, 30 chars = 60 bytes)
            if (ord($raw[self::fo('has_sign')]) !== 0) {
                fread($fp, 60);
            }

            // 2. Timetable (two task_tm per slot; task_tm = 6 × long = 24 bytes)
            $timetable = self::ru32($raw, self::fo('timetable'));
            if ($timetable > 0 && $timetable < 1000) {
                fread($fp, $timetable * 48);
            }

            // 3. Change-key arrays: size × (long[4] + long[4] + bool[1]) = size × 9
            $ckSize = self::ru32($raw, self::fo('change_key_size'));
            if ($ckSize > 0 && $ckSize < 1000) {
                fread($fp, $ckSize * 4); // lChangeKeyArr
                fread($fp, $ckSize * 4); // lChangeKeyValueArr
                fread($fp, $ckSize * 1); // bChangeTypeArr
            }

            // 4. Prem items (ITEM_WANTED = 31 bytes)
            $n = self::ru32($raw, self::fo('prem_items'));
            if ($n > 0 && $n < 1000) fread($fp, $n * 31);

            // 5. Prem monster summoned (MONSTER_SUMMONED = 29 bytes)
            $n = self::ru32($raw, self::fo('prem_monster_summoned'));
            if ($n > 0 && $n < 1000) fread($fp, $n * 29);

            // 6. Prem titles (short = 2 bytes)
            $n = self::ru32($raw, self::fo('prem_title_count'));
            if ($n > 0 && $n < 1000) fread($fp, $n * 2);

            // 7. Given items (ITEM_WANTED = 31 bytes)
            $n = self::ru32($raw, self::fo('given_items'));
            if ($n > 0 && $n < 1000) fread($fp, $n * 31);

            // 8. Team members (TEAM_MEM_WANTED = 37 bytes), only when m_bTeamwork set
            if (ord($raw[self::fo('teamwork')]) !== 0) {
                $n = self::ru32($raw, self::fo('team_mems_wanted'));
                if ($n > 0 && $n < 1000) fread($fp, $n * 37);
            }

            // 9. Prem comparison expressions
            if (ord($raw[self::fo('prem_need_comp')]) !== 0) {
                $n = self::ru32($raw, self::fo('prem_exp1_left_size'));
                if ($n > 0 && $n < 65536) fread($fp, $n);
                $n = self::ru32($raw, self::fo('prem_exp1_right_size'));
                if ($n > 0 && $n < 65536) fread($fp, $n);
                $n = self::ru32($raw, self::fo('prem_exp2_left_size'));
                if ($n > 0 && $n < 65536) fread($fp, $n);
                $n = self::ru32($raw, self::fo('prem_exp2_right_size'));
                if ($n > 0 && $n < 65536) fread($fp, $n);
            }

            // 10. Monster wanted — MONSTER_WANTED (22 bytes)
            $monsterCount = self::ru32($raw, self::fo('monster_wanted_cnt'));
            if ($monsterCount < 50) {
                for ($i = 0; $i < $monsterCount; $i++) {
                    $d = fread($fp, 22);
                    if (!$d || strlen($d) < 22) break;
                    $out['monsters_wanted'][] = [
                        'monster_id'    => self::ru32($d, 0),
                        'monster_num'   => self::ru32($d, 4),
                        'drop_item_id'  => self::ru32($d, 8),
                        'drop_item_num' => self::ru32($d, 12),
                        'drop_cmn_item' => ord($d[16]) !== 0,
                        'drop_prob'     => round(unpack('f', substr($d, 17, 4))[1], 4),
                        'killer_lev'    => ord($d[21]) !== 0,
                    ];
                }
            }

            // 11. Items wanted — ITEM_WANTED (31 bytes)
            $itemsCount = self::ru32($raw, self::fo('items_wanted_cnt'));
            if ($itemsCount < 50) {
                for ($i = 0; $i < $itemsCount; $i++) {
                    $d = fread($fp, 31);
                    if (!$d || strlen($d) < 31) break;
                    $out['items_wanted'][] = [
                        'item_id'   => self::ru32($d, 0),
                        'common'    => ord($d[4]) !== 0,
                        'item_num'  => self::ru32($d, 5),
                        'prob'      => round(unpack('f', substr($d, 9, 4))[1], 4),
                        'bind'      => ord($d[13]) !== 0,
                    ];
                }
            }

            // 12. Interobj wanted — INTEROBJ_WANTED (8 bytes)
            $interobjCount = self::ru32($raw, self::fo('interobj_wanted_cnt'));
            if ($interobjCount < 50) {
                for ($i = 0; $i < $interobjCount; $i++) {
                    $d = fread($fp, 8);
                    if (!$d || strlen($d) < 8) break;
                    $out['interobj_wanted'][] = [
                        'obj_id'  => self::ru32($d, 0),
                        'obj_num' => self::ru32($d, 4),
                    ];
                }
            }
        } catch (Exception $e) {
            // If anything goes wrong parsing variable data, return what we have.
            // The next task iteration will fseek() to the correct absolute offset anyway.
        }
        return $out;
    }

    // ── Sub-task extraction ───────────────────────────────────────────────────
    //
    // After readVariableData() returns, the fp is positioned right after the
    // InterObjWanted array.  To reach sub-tasks we must skip:
    //
    //   13. FinNeedComp strings  (if m_bFinNeedComp at fixed-data offset 2277)
    //   14. LoadAwardDataBin × 2          (Award_S, Award_F)
    //   15. LoadAwardDataRatioScale × 2   (AwByRatio_S, AwByRatio_F)
    //   16. LoadAwardDataItemsScale × 2   (AwByItems_S, AwByItems_F)
    //   17. LoadAwardDataCountScale × 2   (AwByCount_S, AwByCount_F)
    //   18. 6 text strings (LoadDescriptionBin, Tribute, HintText, CanDeliver)
    //   19. 5 talk_procs  (DelvTask, Unqualified, DelvItem, Exe, Award)
    //   20. int32 m_nSubCount, then recursive sub-task LoadBinary calls

    /**
     * After readVariableData() has advanced $fp past MonsterWanted/ItemsWanted/
     * InterObjWanted, skip the rest of LoadFixedDataFromBinFile + LoadBinary
     * overhead and return any embedded sub-tasks as a flat array.
     */
    private static function extractSubTasks(string $raw, $fp, int $packNum): array
    {
        $subTasks = [];
        try {
            // 13. Fin-completion comparison expressions
            self::skipFinNeedComp($raw, $fp);

            // 14-17. Eight award-data blocks
            self::skipAwardDataBin($fp);          // Award_S
            self::skipAwardDataBin($fp);          // Award_F
            self::skipAwardDataRatioScale($fp);   // AwByRatio_S
            self::skipAwardDataRatioScale($fp);   // AwByRatio_F
            self::skipAwardDataItemsScale($fp);   // AwByItems_S
            self::skipAwardDataItemsScale($fp);   // AwByItems_F
            self::skipAwardDataCountScale($fp);   // AwByCount_S
            self::skipAwardDataCountScale($fp);   // AwByCount_F

            // 18. Six text strings (size_t + task_char[len] each)
            self::skipTextStrings($fp, 6);

            // 19. Five talk_procs
            for ($i = 0; $i < 5; $i++) {
                self::skipTalkProc($fp);
            }

            // 20. Sub-task count + recursive records
            $d = fread($fp, 4);
            if (!$d || strlen($d) < 4) return $subTasks;
            $subCount = unpack('V', $d)[1];
            if ($subCount > 0 && $subCount < 200) {
                for ($i = 0; $i < $subCount; $i++) {
                    $nested = self::parseBinaryRecord($fp, $packNum);
                    foreach ($nested as $st) {
                        $subTasks[] = $st;
                    }
                }
            }
        } catch (Exception $e) {
            // Non-fatal — sub-tasks simply won't appear for this parent.
        }
        return $subTasks;
    }

    /**
     * Parse a full task binary record from the current fp position.
     * Used for sub-tasks (not top-level tasks that have absolute offsets).
     * Returns the task plus any nested sub-tasks as a flat array.
     */
    private static function parseBinaryRecord($fp, int $packNum, int $depth = 0): array
    {
        if ($depth > 8) return []; // guard against runaway recursion

        $tasks     = [];
        $fixedSize = self::$cfg['fixed_size'] ?? self::FIXED_SIZE;
        $raw       = fread($fp, $fixedSize);
        if (!$raw || strlen($raw) < $fixedSize) return $tasks;

        $task = self::decodeFixed($raw);
        if ($task['id'] === 0) return $tasks;

        // Read variable data (monsters/items/interobj)
        $varData = self::readVariableData($raw, $fp);
        $task = array_merge($task, $varData);
        $task['pack']       = $packNum;
        $task['pack_index'] = -1; // sub-tasks have no top-level index
        $tasks[] = $task;

        // Recurse into nested sub-tasks
        $subTasks = self::extractSubTasks($raw, $fp, $packNum);
        foreach ($subTasks as $st) {
            $st['depth'] = ($task['depth'] ?? 0) + 1;
            $tasks[] = $st;
        }

        return $tasks;
    }

    // ── Skip helpers ──────────────────────────────────────────────────────────

    /**
     * Skip FinNeedComp expression strings from fp.
     * Uses the already-read 2490-byte fixed-data block ($raw) to check the flag
     * and read the sizes stored in the COMPARE_EXPRESSION vectors.
     */
    private static function skipFinNeedComp(string $raw, $fp): void
    {
        $finOff = self::fo('fin_need_comp');
        if (strlen($raw) < $finOff + 1) return;
        if (ord($raw[$finOff]) === 0) return; // m_bFinNeedComp = false

        $n = self::ru32($raw, self::fo('fin_exp1_left_size'));
        if ($n > 0 && $n < 65536) fread($fp, $n);
        $n = self::ru32($raw, self::fo('fin_exp1_right_size'));
        if ($n > 0 && $n < 65536) fread($fp, $n);
        $n = self::ru32($raw, self::fo('fin_exp2_left_size'));
        if ($n > 0 && $n < 65536) fread($fp, $n);
        $n = self::ru32($raw, self::fo('fin_exp2_right_size'));
        if ($n > 0 && $n < 65536) fread($fp, $n);
    }

    /**
     * Skip one LoadAwardCandBin block:
     *   bool(1) + count(4) + count × ITEM_WANTED(31)
     */
    private static function skipAwardCandBin($fp): void
    {
        fread($fp, 1); // m_bRandChoose
        $d = fread($fp, 4);
        if (!$d || strlen($d) < 4) return;
        $count = unpack('V', $d)[1];
        if ($count > 0 && $count <= 32) {
            fread($fp, $count * 31); // ITEM_WANTED = 31 bytes each
        }
    }

    /**
     * Skip one LoadAwardDataBin block.
     *
     * Binary layout written by LoadAwardDataBin():
     *   [957]  fread(&ad, sizeof(AWARD_DATA))   — fixed struct
     *   [var]  CandItems         (ulCandItems × LoadAwardCandBin)
     *   [var]  AwardSpecifyRole  (if bAwardSpecifyRole && ulRoleSelected: recursive)
     *   [var]  ParaExp           (if ulParaExpSize: ulParaExpSize bytes + 4 + arrLen×8)
     *   [var]  ChangeKeyArrs     (if _cur_size>0: size×(4+4+1))
     *   [var]  GlobalCompExpr    (if bCheckGlobalCompExpr: left+right char strings)
     *   [var]  FactionExtraCands (ulFactionExtraCandItems × LoadAwardCandBin)
     *   [var]  ExtraCands        (ulExtraCandItems × LoadAwardCandBin)
     *   [4+?]  ExtraTribute      (size_t len + task_char×len)
     *
     * Key AWARD_DATA offsets (sizeof=957, pack(1), 32-bit pointers as uint32):
     *   430  m_ulCandItems
     *   446  m_bAwardSpecifyRole
     *   447  m_ulRoleSelected
     *   470  m_ulParaExpSize
     *   486  m_lChangeKeyArr (abase::vector; _cur_size at +12 → offset 498)
     *   652  m_ulFactionExtraCandItems
     *   660  m_ulExtraCandItems
     *   759  m_bCheckGlobalCompareExpression
     *   760  m_GlobalCompareExpression (COMPARE_EXPRESSION 68 bytes)
     *          strExpLeft._cur_size at 760+12 = 772
     *          strExpRight._cur_size at 760+48 = 808
     */
    private static function skipAwardDataBin($fp): void
    {
        $adSize = self::$cfg['award_data_fixed_size'] ?? 957;

        $adRaw = fread($fp, $adSize);
        if (!$adRaw || strlen($adRaw) < $adSize) return;

        $ulCandItems             = self::ru32($adRaw, self::ao('cand_items'));
        $bAwardSpecifyRole       = ord($adRaw[self::ao('award_specify_role')]) !== 0;
        $ulRoleSelected          = self::ru32($adRaw, self::ao('role_selected'));
        $ulParaExpSize           = self::ru32($adRaw, self::ao('para_exp_size'));
        $lChangeKeyArrSize       = self::ru32($adRaw, self::ao('change_key_size'));
        $bCheckGlobalComp        = ord($adRaw[self::ao('check_global_compare')]) !== 0;
        $globalExpLeftSize       = self::ru32($adRaw, self::ao('global_exp_left_size'));
        $globalExpRightSize      = self::ru32($adRaw, self::ao('global_exp_right_size'));
        $ulFactionExtraCandItems = self::ru32($adRaw, self::ao('faction_extra_cand'));
        $ulExtraCandItems        = self::ru32($adRaw, self::ao('extra_cand_items'));

        // CandItems
        if ($ulCandItems > 0 && $ulCandItems <= 16) {
            for ($i = 0; $i < $ulCandItems; $i++) {
                self::skipAwardCandBin($fp);
            }
        }

        // AwardSpecifyRole (recursive)
        if ($bAwardSpecifyRole && $ulRoleSelected > 0) {
            self::skipAwardDataBin($fp);
        }

        // ParaExp: ulParaExpSize bytes + 4-byte arrLen + arrLen × TASK_EXPRESSION(8)
        if ($ulParaExpSize > 0 && $ulParaExpSize < 65536) {
            fread($fp, $ulParaExpSize);
            $d = fread($fp, 4);
            $arrLen = ($d && strlen($d) >= 4) ? unpack('V', $d)[1] : 0;
            if ($arrLen > 0 && $arrLen < 65536) {
                fread($fp, $arrLen * 8); // TASK_EXPRESSION = int(4)+float(4) = 8
            }
        }

        // ChangeKeyArrs: size × (long[4] + long[4] + bool[1])
        if ($lChangeKeyArrSize > 0 && $lChangeKeyArrSize < 100) {
            fread($fp, $lChangeKeyArrSize * 4); // lChangeKeyArr
            fread($fp, $lChangeKeyArrSize * 4); // lChangeKeyValueArr
            fread($fp, $lChangeKeyArrSize * 1); // bChangeTypeArr
        }

        // GlobalCompareExpression strings
        if ($bCheckGlobalComp) {
            if ($globalExpLeftSize > 0 && $globalExpLeftSize < 65536) {
                fread($fp, $globalExpLeftSize);
            }
            if ($globalExpRightSize > 0 && $globalExpRightSize < 65536) {
                fread($fp, $globalExpRightSize);
            }
        }

        // FactionExtraCandItems
        if ($ulFactionExtraCandItems > 0 && $ulFactionExtraCandItems <= 16) {
            for ($i = 0; $i < $ulFactionExtraCandItems; $i++) {
                self::skipAwardCandBin($fp);
            }
        }

        // ExtraCandItems
        if ($ulExtraCandItems > 0 && $ulExtraCandItems <= 16) {
            for ($i = 0; $i < $ulExtraCandItems; $i++) {
                self::skipAwardCandBin($fp);
            }
        }

        // ExtraTribute: always present — size_t(4) + task_char×len
        $d = fread($fp, 4);
        $len = ($d && strlen($d) >= 4) ? unpack('V', $d)[1] : 0;
        if ($len > 0 && $len < 65536) {
            fread($fp, $len * 2); // task_char = uint16 = 2 bytes
        }
    }

    /**
     * Skip one LoadAwardDataRatioScale block:
     *   uint32 m_ulScales + float[5] m_Ratios(20 bytes) + scales × LoadAwardDataBin
     */
    private static function skipAwardDataRatioScale($fp): void
    {
        $d = fread($fp, 4);
        $scales = ($d && strlen($d) >= 4) ? unpack('V', $d)[1] : 0;
        fread($fp, 20); // m_Ratios[5] = float×5
        if ($scales > 0 && $scales <= 5) {
            for ($i = 0; $i < $scales; $i++) {
                self::skipAwardDataBin($fp);
            }
        }
    }

    /**
     * Skip one LoadAwardDataItemsScale block:
     *   uint32 m_ulScales + uint32 m_ulItemId + uint32[5] m_Counts(20 bytes)
     *   + scales × LoadAwardDataBin
     */
    private static function skipAwardDataItemsScale($fp): void
    {
        $d = fread($fp, 4);
        $scales = ($d && strlen($d) >= 4) ? unpack('V', $d)[1] : 0;
        fread($fp, 4);  // m_ulItemId
        fread($fp, 20); // m_Counts[5] = uint×5
        if ($scales > 0 && $scales <= 5) {
            for ($i = 0; $i < $scales; $i++) {
                self::skipAwardDataBin($fp);
            }
        }
    }

    /**
     * Skip one LoadAwardDataCountScale block:
     *   uint32 m_ulScales + uint32[5] m_Counts(20 bytes) + scales × LoadAwardDataBin
     */
    private static function skipAwardDataCountScale($fp): void
    {
        $d = fread($fp, 4);
        $scales = ($d && strlen($d) >= 4) ? unpack('V', $d)[1] : 0;
        fread($fp, 20); // m_Counts[5] = uint×5
        if ($scales > 0 && $scales <= 5) {
            for ($i = 0; $i < $scales; $i++) {
                self::skipAwardDataBin($fp);
            }
        }
    }

    /**
     * Skip $count text strings, each written as:
     *   size_t(4 bytes) + task_char×len (2×len bytes)
     */
    private static function skipTextStrings($fp, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $d = fread($fp, 4);
            if (!$d || strlen($d) < 4) break;
            $len = unpack('V', $d)[1];
            if ($len > 0 && $len < 65536) {
                fread($fp, $len * 2); // task_char = uint16
            }
        }
    }

    /**
     * Skip one talk_proc binary record.
     *
     * talk_proc::load() writes:
     *   uint32  id_talk            (4)
     *   namechar[64] text          (128 = 64×2)
     *   int32   num_window         (4)
     *   windows[num_window] each:
     *     uint32 id                (4)
     *     uint32 id_parent         (4)
     *     int32  talk_text_len     (4)
     *     namechar[talk_text_len]  (talk_text_len×2)
     *     int32  num_option        (4)
     *     option[num_option] each  (num_option × 136):
     *       uint32 id              (4)
     *       namechar[64] text      (128)
     *       uint32 param           (4)
     */
    private static function skipTalkProc($fp): void
    {
        fread($fp, 4 + 128); // id_talk + text[64]
        $d = fread($fp, 4);
        if (!$d || strlen($d) < 4) return;
        $numWindows = unpack('V', $d)[1];
        if ($numWindows > 1000) return; // sanity guard
        for ($i = 0; $i < $numWindows; $i++) {
            fread($fp, 4 + 4); // id + id_parent
            $d = fread($fp, 4);
            $textLen = ($d && strlen($d) >= 4) ? unpack('V', $d)[1] : 0;
            if ($textLen > 0 && $textLen < 65536) {
                fread($fp, $textLen * 2); // namechar = uint16
            }
            $d = fread($fp, 4);
            $numOpts = ($d && strlen($d) >= 4) ? unpack('V', $d)[1] : 0;
            if ($numOpts > 0 && $numOpts < 1000) {
                fread($fp, $numOpts * 136); // sizeof(option) = 4+128+4 = 136
            }
        }
    }

    /** Read uint32 little-endian from a raw string at a byte offset. */
    private static function ru32(string $raw, int $off): int
    {
        if (strlen($raw) < $off + 4) return 0;
        return unpack('V', substr($raw, $off, 4))[1];
    }
}
