<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ config('app.name', 'PlayHub') }} recording</title>
    <style>
        *{box-sizing:border-box}
        html,body{width:100%;height:100%;margin:0;overflow:hidden;background:#000;color:#fff;font-family:Arial,sans-serif}
        #recording-root{width:100%;height:100%;display:grid;gap:8px;background:#000;padding:8px}
        .recording-tile{position:relative;min-width:0;min-height:0;overflow:hidden;background:#080808}
        .recording-tile.audio-only{background:radial-gradient(circle at 50% 38%,rgba(255,193,68,.22),transparent 30%),linear-gradient(145deg,#050607,#10131d 58%,#040404)}
        /* Preserve the entire camera/screen frame for portrait, square and landscape tracks. */
        .recording-tile video{width:100%;height:100%;display:block;object-fit:contain;object-position:center;background:#000}
        .recording-tile audio{display:none}
        .recording-name{position:absolute;z-index:2;top:12px;left:12px;max-width:calc(100% - 24px);overflow:hidden;border-radius:5px;background:rgba(0,0,0,.75);color:#fff;padding:7px 10px;text-overflow:ellipsis;white-space:nowrap;font-size:16px;font-weight:400;line-height:1.2}
        .recording-fallback{position:absolute;inset:0;display:grid;place-items:center;background:#080808;color:#fff;font-size:48px;font-weight:400}
        .recording-audio-card{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;padding:42px 28px;background:transparent;text-align:center}
        .recording-audio-avatar{width:128px;height:128px;display:grid;place-items:center;border:2px solid rgba(255,255,255,.18);border-radius:50%;background:linear-gradient(145deg,#ffc145,#7c3aed);box-shadow:0 24px 60px rgba(0,0,0,.48),0 0 0 14px rgba(255,255,255,.05);color:#fff;font-size:58px;font-weight:700;line-height:1;text-transform:uppercase}
        .recording-audio-details strong,.recording-audio-details span{display:block}
        .recording-audio-details strong{max-width:420px;overflow:hidden;color:#fff;text-overflow:ellipsis;white-space:nowrap;font-size:28px;font-weight:700;line-height:1.15}
        .recording-audio-details span{margin-top:7px;color:rgba(255,255,255,.72);font-size:15px;font-weight:600;letter-spacing:.08em;text-transform:uppercase}
        .recording-audio-meter{height:36px;display:flex;align-items:end;gap:7px}
        .recording-audio-meter i{width:7px;height:14px;border-radius:999px;background:#ffc145;box-shadow:0 0 18px rgba(255,193,69,.42);animation:recordingAudioPulse 1.18s ease-in-out infinite}
        .recording-audio-meter i:nth-child(2){height:24px;animation-delay:.12s}.recording-audio-meter i:nth-child(3){height:34px;animation-delay:.24s}.recording-audio-meter i:nth-child(4){height:22px;animation-delay:.36s}.recording-audio-meter i:nth-child(5){height:16px;animation-delay:.48s}
        @keyframes recordingAudioPulse{0%,100%{transform:scaleY(.52);opacity:.55}50%{transform:scaleY(1);opacity:1}}
        #recording-status{position:fixed;z-index:5;inset:0;display:grid;place-items:center;background:#000;color:rgba(255,255,255,.72);font-size:18px}
        #recording-status[hidden]{display:none}
    </style>
    @php
        $manifestPath = file_exists(base_path('build/manifest.json')) ? base_path('build/manifest.json') : base_path('build/.vite/manifest.json');
        $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
        $entry = $manifest['resources/js/livekitRecordingTemplate.js'] ?? null;
    @endphp
</head>
<body>
    <main id="recording-root" aria-label="Live stream recording"></main>
    <div id="recording-status">Connecting to the recording room...</div>
    @if($entry)
        <script type="module" src="/build/{{ $entry['file'] }}"></script>
    @else
        <script>console.error('LiveKit recording template assets are unavailable.')</script>
    @endif
</body>
</html>
