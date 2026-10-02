# 字型安裝端點安全修補

基準：main `0852e2d530c72d83d0f309bbfbdfec426f093c6a`。

`install_font.php` 原先未檢查身分，HTTP GET `?force=1` 可強制從固定的 Google Fonts 來源下載並覆寫伺服器字型；來源與目的檔固定，不能把它說成任意 SSRF／任意檔案寫入。但匿名請求能耗用 PHP worker、下載流量與檔案寫入，並行下載也可能干擾正在讀取的字型。

改為僅 CLI 可執行；需要重新下載時用 `php install_font.php --force`。前端不再呼叫寫入端點，worker 原有的本地字型→瀏覽器 CDN fallback 保留。README 與 deploy 提示改為 CLI 安裝；部署者擁有字型寫入權限，PHP-FPM 僅需讀取。

驗證：PHP 8.3.6 下全部 4 個 PHP 語法、1 個 JS 語法、渲染後 4 個行內 JS 語法通過。`python tools/securitycheck.py` 啟動 loopback PHP server，請求 `/install_font.php?force=1`，取得 403／cli_only，没有觸發網路下載。沒有使用正式服務測試。

限制：未完整測試 PDF 合併、字型 CDN 連線與印刷輸出；不把語法檢查當作完整瀏覽器功能測試。CSP、CDN 供應鏈與檔案解析器的資源上限仍需後續強化。
