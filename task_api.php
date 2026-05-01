<?php
/**
 * task_api.php — JSON API for cross-pack task lookups
 *
 * GET ?action=search&q=QUERY[&limit=100]
 *   Search all packs by task name (substring) or exact/partial ID.
 *   Returns a JSON array of lightweight task objects (no variable data).
 *
 * GET ?action=find&id=N
 *   Find a single task by ID across all packs.
 *   Returns the full task object (with variable data) or null.
 */

declare(strict_types=1);
require_once __DIR__ . '/classes/TaskDataReader.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dataDir = __DIR__ . '/data';
$flags   = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;

function send($data): void { global $flags; echo json_encode($data, $flags); exit; }

// Load index
try {
    $indexInfo     = TaskDataReader::parseIndex($dataDir . '/tasks.data');
    $packCount     = $indexInfo['pack_count']  ?? 45;
    $binaryVersion = (int)($indexInfo['version'] ?? 0);
} catch (Exception $e) { send(['error' => $e->getMessage()]); }

$action = $_GET['action'] ?? '';

// ── Find single task by ID ────────────────────────────────────────────────────
if ($action === 'find') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) send(null);

    for ($p = 1; $p <= $packCount; $p++) {
        $f = $dataDir . '/tasks.data' . $p;
        if (!file_exists($f)) continue;
        try {
            $pack = TaskDataReader::parsePack($f, $p, true, $binaryVersion);
            foreach ($pack['tasks'] as $t) {
                if ((int)$t['id'] === $id) send($t);
            }
        } catch (Exception $e) { continue; }
    }
    send(null);
}

// ── Search all packs ──────────────────────────────────────────────────────────
if ($action === 'search') {
    $q     = trim($_GET['q'] ?? '');
    $limit = min((int)($_GET['limit'] ?? 100), 300);

    if ($q === '') send([]);

    $isId  = ctype_digit($q);
    $qLow  = strtolower($q);
    $results = [];

    for ($p = 1; $p <= $packCount; $p++) {
        $f = $dataDir . '/tasks.data' . $p;
        if (!file_exists($f)) continue;
        try {
            // For ID searches use varData=true so sub-tasks are included;
            // for name searches use varData=false (faster — sub-tasks share parent names anyway).
            $pack = TaskDataReader::parsePack($f, $p, $isId, $binaryVersion);
            foreach ($pack['tasks'] as $t) {
                $match = false;
                if ($isId) {
                    $match = ((string)$t['id'] === $q || strpos((string)$t['id'], $q) !== false);
                } else {
                    $match = strpos(strtolower((string)$t['name']), $qLow) !== false;
                }
                if ($match) {
                    $results[] = $t;
                    if (count($results) >= $limit) break 2;
                }
            }
        } catch (Exception $e) { continue; }
    }
    send($results);
}

http_response_code(400);
send(['error' => 'Unknown action. Use action=find&id=N or action=search&q=QUERY']);
