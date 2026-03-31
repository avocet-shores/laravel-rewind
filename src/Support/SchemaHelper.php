<?php

namespace AvocetShores\LaravelRewind\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * @internal Not part of the public API. Subject to change without notice.
 */
class SchemaHelper
{
    public static function modelHasCurrentVersionColumn(Model $model): bool
    {
        $cacheKey = sprintf('rewind:tables:%s:has_current_version', $model->getTable());

        if (Cache::has($cacheKey)) {
            return true;
        }

        $result = Schema::connection($model->getConnectionName())
            ->hasColumn($model->getTable(), 'current_version');

        if ($result) {
            Cache::put($cacheKey, true, now()->addMonth());
        }

        return $result;
    }
}
