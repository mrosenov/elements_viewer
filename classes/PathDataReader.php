<?php
/**
 * PathDataReader.php
 *
 * Reads the binary path.data file used by the Jade Dynasty (ZX) client engine (Angelica).
 *
 * File format (all integers are little-endian):
 *   [4 bytes] Magic identifier  – 0x504D4944  ("PMID" big-endian, "DIMP" on disk LE)
 *   [4 bytes] Entry count       – uint32
 *   [entry count] x {
 *     [4 bytes] Path ID         – uint32
 *     [4 bytes] String length   – uint32
 *     [n bytes] Path string     – GBK-encoded bytes (not null-terminated in the count)
 *   }
 *
 * Source reference: ZElement/ZElementData/DataPathMan.cpp
 *   #define PATHMAP_IDENTIFY  (('P'<<24)|('M'<<16)|('I'<<8)|'D')  // 0x504D4944
 *
 * Note: path strings are stored in GBK (Windows Simplified Chinese / CP936).
 *       This reader converts them to UTF-8 for display.
 */

// Magic constant: (('P'<<24)|('M'<<16)|('I'<<8)|'D') = 0x504D4944
define('PATHMAP_IDENTIFY', 0x504D4944);

// Default data file location (relative to this file's parent directory)
define('PATH_DATA_FILE', __DIR__ . '/../data/path.data');

// ─── Reader ───────────────────────────────────────────────────────────────────

/**
 * Reads path.data and returns an array of entries:
 *   [
 *     [ 'id' => <uint32>, 'path' => <string (UTF-8)> ],
 *     ...
 *   ]
 *
 * Throws RuntimeException on any format or I/O error.
 *
 * @param  string $filePath  Absolute or relative path to path.data
 * @return array
 */
function readPathData(string $filePath): array
{
    if (!file_exists($filePath)) {
        throw new RuntimeException("File not found: {$filePath}");
    }

    $fp = fopen($filePath, 'rb');
    if ($fp === false) {
        throw new RuntimeException("Cannot open file: {$filePath}");
    }

    try {
        // ── Header ─────────────────────────────────────────────────────────

        // [4 bytes] Magic identifier (uint32 LE)
        $raw = fread($fp, 4);
        if ($raw === false || strlen($raw) < 4) {
            throw new RuntimeException('Failed to read magic identifier.');
        }
        $magic = unpack('V', $raw)[1];   // 'V' = unsigned long LE (32-bit)

        if ($magic !== PATHMAP_IDENTIFY) {
            throw new RuntimeException(sprintf(
                'Invalid magic: expected 0x%08X, got 0x%08X. Not a valid path.data file.',
                PATHMAP_IDENTIFY,
                $magic
            ));
        }

        // [4 bytes] Entry count (uint32 LE)
        $raw = fread($fp, 4);
        if ($raw === false || strlen($raw) < 4) {
            throw new RuntimeException('Failed to read entry count.');
        }
        $entryCount = unpack('V', $raw)[1];

        // ── Entries ────────────────────────────────────────────────────────

        $entries = [];

        for ($i = 0; $i < $entryCount; $i++) {
            // [4 bytes] Path ID (uint32 LE)
            $raw = fread($fp, 4);
            if ($raw === false || strlen($raw) < 4) {
                throw new RuntimeException("Failed to read Path ID for entry #{$i}.");
            }
            $pathId = unpack('V', $raw)[1];

            // [4 bytes] String length (uint32 LE)
            $raw = fread($fp, 4);
            if ($raw === false || strlen($raw) < 4) {
                throw new RuntimeException("Failed to read string length for entry #{$i}.");
            }
            $len = unpack('V', $raw)[1];

            // [len bytes] Path string (GBK-encoded)
            $pathStr = '';
            if ($len > 0) {
                $pathStr = fread($fp, $len);
                if ($pathStr === false || strlen($pathStr) < $len) {
                    throw new RuntimeException("Failed to read path string for entry #{$i} (expected {$len} bytes).");
                }
                // Trim any trailing null bytes
                $pathStr = rtrim($pathStr, "\0");

                // The ZX client stores paths in GBK (Windows Simplified Chinese / CP936).
                // Convert to UTF-8 so the string is safe for HTML output and PHP string
                // functions. Fall back to the raw bytes if iconv is unavailable.
                if (function_exists('iconv')) {
                    $utf8 = @iconv('GBK', 'UTF-8//IGNORE', $pathStr);
                    if ($utf8 !== false) {
                        $pathStr = $utf8;
                    }
                }
            }

            $entries[] = [
                'id'   => $pathId,
                'path' => $pathStr,
            ];
        }

        return $entries;

    } finally {
        fclose($fp);
    }
}
