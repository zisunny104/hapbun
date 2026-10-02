<?php
// 部署用 CLI 工具。
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['installed' => false, 'error' => 'cli_only']);
    exit;
}

require_once __DIR__ . '/font-install-lib.php';

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

$digests = [
    'NotoSansTC-Regular.ttf' => '619662a0583f38311e92666927e5edbfd30f2a1fbe8593685660bd11bdd46a10',
    'NotoSansTC-Bold.ttf' => '33e8464f3432fd9eba5fa6ff74f5fb9ee612cad703877bd71c36e6f167c0a7e3',
];

// 下載前先檢查環境，讓失敗原因看得出來（而不是只有 file_get_contents_failed）
$envError = !ini_get('allow_url_fopen') ? 'allow_url_fopen_disabled'
    : (!extension_loaded('openssl') ? 'openssl_extension_missing' : null);

if (!is_dir($fontDir)) {
    if (!mkdir($fontDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['installed' => false, 'error' => 'mkdir_failed']);
        exit(1);
    }
}

// 字型與授權一併安裝。
$license = @file_get_contents(__DIR__ . '/licenses/NotoSansTC-OFL.txt');
if ($license === false || !font_install_atomic_write($fontDir . '/OFL.txt', $license)) {
    echo json_encode(['installed' => false, 'error' => 'license_install_failed']);
    exit(1);
}

$results = [];
foreach ($fonts as $filename => $url) {
    $dest = $fontDir . '/' . $filename;
    if (!$force && is_file($dest) && hash_equals($digests[$filename], (string)hash_file('sha256', $dest))) {
        $results[$filename] = 'already_exists';
        continue;
    }
    $r = $envError ? ['ok' => false, 'error' => $envError] : downloadFont($url, $dest, $digests[$filename]);
    $results[$filename] = $r['ok'] ? 'downloaded' : ('failed:' . $r['error']);
}

$anyFailed = in_array(true, array_map(fn($v) => strpos($v, 'failed:') === 0, $results), true);
if ($anyFailed) {
    http_response_code(500);
}
echo json_encode(['installed' => !$anyFailed, 'results' => $results]);
exit($anyFailed ? 1 : 0);
