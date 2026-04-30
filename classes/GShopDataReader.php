<?php
/**
 * GShopDataReader.php
 *
 * Parses the binary gshop.data / gshop1.data / gshop2.data files used by the
 * Jade Dynasty (ZX) client to store the in-game cash shop (QShop / GShop) catalogue.
 *
 * ── File format (little-endian) ────────────────────────────────────────────
 *
 *   [4]  DWORD  timestamp          Unix UTC timestamp of last export
 *   [4]  int    item_count         Number of GSHOP_ITEM records
 *   [item_count × 2630]            GSHOP_ITEM records (pack 1, see below)
 *   [4]  int    num_main           Number of main categories (≥ 7)
 *   [num_main × variable]          GSHOP_MAIN_TYPE records
 *
 * ── GSHOP_ITEM layout (2630 bytes, #pragma pack 1) ─────────────────────────
 *
 *   @   0  uint32   id             — object/template ID of the game item
 *   @   4  uint32   num            — stack size sold in this listing
 *   @   8  char[128] icon          — icon path, GBK-encoded
 *   @ 136  uint32   price          — price in cash-shop currency
 *   @ 140  uint32   duration       — item time limit in seconds (0 = permanent)
 *   @ 144  int32    discount       — discount flag/percentage
 *   @ 148  int32    bonus          — bonus flag/percentage
 *   @ 152  uint32   props          — property bitmask (recommended / featured …)
 *   @ 156  int32    main_type      — index into the main category array
 *   @ 160  int32    sub_type       — index into that main category's sub-type list
 *   @ 164  int32    local_id       — localisation ID
 *   @ 168  uint16[512] desc        — item description, UTF-16LE (1024 bytes)
 *   @1192  uint16[32]  name        — display name, UTF-16LE (64 bytes)
 *   @1256  bool     has_present    — whether a bonus gift item is attached
 *   @1257  uint16[32]  present_name— gift item name, UTF-16LE (64 bytes)
 *   @1321  uint32   present_id     — gift item template ID
 *   @1325  uint32   present_count  — gift item quantity
 *   @1329  uint32   present_duration— gift duration in seconds
 *   @1333  char[128] present_icon  — gift icon path, GBK-encoded
 *   @1461  bool     present_bind   — whether the gift item is bound
 *   @1462  uint16[512] present_desc— gift description, UTF-16LE (1024 bytes)
 *   @2486  int32    vt_type        — 0=always, 1=timed(UTC), 2=weekly, 3=monthly
 *   @2490  int32    vt_start       — activation start (interpretation per vt_type)
 *   @2494  int32    vt_end         — activation end   (interpretation per vt_type)
 *   @2498  int32    vt_param       — extra param for vt_type 1/2/3
 *   @2502  uint16[64] search_key   — comma-separated search keywords, UTF-16LE (128 bytes)
 *
 * ── GSHOP_MAIN_TYPE layout (variable) ─────────────────────────────────────
 *
 *   [4]       int      id          — category ID
 *   [128]     uint16[64] name      — category display name, UTF-16LE
 *   [4]       int      num_subs    — number of sub-categories
 *   [num_subs × 128]  uint16[64]  — sub-category names, UTF-16LE each
 *
 * Source reference: ZElement/ZCommon/globaldataman.h  (struct _GSHOP_ITEM)
 *                   ZElement/ZCommon/globaldataman.cpp (GlobalData_Load)
 */

class GShopDataReader
{
    // ── Fixed sizes ──────────────────────────────────────────────────────────
    const ITEM_SIZE = 2630;

