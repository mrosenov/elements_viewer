<?php
/**
 * ElementsReader.php
 *
 * Reader for Jade Dynasty elements.data files.
 *
 * Responsibilities:
 *   - Read the 4-byte version header and expose it.
 *   - Walk every array ("list") in the file, honouring structural markers
 *     (md5 / exporter_meta / tag_block) between specific list slots.
 *   - Detect and skip the TALK_PROC sentinel block at the end.
 *   - Decode a single list's records using a user-supplied schema
 *     (loaded from a JSON structure file).
 *
 * The file format for every regular list is:
 *     uint32  sizeof    (bytes per record)
 *     uint32  count     (number of records)
 *     byte[]  data      (sizeof * count bytes)
 *
 * Between certain list slots there is a structural marker:
 *     md5             → 8 bytes
 *     exporter_meta   → tag(4) + len(4) + data(len) + timestamp(4)
 *     tag_block       → tag(4) + len(4) + data(len)
 */

class ElementsReader
{
    /** Known version constants → short label */
    const KNOWN_VERSIONS = [
        0x1000009E => 'v158',
        0x100000A0 => 'v160',
        0x100000A5 => 'v165',
        0x100000B0 => 'v176',
    ];

    /** Structural markers that appear BEFORE the list slot with the given index */
    const STRUCTURAL_MARKERS = [
        13  => 'md5',
        23  => 'exporter_meta',
        36  => 'md5',
        56  => 'tag_block',
        62  => 'md5',
        103 => 'md5',
    ];

    /** Extra markers per version */
    const STRUCTURAL_MARKERS_EXTRA = [
        0x100000A5 => [296 => 'md5'], // v165
        0x100000B0 => [296 => 'md5'], // v176
    ];

    const MAX_SIZEOF = 1048576;
    const MAX_COUNT  = 1000000;

    /** @var string */
    private $filepath;
    /** @var int */
    private $fileSize;
    /** @var int */
    private $version = 0;
    /** @var string */
    private $versionLabel = '';
    /** @var array list of entries: [index, sizeof, count, dataOffset, skipped(bool)] */
    private $lists = [];
    /** @var int|null talk_proc count (null if not found) */
    private $talkProcCount = null;
    /**
     * MD5 checkpoints discovered during scan().
     * Each entry: ['offset' => int, 'before_list' => int, 'hex' => string]
     * @var array
     */
    private $md5Checkpoints = [];

    public function __construct($filepath)
    {
        if (!is_file($filepath)) {
            throw new RuntimeException("File not found: $filepath");
        }
        $this->filepath = $filepath;
        $this->fileSize = filesize($filepath);
    }

    public function getVersion()          { return $this->version; }
    public function getVersionLabel()     { return $this->versionLabel; }
    public function getLists()            { return $this->lists; }
    public function getTalkProcCount()    { return $this->talkProcCount; }
    public function getMd5Checkpoints()   { return $this->md5Checkpoints; }
    public function getFilepath()         { return $this->filepath; }
    public function getFileSize()         { return $this->fileSize; }

