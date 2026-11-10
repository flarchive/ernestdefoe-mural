<?php

namespace ErnestDefoe\Mural\Api;

use ErnestDefoe\Mural\Canvas;
use ErnestDefoe\Mural\Season;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/** The season a request names (?season=number), or the open one. */
trait Seasons
{
    private function season(ServerRequestInterface $request, Canvas $canvas, bool $withSnapshot = false): ?Season
    {
        $number = Arr::get($request->getQueryParams(), 'season');
        if ($number === null || $number === '') {
            return $canvas->current($withSnapshot);
        }
        $number = filter_var($number, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($number === false) {
            return null;
        }
        $query = Season::query()->where('number', $number);

        return $withSnapshot ? $query->first() : $query->first(Season::LIGHT);
    }

    /** @param array<string, mixed> $source */
    private static function int(array $source, string $key, ?int $default = null): ?int
    {
        $value = filter_var($source[$key] ?? null, FILTER_VALIDATE_INT);

        return $value === false ? $default : $value;
    }
}
