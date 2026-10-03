<?php
// 部署用 CLI 工具。
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['installed' => false, 'error' => 'cli_only']);
    exit;
}

require_once __DIR__ . '/font-install-lib.php';

// 用法：php install_font.php [--force] [--check]
//   --force  已是正確版本也重新下載
//   --check  只檢查 fonts/ 狀態，不下載（全部正確時結束碼 0）
$fontDir = __DIR__ . '/fonts';
$force = in_array('--force', $argv ?? [], true);
$check = in_array('--check', $argv ?? [], true);
$fonts = font_install_manifest();

$licenseSrc = __DIR__ . '/licenses/NotoSansTC-OFL.txt';
$licenseOk = is_file($fontDir . '/OFL.txt') && @file_get_contents($fontDir . '/OFL.txt') === @file_get_contents($licenseSrc);

if ($check) {
    $bad = false;
    foreach ($fonts as $filename => $info) {
        $st = font_install_status($fontDir . '/' . $filename, $info['sha256']);
        echo $st . "\t" . $filename . "\n";
        $bad = $bad || $st !== 'ok';
    }
    echo ($licenseOk ? 'ok' : 'missing') . "\tOFL.txt\n";
    exit($bad || !$licenseOk ? 1 : 0);
}

// 下載前先檢查環境，讓失敗原因看得出來（而不是只有 file_get_contents_failed）
$envError = !ini_get('allow_url_fopen') ? 'allow_url_fopen_disabled'
    : (!extension_loaded('openssl') ? 'openssl_extension_missing' : null);

if (!is_dir($fontDir) && !mkdir($fontDir, 0755, true)) {
    fwrite(STDERR, "無法建立 fonts/，請確認目前使用者有寫入權限\n");
    exit(1);
}

// 授權檔與字型一併安裝
$license = @file_get_contents($licenseSrc);
if ($license === false || !font_install_atomic_write($fontDir . '/OFL.txt', $license)) {
    fwrite(STDERR, "無法安裝 fonts/OFL.txt，請確認 licenses/NotoSansTC-OFL.txt 存在且 fonts/ 可寫入\n");
    exit(1);
}

$failed = false;
foreach ($fonts as $filename => $info) {
    $dest = $fontDir . '/' . $filename;
    if (!$force && font_install_status($dest, $info['sha256']) === 'ok') {
        echo "已是最新\t$filename\n";
        continue;
    }
    $r = $envError ? ['ok' => false, 'error' => $envError] : downloadFont($info['url'], $dest, $info['sha256']);
    if ($r['ok']) {
        echo "已下載\t$filename\n";
    } else {
        $failed = true;
        echo "失敗\t$filename\n  " . font_install_explain($r['error']) . "\n";
    }
}
exit($failed ? 1 : 0);
