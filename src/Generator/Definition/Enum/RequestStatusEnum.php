<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition\Enum;

enum RequestStatusEnum: string
{
    case INCLUDE = 'include';
    case INCLUDE_CONDITIONALLY = 'include_conditionally';
    case EXCLUDE = 'exclude';
}
