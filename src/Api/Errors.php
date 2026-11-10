<?php

namespace ErnestDefoe\Mural\Api;

use ErnestDefoe\Mural\MuralException;
use Laminas\Diactoros\Response\JsonResponse;

/** Error answers in Flarum's shape; the browser turns each code into a sentence. */
final class Errors
{
    /** @param array<string, mixed> $meta */
    public static function make(int $status, string $code, array $meta = []): JsonResponse
    {
        $error = ['status' => (string) $status, 'code' => $code];
        if ($meta) {
            $error['meta'] = $meta;
        }

        return new JsonResponse(['errors' => [$error]], $status);
    }

    public static function from(MuralException $e): JsonResponse
    {
        return self::make($e->status, $e->errorCode, $e->meta);
    }
}
