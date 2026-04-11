<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Exception;

class ExceptionDataBag
{
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly array  $frames,
    ) {}

    /**
     * Build from a Throwable using the provided StacktraceBuilder.
     */
    public static function fromThrowable(\Throwable $e, StacktraceBuilder $builder): self
    {
        return new self(
            type:   get_class($e),
            value:  $e->getMessage(),
            frames: $builder->buildFromThrowable($e),
        );
    }
}
