<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $appName }} · {{ __('Security check') }}</title>
    <style nonce="{{ $nonce }}">
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #111; color: #fff; font: 16px system-ui, sans-serif; }
        main { width: min(100% - 40px, 400px); padding: 32px 0; }
        h1 { font-size: 24px; } p { line-height: 1.6; color: #ccc; } #status { min-height: 48px; }
    </style>
</head>
<body>
<main>
    <h1>{{ $appName }}</h1>
    <p>{{ __('Complete the security check to return to account creation.') }}</p>
    <form method="post" action="{{ $submitUrl }}" id="challenge">
        <input type="hidden" name="state" value="{{ $state }}">
        <input type="hidden" name="google_captcha_token" id="token">
        <div id="captcha" data-site-key="{{ $siteKey }}"></div>
    </form>
    <p id="status" role="status">{{ __('Loading security check…') }}</p>
</main>
<script nonce="{{ $nonce }}">
    function captchaReady() {
        document.getElementById('status').textContent = '';
        grecaptcha.render('captcha', {
            sitekey: document.getElementById('captcha').dataset.siteKey,
            callback: function (token) {
                document.getElementById('token').value = token;
                document.getElementById('challenge').submit();
            },
            'expired-callback': function () { document.getElementById('token').value = ''; },
            'error-callback': function () { document.getElementById('status').textContent = @json(__('Unable to load the security check. Reload this page to try again.')); }
        });
    }
</script>
<script nonce="{{ $nonce }}" src="https://www.google.com/recaptcha/api.js?onload=captchaReady&amp;render=explicit" async defer></script>
</body>
</html>
