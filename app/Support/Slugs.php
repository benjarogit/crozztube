<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Slugs
{
    public static function unique(string $source, string $modelClass, string $column = 'slug', ?int $ignoreId = null): string
    {
        /** @var class-string<Model> $modelClass */
        $base = Str::slug($source) ?: Str::lower(Str::random(8));
        $slug = $base;
        $counter = 2;

        while ($modelClass::query()
            ->where($column, $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
