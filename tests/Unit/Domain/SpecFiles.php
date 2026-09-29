<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

final class SpecFiles
{
    /**
     * @return array<mixed>
     */
    public static function schema(string $fileName): array
    {
        return self::decode('schemas/'.$fileName);
    }

    /**
     * @return array<mixed>
     */
    public static function fixture(string $fileName): array
    {
        return self::decode('fixtures/'.$fileName);
    }

    /**
     * @param string $pointer JSON pointer, for example `/$defs/AnchorKind/enum`
     */
    public static function schemaValue(string $fileName, string $pointer): mixed
    {
        $value = self::schema($fileName);
        foreach (\array_slice(explode('/', $pointer), 1) as $token) {
            $key = strtr($token, ['~1' => '/', '~0' => '~']);
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                throw new \RuntimeException($fileName.'#'.$pointer.' does not exist');
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $pathInSpecs): array
    {
        $path = \dirname(__DIR__, 3).'/docs/specs/'.$pathInSpecs;
        $json = file_get_contents($path);
        if (false === $json) {
            throw new \RuntimeException('Cannot read '.$path);
        }
        $document = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($document)) {
            throw new \RuntimeException($path.' is not a JSON object');
        }

        return $document;
    }
}
