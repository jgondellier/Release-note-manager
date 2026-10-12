<?php

declare(strict_types=1);

namespace App\Service\Http;

/**
 * Options HTTP communes aux appels Jira/Confluence : proxy d'entreprise,
 * autorité de certification, vérification TLS et délais.
 *
 * Les valeurs vides sont ignorées : HttpClient retombe alors sur les variables
 * système (HTTPS_PROXY, NO_PROXY) et le magasin de certificats de PHP.
 */
final class HttpClientOptionsProvider
{
    public function __construct(
        private readonly string $proxy,
        private readonly string $noProxy,
        private readonly string $caFile,
        private readonly bool $verifyPeer,
        private readonly float $timeout,
        private readonly float $maxDuration,
    ) {
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        $options = [
            'timeout' => $this->timeout > 0 ? $this->timeout : 15.0,
            'max_duration' => $this->maxDuration > 0 ? $this->maxDuration : 60.0,
            'verify_peer' => $this->verifyPeer,
            'verify_host' => $this->verifyPeer,
            'max_redirects' => 3,
        ];
        if ('' !== trim($this->proxy)) {
            $options['proxy'] = trim($this->proxy);
        }
        if ('' !== trim($this->noProxy)) {
            $options['no_proxy'] = trim($this->noProxy);
        }
        if ('' !== trim($this->caFile)) {
            $options['cafile'] = trim($this->caFile);
        }

        return $options;
    }

    /** Description non sensible de la configuration (diagnostic), sans identifiants de proxy. */
    public function describe(): string
    {
        $proxy = '' === trim($this->proxy) ? 'aucun (variables système éventuelles)' : (string) preg_replace('#//[^@/]+@#', '//***@', trim($this->proxy));

        return sprintf('proxy : %s ; vérification TLS : %s ; délai : %ss', $proxy, $this->verifyPeer ? 'oui' : 'NON', $this->timeout);
    }
}