    // ── Field offsets within a single GSHOP_ITEM raw record ─────────────────
    const OFF_ID              = 0;
    const OFF_NUM             = 4;
    const OFF_ICON            = 8;       // char[128], GBK
    const OFF_PRICE           = 136;
    const OFF_DURATION        = 140;     // seconds; 0 = permanent
    const OFF_DISCOUNT        = 144;
    const OFF_BONUS           = 148;
    const OFF_PROPS           = 152;
    const OFF_MAIN_TYPE       = 156;     // index into categories array
    const OFF_SUB_TYPE        = 160;     // index into sub_types of that main category
    const OFF_LOCAL_ID        = 164;
    const OFF_DESC            = 168;     // uint16[512] UTF-16LE, 1024 bytes
    const OFF_NAME            = 1192;    // uint16[32]  UTF-16LE, 64 bytes
    const OFF_HAS_PRESENT     = 1256;    // bool (1 byte)
    const OFF_PRESENT_NAME    = 1257;    // uint16[32]  UTF-16LE, 64 bytes
    const OFF_PRESENT_ID      = 1321;
    const OFF_PRESENT_COUNT   = 1325;
    const OFF_PRESENT_DURATION= 1329;    // seconds
    const OFF_PRESENT_ICON    = 1333;    // char[128], GBK
    const OFF_PRESENT_BIND    = 1461;    // bool (1 byte)
    const OFF_PRESENT_DESC    = 1462;    // uint16[512] UTF-16LE, 1024 bytes
    const OFF_VT_TYPE         = 2486;
    const OFF_VT_START        = 2490;
    const OFF_VT_END          = 2494;
    const OFF_VT_PARAM        = 2498;
    const OFF_SEARCH_KEY      = 2502;    // uint16[64]  UTF-16LE, 128 bytes

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Parse a gshop*.data file and return a structured array:
     *
     *   [
     *     'timestamp'  => int,             Unix UTC timestamp
     *     'items'      => [ ITEM, ... ],   decoded item records
     *     'categories' => [ CAT,  ... ],   main categories with sub-type lists
     *   ]
     *
     * Each ITEM is an associative array with keys matching the field names
     * documented at the top of this file (all strings are UTF-8).
     *
     * Each CAT is:  [ 'id'=>int, 'name'=>string, 'subs'=>[string,...] ]
     *
     * @throws RuntimeException on I/O or format errors.
     */
    public static function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $fp = fopen($filePath, 'rb');
        if ($fp === false) {
            throw new RuntimeException("Cannot open file: {$filePath}");
        }

