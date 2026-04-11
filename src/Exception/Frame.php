<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Exception;

class Frame
{
    public function __construct(
        public readonly string  $filename,
        public readonly ?string $function    = null,
        public readonly ?int    $lineno      = null,
        public readonly ?int    $colno       = null,
        public readonly bool    $inApp       = true,
        public readonly ?string $contextLine = null,
        public readonly ?array  $preContext  = null,
        public readonly ?array  $postContext = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'filename' => $this->filename,
            'in_app'   => $this->inApp,
        ];

        if ($this->function !== null) {
            $data['function'] = $this->function;
        }
        if ($this->lineno !== null) {
            $data['lineno'] = $this->lineno;
        }
        if ($this->colno !== null) {
            $data['colno'] = $this->colno;
        }
        if ($this->contextLine !== null) {
            $data['context_line'] = $this->contextLine;
        }
        if ($this->preContext !== null) {
            $data['pre_context'] = $this->preContext;
        }
        if ($this->postContext !== null) {
            $data['post_context'] = $this->postContext;
        }

        return $data;
    }
}
