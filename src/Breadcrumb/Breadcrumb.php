<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Breadcrumb;

use ErrorWatch\Sdk\Event\Severity;

class Breadcrumb
{
    public readonly string $timestamp;

    public function __construct(
        public readonly string   $category,
        public readonly ?string  $message   = null,
        public readonly ?string  $type      = null,
        public readonly Severity $level     = Severity::INFO,
        public readonly array    $data      = [],
        ?string $timestamp                  = null,
    ) {
        $this->timestamp = $timestamp ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }

    // -------------------------------------------------------------------------
    // Factory methods
    // -------------------------------------------------------------------------

    public static function http(string $method, string $url, int $statusCode): self
    {
        return new self(
            category: 'http',
            message:  sprintf('%s %s → %d', strtoupper($method), $url, $statusCode),
            type:     'http',
            level:    $statusCode >= 400 ? Severity::ERROR : Severity::INFO,
            data:     [
                'method'      => strtoupper($method),
                'url'         => $url,
                'status_code' => $statusCode,
            ],
        );
    }

    public static function navigation(string $from, string $to): self
    {
        return new self(
            category: 'navigation',
            message:  sprintf('Navigated from %s to %s', $from, $to),
            type:     'navigation',
            level:    Severity::INFO,
            data:     ['from' => $from, 'to' => $to],
        );
    }

    public static function query(string $sql, float $durationMs): self
    {
        return new self(
            category: 'db.query',
            message:  $sql,
            type:     'query',
            level:    Severity::INFO,
            data:     ['duration_ms' => $durationMs],
        );
    }

    public static function log(string $message, Severity $level = Severity::INFO): self
    {
        return new self(
            category: 'log',
            message:  $message,
            type:     'log',
            level:    $level,
        );
    }

    // -------------------------------------------------------------------------

    public function toArray(): array
    {
        $data = [
            'timestamp' => $this->timestamp,
            'category'  => $this->category,
            'level'     => $this->level->value,
        ];

        if ($this->message !== null) {
            $data['message'] = $this->message;
        }
        if ($this->type !== null) {
            $data['type'] = $this->type;
        }
        if (!empty($this->data)) {
            $data['data'] = $this->data;
        }

        return $data;
    }
}