    /**
     * Walk the whole file and build the list index (offset + sizeof + count
     * for every list).  Does NOT read the list body bytes — those are
     * fetched on-demand by readList().
     */
    public function scan()
    {
        $fh = fopen($this->filepath, 'rb');
        if (!$fh) {
            throw new RuntimeException("Cannot open file: {$this->filepath}");
        }

        try {
            $this->version = $this->readU32($fh);
            $this->versionLabel = isset(self::KNOWN_VERSIONS[$this->version])
                ? self::KNOWN_VERSIONS[$this->version]
                : sprintf('0x%08X', $this->version);
            $this->readU32($fh); // timestamp

            // Merge per-version extra markers with base markers.
            $markers = self::STRUCTURAL_MARKERS;
            if (isset(self::STRUCTURAL_MARKERS_EXTRA[$this->version])) {
                foreach (self::STRUCTURAL_MARKERS_EXTRA[$this->version] as $slot => $m) {
                    $markers[$slot] = $m;
                }
            }

            $idx = 0;
            while (true) {
                if (isset($markers[$idx])) {
                    $markerType = $markers[$idx];
                    if ($markerType === 'md5') {
                        $markerOffset = ftell($fh);
                        $md5Raw = fread($fh, 8);
                        $this->md5Checkpoints[] = [
                            'offset'      => $markerOffset,
                            'before_list' => $idx,
                            'hex'         => strlen($md5Raw) === 8 ? bin2hex($md5Raw) : '',
                        ];
                    } else {
                        $this->skipMarker($fh, $markerType);
                    }
                }

                $pos = ftell($fh);
                if ($this->fileSize - $pos < 8) break;

                // Peek candidate header without consuming.
                $rawA = fread($fh, 4);
                $rawB = fread($fh, 4);
                fseek($fh, $pos);
                if (strlen($rawA) < 4 || strlen($rawB) < 4) break;

                $candSizeof = unpack('V', $rawA)[1];
                $candCount  = unpack('V', $rawB)[1];

                if ($candSizeof === 0 || $candSizeof > self::MAX_SIZEOF) break;
                if ($candCount > self::MAX_COUNT) break;

                // TALK_PROC lookahead: if the bytes right after the block would
                // give an implausible sizeof, the uint32 we read as sizeof is
                // actually the talk_proc count and the block has no body.
                $blockEnd = $pos + 8 + $candSizeof * $candCount;
                $nextHasMarker = isset($markers[$idx + 1]);
                if ($candCount > 0 && !$nextHasMarker && ($blockEnd + 4) <= $this->fileSize) {
                    fseek($fh, $blockEnd);
                    $la = fread($fh, 4);
                    fseek($fh, $pos);
                    if (strlen($la) === 4) {
                        $laSizeof = unpack('V', $la)[1];
                        if ($laSizeof === 0 || $laSizeof > self::MAX_SIZEOF) {
                            // TALK_PROC sentinel — consume as a single uint32 count
                            $this->talkProcCount = $this->readU32($fh);
                            break;
                        }
                    }
                }

                // Normal list: consume header + skip body.
                $sizeof = $this->readU32($fh);
                $count  = $this->readU32($fh);
                $dataOffset = ftell($fh);
                $bodyBytes = $sizeof * $count;
                if ($bodyBytes > 0) {
                    if (fseek($fh, $bodyBytes, SEEK_CUR) !== 0) break;
                }

                $this->lists[] = [
                    'index'       => $idx,
                    'sizeof'      => $sizeof,
                    'count'       => $count,
                    'data_offset' => $dataOffset,
                    'body_bytes'  => $bodyBytes,
                ];
                $idx++;
            }
        } finally {
            fclose($fh);
        }

        return $this;
    }

