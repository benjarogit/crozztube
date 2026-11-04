<?php

namespace App\Services\Push;

use RuntimeException;

// Only fixed codes cross this boundary. Never put tokens, credentials, provider
// response bodies, or previous HTTP exceptions in logs / failed queue payloads.
final class PushTransportException extends RuntimeException {}
