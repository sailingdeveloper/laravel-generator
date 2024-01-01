<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition\Enum;

enum RequestTypeEnum: string
{
    case CREATE = 'Create';
    case UPDATE = 'Update';
}
