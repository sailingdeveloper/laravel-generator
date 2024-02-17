<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition\Enum;

enum PropertyTypeEnum: string
{
    case ID = 'ID';
    case ULID = 'ULID';
    case TIMESTAMP = 'TIMESTAMP';
    case STRING = 'STRING';
    case TEXT = 'TEXT';
    case INTEGER = 'INTEGER';
    case ENUM = 'ENUM';
    case JSON_OBJECT = 'JSON_OBJECT';
    case JSON_ARRAY = 'JSON_ARRAY';
    case GEOLOCATION = 'GEOLOCATION';
    case POINT = 'POINT';
    case FILE = 'FILE';
    case FILE_COLLECTION = 'FILE[]';
    case IMAGE = 'IMAGE';
    case IMAGE_COLLECTION = 'IMAGE[]';
    case VIDEO = 'VIDEO';
    case VIDEO_COLLECTION = 'VIDEO[]';
    case ADDRESS = 'ADDRESS';
    case MONEY_AMOUNT = 'MONEY_AMOUNT';
}
