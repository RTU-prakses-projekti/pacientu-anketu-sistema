<?php

namespace App\Models\Concerns;

use LogicException;

trait ProtectsPublishedVersion
{
    protected static function bootProtectsPublishedVersion(): void
    {
        $guard = function ($model): void {
            if ($model->immutableVersion()?->status !== 'draft') {
                throw new LogicException('Components of a published form version are immutable.');
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }
}
