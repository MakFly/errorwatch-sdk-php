<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk;

use ErrorWatch\Sdk\Breadcrumb\Breadcrumb;
use ErrorWatch\Sdk\Breadcrumb\BreadcrumbBag;
use ErrorWatch\Sdk\Event\Event;
use ErrorWatch\Sdk\Event\Severity;

class Scope
{
    private ?array  $user        = null;
    private array   $tags        = [];
    private array   $extras      = [];
    private ?array  $request     = null;
    private ?array  $fingerprint = null;
    private ?array  $profile     = null;
    private ?Severity $level     = null;
    private BreadcrumbBag $breadcrumbBag;

    public function __construct(int $maxBreadcrumbs = 100)
    {
        $this->breadcrumbBag = new BreadcrumbBag($maxBreadcrumbs);
    }

    // -------------------------------------------------------------------------
    // Setters
    // -------------------------------------------------------------------------

    public function setUser(array $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function setTags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    public function setTag(string $key, string $value): self
    {
        $this->tags[$key] = $value;
        return $this;
    }

    public function setExtras(array $extras): self
    {
        $this->extras = array_merge($this->extras, $extras);
        return $this;
    }

    public function setExtra(string $key, mixed $value): self
    {
        $this->extras[$key] = $value;
        return $this;
    }

    public function setRequest(array $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function setProfile(?array $profile): self
    {
        $this->profile = $profile;
        return $this;
    }

    public function addBreadcrumb(Breadcrumb $breadcrumb): self
    {
        $this->breadcrumbBag->add($breadcrumb);
        return $this;
    }

    public function setFingerprint(array $fingerprint): self
    {
        $this->fingerprint = $fingerprint;
        return $this;
    }

    public function setLevel(Severity $level): self
    {
        $this->level = $level;
        return $this;
    }

    public function clear(): self
    {
        $this->user        = null;
        $this->tags        = [];
        $this->extras      = [];
        $this->request     = null;
        $this->fingerprint = null;
        $this->level       = null;
        $this->breadcrumbBag->clear();

        return $this;
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getUser(): ?array
    {
        return $this->user;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getExtras(): array
    {
        return $this->extras;
    }

    public function getRequest(): ?array
    {
        return $this->request;
    }

    public function getFingerprint(): ?array
    {
        return $this->fingerprint;
    }

    public function getLevel(): ?Severity
    {
        return $this->level;
    }

    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbBag->toArray();
    }

    // -------------------------------------------------------------------------
    // Apply to event
    // -------------------------------------------------------------------------

    /**
     * Merge scope context into an Event (scope wins over event defaults).
     */
    public function applyToEvent(Event $event): Event
    {
        if ($this->user !== null) {
            $event->setUser($this->user);
        }

        if (!empty($this->tags)) {
            $event->setTags($this->tags);
        }

        if (!empty($this->extras)) {
            $event->setExtras($this->extras);
        }

        if ($this->request !== null) {
            $event->setRequest($this->request);
        }

        if ($this->fingerprint !== null) {
            $event->setFingerprint($this->fingerprint);
        }

        if ($this->profile !== null) {
            $event->setProfile($this->profile);
        }

        if ($this->level !== null) {
            $event->setLevel($this->level);
        }

        $breadcrumbs = $this->breadcrumbBag->toArray();
        if (!empty($breadcrumbs)) {
            $event->setBreadcrumbs($breadcrumbs);
        }

        return $event;
    }
}
