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

/** 字型清單：檔名 => 下載網址與預期 SHA-256（install_font.php 與 deploy.sh 共用）。 */
function font_install_manifest(): array {
    // 靜態字重而非 variable font，避免 pdf-lib 預設取最小字重（wght=100）
    return [
        'NotoSansTC-Regular.ttf' => [
            'url' => 'https://fonts.gstatic.com/s/notosanstc/v39/-nFuOG829Oofr2wohFbTp9ifNAn722rq0MXz76Cy_Co.ttf',
            'sha256' => '619662a0583f38311e92666927e5edbfd30f2a1fbe8593685660bd11bdd46a10',
        ],
        'NotoSansTC-Bold.ttf' => [
            'url' => 'https://fonts.gstatic.com/s/notosanstc/v39/-nFuOG829Oofr2wohFbTp9ifNAn722rq0MXz70e1_Co.ttf',
            'sha256' => '33e8464f3432fd9eba5fa6ff74f5fb9ee612cad703877bd71c36e6f167c0a7e3',
        ],
    ];
}

/** 檔案狀態：ok（摘要相符）、missing（不存在或為空）、mismatch（存在但摘要不同）。 */
function font_install_status(string $path, string $expectedSha): string {
    if (!is_file($path) || filesize($path) === 0) return 'missing';
    return hash_equals($expectedSha, (string)hash_file('sha256', $path)) ? 'ok' : 'mismatch';
}

/** 錯誤代碼的白話說明與處理方式。 */
function font_install_explain(string $code): string {
    switch ($code) {
        case 'sha256_mismatch':
            return '下載內容與記錄的摘要不符（Google 可能更新了檔案）；現有檔案維持不動，網站仍可使用現有字型或改從 CDN 載入。'
                . '確認新檔案可信後，更新 font-install-lib.php 內的網址與 sha256';
        case 'font_too_large': return '下載內容超過大小上限，已放棄';
        case 'file_get_contents_failed': return '連不上下載網址（網路、DNS 或防火牆）';
        case 'allow_url_fopen_disabled': return 'PHP 的 allow_url_fopen 未開啟，請在 CLI 的 php.ini 開啟';
        case 'openssl_extension_missing': return 'PHP 未載入 openssl 擴充套件，請在 php.ini 開啟 extension=openssl';
        case 'save_failed': return '無法寫入 fonts/，請確認目前使用者有寫入權限';
        default: return $code;
    }
}
