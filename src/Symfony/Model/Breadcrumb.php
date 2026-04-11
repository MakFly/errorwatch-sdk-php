<?php

namespace ErrorWatch\Symfony\Model;

/**
 * Represents a single breadcrumb for error tracking.
 *
 * Breadcrumbs track user actions leading up to an error.
 */
final class Breadcrumb
{
    public const TYPE_DEFAULT = 'default';
    public const TYPE_HTTP = 'http';
    public const TYPE_INFO = 'info';
    public const TYPE_ERROR = 'error';
    public const TYPE_NAVIGATION = 'navigation';
    public const TYPE_USER = 'user';

    public const CATEGORY_HTTP = 'http';
    public const CATEGORY_UI = 'ui';
    public const CATEGORY_NAVIGATION = 'navigation';
    public const CATEGORY_CONSOLE = 'console';
    public const CATEGORY_USER = 'user';
    public const CATEGORY_LOG = 'log';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $category,
        public readonly int $timestamp,
        public readonly ?string $type = null,
        public readonly ?string $level = null,
        public readonly ?string $message = null,
        public readonly array $data = [],
    ) {
    }

    /**
     * Create a breadcrumb for an HTTP request.
     */
    public static function http(
        string $method,
        string $url,
        int $statusCode = 0,
        ?string $message = null,
    ): self {
        return new self(
            category: self::CATEGORY_HTTP,
            timestamp: time(),
            type: self::TYPE_HTTP,
            message: $message ?? sprintf('%s %s', $method, $url),
            data: [
                'method' => $method,
                'url' => $url,
                'status_code' => $statusCode > 0 ? $statusCode : null,
            ]
        );
    }

    /**
     * Create a breadcrumb for a navigation event.
     */
    public static function navigation(string $from, string $to, ?string $message = null): self
    {
        return new self(
            category: self::CATEGORY_NAVIGATION,
            timestamp: time(),
            type: self::TYPE_NAVIGATION,
            message: $message ?? sprintf('Navigate from %s to %s', $from, $to),
            data: [
                'from' => $from,
                'to' => $to,
            ]
        );
    }

    /**
     * Create a breadcrumb for a user action.
     *
     * @param array<string, mixed> $data
     */
    public static function user(string $action, ?string $message = null, array $data = []): self
    {
        return new self(
            category: self::CATEGORY_USER,
            timestamp: time(),
            type: self::TYPE_USER,
            message: $message ?? $action,
            data: array_merge(['action' => $action], $data)
        );
    }

    /**
     * Create a breadcrumb for a log entry.
     *
     * @param array<string, mixed> $data
     */
    /**
     * Uses CATEGORY_CONSOLE because the server only accepts: ui, navigation, console, http, user.
     */
    public static function log(string $level, string $message, array $data = []): self
    {
        return new self(
            category: self::CATEGORY_CONSOLE,
            timestamp: time(),
            type: self::TYPE_INFO,
            level: $level,
            message: $message,
            data: $data,
        );
    }

    /**
     * Create a breadcrumb for a console command.
     *
     * @param array<string, mixed> $data
     */
    public static function console(string $message, array $data = []): self
    {
        return new self(
            category: self::CATEGORY_CONSOLE,
            timestamp: time(),
            type: self::TYPE_DEFAULT,
            message: $message,
            data: $data
        );
    }

    /**
     * Create a breadcrumb for a UI interaction.
     */
    public static function ui(string $element, string $action, ?string $message = null): self
    {
        return new self(
            category: self::CATEGORY_UI,
            timestamp: time(),
            type: self::TYPE_USER,
            message: $message ?? sprintf('%s on %s', $action, $element),
            data: [
                'element' => $element,
                'action' => $action,
            ]
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'timestamp' => $this->timestamp,
            'category' => $this->category,
            'type' => $this->type,
            'level' => $this->level,
            'message' => $this->message,
            'data' => $this->data ?: null,
        ], fn ($v) => null !== $v);
    }
}
