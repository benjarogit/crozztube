<?php

namespace App\Support\Diagnostics;

use Closure;
use Illuminate\Http\Request;

/** Delegates unchanged middleware behavior, excluding its downstream span. */
final class ObservedProbeMiddleware
{
    private readonly Closure $clock;

    public function __construct(private readonly object $original, private readonly string $name, ?Closure $clock = null)
    {
        $this->clock = $clock ?? fn (): int => hrtime(true);
    }

    public function handle(Request $request, Closure $next, mixed ...$parameters): mixed
    {
        $timing = LiveProbeTiming::current($request);
        if (! $timing) {
            return $this->original->handle($request, $next, ...$parameters);
        }

        $started = ($this->clock)();
        $downstream = 0;
        try {
            return $this->original->handle($request, function (Request $request) use ($next, &$downstream): mixed {
                $started = ($this->clock)();
                try {
                    return $next($request);
                } finally {
                    $downstream += ($this->clock)() - $started;
                }
            }, ...$parameters);
        } finally {
            $timing->record($this->name, max(0, ($this->clock)() - $started - $downstream) / 1e6);
        }
    }
}
