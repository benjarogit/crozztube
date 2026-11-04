<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserBlocks
{
    /** Call within a transaction; serializes a DM write against a committed block. */
    public static function lockPair(int $first, int $second): void
    {
        DB::table('users')->whereIn('id', [$first, $second])->orderBy('id')->lockForUpdate()->get(['id']);
    }

    public static function between(int $first, int $second): bool
    {
        if ($first <= 0 || $second <= 0 || $first === $second || ! Schema::hasTable('user_blocks')) return false;
        return DB::table('user_blocks')->where(fn ($q) => $q->where('user_id', $first)->where('blocked_user_id', $second))
            ->orWhere(fn ($q) => $q->where('user_id', $second)->where('blocked_user_id', $first))->exists();
    }

    /** A subquery avoids large ID lists and always observes blocks committed this request. */
    public static function filter(Builder|QueryBuilder $query, string $column, ?int $viewerId): Builder|QueryBuilder
    {
        if (! $viewerId || ! Schema::hasTable('user_blocks')) return $query;
        return $query->where(function ($q) use ($column, $viewerId): void {
            $q->whereNull($column)->orWhere(function ($visible) use ($column, $viewerId): void {
                $visible->whereNotIn($column, DB::table('user_blocks')->where('user_id', $viewerId)->select('blocked_user_id'))
                    ->whereNotIn($column, DB::table('user_blocks')->where('blocked_user_id', $viewerId)->select('user_id'));
            });
        });
    }

    /** Administrative review, reports and removal must not lose their targets. */
    public static function bypass(?Request $request = null): bool
    {
        $request ??= app()->bound('request') ? request() : null;
        if (! $request) return true;
        if ($request->is('api/admin/*', 'api/v1/admin/*') || $request->isMethod('DELETE')) return true;
        if (preg_match('#^api/(?:v1/)?account/blocks(?:/\d+)?$#', $request->path())) return true;
        $action = $request->route()?->getActionName() ?? '';
        $method = str_contains($action, '@') ? substr($action, strrpos($action, '@') + 1) : '';
        $controller = str_contains($action, '@') ? substr($action, 0, strrpos($action, '@')) : '';
        if ($controller === \App\Http\Controllers\Api\V1\SafetyController::class) return true;
        if ($controller === \App\Http\Controllers\Api\VideoController::class && $method === 'report') return true;
        if ($request->isMethod('POST') && preg_match('#^api/channels/[^/]+/report$#', $request->path())) return true;
        if (in_array($controller, [\App\Http\Controllers\Api\CommentController::class, \App\Http\Controllers\Api\V1\CommentController::class], true) && $method === 'report') return true;
        if ($controller === \App\Http\Controllers\Api\V1\EngagementController::class && in_array($method, ['reportVideo', 'reportProfile'], true)) return true;
        if ($controller === \App\Http\Controllers\Api\V1\CommentModerationController::class && $method === 'index') return true;
        return $controller === \App\Http\Controllers\Api\LiveStreamController::class
            && in_array($method, ['controlRoom', 'deleteMessage', 'restrictViewer', 'removeViewerRestriction', 'respondParticipantRequest', 'participantAction', 'pinMessage', 'addModerator', 'removeModerator'], true);
    }

    public static function currentViewer(): ?User
    {
        if (! app()->bound('request')) return null;
        $request = request();
        if (! $request->is('api/*') || self::bypass($request)) return null;
        return $request->user() ?: VideoSales::viewer($request);
    }

    public static function assertInteraction(?User $viewer, int $author): void
    {
        abort_if($viewer && self::between((int) $viewer->id, $author), 403, 'This interaction is unavailable.');
    }

    public static function canSee(?User $viewer, int $author): bool
    {
        return self::bypass() || ! $viewer || ! self::between((int) $viewer->id, $author);
    }
}