        try {
            // ── File header ────────────────────────────────────────────────
            $timestamp  = self::readU32($fp);
            $itemCount  = self::readU32($fp);

            // ── Item records ───────────────────────────────────────────────
            $items = [];
            for ($i = 0; $i < $itemCount; $i++) {
                $raw = fread($fp, self::ITEM_SIZE);
                if ($raw === false || strlen($raw) < self::ITEM_SIZE) {
                    throw new RuntimeException(
                        "Truncated file: failed to read item #{$i} (expected " . self::ITEM_SIZE . " bytes)."
                    );
                }
                $items[] = self::decodeItem($raw);
            }

            // ── Category tree ──────────────────────────────────────────────
            $numMain = self::readI32($fp);
            $categories = [];
            for ($m = 0; $m < $numMain; $m++) {
                $catId   = self::readI32($fp);
                $catName = self::readWStrFp($fp, 64);    // WORD[64] = 128 bytes

                $numSub  = self::readI32($fp);
                $subs    = [];
                for ($s = 0; $s < $numSub; $s++) {
                    $subs[] = self::readWStrFp($fp, 64); // WORD[64] = 128 bytes
                }

                $categories[] = [
                    'id'   => $catId,
                    'name' => $catName,
                    'subs' => $subs,
                ];
            }

            return [
                'timestamp'  => $timestamp,
                'items'      => $items,
                'categories' => $categories,
            ];

        } finally {
            fclose($fp);
        }
    }

    /**
     * Format a duration in seconds as  "dd-HH:MM:SS" (or "Permanent" if 0).
     */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds === 0) return 'Permanent';
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return sprintf('%02d-%02d:%02d:%02d', $d, $h, $m, $s);
    }

    /**
     * Format a valid_time UTC timestamp as "YYYY-MM-DD HH:MM:SS" (or '' if 0).
     */
    public static function formatTimestamp(int $ts): string
    {
        return $ts > 0 ? gmdate('Y-m-d H:i:s', $ts) : '';
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    /** Decode one 2630-byte GSHOP_ITEM record into an associative array. */
    private static function decodeItem(string $raw): array
    {
        return [
            'id'               => self::u32($raw, self::OFF_ID),
            'num'              => self::u32($raw, self::OFF_NUM),
            'icon'             => self::gbk($raw, self::OFF_ICON,  128),
            'price'            => self::u32($raw, self::OFF_PRICE),
            'duration'         => self::u32($raw, self::OFF_DURATION),
            'discount'         => self::i32($raw, self::OFF_DISCOUNT),
            'bonus'            => self::i32($raw, self::OFF_BONUS),
            'props'            => self::u32($raw, self::OFF_PROPS),
            'main_type'        => self::i32($raw, self::OFF_MAIN_TYPE),
            'sub_type'         => self::i32($raw, self::OFF_SUB_TYPE),
            'local_id'         => self::i32($raw, self::OFF_LOCAL_ID),
            'desc'             => self::wstr($raw, self::OFF_DESC,  512),
            'name'             => self::wstr($raw, self::OFF_NAME,   32),
            'has_present'      => ord($raw[self::OFF_HAS_PRESENT]) !== 0,
            'present_name'     => self::wstr($raw, self::OFF_PRESENT_NAME,  32),
            'present_id'       => self::u32($raw, self::OFF_PRESENT_ID),
            'present_count'    => self::u32($raw, self::OFF_PRESENT_COUNT),
            'present_duration' => self::u32($raw, self::OFF_PRESENT_DURATION),
            'present_icon'     => self::gbk($raw, self::OFF_PRESENT_ICON, 128),
            'present_bind'     => ord($raw[self::OFF_PRESENT_BIND]) !== 0,
            'present_desc'     => self::wstr($raw, self::OFF_PRESENT_DESC, 512),
            'vt_type'          => self::i32($raw, self::OFF_VT_TYPE),
            'vt_start'         => self::i32($raw, self::OFF_VT_START),
            'vt_end'           => self::i32($raw, self::OFF_VT_END),
            'vt_param'         => self::i32($raw, self::OFF_VT_PARAM),
            'search_key'       => self::wstr($raw, self::OFF_SEARCH_KEY, 64),
        ];
    }

    // ── Primitive decoders ────────────────────────────────────────────────────

    /** Read a 4-byte unsigned int from a raw string at $off. */
    private static function u32(string $raw, int $off): int
    {
        return unpack('V', substr($raw, $off, 4))[1];
    }

    /** Read a 4-byte signed int from a raw string at $off. */
    private static function i32(string $raw, int $off): int
    {
        return unpack('l', substr($raw, $off, 4))[1];
    }

    /**
     * Decode a GBK-encoded byte string (null-terminated, padded to $len bytes)
     * and return a UTF-8 string.
     */
    private static function gbk(string $raw, int $off, int $len): string
    {
        $bytes = substr($raw, $off, $len);
        $nul   = strpos($bytes, "\x00");
        if ($nul !== false) $bytes = substr($bytes, 0, $nul);
        if ($bytes === '' || $bytes === false) return '';
        if (function_exists('iconv')) {
            $utf8 = @iconv('GBK', 'UTF-8//IGNORE', $bytes);
            if ($utf8 !== false) return $utf8;
        }
        return $bytes; // fallback — raw bytes
    }

    /**
     * Decode a UTF-16LE wide string ($maxChars WORDs = $maxChars×2 bytes),
     * stopping at the first null wchar. Returns a UTF-8 string.
     */
    private static function wstr(string $raw, int $off, int $maxChars): string
    {
        $bytes = substr($raw, $off, $maxChars * 2);
        $text  = @iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes);
        if ($text === false) return '';
        $nul = strpos($text, "\x00");
        if ($nul !== false) $text = substr($text, 0, $nul);
        return $text;
    }

    // ── File-handle readers ───────────────────────────────────────────────────

    private static function readU32($fp): int
    {
        $d = fread($fp, 4);
        if (strlen($d) < 4) throw new RuntimeException('Unexpected EOF reading uint32.');
        return unpack('V', $d)[1];
    }

    private static function readI32($fp): int
    {
        $d = fread($fp, 4);
        if (strlen($d) < 4) throw new RuntimeException('Unexpected EOF reading int32.');
        return unpack('l', $d)[1];
    }

    /** Read $maxChars UTF-16LE WORDs (= $maxChars×2 bytes) from the file handle. */
    private static function readWStrFp($fp, int $maxChars): string
    {
        $bytes = fread($fp, $maxChars * 2);
        if (strlen($bytes) < $maxChars * 2) throw new RuntimeException('Unexpected EOF reading wstring.');
        $text  = @iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes);
        if ($text === false) return '';
        $nul = strpos($text, "\x00");
        if ($nul !== false) $text = substr($text, 0, $nul);
        return $text;
    }
}
