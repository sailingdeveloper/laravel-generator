<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use Nette\PhpGenerator\PhpNamespace;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
class EnumDefinition extends Definition
{
    public function __construct(
        string $name,
        public PhpNamespace $namespace,
        public EnumChoiceCollection $choices,
    ) {
        parent::__construct($name);
    }
}
