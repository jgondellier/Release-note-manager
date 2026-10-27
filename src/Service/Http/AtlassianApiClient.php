<?php

declare(strict_types=1);

namespace App\Service\Http;

use App\Entity\ExternalConnection;
use App\Enum\AuthenticationType;
use App\Service\Security\SecretEncryptionException;
use App\Service\Security\SecretEncryptionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Client HTTP JSON pour les API REST Jira et Confluence (Cloud et Server/DC).
 *
 * - Authentification Basic (identifiant/e-mail + token) ou Bearer (PAT).
 * - Secret déchiffré à la volée, jamais journalisé ni inclus dans les erreurs.
 * - Délais, proxy et CA issus de HttpClientOptionsProvider.
 * - Erreurs HTTP/transport converties en ExternalApiException explicites.
 */
class AtlassianApiClient
{
    private const MAX_DETAIL_LENGTH = 300;

    public function __construct(
        #[Autowire(service: 'app.atlassian_http_client')]
        private readonly HttpClientInterface $httpClient,
        private readonly SecretEncryptionService $encryption,
        private readonly HttpClientOptionsProvider $optionsProvider,
        private readonly LoggerInterface $integrationLogger,
    ) {
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<mixed>
     */
    public function get(ExternalConnection $connection, string $path, array $query = []): array
    {
        return $this->request($connection, 'GET', $path, ['query' => array_filter($query, static fn ($v) => null !== $v)]);
    }

    /**
     * @param array<mixed> $json
     *
     * @return array<mixed>
     */
    public function post(ExternalConnection $connection, string $path, array $json): array
    {
        return $this->request($connection, 'POST', $path, ['json' => $json]);
    }

    /**
     * @param array<mixed> $json
     *
     * @return array<mixed>
     */
    public function put(ExternalConnection $connection, string $path, array $json): array
    {
        return $this->request($connection, 'PUT', $path, ['json' => $json]);
    }

    /**
     * @param array<string, mixed> $options options HttpClient (query, json…)
     *
     * @return array<mixed>
     */
    public function request(ExternalConnection $connection, string $method, string $path, array $options = []): array
    {
        [$response, $secret, $started] = $this->send($connection, $method, $path, $options);

        return $this->read($connection, $method, $path, $response, $secret, $started);
    }

    /**
     * GET en parallèle (même connexion) : les requêtes d'un lot partent ensemble, ce qui divise le temps
     * d'attente sans changer le nombre d'appels. Une erreur n'interrompt pas les autres requêtes.
     *
     * @param array<array-key, array{0: string, 1: array<string, scalar|null>}> $requests [chemin, paramètres] par clé
     *
     * @return array<array-key, array<mixed>|ExternalApiException> résultat (ou erreur) par clé, dans l'ordre demandé
     */
    public function getMany(ExternalConnection $connection, array $requests, int $concurrency = 6): array
    {
        $results = [];
        foreach (array_chunk($requests, max(1, $concurrency), true) as $chunk) {
            $pending = [];
            foreach ($chunk as $key => [$path, $query]) {
                try {
                    $pending[$key] = [$path, ...$this->send($connection, 'GET', $path, ['query' => array_filter($query, static fn ($v) => null !== $v)])];
                } catch (ExternalApiException $e) {
                    $results[$key] = $e;
                }
            }
            foreach ($pending as $key => [$path, $response, $secret, $started]) {
                try {
                    $results[$key] = $this->read($connection, 'GET', $path, $response, $secret, $started);
                } catch (ExternalApiException $e) {
                    $results[$key] = $e;
                }
            }
        }

        // Ordre d'origine conservé.
        return array_replace(array_intersect_key($requests, $results), $results);
    }

    /**
     * Prépare et envoie la requête (non bloquant : la réponse est lue par read()).
     *
     * @param array<string, mixed> $options
     *
     * @return array{0: ResponseInterface, 1: string, 2: float}
     */
    private function send(ExternalConnection $connection, string $method, string $path, array $options): array
    {
        if (!$connection->isActive()) {
            throw ExternalApiException::configuration(sprintf('La connexion « %s » est désactivée.', $connection->getName()));
        }
        if ('' === $connection->getBaseUrl()) {
            throw ExternalApiException::configuration(sprintf('La connexion « %s » n\'a pas d\'URL de base.', $connection->getName()));
        }

        $secret = $this->decryptSecret($connection);
        $url = $connection->getBaseUrl() . '/' . ltrim($path, '/');

        $options = array_replace($this->optionsProvider->getOptions(), $options);
        $options['headers'] = array_replace(
            ['Accept' => 'application/json', 'X-Atlassian-Token' => 'no-check'],
            $options['headers'] ?? []
        );
        if (AuthenticationType::Bearer === $connection->getAuthenticationType()) {
            $options['auth_bearer'] = $secret;
        } else {
            $options['auth_basic'] = [(string) $connection->getUsername(), $secret];
        }

        $started = microtime(true);
        try {
            return [$this->httpClient->request($method, $url, $options), $secret, $started];
        } catch (HttpClientException $e) {
            throw $this->transportError($connection, $method, $path, $e, $secret, $started);
        }
    }

    /** @return array<mixed> */
    private function read(ExternalConnection $connection, string $method, string $path, ResponseInterface $response, string $secret, float $started): array
    {
        try {
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $content = $response->getContent(false);
        } catch (HttpClientException $e) {
            throw $this->transportError($connection, $method, $path, $e, $secret, $started);
        }
        $this->log($status >= 400 ? 'warning' : 'info', $connection, $method, $path, $status, $started);

        $loginReason = strtoupper(implode(',', $headers['x-seraph-loginreason'] ?? []));
        if (str_contains($loginReason, 'AUTHENTICATION_DENIED')) {
            throw new ExternalApiException('Compte bloqué par un CAPTCHA côté Jira/Confluence : connectez-vous une fois via le navigateur pour le débloquer, ou utilisez un Personal Access Token.', ExternalApiException::AUTHENTICATION, $status);
        }

        if ($status >= 400) {
            throw $this->buildHttpError($connection, $status, $content, $secret);
        }

        if (204 === $status || '' === trim($content)) {
            return [];
        }

        $contentType = strtolower(implode(',', $headers['content-type'] ?? []));
        if (str_contains($contentType, 'text/html')) {
            throw new ExternalApiException(sprintf('Réponse HTML inattendue de « %s » : l\'URL de base est probablement incorrecte (ou une page de proxy/SSO a été renvoyée).', $connection->getName()), ExternalApiException::INVALID_RESPONSE, $status);
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ExternalApiException(sprintf('Réponse JSON invalide reçue de « %s ».', $connection->getName()), ExternalApiException::INVALID_RESPONSE, $status, $e);
        }
        if (!\is_array($data)) {
            throw new ExternalApiException(sprintf('Réponse inattendue reçue de « %s ».', $connection->getName()), ExternalApiException::INVALID_RESPONSE, $status);
        }

        return $data;
    }

    private function transportError(ExternalConnection $connection, string $method, string $path, HttpClientException $e, string $secret, float $started): ExternalApiException
    {
        if ($e instanceof TimeoutExceptionInterface) {
            $this->log('warning', $connection, $method, $path, null, $started, 'timeout');

            return new ExternalApiException(sprintf('Délai dépassé lors de l\'appel à « %s ». Vérifiez le réseau, le proxy ou augmentez APP_HTTP_TIMEOUT.', $connection->getName()), ExternalApiException::TIMEOUT, null, $e);
        }
        $isTimeout = str_contains(strtolower($e->getMessage()), 'timeout') || str_contains(strtolower($e->getMessage()), 'timed out');
        $this->log('warning', $connection, $method, $path, null, $started, $isTimeout ? 'timeout' : 'transport');
        if ($isTimeout) {
            return new ExternalApiException(sprintf('Délai dépassé lors de l\'appel à « %s ».', $connection->getName()), ExternalApiException::TIMEOUT, null, $e);
        }

        return new ExternalApiException(sprintf('Serveur « %s » injoignable (%s). Vérifiez l\'URL, le DNS, le proxy d\'entreprise et les certificats.', $connection->getName(), $this->sanitize($this->shortTransportReason($e->getMessage()), $secret)), ExternalApiException::UNREACHABLE, null, $e);
    }

    private function decryptSecret(ExternalConnection $connection): string
    {
        if (!$connection->hasSecret()) {
            throw ExternalApiException::configuration(sprintf('Aucun token/secret n\'est enregistré pour la connexion « %s ».', $connection->getName()));
        }
        try {
            return $this->encryption->decrypt((string) $connection->getEncryptedSecret());
        } catch (SecretEncryptionException $e) {
            throw ExternalApiException::configuration(sprintf('Secret de la connexion « %s » illisible : %s', $connection->getName(), $e->getMessage()));
        }
    }

    private function buildHttpError(ExternalConnection $connection, int $status, string $content, string $secret): ExternalApiException
    {
        $detail = $this->sanitize($this->extractApiMessage($content), $secret);
        $suffix = '' !== $detail ? ' Détail : ' . $detail : '';
        $name = $connection->getName();

        [$message, $category] = match (true) {
            401 === $status => [sprintf('Authentification refusée par « %s » (401). Vérifiez l\'identifiant, le token et la méthode d\'authentification.', $name), ExternalApiException::AUTHENTICATION],
            403 === $status => [sprintf('Droits insuffisants sur « %s » (403).', $name), ExternalApiException::FORBIDDEN],
            404 === $status => [sprintf('Ressource introuvable sur « %s » (404).', $name), ExternalApiException::NOT_FOUND],
            409 === $status => [sprintf('Conflit signalé par « %s » (409).', $name), ExternalApiException::CONFLICT],
            429 === $status => [sprintf('Trop de requêtes vers « %s » (429). Réessayez dans quelques instants.', $name), ExternalApiException::RATE_LIMITED],
            $status >= 500 => [sprintf('Le service « %s » est indisponible (%d).', $name, $status), ExternalApiException::UNAVAILABLE],
            default => [sprintf('Requête refusée par « %s » (%d).', $name, $status), ExternalApiException::BAD_REQUEST],
        };

        return new ExternalApiException($message . $suffix, $category, $status);
    }

    /** Extrait un message lisible des formats d'erreur Jira et Confluence (v1 et v2). */
    private function extractApiMessage(string $content): string
    {
        try {
            $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
        if (!\is_array($data)) {
            return '';
        }
        $messages = [];
        foreach ((array) ($data['errorMessages'] ?? []) as $m) {
            if (\is_string($m)) {
                $messages[] = $m;
            }
        }
        foreach ((array) ($data['errors'] ?? []) as $field => $m) {
            if (\is_string($m)) {
                $messages[] = \is_string($field) ? $field . ' : ' . $m : $m;
            } elseif (\is_array($m)) {
                $messages[] = (string) ($m['title'] ?? $m['detail'] ?? $m['code'] ?? '');
            }
        }
        if (isset($data['message']) && \is_string($data['message'])) {
            $messages[] = $data['message'];
        }

        return trim(implode(' ; ', array_filter($messages)));
    }

    private function sanitize(string $text, string $secret): string
    {
        if ('' !== $secret) {
            $text = str_replace($secret, '***', $text);
        }
        $text = (string) preg_replace('/\s+/', ' ', strip_tags($text));

        return mb_substr(trim($text), 0, self::MAX_DETAIL_LENGTH);
    }

    private function shortTransportReason(string $message): string
    {
        // Les messages cURL incluent l'URL ; on n'en garde que la cause.
        $message = (string) preg_replace('#https?://\S+#', '', $message);

        return trim($message, " .:\"'");
    }

    private function log(string $level, ExternalConnection $connection, string $method, string $path, ?int $status, float $started, ?string $error = null): void
    {
        $this->integrationLogger->log($level, 'Appel API externe', [
            'connection_id' => $connection->getId(),
            'connection' => $connection->getName(),
            'method' => $method,
            'path' => strtok($path, '?'),
            'status' => $status,
            'error' => $error,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }
}
