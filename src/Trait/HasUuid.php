<?php

namespace SailingDeveloper\LaravelGenerator\Trait;

use Illuminate\Support\Str;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230326
 *
 * @property string $uuid
 */
trait HasUuid
{
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Model $model): void {
            $model->uuid ??= Str::uuid()->toString();
        });
    }
}
