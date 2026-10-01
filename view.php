<!DOCTYPE html>
<html id="html" lang="zh-tw">

<?php
// 計算此應用展開後的 URL 基準路徑（例： /koilisu/apps/hapbun）
$appBasePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/\\');
$appBasePath = str_replace('\\', '/', $appBasePath);
$appConfig = require __DIR__ . '/config.php';
$appVersion = $appConfig['version'] ?? '0.0.0';
?>

<head>
    <meta charset="UTF-8">
    <title>合本 - KoiLiSu | prjToka</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tocas-ui/5.7.0/tocas.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tocas-ui/5.7.0/tocas.min.js"></script>
    <script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
    <script src="https://unpkg.com/@pdf-lib/fontkit@1.1.1/dist/fontkit.umd.min.js"></script>
    <script src="https://unpkg.com/downloadjs@1.4.7/download.min.js"></script>

    <style type="text/css">
    body {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }

    .main-content {
        flex: 1;
    }

    /* 處理中覆蓋層 */
    .processing-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    }

    .processing-overlay.active {
        display: flex !important;
    }

    #dropZone.drag-over {
        background: var(--ts-primary-50);
        border-color: var(--ts-primary-400);
    }

    /* ---- 無障礙：僅供螢幕閱讀器讀出的文字（沿用 KoiLiSu common/header.php 慣例） ---- */
    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    /* ==== 授權（License）彈窗：Tocas UI 沒有直接對應的版面元件，僅保留固定高度／捲動這類
       佈局必要的最小樣式，內文標記（表格、清單、段落…）一律交給 Tocas 原生 class。 ==== */
    .help-dialog-content {
        display: flex;
        flex-direction: column;
        /* 固定高度：切換章節時分頁列與關閉鈕位置不跳動，內文不夠長時底下留白 */
        height: min(40rem, calc(100dvh - 3rem));
    }

    .help-body {
        flex: 1 1 auto;
        min-height: 12rem;
        overflow-y: auto;
        overflow-wrap: anywhere; /* LICENSE 轉出的內容可能含長字串（網址、路徑），需要可以換行 */
    }

    .help-body kbd {
        white-space: nowrap;
    }

    /* Tocas 沒有專門的引言元件，用 .ts-text.is-description + is-italic 模擬，僅額外保留左側框線辨識 */
    .help-quote {
        margin: 0;
        padding-left: .75rem;
        border-left: 2px solid var(--ts-gray-300, #ddd);
    }

    /* 分頁是 <button>（鍵盤可聚焦），外觀維持 Tocas 的 .ts-tab .item，章節一多就換行 */
    .ts-tab.help-tabs {
        display: flex;
        flex-wrap: wrap;
        height: auto;
        margin: 0 1rem;
    }

    .ts-tab.help-tabs > button.item {
        appearance: none;
        border: 0;
        font: inherit;
    }

    .ts-tab.help-tabs > button.item:focus-visible {
        outline: 2px solid light-dark(#1d4ed8, #93c5fd);
        outline-offset: -2px;
    }

    /* 頁尾 License 鈕是 <button>，需重置原生按鈕樣式讓 .ts-badge 的 pill 外觀生效 */
    #btn-license.ts-badge {
        appearance: none;
        border: 0;
        font: inherit;
        cursor: pointer;
    }
    </style>
    <script id="clientEventHandlersJS" language="javascript" type="text/javascript">
    // CDN 函式庫載入失敗時不直接丟 ReferenceError 讓整頁失效，改在頁面上提示並停用處理鈕
    const missingDeps = [
        ['PDFLib', 'pdf-lib'],
        ['fontkit', '@pdf-lib/fontkit'],
        ['download', 'downloadjs']
    ].filter(([globalName]) => typeof window[globalName] === 'undefined').map(([, name]) => name);
    const {
        PDFDocument,
        rgb,
        StandardFonts
    } = window.PDFLib || {};

    function showNotice(type, title, message) {
        const notice = document.getElementById('appNotice');
        notice.className = `ts-notice is-${type} has-top-spaced`;
        notice.querySelector('.title').textContent = title;
        notice.querySelector('.content').textContent = message;
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (missingDeps.length > 0) {
            showNotice('negative', '必要元件載入失敗',
                `無法從 CDN（unpkg.com）載入：${missingDeps.join('、')}。請確認網路連線或是否被擋，然後重新整理頁面。`);
            document.getElementById('processBtn').disabled = true;
        }
    });
    let uploadedFiles = [];
    let processedPdfBytes = null;
    let coverImageFile = null; // 儲存上傳的封面檔案

    // 檔案驗證
    function validateFile(file) {
        if (!file.type.includes('pdf')) {
            alert('請上傳 PDF 檔案');
            return false;
        }
        if (file.size > 50 * 1024 * 1024) { // 50MB limit
            alert('檔案大小不可超過 50MB');
            return false;
        }
        return true;
    }

    // 檔案上傳處理
    function handleFileSelect(files) {
        Array.from(files).forEach(file => {
            if (validateFile(file)) {
                uploadedFiles.push(file);
                addFileToList(file);
            }
        });
        updateUI();
    }

    // 新增檔案卡片
    function addFileToList(file) {
        const sortableList = document.getElementById('sortableFileList');

        const fileCard = document.createElement('div');
        fileCard.className = 'ts-segment';
        fileCard.style.minWidth = '280px';
        fileCard.style.flexShrink = '0';
        fileCard.draggable = true;
        fileCard.dataset.filename = file.name;

        fileCard.innerHTML = `
                <div class="ts-box" style="height: 100%;">
                    <div class="ts-content is-secondary is-dense">
                        <div class="ts-grid is-middle-aligned">
                            <div class="column is-fluid">
                                <div class="ts-text is-description is-small" data-role="filename"></div>
                            </div>

                            <div class="column">
                                <span class="ts-icon is-grip-vertical-icon" style="cursor: move; color: var(--ts-gray-400);"></span>
                            </div>
                        </div>
                    </div>
                    <div class="ts-divider"></div>
                    <div class="ts-content">
                        <div class="ts-text is-label">章節標題</div>
                        <div class="ts-input is-underlined is-fluid has-top-spaced-small">
                            <input type="text" class="file-title" placeholder="請輸入章節名稱">
                        </div>
                    </div>
                    <div class="ts-content is-dense">
                        <div class="ts-grid is-middle-aligned">
                            <div class="column is-fluid">
                                <div class="ts-text is-description">
                                    <span class="ts-icon is-file-icon"></span>
                                    <span data-role="filesize"></span>
                                </div>
                            </div>
                            <div class="column">
                                <button type="button" class="ts-button is-small is-outlined is-negative" data-role="remove" title="移除檔案">
                                    <span class="ts-icon is-trash-icon"></span>
                                    移除
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        // 檔名／標題／檔案大小含使用者可控字串，一律以 textContent／value 屬性賦值，
        // 不經過 innerHTML／inline onclick 字串拼接，避免惡意檔名（如含 <img onerror> 或引號）造成 DOM XSS。
        fileCard.querySelector('[data-role="filename"]').textContent = file.name;
        fileCard.querySelector('.file-title').value = file.name.replace('.pdf', '');
        fileCard.querySelector('[data-role="filesize"]').textContent = formatFileSize(file.size);
        fileCard.querySelector('[data-role="remove"]').addEventListener('click', () => removeFile(file.name));
        sortableList.appendChild(fileCard);

        // 加入拖動事件
        setupDragAndDrop(fileCard);
    }

    // 移除檔案
    function removeFile(fileName) {
        uploadedFiles = uploadedFiles.filter(f => f.name !== fileName);
        updateFileList();
    }

    // 更新檔案列表
    function updateFileList() {
        const sortableList = document.getElementById('sortableFileList');
        sortableList.innerHTML = '';
        uploadedFiles.forEach(file => addFileToList(file));
        updateUI();
        updateCoverFirstFileName();
    }

    // 格式化檔案大小
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    // 更新介面狀態
    function updateUI() {
        const processBtn = document.getElementById('processBtn');
        const fileCount = document.getElementById('fileCount');
        const uploadColumn = document.getElementById('uploadColumn');
        const fileListColumn = document.getElementById('fileListColumn');
        const settingsSection = document.getElementById('settingsSection');
        const uploadSection = document.getElementById('uploadSection');

        if (uploadedFiles.length > 0) {
            processBtn.disabled = missingDeps.length > 0;
            fileCount.textContent = `已選擇 ${uploadedFiles.length} 個檔案`;
            uploadColumn.className = 'column is-6-wide mobile:is-fluid';
            fileListColumn.className = 'column is-fluid';
            fileListColumn.style.display = '';
            uploadSection.classList.add('is-stretched');
            settingsSection.style.display = 'block';
        } else {
            processBtn.disabled = true;
            fileCount.textContent = '尚未選擇檔案';
            uploadColumn.className = 'column is-fluid';
            fileListColumn.style.display = 'none';
            uploadSection.classList.remove('is-stretched');
            settingsSection.style.display = 'none';
        }
    }

    // 更新封面首項檔案名稱
    function updateCoverFirstFileName() {
        const coverFirstFileName = document.getElementById('coverFirstFileName');
        if (coverFirstFileName && uploadedFiles.length > 0) {
            coverFirstFileName.textContent = uploadedFiles[0].name;
        } else if (coverFirstFileName) {
            coverFirstFileName.textContent = '（無檔案）';
        }
    }

    // 定義紙張尺寸 (Points, 1 mm = 2.8346 points)
    const MM_TO_PT = 2.8346;
    const SIZES = {
        'A4': [595.28, 841.89],
        'A3': [841.89, 1190.55],
        'B4': [728.5, 1031.8] // JIS B4
    };

    // 處理 PDF 合併排版
    async function processPDF() {
        if (uploadedFiles.length === 0) {
            alert('請先上傳 PDF 檔案');
            return;
        }

        // 顯示處理中狀態
        const overlay = document.getElementById('processingOverlay');
        overlay.classList.add('active');

        try {
            // 取得設定值
            const pageSizeKey = document.getElementById('pageSize').value;
            const orientation = document.getElementById('orientation').value;
            const nUp = parseInt(document.getElementById('pagesPerSheet').value);
            const gutterMm = parseFloat(document.getElementById('gutter').value || 10);
            const gapMm = parseFloat(document.getElementById('gap').value || 5);
            const drawBorder = document.getElementById('drawBorder').checked;
            const pageNoPos = document.getElementById('pageNoPos').value;
            const startNo = parseInt(document.getElementById('startPageNo').value || 1);
            const pageNoOutline = document.getElementById('pageNoOutline').checked;
            const pageNoOutlineWidth = parseFloat(document.getElementById('pageNoOutlineWidth').value || 2);
            const pageNoStyle = document.getElementById('pageNoStyle')?.value || 'light';

            // 計算頁面尺寸
            let [pW, pH] = SIZES[pageSizeKey];
            if (orientation === 'landscape') {
                [pW, pH] = [pH, pW];
            }

            const gutter = gutterMm * MM_TO_PT;
            const gap = gapMm * MM_TO_PT;

            // 定義 N-up 網格 (Rows, Cols)
            let rows, cols;
            if (orientation === 'portrait') {
                if (nUp === 1) {
                    rows = 1;
                    cols = 1;
                } else if (nUp === 2) {
                    rows = 2;
                    cols = 1;
                } else if (nUp === 4) {
                    rows = 2;
                    cols = 2;
                } else if (nUp === 6) {
                    rows = 3;
                    cols = 2;
                }
            } else {
                if (nUp === 1) {
                    rows = 1;
                    cols = 1;
                } else if (nUp === 2) {
                    rows = 1;
                    cols = 2;
                } else if (nUp === 4) {
                    rows = 2;
                    cols = 2;
                } else if (nUp === 6) {
                    rows = 2;
                    cols = 3;
                }
            }

            // 建立新 PDF
            const pdfDoc = await PDFDocument.create();

            // 註冊 fontkit
            pdfDoc.registerFontkit(fontkit);

            // 載入中文字型（完整 TTF，先嘗試本地，若無再從 CDN 下載）
            let customFont, customFontBold;
            // 是否可以正確輸出 Unicode（非 WinAnsi）字元
            let canRenderUnicode = true;

            // 輔助：將非 WinAnsi 字元替換掉以避免 WinAnsi 編碼錯誤
            function ensureWinAnsi(text) {
                return String(text).replace(/[^\x00-\xFF]/g, '?');
            }

            // 輔助：量測混合文字寬度（ASCII 用 asciiFont、非 ASCII 用 cjkFont，逐字元累加）
            // 直接用 CJK 字型量測 ASCII 字元會得到錯誤寬度，導致換行失效
            function measureMixedWidth(text, cjkFont, asciiFont, size) {
                let w = 0;
                for (const char of String(text)) {
                    w += (char.codePointAt(0) < 128 ? asciiFont : cjkFont).widthOfTextAtSize(char, size);
                }
                return w;
            }

            // 輔助：自動換行（以混合字型寬度量測，避免 CJK 字型誤報 ASCII 寬度）
            function wrapMixed(text, cjkFont, asciiFont, size, maxWidth) {
                const lines = [];
                let current = '';
                let currentW = 0;
                for (const char of String(text)) {
                    const charW = (char.codePointAt(0) < 128 ? asciiFont : cjkFont).widthOfTextAtSize(char, size);
                    if (currentW + charW > maxWidth && current.length > 0) {
                        lines.push(current);
                        current = char;
                        currentW = charW;
                    } else {
                        current += char;
                        currentW += charW;
                    }
                }
                if (current) lines.push(current);
                return lines;
            }

            // 輔助：繪製混合文字，ASCII 用 asciiFont（確保 PDF 複製正確）、非 ASCII 用 cjkFont
            // 回傳繪製總寬度
            function drawMixedText(page, text, x, y, size, cjkFont, asciiFont, color) {
                let curX = x;
                let i = 0;
                while (i < text.length) {
                    const isAscii = text.codePointAt(i) < 128;
                    let run = '';
                    while (i < text.length) {
                        const cp = text.codePointAt(i);
                        if ((cp < 128) !== isAscii) break;
                        const ch = String.fromCodePoint(cp);
                        run += ch;
                        i += ch.length;
                    }
                    const f = isAscii ? asciiFont : cjkFont;
                    page.drawText(run, {
                        x: curX,
                        y,
                        size,
                        font: f,
                        color
                    });
                    curX += f.widthOfTextAtSize(run, size);
                }
                return curX - x;
            }

            // 輔助：嘗試本地字型，若本地不存在則從 CDN 下載
            async function loadFontBytes(localPath, cdnUrl) {
                let resp = await fetch(localPath);
                if (!resp.ok) {
                    console.log(`本地字型 ${localPath} 不存在，改從 CDN 下載...`);
                    resp = await fetch(cdnUrl);
                    if (!resp.ok) throw new Error(`無法載入字型: ${cdnUrl}`);
                }
                return resp.arrayBuffer();
            }

            try {
                // 嘗試讓伺服器安裝字型（靜默失敗不影響後續流程）
                fetch('<?= $appBasePath ?>/install_font.php').catch(() => {});

                // 使用靜態字重 TTF，避免 variable font 預設取最小字重（wght=100 超細）
                console.log('載入中文字型 (Noto Sans TC Regular + Bold)...');
                const [regularBytes, boldBytes] = await Promise.all([
                    loadFontBytes(
                        '<?= $appBasePath ?>/fonts/NotoSansTC-Regular.ttf',
                        'https://fonts.gstatic.com/s/notosanstc/v39/-nFuOG829Oofr2wohFbTp9ifNAn722rq0MXz76Cy_Co.ttf'
                    ),
                    loadFontBytes(
                        '<?= $appBasePath ?>/fonts/NotoSansTC-Bold.ttf',
                        'https://fonts.gstatic.com/s/notosanstc/v39/-nFuOG829Oofr2wohFbTp9ifNAn722rq0MXz70e1_Co.ttf'
                    ),
                ]);

                customFont = await pdfDoc.embedFont(regularBytes); // Regular (400)：內文、副標題
                customFontBold = await pdfDoc.embedFont(boldBytes); // Bold (700)：封面標題、目錄標題
                console.log('成功載入中文字型 (Noto Sans TC Regular + Bold)');
                canRenderUnicode = true;
            } catch (error) {
                console.warn('無法載入中文字型，退回 WinAnsi (不支援中文):', error);
                showNotice('warning', '中文字型載入失敗',
                    '伺服器 fonts/ 與 Google Fonts 都讀不到 Noto Sans TC，封面、目錄的中文字會被略過。' +
                    '請確認伺服器可連外並開啟 allow_url_fopen，或手動下載字型放到 fonts/（見 README）。');
                customFont = await pdfDoc.embedFont(StandardFonts.Helvetica);
                customFontBold = await pdfDoc.embedFont(StandardFonts.HelveticaBold);
                canRenderUnicode = false;
            }

            // 標準字型(用於數字和英文)
            const font = await pdfDoc.embedFont(StandardFonts.Helvetica);
            const fontBold = await pdfDoc.embedFont(StandardFonts.HelveticaBold);

            // 取得封面和目錄設定
            const enableCover = document.getElementById('enableCover')?.checked || false;
            const enableToc = document.getElementById('enableToc')?.checked || false;

            // 1. 封面頁
            let useFirstAsCover = false;
            if (enableCover) {
                const coverSource = document.getElementById('coverSource')?.value || 'template';
                if (coverSource === 'template') {
                    const coverPage = pdfDoc.addPage([pW, pH]);
                    const coverTitle = document.getElementById('coverTitle')?.value || '';
                    const coverSubtitle = document.getElementById('coverSubtitle')?.value || '';

                    // 封面是第1頁（奇數頁），裝訂邊在左，內容向右偏移
                    const coverGutterOffset = gutter + 10;

                    if (coverTitle) {
                        const titleSize = 48;
                        const titleText = canRenderUnicode ? coverTitle : ensureWinAnsi(coverTitle);
                        const titleFont = canRenderUnicode ? customFontBold : fontBold;
                        const titleWidth = titleFont.widthOfTextAtSize(titleText, titleSize);
                        coverPage.drawText(titleText, {
                            x: (pW - titleWidth) / 2 + coverGutterOffset / 2,
                            y: pH * 0.6,
                            size: titleSize,
                            font: titleFont,
                            color: rgb(0, 0, 0),
                        });
                    }

                    if (coverSubtitle) {
                        const subtitleSize = 28;
                        const subtitleText = canRenderUnicode ? coverSubtitle : ensureWinAnsi(coverSubtitle);
                        const subtitleFont = canRenderUnicode ? customFont : font;
                        const subtitleWidth = subtitleFont.widthOfTextAtSize(subtitleText, subtitleSize);
                        coverPage.drawText(subtitleText, {
                            x: (pW - subtitleWidth) / 2 + coverGutterOffset / 2,
                            y: pH * 0.5,
                            size: subtitleSize,
                            font: subtitleFont,
                            color: rgb(0.3, 0.3, 0.3),
                        });
                    }
                } else if (coverSource === 'first' && uploadedFiles.length > 0) {
                    // 使用第一個檔案的第一頁作為封面
                    useFirstAsCover = true;
                    const coverFile = uploadedFiles[0];
                    const arrayBuffer = await coverFile.arrayBuffer();
                    const srcDoc = await PDFDocument.load(arrayBuffer);
                    const [firstPage] = await pdfDoc.embedPages([srcDoc.getPages()[0]]);
                    const newPage = pdfDoc.addPage([pW, pH]);

                    // 計算縮放
                    const scale = Math.min(pW / firstPage.width, pH / firstPage.height);
                    const drawWidth = firstPage.width * scale;
                    const drawHeight = firstPage.height * scale;
                    const x = (pW - drawWidth) / 2;
                    const y = (pH - drawHeight) / 2;

                    newPage.drawPage(firstPage, {
                        x: x,
                        y: y,
                        width: drawWidth,
                        height: drawHeight,
                    });
                } else if (coverSource === 'upload' && coverImageFile) {
                    // 使用上傳的 PDF 作為封面
                    const arrayBuffer = await coverImageFile.arrayBuffer();
                    const srcDoc = await PDFDocument.load(arrayBuffer);
                    const [firstPage] = await pdfDoc.embedPages([srcDoc.getPages()[0]]);
                    const newPage = pdfDoc.addPage([pW, pH]);

                    // 計算縮放
                    const scale = Math.min(pW / firstPage.width, pH / firstPage.height);
                    const drawWidth = firstPage.width * scale;
                    const drawHeight = firstPage.height * scale;
                    const x = (pW - drawWidth) / 2;
                    const y = (pH - drawHeight) / 2;

                    newPage.drawPage(firstPage, {
                        x: x,
                        y: y,
                        width: drawWidth,
                        height: drawHeight,
                    });
                }
            }

            // 2. 目錄頁
            if (enableToc) {
                const tocPage = pdfDoc.addPage([pW, pH]);
                const tocTitle = '目錄';
                const tocTitleSize = 32;

                // 目錄頁數處理：
                // - 如果只有目錄（沒有封面），目錄是第1頁（奇數）
                // - 如果有封面，目錄是第2頁（偶數）
                const tocPageNumber = enableCover ? 2 : 1;
                const isTocOddPage = (tocPageNumber % 2 === 1);

                // 奇數頁：裝訂邊在左，內容向右；偶數頁：裝訂邊在右，內容在左
                const tocLeftMargin = isTocOddPage ? (gutter + 60) : 60;
                const tocRightMargin = isTocOddPage ? 60 : (gutter + 60);

                const tocTitleText = canRenderUnicode ? tocTitle : ensureWinAnsi(tocTitle);
                const tocTitleFont = canRenderUnicode ? customFontBold : fontBold;
                const tocTitleWidthReal = tocTitleFont.widthOfTextAtSize(tocTitleText, tocTitleSize);
                tocPage.drawText(tocTitleText, {
                    x: (pW - tocTitleWidthReal) / 2,
                    y: pH - 80,
                    size: tocTitleSize,
                    font: tocTitleFont,
                    color: rgb(0, 0, 0),
                });

                // 繪製目錄項目
                let yPos = pH - 140;
                const lineHeight = 55;

                // 檢查是否使用第一個檔案作為封面
                let startFileIndex = useFirstAsCover ? 1 : 0;

                const fileTitles = Array.from(document.querySelectorAll('.file-title')).map(input => input.value);
                const displayTitles = fileTitles.slice(startFileIndex);

                // 計算每個檔案的頁數，以得到實際合併後的頁碼
                const filePageCounts = [];
                for (let fileIndex = startFileIndex; fileIndex < uploadedFiles.length; fileIndex++) {
                    const file = uploadedFiles[fileIndex];
                    const arrayBuffer = await file.arrayBuffer();
                    const srcDoc = await PDFDocument.load(arrayBuffer);
                    filePageCounts.push(srcDoc.getPageCount());
                }

                let currentPageNum = startNo; // 從起始頁號開始
                displayTitles.forEach((title, index) => {
                    if (yPos < 100) return;

                    // 章節名稱（使用左邊界）
                    // 目錄項目使用 Regular（內文字重），「目錄」標題才用 Bold
                    const chapterTitleRaw = title || `章節 ${index + 1}`;
                    const chapterTitle = canRenderUnicode ? chapterTitleRaw : ensureWinAnsi(
                        chapterTitleRaw);
                    // entryFont 選 Regular；WinAnsi fallback 時用 Helvetica
                    const entryFont = canRenderUnicode ? customFont : font;

                    // 計算該檔案在合併後的起始頁碼
                    const filePages = filePageCounts[index] || 0;
                    const sheetsNeeded = Math.ceil(filePages / nUp);
                    const pageNum = currentPageNum.toString();
                    const pageNumWidth = font.widthOfTextAtSize(pageNum, 24);

                    // 計算標題可用寬度，以混合字型正確量測避免 CJK 字型誤報 ASCII 寬度
                    const minDotsGap = 30;
                    const maxTitleWidth = pW - tocRightMargin - tocLeftMargin - pageNumWidth - minDotsGap;
                    const titleLines = wrapMixed(chapterTitle, entryFont, font, 24, maxTitleWidth);
                    const intraLineHeight = 34; // 同一章節內換行間距

                    // 繪製所有行（ASCII 用 Helvetica 確保 PDF 複製正確，非 ASCII 用 CJK 字型）
                    titleLines.forEach((line, lineIdx) => {
                        const lineY = yPos - lineIdx * intraLineHeight;
                        drawMixedText(tocPage, line, tocLeftMargin, lineY, 24, entryFont, font, rgb(
                            0, 0, 0));
                    });

                    // 點線和頁碼對齊最後一行
                    const lastLineY = yPos - (titleLines.length - 1) * intraLineHeight;
                    const lastLineWidth = measureMixedWidth(titleLines[titleLines.length - 1], entryFont,
                        font, 24);

                    tocPage.drawText(pageNum, {
                        x: pW - tocRightMargin - pageNumWidth,
                        y: lastLineY,
                        size: 24,
                        font: font,
                        color: rgb(0, 0, 0),
                    });

                    currentPageNum += sheetsNeeded;

                    // 繪製點線
                    const dotStartX = tocLeftMargin + lastLineWidth + 10;
                    const dotEndX = pW - tocRightMargin - pageNumWidth - 10;
                    for (let x = dotStartX; x < dotEndX; x += 5) {
                        tocPage.drawText('.', {
                            x: x,
                            y: lastLineY,
                            size: 24,
                            font: font,
                            color: rgb(0.5, 0.5, 0.5),
                        });
                    }

                    // 下一個項目的 yPos：考慮換行行數
                    yPos -= lineHeight + (titleLines.length - 1) * intraLineHeight;
                });
            }

            // 計算每格尺寸
            const safeW = pW - gutter - 20;
            const safeH = pH - 40;
            const gridW = (safeW - (cols - 1) * gap) / cols;
            const gridH = (safeH - (rows - 1) * gap) / rows;

            // 3. 處理所有上傳的檔案（分檔案處理，避免跨頁混合）
            let coverPages = 0; // 封面和目錄的總頁數

            let startFileIndex = useFirstAsCover ? 1 : 0;

            if (enableCover) {
                coverPages++; // 封面佔一頁
            }

            if (enableToc) {
                coverPages++; // 目錄佔一頁
            }

            // 分別處理每個 PDF 檔案，確保不混合在同一頁
            let sheetCount = 0;

            for (let fileIndex = startFileIndex; fileIndex < uploadedFiles.length; fileIndex++) {
                const file = uploadedFiles[fileIndex];
                const arrayBuffer = await file.arrayBuffer();
                const srcDoc = await PDFDocument.load(arrayBuffer);
                const srcPages = await pdfDoc.embedPages(srcDoc.getPages());

                let currentSheet = null;
                let pageIndexOnSheet = 0;

                for (let i = 0; i < srcPages.length; i++) {
                    if (pageIndexOnSheet === 0) {
                        currentSheet = pdfDoc.addPage([pW, pH]);
                        sheetCount++;
                    }

                    // 計算網格位置
                    const c = pageIndexOnSheet % cols;
                    const r = Math.floor(pageIndexOnSheet / cols);

                    const srcPage = srcPages[i];
                    const scale = Math.min(gridW / srcPage.width, gridH / srcPage.height);
                    const drawWidth = srcPage.width * scale;
                    const drawHeight = srcPage.height * scale;

                    const isOddSheet = (sheetCount % 2 === 1);
                    let baseX = isOddSheet ? gutter + 10 : 10;

                    const x = baseX + c * (gridW + gap) + (gridW - drawWidth) / 2;
                    const topY = pH - 20;
                    const y = topY - (r * (gridH + gap)) - drawHeight - (gridH - drawHeight) / 2;

                    currentSheet.drawPage(srcPage, {
                        x: x,
                        y: y,
                        width: drawWidth,
                        height: drawHeight,
                    });

                    // 畫外框
                    if (drawBorder) {
                        currentSheet.drawRectangle({
                            x: x,
                            y: y,
                            width: drawWidth,
                            height: drawHeight,
                            borderColor: rgb(0, 0, 0),
                            borderWidth: 1,
                        });
                    }

                    pageIndexOnSheet++;

                    // 如果目前頁面已滿，重置計數器
                    if (pageIndexOnSheet >= nUp) {
                        pageIndexOnSheet = 0;
                    }
                }

                // 如果該檔案結束時沒填滿最後一頁，則下個檔案從新頁開始
                // （已經通過pageIndexOnSheet重置實現）
            }

            // 繪製頁碼（最後疊加，確保在所有內容之上）
            if (pageNoPos !== 'none') {
                const allPages = pdfDoc.getPages();
                for (let s = 0; s < sheetCount; s++) {
                    const page = allPages[coverPages + s];
                    const pageNumStr = (startNo + s).toString();
                    const textWidth = font.widthOfTextAtSize(pageNumStr, 12);
                    const isOddSheet = ((s + 1) % 2 === 1);
                    let textX;
                    if (pageNoPos === 'center') {
                        textX = (pW - textWidth) / 2;
                    } else {
                        // outside
                        textX = isOddSheet ? pW - textWidth - 20 : 20;
                    }
                    const textY = 15;
                    // 繪製膠囊（pill）背景並在上方繪製頁碼文字，文字水平垂直置中
                    const fontSize = 12;
                    const paddingH = pageNoOutline && pageNoOutlineWidth > 0 ? pageNoOutlineWidth : 6;
                    const paddingV = Math.max(4, Math.round(paddingH / 1.5));
                    const textW = font.widthOfTextAtSize(pageNumStr, fontSize);
                    const rectH = fontSize + paddingV * 2;
                    const minW = rectH; // 以高度作為最小寬度，單字時會成圓形
                    const rectW = Math.max(textW + paddingH * 2, minW);
                    const centerX = textX + textW / 2;
                    const centerY = textY + fontSize / 2;

                    // 顏色根據 style 決定
                    const darkStyle = (pageNoStyle === 'dark');
                    const bgColor = darkStyle ? rgb(0, 0, 0) : rgb(1, 1, 1);
                    const fgColor = darkStyle ? rgb(1, 1, 1) : rgb(0, 0, 0);

                    const halfH = rectH / 2;
                    const halfW = rectW / 2;

                    // 左右兩端圓心位置（形成膠囊）
                    const leftCenterX = centerX - (rectW - rectH) / 2;
                    const rightCenterX = centerX + (rectW - rectH) / 2;

                    // 繪製填滿的左半圓、右半圓與中間矩形
                    page.drawEllipse({
                        x: leftCenterX,
                        y: centerY,
                        xScale: halfH,
                        yScale: halfH,
                        color: bgColor
                    });
                    page.drawEllipse({
                        x: rightCenterX,
                        y: centerY,
                        xScale: halfH,
                        yScale: halfH,
                        color: bgColor
                    });
                    page.drawRectangle({
                        x: leftCenterX,
                        y: centerY - halfH,
                        width: rectW - rectH,
                        height: rectH,
                        color: bgColor
                    });

                    // 文字置中（PDF 座標 y 為文字基線，故以 fontSize/2 做近似置中）
                    const textDrawX = centerX - textW / 2;
                    const textDrawY = centerY - fontSize / 2 + Math.round(fontSize * 0.15);
                    page.drawText(pageNumStr, {
                        x: textDrawX,
                        y: textDrawY,
                        size: fontSize,
                        font,
                        color: fgColor
                    });
                }
            }

            // 輸出與預覽
            processedPdfBytes = await pdfDoc.save();
            const blob = new Blob([processedPdfBytes], {
                type: 'application/pdf'
            });
            const url = URL.createObjectURL(blob);
            document.getElementById('pdfPreview').src = url;

            // 顯示預覽區域和下載按鈕
            document.getElementById('previewSection').style.display = 'block';
            document.getElementById('downloadBtn').disabled = false;

            // 設定預設檔名（包含時間戳）
            const now = new Date();
            const timestamp = now.getFullYear() +
                String(now.getMonth() + 1).padStart(2, '0') +
                String(now.getDate()).padStart(2, '0') + '_' +
                String(now.getHours()).padStart(2, '0') +
                String(now.getMinutes()).padStart(2, '0') +
                String(now.getSeconds()).padStart(2, '0');
            const defaultFileName = `merged_${timestamp}.pdf`;
            document.getElementById('outputFileName').value = defaultFileName;

        } catch (err) {
            alert('處理發生錯誤：' + err.message);
            console.error(err);
        } finally {
            overlay.classList.remove('active');
        }
    }

    // 下載處理後的 PDF
    function downloadPDF() {
        if (processedPdfBytes) {
            let fileName = document.getElementById('outputFileName')?.value || 'processed_output.pdf';
            // 確保檔名有.pdf副檔名
            if (!fileName.toLowerCase().endsWith('.pdf')) {
                fileName += '.pdf';
            }
            download(processedPdfBytes, fileName, "application/pdf");
        } else {
            alert('請先處理 PDF 檔案！');
        }
    }

    // 拖動排序功能
    let draggedElement = null;

    function setupDragAndDrop(element) {
        element.addEventListener('dragstart', function(e) {
            draggedElement = this;
            this.style.opacity = '0.5';
        });

        element.addEventListener('dragend', function(e) {
            this.style.opacity = '';
            draggedElement = null;
        });

        element.addEventListener('dragover', function(e) {
            e.preventDefault();
            if (draggedElement && draggedElement !== this) {
                const sortableList = document.getElementById('sortableFileList');
                const allCards = [...sortableList.children];
                const draggedIndex = allCards.indexOf(draggedElement);
                const targetIndex = allCards.indexOf(this);

                if (draggedIndex < targetIndex) {
                    this.parentNode.insertBefore(draggedElement, this.nextSibling);
                } else {
                    this.parentNode.insertBefore(draggedElement, this);
                }

                // 更新 uploadedFiles 順序
                const draggedFile = uploadedFiles.splice(draggedIndex, 1)[0];
                uploadedFiles.splice(targetIndex, 0, draggedFile);
            }
        });
    }

    // 封面/目錄選項處理
    document.addEventListener('DOMContentLoaded', function() {
        // 封面啟用/停用
        document.getElementById('enableCover')?.addEventListener('change', function() {
            document.getElementById('coverSettings').style.display = this.checked ? 'block' : 'none';
        });

        // 目錄啟用/停用
        document.getElementById('enableToc')?.addEventListener('change', function() {
            document.getElementById('tocSettings').style.display = this.checked ? 'block' : 'none';
        });

        // 封面來源切換
        document.getElementById('coverSource')?.addEventListener('change', function() {
            const isTemplate = this.value === 'template';
            const isUpload = this.value === 'upload';
            const isFirst = this.value === 'first';
            document.getElementById('coverTemplateEditor').style.display = isTemplate ? 'block' :
                'none';
            document.getElementById('coverUploadArea').style.display = isUpload ? 'block' : 'none';
            document.getElementById('coverFirstFileArea').style.display = isFirst ? 'block' : 'none';
            if (isFirst) {
                updateCoverFirstFileName();
            }
        });

        // 目錄編輯按鈕
        document.getElementById('editTocBtn')?.addEventListener('click', function() {
            openTocEditor();
        });

        // 封面上傳處理
        document.getElementById('coverFileInput')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file && validateFile(file)) {
                coverImageFile = file;
                document.getElementById('coverFileName').textContent = file.name;
                document.getElementById('coverFileStatus').style.display = 'block';
            }
        });

        // 原有的拖放功能初始化
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('drag-over');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            handleFileSelect(e.dataTransfer.files);
        });

        fileInput.addEventListener('change', (e) => {
            handleFileSelect(e.target.files);
        });

        updateUI();
    });
    </script>
</head>

<body class="is-rounded">
    <div class="main-content">
        <div class="ts-container has-vertically-padded">

            <!-- 標題 -->
            <div class="ts-header is-heavy is-large is-start-icon">
                <span class="ts-icon is-file-pdf-icon"></span>
                HapBun 合本 <span
                    style="font-size:0.875rem;color:var(--ts-gray-500);font-weight:normal;margin-left:0.5rem;">v<?= htmlspecialchars($appVersion) ?></span>
            </div>
            <div class="ts-text is-secondary">
                PDF 合併排版工具，可設定多頁、增加封面與目錄、頁碼，方便列印簡報講義
            </div>

            <div id="appNotice" class="ts-notice has-hidden">
                <div class="title"></div>
                <div class="content"></div>
            </div>

            <div class="ts-divider has-vertically-spaced"></div>

            <!-- 檔案上傳與列表 -->
            <div id="uploadSection" class="ts-grid mobile:is-stacked is-relaxed">
                <div id="uploadColumn" class="column is-fluid">
                    <!-- 檔案上傳區 -->
                    <div class="ts-box" style="height: 100%;">
                        <div class="ts-content is-padded">
                            <div class="ts-header is-large is-start-icon">
                                <span class="ts-icon is-upload-icon"></span>
                                上傳 PDF 檔案
                            </div>

                            <div class="ts-box is-hollowed has-top-spaced">
                                <label class="ts-blankslate is-interactive" id="dropZone" for="fileInput"
                                    style="cursor: pointer;">
                                    <div class="header">上傳 PDF 檔案</div>
                                    <div class="description">點擊或拖曳至此，可一次上傳多個檔案（單檔上限 50MB）</div>
                                </label>
                            </div>
                            <input type="file" id="fileInput" multiple accept=".pdf" style="display: none;">

                            <div class="ts-text is-description has-top-spaced" id="fileCount">尚未選擇檔案</div>
                        </div>
                    </div>
                </div>

                <!-- 檔案列表區域（上傳後顯示） -->
                <div id="fileListColumn" class="column is-fluid" style="display: none;">
                    <div class="ts-box" style="height: 100%; display: flex; flex-direction: column;">
                        <div class="ts-content is-padded">
                            <div class="ts-header is-large is-start-icon">
                                <span class="ts-icon is-copy-icon"></span>
                                已上傳檔案
                            </div>
                            <div class="ts-text is-description">拖動卡片調整順序，編輯章節名稱</div>
                        </div>
                        <!-- 可拖動的檔案卡片列表 -->
                        <div id="sortableFileList" class="has-top-spaced"
                            style="flex: 1; overflow-x: auto; overflow-y: hidden; padding: 0 1rem 1rem 1rem; display: flex; gap: 1rem; align-items: flex-start;">
                            <!-- 動態生成的檔案卡片 -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- 排版設定 -->
            <div id="settingsSection" class="has-top-spaced" style="display: none;">
                <div id="settingsColumn">
                    <!-- 排版設定 -->
                    <div class="ts-box">
                        <div class="ts-content is-padded">
                            <div class="ts-header is-large is-start-icon">
                                <span class="ts-icon is-gear-icon"></span>
                                排版設定
                            </div>

                            <!-- 封面與目錄設定 -->
                            <div class="ts-wrap has-top-spaced">
                                <label class="ts-checkbox">
                                    <input type="checkbox" id="enableCover">
                                    <div class="text">啟用封面</div>
                                </label>
                                <label class="ts-checkbox">
                                    <input type="checkbox" id="enableToc">
                                    <div class="text">啟用目錄</div>
                                </label>
                            </div>

                            <!-- 封面設定區 -->
                            <div id="coverSettings" class="ts-box is-secondary has-top-spaced" style="display: none;">
                                <div class="ts-content is-compact">
                                    <div class="ts-text is-bold">封面設定</div>
                                    <div class="ts-grid has-top-spaced-small">
                                        <div class="column is-fluid">
                                            <label class="ts-text is-label">封面來源</label>
                                            <div class="ts-select is-fluid">
                                                <select id="coverSource">
                                                    <option value="template">預設模板</option>
                                                    <option value="upload">上傳 PDF</option>
                                                    <option value="first">選擇已上傳首項</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="coverTemplateEditor" class="has-top-spaced-small">
                                        <label class="ts-text is-label">標題</label>
                                        <div class="ts-input is-fluid">
                                            <input type="text" id="coverTitle" placeholder="請輸入標題">
                                        </div>
                                        <label class="ts-text is-label has-top-spaced-small">副標題</label>
                                        <div class="ts-input is-fluid">
                                            <input type="text" id="coverSubtitle" placeholder="請輸入副標題">
                                        </div>
                                    </div>
                                    <div id="coverUploadArea" class="has-top-spaced-small" style="display: none;">
                                        <input type="file" id="coverFileInput" accept=".pdf" style="display: none;">
                                        <button class="ts-button is-outlined is-fluid"
                                            onclick="document.getElementById('coverFileInput').click()">
                                            <span class="ts-icon is-upload-icon"></span>
                                            上傳封面 PDF
                                        </button>
                                        <div id="coverFileStatus" class="ts-text is-description has-top-spaced-small"
                                            style="display: none;">
                                            已選擇：<span id="coverFileName"></span>
                                        </div>
                                    </div>
                                    <div id="coverFirstFileArea" class="has-top-spaced-small" style="display: none;">
                                        <div class="ts-box is-secondary">
                                            <div class="ts-content is-compact">
                                                <div class="ts-text is-description">將使用以下檔案作為封面：</div>
                                                <div class="ts-text is-bold has-top-spaced-small"
                                                    id="coverFirstFileName">（無檔案）</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 目錄設定區 -->
                            <div id="tocSettings" class="ts-box is-secondary has-top-spaced" style="display: none;">
                                <div class="ts-content is-compact">
                                    <div class="ts-text is-bold">目錄設定</div>
                                    <div class="ts-text is-description has-top-spaced-small">目錄將自動根據檔案標題生成，也可以手動編輯</div>
                                    <button id="editTocBtn" class="ts-button is-outlined is-small has-top-spaced-small">
                                        <span class="ts-icon is-edit-icon"></span>
                                        編輯目錄
                                    </button>
                                </div>
                            </div>

                            <div class="ts-divider has-top-spaced"></div>

                            <div class="ts-grid has-top-spaced">
                                <!-- 輸出紙張 -->
                                <div class="column is-8-wide tablet+:is-6-wide">
                                    <label class="ts-text is-label">輸出紙張</label>
                                    <div class="ts-select is-fluid">
                                        <select id="pageSize">
                                            <option value="A4">A4</option>
                                            <option value="A3">A3</option>
                                            <option value="B4" selected>JIS B4</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- 方向 -->
                                <div class="column is-8-wide tablet+:is-6-wide">
                                    <label class="ts-text is-label">方向</label>
                                    <div class="ts-select is-fluid">
                                        <select id="orientation">
                                            <option value="landscape" selected>橫向</option>
                                            <option value="portrait">直向</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- 每頁顯示頁數 -->
                                <div class="column is-16-wide tablet+:is-6-wide">
                                    <label class="ts-text is-label">每頁版數 (N-up)</label>
                                    <div class="ts-select is-fluid">
                                        <select id="pagesPerSheet">
                                            <option value="1">1 頁 (不縮放)</option>
                                            <option value="2">2 頁 (2合1)</option>
                                            <option value="4" selected>4 頁 (4合1)</option>
                                            <option value="6">6 頁 (6合1)</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- 裝訂邊 -->
                                <div class="column is-8-wide tablet+:is-5-wide">
                                    <label class="ts-text is-label">裝訂邊 (mm)</label>
                                    <div class="ts-input is-fluid">
                                        <input type="number" id="gutter" value="10">
                                    </div>
                                </div>

                                <!-- 頁面間距 -->
                                <div class="column is-8-wide tablet+:is-5-wide">
                                    <label class="ts-text is-label">頁面間距 (mm)</label>
                                    <div class="ts-input is-fluid">
                                        <input type="number" id="gap" value="5">
                                    </div>
                                </div>
                            </div>

                            <!-- 繪製外框 -->
                            <label class="ts-checkbox has-top-spaced">
                                <input type="checkbox" id="drawBorder" checked>
                                <div class="text">繪製單頁外框</div>
                            </label>

                            <!-- 頁碼設定 -->
                            <div class="ts-grid has-top-spaced">
                                <div class="column is-8-wide tablet+:is-8-wide">
                                    <label class="ts-text is-label">頁碼位置</label>
                                    <div class="ts-select is-fluid">
                                        <select id="pageNoPos">
                                            <option value="none">不顯示</option>
                                            <option value="center">置中</option>
                                            <option value="outside" selected>靠外側（雙面）</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="column is-8-wide tablet+:is-8-wide">
                                    <label class="ts-text is-label">起始頁號</label>
                                    <div class="ts-input is-fluid">
                                        <input type="number" id="startPageNo" value="1">
                                    </div>
                                </div>
                            </div>

                            <!-- 頁碼底色設定 -->
                            <label class="ts-checkbox has-top-spaced">
                                <input type="checkbox" id="pageNoOutline" checked
                                    onchange="document.getElementById('pageNoOutlineRow').style.display=this.checked?'':'none'">
                                <div class="text">顯示頁碼底色</div>
                            </label>
                            <div id="pageNoOutlineRow" class="ts-grid has-top-spaced-small">
                                <div class="column is-8-wide tablet+:is-8-wide">
                                    <label class="ts-text is-label">頁碼內邊距 (pt)</label>
                                    <div class="ts-input is-fluid">
                                        <input type="number" id="pageNoOutlineWidth" value="2" min="0.5" max="8"
                                            step="0.5">
                                    </div>
                                </div>
                                <div class="column is-8-wide tablet+:is-8-wide">
                                    <label class="ts-text is-label">頁碼底色風格</label>
                                    <div class="ts-select is-fluid">
                                        <select id="pageNoStyle">
                                            <option value="dark">深色底</option>
                                            <option value="light" selected>淺色底</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <!-- 操作按鈕 -->
            <div class="has-top-spaced">
                <button id="processBtn" class="ts-button is-primary is-large is-fluid" onclick="processPDF()" disabled>
                    <span class="ts-icon is-magic-icon"></span>
                    開始轉換並預覽
                </button>
            </div>

            <!-- 預覽區域 -->
            <div id="previewSection" class="has-top-spaced" style="display: none;">
                <div class="ts-box">
                    <div class="ts-content is-padded">
                        <div class="ts-grid is-middle-aligned">
                            <div class="column">
                                <div class="ts-header is-large is-start-icon">
                                    <span class="ts-icon is-eye-icon"></span>
                                    預覽
                                </div>
                            </div>
                            <div class="column is-fluid">
                                <div class="ts-input is-underlined is-fluid">
                                    <input type="text" id="outputFileName" value="processed_output.pdf"
                                        placeholder="輸出檔案名稱">
                                </div>
                            </div>
                            <div class="column">
                                <button id="downloadBtn" class="ts-button is-positive" onclick="downloadPDF()" disabled>
                                    <span class="ts-icon is-download-icon"></span>
                                    下載
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="ts-divider"></div>
                    <div class="ts-content" style="padding: 0;">
                        <div
                            style="position: relative; width: 100%; padding-bottom: 62.5%; background: var(--ts-gray-100); border-radius: 8px; overflow: hidden;">
                            <iframe id="pdfPreview"
                                style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none;"></iframe>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 使用說明 -->
            <div class="ts-box has-top-spaced">
                <div class="ts-content is-padded">
                    <div class="ts-header is-start-icon">
                        <span class="ts-icon is-circle-info-icon"></span>
                        使用說明
                    </div>
                    <div class="ts-list is-ordered has-top-spaced">
                        <div class="item">上傳一個或多個 PDF 檔案，可拖曳上傳</div>
                        <div class="item">選擇輸出紙張大小（A4、A3、B4）和方向</div>
                        <div class="item">選擇每頁顯示版數</div>
                        <div class="item">調整裝訂邊和頁面間距</div>
                        <div class="item">設定頁碼位置和起始頁號</div>
                        <div class="item">點擊「開始轉換並預覽」查看結果</div>
                        <div class="item">確認無誤後點擊「下載處理後的檔案」</div>
                    </div>
                    <div class="ts-box is-secondary has-top-spaced">
                        <div class="ts-content is-compact">
                            <div class="ts-text is-bold">
                                <span class="ts-icon is-lightbulb-icon"></span>
                                實用提示
                            </div>
                            <div class="ts-list is-unordered has-top-spaced-small">
                                <div class="item">選擇多頁模式適合列印講義，可節省紙張</div>
                                <div class="item">「靠外側」頁碼位置適合雙面列印裝訂</div>
                                <div class="item">裝訂邊可預留裝訂空間</div>
                                <div class="item">所有處理都在瀏覽器中完成，不會上傳到伺服器</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>



    </div>

    <!-- 處理中遮罩 -->
    <div id="processingOverlay" class="processing-overlay">
        <div class="ts-box is-raised" style="min-width: 300px;">
            <div class="ts-content is-center-aligned is-padded">
                <div class="ts-loading is-notched"></div>
                <div class="ts-text is-bold has-top-spaced">處理中...</div>
                <div class="ts-text is-description has-top-spaced-small">請稍候，正在轉換您的 PDF 檔案</div>
            </div>
        </div>
    </div>

    <!-- 目錄編輯器模態視窗 -->
    <dialog id="tocEditorDialog" class="ts-modal">
        <div class="content">
            <div class="ts-content">
                <div class="ts-header">編輯目錄</div>
                <div class="ts-text is-description has-top-spaced-small">設定章節名稱和起始頁碼</div>
            </div>
            <div class="ts-divider"></div>
            <div class="ts-content">
                <div id="tocEditorList" class="ts-list is-divided">
                    <!-- 動態生成的目錄項目 -->
                </div>
            </div>
            <div class="ts-divider"></div>
            <div class="ts-content">
                <div class="ts-wrap is-end-aligned">
                    <button class="ts-button" onclick="closeTocEditor()">取消</button>
                    <button class="ts-button is-primary" onclick="saveTocEditor()">儲存</button>
                </div>
            </div>
        </div>
    </dialog>

    <script>
    // 目錄編輯器功能
    async function openTocEditor() {
        const dialog = document.getElementById('tocEditorDialog');
        const list = document.getElementById('tocEditorList');
        list.innerHTML = '';

        // 取得 N-up 設定
        const nUp = parseInt(document.getElementById('pagesPerSheet').value);
        const enableCover = document.getElementById('enableCover')?.checked || false;
        const enableToc = document.getElementById('enableToc')?.checked || false;

        // 計算封面和目錄佔用的頁數
        let currentPage = 1;
        if (enableCover) currentPage++;
        if (enableToc) currentPage++;

        // 讀取每個檔案的實際頁數並建立列表
        for (let index = 0; index < uploadedFiles.length; index++) {
            const file = uploadedFiles[index];
            const title = document.querySelectorAll('.file-title')[index]?.value || file.name.replace('.pdf', '');

            // 讀取 PDF 頁數
            let pageCount = 0;
            try {
                const arrayBuffer = await file.arrayBuffer();
                const srcDoc = await PDFDocument.load(arrayBuffer);
                pageCount = srcDoc.getPageCount();
            } catch (err) {
                console.error('無法讀取 PDF 頁數:', err);
                pageCount = 1; // 預設值
            }

            const item = document.createElement('div');
            item.className = 'item';
            item.draggable = true;
            item.dataset.filename = file.name;
            item.innerHTML = `
                    <div class="ts-content">
                        <div class="ts-grid is-middle-aligned">
                            <div class="column">
                                <span class="ts-icon is-grip-vertical-icon" style="cursor: move;"></span>
                            </div>
                            <div class="column is-fluid">
                                <div class="ts-input is-underlined">
                                    <input type="text" data-index="${index}" class="toc-title-input">
                                </div>
                            </div>
                            <div class="column">
                                <div class="ts-text is-description">第 <span id="page-${index}">${currentPage}</span> 頁</div>
                            </div>
                        </div>
                    </div>
                `;
            // title 是使用者可編輯的章節標題（可能源自檔名），以 value 屬性賦值避免 DOM XSS
            item.querySelector('.toc-title-input').value = title;

            // 拖動事件
            item.addEventListener('dragstart', (e) => {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/html', item.innerHTML);
                item.classList.add('dragging');
            });

            item.addEventListener('dragend', () => {
                item.classList.remove('dragging');
            });

            item.addEventListener('dragover', (e) => {
                e.preventDefault();
                const draggingItem = list.querySelector('.dragging');
                if (draggingItem && draggingItem !== item) {
                    const rect = item.getBoundingClientRect();
                    const midpoint = rect.top + rect.height / 2;
                    if (e.clientY < midpoint) {
                        list.insertBefore(draggingItem, item);
                    } else {
                        list.insertBefore(draggingItem, item.nextSibling);
                    }
                }
            });

            list.appendChild(item);

            // 計算下一個檔案的起始頁（目前頁 + 該檔案需要的頁數）
            const sheetsNeeded = Math.ceil(pageCount / nUp);
            currentPage += sheetsNeeded;
        }

        dialog.showModal();
    }

    function closeTocEditor() {
        document.getElementById('tocEditorDialog').close();
    }

    function saveTocEditor() {
        // 取得排序後的檔案順序
        const items = document.querySelectorAll('#tocEditorList .item');
        const newOrder = [];
        const newTitles = [];

        items.forEach((item, newIndex) => {
            const filename = item.dataset.filename;
            const titleInput = item.querySelector('.toc-title-input');
            const fileIndex = uploadedFiles.findIndex(f => f.name === filename);

            if (fileIndex !== -1) {
                newOrder.push(uploadedFiles[fileIndex]);
                newTitles.push(titleInput.value);
            }
        });

        // 更新檔案順序
        uploadedFiles = newOrder;

        // 更新檔案列表UI並同步標題
        updateFileList();

        // 同步標題到卡片
        setTimeout(() => {
            const titleInputs = document.querySelectorAll('.file-title');
            titleInputs.forEach((input, index) => {
                if (newTitles[index] !== undefined) {
                    input.value = newTitles[index];
                }
            });
        }, 100);

        closeTocEditor();
    }
    </script>

    <!-- 授權內容讀根目錄 LICENSE，由下方 inline script 第一次開啟時載入，每章一個分頁。 -->
    <dialog id="license-dialog" class="ts-modal is-large" aria-labelledby="license-dialog-title"
        data-license-src="<?= htmlspecialchars($appBasePath) ?>/LICENSE">
        <div class="content help-dialog-content">
            <div class="ts-content">
                <div class="ts-header is-start-icon" id="license-dialog-title">
                    <span class="ts-icon is-copyright-icon" aria-hidden="true"></span>
                    授權
                </div>
            </div>
            <div class="ts-tab is-dense is-segmented help-tabs" role="tablist"></div>
            <div class="ts-content help-body">
                <div class="ts-text is-description">載入中…</div>
            </div>
            <div class="ts-divider"></div>
            <div class="ts-content">
                <div class="ts-wrap is-end-aligned">
                    <button type="button" class="ts-button" id="btn-license-close">關閉</button>
                </div>
            </div>
        </div>
    </dialog>

    <!-- 開利手底部 -->
    <div id="app-footer" class="ts-content is-secondary is-vertically-padded">
        <div class="ts-container">
            <div class="ts-grid mobile:is-stacked">
                <div class="column is-fluid">
                    <div class="ts-text is-description">
                        <a href="/koilisu/">KoiLiSu 開利手</a> -
                        讓工具使用更順手的開放專案 | prjToka
                    </div>
                    <div class="ts-text is-description">
                        Built with ❤️ using Tocas UI |
                        <a href="https://github.com/zisunny104/hapbun" target="_blank" rel="noopener noreferrer"
                            class="ts-badge">
                            <span class="ts-icon is-github-icon" aria-hidden="true"></span>
                            View on GitHub<span class="sr-only"> (在新視窗開啟)</span>
                        </a>
                        <button type="button" id="btn-license" class="ts-badge">
                            <span class="ts-icon is-copyright-icon" aria-hidden="true"></span>
                            License
                        </button>
                    </div>
                </div>
                <div class="column is-end-aligned">
                    <div class="ts-selection is-circular is-compact" role="radiogroup" aria-label="佈景主題切換">
                        <label class="item">
                            <input type="radio" name="theme" value="light" id="theme-light">
                            <div class="text">淺色</div>
                        </label>
                        <label class="item">
                            <input checked type="radio" name="theme" value="system" id="theme-system">
                            <div class="text">系統</div>
                        </label>
                        <label class="item">
                            <input type="radio" name="theme" value="dark" id="theme-dark">
                            <div class="text">深色</div>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // 「授權」彈窗：內文讀根目錄 LICENSE（Markdown，以 ## 分章），第一次開啟時載入並轉成 HTML，
    // 每章一個分頁。載入失敗只顯示簡短錯誤，不留空白。
    // hapbun 是單檔 view.php（無模組化檔案結構），這裡用 IIFE 自足、不用 ES module，
    // 整段 port 自 printan 的 js/help/markdown.js 與 js/help/license-dialog.js。
    (function () {
        // ---- Markdown 轉換（只支援 LICENSE 用到的語法）----
        // 安全：先把整段文字跳脫（& < > " '）再套標記，來源裡的 HTML 一律當純文字；
        // 連結只允許 http(s):// 與相對路徑（含 # 錨點），其餘（javascript:、data: …）不轉成連結。
        var ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        function escapeHtml(s) {
            return s.replace(/[&<>"']/g, function (c) { return ESCAPES[c]; });
        }
        var ENTITY_CHARS = { quot: '"', '#39': "'", amp: '&' };
        var HOLD = '\u0000'; // 暫存已轉好的 HTML 用的占位符；輸入裡的 NUL 會先移除，來源文字無法偽造

        function safeHref(url) {
            if (/^https?:\/\/\S+$/i.test(url)) return url;
            if (/^[\w./#?=&%-]+$/.test(url) && url.indexOf('//') !== 0 && !/^[a-z][a-z0-9+.-]*:/i.test(url)) return url;
            return null;
        }

        // 行內標記。輸入是原始文字，輸出已跳脫的 HTML。
        function renderInline(raw) {
            var held = [];
            function hold(html) { return HOLD + (held.push(html) - 1) + HOLD; }
            var s = escapeHtml(raw.split(HOLD).join(''));
            s = s.replace(/`([^`]{1,500})`/g, function (_, code) { return hold('<kbd>' + code + '</kbd>'); });
            s = s.replace(/\[\[([^\]]{1,50})\]\]/g, function (_, key) { return hold('<kbd>' + key + '</kbd>'); });
            s = s.replace(/\[([^\]]{1,300})\]\(([^)\s]{1,500})\)/g, function (_, label, url) {
                var href = safeHref(url.replace(/&(quot|#39|amp);/g, function (_m, e) { return ENTITY_CHARS[e]; }));
                if (!href) return label;
                var external = /^https?:/i.test(href) ? ' target="_blank" rel="noopener noreferrer"' : '';
                return hold('<a href="' + escapeHtml(href) + '"' + external + '>' + label + '</a>');
            });
            s = s.replace(/\*\*([^*]{1,500})\*\*/g, '<strong>$1</strong>');
            s = s.replace(/\*([^*]{1,500})\*/g, '<em>$1</em>');
            return s.replace(new RegExp(HOLD + '(\\d+)' + HOLD, 'g'), function (_, i) { return held[Number(i)]; });
        }

        function splitRow(line) {
            return line.trim().replace(/^\||\|$/g, '').split('|').map(function (c) { return c.trim(); });
        }
        var isTableSep = function (line) { return /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(line); };
        var BULLET = /^\s*[-*]\s+/;
        var NUMBERED = /^\s*\d+[.)]\s+/;

        // 區塊層級：回傳 HTML 字串。表格用 Tocas 原生 .ts-table、清單用 .ts-list，
        // 一般段落不特別加 class（沿用對話框內文字基礎樣式）。
        function renderMarkdown(src) {
            var lines = src.replace(/\r\n?/g, '\n').split('\n');
            var out = [];
            var i = 0;
            while (i < lines.length) {
                var line = lines[i];
                if (!line.trim()) { i++; continue; }

                var heading = /^#{3,4}\s+(.*)$/.exec(line);
                if (heading) {
                    out.push('<div class="ts-header is-small">' + renderInline(heading[1]) + '</div>');
                    i++;
                } else if (/^---+\s*$/.test(line)) {
                    out.push('<div class="ts-divider"></div>');
                    i++;
                } else if (line.indexOf('|') !== -1 && isTableSep(lines[i + 1] || '')) {
                    var head = splitRow(line);
                    i += 2;
                    var rows = [];
                    while (i < lines.length && lines[i].trim() && lines[i].indexOf('|') !== -1) rows.push(splitRow(lines[i++]));
                    var th = head.map(function (c) { return '<th>' + renderInline(c) + '</th>'; }).join('');
                    var tr = rows.map(function (r) {
                        return '<tr>' + head.map(function (_, k) { return '<td>' + renderInline(r[k] || '') + '</td>'; }).join('') + '</tr>';
                    }).join('');
                    out.push('<table class="ts-table is-celled"><thead><tr>' + th + '</tr></thead><tbody>' + tr + '</tbody></table>');
                } else if (BULLET.test(line) || NUMBERED.test(line)) {
                    var marker = BULLET.test(line) ? BULLET : NUMBERED;
                    var tag = marker === BULLET ? 'ul' : 'ol';
                    var items = [];
                    while (i < lines.length && marker.test(lines[i])) items.push(lines[i++].replace(marker, ''));
                    out.push('<' + tag + ' class="ts-list">' + items.map(function (t) { return '<li>' + renderInline(t) + '</li>'; }).join('') + '</' + tag + '>');
                } else if (/^>\s?/.test(line)) {
                    var quote = [];
                    while (i < lines.length && /^>\s?/.test(lines[i])) quote.push(lines[i++].replace(/^>\s?/, ''));
                    out.push('<blockquote class="help-quote ts-text is-description is-italic">' + renderInline(quote.join(' ')) + '</blockquote>');
                } else {
                    var para = [lines[i++]];
                    while (i < lines.length && lines[i].trim() && !/^(#{3,4}\s|---+\s*$|>\s?)/.test(lines[i]) && !BULLET.test(lines[i]) && !NUMBERED.test(lines[i])) para.push(lines[i++]);
                    out.push('<p>' + renderInline(para.join(' ')) + '</p>');
                }
            }
            return out.join('');
        }

        // 以 `## 章名` 切成章節：[{ title, body(原始 Markdown) }]；第一個 ## 之前的文字忽略。
        function splitChapters(src) {
            var chapters = [];
            src.replace(/\r\n?/g, '\n').split('\n').forEach(function (line) {
                var m = /^##\s+(.+?)\s*$/.exec(line);
                if (m) {
                    chapters.push({ title: m[1], body: '' });
                } else if (chapters.length) {
                    chapters[chapters.length - 1].body += line + '\n';
                }
            });
            return chapters;
        }

        // ---- 「授權」dialog 開關、分頁、lazy fetch ----
        function wireLicenseDialog() {
            var dialog = document.getElementById('license-dialog');
            var openButton = document.getElementById('btn-license');
            if (!dialog || !openButton) return;
            var tabsBox = dialog.querySelector('.help-tabs');
            var body = dialog.querySelector('.help-body');
            var src = dialog.dataset.licenseSrc;
            var loaded = false;
            var loading = null;

            function showError() {
                tabsBox.hidden = true;
                if (dialog.open) { var closeBtn = document.getElementById('btn-license-close'); if (closeBtn) closeBtn.focus(); }
                body.textContent = '';
                var notice = document.createElement('div');
                notice.className = 'ts-notice is-negative';
                var content = document.createElement('div');
                content.className = 'content';
                content.textContent = '授權資訊載入失敗，請關閉後再開一次。';
                notice.appendChild(content);
                body.appendChild(notice);
            }

            function select(name, opts) {
                opts = opts || {};
                Array.prototype.forEach.call(tabsBox.children, function (tab) {
                    var active = tab.dataset.licenseTab === name;
                    tab.classList.toggle('is-active', active);
                    tab.setAttribute('aria-selected', String(active));
                    tab.tabIndex = active ? 0 : -1; // roving tabindex：Tab 只停在目前分頁，方向鍵切換
                    if (active && opts.focus) tab.focus();
                });
                Array.prototype.forEach.call(body.children, function (panel) {
                    panel.hidden = panel.dataset.licensePanel !== name;
                });
                body.scrollTop = 0;
            }

            tabsBox.addEventListener('keydown', function (e) {
                var tabs = Array.prototype.slice.call(tabsBox.children);
                var current = tabs.indexOf(document.activeElement);
                if (current < 0) return;
                var map = { ArrowRight: current + 1, ArrowLeft: current - 1, Home: 0, End: tabs.length - 1 };
                var next = map[e.key];
                if (next === undefined) return;
                e.preventDefault();
                select(String(((next % tabs.length) + tabs.length) % tabs.length), { focus: true });
            });

            function build(chapters) {
                tabsBox.hidden = false;
                tabsBox.textContent = '';
                body.textContent = '';
                chapters.forEach(function (chapter, index) {
                    var name = String(index);
                    var tab = document.createElement('button');
                    tab.type = 'button';
                    tab.className = 'item';
                    tab.id = 'license-tab-' + name;
                    tab.setAttribute('role', 'tab');
                    tab.setAttribute('aria-controls', 'license-panel-' + name);
                    tab.dataset.licenseTab = name;
                    tab.textContent = chapter.title;
                    tab.addEventListener('click', function () { select(name); });
                    tabsBox.appendChild(tab);
                    var panel = document.createElement('div');
                    panel.id = 'license-panel-' + name;
                    panel.setAttribute('role', 'tabpanel');
                    panel.setAttribute('aria-labelledby', 'license-tab-' + name);
                    panel.dataset.licensePanel = name;
                    panel.innerHTML = renderMarkdown(chapter.body); // 已先跳脫再套標記
                    body.appendChild(panel);
                });
                select('0');
                loaded = true;
            }

            function load() {
                if (loaded || loading) return;
                loading = fetch(src)
                    .then(function (res) {
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        return res.text();
                    })
                    .then(function (text) {
                        var chapters = splitChapters(text);
                        if (!chapters.length) throw new Error('沒有章節');
                        build(chapters);
                        if (dialog.open) {
                            var activeTab = tabsBox.querySelector('[tabindex="0"]');
                            if (activeTab) activeTab.focus();
                        }
                    })
                    .catch(function (err) {
                        console.error('授權資訊載入失敗', err);
                        showError();
                    })
                    .then(function () { loading = null; }, function () { loading = null; });
            }

            // 開啟時焦點放在目前分頁（還在載入或失敗時放在關閉鈕）；Esc 由 <dialog> 原生處理，
            // 關閉後（Esc 或關閉鈕）焦點明確還給開啟鈕。
            openButton.addEventListener('click', function () {
                dialog.showModal();
                load();
                var target = tabsBox.querySelector('[tabindex="0"]') || document.getElementById('btn-license-close');
                if (target) target.focus();
            });
            dialog.addEventListener('close', function () { openButton.focus(); });
            var closeBtn = document.getElementById('btn-license-close');
            if (closeBtn) closeBtn.addEventListener('click', function () { dialog.close(); });
        }

        document.addEventListener('DOMContentLoaded', wireLicenseDialog);
    })();
    </script>

    <script>
    // 深淺色模式功能
    function setTheme(theme) {
        document.body.className = theme === 'system' ?
            'is-rounded' :
            `is-rounded is-${theme}`;

        // Save theme preference to cookie
        document.cookie = `preferred-theme=${theme}; path=/; max-age=31536000`; // 1 year
    }

    function getPreferredTheme() {
        const cookies = document.cookie.split(';');
        for (let cookie of cookies) {
            const [name, value] = cookie.trim().split('=');
            if (name === 'preferred-theme') {
                return value;
            }
        }
        return 'system'; // Default theme
    }

    // 初始化主題
    document.addEventListener('DOMContentLoaded', function() {
        const preferredTheme = getPreferredTheme();
        const themeRadio = document.getElementById(`theme-${preferredTheme}`);
        if (themeRadio) {
            themeRadio.checked = true;
            setTheme(preferredTheme);
        }
    });

    // Theme change event listeners
    document.getElementById('theme-light').addEventListener('change', function() {
        if (this.checked) {
            setTheme('light');
        }
    });

    document.getElementById('theme-dark').addEventListener('change', function() {
        if (this.checked) {
            setTheme('dark');
        }
    });

    document.getElementById('theme-system').addEventListener('change', function() {
        if (this.checked) {
            setTheme('system');
        }
    });
    </script>
</body>

</html>