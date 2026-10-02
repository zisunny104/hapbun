#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

# 有顏色的終端機才上色，避免 log 檔案裡混進一堆 ANSI 逃脫碼
if [ -t 1 ]; then
  BOLD=$'\033[1m'; DIM=$'\033[2m'
  RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; CYAN=$'\033[36m'
  RESET=$'\033[0m'
else
  BOLD=''; DIM=''; RED=''; GREEN=''; YELLOW=''; CYAN=''; RESET=''
fi

step() { echo "${BOLD}${CYAN}==>${RESET} ${BOLD}$1${RESET}"; }
ok()   { echo "  ${GREEN}✓${RESET} $1"; }
warn() { echo "  ${YELLOW}!${RESET} $1"; }
fail() { echo "  ${RED}✗${RESET} $1"; }

BRANCH="${DEPLOY_BRANCH:-main}"

# hapbun 是 PHP 頁面殼＋瀏覽器端 JS，唯一的伺服器端依賴是 fonts/ 裡的 Noto Sans TC 字型快取
# （不進版本控制），更新完會檢查，缺了就引導安裝。

step "檢查 working tree"
# 伺服器上的檔案被手動改過時，git fast-forward merge 會中途失敗；先擋下來，講清楚是哪些檔案。
DIRTY="$(git status --porcelain --untracked-files=no)"
if [ -n "$DIRTY" ]; then
  fail "有尚未 commit 的修改，部署已中止（怕蓋掉伺服器上的手動修改）："
  sed 's/^/    /' <<< "$DIRTY"
  echo "  ${DIM}確認不需要之後，用 git checkout -- <檔案> 還原，再重新執行 ./deploy.sh${RESET}"
  exit 1
fi
ok "沒有未 commit 的修改"

HAS_PHP=0
if command -v php >/dev/null 2>&1; then
  HAS_PHP=1
  ok "PHP CLI：$(php -r 'echo PHP_VERSION;')"
else
  warn "找不到 php 指令，會略過語法檢查：有語法錯誤的 PHP 檔不會被擋在部署之前（網頁的 PHP-FPM 不受影響）"
fi

echo
step "Fetch 最新程式碼"
BEFORE=$(git rev-parse --short HEAD)
git fetch --quiet origin "$BRANCH"
AFTER=$(git rev-parse --short FETCH_HEAD)
ok "remote ${BRANCH}：${AFTER}"

if [ "$(git rev-parse HEAD)" != "$(git rev-parse FETCH_HEAD)" ] && ! git merge-base --is-ancestor HEAD FETCH_HEAD; then
  # local 有 remote 沒有的 commit（或兩邊 diverge）：fast-forward 做不到，硬 merge 會在伺服器上產生 merge commit，都不是預期的部署結果
  fail "local（${BEFORE}）不是 remote（${AFTER}）的 ancestor，無法 fast-forward，部署已中止"
  echo "  ${DIM}伺服器上不該有 remote 沒有的 commit；請確認後再處理（例如 git log ${AFTER}..HEAD 看多出什麼）${RESET}"
  exit 1
fi

if [ "$BEFORE" = "$AFTER" ]; then
  echo
  warn "已經是最新版本（${AFTER}），沒有新的變更"
