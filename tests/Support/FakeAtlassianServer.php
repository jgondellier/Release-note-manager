<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Faux serveur Jira/Confluence pour MockHttpClient : routage par méthode + motif d'URL,
 * enregistrement des requêtes reçues. Aucune instance réelle n'est contactée.
 */
final class FakeAtlassianServer
{
    /** @var list<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    /** @var list<array{method: string, url: string, json: mixed, headers: array<int, string>}> */
    public array $requests = [];

    /**
     * @param callable(array<string, mixed> $json, string $url, array<string, mixed> $query): (MockResponse|array<mixed>) $handler
     */
    public function on(string $method, string $pattern, callable $handler): self
    {
        // Les routes ajoutées en dernier sont prioritaires (surcharge dans un test).
        array_unshift($this->routes, ['method' => $method, 'pattern' => $pattern, 'handler' => $handler]);

        return $this;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $json = isset($options['body']) && \is_string($options['body']) && '' !== $options['body'] ? json_decode($options['body'], true) : null;
        $this->requests[] = ['method' => $method, 'url' => $url, 'json' => $json, 'headers' => $options['headers'] ?? []];
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && 1 === preg_match('#^' . $route['pattern'] . '$#', $path)) {
                $result = ($route['handler'])(\is_array($json) ? $json : [], $url, $query);
                if ($result instanceof MockResponse) {
                    return $result;
                }

                return self::json($result);
            }
        }

        return self::json(['errorMessages' => ['Route non simulée : ' . $method . ' ' . $path]], 404);
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /** @return list<array{method: string, url: string, json: mixed, headers: array<int, string>}> */
    public function requestsMatching(string $method, string $pathFragment): array
    {
        return array_values(array_filter($this->requests, static fn (array $r) => $r['method'] === $method && str_contains($r['url'], $pathFragment)));
    }
}
