<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
abstract class Definition
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
