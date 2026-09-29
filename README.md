# 合本 (hapbun)

PDF 合併排版工具，可設定多頁、增加封面與目錄、頁碼，方便列印簡報講義。

## 功能特色

- **多檔案合併**：支援一次上傳多個 PDF 檔案進行合併
- **靈活排版**：可選擇每頁顯示 1、2、4 或 6 頁內容（N-up 排版）
- **紙張選擇**：支援 A4、A3、JIS B4 等常用紙張尺寸
- **方向設定**：可選擇橫向或直向輸出
- **封面製作**：支援範本封面、上傳封面或使用首個檔案作為封面
- **目錄生成**：自動生成可編輯的目錄頁，支援章節標題自訂
- **頁碼標註**：支援不顯示、置中、靠外側（雙面）等位置
- **裝訂邊設定**：為裝訂預留空間，奇偶頁自動調整
- **單頁外框**：可選擇性繪製每個小頁面的外框
- **檔案排序**：支援拖曳卡片重新排列檔案順序
- **列印最佳化**：特別適合列印簡報講義，節省紙張
- **隱私保護**：純前端處理，不上傳至伺服器

## 使用方式

1. **上傳檔案**
   - 點擊上傳區域或直接拖曳 PDF 檔案
   - 支援多檔案同時上傳
   - 單一檔案大小限制：50MB
   - 可拖曳卡片重新排列檔案順序

2. **封面與目錄（可選）**
   - 啟用封面：可選擇使用範本、上傳圖片或使用首個檔案
   - 啟用目錄：自動生成目錄頁，可編輯章節標題

3. **設定排版**
   - 選擇輸出紙張大小（A4、A3、B4）
   - 選擇方向（橫向或直向）
   - 選擇每頁顯示的版數（1、2、4、6 頁）
   - 調整裝訂邊和頁面間距
   - 勾選是否繪製單頁外框

4. **頁碼設定**
   - 選擇頁碼位置（不顯示、置中、靠外側）
   - 設定起始頁號

5. **處理下載**
   - 點擊「開始轉換並預覽」查看結果
   - 確認無誤後點擊「下載處理後的檔案」

## 技術規格

- **前端框架**：Tocas UI 5.0.3
- **PDF 處理**：pdf-lib 1.17.1 (純前端 JavaScript)
- **中文字型**：@pdf-lib/fontkit 1.1.1 (支援中文顯示)
- **檔案下載**：downloadjs 1.4.7
- **處理方式**：完全在瀏覽器端處理，無需後端伺服器

## 安裝

### 獨立使用

1. Clone repo：
```bash
git clone https://github.com/zisunny104/hapbun.git
cd hapbun
```

2. 配置網頁伺服器

3. 直接訪問 `index.php`

4. 安裝中文字型（`fonts/` 不進版本控制）：執行 `php install_font.php`，或用瀏覽器開啟 `install_font.php` 看 JSON 結果。需要 PHP 開啟 `allow_url_fopen` 與 `openssl` 擴充套件；失敗時會在結果中寫明原因（例如 `openssl_extension_missing`）

### 更新部署

在伺服器上的專案目錄執行 `./deploy.sh`：確認沒有未 commit 的修改，fetch remote `main`，**merge 前**用 `php -l` 檢查新增／修改的 PHP 檔語法（有錯就中止），fast-forward 更新後**檢查字型依賴**：`fonts/` 缺字型時會列出環境問題（`allow_url_fopen`、`openssl`、目錄權限），互動終端會詢問是否立即下載（先用 `install_font.php`，失敗再改用 `curl`），非互動執行則印出安裝指令。缺字型不會讓部署失敗，瀏覽器端仍會 fallback 到 Google Fonts。

- `DEPLOY_BRANCH`：要部署的 branch，預設 `main`
- `DEPLOY_RELOAD_CMD`：更新後要執行的指令，例如 `DEPLOY_RELOAD_CMD="systemctl reload php8.3-fpm" ./deploy.sh`

### 與 KoiLiSu 開利手整合

1. 將此 repo 放置在 `koilisu/apps/hapbun/` 目錄
2. 透過 `https://toka.dev/koilisu/hapbun` 造訪

## 其他說明

### 中文字型嵌入方案

pdf-lib 預設僅支援 WinAnsi 編碼（Helvetica 等標準字型），無法直接顯示中文。本應用採用以下策略：

