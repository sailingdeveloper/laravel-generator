<?php

namespace SailingDeveloper\LaravelGenerator\Trait;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230509
 *
 * @property string $ulid
 */
trait HasUlid
{
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Model $model): void {
            $model->ulid ??= static::generateNewUlid();
        });
    }

    public static function determineUlidPrefix(): string
    {
        return '';
    }

    public static function generateNewUlid(): string
    {
        return static::determineUlidPrefix() . Str::ulid();
    }
}
