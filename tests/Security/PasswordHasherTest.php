<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use System\Security\PasswordHasher;

#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new PasswordHasher();
    }

    public function testHashIsVerifiable(): void
    {
        $hash = $this->hasher->hash('dogru-parola');

        self::assertTrue($this->hasher->verify('dogru-parola', $hash));
        self::assertFalse($this->hasher->verify('yanlis-parola', $hash));
    }

    public function testSaltIsRandomSoHashesDiffer(): void
    {
        $a = $this->hasher->hash('ayni-parola');
        $b = $this->hasher->hash('ayni-parola');

        self::assertNotSame($a, $b, 'Salt rastgele değil: aynı parola aynı hash üretti.');
        self::assertTrue($this->hasher->verify('ayni-parola', $a));
        self::assertTrue($this->hasher->verify('ayni-parola', $b));
    }

    public function testVerifyRejectsEmptyAndMalformedHash(): void
    {
        self::assertFalse($this->hasher->verify('parola', ''));
        self::assertFalse($this->hasher->verify('parola', 'bu-bir-hash-degil'));
    }

    public function testEmptyPasswordStillProducesIsolatedHash(): void
    {
        $hash = $this->hasher->hash('');

        self::assertTrue($this->hasher->verify('', $hash));
        self::assertFalse($this->hasher->verify('x', $hash));
    }

    public function testFreshHashDoesNotNeedRehash(): void
    {
        self::assertFalse($this->hasher->needsRehash($this->hasher->hash('p')));
    }

    public function testHashFromDifferentAlgorithmNeedsRehash(): void
    {
        $legacy = password_hash('p', PASSWORD_BCRYPT, ['cost' => 4]);

        self::assertIsString($legacy);
        self::assertTrue(
            $this->hasher->needsRehash($legacy),
            'Politika dışı parametrelerle üretilen hash rehash istemeli.'
        );
        self::assertTrue($this->hasher->verify('p', $legacy));
    }

    public function testGenerateRespectsRequestedLength(): void
    {
        foreach ([8, 12, 16, 32] as $len) {
            self::assertSame($len, strlen($this->hasher->generate($len)));
        }
    }

    public function testGenerateIsNotAffectedBySeedingWeakPrng(): void
    {
        mt_srand(12345);
        $a = $this->hasher->generate(24);

        mt_srand(12345);
        $b = $this->hasher->generate(24);

        self::assertNotSame(
            $a,
            $b,
            'Aynı mt_srand tohumu aynı parolayı üretti: CSPRNG kullanılmıyor '
            . '(str_shuffle/mt_rand regresyonu).'
        );
    }

    public function testGenerateProducesUniqueValues(): void
    {
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            $seen[$this->hasher->generate(16)] = true;
        }

        self::assertCount(200, $seen, 'Üretilen paroralarda çakışma var.');
    }

    public function testGeneratedPasswordIsHashableAndVerifiable(): void
    {
        $plain = $this->hasher->generate();
        $hash  = $this->hasher->hash($plain);

        self::assertNotSame('', $plain);
        self::assertTrue($this->hasher->verify($plain, $hash));
    }
}
