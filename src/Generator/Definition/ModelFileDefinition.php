<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\ModelFileCollectionTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\ModelFileTypeEnum;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230826
 */
class ModelFileDefinition extends Definition
{
    public function __construct(
        string $name,
        public ModelDefinition $modelDefinition,
        public ModelFileTypeEnum $type,
        public ModelFileCollectionTypeEnum $collectionType,
    ) {
        parent::__construct($name);
    }
}