    /**
     * Decode up to $limit records of a given list using $schema.
     * $schema is an array of: ['name' => string, 'type' => string]
     * where type is one of: int32, int64, float, double,
     *                       wstring:N, byte:N (N is byte count).
     *
     * Returns array with keys:
     *   'fields'      : expanded field defs with offset/byte_width
     *   'schema_size' : total bytes defined by schema
     *   'rows'        : decoded records (array of assoc arrays)
     *   'actual_size' : list's sizeof from the file
     *   'count'       : total rows in list
     *   'warning'     : string (empty if schema matches exactly)
     */
    public function decodeList($listIndex, $schema, $limit = 200, $offset = 0)
    {
        if (!isset($this->lists[$listIndex])) {
            throw new RuntimeException("List index $listIndex out of range");
        }
        $meta = $this->lists[$listIndex];
        $sizeof = $meta['sizeof'];
        $count  = $meta['count'];

        // Expand schema with offsets & widths
        $fields = [];
        $schemaSize = 0;
        foreach ($schema as $f) {
            $w = self::typeWidth($f['type']);
            if ($w === null) {
                throw new RuntimeException("Unknown type: " . $f['type']);
            }
            $fields[] = [
                'name'       => $f['name'],
                'type'       => $f['type'],
                'offset'     => $schemaSize,
                'byte_width' => $w,
            ];
            $schemaSize += $w;
        }

        $warning = '';
        if ($schemaSize !== $sizeof) {
            $warning = "Schema size ($schemaSize) != list sizeof ($sizeof). "
                     . "Adjust fields until they match.";
        }

        $rows = [];
        if ($count > 0 && $sizeof > 0) {
            $fh = fopen($this->filepath, 'rb');
            if (!$fh) throw new RuntimeException("Cannot open file");
            try {
                $startRow = max(0, (int)$offset);
                $startRow = min($startRow, $count);
                $rowsToRead = min((int)$limit, $count - $startRow);
                if ($rowsToRead > 0) {
                    fseek($fh, $meta['data_offset'] + $startRow * $sizeof);
                    for ($r = 0; $r < $rowsToRead; $r++) {
                        $raw = fread($fh, $sizeof);
                        if (strlen($raw) < $sizeof) break;
                        $rows[] = $this->decodeRecord($raw, $fields, $sizeof);
                    }
                }
            } finally {
                fclose($fh);
            }
        }

        return [
            'fields'      => $fields,
            'schema_size' => $schemaSize,
            'actual_size' => $sizeof,
            'count'       => $count,
            'rows'        => $rows,
            'warning'     => $warning,
        ];
    }

    /**
     * Scan a list and return row indices whose FIRST field value (packed into
     * $keyBytes bytes of $keyType) equals $keyValue.
     *
     * Reads only the first few bytes of every record, so it's fast even on
     * huge lists.  Used to resolve field references — e.g. Item_ID 12345 in
     * a trading list is looked up against EQUIPMENT_ESSENCE.field[0] (ID).
     *
     * Supported key types: int32, int64, float, double.
     * Returns a list of matching row indices (capped by $limit).
     */
    public function findMatchingRows($listIndex, $keyType, $keyValue, $limit = 20)
    {
        if (!isset($this->lists[$listIndex])) return [];
        $meta = $this->lists[$listIndex];
        $sizeof = $meta['sizeof'];
        $count  = $meta['count'];
        if ($sizeof === 0 || $count === 0) return [];

        // Pack the needle in the same byte layout the record stores.
        switch ($keyType) {
            case 'int32':
                $needle = pack('V', ((int)$keyValue) & 0xFFFFFFFF);
                $kw = 4;
                break;
            case 'int64':
                // 64-bit PHP: 'P' is unsigned LE, but the bit pattern is the
                // same for signed values and PHP wraps negatives correctly.
                $needle = pack('P', (int)$keyValue);
                $kw = 8;
                break;
            case 'float':
                $needle = pack('g', (float)$keyValue);
                $kw = 4;
                break;
            case 'double':
                $needle = pack('e', (float)$keyValue);
                $kw = 8;
                break;
            default:
                return [];
        }
        if ($kw > $sizeof) return [];

        $matches = [];
        $fh = fopen($this->filepath, 'rb');
        if (!$fh) return [];
        try {
            fseek($fh, $meta['data_offset']);
            $skip = $sizeof - $kw;
            for ($r = 0; $r < $count; $r++) {
                $head = fread($fh, $kw);
                if (strlen($head) < $kw) break;
                if ($head === $needle) {
                    $matches[] = $r;
                    if (count($matches) >= $limit) break;
                }
                if ($skip > 0) fseek($fh, $skip, SEEK_CUR);
            }
        } finally {
            fclose($fh);
        }
        return $matches;
    }

