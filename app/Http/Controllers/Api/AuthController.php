<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Channel;
use App\Models\User;
use App\Services\Auth\NativeDeviceSessionManager;
use App\Services\PushDeviceService;
use App\Support\AuthSettings;
use App\Support\BrandedEmail;
use App\Support\EmailTemplates;
use App\Support\IntegrationSettings;
use App\Support\MediaStorage;
use App\Support\PublishingAccess;
use App\Support\Slugs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public const DEFER_FIRST_PARTY_TOKEN_ATTRIBUTE = 'playhub.native.defer_token';
    public const AUTHENTICATED_USER_ATTRIBUTE = 'playhub.native.authenticated_user';

    private const DEVICE_META_PREFIX = 'device:';
    private const MAX_DEVICE_SESSIONS = 10;

    public function simpleCaptcha(): JsonResponse
    {
        $captcha = app('captcha')->create($this->simpleCaptchaConfig(), true);

        return response()->json([
            'enabled' => true,
            'id' => (string) ($captcha['key'] ?? ''),
            'image' => (string) ($captcha['img'] ?? ''),
            'sensitive' => (bool) ($captcha['sensitive'] ?? false),
        ])->header('Cache-Control', 'no-store');
    }

    public function registrationCaptcha(Request $request): JsonResponse
    {
        $settings = AuthSettings::settings();
        if (! (bool) data_get($settings, 'registration.captchaEnabled', false) || data_get($settings, 'registration.captchaProvider') === 'google') {
            return response()->json(['enabled' => false]);
        }

        return $this->simpleCaptcha();
    }

    public function registrationAvailability(Request $request): JsonResponse
    {
        $result = [];
        $username = trim((string) $request->query('username', ''));
        $email = strtolower(trim((string) $request->query('email', '')));
        $phone = $this->normalizePhone((string) $request->query('phone', ''));

        if ($username !== '') {
            $validFormat = (bool) preg_match('/^[A-Za-z0-9_-]{4,30}$/', $username);
            $hasLetter = (bool) preg_match('/[A-Za-z]/', $username);
            $valid = $validFormat && $hasLetter;
            $result['username'] = [
                'valid' => $valid,
                'available' => $valid && ! User::query()->where('username', $username)->exists(),
                'message' => $valid ? 'Username is available.' : ($hasLetter ? 'Username must be at least 4 characters and use only letters, numbers, dashes, and underscores.' : 'Username must include at least one letter.'),
            ];
            if ($valid && ! $result['username']['available']) {
                $result['username']['message'] = 'This username is already taken.';
            }
        }

        if ($email !== '') {
            $valid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            $result['email'] = [
                'valid' => $valid,
                'available' => $valid && ! User::query()->where('email', $email)->exists(),
                'message' => $valid ? 'Email is available.' : 'Enter a valid email address.',
            ];
            if ($valid && ! $result['email']['available']) {
                $result['email']['message'] = 'This email address is already registered.';
            }
        }

        if ($phone !== '') {
            $valid = $this->isValidPhoneNumber($phone);
            $result['phone'] = [
                'valid' => $valid,
                'available' => $valid && ! $this->phoneRegistered($phone),
                'message' => $valid ? 'Phone number is available.' : 'A valid phone number is required.',
                'login_url' => '/login',
                'recover_url' => '/contact',
            ];
            if ($valid && ! $result['phone']['available']) {
                $result['phone']['message'] = 'This phone number already has an account.';
            }
        }

        return response()->json($result);
    }

    public function registrationEmailChallenge(Request $request): JsonResponse
    {
        $settings = AuthSettings::settings();
        if ($message = AuthSettings::registrationDeniedMessage($request->ip())) {
            throw ValidationException::withMessages(['registration' => [$message]]);
        }
        if ($message = $this->registrationCodeBlockedMessage($request, $settings)) {
            throw ValidationException::withMessages(['registration' => [$message]]);
        }
        if (AuthSettings::registrationConfirmationMethod($settings) !== 'email') {
            throw ValidationException::withMessages(['email' => ['Email registration verification is not enabled.']]);
        }
        if (! $this->isTwoFactorEmailConfigured()) {
            throw ValidationException::withMessages(['email' => ['Email delivery is unavailable. Configure SMTP Email settings in the admin dashboard and try again.']]);
        }

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
        ]);
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => ['This email address is already registered.']]);
        }

        $sendKey = $this->registrationChallengeSendKey($request, 'email', $email);
        $maxSends = AuthSettings::registrationMaxResends($settings) + 1;
        if (RateLimiter::tooManyAttempts($sendKey, $maxSends)) {
            throw ValidationException::withMessages(['email' => ['Too many verification codes requested. Try again later.']]);
        }

        $code = $this->generateRegistrationChallengeCode($settings);
        $codeHash = Hash::make($code);
        if (! $this->sendRegistrationEmailCode($email, $code, (int) ceil(AuthSettings::registrationCodeExpiresSeconds($settings) / 60))) {
            throw ValidationException::withMessages(['email' => [$this->twoFactorDeliveryFailureMessage('email')]]);
        }

        RateLimiter::hit($sendKey, AuthSettings::registrationInvalidBlockSeconds($settings));
        $expiresIn = AuthSettings::registrationCodeExpiresSeconds($settings);
        $expiresAt = now()->addSeconds($expiresIn);
        $sentAt = now()->toIso8601String();
        $codes = $this->activeRegistrationChallengeCodes(Cache::get($this->registrationEmailChallengeKey($email)) ?: []);
        $codes[] = ['code_hash' => $codeHash, 'sent_at' => $sentAt, 'expires_at' => $expiresAt->toIso8601String()];
        $codes = array_slice($codes, -$maxSends);
        Cache::put($this->registrationEmailChallengeKey($email), [
            'email' => $email,
            'code_hash' => $codeHash,
            'codes' => $codes,
            'sent_at' => $sentAt,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return response()->json([
            'message' => 'A verification code has been sent to your email. Enter it below to finish registration.',
            'expires_in' => $expiresIn,
            'code_digits' => AuthSettings::registrationCodeDigits($settings),
            'resends_remaining' => max(0, $maxSends - RateLimiter::attempts($sendKey)),
        ]);
    }

    public function registrationPhoneChallenge(Request $request): JsonResponse
    {
        $settings = AuthSettings::settings();
        if ($message = AuthSettings::registrationDeniedMessage($request->ip())) {
            throw ValidationException::withMessages(['registration' => [$message]]);
        }
        if ($message = $this->registrationCodeBlockedMessage($request, $settings)) {
            throw ValidationException::withMessages(['registration' => [$message]]);
        }
        if (AuthSettings::registrationConfirmationMethod($settings) !== 'sms') {
            throw ValidationException::withMessages(['phone' => ['Phone registration is not enabled.']]);
        }
        if (! $this->isTwoFactorSmsConfigured()) {
            throw ValidationException::withMessages(['phone' => ['SMS delivery is unavailable. Configure SMS OTP settings in the admin dashboard and try again.']]);
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'phone_country_code' => ['nullable', 'string', 'max:8'],
            'phone_country_iso' => ['nullable', 'string', 'size:2'],
        ]);
        $phone = $this->normalizePhone((string) $data['phone']);
        if (! $this->isValidPhoneNumber($phone)) {
            throw ValidationException::withMessages(['phone' => ['A valid phone number is required for SMS confirmation.']]);
        }
        if ($this->phoneRegistered($phone)) {
            throw ValidationException::withMessages(['phone' => ['This phone number is already registered.']]);
        }

        $sendKey = $this->registrationChallengeSendKey($request, 'phone', $phone);
        $maxSends = AuthSettings::registrationMaxResends($settings) + 1;
        if (RateLimiter::tooManyAttempts($sendKey, $maxSends)) {
            throw ValidationException::withMessages(['phone' => ['Too many verification codes requested. Try again later.']]);
        }

        $code = $this->generateRegistrationChallengeCode($settings);
        $codeHash = Hash::make($code);
        if (! $this->sendTwoFactorPhoneCode($phone, $code)) {
            throw ValidationException::withMessages(['phone' => [$this->twoFactorDeliveryFailureMessage('phone')]]);
        }

        RateLimiter::hit($sendKey, AuthSettings::registrationInvalidBlockSeconds($settings));
        $expiresIn = AuthSettings::registrationCodeExpiresSeconds($settings);
        $expiresAt = now()->addSeconds($expiresIn);
        $sentAt = now()->toIso8601String();
        $codes = $this->activeRegistrationChallengeCodes(Cache::get($this->registrationPhoneChallengeKey($phone)) ?: []);
        $codes[] = ['code_hash' => $codeHash, 'sent_at' => $sentAt, 'expires_at' => $expiresAt->toIso8601String()];
        $codes = array_slice($codes, -$maxSends);
        Cache::put($this->registrationPhoneChallengeKey($phone), [
            'phone' => $phone,
            'country_code' => trim((string) ($data['phone_country_code'] ?? '')),
            'country_iso' => strtoupper(trim((string) ($data['phone_country_iso'] ?? ''))),
            'code_hash' => $codeHash,
            'codes' => $codes,
            'sent_at' => $sentAt,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return response()->json([
            'message' => 'A verification code has been sent to your phone. Enter it below to finish registration.',
            'expires_in' => $expiresIn,
            'code_digits' => AuthSettings::registrationCodeDigits($settings),
            'resends_remaining' => max(0, $maxSends - RateLimiter::attempts($sendKey)),
        ]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $authSettings = AuthSettings::settings();
        if ($message = AuthSettings::registrationDeniedMessage($request->ip())) {
            throw ValidationException::withMessages([
                'registration' => [$message],
            ]);
        }
        if ($message = $this->registrationCodeBlockedMessage($request, $authSettings)) {
            throw ValidationException::withMessages([
                'registration' => [$message],
            ]);
        }

        $this->validateRegistrationCaptcha($request, $authSettings);
        $data = $request->validated();
        $this->validateRegistrationPasswordStrength((string) $data['password'], $authSettings, $request);
        $confirmationMethod = AuthSettings::registrationConfirmationMethod($authSettings);
        $data['email'] = strtolower(trim((string) $data['email']));
        $phone = $this->normalizePhone((string) ($data['phone'] ?? ''));
        $phoneCollectionEnabled = AuthSettings::registrationPhoneEnabled($authSettings) || $confirmationMethod === 'sms';
        $phoneChallenge = null;

        if ($confirmationMethod === 'email' && ! $this->isTwoFactorEmailConfigured()) {
            throw ValidationException::withMessages([
                'email' => ['Email delivery is unavailable. Configure SMTP Email settings in the admin dashboard and try again.'],
            ]);
        }

        if ($confirmationMethod === 'email') {
            $this->validateRegistrationEmailChallenge($request, (string) $data['email'], $authSettings);
        }

        if ($phoneCollectionEnabled) {
            if (! $this->isValidPhoneNumber($phone)) {
                throw ValidationException::withMessages([
                    'phone' => ['A valid phone number is required.'],
                ]);
            }
            if ($confirmationMethod === 'sms' && ! $this->isTwoFactorSmsConfigured()) {
                throw ValidationException::withMessages([
                    'phone' => ['SMS delivery is unavailable. Configure SMS OTP settings in the admin dashboard and try again.'],
                ]);
            }
            if ($this->phoneRegistered($phone)) {
                throw ValidationException::withMessages([
                    'phone' => ['This phone number is already registered.'],
                ]);
            }
            if ($confirmationMethod === 'sms') {
                $phoneChallenge = $this->validateRegistrationPhoneChallenge($request, $phone, $authSettings);
            }
        }

        $deviceInfo = $this->registrationDeviceInfo($request);
        $locationInfo = $this->registrationLocationInfo($request);
        $registrationIp = $this->registrationIp($request);

        $user = DB::transaction(function () use ($data, $confirmationMethod, $phone, $phoneCollectionEnabled, $phoneChallenge, $deviceInfo, $locationInfo, $registrationIp): User {
            $requestedUsername = trim((string) ($data['username'] ?? ''));
            $username = $requestedUsername !== '' ? $requestedUsername : Slugs::unique($data['name'], User::class, 'username');
            $settings = [];

            if ($confirmationMethod === 'sms') {
                $settings['security'] = [
                    'two_factor_enabled' => false,
                    'two_factor_method' => 'phone',
                    'two_factor_contact' => $phone,
                ];
            }

            if ($phoneCollectionEnabled) {
                $settings['registration_phone'] = [
                    'number' => $phone,
                    'country_code' => (string) ($phoneChallenge['country_code'] ?? ($data['phone_country_code'] ?? '')),
                    'country_iso' => (string) ($phoneChallenge['country_iso'] ?? ($data['phone_country_iso'] ?? '')),
                    'confirmed_at' => $confirmationMethod === 'sms' ? now()->toIso8601String() : null,
                ];
            }

            if ($deviceInfo) {
                $settings['registration_device'] = $deviceInfo;
            }
            if ($locationInfo) {
                $settings['registration_location'] = $locationInfo;
            }

            $user = User::query()->create([
                'name' => $data['name'],
                'username' => $username,
                'email' => $data['email'],
                'password' => $data['password'],
                'registration_ip' => $registrationIp,
                'registration_provider' => 'local',
                'settings' => ['role' => AuthSettings::registrationDefaultRole(), ...$settings],
            ]);

            Channel::query()->create([
                'user_id' => $user->id,
                'name' => $user->name,
                'slug' => Slugs::unique($user->username, Channel::class),
                'bio' => null,
            ]);

            return $user->load('channel');
        });

        if ($confirmationMethod === 'sms') {
            Cache::forget($this->registrationPhoneChallengeKey($phone));
        }
        if ($confirmationMethod === 'email') {
            Cache::forget($this->registrationEmailChallengeKey((string) $data['email']));
            RateLimiter::clear($this->registrationChallengeSendKey($request, 'email', (string) $data['email']));
        }

        return response()->json([
            'message' => 'Account created successfully.',
            'token' => $this->createPlayhubToken($user, $request),
            'user' => new UserResource($user),
        ], 201);
    }

    public function confirmRegistration(Request $request): JsonResponse
    {
        $codeDigits = AuthSettings::registrationCodeDigits();
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'code' => ['required', 'string', 'regex:/^\d{'.$codeDigits.'}$/'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        if (! $user) {
            throw ValidationException::withMessages(['code' => ['The confirmation code is invalid.']]);
        }

        $settings = is_array($user->settings) ? $user->settings : [];
        $this->confirmRegistrationCode($user, $settings, $data['code']);
        return response()->json([
            'message' => 'Account confirmed successfully.',
            'token' => $this->createPlayhubToken($user, $request),
            'user' => new UserResource($user->fresh()->load('channel')),
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $login = trim((string) ($data['login'] ?? $data['email'] ?? ''));
        $authSettings = AuthSettings::settings();
        $throttleKey = 'playhub-login:'.sha1((string) $request->ip().'|'.strtolower($login));

        if (RateLimiter::tooManyAttempts($throttleKey, AuthSettings::maxLoginAttempts($authSettings))) {
            throw ValidationException::withMessages([
                'login' => ['Too many failed login attempts. Try again in '.ceil(RateLimiter::availableIn($throttleKey) / 60).' minutes.'],
            ]);
        }

        if ($this->loginCaptchaRequired($throttleKey, $authSettings)) {
            if ($captchaMessage = $this->simpleCaptchaValidationMessage($request)) {
                return $this->loginCaptchaResponse($captchaMessage);
            }
        }

        $user = $this->userForLogin($login, $authSettings);

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey, AuthSettings::banSeconds($authSettings));
            if ($this->loginCaptchaRequired($throttleKey, $authSettings)) {
                return $this->loginCaptchaResponse('The provided credentials are incorrect.');
            }

            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        $settings = is_array($user->settings) ? $user->settings : [];
        $status = strtolower(str_replace(['_', '-'], ' ', trim((string) ($settings['status'] ?? 'active'))));
        if (in_array($status, ['suspended', 'deleted'], true)) {
            throw ValidationException::withMessages([
                'login' => ['This account has been suspended. Contact support if you believe this is a mistake.'],
            ]);
        }
        if ($user->isBannedFrom('account')) {
            throw ValidationException::withMessages([
                'login' => [$user->banMessage('account')],
            ]);
        }
        if ($this->registrationConfirmationPending($user, $settings)) {
            $registrationCode = trim((string) ($data['two_factor_code'] ?? ''));
            if ($registrationCode !== '') {
                $settings = $this->confirmRegistrationCode($user, $settings, $registrationCode);
            } else {
                throw ValidationException::withMessages([
                    'login' => ['This account must be confirmed before login. Enter the registration verification code first.'],
                ]);
            }
        }

        $security = is_array($settings['security'] ?? null) ? $settings['security'] : [];
        $twoFactorEnabled = AuthSettings::twoFactorLoginEnabled($authSettings) && (bool) ($security['two_factor_enabled'] ?? false);
        $twoFactorCode = trim((string) ($data['two_factor_code'] ?? ''));

        if ($twoFactorEnabled) {
            if (! $twoFactorCode) {
                $this->issueTwoFactorLoginChallenge($user);
            }

            $user = $this->consumeTwoFactorLoginCode($user, $twoFactorCode, $authSettings);
        }

        RateLimiter::clear($throttleKey);

        return response()->json([
            'message' => 'Logged in successfully.',
            'token' => $this->createPlayhubToken($user, $request),
            'user' => new UserResource($user->load('channel')),
        ]);
    }

    public function socialRedirect(Request $request, string $provider): RedirectResponse
    {
        $provider = $this->normalizeSocialProvider($provider);
        $authSettings = AuthSettings::settings();
        $config = AuthSettings::socialProviderSettings($provider, $authSettings);

        if (! AuthSettings::socialProviderEnabled($provider, $authSettings)) {
            abort(404, ucfirst($provider).' Sign-In is not configured.');
        }

        $state = Str::random(48);
        $intent = $request->query('intent') === 'register' ? 'register' : 'login';
        $returnTo = trim((string) $request->query('return_to', ''));
        $codeChallenge = trim((string) $request->query('code_challenge', ''));
        if ($returnTo !== '') {
            abort_unless(in_array($returnTo, (array) config('native_auth.social_return_uris', []), true), 422, 'The native return URI is not allowed.');
            abort_unless(preg_match('/^[A-Za-z0-9_-]{43}$/', $codeChallenge), 422, 'A valid S256 code challenge is required.');
        } else {
            $codeChallenge = '';
        }
        Cache::put($this->socialStateKey($state), [
            'provider' => $provider,
            'intent' => $intent,
            'return_to' => $returnTo ?: null,
            'code_challenge' => $codeChallenge ?: null,
        ], now()->addMinutes(10));

        $query = [
            'client_id' => trim((string) ($config['clientId'] ?? '')),
            'redirect_uri' => $this->socialRedirectUri($provider),
            'response_type' => 'code',
            'scope' => match ($provider) {
                'google' => 'openid email profile',
                'facebook' => 'email,public_profile',
                default => 'name email',
            },
            'state' => $state,
        ];
        if ($provider === 'google') {
            $query['prompt'] = 'select_account';
        } elseif ($provider === 'apple') {
            $query['response_mode'] = 'form_post';
        }

        $baseUrl = match ($provider) {
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'facebook' => 'https://www.facebook.com/dialog/oauth',
            default => 'https://appleid.apple.com/auth/authorize',
        };

        return redirect()->away($baseUrl.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function socialCallback(Request $request, string $provider): RedirectResponse
    {
        $provider = $this->normalizeSocialProvider($provider);
        $state = trim((string) $request->input('state', $request->query('state', '')));
        $stateData = $state !== '' ? $this->consumeCachedValue($this->socialStateKey($state)) : null;
        $intent = is_array($stateData) && ($stateData['intent'] ?? null) === 'register' ? 'register' : 'login';

        if (! is_array($stateData) || ($stateData['provider'] ?? '') !== $provider) {
            return $this->socialErrorRedirect($intent, 'Social sign-in expired. Try again.');
        }

        if ($request->filled('error')) {
            return $this->socialCompletionRedirect($stateData, $intent, [
                'social_auth_error' => (string) $request->input('error_description', 'Unable to finish social sign-in.'),
            ]);
        }

        try {
            $authSettings = AuthSettings::settings();
            $profile = match ($provider) {
                'google' => $this->googleSocialProfile($request, $authSettings),
                'facebook' => $this->facebookSocialProfile($request, $authSettings),
                default => $this->appleSocialProfile($request, $authSettings),
            };
            $user = $this->userForSocialProfile($provider, $profile, $request, $authSettings);
            $this->ensureSocialUserCanLogin($user);

            $handoff = Str::random(48);
            Cache::put($this->socialHandoffKey($handoff, $stateData['code_challenge'] ?? null), [
                'user_id' => $user->id,
                'code_challenge' => $stateData['code_challenge'] ?? null,
            ], now()->addMinutes(3));

            return $this->socialCompletionRedirect($stateData, $intent, ['social_auth' => $handoff]);
        } catch (\Throwable $exception) {
            // Connection exceptions include the full OAuth URL, which can contain
            // a Facebook client secret or access token in its query string.
            Log::warning('Social sign-in callback failed.', [
                'provider' => $provider,
                'exception_type' => $exception::class,
            ]);
            return $this->socialCompletionRedirect($stateData, $intent, [
                'social_auth_error' => 'Unable to finish social sign-in.',
            ]);
        }
    }

    public function socialExchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:48'],
            'code_verifier' => ['nullable', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/'],
        ]);
        $codeChallenge = filled($data['code_verifier'] ?? null)
            ? rtrim(strtr(base64_encode(hash('sha256', (string) $data['code_verifier'], true)), '+/', '-_'), '=')
            : null;
        $handoff = $this->consumeCachedValue($this->socialHandoffKey($data['code'], $codeChallenge));
        if (is_array($handoff) && filled($handoff['code_challenge'] ?? null)
            && ! hash_equals((string) $handoff['code_challenge'], (string) $codeChallenge)) {
            throw ValidationException::withMessages([
                'code_verifier' => ['The social sign-in verifier is invalid.'],
            ]);
        }
        $user = is_array($handoff) ? User::query()->with('channel')->find($handoff['user_id'] ?? null) : null;

        if (! $user) {
            throw ValidationException::withMessages([
                'code' => ['Social sign-in expired. Try again.'],
            ]);
        }

        $this->ensureSocialUserCanLogin($user);
        return response()->json([
            'message' => 'Social sign-in completed.',
            'token' => $this->createPlayhubToken($user, $request),
            'user' => new UserResource($user),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'phone_country_code' => ['nullable', 'string', 'max:8'],
            'phone_country_iso' => ['nullable', 'string', 'size:2'],
        ]);
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = $this->normalizePhone((string) ($data['phone'] ?? ''));
        if ($email === '' && $phone === '') {
            throw ValidationException::withMessages(['email' => ['Enter a valid email address.']]);
        }
        if ($email === '' && ! $this->isValidPhoneNumber($phone)) {
            throw ValidationException::withMessages(['phone' => ['A valid phone number is required.']]);
        }
        $user = $email !== '' ? User::query()->where('email', $email)->first() : $this->userForPhone($phone);
        $method = $email !== '' ? 'email' : 'phone';
        $message = "If an account exists, a verification code has been sent by {$method}.";

        if (! $user) {
            return response()->json(['message' => $message]);
        }
        if ($method === 'email' && ! $this->isTwoFactorEmailConfigured()) {
            throw ValidationException::withMessages([
                'email' => ['Email delivery is unavailable. Configure SMTP Email settings in the admin dashboard and try again.'],
            ]);
        }
        if ($method === 'phone' && ! $this->isTwoFactorSmsConfigured()) {
            throw ValidationException::withMessages([
                'phone' => ['SMS delivery is unavailable. Configure SMS OTP settings in the admin dashboard and try again.'],
            ]);
        }

        $token = $this->generateTwoFactorChallengeCode();
        $email = strtolower((string) $user->email);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $sent = $method === 'phone'
            ? $this->sendTwoFactorPhoneCode($phone, $token)
            : $this->sendPasswordResetCode($email, $token);
        if (! $sent) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            throw ValidationException::withMessages([
                $method => [$this->twoFactorDeliveryFailureMessage($method)],
            ]);
        }

        return response()->json(['message' => $message]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'token' => ['required', 'string', 'regex:/^\d{6}$/'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = $this->normalizePhone((string) ($data['phone'] ?? ''));
        if ($email === '' && ! $this->isValidPhoneNumber($phone)) {
            throw ValidationException::withMessages(['email' => ['Enter a valid email address or phone number.']]);
        }
        $user = $email !== '' ? User::query()->where('email', $email)->first() : $this->userForPhone($phone);
        $email = strtolower((string) ($user?->email ?? $email));
        $reset = DB::table('password_reset_tokens')->where('email', $email)->first();
        $attemptKey = 'password-reset-code:'.sha1($request->ip().'|'.$email);

        if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
            throw ValidationException::withMessages([
                'token' => ['Too many invalid verification codes. Try again later.'],
            ]);
        }
        if (! $reset || strtotime((string) $reset->created_at) < now()->subMinutes(15)->timestamp || ! Hash::check((string) $data['token'], (string) $reset->token)) {
            RateLimiter::hit($attemptKey, 900);
            throw ValidationException::withMessages([
                'token' => ['This password reset code is invalid or has expired.'],
            ]);
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['This password reset code is invalid or has expired.'],
            ]);
        }

        $this->validateRegistrationPasswordStrength((string) $data['password'], AuthSettings::settings(), $request);
        $user->update(['password' => $data['password']]);
        app(NativeDeviceSessionManager::class)->revokeAll($user);
        $user->tokens()->delete();
        app(PushDeviceService::class)->revokeAll($user);
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        RateLimiter::clear($attemptKey);

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $user = $request->user();
        if (! $user || ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The new password must be different from the current one.'],
            ]);
        }

        $user->update(['password' => $data['password']]);

        return response()->json([
            'message' => 'Password updated successfully.',
            'user' => new UserResource($user->fresh()->load('channel')),
        ]);
    }

    public function setupTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'two_factor_method' => ['required', 'string', 'in:email,phone'],
            'two_factor_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $settings = is_array($user->settings) ? $user->settings : [];
        $security = is_array($settings['security'] ?? null) ? $settings['security'] : [];
        $method = $this->normalizeTwoFactorMethod($data['two_factor_method']);
        $phone = $this->normalizePhone($data['two_factor_phone'] ?? '');

        if ($method === 'phone' && ! $this->isValidPhoneNumber($phone)) {
            throw ValidationException::withMessages([
                'two_factor_phone' => ['A valid phone number is required when using phone delivery.'],
            ]);
        }

        $security['two_factor_method'] = $method;
        $security['two_factor_contact'] = $method === 'phone' ? $phone : null;
        $security['two_factor_enabled'] = (bool) ($security['two_factor_enabled'] ?? false);
        $security = $this->clearTwoFactorChallenge($security);
        $security['two_factor_secret'] = null;

        $challenge = $this->sendTwoFactorChallenge($user, $security, $method, (string) $security['two_factor_contact']);

        if (! $challenge['sent']) {
            throw ValidationException::withMessages([
                'two_factor_method' => [$challenge['message'] ?? 'Unable to send a verification code to the selected method.'],
            ]);
        }

        $security['two_factor_method'] = $challenge['method'] === 'phone' ? 'phone' : 'email';
        $settings['security'] = $security;
        $user->update(['settings' => $settings]);

        return response()->json([
            'message' => $challenge['message'],
            'two_factor_method' => $security['two_factor_method'],
        ]);
    }

    public function enableTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'two_factor_code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $user = $request->user();
        $settings = is_array($user->settings) ? $user->settings : [];
        $security = is_array($settings['security'] ?? null) ? $settings['security'] : [];

        if (! $this->verifyTwoFactorCode($security, $data['two_factor_code'])) {
            throw ValidationException::withMessages([
                'two_factor_code' => ['The two-factor authentication code is invalid.'],
            ]);
        }

        $security['two_factor_enabled'] = true;
        $security['two_factor_secret'] = null;
        $security = $this->clearTwoFactorChallenge($security);
        $settings['security'] = $security;
        $user->update(['settings' => $settings]);

        return response()->json([
            'message' => 'Two-factor authentication enabled.',
            'user' => new UserResource($user->fresh()->load('channel')),
        ]);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'two_factor_code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $user = $request->user();
        $settings = is_array($user->settings) ? $user->settings : [];
        $security = is_array($settings['security'] ?? null) ? $settings['security'] : [];

        if (! $this->verifyTwoFactorCode($security, $data['two_factor_code'])) {
            throw ValidationException::withMessages([
                'two_factor_code' => ['The two-factor authentication code is invalid.'],
            ]);
        }

        $security['two_factor_enabled'] = false;
        $security['two_factor_secret'] = null;
        $security = $this->clearTwoFactorChallenge($security);
        $settings['security'] = $security;
        $user->update(['settings' => $settings]);

        return response()->json([
            'message' => 'Two-factor authentication disabled.',
            'user' => new UserResource($user->fresh()->load('channel')),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->load('channel')),
        ]);
    }

    public function devices(Request $request): JsonResponse
    {
        return response()->json($this->deviceSessionsResponse($request));
    }

    public function revokeDevice(Request $request, int $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = (int) ($user->currentAccessToken()?->id ?? 0);

        if ($device === $currentId) {
            throw ValidationException::withMessages([
                'device' => ['Use logout to sign out from the current device.'],
            ]);
        }

        $token = $user->tokens()
            ->where('name', 'playhub-web')
            ->whereKey($device)
            ->first();

        if (! $token) {
            throw ValidationException::withMessages([
                'device' => ['Device session not found.'],
            ]);
        }

        $token->delete();

        return response()->json(array_merge($this->deviceSessionsResponse($request), [
            'message' => 'Device signed out successfully.',
        ]));
    }

    public function revokeOtherDevices(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = (int) ($user->currentAccessToken()?->id ?? 0);

        $query = $user->tokens()->where('name', 'playhub-web');
        if ($currentId > 0) {
            $query->where('id', '!=', $currentId);
        }

        $revoked = (int) $query->delete();

        return response()->json(array_merge($this->deviceSessionsResponse($request), [
            'message' => $revoked > 0 ? 'Other devices signed out successfully.' : 'No other devices to sign out.',
            'revoked_count' => $revoked,
        ]));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $channel = $user->channel()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $user->name,
                'slug' => Slugs::unique($user->username, Channel::class),
                'bio' => null,
            ]
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:40',
                'regex:/^[A-Za-z0-9_.]+$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'email' => [
                'required',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'channel_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'channel_slug' => [
                'required',
                'string',
                'min:3',
                'max:140',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('channels', 'slug')->ignore($channel->id),
            ],
            'bio' => ['nullable', 'string', 'max:160'],
            'settings' => ['nullable', 'array'],
            'settings.profile' => ['nullable', 'array'],
            'settings.profile.website' => ['nullable', 'string', 'max:255'],
            'settings.profile.location' => ['nullable', 'string', 'max:120'],
            'settings.profile.socials' => ['nullable', 'array'],
            'settings.profile.socials.instagram' => ['nullable', 'string', 'max:255'],
            'settings.profile.socials.youtube' => ['nullable', 'string', 'max:255'],
            'settings.profile.socials.twitter' => ['nullable', 'string', 'max:255'],
            'settings.profile.socials.facebook' => ['nullable', 'string', 'max:255'],
            'settings.profile.socials.tiktok' => ['nullable', 'string', 'max:255'],
            'settings.profile.socials.linkedin' => ['nullable', 'string', 'max:255'],
            'settings.preferences' => ['nullable', 'array'],
            'settings.preferences.publicProfile' => ['nullable', 'boolean'],
            'settings.preferences.showSubscriptions' => ['nullable', 'boolean'],
            'settings.preferences.showEmail' => ['nullable', 'boolean'],
            'settings.preferences.acceptTips' => ['nullable', 'boolean'],
            'settings.preferences.privateMessages' => ['nullable', 'string', Rule::in(['anyone', 'subscribers', 'disabled'])],
            'settings.preferences.comments' => ['nullable', 'boolean'],
            'settings.preferences.replies' => ['nullable', 'boolean'],
            'settings.preferences.mentions' => ['nullable', 'boolean'],
            'settings.preferences.liveReminders' => ['nullable', 'boolean'],
            'settings.preferences.uploads' => ['nullable', 'boolean'],
            'settings.preferences.subscriptions' => ['nullable', 'boolean'],
            'settings.preferences.newsletter' => ['nullable', 'boolean'],
            'settings.preferences.autoplay' => ['nullable', 'boolean'],
            'settings.preferences.reducedMotion' => ['nullable', 'boolean'],
            'settings.playback' => ['nullable', 'array'],
            'settings.playback.defaultQuality' => ['nullable', 'string', 'in:Auto,720p,1080p,1440p,2160p'],
            'settings.playback.captions' => ['nullable', 'string', 'in:On,Off,Auto'],
            'settings.playback.language' => ['nullable', 'string', 'max:80'],
            'settings.appearance' => ['nullable', 'array'],
            'settings.appearance.themeMode' => ['nullable', 'string', 'in:light,dark,system'],
            'settings.appearance.accent' => ['nullable', 'string', 'max:40'],
            'settings.membership' => ['nullable', 'string', Rule::in(['starter', 'plus', 'premium', 'pro', 'vip'])],
        ]);

        $requestedMembership = data_get($data, 'settings.membership');
        if ($requestedMembership !== null) {
            $nextMembership = MediaStorage::normalizeMembership($requestedMembership);
            if ($nextMembership !== MediaStorage::membershipFor($user) && MediaStorage::membershipPriceUsd($nextMembership) > 0) {
                throw ValidationException::withMessages([
                    'settings.membership' => ['Payment is required before changing to this paid plan.'],
                ]);
            }
        }

        $emailChanged = $user->email !== $data['email'];

        $user->update([
            'name' => trim($data['name']),
            'username' => trim($data['username']),
            'email' => trim($data['email']),
            'gender' => array_key_exists('gender', $data) ? $data['gender'] : $user->gender,
            'date_of_birth' => array_key_exists('date_of_birth', $data) ? $data['date_of_birth'] : $user->date_of_birth,
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
            'settings' => $this->normalizeSettings($data['settings'] ?? [], $user->settings ?: []),
        ]);

        $channel->update([
            'name' => trim($data['channel_name'] ?? $data['name']),
            'slug' => strtolower(trim($data['channel_slug'])),
            'bio' => trim((string) ($data['bio'] ?? '')) ?: null,
        ]);

        return response()->json([
            'message' => 'Settings updated.',
            'user' => new UserResource($user->fresh()->load('channel')),
        ]);
    }

    public function upgradeCreator(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->loadMissing('channel');
        $role = PublishingAccess::role($user);
        if ($role !== 'user') {
            return response()->json([
                'message' => 'Your account already has a publishing role.',
                'user' => new UserResource($user),
            ]);
        }

        $requirements = PublishingAccess::profileRequirements($user);
        $missing = collect($requirements)->where('complete', false)->values();
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'profile' => ['Complete your profile before upgrading: '.$missing->pluck('label')->implode(', ').'.'],
            ]);
        }

        $settings = is_array($user->settings) ? $user->settings : [];
        $settings['role'] = 'creator';
        $settings['creator_upgraded_at'] = now()->toIso8601String();
        $user->update(['settings' => $settings]);

        return response()->json([
            'message' => 'Your creator account is ready.',
            'user' => new UserResource($user->fresh()->load('channel')),
        ]);
    }

    public function updateAutoplayPreference(Request $request): JsonResponse
    {
        $data = $request->validate([
            'autoplay' => ['required', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $settings = is_array($user->settings) ? $user->settings : [];
        $preferences = is_array($settings['preferences'] ?? null) ? $settings['preferences'] : [];
        $preferences['autoplay'] = (bool) $data['autoplay'];
        $settings['preferences'] = $preferences;
        $user->update(['settings' => $settings]);

        return response()->json([
            'autoplay' => $preferences['autoplay'],
            'user' => new UserResource($user->fresh()->load('channel')),
        ]);
    }

    private function normalizeSettings(array $incoming, array $current): array
    {
        $settings = $current;
        $settings['security'] = is_array($settings['security'] ?? null) ? $settings['security'] : [];
        $settings['profile'] = [
            'website' => trim((string) data_get($incoming, 'profile.website', data_get($current, 'profile.website', ''))),
            'location' => trim((string) data_get($incoming, 'profile.location', data_get($current, 'profile.location', 'Not specified'))),
            'socials' => collect(['instagram', 'youtube', 'twitter', 'facebook', 'tiktok', 'linkedin'])
                ->mapWithKeys(fn (string $key): array => [$key => trim((string) data_get($incoming, "profile.socials.$key", data_get($current, "profile.socials.$key", '')))])
                ->all(),
        ];
        $settings['preferences'] = collect([
            'publicProfile' => true,
            'showSubscriptions' => true,
            'showEmail' => false,
            'acceptTips' => true,
            'comments' => true,
            'replies' => true,
            'mentions' => true,
            'liveReminders' => true,
            'uploads' => true,
            'subscriptions' => true,
            'newsletter' => false,
            'autoplay' => true,
            'reducedMotion' => false,
        ])->mapWithKeys(fn (bool $default, string $key): array => [
            $key => (bool) data_get($incoming, "preferences.$key", data_get($current, "preferences.$key", $default)),
        ])->all();
        $privateMessages = data_get($incoming, 'preferences.privateMessages', data_get($current, 'preferences.privateMessages', 'anyone'));
        $settings['preferences']['privateMessages'] = in_array($privateMessages, ['anyone', 'subscribers', 'disabled'], true) ? $privateMessages : 'anyone';
        $settings['playback'] = [
            'defaultQuality' => data_get($incoming, 'playback.defaultQuality', data_get($current, 'playback.defaultQuality', '1080p')),
            'captions' => data_get($incoming, 'playback.captions', data_get($current, 'playback.captions', 'On')),
            'language' => trim((string) data_get($incoming, 'playback.language', data_get($current, 'playback.language', 'English'))),
        ];
        $settings['appearance'] = [
            'themeMode' => data_get($incoming, 'appearance.themeMode', data_get($current, 'appearance.themeMode', 'system')),
            'accent' => data_get($incoming, 'appearance.accent', data_get($current, 'appearance.accent', 'platform')),
        ];
        $settings['membership'] = MediaStorage::normalizeMembership(data_get($incoming, 'membership', data_get($current, 'membership', MediaStorage::defaultMembership())));

        return $settings;
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    private function createPlayhubToken(User $user, Request $request): string
    {
        if ($request->attributes->get(self::DEFER_FIRST_PARTY_TOKEN_ATTRIBUTE) === true) {
            $request->attributes->set(self::AUTHENTICATED_USER_ATTRIBUTE, $user);

            return '';
        }

        $token = $user->createToken('playhub-web', ['*', $this->encodedDeviceAbility($request)]);
        $this->prunePlayhubDeviceTokens($user, (int) $token->accessToken->id);

        return $token->plainTextToken;
    }

    private function deviceSessionsResponse(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = (int) ($user->currentAccessToken()?->id ?? 0);
        $tokens = $user->tokens()
            ->where('name', 'playhub-web')
            ->get()
            ->sort(function (PersonalAccessToken $left, PersonalAccessToken $right) use ($currentId): int {
                $leftCurrent = (int) $left->id === $currentId;
                $rightCurrent = (int) $right->id === $currentId;
                if ($leftCurrent !== $rightCurrent) {
                    return $leftCurrent ? -1 : 1;
                }

                return $this->deviceActivityTimestamp($right) <=> $this->deviceActivityTimestamp($left);
            })
            ->values();

        return [
            'devices' => $tokens->map(fn (PersonalAccessToken $token): array => $this->deviceSessionPayload($token, $currentId))->values(),
            'active_count' => $tokens->count(),
            'max_devices' => self::MAX_DEVICE_SESSIONS,
        ];
    }

    private function prunePlayhubDeviceTokens(User $user, int $currentTokenId): void
    {
        $keepOtherSessions = max(0, self::MAX_DEVICE_SESSIONS - 1);
        $staleIds = $user->tokens()
            ->where('name', 'playhub-web')
            ->where('id', '!=', $currentTokenId)
            ->get()
            ->sortByDesc(fn (PersonalAccessToken $token): int => $this->deviceActivityTimestamp($token))
            ->slice($keepOtherSessions)
            ->pluck('id');

        if ($staleIds->isNotEmpty()) {
            PersonalAccessToken::query()->whereIn('id', $staleIds)->delete();
        }
    }

    private function deviceSessionPayload(PersonalAccessToken $token, int $currentId): array
    {
        $meta = $this->deviceMetadataFromToken($token);
        $lastActive = $token->last_used_at ?: $token->created_at;
        $browser = (string) ($meta['browser'] ?? 'Browser');
        $platform = (string) ($meta['platform'] ?? 'Unknown platform');

        return [
            'id' => $token->id,
            'name' => $this->deviceDisplayName($browser, $platform),
            'device' => (string) ($meta['device'] ?? 'Desktop'),
            'browser' => $browser,
            'platform' => $platform,
            'ip_address' => $meta['ip_address'] ?? null,
            'user_agent' => $meta['user_agent'] ?? null,
            'created_at' => $token->created_at?->toIso8601String(),
            'last_active_at' => $lastActive?->toIso8601String(),
            'is_current' => (int) $token->id === $currentId,
        ];
    }

    private function encodedDeviceAbility(Request $request): string
    {
        $json = json_encode($this->requestDeviceMetadata($request), JSON_UNESCAPED_SLASHES) ?: '{}';

        return self::DEVICE_META_PREFIX.rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function deviceMetadataFromToken(PersonalAccessToken $token): array
    {
        foreach ((array) $token->abilities as $ability) {
            if (! is_string($ability) || ! Str::startsWith($ability, self::DEVICE_META_PREFIX)) {
                continue;
            }

            $payload = substr($ability, strlen(self::DEVICE_META_PREFIX));
            $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
            $json = base64_decode(strtr($payload, '-_', '+/'), true);
            $meta = $json ? json_decode($json, true) : null;

            return is_array($meta) ? $meta : [];
        }

        return [];
    }

    private function requestDeviceMetadata(Request $request): array
    {
        $userAgent = trim((string) $request->userAgent());

        return [
            'browser' => $this->browserFromUserAgent($userAgent),
            'platform' => $this->platformFromUserAgent($userAgent),
            'device' => $this->deviceTypeFromUserAgent($userAgent),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit($userAgent, 180, ''),
        ];
    }

    private function deviceActivityTimestamp(PersonalAccessToken $token): int
    {
        return (int) ($token->last_used_at ?: $token->created_at)?->getTimestamp();
    }

    private function deviceDisplayName(string $browser, string $platform): string
    {
        $browser = trim($browser) ?: 'Browser';
        $platform = trim($platform) ?: 'Unknown platform';

        return $browser.' on '.$platform;
    }

    private function browserFromUserAgent(string $userAgent): string
    {
        return match (true) {
            $userAgent === '' => 'Browser',
            (bool) preg_match('/Edg\//i', $userAgent) => 'Microsoft Edge',
            (bool) preg_match('/OPR\/|Opera/i', $userAgent) => 'Opera',
            (bool) preg_match('/CriOS\/|Chrome\//i', $userAgent) => 'Chrome',
            (bool) preg_match('/FxiOS\/|Firefox\//i', $userAgent) => 'Firefox',
            (bool) preg_match('/Safari\//i', $userAgent) => 'Safari',
            default => 'Browser',
        };
    }

    private function platformFromUserAgent(string $userAgent): string
    {
        return match (true) {
            (bool) preg_match('/iPad|iPhone|iPod/i', $userAgent) => 'iOS',
            (bool) preg_match('/Android/i', $userAgent) => 'Android',
            (bool) preg_match('/Windows NT/i', $userAgent) => 'Windows',
            (bool) preg_match('/Mac OS X|Macintosh/i', $userAgent) => 'macOS',
            (bool) preg_match('/Linux/i', $userAgent) => 'Linux',
            default => 'Unknown platform',
        };
    }

    private function deviceTypeFromUserAgent(string $userAgent): string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet/i', $userAgent) => 'Tablet',
            (bool) preg_match('/Mobile|iPhone|iPod|Android/i', $userAgent) => 'Mobile',
            default => 'Desktop',
        };
    }

    private function validateRegistrationCaptcha(Request $request, array $settings): void
    {
        if (! (bool) data_get($settings, 'registration.captchaEnabled', false)) {
            return;
        }

        if (data_get($settings, 'registration.captchaProvider') === 'google') {
            $token = trim((string) $request->input('google_captcha_token', ''));
            $secret = trim((string) data_get($settings, 'registration.googleCaptchaSecretKey', ''));
            if ($secret === '') {
                throw ValidationException::withMessages(['captcha' => ['Google captcha is not configured.']]);
            }
            if ($token === '') {
                throw ValidationException::withMessages(['captcha' => ['Complete the captcha to continue.']]);
            }

            try {
                $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['captcha' => ['Google captcha could not be verified. Try again.']]);
            }

            if (! $response->successful() || ! (bool) $response->json('success')) {
                throw ValidationException::withMessages(['captcha' => ['Google captcha could not be verified. Try again.']]);
            }

            return;
        }

        if ($message = $this->simpleCaptchaValidationMessage($request)) {
            throw ValidationException::withMessages(['captcha_answer' => [$message]]);
        }
    }

    private function validateRegistrationEmailChallenge(Request $request, string $email, array $settings): array
    {
        $code = trim((string) $request->input('email_confirmation_code', ''));
        $codeDigits = AuthSettings::registrationCodeDigits($settings);
        if (! preg_match('/^\d{'.$codeDigits.'}$/', $code)) {
            throw ValidationException::withMessages(['email_confirmation_code' => ['Enter the email verification code.']]);
        }

        $challenge = Cache::get($this->registrationEmailChallengeKey($email));
        if (! is_array($challenge) || ($challenge['email'] ?? '') !== $email || ! $this->activeRegistrationChallengeCodes($challenge)) {
            throw ValidationException::withMessages(['email_confirmation_code' => ['The email verification code has expired. Request a new code.']]);
        }

        if (! $this->registrationChallengeCodeMatches($challenge, $code)) {
            if ($message = $this->recordRegistrationCodeInvalidAttempt($request, $settings)) {
                throw ValidationException::withMessages(['email_confirmation_code' => [$message]]);
            }

            throw ValidationException::withMessages(['email_confirmation_code' => ['The email verification code is invalid.']]);
        }

        RateLimiter::clear($this->registrationCodeInvalidAttemptKey($request));

        return $challenge;
    }

    private function validateRegistrationPhoneChallenge(Request $request, string $phone, array $settings): array
    {
        $code = trim((string) $request->input('phone_confirmation_code', ''));
        $codeDigits = AuthSettings::registrationCodeDigits($settings);
        if (! preg_match('/^\d{'.$codeDigits.'}$/', $code)) {
            throw ValidationException::withMessages(['phone_confirmation_code' => ['Enter the SMS verification code.']]);
        }

        $challenge = Cache::get($this->registrationPhoneChallengeKey($phone));
        if (! is_array($challenge) || ($challenge['phone'] ?? '') !== $phone || ! $this->activeRegistrationChallengeCodes($challenge)) {
            throw ValidationException::withMessages(['phone_confirmation_code' => ['The SMS verification code has expired. Request a new code.']]);
        }

        if (! $this->registrationChallengeCodeMatches($challenge, $code)) {
            if ($message = $this->recordRegistrationCodeInvalidAttempt($request, $settings)) {
                throw ValidationException::withMessages(['phone_confirmation_code' => [$message]]);
            }

            throw ValidationException::withMessages(['phone_confirmation_code' => ['The SMS verification code is invalid.']]);
        }

        RateLimiter::clear($this->registrationCodeInvalidAttemptKey($request));

        return $challenge;
    }

    private function activeRegistrationChallengeCodes(array $challenge): array
    {
        $active = [];
        $seen = [];
        $now = time();

        foreach ((array) ($challenge['codes'] ?? []) as $item) {
            $hash = is_array($item) ? (string) ($item['code_hash'] ?? '') : '';
            $expiresAt = is_array($item) ? strtotime((string) ($item['expires_at'] ?? '')) : false;
            if ($hash === '' || $expiresAt === false || $expiresAt <= $now || isset($seen[$hash])) {
                continue;
            }
            $active[] = $item;
            $seen[$hash] = true;
        }

        $hash = (string) ($challenge['code_hash'] ?? '');
        $expiresAt = strtotime((string) ($challenge['expires_at'] ?? ''));
        if ($hash !== '' && ! isset($seen[$hash]) && ($expiresAt === false || $expiresAt > $now)) {
            $active[] = ['code_hash' => $hash, 'sent_at' => $challenge['sent_at'] ?? null, 'expires_at' => $challenge['expires_at'] ?? null];
        }

        return $active;
    }

    private function registrationChallengeCodeMatches(array $challenge, string $code): bool
    {
        foreach ($this->activeRegistrationChallengeCodes($challenge) as $item) {
            if (Hash::check($code, (string) ($item['code_hash'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function registrationEmailChallengeKey(string $email): string
    {
        return 'playhub-registration-email:'.sha1(strtolower(trim($email)));
    }

    private function registrationPhoneChallengeKey(string $phone): string
    {
        return 'playhub-registration-phone:'.sha1($phone);
    }

    private function registrationChallengeSendKey(Request $request, string $method, string $destination): string
    {
        return 'playhub-registration-'.$method.'-send:'.sha1((string) $request->ip().'|'.$destination);
    }

    private function registrationCodeInvalidAttemptKey(Request $request): string
    {
        return 'playhub-registration-code-invalid:'.sha1((string) $request->ip());
    }

    private function registrationCodeBlockKey(Request $request): string
    {
        return 'playhub-registration-code-block:'.sha1((string) $request->ip());
    }

    private function registrationCodeBlockedMessage(Request $request, array $settings): ?string
    {
        return RateLimiter::tooManyAttempts($this->registrationCodeBlockKey($request), 1)
            ? 'Registration is temporarily blocked for this IP address. Try again later.'
            : null;
    }

    private function recordRegistrationCodeInvalidAttempt(Request $request, array $settings): ?string
    {
        $attemptKey = $this->registrationCodeInvalidAttemptKey($request);
        $blockSeconds = AuthSettings::registrationInvalidBlockSeconds($settings);

        RateLimiter::hit($attemptKey, $blockSeconds);
        if (RateLimiter::attempts($attemptKey) < AuthSettings::registrationInvalidAttempts($settings)) {
            return null;
        }

        RateLimiter::clear($attemptKey);
        RateLimiter::hit($this->registrationCodeBlockKey($request), $blockSeconds);

        return 'Too many invalid verification codes. Registration is temporarily blocked for this IP address.';
    }

    private function phoneRegistered(string $phone): bool
    {
        return $this->userForPhone($phone) !== null;
    }

    private function userForPhone(string $phone): ?User
    {
        $phoneOptions = array_unique([$phone, str_starts_with($phone, '+') ? ltrim($phone, '+') : '+'.$phone]);

        return User::query()
            ->where(function ($query) use ($phoneOptions): void {
                foreach ($phoneOptions as $phoneOption) {
                    $query->orWhere('settings->security->two_factor_contact', $phoneOption)
                        ->orWhere('settings->registration_phone->number', $phoneOption);
                }
            })
            ->first();
    }

    private function registrationDeviceInfo(Request $request): array
    {
        $device = is_array($request->input('device_info')) ? $request->input('device_info') : [];

        return array_filter([
            'user_agent' => substr((string) ($request->userAgent() ?: data_get($device, 'userAgent', '')), 0, 500),
            'platform' => substr((string) data_get($device, 'platform', ''), 0, 120),
            'language' => substr((string) data_get($device, 'language', ''), 0, 80),
            'timezone' => substr((string) data_get($device, 'timezone', ''), 0, 120),
            'screen' => substr((string) data_get($device, 'screen', ''), 0, 40),
        ], fn ($value): bool => $value !== '');
    }

    private function registrationIp(Request $request): ?string
    {
        $ip = trim((string) $request->ip());

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private function registrationLocationInfo(Request $request): array
    {
        $location = is_array($request->input('location_info')) ? $request->input('location_info') : [];

        return array_filter([
            'ip_address' => $this->registrationIp($request),
            'country_iso' => strtoupper(substr((string) data_get($location, 'countryIso', ''), 0, 2)),
            'timezone' => substr((string) data_get($location, 'timezone', ''), 0, 120),
        ], fn ($value): bool => $value !== '' && $value !== null);
    }

    private function simpleCaptchaValidationMessage(Request $request): ?string
    {
        $id = trim((string) $request->input('captcha_id', ''));
        $answer = trim((string) $request->input('captcha_answer', ''));
        if ($id === '' || $answer === '') {
            return 'Complete the captcha to continue.';
        }

        try {
            $valid = captcha_api_check($answer, $id, $this->simpleCaptchaConfig());
        } catch (\Throwable) {
            $valid = false;
        }

        return $valid ? null : 'The captcha answer is incorrect.';
    }

    private function loginCaptchaRequired(string $throttleKey, array $settings): bool
    {
        return RateLimiter::attempts($throttleKey) >= AuthSettings::loginCaptchaAfterAttempts($settings);
    }

    private function loginCaptchaResponse(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => ['captcha_answer' => [$message]],
            'requires_captcha' => true,
        ], 422);
    }

    private function simpleCaptchaConfig(): string
    {
        return 'default';
    }

    private function validateRegistrationPasswordStrength(string $password, array $settings, Request $request): void
    {
        if (! AuthSettings::passwordStrengthEnabled($settings)) {
            return;
        }

        $level = AuthSettings::passwordStrengthLevel($settings);
        if ($level <= 0) {
            return;
        }

        $score = $this->passwordStrengthScore($password);
        if ($score < $level) {
            throw ValidationException::withMessages([
                'password' => [app(\App\Services\LocalizationService::class)->translate('Password strength must be at least {level}/10.', $request->header('X-PlayHub-Language') ?: $request->header('X-PlayHub-Locale') ?: $request->getPreferredLanguage(), ['level' => $level])],
            ]);
        }
    }

    private function passwordStrengthScore(string $password): int
    {
        $length = strlen($password);
        $classes = 0;
        $score = 0;
        $score += $length >= 8 ? 2 : 0;
        $score += $length >= 10 ? 1 : 0;
        $score += $length >= 12 ? 1 : 0;
        $score += $length >= 16 ? 1 : 0;

        foreach (['/[a-z]/', '/[A-Z]/', '/\d/', '/[^A-Za-z0-9]/'] as $pattern) {
            if (preg_match($pattern, $password)) {
                $classes++;
                $score++;
            }
        }

        if ($classes >= 3) {
            $score++;
        }

        return max(0, min(10, $score));
    }

    private function confirmRegistrationCode(User $user, array $settings, string $code): array
    {
        $authSettings = AuthSettings::settings();
        $maximumAttempts = AuthSettings::registrationInvalidAttempts($authSettings);
        $decaySeconds = AuthSettings::registrationInvalidBlockSeconds($authSettings);
        $attemptKey = $this->registrationConfirmationAttemptKey((string) $user->email);

        if (RateLimiter::tooManyAttempts($attemptKey, $maximumAttempts)) {
            throw ValidationException::withMessages([
                'code' => ['Too many invalid confirmation codes. Try again later.'],
            ]);
        }

        $outcome = DB::transaction(function () use (
            $user,
            $code,
            $attemptKey,
            $maximumAttempts,
            $decaySeconds,
        ): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $currentSettings = is_array($lockedUser->settings) ? $lockedUser->settings : [];
            $confirmation = is_array($currentSettings['registration_confirmation'] ?? null)
                ? $currentSettings['registration_confirmation']
                : [];

            if (! (bool) ($confirmation['required'] ?? false) || ! empty($confirmation['confirmed_at'] ?? null)) {
                return ['settings' => null, 'exception' => ValidationException::withMessages([
                    'code' => ['This account does not have a pending confirmation.'],
                ])];
            }

            if (empty($confirmation['code_hash'] ?? null)
                || empty($confirmation['code_expires_at'] ?? null)
                || strtotime((string) $confirmation['code_expires_at']) <= time()) {
                return ['settings' => null, 'exception' => ValidationException::withMessages([
                    'code' => ['The confirmation code has expired. Register again to receive a new code.'],
                ])];
            }

            if (! Hash::check(trim($code), (string) $confirmation['code_hash'])) {
                RateLimiter::hit($attemptKey, $decaySeconds);
                $confirmation['attempts'] = (int) ($confirmation['attempts'] ?? 0) + 1;

                if ($confirmation['attempts'] >= $maximumAttempts
                    || RateLimiter::tooManyAttempts($attemptKey, $maximumAttempts)) {
                    $confirmation['code_hash'] = null;
                    $confirmation['code_expires_at'] = null;
                    $confirmation['invalidated_at'] = now()->toIso8601String();
                }

                $currentSettings['registration_confirmation'] = $confirmation;
                $lockedUser->forceFill(['settings' => $currentSettings])->save();

                return ['settings' => null, 'exception' => ValidationException::withMessages([
                    'code' => ['The confirmation code is invalid.'],
                ])];
            }

            $confirmation['code_hash'] = null;
            $confirmation['code_expires_at'] = null;
            $confirmation['confirmed_at'] = now()->toIso8601String();
            $currentSettings['registration_confirmation'] = $confirmation;
            $lockedUser->forceFill([
                'email_verified_at' => $lockedUser->email_verified_at ?: now(),
                'settings' => $currentSettings,
            ])->save();

            return ['settings' => $currentSettings, 'exception' => null];
        }, 3);

        if ($outcome['exception'] instanceof ValidationException) {
            throw $outcome['exception'];
        }

        RateLimiter::clear($attemptKey);

        return $outcome['settings'];
    }

    private function registrationConfirmationPending(User $user, array $settings): bool
    {
        if ($user->email_verified_at) {
            return false;
        }

        $confirmation = is_array($settings['registration_confirmation'] ?? null) ? $settings['registration_confirmation'] : [];

        return (bool) ($confirmation['required'] ?? false) && empty($confirmation['confirmed_at'] ?? null);
    }

    private function generateTwoFactorChallengeCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function generateRegistrationChallengeCode(array $settings): string
    {
        $digits = AuthSettings::registrationCodeDigits($settings);

        return str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);
    }

    private function userForLogin(string $login, array $authSettings): ?User
    {
        if (AuthSettings::loginMethodEnabled('email', $authSettings) && filter_var($login, FILTER_VALIDATE_EMAIL)) {
            if ($user = User::query()->where('email', $login)->first()) {
                return $user;
            }
        }

        if (AuthSettings::loginMethodEnabled('username', $authSettings)) {
            if ($user = User::query()->where('username', $login)->first()) {
                return $user;
            }
        }

        $phone = $this->normalizePhone($login);
        if (AuthSettings::loginMethodEnabled('phone', $authSettings) && $this->isValidPhoneNumber($phone)) {
            // Registration phone collection does not require enabling two-factor authentication.
            return $this->userForPhone($phone);
        }

        return null;
    }

    private function normalizeTwoFactorMethod(string $method): string
    {
        return $method === 'phone' ? 'phone' : 'email';
    }

    private function normalizePhone(string $phone): string
    {
        return trim(preg_replace('/[^0-9+]/', '', $phone));
    }

    private function isValidPhoneNumber(string $phone): bool
    {
        if ($phone === '') {
            return false;
        }

        $digits = ltrim($phone, '+');

        return strlen($digits) >= 7 && strlen($digits) <= 18;
    }

    private function generateChallengeExpiry(): string
    {
        return now()->addMinutes(10)->toDateTimeString();
    }

    private function sendTwoFactorChallenge(User $user, array &$security, string $method, string $contact = ''): array
    {
        $method = $this->normalizeTwoFactorMethod($method);
        $requestedMethod = $method;
        $contact = $this->normalizePhone($contact);
        $security['two_factor_contact'] = $method === 'phone' ? $contact : null;
        $challengeCode = $this->generateTwoFactorChallengeCode();

        $security['two_factor_method'] = $method;
        $security['two_factor_code_hash'] = Hash::make($challengeCode);
        $security['two_factor_code_expires_at'] = $this->generateChallengeExpiry();

        if ($method === 'phone' && ! $this->isTwoFactorSmsConfigured()) {
            if (! $this->isTwoFactorEmailConfigured()) {
                $security['two_factor_code_hash'] = null;
                $security['two_factor_code_expires_at'] = null;
                return [
                    'sent' => false,
                    'method' => 'phone',
                    'message' => 'SMS delivery is not configured. Configure SMS OTP settings in the admin dashboard or switch to email verification.',
                ];
            }

            $method = 'email';
            $security['two_factor_method'] = 'email';
            $security['two_factor_contact'] = null;
        } elseif ($method === 'email' && ! $this->isTwoFactorEmailConfigured()) {
            $security['two_factor_code_hash'] = null;
            $security['two_factor_code_expires_at'] = null;
            return [
                'sent' => false,
                'method' => 'email',
                'message' => 'Email delivery is unavailable. Configure MAIL_* settings and try again.',
            ];
        }

        $sent = false;
        if ($method === 'phone') {
            $sent = $this->sendTwoFactorPhoneCode($contact, $challengeCode);

            if (! $sent && $requestedMethod === 'phone' && $this->isTwoFactorEmailConfigured()) {
                $method = 'email';
                $security['two_factor_method'] = 'email';
                $security['two_factor_contact'] = null;
                $sent = $this->sendTwoFactorEmailCode($user, $challengeCode);
            }
        } else {
            $sent = $this->sendTwoFactorEmailCode($user, $challengeCode);
        }

        if (! $sent) {
            $security['two_factor_code_hash'] = null;
            $security['two_factor_code_expires_at'] = null;

            return [
                'sent' => false,
                'method' => $method,
                'message' => $this->twoFactorDeliveryFailureMessage($method),
            ];
        }

        return [
            'sent' => true,
            'method' => $method,
            'message' => $this->twoFactorChallengeMessage($method),
        ];
    }

    private function sendTwoFactorEmailCode(User $user, string $code): bool
    {
        return $this->sendRegistrationEmailCode($user->email, $code);
    }

    private function sendRegistrationEmailCode(string $email, string $code, int $expiryMinutes = 10): bool
    {
        $user = User::query()->where('email', $email)->first(['name']);
        $template = EmailTemplates::render('verification_code', [
            'USERNAME' => $user?->name ?: Str::before($email, '@'),
            'USER_EMAIL' => $email,
            'VERIFICATION_CODE' => $code,
            'EXPIRY_MINUTES' => $expiryMinutes,
        ], request()->header('X-PlayHub-Language'));

        return $this->sendPlainEmail($email, $template['subject'], $template['body'], $template['locale']);
    }

    private function normalizeSocialProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        abort_unless(in_array($provider, ['google', 'facebook', 'apple'], true), 404);

        return $provider;
    }

    private function socialStateKey(string $state): string
    {
        return 'playhub-social-state:'.$state;
    }

    private function socialHandoffKey(string $code, ?string $codeChallenge = null): string
    {
        return 'playhub-social-handoff:'.$code.($codeChallenge
            ? ':'.hash('sha256', $codeChallenge)
            : '');
    }

    private function consumeCachedValue(string $key): mixed
    {
        try {
            return Cache::lock('playhub-cache-consume:'.hash('sha256', $key), 10)
                ->block(5, fn (): mixed => Cache::pull($key));
        } catch (LockTimeoutException) {
            return null;
        }
    }

    private function registrationConfirmationAttemptKey(string $email): string
    {
        return 'playhub-registration-confirm:'.hash('sha256', strtolower(trim($email)));
    }

    private function socialRedirectUri(string $provider): string
    {
        return url('/api/auth/social/'.$provider.'/callback');
    }

    private function socialReturnPath(string $intent, array $query): string
    {
        $path = $intent === 'register' ? '/register' : '/login';

        return $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function socialErrorRedirect(string $intent, string $message): RedirectResponse
    {
        return redirect()->to($this->socialReturnPath($intent, ['social_auth_error' => $message]));
    }

    private function socialCompletionRedirect(array $stateData, string $intent, array $query): RedirectResponse
    {
        $returnTo = trim((string) ($stateData['return_to'] ?? ''));
        if ($returnTo !== '' && in_array($returnTo, (array) config('native_auth.social_return_uris', []), true)) {
            return redirect()->away($returnTo.(str_contains($returnTo, '?') ? '&' : '?')
                .http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        }

        return redirect()->to($this->socialReturnPath($intent, $query));
    }

    private function googleSocialProfile(Request $request, array $authSettings): array
    {
        $config = AuthSettings::socialProviderSettings('google', $authSettings);
        if (! AuthSettings::socialProviderEnabled('google', $authSettings)) {
            throw new \RuntimeException('Google Sign-In is not configured.');
        }

        $code = trim((string) $request->input('code', $request->query('code', '')));
        if ($code === '') {
            throw new \RuntimeException('Unable to finish social sign-in.');
        }

        $tokenResponse = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => trim((string) ($config['clientId'] ?? '')),
            'client_secret' => trim((string) ($config['clientSecret'] ?? '')),
            'redirect_uri' => $this->socialRedirectUri('google'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $tokenResponse->successful() || ! data_get($tokenResponse->json(), 'access_token')) {
            throw new \RuntimeException('Unable to verify Google sign-in.');
        }

        $profileResponse = Http::withToken((string) data_get($tokenResponse->json(), 'access_token'))
            ->acceptJson()
            ->timeout(15)
            ->get('https://openidconnect.googleapis.com/v1/userinfo');

        if (! $profileResponse->successful()) {
            throw new \RuntimeException('Unable to read your Google account details.');
        }

        $profile = $profileResponse->json();
        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        $verified = filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($email === '' || ! $verified) {
            throw new \RuntimeException('Google did not return a verified email address.');
        }

        return [
            'id' => (string) ($profile['sub'] ?? ''),
            'email' => $email,
            'email_verified' => $verified,
            'name' => trim((string) ($profile['name'] ?? '')),
            'avatar_url' => trim((string) ($profile['picture'] ?? '')),
        ];
    }

    private function facebookSocialProfile(Request $request, array $authSettings): array
    {
        $config = AuthSettings::socialProviderSettings('facebook', $authSettings);
        if (! AuthSettings::socialProviderEnabled('facebook', $authSettings)) {
            throw new \RuntimeException('Facebook Login is not configured.');
        }

        $code = trim((string) $request->input('code', $request->query('code', '')));
        if ($code === '') {
            throw new \RuntimeException('Unable to finish social sign-in.');
        }

        $tokenResponse = Http::timeout(15)->get('https://graph.facebook.com/oauth/access_token', [
            'client_id' => trim((string) ($config['clientId'] ?? '')),
            'client_secret' => trim((string) ($config['clientSecret'] ?? '')),
            'redirect_uri' => $this->socialRedirectUri('facebook'),
            'code' => $code,
        ]);

        if (! $tokenResponse->successful() || ! data_get($tokenResponse->json(), 'access_token')) {
            throw new \RuntimeException('Unable to verify Facebook login.');
        }

        $profileResponse = Http::timeout(15)->get('https://graph.facebook.com/me', [
            'fields' => 'id,name,email,picture.type(large)',
            'access_token' => (string) data_get($tokenResponse->json(), 'access_token'),
        ]);

        if (! $profileResponse->successful()) {
            throw new \RuntimeException('Unable to read your Facebook account details.');
        }

        $profile = $profileResponse->json();
        $email = strtolower(trim((string) ($profile['email'] ?? '')));

        if ($email === '') {
            throw new \RuntimeException('Facebook did not return an email address.');
        }

        return [
            'id' => (string) ($profile['id'] ?? ''),
            'email' => $email,
            'email_verified' => true,
            'name' => trim((string) ($profile['name'] ?? '')),
            'avatar_url' => trim((string) data_get($profile, 'picture.data.url', '')),
        ];
    }

    private function appleSocialProfile(Request $request, array $authSettings): array
    {
        $config = AuthSettings::socialProviderSettings('apple', $authSettings);
        if (! AuthSettings::socialProviderEnabled('apple', $authSettings)) {
            throw new \RuntimeException('Apple Sign-In is not configured.');
        }

        $code = trim((string) $request->input('code', $request->query('code', '')));
        if ($code === '') {
            throw new \RuntimeException('Unable to finish social sign-in.');
        }

        $clientId = trim((string) ($config['clientId'] ?? ''));
        $tokenResponse = Http::asForm()->timeout(15)->post('https://appleid.apple.com/auth/token', [
            'client_id' => $clientId,
            'client_secret' => $this->appleClientSecret($config),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->socialRedirectUri('apple'),
        ]);

        if (! $tokenResponse->successful() || ! data_get($tokenResponse->json(), 'id_token')) {
            throw new \RuntimeException('Unable to verify Apple sign-in.');
        }

        $claims = $this->decodeJwtPayload((string) data_get($tokenResponse->json(), 'id_token'));
        if (($claims['iss'] ?? '') !== 'https://appleid.apple.com' || ($claims['aud'] ?? '') !== $clientId || (int) ($claims['exp'] ?? 0) <= time()) {
            throw new \RuntimeException('Unable to verify Apple sign-in.');
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = ! array_key_exists('email_verified', $claims) || filter_var($claims['email_verified'], FILTER_VALIDATE_BOOLEAN);
        if ($email !== '' && ! $verified) {
            throw new \RuntimeException('Apple did not return a verified email address.');
        }

        return [
            'id' => (string) ($claims['sub'] ?? ''),
            'email' => $email,
            'email_verified' => $verified,
            'name' => $this->appleNameFromRequest($request),
            'avatar_url' => '',
        ];
    }

    private function userForSocialProfile(string $provider, array $profile, Request $request, array $authSettings): User
    {
        $providerId = trim((string) ($profile['id'] ?? ''));
        if ($providerId === '') {
            throw new \RuntimeException('Unable to finish social sign-in.');
        }

        $providerLabel = ucfirst($provider);
        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        $emailVerified = (bool) ($profile['email_verified'] ?? false);
        $user = User::query()->where("settings->auth_providers->{$provider}->id", $providerId)->first();

        if (! $user && $email !== '') {
            if (! $emailVerified) {
                throw new \RuntimeException($providerLabel.' did not return a verified email address.');
            }
            $user = User::query()->where('email', $email)->first();
        }

        if (! $user) {
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $emailVerified) {
                throw new \RuntimeException($providerLabel.' did not return a verified email address.');
            }
            if ($message = AuthSettings::registrationDeniedMessage($request->ip())) {
                throw new \RuntimeException($message);
            }

            $registrationIp = $this->registrationIp($request);
            $user = DB::transaction(function () use ($provider, $providerId, $profile, $email, $registrationIp): User {
                $name = trim((string) ($profile['name'] ?? '')) ?: Str::before($email, '@') ?: ucfirst($provider).' User';
                $user = User::query()->create([
                    'name' => $name,
                    'username' => Slugs::unique($name, User::class, 'username'),
                    'email' => $email,
                    'password' => Str::random(64),
                    'registration_ip' => $registrationIp,
                    'registration_provider' => $provider,
                    'settings' => [
                        'role' => AuthSettings::registrationDefaultRole(),
                        'auth_providers' => [$provider => $this->socialProviderRecord($providerId, $profile)],
                        'registration_provider' => $provider,
                    ],
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                Channel::query()->create([
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'slug' => Slugs::unique($user->username, Channel::class),
                    'bio' => null,
                ]);

                return $user;
            });
        }

        $settings = is_array($user->settings) ? $user->settings : [];
        $providers = is_array($settings['auth_providers'] ?? null) ? $settings['auth_providers'] : [];
        $providers[$provider] = $this->socialProviderRecord($providerId, $profile, is_array($providers[$provider] ?? null) ? $providers[$provider] : []);
        $settings['auth_providers'] = $providers;
        $user->update(['settings' => $settings]);

        if (! $user->email_verified_at && $email !== '' && strtolower((string) $user->email) === $email && $emailVerified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->loadMissing('channel');
        if (! $user->channel) {
            Channel::query()->create([
                'user_id' => $user->id,
                'name' => $user->name,
                'slug' => Slugs::unique($user->username, Channel::class),
                'bio' => null,
            ]);
            $user->load('channel');
        }

        return $user;
    }

    private function socialProviderRecord(string $providerId, array $profile, array $current = []): array
    {
        return array_filter([
            'id' => $providerId,
            'email' => trim((string) ($profile['email'] ?? '')) ?: ($current['email'] ?? null),
            'name' => trim((string) ($profile['name'] ?? '')) ?: ($current['name'] ?? null),
            'avatar_url' => trim((string) ($profile['avatar_url'] ?? '')) ?: ($current['avatar_url'] ?? null),
            'linked_at' => now()->toIso8601String(),
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    private function ensureSocialUserCanLogin(User $user): void
    {
        $settings = is_array($user->settings) ? $user->settings : [];
        $status = strtolower(str_replace(['_', '-'], ' ', trim((string) ($settings['status'] ?? 'active'))));
        if (in_array($status, ['suspended', 'deleted'], true)) {
            throw new \RuntimeException('This account has been suspended. Contact support if you believe this is a mistake.');
        }
        if ($user->isBannedFrom('account')) {
            throw new \RuntimeException($user->banMessage('account'));
        }
    }

    private function appleNameFromRequest(Request $request): string
    {
        $payload = $request->input('user');
        $payload = is_string($payload) ? json_decode($payload, true) : $payload;
        if (! is_array($payload)) {
            return '';
        }

        $name = is_array($payload['name'] ?? null) ? $payload['name'] : [];

        return trim(implode(' ', array_filter([
            $name['firstName'] ?? '',
            $name['lastName'] ?? '',
        ])));
    }

    private function appleClientSecret(array $config): string
    {
        $clientId = trim((string) ($config['clientId'] ?? ''));
        $teamId = trim((string) ($config['teamId'] ?? ''));
        $keyId = trim((string) ($config['keyId'] ?? ''));
        $privateKey = str_replace('\\n', "\n", trim((string) ($config['privateKey'] ?? '')));
        $issuedAt = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'ES256', 'kid' => $keyId], JSON_UNESCAPED_SLASHES));
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $teamId,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 86400 * 180,
            'aud' => 'https://appleid.apple.com',
            'sub' => $clientId,
        ], JSON_UNESCAPED_SLASHES));
        $unsigned = $header.'.'.$payload;
        $key = openssl_pkey_get_private($privateKey);

        if (! $key || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Apple private key is invalid.');
        }

        return $unsigned.'.'.$this->base64UrlEncode($this->derSignatureToJose($signature));
    }

    private function decodeJwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            throw new \RuntimeException('Unable to finish social sign-in.');
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/').str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        $payload = $json ? json_decode($json, true) : null;

        if (! is_array($payload)) {
            throw new \RuntimeException('Unable to finish social sign-in.');
        }

        return $payload;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function derSignatureToJose(string $signature): string
    {
        $offset = 0;
        if (ord($signature[$offset++] ?? "\0") !== 0x30) {
            throw new \RuntimeException('Apple private key is invalid.');
        }
        $this->readDerLength($signature, $offset);
        if (ord($signature[$offset++] ?? "\0") !== 0x02) {
            throw new \RuntimeException('Apple private key is invalid.');
        }
        $rLength = $this->readDerLength($signature, $offset);
        $r = substr($signature, $offset, $rLength);
        $offset += $rLength;
        if (ord($signature[$offset++] ?? "\0") !== 0x02) {
            throw new \RuntimeException('Apple private key is invalid.');
        }
        $sLength = $this->readDerLength($signature, $offset);
        $s = substr($signature, $offset, $sLength);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT).str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    private function readDerLength(string $der, int &$offset): int
    {
        $length = ord($der[$offset++] ?? "\0");
        if ($length < 0x80) {
            return $length;
        }

        $bytes = $length & 0x7f;
        $length = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $length = ($length << 8) + ord($der[$offset++] ?? "\0");
        }

        return $length;
    }

    private function sendPasswordResetCode(string $email, string $code): bool
    {
        $user = User::query()->where('email', $email)->first(['name']);
        $template = EmailTemplates::render('password_reset_code', [
            'USERNAME' => $user?->name ?: Str::before($email, '@'),
            'USER_EMAIL' => $email,
            'RESET_CODE' => $code,
            'EXPIRY_MINUTES' => 15,
        ], request()->header('X-PlayHub-Language'));

        return $this->sendPlainEmail($email, $template['subject'], $template['body'], $template['locale']);
    }

    private function sendPlainEmail(string $email, string $subject, string $body, ?string $locale = null): bool
    {
        try {
            if ($this->configureEmailMailer()) {
                $fromAddress = $this->mailFromAddress();
                $fromName = $this->mailFromName();
                Mail::raw($body, function ($message) use ($email, $subject, $body, $fromAddress, $fromName, $locale): void {
                    BrandedEmail::apply($message, $subject, $body, $locale);
                    $message->to($email)->from($fromAddress, $fromName)->returnPath($fromAddress)->subject($subject);
                });

                return true;
            }
        } catch (\Throwable) {
            // Fall back to PHP's native mail() below when Laravel transport fails.
        }

        return $this->sendNativePhpMail($email, $subject, $body, $locale);
    }

    private function sendNativePhpMail(string $email, string $subject, string $body, ?string $locale = null): bool
    {
        if (! function_exists('mail')) {
            return false;
        }

        $fromAddress = $this->mailFromAddress();
        $fromName = $this->mailFromName();
        $fromName = str_replace(["\r", "\n"], '', $fromName);
        $fromAddress = str_replace(["\r", "\n"], '', $fromAddress);
        $safeSubject = str_replace(["\r", "\n"], '', $subject);
        $parameters = preg_match('/^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/', $fromAddress) ? '-f'.$fromAddress : '';
        $boundary = 'playhub-'.bin2hex(random_bytes(12));
        $messageBody = implode("\r\n", [
            '--'.$boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode($body),
            '--'.$boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode(BrandedEmail::render($safeSubject, $body, $locale)),
            '--'.$boundary.'--',
        ]);

        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="'.$boundary.'"',
            'From: '.$fromName.' <'.$fromAddress.'>',
            'Return-Path: '.$fromAddress,
        ]);

        return $parameters !== ''
            ? mail($email, $safeSubject, $messageBody, $headers, $parameters)
            : mail($email, $safeSubject, $messageBody, $headers);
    }

    private function sendTwoFactorPhoneCode(string $phone, string $code): bool
    {
        if (IntegrationSettings::smsOtpEnabled()) {
            return IntegrationSettings::sendSmsOtpCode($phone, $code);
        }

        $accountSid = env('TWILIO_ACCOUNT_SID');
        $authToken = env('TWILIO_AUTH_TOKEN');
        $fromNumber = env('TWILIO_FROM_NUMBER');

        if (! $accountSid || ! $authToken || ! $fromNumber || ! $phone) {
            return false;
        }

        try {
            $appName = (string) config('app.name', 'PlayHub');
            $response = Http::withBasicAuth($accountSid, $authToken)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                    'From' => $fromNumber,
                    'To' => $phone,
                    'Body' => "Your {$appName} verification code is: {$code}",
                ]);

            return $response->successful();
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private function verifyTwoFactorCode(array $security, string $code): bool
    {
        if (! preg_match('/^\d{6}$/', trim($code))) {
            return false;
        }

        if (empty($security['two_factor_code_hash'] ?? null) || empty($security['two_factor_code_expires_at'] ?? null)) {
            return false;
        }

        if (strtotime((string) $security['two_factor_code_expires_at']) <= time()) {
            return false;
        }

        return Hash::check(trim($code), (string) $security['two_factor_code_hash']);
    }

    private function issueTwoFactorLoginChallenge(User $user): never
    {
        $lockKey = 'playhub-login-2fa-challenge:'.hash('sha256', (string) $user->getKey());

        try {
            Cache::lock($lockKey, 15)->block(5, function () use ($user): void {
                $lockedUser = User::query()->findOrFail($user->getKey());
                $settings = is_array($lockedUser->settings) ? $lockedUser->settings : [];
                $security = is_array($settings['security'] ?? null) ? $settings['security'] : [];
                $method = $this->normalizeTwoFactorMethod((string) ($security['two_factor_method'] ?? ''));

                if (! empty($security['two_factor_code_hash'])
                    && ! empty($security['two_factor_code_expires_at'])
                    && strtotime((string) $security['two_factor_code_expires_at']) > time()) {
                    throw ValidationException::withMessages([
                        'two_factor_code' => [$this->twoFactorChallengeMessage($method)],
                    ]);
                }

                $challenge = $this->sendTwoFactorChallenge(
                    $lockedUser,
                    $security,
                    $method,
                    (string) ($security['two_factor_contact'] ?? ''),
                );
                $settings['security'] = $security;
                $lockedUser->forceFill(['settings' => $settings])->save();

                throw ValidationException::withMessages([
                    'two_factor_code' => [
                        $challenge['sent']
                            ? (string) $challenge['message']
                            : (string) ($challenge['message'] ?? 'Two-factor is enabled, but a verification code could not be delivered.'),
                    ],
                ]);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'two_factor_code' => ['A verification challenge is already being prepared. Try again shortly.'],
            ]);
        }

        throw ValidationException::withMessages([
            'two_factor_code' => ['Enter the two-factor authentication code.'],
        ]);
    }

    private function consumeTwoFactorLoginCode(User $user, string $code, array $authSettings): User
    {
        $attemptKey = 'playhub-login-2fa:'.hash('sha256', (string) $user->getKey());
        $maximumAttempts = max(3, min(AuthSettings::maxLoginAttempts($authSettings), 10));
        $decaySeconds = max(60, AuthSettings::banSeconds($authSettings));

        if (RateLimiter::tooManyAttempts($attemptKey, $maximumAttempts)) {
            throw ValidationException::withMessages([
                'two_factor_code' => ['Too many invalid two-factor codes. Try again later.'],
            ]);
        }

        RateLimiter::hit($attemptKey, $decaySeconds);
        $attempts = RateLimiter::attempts($attemptKey);
        $outcome = DB::transaction(function () use ($user, $code, $attempts, $maximumAttempts): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $settings = is_array($lockedUser->settings) ? $lockedUser->settings : [];
            $security = is_array($settings['security'] ?? null) ? $settings['security'] : [];

            if (! $this->verifyTwoFactorCode($security, $code)) {
                if ($attempts >= $maximumAttempts) {
                    $security = $this->clearTwoFactorChallenge($security);
                    $security['two_factor_invalidated_at'] = now()->toIso8601String();
                    $settings['security'] = $security;
                    $lockedUser->forceFill(['settings' => $settings])->save();
                }

                return ['user' => null, 'valid' => false];
            }

            $security = $this->clearTwoFactorChallenge($security);
            unset($security['two_factor_invalidated_at']);
            $settings['security'] = $security;
            $lockedUser->forceFill(['settings' => $settings])->save();

            return ['user' => $lockedUser, 'valid' => true];
        }, 3);

        if (! $outcome['valid']) {
            throw ValidationException::withMessages([
                'two_factor_code' => [$attempts >= $maximumAttempts
                    ? 'Too many invalid two-factor codes. Request a new code later.'
                    : 'The two-factor authentication code is invalid.'],
            ]);
        }

        RateLimiter::clear($attemptKey);

        return $outcome['user'];
    }

    private function clearTwoFactorChallenge(array $security): array
    {
        $security['two_factor_code_hash'] = null;
        $security['two_factor_code_expires_at'] = null;

        return $security;
    }

    private function twoFactorChallengeMessage(string $method): string
    {
        return $method === 'phone'
            ? 'A 6-digit code has been sent to your phone. Enter it to continue.'
            : 'A 6-digit code has been sent to your email. Enter it to continue.';
    }

    private function twoFactorDeliveryFailureMessage(string $method): string
    {
        return $method === 'phone'
            ? 'SMS delivery is unavailable. Configure SMS OTP settings in the admin dashboard or switch to email verification.'
            : 'Email delivery is unavailable. Configure MAIL_* settings and try again.';
    }

    private function isTwoFactorEmailConfigured(): bool
    {
        return $this->configureEmailMailer() || function_exists('mail');
    }

    private function configureEmailMailer(): bool
    {
        if (IntegrationSettings::configureSmtpMailer()) {
            return true;
        }

        $driver = (string) config('mail.default', '');

        return in_array($driver, ['smtp', 'ses', 'mailgun', 'postmark', 'sendmail'], true)
            && trim((string) config('mail.from.address')) !== '';
    }

    private function mailFromAddress(): string
    {
        $settings = IntegrationSettings::settings();
        $fromAddress = trim((string) data_get($settings, 'smtp.fromEmail', config('mail.from.address')));
        if ($fromAddress === '' || str_ends_with($fromAddress, '@example.com')) {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: (request()->getHost() ?: 'localhost');
            $fromAddress = 'no-reply@'.$host;
        }

        return $fromAddress;
    }

    private function mailFromName(): string
    {
        $settings = IntegrationSettings::settings();
        $fromName = trim((string) data_get($settings, 'smtp.fromName', config('mail.from.name')));

        return $fromName !== '' && $fromName !== 'PlayHub' ? $fromName : (string) config('app.name', 'PlayHub');
    }

    private function isTwoFactorSmsConfigured(): bool
    {
        return IntegrationSettings::smsOtpEnabled() || (bool) (env('TWILIO_ACCOUNT_SID') && env('TWILIO_AUTH_TOKEN') && env('TWILIO_FROM_NUMBER'));
    }
}
