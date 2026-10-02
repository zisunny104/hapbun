"""測試 HTTP 無法觸發安裝；只啟動 loopback PHP server，不下載字型。"""
import pathlib, socket, subprocess, time, urllib.request, urllib.error, json, shutil
root = pathlib.Path(__file__).resolve().parents[1]
php = shutil.which('php')
if not php:
    raise SystemExit('需要 PHP CLI')
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
p = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
try:
    for attempt in range(100):
        try:
            urllib.request.urlopen(f'http://127.0.0.1:{port}/install_font.php?force=1',timeout=1)
            raise AssertionError('HTTP 安裝不可成功')
        except urllib.error.HTTPError as e:
            assert e.code == 403, e.code
            assert json.loads(e.read())['error'] == 'cli_only'
            break
        except urllib.error.URLError:
            time.sleep(.05)
    else:
        raise AssertionError('PHP server 未啟動')
    print('securitycheck：HTTP force 安裝被 403 拒絕，沒有觸發下載')
finally:
    p.terminate(); p.wait(timeout=5)
