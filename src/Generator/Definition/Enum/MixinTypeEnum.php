<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition\Enum;

enum MixinTypeEnum: string
{
    case GEOLOCATION = 'GEOLOCATION';
    case REVIEW = 'REVIEW';
    case SOFT_DELETE = 'SOFT_DELETE';
}
