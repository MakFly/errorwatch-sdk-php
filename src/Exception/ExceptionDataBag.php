<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Exception;

class ExceptionDataBag
{
    /**
     * @param Frame[] $frames
     * @param list<array{type: string, value: string, stacktrace: array{frames: array<int, array<string, mixed>>}}>|null $values
     */
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly array  $frames,
        public readonly ?array $values = null,
        public readonly ?array $mechanism = null,
    ) {}

    /**
     * Build from a Throwable using the provided StacktraceBuilder.
     * Walks getPrevious() to populate Sentry-style exception.values[].
     */
    public static function fromThrowable(\Throwable $e, StacktraceBuilder $builder): self
    {
        $chain = [];
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            $frames = $builder->buildFromThrowable($cur);
            $chain[] = [
                'type'  => $cur::class,
                'value' => $cur->getMessage(),
                'frames' => $frames,
            ];
        }

        $top = $chain[0];
        $values = array_map(
            static fn (array $entry) => [
                'type' => $entry['type'],
                'value' => $entry['value'],
                'stacktrace' => [
                    'frames' => array_map(
                        static fn (Frame $f) => $f->toArray(),
                        $entry['frames'],
                    ),
                ],
            ],
            $chain,
        );

        return new self(
            type:   $top['type'],
            value:  $top['value'],
            frames: $top['frames'],
            values: count($values) > 1 ? $values : null,
            mechanism: ['type' => 'generic', 'handled' => false],
        );
    }
}
