<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition\Enum;

enum RelationTypeEnum: string
{
    case BELONGS_TO = 'BELONGS_TO';
    case HAS_MANY = 'HAS_MANY';
    case POLYMORPHIC = 'POLYMORPHIC';
}
