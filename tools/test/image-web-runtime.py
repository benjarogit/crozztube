#!/usr/bin/env python3
"""Exercise DNS subprocesses in Apache's SAPI, without uploading any images."""
import json
import os
from pathlib import Path
import secrets
import ssl
import tempfile
from urllib.request import Request, urlopen

root = Path(__file__).resolve().parents[2]
key = secrets.token_hex(24)
source = r"""<?php
if (!hash_equals('__KEY__', $_SERVER['HTTP_X_CRZ_RUNTIME'] ?? '')) { http_response_code(403); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    $deadline = microtime(true) + 8;
    $http = new App\Support\Thumbnails\RemoteImages;
    foreach (['https://www.directupload.eu', 'https://new.fastpic.org', 'https://api.postimage.org'] as $host) {
        $http->address($host, $deadline);
    }
    echo json_encode(['ok' => true, 'sapi' => PHP_SAPI, 'providers' => 3]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'sapi' => PHP_SAPI, 'reason' => 'dns-runtime-failed']);
}
""".replace("__KEY__", key)

with tempfile.NamedTemporaryFile(mode="w", prefix="crz-runtime-", suffix=".php",
                                 dir=root / "public", delete=False) as fixture:
    fixture.write(source)
    path = Path(fixture.name)
    path.chmod(0o644)

try:
    url = os.environ.get("BASE_URL", "https://crozztube.local:8443").rstrip("/") + "/public/" + path.name
    request = Request(url, headers={"X-Crz-Runtime": key})
    with urlopen(request, context=ssl._create_unverified_context(), timeout=15) as response:
        result = json.load(response)
    if result.get("ok") is not True or result.get("sapi") == "cli" or result.get("providers") != 3:
        raise RuntimeError("Expected real web SAPI with three validated DNS destinations")
    print("Web image runtime: PHP CLI subprocess and public DNS verified for all three providers")
finally:
    path.unlink(missing_ok=True)
