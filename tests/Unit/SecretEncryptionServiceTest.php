<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Security\SecretEncryptionException;
use App\Service\Security\SecretEncryptionService;
use PHPUnit\Framework\TestCase;

final class SecretEncryptionServiceTest extends TestCase
{
    public function testEncryptThenDecryptReturnsOriginalSecret(): void
    {
        $service = new SecretEncryptionService(SecretEncryptionService::generateKey());
        $cipher = $service->encrypt('ATATT3x-mon-token');

        self::assertStringStartsWith('v1:', $cipher);
        self::assertStringNotContainsString('mon-token', $cipher);
        self::assertSame('ATATT3x-mon-token', $service->decrypt($cipher));
    }

    public function testEachEncryptionUsesARandomNonce(): void
    {
        $service = new SecretEncryptionService(SecretEncryptionService::generateKey());
        self::assertNotSame($service->encrypt('x'), $service->encrypt('x'));
    }

    public function testDecryptWithAnotherKeyFails(): void
    {
        $cipher = (new SecretEncryptionService(SecretEncryptionService::generateKey()))->encrypt('secret');
        $this->expectException(SecretEncryptionException::class);
        (new SecretEncryptionService(SecretEncryptionService::generateKey()))->decrypt($cipher);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $service = new SecretEncryptionService(SecretEncryptionService::generateKey());
        $cipher = $service->encrypt('secret');
        $raw = base64_decode(substr($cipher, 3), true);
        $raw[\strlen($raw) - 1] = \chr(\ord($raw[\strlen($raw) - 1]) ^ 1);
        $this->expectException(SecretEncryptionException::class);
        $service->decrypt('v1:' . base64_encode($raw));
    }

    public function testMissingOrInvalidKeyIsReportedWithoutLeakingIt(): void
    {
        $missing = new SecretEncryptionService('');
        self::assertFalse($missing->isConfigured());
        self::assertFalse((new SecretEncryptionService(base64_encode('trop-court')))->isConfigured());

        try {
            $missing->encrypt('secret');
            self::fail('Exception attendue');
        } catch (SecretEncryptionException $e) {
            self::assertStringContainsString('APP_ENCRYPTION_KEY', $e->getMessage());
        }
    }
}
