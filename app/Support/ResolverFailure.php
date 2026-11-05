<?php

namespace App\Support;

final class ResolverFailure extends \RuntimeException
{
    public function __construct(public readonly string $category, string $message)
    {
        parent::__construct($message);
    }

    public static function fromOutput(string $output): self
    {
        // Only expose a stable category, never signed URLs, cookies, or command output.
        $text = strtolower($output);
        $category = match (true) {
            (bool) preg_match('/video unavailable|has been removed|private video|not available in your country|geo.restrict|unsupported url|copyright|account.*terminated|login required|sign in|members.only|age.restrict/', $text) => 'permanent',
            (bool) preg_match('/timed out|timeout|temporary|429|too many requests|5\d\d|network|connection|unable to resolve|dns|remote end closed/', $text) => 'transient',
            default => 'unknown',
        };
        return new self($category, 'Video source resolution failed ('.$category.').');
    }
}
