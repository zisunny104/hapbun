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

# ── 網站自我檢查（部署後執行；也可單獨跑：./deploy.sh --check-only）──────────────────
# 這個專案在伺服器上是 git pull 原地更新：Nginx 沒擋 .git/ 的話，
# 任何人都能下載 $DEPLOY_CHECK_URL/.git/HEAD 乃至整份原始碼與歷史紀錄。
# 用環境變數指定網站對外網址，例：DEPLOY_CHECK_URL=https://example.com/project ./deploy.sh
CRIT=0

# 向 $1 發 GET（不跟隨轉址、8 秒逾時），輸出：
#   exposed＝200 且內容以 ref: 開頭（真的是 git HEAD）；unreach＝連不上；其他為 HTTP 狀態碼
probe_git_head() {
  local tmp code
  tmp="$(mktemp)"
  code="$(curl -sS --max-time 8 --max-redirs 0 -o "$tmp" -w '%{http_code}' "$1" 2>/dev/null)" || code="000"
  if [ "$code" = "000" ]; then echo "unreach"
  elif [ "$code" = "200" ] && head -c 4 "$tmp" 2>/dev/null | grep -q '^ref:'; then echo "exposed"
  else echo "$code"; fi
  rm -f "$tmp"
}

NGINX_SNIPPET='    location ~ /\.git { deny all; return 404; }'

selfcheck_web() {
  local base url r
  if [ -z "${DEPLOY_CHECK_URL:-}" ]; then
    warn "略過「.git/ 可否被網頁下載」檢查：未設定檢查網址，可用 DEPLOY_CHECK_URL=https://example.com/project ./deploy.sh"
    return 0
  fi
  if ! command -v curl >/dev/null 2>&1; then
    warn "略過「.git/ 可否被網頁下載」檢查：找不到 curl"
    return 0
  fi
  base="${DEPLOY_CHECK_URL%/}"
  case "$base" in
    https://*|http://*) ;;
    *) warn "檢查網址要以 https:// 或 http:// 開頭：$base"; return 0 ;;
  esac
  url="$base/.git/HEAD"
  r="$(probe_git_head "$url")"
  case "$r" in
    exposed)
      CRIT=1
      fail "${BOLD}${RED}嚴重：.git/ 可被網頁直接下載${RESET}（$url 回 200 並送出 git 內容）"
      echo "  ${BOLD}${RED}!!! 整份原始碼與歷史紀錄都能被任何人下載 !!!${RESET}"
      echo "  ${BOLD}修法：${RESET}把下面這條貼進 Nginx 的 server { } 區塊（與 listen／root 同一層），再執行 sudo nginx -t && sudo systemctl reload nginx："
      echo "${YELLOW}${NGINX_SNIPPET}${RESET}"
      echo "  ${DIM}完成後重跑 ./deploy.sh --check-only 確認；歷史紀錄裡若曾有機密，請一併更換${RESET}" ;;
    unreach)
      warn "連不上 $url（逾時或網路不通），略過這一項" ;;
    3??)
      warn "$url 回 $r 轉址，腳本不跟隨；請把檢查網址改成最終網址（例如直接用 https://）再測" ;;
    *)
      ok ".git/ 無法被網頁下載（回 $r）" ;;
  esac
}

run_selfcheck() {
  step "網站自我檢查"
  selfcheck_web
}

CHECK_ONLY=0
for arg in "$@"; do
  case "$arg" in
    --check-only) CHECK_ONLY=1 ;;
    -h|--help)
      echo "用法：./deploy.sh [--check-only]"
      echo "  --check-only  不更新程式碼，只跑「網站自我檢查」"
      echo "環境變數：DEPLOY_BRANCH、DEPLOY_RELOAD_CMD、DEPLOY_INSTALL_FONTS、DEPLOY_CHECK_URL（網站對外網址，例：https://example.com/project）"
      exit 0 ;;
    *) fail "未知參數：$arg"; exit 2 ;;
  esac
done

if [ "$CHECK_ONLY" -eq 1 ]; then
  run_selfcheck
  [ "$CRIT" -eq 0 ]
  exit $?
fi

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
# 狀態由 install_font.php --check 判斷（摘要與清單只維護一份）；沒有 php 時只能看檔案是否存在。
# 每行格式：狀態<TAB>檔名，狀態為 ok／missing／mismatch
if [ "$HAS_PHP" -eq 1 ]; then
  STATUS="$(php install_font.php --check 2>/dev/null || true)"
