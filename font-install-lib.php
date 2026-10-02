<?php
/** 寫入同目錄暫存檔後 rename，失敗時保留原檔。 */
function font_install_atomic_write(string $dest, string $data): bool {
    $tmp = @tempnam(dirname($dest), '.font-');
    if ($tmp === false) return false;
    try {
        if (@file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) return false;
        if (!@chmod($tmp, 0644) || !@rename($tmp, $dest)) return false;
        return true;
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
}

/** 驗證摘要與大小後安裝字型。 */
function downloadFont(string $url, string $dest, string $expectedSha): array {
    $context = stream_context_create([
        'http' => ['timeout' => 30, 'follow_location' => 0],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $maxBytes = 16 * 1024 * 1024;
    $data = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
    if ($data === false) return ['ok' => false, 'error' => 'file_get_contents_failed'];
    if (strlen($data) > $maxBytes) return ['ok' => false, 'error' => 'font_too_large'];
    if (!hash_equals($expectedSha, hash('sha256', $data))) return ['ok' => false, 'error' => 'sha256_mismatch'];
    if (!font_install_atomic_write($dest, $data)) return ['ok' => false, 'error' => 'save_failed'];
    return ['ok' => true, 'error' => null];
}
