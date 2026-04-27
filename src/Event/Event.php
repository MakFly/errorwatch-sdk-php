<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Event;

use ErrorWatch\Sdk\Context\RuntimeContext;
use ErrorWatch\Sdk\Exception\ExceptionDataBag;
use ErrorWatch\Sdk\Options;

class Event
{
    private const SDK_NAME    = 'errorwatch-php';
    private const SDK_VERSION = '2.1.0';

    private string  $eventId;
    private string  $timestamp;
    private string  $platform  = 'php';
    private Severity $level    = Severity::ERROR;
    private ?string $message   = null;
    private ?ExceptionDataBag $exception = null;
    private ?string $environment = null;
    private ?string $release   = null;
    private ?string $serverName = null;
    private array   $tags      = [];
    private array   $extra     = [];
    private ?array  $user      = null;
    private ?array  $request   = null;
    private array   $breadcrumbs = [];
    private array   $contexts  = [];
    private ?array  $sdk       = null;
    private ?array  $frames    = null;
    private ?array  $fingerprint = null;

    private function __construct()
    {
        $this->eventId   = self::generateUuid();
        $this->timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $this->sdk       = ['name' => self::SDK_NAME, 'version' => self::SDK_VERSION];
        $this->contexts  = RuntimeContext::capture();
    }

    // -------------------------------------------------------------------------
    // Static constructors
    // -------------------------------------------------------------------------

    public static function fromException(\Throwable $e, Options $options): self
    {
        $self = new self();
        $self->level = Severity::ERROR;

        $builder   = new \ErrorWatch\Sdk\Exception\StacktraceBuilder(
            $options->getProjectRoot(),
        );
        $dataBag   = ExceptionDataBag::fromThrowable($e, $builder);

        $self->exception   = $dataBag;
        $self->frames      = array_map(
            static fn(\ErrorWatch\Sdk\Exception\Frame $f) => $f->toArray(),
            $dataBag->frames,
        );
        $self->message     = $e->getMessage();
        $self->environment = $options->getEnvironment();
        $self->release     = $options->getRelease();
        $self->serverName  = $options->getServerName();

        return $self;
    }

    public static function fromMessage(string $msg, Severity $level): self
    {
        $self           = new self();
        $self->message  = $msg;
        $self->level    = $level;

        return $self;
    }

    // -------------------------------------------------------------------------
    // Setters (called by Scope::applyToEvent)
    // -------------------------------------------------------------------------

    public function setEnvironment(string $env): self
    {
        $this->environment = $env;
        return $this;
    }

    public function setRelease(?string $release): self
    {
        $this->release = $release;
        return $this;
    }

    public function setServerName(string $name): self
    {
        $this->serverName = $name;
        return $this;
    }

    public function setTags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    public function setExtras(array $extra): self
    {
        $this->extra = array_merge($this->extra, $extra);
        return $this;
    }

    public function setUser(?array $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function setRequest(?array $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function setBreadcrumbs(array $breadcrumbs): self
    {
        $this->breadcrumbs = $breadcrumbs;
        return $this;
    }

    public function setLevel(Severity $level): self
    {
        $this->level = $level;
        return $this;
    }

    public function setFingerprint(?array $fingerprint): self
    {
        $this->fingerprint = $fingerprint;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getEventId(): string
    {
        return $this->eventId;
    }

    // -------------------------------------------------------------------------
    // Serialisation
    // -------------------------------------------------------------------------

    /**
     * Build the v2 JSON payload array.
     */
    public function toPayload(): array
    {
        $payload = [
            'event_id'    => $this->eventId,
            'timestamp'   => $this->timestamp,
            'platform'    => $this->platform,
            'level'       => $this->level->value,
            'sdk'         => $this->sdk,
            'contexts'    => $this->contexts,
        ];

        if ($this->message !== null) {
            $payload['message'] = $this->message;
        }

        if ($this->exception !== null) {
            $payload['exception'] = [
                'type'  => $this->exception->type,
                'value' => $this->exception->value,
            ];
        }

        if ($this->frames !== null) {
            $payload['frames'] = $this->frames;
        }

        if ($this->environment !== null) {
            $payload['environment'] = $this->environment;
        }

        if ($this->release !== null) {
            $payload['release'] = $this->release;
        }

        if ($this->serverName !== null) {
            $payload['server_name'] = $this->serverName;
        }

        if (!empty($this->tags)) {
            $payload['tags'] = $this->tags;
        }

        if (!empty($this->extra)) {
            $payload['extra'] = $this->extra;
        }

        if ($this->user !== null) {
            $payload['user'] = $this->user;
        }

        if ($this->request !== null) {
            $payload['request'] = $this->request;
        }

        if (!empty($this->breadcrumbs)) {
            $payload['breadcrumbs'] = $this->breadcrumbs;
        }

        if ($this->fingerprint !== null) {
            $payload['fingerprint'] = $this->fingerprint;
        }

        return $payload;
    }

    // -------------------------------------------------------------------------

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