    /**
     * Full-list text search.
     *
     * Decodes every record of $listIndex using $schema and returns those
     * where $query (case-insensitive substring) appears in any of the
     * searched fields.  If $fieldName is null, every field is searched;
     * otherwise only that field.
     *
     * Results:
     *   [
     *     'fields'       => expanded schema,
     *     'total_rows'   => count,
     *     'scanned_rows' => how many were read (== total_rows unless truncated),
     *     'rows'         => [ ['row' => int, 'data' => assoc] , ... ]  (≤ $limit)
     *     'truncated'    => bool (true if hit the limit and there may be more)
     *   ]
     */
    public function searchList($listIndex, $schema, $query, $fieldName = null, $limit = 500)
    {
        if (!isset($this->lists[$listIndex])) {
            throw new RuntimeException("List index $listIndex out of range");
        }
        $meta = $this->lists[$listIndex];
        $sizeof = $meta['sizeof'];
        $count  = $meta['count'];

        // Expand the schema once up-front.
        $fields = [];
        $off = 0;
        foreach ($schema as $f) {
            $w = self::typeWidth($f['type']);
            if ($w === null) {
                throw new RuntimeException("Unknown type: " . $f['type']);
            }
            $fields[] = [
                'name'       => $f['name'],
                'type'       => $f['type'],
                'offset'     => $off,
                'byte_width' => $w,
            ];
            $off += $w;
        }

        $needle = mb_strtolower((string)$query, 'UTF-8');
        $out = [
            'fields'       => $fields,
            'total_rows'   => $count,
            'scanned_rows' => 0,
            'rows'         => [],
            'truncated'    => false,
        ];
        if ($needle === '' || $count === 0 || $sizeof === 0) return $out;

        // Resolve single-field target (if any) so we can skip full-row decode
        // during the scan — decoding one field is typically 5-20× faster.
        $singleField = null;
        if ($fieldName !== null) {
            foreach ($fields as $f) {
                if ($f['name'] === $fieldName) { $singleField = $f; break; }
            }
            if ($singleField === null) return $out;  // unknown field ⇒ no matches
        }

        // Bulk-read in ~1 MiB chunks aligned to record size; massively cuts
        // per-record fread() overhead.
        $recsPerChunk = max(1, (int)(1048576 / max(1, $sizeof)));

        $fh = fopen($this->filepath, 'rb');
        if (!$fh) throw new RuntimeException("Cannot open file");
        try {
            fseek($fh, $meta['data_offset']);
            $rowIdx = 0;
            while ($rowIdx < $count) {
                $want  = min($recsPerChunk, $count - $rowIdx);
                $bytes = $want * $sizeof;
                $buf   = fread($fh, $bytes);
                if (strlen($buf) < $bytes) break;

                for ($i = 0; $i < $want; $i++) {
                    $out['scanned_rows']++;
                    $recOff = $i * $sizeof;

                    $hit = false;
                    $row = null;
                    if ($singleField !== null) {
                        // Decode just the one field — 5-20× faster than full row.
                        $fbytes = substr($buf, $recOff + $singleField['offset'],
                                         $singleField['byte_width']);
                        $val = $this->decodeOne($fbytes, $singleField['type']);
                        $hit = $this->matchValue($val, $needle);
                    } else {
                        // Any-field search — full decode is unavoidable, but we
                        // still saved the per-record fread() syscall.
                        $rec = substr($buf, $recOff, $sizeof);
                        $row = $this->decodeRecord($rec, $fields, $sizeof);
                        foreach ($row as $v) {
                            if ($this->matchValue($v, $needle)) { $hit = true; break; }
                        }
                    }

                    if ($hit) {
                        // Only decode the whole record now, for display.
                        $fullRow = ($row !== null)
                            ? $row
                            : $this->decodeRecord(substr($buf, $recOff, $sizeof),
                                                  $fields, $sizeof);
                        $out['rows'][] = ['row' => $rowIdx + $i, 'data' => $fullRow];
                        if (count($out['rows']) >= $limit) {
                            $out['truncated'] = true;
                            return $out;
                        }
                    }
                }
                $rowIdx += $want;
            }
        } finally {
            fclose($fh);
        }
        return $out;
    }

