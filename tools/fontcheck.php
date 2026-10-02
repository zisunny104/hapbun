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
    echo "fontcheck：摘要驗證、失敗保留原檔、原子替換與暫存清理通過\n";
} finally {
    foreach (glob($dir . '/*') as $f) unlink($f);
    rmdir($dir);
}
