#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

# 與其他開利手專案共用部署輸出慣例。
source tools/deploy-output.sh

# ── 網站檢查：部署後執行，也可單獨跑 ./deploy.sh --check-only ──────────────────
# 伺服器是 git pull 原地更新，Nginx 沒擋 .git/ 就能被下載整份原始碼與歷史。
# 檢查網址優先序：環境變數 DEPLOY_CHECK_URL，其次 .deploy_check_url，後者用 --set-check-url 寫入。
CRIT=0
CHECK_URL_FILE=".deploy_check_url"
EXAMPLE_URL="https://example.com/project"

# 向 $1 發 GET，不跟隨轉址，8 秒逾時。
# 輸出 exposed 代表 200 且內容以 ref: 開頭，unreach 代表連不上，其他輸出 HTTP 狀態碼。
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
export NGINX_SNIPPET

configure_nginx_git() {
  local site="$1" temporary previous count
  [ "$(id -u)" = 0 ] || { fail "需要 root／sudo"; return 1; }
  [ -n "$site" ] || { fail "用法：./deploy.sh --configure-nginx <Nginx 網站設定檔>"; return 1; }
  site="$(realpath "$site" 2>/dev/null)" || { fail "找不到檔案：$1"; return 1; }
  [ -f "$site" ] || { fail "找不到檔案：$site"; return 1; }
  if grep -q 'BEGIN KoiLiSu managed' "$site"; then
    ok "已有 KoiLiSu 管理的區塊，略過  ${DIM}${site}${RESET}"
    return 0
  fi
  count="$(grep -cE '^[[:space:]]*server[[:space:]]*\{[[:space:]]*$' "$site" || true)"
  [ "$count" = 1 ] || { fail "須有唯一的 server { 區塊（找到 ${count} 個），未變更"; return 1; }
  temporary="$(mktemp "$(dirname "$site")/.koilisu.XXXXXX")"
  previous="$(mktemp)"
  cp -p "$site" "$previous"
  [ -e "$site.koilisu-backup" ] || cp -p "$site" "$site.koilisu-backup"
  awk '
    { print }
    !done && $0 ~ /^[[:space:]]*server[[:space:]]*\{[[:space:]]*$/ { print "    # BEGIN KoiLiSu managed"; print ENVIRON["NGINX_SNIPPET"]; print "    # END KoiLiSu managed"; done=1 }
  ' "$site" > "$temporary"
  chmod --reference="$site" "$temporary"
  chown --reference="$site" "$temporary" 2>/dev/null || true
  if cmp -s "$temporary" "$site"; then rm -f "$temporary"; else mv -f "$temporary" "$site"; fi
  if ! nginx -t; then
    cp -p "$previous" "$site"
    rm -f "$previous"
    fail "Nginx 設定檢查失敗，已還原，未重載"
    return 1
  fi
  rm -f "$previous"
  systemctl reload nginx
  ok "已寫入 .git 封鎖規則並重載 Nginx"
}

# 讀檢查網址：環境變數優先，其次檔案；都沒有就輸出空字串
read_check_url() {
  local u="${DEPLOY_CHECK_URL:-}"
  if [ -z "$u" ] && [ -f "$CHECK_URL_FILE" ]; then
    u="$(head -n 1 "$CHECK_URL_FILE" | tr -d '\r')"
  fi
  printf '%s' "$u"
}

set_check_url() {
  local u="${1:-}"
  case "$u" in
    https://?*|http://?*) ;;
    *) fail "檢查網址要以 http:// 或 https:// 開頭"
       echo "  ${DIM}例如 ./deploy.sh --set-check-url ${EXAMPLE_URL}${RESET}"
       return 1 ;;
  esac
  while [ "${u%/}" != "$u" ]; do u="${u%/}"; done
  printf '%s\n' "$u" > "$CHECK_URL_FILE"
  ok "已儲存  ${DIM}${u}${RESET}"
  echo "  ${DIM}之後 ./deploy.sh 會自動使用${RESET}"
}

selfcheck_web() {
  local base url r
  base="$(read_check_url)"
  if [ -z "$base" ]; then
    warn "未設定網址，略過網站檢查"
    echo "    ${DIM}只需設一次：./deploy.sh --set-check-url ${EXAMPLE_URL}${RESET}"
    return 0
  fi
  if ! command -v curl >/dev/null 2>&1; then
    warn "缺少 curl，略過網站檢查"
    install_hint curl
    return 0
  fi
  while [ "${base%/}" != "$base" ]; do base="${base%/}"; done
  case "$base" in
    https://?*|http://?*) ;;
    *) warn "檢查網址要以 http:// 或 https:// 開頭，略過外洩檢查"
       echo "    ${DIM}${base}${RESET}"
       return 0 ;;
  esac
  url="$base/.git/HEAD"
  r="$(probe_git_head "$url")"
  case "$r" in
    exposed)
      CRIT=1
      fail "${BOLD}${RED}.git/ 可被下載${RESET}  ${DIM}回 200，${url}${RESET}"
      echo "  ${BOLD}${RED}整份原始碼與提交歷史都能被任何人取得${RESET}"
      echo "  ${BOLD}修法${RESET}：貼進 nginx 的 server 區塊再 reload，或用 sudo ./deploy.sh --configure-nginx <設定檔> 自動處理"
      echo "${CYAN}${NGINX_SNIPPET}${RESET}"
      echo "  ${DIM}完成後執行 ./deploy.sh --check-only 重測${RESET}" ;;
    unreach)
      warn ".git/ 連不上，略過" ;;
    200)
      warn ".git/ 回 200 但不是 git 內容"
      echo "    ${DIM}請確認檢查網址指向本站${RESET}" ;;
    3??)
      warn ".git/ 回 ${r} 轉址，不跟隨"
      echo "    ${DIM}請改用最終網址${RESET}" ;;
    *)
      ok ".git/ 已擋住  ${DIM}回 ${r}${RESET}" ;;
  esac
}

