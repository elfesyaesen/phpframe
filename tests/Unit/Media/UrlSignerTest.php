<?php

declare(strict_types=1);

namespace Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use System\Media\UrlSigner;

#[CoversClass(UrlSigner::class)]
final class UrlSignerTest extends TestCase
{
    private const KEY  = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OLD  = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const UUID = '0b6f6a52-4f3e-4b8a-9d2c-3f1e5a7b9c11';
    private const NOW  = 1_800_000_000;
    private const TTL  = 600;

    private function signer(string $key = self::KEY, string $previous = ''): UrlSigner
    {
        return new UrlSigner($key, $previous, self::TTL);
    }

    public function testValidSignatureVerifies(): void
    {
        $signer  = $this->signer();
        $expires = $signer->expiry(self::NOW);
        $sig     = $signer->sign(self::UUID, 'medium.webp', $expires);

        self::assertTrue($signer->verify(self::UUID, 'medium.webp', $expires, $sig, self::NOW));
    }

    public function testSignatureIsUrlSafe(): void
    {
        $signer = $this->signer();
        $sig    = $signer->sign(self::UUID, 'medium.webp', $signer->expiry(self::NOW));

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $sig);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $signer  = $this->signer();
        $expires = $signer->expiry(self::NOW);
        $sig     = $signer->sign(self::UUID, 'medium.webp', $expires);
        $tampered = ($sig[0] === 'A' ? 'B' : 'A') . substr($sig, 1);

        self::assertFalse($signer->verify(self::UUID, 'medium.webp', $expires, $tampered, self::NOW));
        self::assertFalse($signer->verify(self::UUID, 'medium.webp', $expires, '', self::NOW));
    }

    public function testSignatureIsBoundToFileAndUuid(): void
    {
        $signer  = $this->signer();
        $expires = $signer->expiry(self::NOW);
        $sig     = $signer->sign(self::UUID, 'thumb.webp', $expires);

        self::assertFalse($signer->verify(self::UUID, 'full.webp', $expires, $sig, self::NOW));
        self::assertFalse($signer->verify(self::UUID, 'thumb.avif', $expires, $sig, self::NOW));
        self::assertFalse($signer->verify('1b6f6a52-4f3e-4b8a-9d2c-3f1e5a7b9c11', 'thumb.webp', $expires, $sig, self::NOW));
    }

    public function testExpiryCannotBeExtended(): void
    {
        $signer  = $this->signer();
        $expires = $signer->expiry(self::NOW);
        $sig     = $signer->sign(self::UUID, 'file.pdf', $expires);

        self::assertFalse($signer->verify(self::UUID, 'file.pdf', $expires + 600, $sig, self::NOW));
    }

    public function testExpiredUrlIsRejected(): void
    {
        $signer  = $this->signer();
        $expires = $signer->expiry(self::NOW);
        $sig     = $signer->sign(self::UUID, 'file.pdf', $expires);

        self::assertFalse($signer->verify(self::UUID, 'file.pdf', $expires, $sig, $expires + 1));
    }

    public function testExpiryBeyondTtlWindowIsRejected(): void
    {
        $signer  = $this->signer();
        $farAway = self::NOW + 10 * self::TTL;
        $sig     = $signer->sign(self::UUID, 'file.pdf', $farAway);

        self::assertFalse($signer->verify(self::UUID, 'file.pdf', $farAway, $sig, self::NOW));
    }

    public function testExpiryIsBucketed(): void
    {
        $signer = $this->signer();

        $first  = $signer->expiry(self::NOW);
        $later  = $signer->expiry(self::NOW + 100);

        self::assertSame(0, $first % self::TTL);
        self::assertGreaterThanOrEqual(self::NOW + self::TTL, $first);
        self::assertLessThanOrEqual(self::NOW + 2 * self::TTL, $first);

        if (intdiv(self::NOW, self::TTL) === intdiv(self::NOW + 100, self::TTL)) {
            self::assertSame($first, $later);
        }
    }

    public function testPreviousKeyIsAcceptedDuringRotation(): void
    {
        $old     = $this->signer(self::OLD);
        $expires = $old->expiry(self::NOW);
        $sig     = $old->sign(self::UUID, 'medium.webp', $expires);

        self::assertTrue($this->signer(self::KEY, self::OLD)->verify(self::UUID, 'medium.webp', $expires, $sig, self::NOW));
        self::assertFalse($this->signer(self::KEY)->verify(self::UUID, 'medium.webp', $expires, $sig, self::NOW));
    }

    public function testEmptyKeyNeverVerifies(): void
    {
        $signer  = $this->signer('');
        $expires = $signer->expiry(self::NOW);

        self::assertFalse($signer->verify(self::UUID, 'file.pdf', $expires, $signer->sign(self::UUID, 'file.pdf', $expires), self::NOW));
    }
}
