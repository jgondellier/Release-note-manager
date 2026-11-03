<?php

declare(strict_types=1);

namespace App\Service\Http;

/**
 * Erreur d'appel à une API externe. getMessage() est un message en français,
 * exploitable par l'utilisateur, ne contenant ni secret ni trace technique.
 */
final class ExternalApiException extends \RuntimeException
{
    public const AUTHENTICATION = 'authentication';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const CONFLICT = 'conflict';
    public const BAD_REQUEST = 'bad_request';
    public const RATE_LIMITED = 'rate_limited';
    public const UNAVAILABLE = 'unavailable';
    public const TIMEOUT = 'timeout';
    public const UNREACHABLE = 'unreachable';
    public const INVALID_RESPONSE = 'invalid_response';
    public const CONFIGURATION = 'configuration';

    public function __construct(
        string $message,
        private readonly string $category,
        private readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
        private readonly string $apiDetail = '',
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /** Message complet renvoyé par l'API (non tronqué, sans secret), pour exploitation par le code. */
    public function getApiDetail(): string
    {
        return $this->apiDetail;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function isNotFound(): bool
    {
        return self::NOT_FOUND === $this->category;
    }

    public function isConflict(): bool
    {
        return self::CONFLICT === $this->category;
    }

    public static function configuration(string $message): self
    {
        return new self($message, self::CONFIGURATION);
    }
}
