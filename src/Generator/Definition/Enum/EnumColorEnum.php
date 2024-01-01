<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition\Enum;

enum EnumColorEnum: string
{
    case INFO = 'info';
    case SUCCESS = 'success';
    case WARNING = 'warning';
    case DANGER = 'danger';
}
