<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ResetPasswordRequestTest extends TestCase
{
    public function testResetPasswordRequestStoresUserAndTokenMetadata(): void
    {
        $user = (new User())->setEmail('member@example.com')->setPassword('hashed-password');
        $expiresAt = new \DateTimeImmutable('+1 hour');

        $request = new ResetPasswordRequest($user, $expiresAt, 'selector-value', 'hashed-token');

        self::assertSame($user, $request->getUser());
        self::assertSame($expiresAt, $request->getExpiresAt());
        self::assertSame('hashed-token', $request->getHashedToken());
        self::assertFalse($request->isExpired());
    }
}
