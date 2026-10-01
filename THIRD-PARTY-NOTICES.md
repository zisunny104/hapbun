本專案的合併、排版、目錄生成等核心邏輯為原生實作；PDF 讀寫與字型嵌入則透過
下列第三方函式庫。

#### 由瀏覽器自 CDN 載入 · Loaded at runtime from a CDN (not redistributed here)

| 元件 Component      | 用途 Purpose               | 授權 License |
|----------------------|-----------------------------|--------------|
| Tocas UI              | 介面框架 UI shell           | MIT          |
| pdf-lib               | PDF 讀寫與合併              | MIT          |
| @pdf-lib/fontkit      | 字型嵌入（支援中文顯示）    | MIT          |
| downloadjs            | 前端檔案下載                | MIT          |

Tocas UI 內含 Font Awesome Free 圖示（Icons CC BY 4.0 / Fonts SIL OFL 1.1 / Code MIT）。

#### 伺服器端字型檔 · Server-side font asset

Noto Sans TC（Regular／Bold 靜態字重）由 `install_font.php` 於首次執行時自
Google Fonts CDN（`fonts.gstatic.com`）下載並快取於 `fonts/` 目錄，授權為
SIL Open Font License 1.1，不隨本 repo 原始碼一併散布。
