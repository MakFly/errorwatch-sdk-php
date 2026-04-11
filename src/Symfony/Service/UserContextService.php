<?php

namespace ErrorWatch\Symfony\Service;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Service for capturing user context for error attribution.
 *
 * Captures:
 * - User ID and email (when available via Symfony Security)
 * - IP address (optional, configurable)
 */
final class UserContextService
{
    private readonly bool $captureIp;

    public function __construct(
        private readonly ?Security $security,
        private readonly RequestStack $requestStack,
        bool $captureIp = true,
    ) {
        $this->captureIp = $captureIp;
    }

    /**
     * Get the current user context.
     *
     * @return array<string, mixed>|null
     */
    public function getContext(): ?array
    {
        $user = $this->getUser();
        $request = $this->requestStack->getCurrentRequest();

        if (!$user && !$request) {
            return null;
        }

        $context = [];

        // Add user information if available
        if (null !== $user) {
            $context['id'] = $this->getUserId($user);

            $email = $this->getUserEmail($user);
            if (null !== $email) {
                $context['email'] = $email;
            }

            $username = $this->getUsername($user);
            if (null !== $username) {
                $context['username'] = $username;
            }
        }

        // Add IP address if enabled and request available
        if (null !== $request && $this->captureIp) {
            $ip = $request->getClientIp();
            if (null !== $ip) {
                $context['ip_address'] = $ip;
            }
        }

        return $context ?: null;
    }

    /**
     * Get the current authenticated user.
     */
    private function getUser(): ?UserInterface
    {
        if (null === $this->security) {
            return null;
        }

        return $this->security->getUser();
    }

    /**
     * Get user identifier.
     */
    private function getUserId(UserInterface $user): string
    {
        return $user->getUserIdentifier();
    }

    /**
     * Get user email if available.
     */
    private function getUserEmail(UserInterface $user): ?string
    {
        // Check common email methods
        if (method_exists($user, 'getEmail')) {
            $email = $user->getEmail();

            return is_string($email) ? $email : null;
        }

        if (method_exists($user, 'getEmailAddress')) {
            $email = $user->getEmailAddress();

            return is_string($email) ? $email : null;
        }

        // Check if user identifier looks like an email
        $identifier = $user->getUserIdentifier();
        if (str_contains($identifier, '@')) {
            return $identifier;
        }

        return null;
    }

    /**
     * Get username if available.
     */
    private function getUsername(UserInterface $user): ?string
    {
        if (method_exists($user, 'getUsername')) {
            return $user->getUsername();
        }

        if (method_exists($user, 'getName')) {
            $name = $user->getName();

            return is_string($name) ? $name : null;
        }

        return null;
    }

    /**
     * Check if user context capture is available.
     */
    public function isAvailable(): bool
    {
        return null !== $this->security || null !== $this->requestStack->getCurrentRequest();
    }
}
