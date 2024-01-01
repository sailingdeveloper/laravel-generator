<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230803
 */
class TableDefinition extends Definition
{
    /**
     * @param array<int, string> $allUniqueIndexName
     */
    public function __construct(
        string $name,
        public array $allUniqueIndexName,
        public bool $shouldLogActivity,
    ) {
        parent::__construct($name);
    }
}
