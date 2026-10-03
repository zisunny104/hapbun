// HapBun PDF 合併 Worker
// 把 pdf-lib 的合併／頁碼／目錄繪製搬出主執行緒，避免多檔案或大檔案時 UI 卡死。
// pdf-lib／fontkit 的 UMD bundle 用 `this || self` 偵測全域物件，純 JS 操作 ArrayBuffer、
// 不碰 DOM，importScripts 載入同一組 CDN URL 即可在 Worker context 正常運作。
importScripts(
    'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js',
    'https://unpkg.com/@pdf-lib/fontkit@1.1.1/dist/fontkit.umd.min.js'
);

const { PDFDocument, rgb, StandardFonts } = self.PDFLib;
const fontkit = self.fontkit;

const MM_TO_PT = 2.8346;
const SIZES = {
    'A4': [595.28, 841.89],
    'A3': [841.89, 1190.55],
    'B4': [728.5, 1031.8] // JIS B4
};

// 將非 WinAnsi 字元替換掉以避免 WinAnsi 編碼錯誤
function ensureWinAnsi(text) {
    return String(text).replace(/[^\x00-\xFF]/g, '?');
}

// 量測混合文字寬度（ASCII 用 asciiFont、非 ASCII 用 cjkFont，逐字元累加）
// 直接用 CJK 字型量測 ASCII 字元會得到錯誤寬度，導致換行失效
function measureMixedWidth(text, cjkFont, asciiFont, size) {
    let w = 0;
    for (const char of String(text)) {
        w += (char.codePointAt(0) < 128 ? asciiFont : cjkFont).widthOfTextAtSize(char, size);
    }
    return w;
}

// 自動換行（以混合字型寬度量測，避免 CJK 字型誤報 ASCII 寬度）
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

// 繪製混合文字，ASCII 用 asciiFont（確保 PDF 複製正確）、非 ASCII 用 cjkFont，回傳繪製總寬度
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
        page.drawText(run, { x: curX, y, size, font: f, color });
        curX += f.widthOfTextAtSize(run, size);
    }
    return curX - x;
}

// 檢查是不是字型檔（TrueType／OpenType 標頭），避免伺服器把缺少的檔案導向 200 的 HTML 頁面
function looksLikeFont(buf) {
    if (buf.byteLength < 4) return false;
    const tag = String.fromCharCode(...new Uint8Array(buf, 0, 4));
    return tag === '\x00\x01\x00\x00' || tag === 'true' || tag === 'OTTO';
}

// 先試伺服器本地字型，讀不到或內容不是字型時改從 CDN 下載；Worker 也有 fetch，絕對路徑不受 worker 腳本位置影響
async function loadFontBytes(localPath, cdnUrl) {
    try {
        const resp = await fetch(localPath);
        if (resp.ok) {
            const buf = await resp.arrayBuffer();
            if (looksLikeFont(buf)) return buf;
        }
    } catch (e) {
        // 本地讀取失敗，改用 CDN
    }
    const resp = await fetch(cdnUrl);
    if (!resp.ok) throw new Error(`無法載入字型: ${cdnUrl}`);
    return resp.arrayBuffer();
}