    /**
     * Decode a SINGLE field from every record in a range.
     *
     * Used by the sidebar record list — we need just the "name" column for
     * up to a few hundred records and don't want to pay for full-row decode.
     *
     * Returns an associative array:
     *   [ <rowIndex> => <decoded value>, ... ]
     *
     * Returns an empty array if the field isn't in the schema.
     */
    public function decodeField($listIndex, $schema, $fieldName, $offset = 0, $limit = 500)
    {
        if (!isset($this->lists[$listIndex])) return [];
        $meta = $this->lists[$listIndex];
        $sizeof = $meta['sizeof'];
        $count  = $meta['count'];
        if ($sizeof === 0 || $count === 0 || $fieldName === '') return [];

        // Find the field + its byte offset in the record.
        $fOff = 0; $target = null;
        foreach ($schema as $f) {
            $w = self::typeWidth($f['type']);
            if ($w === null) return [];
            if ($f['name'] === $fieldName) {
                $target = ['offset' => $fOff, 'byte_width' => $w, 'type' => $f['type']];
                break;
            }
            $fOff += $w;
        }
        if ($target === null) return [];
        if ($target['offset'] + $target['byte_width'] > $sizeof) return [];

        $startRow = max(0, (int)$offset);
        $startRow = min($startRow, $count);
        $rowsToRead = min((int)$limit, $count - $startRow);
        if ($rowsToRead <= 0) return [];

        $out = [];
        $fh = fopen($this->filepath, 'rb');
        if (!$fh) return [];
        try {
            // Bulk-read in ~1 MiB chunks aligned to record size, then pull the
            // slice we need from each record.
            $recsPerChunk = max(1, (int)(1048576 / max(1, $sizeof)));
            fseek($fh, $meta['data_offset'] + $startRow * $sizeof);
            $read = 0;
            while ($read < $rowsToRead) {
                $want  = min($recsPerChunk, $rowsToRead - $read);
                $bytes = $want * $sizeof;
                $buf   = fread($fh, $bytes);
                if (strlen($buf) < $bytes) break;
                for ($i = 0; $i < $want; $i++) {
                    $recOff = $i * $sizeof;
                    $fbytes = substr($buf, $recOff + $target['offset'], $target['byte_width']);
                    $out[$startRow + $read + $i] = $this->decodeOne($fbytes, $target['type']);
                }
                $read += $want;
            }
        } finally {
            fclose($fh);
        }
        return $out;
    }

    /** Decode a single field's chunk — used by the search fast-path. */
    private function decodeOne($chunk, $type)
    {
        switch (true) {
            case $type === 'int32':  return unpack('l', $chunk)[1];
            case $type === 'int64':  return unpack('q', $chunk)[1];
            case $type === 'float':  return unpack('g', $chunk)[1];
            case $type === 'double': return unpack('e', $chunk)[1];
            case strncmp($type, 'wstring:', 8) === 0: return $this->decodeWString($chunk);
            case strncmp($type, 'byte:', 5) === 0:    return bin2hex($chunk);
            default: return bin2hex($chunk);
        }
    }

    /** Case-insensitive substring test against a decoded field value. */
    private function matchValue($v, $lcNeedle)
    {
        if ($v === null) return false;
        if (is_float($v)) {
            // Compare against a trimmed decimal form, not scientific notation.
            $s = rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
        } else {
            $s = (string)$v;
        }
        if ($s === '') return false;
        return mb_stripos($s, $lcNeedle, 0, 'UTF-8') !== false;
    }

    /**
     * Return a hex dump of the first $nBytes of list $listIndex,
     * useful for manually inferring the schema.
     */
    public function hexDumpList($listIndex, $nBytes = 256, $recordIndex = 0)
    {
        if (!isset($this->lists[$listIndex])) return '';
        $meta = $this->lists[$listIndex];
        if ($meta['sizeof'] === 0 || $meta['count'] === 0) return '';
        $fh = fopen($this->filepath, 'rb');
        try {
            fseek($fh, $meta['data_offset'] + $recordIndex * $meta['sizeof']);
            $raw = fread($fh, min($nBytes, $meta['sizeof']));
        } finally {
            fclose($fh);
        }
        return $raw;
    }