else
  echo
  step "部署前先檢查新增／修改的 PHP 語法"
  # 在 merge「之前」就檢查：直接用 git show 把 remote 版本餵給 php -l，有錯就中止，
  # 線上的檔案完全沒動。merge 之後才發現，網站已經是壞的了。
  if [ "$HAS_PHP" -eq 1 ]; then
    BAD=()
    COUNT=0
    while IFS= read -r file; do
      [ -n "$file" ] || continue
      COUNT=$((COUNT + 1))
      if ! git show "FETCH_HEAD:${file}" | php -l >/dev/null 2>&1; then
        BAD+=("$file")
      fi
    done < <(git diff --name-only --diff-filter=AM HEAD FETCH_HEAD -- '*.php')
    if [ ${#BAD[@]} -gt 0 ]; then
      for file in "${BAD[@]}"; do
        fail "$file（語法錯誤）"
        git show "FETCH_HEAD:${file}" | php -l 2>&1 | sed -n '1p' | sed 's/^/      /' || true
      done
      echo
      fail "${#BAD[@]} 個 PHP 檔有語法錯誤，部署已中止，線上檔案沒有變動"
      exit 1
    fi
    ok "檢查了 ${COUNT} 個 PHP 檔，語法都正確"
  else
    warn "略過（沒有 php 指令）"
  fi

  echo
  step "更新程式碼"
  git merge --ff-only --quiet FETCH_HEAD
  ok "已更新：${DIM}${BEFORE}${RESET} → ${GREEN}${BOLD}${AFTER}${RESET}"
  echo "  ${DIM}此次更新的變更：${RESET}"
  git log --oneline "${BEFORE}..${AFTER}" | sed 's/^/    /'
fi

# 選用：PHP 開了 opcache 且不檢查檔案時間戳（validate_timestamps=0）的伺服器，
# 換了檔案要重載 PHP-FPM 才會生效；用環境變數帶進來，例如
#   DEPLOY_RELOAD_CMD="systemctl reload php8.3-fpm" ./deploy.sh
if [ -n "${DEPLOY_RELOAD_CMD:-}" ] && [ "$BEFORE" != "$AFTER" ]; then
  echo
  step "重載 PHP"
  if bash -c "$DEPLOY_RELOAD_CMD"; then
    ok "${DEPLOY_RELOAD_CMD}"
  else
    fail "重載失敗：${DEPLOY_RELOAD_CMD}（程式碼已更新，請手動重載 PHP-FPM）"
    exit 1
  fi
fi

echo
step "檢查依賴：Noto Sans TC 字型（fonts/）"
FONTS=(NotoSansTC-Regular.ttf NotoSansTC-Bold.ttf)
missing_fonts() {
  local f expected actual
  for f in "${FONTS[@]}"; do
    if [ "$f" = "NotoSansTC-Regular.ttf" ]; then
      expected=619662a0583f38311e92666927e5edbfd30f2a1fbe8593685660bd11bdd46a10
    else
      expected=33e8464f3432fd9eba5fa6ff74f5fb9ee612cad703877bd71c36e6f167c0a7e3
    fi
    actual="$(sha256sum "fonts/$f" 2>/dev/null | awk '{print $1}' || true)"
    [ "$actual" = "$expected" ] || echo "$f（缺少或摘要不符）"
  done
  cmp -s fonts/OFL.txt licenses/NotoSansTC-OFL.txt || echo 'OFL.txt（缺少或內容不符）' 
}
MISSING="$(missing_fonts)"
if [ -z "$MISSING" ]; then
  ok "字型已就緒：${FONTS[*]}"
else
  warn "缺少字型：$(echo $MISSING)"
  echo "  ${DIM}沒有字型時，瀏覽器會改從 Google Fonts 直接下載；兩邊都失敗則 PDF 封面／目錄的中文會被略過（頁面會顯示警告）${RESET}"
  if [ "$HAS_PHP" -eq 1 ]; then
    if [ "$(php -r 'echo ini_get("allow_url_fopen") ? 1 : 0;')" != "1" ]; then
      warn "PHP 的 allow_url_fopen 未開啟，CLI install_font.php 無法下載字型"
    fi
    if [ "$(php -r 'echo extension_loaded("openssl") ? 1 : 0;')" != "1" ]; then
      warn "PHP 未載入 openssl 擴充套件，無法下載 https 網址（php.ini 開啟 extension=openssl）"
    fi
  fi
  if [ -e fonts ] && [ ! -w fonts ]; then
    warn "fonts/ 目前的使用者無法寫入；安裝時需要部署者可寫入，PHP-FPM 只需讀取"
  fi

  ANSWER=n
  if [ -t 0 ]; then
    read -r -p "  現在下載字型嗎？[Y/n] " ANSWER || ANSWER=n
    ANSWER="${ANSWER:-y}"
  fi
  if [[ "$ANSWER" =~ ^[Yy] ]]; then
    if [ "$HAS_PHP" -eq 1 ]; then
      # 安裝程式僅允許 CLI
      echo "  ${DIM}$(php install_font.php 2>&1 || true)${RESET}"
    fi
    # 安裝與完整性檢查由 CLI 工具處理。
    MISSING="$(missing_fonts)"
    if [ -z "$MISSING" ]; then
      ok "字型已安裝到 fonts/"
    else
      fail "仍缺少：$(echo $MISSING)（部署本身已完成，字型可稍後補裝）"
    fi
  else
    echo "  之後可用下列任一方式安裝："
    echo "    ${BOLD}php install_font.php${RESET}             ${DIM}# 在此目錄執行${RESET}"
    echo "    ${BOLD}php install_font.php --force${RESET}     ${DIM}# 需要重新下載时${RESET}"
  fi
  if [ -d fonts ] && [ "$(id -u)" = "0" ]; then
    warn "以 root 執行，下載的 fonts/ 屬於 root；請保留部署者寫入權限，PHP-FPM 只需讀取"
  fi
fi

echo
step "部署完成"
if [ "$HAS_PHP" -eq 1 ]; then
  VERSION="$(php -r '$c = require "config.php"; echo $c["version"] ?? "?";' 2>/dev/null || echo '?')"
  echo "  應用版本：${BOLD}v${VERSION}${RESET}"
fi
echo "  目前 commit：${BOLD}$(git rev-parse --short HEAD)${RESET}"
echo "  完成時間：${DIM}$(date '+%Y-%m-%d %H:%M:%S')${RESET}"
