<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Context;

class UserContext
{
    protected ?array $user = null;
    protected mixed $id = null;
    protected ?string $username = null;
    protected ?string $email = null;
    protected array $data = [];

    /**
     * Set the current user context.
     */
    public function setUser(array|object|null $user): self
    {
        if ($user === null) {
            return $this->clearUser();
        }

        if (is_array($user)) {
            $this->id = $user['id'] ?? null;
            $this->username = $user['username'] ?? $user['name'] ?? null;
            $this->email = $user['email'] ?? null;
            $this->data = $user;
        } elseif (is_object($user)) {
            $this->id = $user->id ?? null;
            $this->username = $user->username ?? $user->name ?? null;
            $this->email = $user->email ?? null;
            $this->data = method_exists($user, 'toArray')
                ? $user->toArray()
                : get_object_vars($user);
        }

        $this->user = [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
        ];

        return $this;
    }

    /**
     * Clear the current user context.
     */
    public function clearUser(): self
    {
        $this->user = null;
        $this->id = null;
        $this->username = null;
        $this->email = null;
        $this->data = [];

        return $this;
    }

    /**
     * Get the current user context.
     */
    public function getUser(): ?array
    {
        return $this->user;
    }

    /**
     * Get the user ID.
     */
    public function getId(): mixed
    {
        return $this->id;
    }

    /**
     * Get the username.
     */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    /**
     * Get the user email.
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Get additional user data.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Set user IP address.
     */
    public function setIpAddress(?string $ip): self
    {
        if ($this->user !== null && $ip !== null) {
            $this->user['ip_address'] = $ip;
        }

        return $this;
    }

    /**
     * Set additional user data.
     */
    public function setExtra(array $extra): self
    {
        if ($this->user !== null) {
            $this->user['extra'] = $extra;
        }

        return $this;
    }
}
