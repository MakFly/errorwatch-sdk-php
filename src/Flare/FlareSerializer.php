<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Flare;

/**
 * FlareSerializer
 *
 * Translates the SDK's internal envelope payload (produced by
 * {@see \ErrorWatch\Sdk\Event\Event::toPayload()}) into the Flare error
 * protocol shape expected by `POST /api/v1/errors`:
 * https://flareapp.io/docs/protocol/errors/payload
 *
 * Flat `attributes` (OpenTelemetry-style keys), a `stacktrace` of Flare
 * frames, `exceptionClass`, `seenAtUnixNano`, etc. It is the exact inverse of
 * the server-side `flareToEnvelope()` adapter.
 *
 * Pure and dependency-free so it can be unit-tested in isolation.
 */
final class FlareSerializer
{
    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    public static function fromEnvelope(array $envelope): array
    {
        $exception = is_array($envelope['exception'] ?? null) ? $envelope['exception'] : [];
        $message = $envelope['message'] ?? ($exception['value'] ?? null);

        return [
            'exceptionClass'     => $exception['type'] ?? null,
            'seenAtUnixNano'     => self::toUnixNano($envelope['timestamp'] ?? null),
            'message'            => $message,
            'code'               => null,
            'applicationPath'    => null,
            'openFrameIndex'     => null,
            'sourcemapVersionId' => null,
            'attributes'         => self::buildAttributes($envelope),
            'events'             => [],
            'stacktrace'         => self::buildStacktrace($envelope['frames'] ?? []),
            'trackingUuid'       => $envelope['event_id'] ?? null,
            'handled'            => $exception['mechanism']['handled'] ?? null,
        ];
    }

    /**
     * Flatten the envelope's nested context into Flare's flat attribute map,
     * using OpenTelemetry-style semantic keys.
     *
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private static function buildAttributes(array $envelope): array
    {
        $attributes = [];

        $direct = [
            'environment' => $envelope['environment'] ?? null,
            'release'     => $envelope['release'] ?? null,
            'server.name' => $envelope['server_name'] ?? null,
        ];
        foreach ($direct as $key => $value) {
            if ($value !== null && $value !== '') {
                $attributes[$key] = $value;
            }
        }

        $request = is_array($envelope['request'] ?? null) ? $envelope['request'] : [];
        if (isset($request['url'])) {
            $attributes['http.url'] = $request['url'];
        }
        if (isset($request['method'])) {
            $attributes['http.method'] = $request['method'];
        }
        if (isset($envelope['status_code'])) {
            $attributes['http.status_code'] = $envelope['status_code'];
        }

        $user = is_array($envelope['user'] ?? null) ? $envelope['user'] : [];
        $userMap = [
            'id'         => 'user.id',
            'email'      => 'user.email',
            'username'   => 'user.username',
            'ip_address' => 'client.address',
        ];
        foreach ($userMap as $from => $to) {
            if (isset($user[$from]) && $user[$from] !== '') {
                $attributes[$to] = $user[$from];
            }
        }

        // Tags are already a flat string map — carry them through verbatim.
        $tags = is_array($envelope['tags'] ?? null) ? $envelope['tags'] : [];
        foreach ($tags as $key => $value) {
            $attributes[(string) $key] = $value;
        }

        return $attributes;
    }

    /**
     * Map envelope frames (Sentry-style) to Flare stacktrace frames.
     *
     * @param array<int, array<string, mixed>> $frames
     * @return array<int, array<string, mixed>>
     */
    private static function buildStacktrace(array $frames): array
    {
        $out = [];
        foreach ($frames as $frame) {
            if (!is_array($frame)) {
                continue;
            }
            $line = isset($frame['lineno']) ? (int) $frame['lineno'] : 0;
            $out[] = [
                'file'               => $frame['filename'] ?? '',
                'lineNumber'         => $line,
                'columnNumber'       => $frame['colno'] ?? null,
                'method'             => $frame['function'] ?? null,
                'class'              => $frame['module'] ?? null,
                'isApplicationFrame' => (bool) ($frame['in_app'] ?? false),
                'codeSnippet'        => self::buildCodeSnippet($frame, $line),
            ];
        }

        return $out;
    }

    /**
     * Rebuild Flare's line-keyed codeSnippet from Sentry context_line / pre / post.
     *
     * @param array<string, mixed> $frame
     * @return array<string, string>|null
     */
    private static function buildCodeSnippet(array $frame, int $line): ?array
    {
        $pre = is_array($frame['pre_context'] ?? null) ? array_values($frame['pre_context']) : [];
        $post = is_array($frame['post_context'] ?? null) ? array_values($frame['post_context']) : [];
        $contextLine = $frame['context_line'] ?? null;

        if ($pre === [] && $post === [] && $contextLine === null) {
            return null;
        }

        $snippet = [];
        $start = $line - count($pre);
        foreach ($pre as $i => $code) {
            $snippet[(string) ($start + $i)] = $code;
        }
        if ($contextLine !== null) {
            $snippet[(string) $line] = $contextLine;
        }
        foreach ($post as $i => $code) {
            $snippet[(string) ($line + 1 + $i)] = $code;
        }

        return $snippet;
    }

    /**
     * Convert the envelope timestamp (ISO-8601 string, or numeric seconds/ms)
     * into Unix nanoseconds. Falls back to "now" when absent/unparseable.
     */
    private static function toUnixNano(mixed $timestamp): int
    {
        if (is_numeric($timestamp)) {
            $seconds = (float) $timestamp;
            if ($seconds > 1e12) {
                $seconds /= 1000.0; // milliseconds → seconds
            }
            return (int) round($seconds * 1e9);
        }

        if (is_string($timestamp) && $timestamp !== '') {
            try {
                $dt = new \DateTimeImmutable($timestamp);
                return (int) round(((float) $dt->format('U.u')) * 1e9);
            } catch (\Throwable) {
                // fall through to "now"
            }
        }

        return (int) round(microtime(true) * 1e9);
    }
}
