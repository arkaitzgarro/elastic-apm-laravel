<?php

namespace AG\ElasticApmLaravel\Helpers;

use Illuminate\Config\Repository as Config;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StacktraceExtractor
{
    public static function getStacktrace(Config $config): array
    {
        $stackTrace = self::stripVendorTraces(
            collect(
                debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $config->get('elastic-apm-laravel.spans.backtraceDepth', 0))
            )
        );

        return $stackTrace->map(function ($trace) use ($config) {
            $sourceCode = self::getSourceCode($trace, $config);

            return [
                'function' => Arr::get($trace, 'class') . Arr::get($trace, 'type') . Arr::get($trace, 'function'),
                'abs_path' => Arr::get($trace, 'file'),
                'filename' => basename(Arr::get($trace, 'file')),
                'lineno' => Arr::get($trace, 'line', 0),
                'library_frame' => false,
                'pre_context' => optional($sourceCode->get('pre_context'))->toArray(),
                'context_line' => optional($sourceCode->get('context_line'))->first(),
                'post_context' => optional($sourceCode->get('post_context'))->toArray(),
            ];
        })->values()->toArray();
    }

    private static function getSourceCode(array $stackTrace, Config $config): Collection
    {
        if (false === $config->get('elastic-apm-laravel.spans.renderSource', false)) {
            return collect([]);
        }

        if (empty(Arr::get($stackTrace, 'file'))) {
            return collect([]);
        }

        $fileLines = file(Arr::get($stackTrace, 'file'));

        return collect($fileLines)->filter(function ($code, $line) use ($stackTrace) {
            // file starts counting from 0, debug_stacktrace from 1
            $stackTraceLine = Arr::get($stackTrace, 'line') - 1;

            $lineStart = $stackTraceLine - 5;
            $lineStop = $stackTraceLine + 5;

            return $line >= $lineStart && $line <= $lineStop;
        })->groupBy(function ($code, $line) use ($stackTrace) {
            if ($line < Arr::get($stackTrace, 'line') - 1) {
                return 'pre_context';
            }

            if ($line == Arr::get($stackTrace, 'line') - 1) {
                return 'context_line';
            }

            if ($line > Arr::get($stackTrace, 'line') - 1) {
                return 'post_context';
            }

            return 'trash';
        });
    }

    private static function stripVendorTraces(Collection $stackTrace): Collection
    {
        return $stackTrace->filter(function ($trace) {
            return !Str::startsWith(Arr::get($trace, 'file'), [
                base_path() . '/vendor',
            ]);
        });
    }
}
