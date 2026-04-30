<?php
/**
 * gshop_icon.php — On-demand DDS → PNG icon server for GShop items.
 *
 * Usage:  gshop_icon.php?p=Surfaces\QShop\1\some_icon.dds
 *
 * On the first request the DDS file is converted to PNG via ImageMagick and
 * cached in client/cache/.  Subsequent requests are served straight from the
 * cache.  A transparent 1×1 PNG placeholder is returned for any path that
 * fails validation or cannot be found/converted.
 *
 * Security constraints (enforced before any filesystem access):
 *   - Path must match: Surfaces\QShop\{1|2}\<filename>.dds
 *   - No ".." segments or absolute-path tricks.
 *   - Resolved real path must reside inside client/surfaces/qshop/.
 */

// ── Config ────────────────────────────────────────────────────────────────────

// Resolve CLIENT_DIR once; normalise separators so comparisons work on Windows.
$clientDir = normSep(realpath(__DIR__ . DIRECTORY_SEPARATOR . 'client'));
$cacheDir  = $clientDir . '/' . 'cache';

// ImageMagick binary — try common locations on both Linux and Windows.
$convertBin = findConvert();

// ── Validate & normalise the requested path ────────────────────────────────────

$raw = (string)($_GET['p'] ?? '');

// Normalise to forward slashes, strip leading slash, collapse ".."
$norm = str_replace('\\', '/', $raw);
$norm = ltrim($norm, '/');
$norm = preg_replace('#(^|/)\.\.(/|$)#', '$1$2', $norm); // strip ".." segments
$norm = strtolower($norm);

// Must be exactly:  surfaces/qshop/{1|2}/<filename>.dds
if (!preg_match('#^surfaces/qshop/[12]/[^/\x00]+\.dds$#u', $norm)) {
    servePlaceholder(); exit;
}

// Build the source path and verify it sits inside CLIENT_DIR
$srcPath = $clientDir . '/' . $norm;
$realSrc = normSep(realpath($srcPath));

if ($realSrc === false
    || strpos($realSrc, $clientDir . '/') !== 0
    || !is_file($realSrc)) {
    servePlaceholder(); exit;
}

// ── Cache lookup / on-demand conversion ───────────────────────────────────────

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$cacheKey  = md5($norm);
$cachePath = $cacheDir . '/' . $cacheKey . '.png';

if (!is_file($cachePath)) {
    if ($convertBin === null) {
        servePlaceholder(); exit;
    }
    $tmpPath = $cachePath . '.tmp';
    $cmd = sprintf('"%s" %s %s',
        $convertBin,
        escapeshellarg($realSrc),
        escapeshellarg($tmpPath)
    );
    exec($cmd, $cmdOutput, $exitCode);
    if ($exitCode !== 0 || !is_file($tmpPath)) {
        @unlink($tmpPath);
        servePlaceholder(); exit;
    }
    rename($tmpPath, $cachePath);
}

// ── Serve the cached PNG ───────────────────────────────────────────────────────

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800'); // 7 days
header('Content-Length: ' . filesize($cachePath));
readfile($cachePath);
exit;

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Normalise directory-separator to forward-slash (fixes Windows realpath). */
function normSep(?string $path): ?string
{
    if ($path === null || $path === false) return null;
    return rtrim(str_replace('\\', '/', $path), '/');
}

/** Locate the ImageMagick `convert` binary. Returns null if not found. */
function findConvert(): ?string
{
    $candidates = [
        '/usr/bin/convert',
        '/usr/local/bin/convert',
        'C:/Program Files/ImageMagick-7.1.0-Q16-HDRI/magick.exe',
        'C:/Program Files/ImageMagick/convert.exe',
        'magick',    // if on PATH (Windows ImageMagick 7 uses `magick convert`)
        'convert',   // if on PATH
    ];
    foreach ($candidates as $bin) {
        if (@is_executable($bin)) return $bin;
    }
    // Last resort: ask the shell
    $which = PHP_OS_FAMILY === 'Windows' ? 'where convert 2>NUL' : 'which convert 2>/dev/null';
    $found = trim((string)shell_exec($which));
    return $found !== '' ? $found : null;
}

/** Return a 1×1 transparent PNG — never a browser broken-image icon. */
function servePlaceholder(): void
{
    header('Content-Type: image/png');
    header('Cache-Control: no-store');
    echo base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );
}
