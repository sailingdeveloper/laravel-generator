<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230511
 */
class NovaDefinition extends Definition
{
    public function __construct(
        string $name,
        public ?string $type = null,
        public ?string $help = null,
        public bool $shouldShowOnIndex = true,
        public bool $shouldShowOnDetail = true,
        public bool $shouldShowWhenCreating = true,
        public bool $shouldShowWhenUpdating = true,
    ) {
        parent::__construct($name);
    }
}