async function mergePdfs(settings, files, coverFile) {
    const {
        pageSizeKey, orientation, nUp, gutterMm, gapMm, drawBorder,
        pageNoPos, startNo, pageNoOutline, pageNoOutlineWidth, pageNoStyle,
        enableCover, enableToc, coverSource, coverTitle, coverSubtitle,
        fontBase
    } = settings;

    let [pW, pH] = SIZES[pageSizeKey];
    if (orientation === 'landscape') {
        [pW, pH] = [pH, pW];
    }

    const gutter = gutterMm * MM_TO_PT;
    const gap = gapMm * MM_TO_PT;

    // 定義 N-up 網格 (Rows, Cols)
    let rows, cols;
    if (orientation === 'portrait') {
        if (nUp === 1) { rows = 1; cols = 1; }
        else if (nUp === 2) { rows = 2; cols = 1; }
        else if (nUp === 4) { rows = 2; cols = 2; }
        else if (nUp === 6) { rows = 3; cols = 2; }
    } else {
        if (nUp === 1) { rows = 1; cols = 1; }
        else if (nUp === 2) { rows = 1; cols = 2; }
        else if (nUp === 4) { rows = 2; cols = 2; }
        else if (nUp === 6) { rows = 2; cols = 3; }
    }

    const pdfDoc = await PDFDocument.create();
    pdfDoc.registerFontkit(fontkit);

    let customFont, customFontBold;
    let canRenderUnicode = true;
    let fontWarning = null;

    try {
        const [regularBytes, boldBytes] = await Promise.all([
            loadFontBytes(
                `${fontBase}/fonts/NotoSansTC-Regular.ttf`,
                'https://fonts.gstatic.com/s/notosanstc/v39/-nFuOG829Oofr2wohFbTp9ifNAn722rq0MXz76Cy_Co.ttf'
            ),
            loadFontBytes(
                `${fontBase}/fonts/NotoSansTC-Bold.ttf`,
                'https://fonts.gstatic.com/s/notosanstc/v39/-nFuOG829Oofr2wohFbTp9ifNAn722rq0MXz70e1_Co.ttf'
            ),
        ]);

        customFont = await pdfDoc.embedFont(regularBytes); // Regular (400)：內文、副標題
        customFontBold = await pdfDoc.embedFont(boldBytes); // Bold (700)：封面標題、目錄標題
        canRenderUnicode = true;
    } catch (error) {
        fontWarning = '伺服器 fonts/ 與 Google Fonts 都讀不到 Noto Sans TC，封面、目錄的中文字會被略過。' +
            '請確認瀏覽器可連 Google Fonts，或請部署者用 CLI 安裝字型（見 README）。';
        customFont = await pdfDoc.embedFont(StandardFonts.Helvetica);
        customFontBold = await pdfDoc.embedFont(StandardFonts.HelveticaBold);
        canRenderUnicode = false;
    }

    // 標準字型(用於數字和英文)
    const font = await pdfDoc.embedFont(StandardFonts.Helvetica);
    const fontBold = await pdfDoc.embedFont(StandardFonts.HelveticaBold);

    // 快取已解析的來源 PDF：目錄頁計算頁數與合併階段會讀取同一批檔案，
    // 避免每個檔案被 PDFDocument.load() 各解析一次（大檔案時明顯拖慢處理速度）
    const srcDocCache = new Map();
    async function getSrcDoc(fileIndex) {
        if (!srcDocCache.has(fileIndex)) {
            srcDocCache.set(fileIndex, await PDFDocument.load(files[fileIndex].buffer));
        }
        return srcDocCache.get(fileIndex);
    }

    // 1. 封面頁
    let useFirstAsCover = false;
    if (enableCover) {
        if (coverSource === 'template') {
            const coverPage = pdfDoc.addPage([pW, pH]);

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
        } else if (coverSource === 'first' && files.length > 0) {
            // 使用第一個檔案的第一頁作為封面
            useFirstAsCover = true;
            const srcDoc = await getSrcDoc(0);
            const [firstPage] = await pdfDoc.embedPages([srcDoc.getPages()[0]]);
            const newPage = pdfDoc.addPage([pW, pH]);

            const scale = Math.min(pW / firstPage.width, pH / firstPage.height);
            const drawWidth = firstPage.width * scale;
            const drawHeight = firstPage.height * scale;
            const x = (pW - drawWidth) / 2;
            const y = (pH - drawHeight) / 2;

            newPage.drawPage(firstPage, { x, y, width: drawWidth, height: drawHeight });
        } else if (coverSource === 'upload' && coverFile) {
            // 使用上傳的 PDF 作為封面
            const srcDoc = await PDFDocument.load(coverFile.buffer);
            const [firstPage] = await pdfDoc.embedPages([srcDoc.getPages()[0]]);
            const newPage = pdfDoc.addPage([pW, pH]);

            const scale = Math.min(pW / firstPage.width, pH / firstPage.height);
            const drawWidth = firstPage.width * scale;
            const drawHeight = firstPage.height * scale;
            const x = (pW - drawWidth) / 2;
            const y = (pH - drawHeight) / 2;

            newPage.drawPage(firstPage, { x, y, width: drawWidth, height: drawHeight });
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

        let yPos = pH - 140;
        const lineHeight = 55;

        let startFileIndex = useFirstAsCover ? 1 : 0;

        const fileTitles = files.map(f => f.title);
        const displayTitles = fileTitles.slice(startFileIndex);

        // 計算每個檔案的頁數，以得到實際合併後的頁碼
        const filePageCounts = [];
        for (let fileIndex = startFileIndex; fileIndex < files.length; fileIndex++) {
            const srcDoc = await getSrcDoc(fileIndex);
            filePageCounts.push(srcDoc.getPageCount());
        }

        let currentPageNum = startNo;
        displayTitles.forEach((title, index) => {
            if (yPos < 100) return;

            // 目錄項目使用 Regular（內文字重），「目錄」標題才用 Bold
            const chapterTitleRaw = title || `章節 ${index + 1}`;
            const chapterTitle = canRenderUnicode ? chapterTitleRaw : ensureWinAnsi(chapterTitleRaw);
            const entryFont = canRenderUnicode ? customFont : font;

            const filePages = filePageCounts[index] || 0;
            const sheetsNeeded = Math.ceil(filePages / nUp);
            const pageNum = currentPageNum.toString();
            const pageNumWidth = font.widthOfTextAtSize(pageNum, 24);

            const minDotsGap = 30;
            const maxTitleWidth = pW - tocRightMargin - tocLeftMargin - pageNumWidth - minDotsGap;
            const titleLines = wrapMixed(chapterTitle, entryFont, font, 24, maxTitleWidth);
            const intraLineHeight = 34; // 同一章節內換行間距

            titleLines.forEach((line, lineIdx) => {
                const lineY = yPos - lineIdx * intraLineHeight;
                drawMixedText(tocPage, line, tocLeftMargin, lineY, 24, entryFont, font, rgb(0, 0, 0));
            });

            const lastLineY = yPos - (titleLines.length - 1) * intraLineHeight;
            const lastLineWidth = measureMixedWidth(titleLines[titleLines.length - 1], entryFont, font, 24);

            tocPage.drawText(pageNum, {
                x: pW - tocRightMargin - pageNumWidth,
                y: lastLineY,
                size: 24,
                font: font,
                color: rgb(0, 0, 0),
            });

            currentPageNum += sheetsNeeded;

            const dotStartX = tocLeftMargin + lastLineWidth + 10;
            const dotEndX = pW - tocRightMargin - pageNumWidth - 10;
            for (let x = dotStartX; x < dotEndX; x += 5) {
                tocPage.drawText('.', { x, y: lastLineY, size: 24, font: font, color: rgb(0.5, 0.5, 0.5) });
            }

            yPos -= lineHeight + (titleLines.length - 1) * intraLineHeight;
        });
    }

    const safeW = pW - gutter - 20;
    const safeH = pH - 40;
    const gridW = (safeW - (cols - 1) * gap) / cols;
    const gridH = (safeH - (rows - 1) * gap) / rows;

    // 3. 處理所有上傳的檔案（分檔案處理，避免跨頁混合）
    let coverPages = 0; // 封面和目錄的總頁數
    let startFileIndex = useFirstAsCover ? 1 : 0;

    if (enableCover) coverPages++;
    if (enableToc) coverPages++;

    let sheetCount = 0;

    for (let fileIndex = startFileIndex; fileIndex < files.length; fileIndex++) {
        const srcDoc = await getSrcDoc(fileIndex);
        const srcPages = await pdfDoc.embedPages(srcDoc.getPages());

        let currentSheet = null;
        let pageIndexOnSheet = 0;

        for (let i = 0; i < srcPages.length; i++) {
            if (pageIndexOnSheet === 0) {
                currentSheet = pdfDoc.addPage([pW, pH]);
                sheetCount++;
            }

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

            currentSheet.drawPage(srcPage, { x, y, width: drawWidth, height: drawHeight });

            if (drawBorder) {
                currentSheet.drawRectangle({
                    x, y, width: drawWidth, height: drawHeight,
                    borderColor: rgb(0, 0, 0),
                    borderWidth: 1,
                });
            }

            pageIndexOnSheet++;
            if (pageIndexOnSheet >= nUp) {
                pageIndexOnSheet = 0;
            }
        }
        // 檔案結束時沒填滿最後一頁，下個檔案從新頁開始（已透過 pageIndexOnSheet 重置處理）
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
                textX = isOddSheet ? pW - textWidth - 20 : 20;
            }
            const textY = 15;
            const fontSize = 12;
            const paddingH = pageNoOutline && pageNoOutlineWidth > 0 ? pageNoOutlineWidth : 6;
            const paddingV = Math.max(4, Math.round(paddingH / 1.5));
            const textW = font.widthOfTextAtSize(pageNumStr, fontSize);
            const rectH = fontSize + paddingV * 2;
            const minW = rectH; // 以高度作為最小寬度，單字時會成圓形
            const rectW = Math.max(textW + paddingH * 2, minW);
            const centerX = textX + textW / 2;
            const centerY = textY + fontSize / 2;

            const darkStyle = (pageNoStyle === 'dark');
            const bgColor = darkStyle ? rgb(0, 0, 0) : rgb(1, 1, 1);
            const fgColor = darkStyle ? rgb(1, 1, 1) : rgb(0, 0, 0);

            const halfH = rectH / 2;
            const leftCenterX = centerX - (rectW - rectH) / 2;
            const rightCenterX = centerX + (rectW - rectH) / 2;

            // 繪製填滿的左半圓、右半圓與中間矩形，組成膠囊（pill）背景
            page.drawEllipse({ x: leftCenterX, y: centerY, xScale: halfH, yScale: halfH, color: bgColor });
            page.drawEllipse({ x: rightCenterX, y: centerY, xScale: halfH, yScale: halfH, color: bgColor });
            page.drawRectangle({
                x: leftCenterX, y: centerY - halfH,
                width: rectW - rectH, height: rectH,
                color: bgColor
            });

            // 文字置中（PDF 座標 y 為文字基線，故以 fontSize/2 做近似置中）
            const textDrawX = centerX - textW / 2;
            const textDrawY = centerY - fontSize / 2 + Math.round(fontSize * 0.15);
            page.drawText(pageNumStr, { x: textDrawX, y: textDrawY, size: fontSize, font, color: fgColor });
        }
    }

    const pdfBytes = await pdfDoc.save();
    return { pdfBytes, canRenderUnicode, fontWarning };
}

self.onmessage = async function (e) {
    const { settings, files, coverFile } = e.data;
    try {
        const { pdfBytes, canRenderUnicode, fontWarning } = await mergePdfs(settings, files, coverFile);
        self.postMessage({ success: true, pdfBytes, canRenderUnicode, fontWarning }, [pdfBytes.buffer]);
    } catch (err) {
        self.postMessage({ success: false, error: err && err.message ? err.message : String(err) });
    }
};
