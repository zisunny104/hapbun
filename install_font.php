<?php
// 部署工具只允許 CLI；拒絕匿名 HTTP 觸發下載／覆寫。
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['installed' => false, 'error' => 'cli_only']);
    exit;
}

$fontDir = __DIR__ . '/fonts';
$force = in_array('--force', $argv ?? [], true);

// Noto Sans TC 靜態字重 TTF（來源：Google Fonts CDN，支援 CORS）
// 使用静態字重而非 variable fo，避免 pdf-lib 預設取最小字重（wght=100）
$fonts = [
    'NotoSansTC-Regular.ttf' =>
    'https://fonts.gstatic.com/s/notosanstc/v39/-nFuOG829Oofr2wohFbTp9ifNAn722rq0MXz76Cy_Co.ttf',
    'NotoSansTC-Bold.ttf' =>
    'https://fonts.gstatic.com/s/notosanstc/v39/-nFuOG829Oofr2wohFbTp9ifNAn722rq0MXz70e1_Co.ttf',
];

// 下載前先檢查環境，讓失敗原因看得出來（而不是只有 file_get_contents_failed）
$envError = !ini_get('allow_url_fopen') ? 'allow_url_fopen_disabled'
    : (!extension_loaded('openssl') ? 'openssl_extension_missing' : null);

if (!is_dir($fontDir)) {
    if (!mkdir($fontDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['installed' => false, 'error' => 'mkdir_failed']);
        exit;
    }
}

// 若所有字型已存在且不強制更新，直接回傳已安裝
if (!$force) {
    $allExist = true;
    foreach ($fonts as $filename => $_) {
        if (!file_exists($fontDir . '/' . $filename) || filesize($fontDir . '/' . $filename) === 0) {
            $allExist = false;
            break;
        }
    }
    if ($allExist) {
        echo json_encode(['installed' => true, 'fonts' => array_keys($fonts)]);
        exit;
    }
}

/**
 * 下載單一字型（file_get_contents，需 allow_url_fopen = On）
 * 回傳 ['ok'=>bool, 'error'=>string|null]
 */
function downloadFont(string $url, string $dest): array
{
    $context = stream_context_create(['http' => [
        'timeout' => 30,
        'follow_location' => 1,
    ]]);
    $data = @file_get_contents($url, false, $context);
    if ($data === false) {
        return ['ok' => false, 'error' => 'file_get_contents_failed'];
    }
    // 解析 HTTP 狀態碼
    if (isset($http_response_header) && is_array($http_response_header)) {
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m) && intval($m[1]) >= 400) {
            return ['ok' => false, 'error' => 'http_' . $m[1]];
        }
    }
    if (file_put_contents($dest, $data) === false) {
        return ['ok' => false, 'error' => 'save_failed'];
    }
    return ['ok' => true, 'error' => null];
}

$results = [];
foreach ($fonts as $filename => $url) {
    $dest = $fontDir . '/' . $filename;
    if (!$force && file_exists($dest) && filesize($dest) > 0) {
        $results[$filename] = 'already_exists';
        continue;
    }
    $r = $envError ? ['ok' => false, 'error' => $envError] : downloadFont($url, $dest);
    $results[$filename] = $r['ok'] ? 'downloaded' : ('failed:' . $r['error']);
}

$anyFailed = in_array(true, array_map(fn($v) => strpos($v, 'failed:') === 0, $results), true);
if ($anyFailed) {
    http_response_code(500);
}
echo json_encode(['installed' => !$anyFailed, 'results' => $results]);
