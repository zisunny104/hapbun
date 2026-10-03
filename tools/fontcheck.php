<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../font-install-lib.php';
$dir = sys_get_temp_dir() . '/fontcheck_' . bin2hex(random_bytes(6));
mkdir($dir);
try {
    $src = $dir . '/source'; $dest = $dir . '/font.ttf';
    file_put_contents($src, 'fixture-font'); file_put_contents($dest, 'old-font');
    $bad = downloadFont('file://' . $src, $dest, str_repeat('0', 64));
    if ($bad['ok'] || file_get_contents($dest) !== 'old-font') throw new RuntimeException('摘要不符應保留原字型');
    $good = downloadFont('file://' . $src, $dest, hash('sha256', 'fixture-font'));
    if (!$good['ok'] || file_get_contents($dest) !== 'fixture-font') throw new RuntimeException('驗證後原子替換');
    if (glob($dir . '/.font-*')) throw new RuntimeException('暫存檔應清理');
    // 過大：用本機 loopback 假伺服器送出超過 16MB 的內容
    $router = $dir . '/router.php';
    file_put_contents($router, '<?php if ($_SERVER["REQUEST_URI"] === "/big") { echo str_repeat("a", 16 * 1024 * 1024 + 10); } elseif ($_SERVER["REQUEST_URI"] === "/ok") { echo "fixture-font"; } else { http_response_code(404); }');
    $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1); fclose($sock);
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    try {
        for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
        file_put_contents($dest, 'old-font');
        $big = downloadFont("http://127.0.0.1:$port/big", $dest, str_repeat('0', 64));
        if ($big['ok'] || $big['error'] !== 'font_too_large' || file_get_contents($dest) !== 'old-font') throw new RuntimeException('過大應拒絕並保留原檔');
        $nf = downloadFont("http://127.0.0.1:$port/missing", $dest, str_repeat('0', 64));
        if ($nf['ok'] || file_get_contents($dest) !== 'old-font') throw new RuntimeException('404 應失敗並保留原檔');
        $okr = downloadFont("http://127.0.0.1:$port/ok", $dest, hash('sha256', 'fixture-font'));
        if (!$okr['ok'] || file_get_contents($dest) !== 'fixture-font') throw new RuntimeException('本機伺服器下載成功路徑');
    } finally {
        proc_terminate($srv); proc_close($srv);
    }
    // 狀態判斷
    if (font_install_status($dir . '/none', 'x') !== 'missing') throw new RuntimeException('不存在應為 missing');
    if (font_install_status($dest, str_repeat('0', 64)) !== 'mismatch') throw new RuntimeException('摘要不同應為 mismatch');
    if (font_install_status($dest, hash('sha256', 'fixture-font')) !== 'ok') throw new RuntimeException('摘要相同應為 ok');
    // 每個錯誤代碼都有說明，摘要不符的說明要提到處理方式
    foreach (['sha256_mismatch', 'font_too_large', 'file_get_contents_failed', 'allow_url_fopen_disabled', 'openssl_extension_missing', 'save_failed'] as $code) {
        if (font_install_explain($code) === $code) throw new RuntimeException("缺少 $code 的說明");
    }
    if (strpos(font_install_explain('sha256_mismatch'), 'font-install-lib.php') === false) throw new RuntimeException('摘要不符說明要指出處理方式');
    foreach (font_install_manifest() as $info) {
        if (!preg_match('/^[0-9a-f]{64}$/', $info['sha256']) || strpos($info['url'], 'https://fonts.gstatic.com/') !== 0) throw new RuntimeException('清單格式');
    }
    echo "fontcheck：摘要驗證、失敗保留原檔、原子替換、暫存清理、過大／404／成功路徑、狀態與錯誤說明通過\n";
} finally {
    foreach (glob($dir . '/{*,.font-*}', GLOB_BRACE) as $f) unlink($f);
    rmdir($dir);
}
