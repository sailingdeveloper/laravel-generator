<?php

namespace SailingDeveloper\LaravelGenerator\Lib;

use Exception;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230509
 */
class JsonLib
{
    /**
     * @return array<string, mixed>
     */
    public static function decodeFileToArray(string $fileName): array
    {
        $string = file_get_contents($fileName);

        if (is_string($string)) {
            return static::decodeArray($string);
        } else {
            throw new Exception(sprintf('Invalid file %s', $fileName));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeArray(string $jsonString): array
    {
        $array = json_decode($jsonString, associative: true);

        if (is_array($array)) {
            return $array;
        } else {
            throw new Exception(sprintf('Invalid JSON "%s": %s', $jsonString, json_last_error_msg()));
        }
    }

    public static function decodeFileToObject(string $fileName): object
    {
        $string = file_get_contents($fileName);

        if (is_string($string)) {
            return static::decodeObject($string);
        } else {
            throw new Exception(sprintf('Invalid file %s', $fileName));
        }
    }

    public static function decodeObject(string $jsonString): object
    {
        $object = json_decode($jsonString);

        if (is_object($object)) {
            return $object;
        } else {
            throw new Exception(sprintf('Invalid JSON "%s": %s', $jsonString, json_last_error_msg()));
        }
    }
}