    // ------------------------------------------------------------------
    // Type helpers
    // ------------------------------------------------------------------

    /**
     * Supported type tokens:
     *   int32, int64, float, double, byte:N, wstring:N
     * Return byte width for a type token, or null if unrecognised.
     */
    public static function typeWidth($t)
    {
        static $widths = [
            'int32'  => 4,
            'int64'  => 8,
            'float'  => 4,
            'double' => 8,
        ];
        if (isset($widths[$t])) return $widths[$t];
        if (strncmp($t, 'wstring:', 8) === 0) {
            $n = (int)substr($t, 8);
            return $n > 0 ? $n : null;
        }
        if (strncmp($t, 'byte:', 5) === 0) {
            $tail = substr($t, 5);
            if ($tail === 'AUTO') return null;
            $n = (int)$tail;
            return $n > 0 ? $n : null;
        }
        return null;
    }

    /** Available type tokens for the field-editor UI. */
    public static function availableTypes()
    {
        return [
            'int32', 'int64', 'float', 'double',
            'wstring:16', 'wstring:32', 'wstring:64', 'wstring:128',
            'wstring:200', 'wstring:256', 'wstring:512', 'wstring:1024',
            'byte:1', 'byte:2', 'byte:4', 'byte:8', 'byte:16', 'byte:32',
        ];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private function readU32($fh)
    {
        $d = fread($fh, 4);
        if (strlen($d) < 4) {
            throw new RuntimeException("EOF reading uint32");
        }
        return unpack('V', $d)[1];
    }

    private function skipMarker($fh, $marker)
    {
        if ($marker === 'md5') {
            fread($fh, 8);
        } elseif ($marker === 'exporter_meta') {
            $this->readU32($fh); // tag
            $len = $this->readU32($fh);
            if ($len > 0 && $len <= 1024) fread($fh, $len);
            $this->readU32($fh); // timestamp
        } elseif ($marker === 'tag_block') {
            $this->readU32($fh); // tag
            $len = $this->readU32($fh);
            if ($len > 0 && $len <= 4096) fread($fh, $len);
        }
    }

    private function decodeRecord($raw, $fields, $recordSize)
    {
        $row = [];
        foreach ($fields as $f) {
            $off = $f['offset'];
            $bw  = $f['byte_width'];
            $t   = $f['type'];
            if ($off + $bw > $recordSize) {
                $row[$f['name']] = null;
                continue;
            }
            $chunk = substr($raw, $off, $bw);
            switch (true) {
                case $t === 'int32':
                    $row[$f['name']] = unpack('l', $chunk)[1];
                    break;
                case $t === 'int64':
                    // Requires 64-bit PHP
                    $row[$f['name']] = unpack('q', $chunk)[1];
                    break;
                case $t === 'float':
                    $row[$f['name']] = unpack('g', $chunk)[1]; // little-endian float
                    break;
                case $t === 'double':
                    $row[$f['name']] = unpack('e', $chunk)[1]; // little-endian double
                    break;
                case strncmp($t, 'wstring:', 8) === 0:
                    $row[$f['name']] = $this->decodeWString($chunk);
                    break;
                case strncmp($t, 'byte:', 5) === 0:
                    $row[$f['name']] = bin2hex($chunk);
                    break;
                default:
                    $row[$f['name']] = bin2hex($chunk);
            }
        }
        return $row;
    }

    private function decodeWString($raw)
    {
        // UTF-16LE, stop at first null wchar
        $text = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
        if ($text === false) return '';
        $pos = strpos($text, "\x00");
        if ($pos !== false) $text = substr($text, 0, $pos);
        return $text;
    }
}
