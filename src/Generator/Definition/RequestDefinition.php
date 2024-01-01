<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RequestStatusEnum;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
class RequestDefinition extends Definition
{
    public function __construct(
        string $name,
        public bool $isRequired,
        public RequestStatusEnum $getStatus = RequestStatusEnum::INCLUDE,
        public RequestStatusEnum $createStatus = RequestStatusEnum::INCLUDE,
        public RequestStatusEnum $updateStatus = RequestStatusEnum::INCLUDE,
    ) {
        parent::__construct($name);
    }
}
