<?php

namespace App\Support;

/** Raised when a captcha provider cannot answer, so the visitor is not silently passed. */
final class CaptchaVerificationUnavailable extends \RuntimeException {}