1. **靜態字重 TTF**：使用 Google Fonts CDN（`fonts.gstatic.com`）提供的 Noto Sans TC 靜態字重 TTF 檔案
   - `NotoSansTC-Regular.ttf`（wght=400）：內文、副標題
   - `NotoSansTC-Bold.ttf`（wght=700）：封面標題、目錄標題
   - 未使用 variable font（`NotoSansTC[wght].ttf`），因為 pdf-lib 會預設取最小字重軸（wght=100），導致文字極細
   - 未使用 woff2 subset，因為每個 subset 檔案只涵蓋部分 Unicode 區段，單一檔案無法完整顯示中文

2. **字型載入策略**（`loadFontBytes`）：優先從伺服器本地 `fonts/` 目錄讀取；若不存在則 fallback 至 CDN
   - `fonts.gstatic.com` 支援 CORS，可直接從瀏覽器 fetch

3. **伺服器端字型安裝**（`install_font.php`）：每次合併時於背景呼叫，自動下載字型至 `fonts/` 目錄快取（`fonts/` 不進版本控制）
   - 使用 PHP `file_get_contents`，不依賴 curl
   - 需確認 `allow_url_fopen = On`
   - 也可直接開啟 `/koilisu/hapbun/install_font.php` 看安裝結果（JSON），或手動把上述兩個 TTF 下載到 `fonts/`

4. **WinAnsi 安全防護**（`ensureWinAnsi`）：若字型載入失敗則 fallback 至 Helvetica，同時過濾非 WinAnsi 字元，避免 pdf-lib 拋出編碼錯誤，並在頁面上顯示警告

5. **CDN 依賴檢查**：pdf-lib、fontkit、downloadjs 任一載入失敗時，頁面頂端顯示錯誤提示並停用「處理」按鈕，不會整頁無回應

## 使用的開源函式庫

- [pdf-lib](https://pdf-lib.js.org/) - MIT License
- [@pdf-lib/fontkit](https://github.com/Hopding/fontkit) - MIT License
- [downloadjs](https://github.com/rndme/download) - MIT License
- [Tocas UI](https://tocas-ui.com/) - MIT License

## 授權

此專案為 [KoiLiSu 開利手](https://github.com/zisunny104/koilisu) 專案的一部分，MIT 授權，由 Tokas (Xiang-zi Xie) 開發。詳見 [LICENSE](LICENSE)。

---

**版本**：1.0.4
**作者**：Tokas (Xiang-zi Xie)
**專案**：KoiLiSu 開利手
**網址**：https://toka.dev/koilisu/hapbun

## 更新日誌

### v1.0.4 (2026-03-11)

- 修正字體與頁碼顯示問題
  - 前端改為載入靜態 TTF（Regular + Bold），修正 variable font 導致字重過細的問題
  - 修正伺服器端 `install_font.php` 與字型快取機制，確保 Noto Sans TC 可正確下載與使用
  - 目錄（TOC）字元寬度量測與點線對齊修正，加入目錄項目自動換行避免遮擋頁碼
  - 頁碼改為可選啟用底色（深底/淺底），並改為膠囊（pill）式背景，文字做水平與垂直置中，且會依數字長度伸展

### v1.0.3 (2025-12-11)

- 範本封面加入裝訂邊處理（奇數頁，內容向右偏移）
- 目錄頁加入智慧裝訂邊處理
  - 自動判斷目錄是奇數頁或偶數頁
  - 根據頁碼調整左右邊界，確保不被裝訂遮擋
- 改善雙面列印時的版面配置

### v1.0.2 (2025-12-10)

- 修正上傳封面功能無法運作的問題
- 新增封面檔案上傳處理邏輯
- 新增封面檔案選擇狀態顯示
- 上傳的 PDF 第一頁會被用作封面

### v1.0.1 (2025-12-10)

- 修正目錄編輯器頁碼計算錯誤
- 目錄頁碼現在會根據實際 PDF 頁數和 N-up 設定正確計算
- 考慮封面和目錄頁的頁碼偏移

### v1.0.0 (2025-12-10)

- 初始版本發布
- PDF 多檔案合併與 N-up 排版
- 封面和目錄生成功能
- 檔案拖曳排序與章節編輯
