<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\AdminFingerprintEnvVarProcessor;
use PHPUnit\Framework\TestCase;

final class AdminFingerprintEnvVarProcessorTest extends TestCase
{
    public function testFingerprintIsStableSecretFreeAndFollowsTheToken(): void
    {
        $processor = new AdminFingerprintEnvVarProcessor();
        $fingerprint = static fn (string $token) => $processor->getEnv('admin_fingerprint', 'ADMIN_TOKEN', static fn () => $token);

        self::assertSame($fingerprint('token-a'), $fingerprint('token-a'), 'Identique d\'un cache à l\'autre : sessions conservées');
        self::assertNotSame($fingerprint('token-a'), $fingerprint('token-b'), 'Token changé : sessions invalidées');
        self::assertStringNotContainsString('token-a', $fingerprint('token-a'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint('token-a'));
    }
}
