<?php

namespace SailingDeveloper\LaravelGenerator\MoneyAmount\Cast;

use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use SailingDeveloper\LaravelGenerator\MoneyAmount\Object\MoneyAmount;

class MoneyAmountCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        $value = json_decode($value, associative: true);

        return Money::ofMinor($value['amount'], $value['currency']);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return [
            $key => json_encode(
                [
                    'currency' => $value->getCurrency()->getCurrencyCode(),
                    'amount' => $value->getMinorAmount()->toInt(),
                ],
            ),
        ];
    }
}