run_selfcheck() {
  step "網站檢查"
  selfcheck_web
}

CHECK_ONLY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --check-only) CHECK_ONLY=1 ;;
    --set-check-url)
      set_check_url "${2:-}" || exit 2
      exit 0 ;;
    --configure-nginx)
      configure_nginx_git "${2:-}" || exit 2
      exit 0 ;;
    help|-h|--help)
      echo "用法：./deploy.sh [--check-only] [--set-check-url URL] [--configure-nginx FILE]"
      echo "  --check-only          不更新程式，只跑網站檢查"
      echo "  --set-check-url URL   儲存檢查網址，例如 ${EXAMPLE_URL}"
      echo "  --configure-nginx FILE 寫入 .git 封鎖規則並 reload Nginx（需 root）"
      echo "環境變數：DEPLOY_BRANCH、DEPLOY_RELOAD_CMD、DEPLOY_INSTALL_FONTS、DEPLOY_CHECK_URL"
      exit 0 ;;
    *) fail "未知參數：$1；請執行 ./deploy.sh help"; exit 2 ;;
  esac
  shift
done

if [ "$CHECK_ONLY" -eq 1 ]; then
  run_selfcheck
  [ "$CRIT" -eq 0 ]
  exit $?
fi

BRANCH="${DEPLOY_BRANCH:-main}"

# hapbun 是 PHP 頁面殼＋瀏覽器端 JS，唯一的伺服器端依賴是 fonts/ 裡的 Noto Sans TC 字型快取
# （不進版本控制），更新完會檢查，缺了就引導安裝。

require_cmd git git

step "檢查本機變更"
# 伺服器上的檔案被手動改過時，git fast-forward merge 會中途失敗；先擋下來，講清楚是哪些檔案。
DIRTY="$(git status --porcelain --untracked-files=no)"
if [ -n "$DIRTY" ]; then
  fail "有未提交的修改，部署已中止："
  sed 's/^/    /' <<< "$DIRTY"
  echo "  ${DIM}請先提交或備份上述修改，再執行 ./deploy.sh${RESET}"
  exit 1
fi
ok "沒有未提交的修改"

HAS_PHP=0
if command -v php >/dev/null 2>&1; then
  HAS_PHP=1
  ok "PHP CLI：$(php -r 'echo PHP_VERSION;')"
else
  warn "缺少 PHP CLI，略過 PHP 執行環境檢查"
  install_hint php-cli
fi

step "取得遠端版本"
BEFORE=$(git rev-parse --short HEAD)
git fetch --quiet origin "$BRANCH"
AFTER=$(git rev-parse --short FETCH_HEAD)
ok "遠端分支 ${BRANCH}：${AFTER}"

if [ "$(git rev-parse HEAD)" != "$(git rev-parse FETCH_HEAD)" ] && ! git merge-base --is-ancestor HEAD FETCH_HEAD; then
  # local 有 remote 沒有的 commit（或兩邊 diverge）：fast-forward 做不到，硬 merge 會在伺服器上產生 merge commit，都不是預期的部署結果
  fail "本機與遠端版本已分歧，無法快轉更新，部署已中止"
  echo "  ${DIM}請先保留並確認本機提交：git log ${AFTER}..HEAD${RESET}"
  exit 1
fi

if [ "$BEFORE" = "$AFTER" ]; then
  ok "已是最新版本（${AFTER}）"
else
  step "更新程式"
  git merge --ff-only --quiet FETCH_HEAD
  ok "已更新：${DIM}${BEFORE}${RESET} → ${GREEN}${BOLD}${AFTER}${RESET}"
  echo "  ${DIM}此次更新的變更：${RESET}"
  git log --oneline "${BEFORE}..${AFTER}" | sed 's/^/    /'
fi

# 選用：PHP 開了 opcache 且不檢查檔案時間戳（validate_timestamps=0）的伺服器，
# 換了檔案要重載服務-FPM 才會生效；用環境變數帶進來，例如
#   DEPLOY_RELOAD_CMD="systemctl reload php8.3-fpm" ./deploy.sh
if [ -n "${DEPLOY_RELOAD_CMD:-}" ] && [ "$BEFORE" != "$AFTER" ]; then
  step "重載服務"
  if bash -c "$DEPLOY_RELOAD_CMD"; then
    ok "${DEPLOY_RELOAD_CMD}"
  else
    fail "重載失敗：${DEPLOY_RELOAD_CMD}（程式碼已更新，請手動重載服務-FPM）"
    exit 1
  fi
fi

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

run_selfcheck
VERSION=''
if [ "$HAS_PHP" -eq 1 ]; then
  VERSION="$(php -r '$c = require "config.php"; echo $c["version"] ?? "?";' 2>/dev/null || echo '?')"
fi
deployment_summary "$VERSION" "$CRIT"
[ "$CRIT" -eq 0 ] || { echo; fail "網站檢查未通過，請依上方訊息處理"; exit 1; }