else
  STATUS=""
  for f in NotoSansTC-Regular.ttf NotoSansTC-Bold.ttf OFL.txt; do
    if [ -s "fonts/$f" ]; then STATUS+=$'ok\t'"$f"$'\n'; else STATUS+=$'missing\t'"$f"$'\n'; fi
  done
fi
MISSING="$(awk -F'\t' '$1=="missing"{printf "%s ", $2}' <<< "$STATUS")"
MISMATCH="$(awk -F'\t' '$1=="mismatch"{printf "%s ", $2}' <<< "$STATUS")"
[ -n "$STATUS" ] || MISSING="（無法執行 install_font.php --check） "
if [ -z "$MISSING" ] && [ -z "$MISMATCH" ]; then
  ok "字型已就緒：NotoSansTC-Regular.ttf NotoSansTC-Bold.ttf（摘要與授權檔皆相符）"
else
  [ -z "$MISSING" ] || warn "缺少：${MISSING}"
  [ -z "$MISMATCH" ] || warn "與預期版本不同（可能是手動放的舊版或已損壞）：${MISMATCH}"
  [ -z "$MISSING" ] || echo "  ${DIM}缺少字型時，瀏覽器會改從 Google Fonts 下載；兩邊都失敗則 PDF 封面／目錄的中文會被略過（頁面會顯示警告）${RESET}"
  [ -z "$MISMATCH" ] || echo "  ${DIM}摘要不同的字型檔仍會被瀏覽器使用，只要確實是 Noto Sans TC 就能正常運作；想換成官方版本請執行下載${RESET}"
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

  # 互動終端機會詢問；非互動環境（被其他腳本或 CI 呼叫）預設不下載，
  # 要自動下載請設 DEPLOY_INSTALL_FONTS=1
  ANSWER=n
  if [ "${DEPLOY_INSTALL_FONTS:-}" = "1" ]; then
    ANSWER=y
  elif [ -t 0 ] && [ "${DEPLOY_INSTALL_FONTS:-}" != "0" ]; then
    read -r -p "  現在下載字型嗎？[Y/n] " ANSWER || ANSWER=n
    ANSWER="${ANSWER:-y}"
  elif [ ! -t 0 ]; then
    echo "  ${DIM}非互動環境，略過下載（需要自動下載請設 DEPLOY_INSTALL_FONTS=1）${RESET}"
  fi
  if [[ "$ANSWER" =~ ^[Yy] ]] && [ "$HAS_PHP" -eq 1 ]; then
    php install_font.php 2>&1 | sed 's/^/    /' || true
    if [ "$(php install_font.php --check >/dev/null 2>&1; echo $?)" = "0" ]; then
      ok "字型已安裝到 fonts/"
    else
      fail "字型尚未就緒（部署本身已完成，字型可稍後補裝；上方訊息說明原因）"
    fi
  elif [[ "$ANSWER" =~ ^[Yy] ]]; then
    fail "沒有 php 指令，無法下載字型；請在有 PHP CLI 的環境執行 php install_font.php"
  else
    echo "  之後可在此目錄執行："
    echo "    ${BOLD}php install_font.php${RESET}             ${DIM}# 下載缺少或摘要不符的字型${RESET}"
    echo "    ${BOLD}php install_font.php --force${RESET}     ${DIM}# 已是正確版本也重新下載${RESET}"
  fi
  if [ -d fonts ] && [ "$(id -u)" = "0" ]; then
    warn "以 root 執行，下載的 fonts/ 屬於 root；請保留部署者寫入權限，PHP-FPM 只需讀取"
  fi
fi

echo
run_selfcheck
echo
step "部署完成"
if [ "$HAS_PHP" -eq 1 ]; then
  VERSION="$(php -r '$c = require "config.php"; echo $c["version"] ?? "?";' 2>/dev/null || echo '?')"
  echo "  工具版本：${BOLD}v${VERSION}${RESET}"
fi
echo "  目前 commit：${BOLD}$(git rev-parse --short HEAD)${RESET}"
echo "  完成時間：${DIM}$(date '+%Y-%m-%d %H:%M:%S')${RESET}"
[ "$CRIT" -eq 0 ] || { echo; fail "自我檢查有嚴重問題（見上方 ✗），請先處理"; exit 1; }
