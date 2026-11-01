<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use System\Engine\Env;

#[CoversClass(Env::class)]
final class EnvParseTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function valueProvider(): array
    {
        return [
            'düz'                          => ['KEY=abc', 'abc', false],
            'boş'                          => ['KEY=', '', false],
            '= etrafında boşluk'           => ['KEY = abc', 'abc', false],
            'çift tırnak'                  => ['KEY="a b"', 'a b', true],
            'tek tırnak'                   => ["KEY='0123'", '0123', true],
            'tırnaklıda # korunur'         => ['KEY="p@ss #1"', 'p@ss #1', true],
            'tırnak + yorum'               => ['KEY="abc" # not', 'abc', true],
            'tırnaksız satır sonu yorumu'  => ['KEY=abc # not', 'abc', false],
            'boşluksuz # değerin parçası'  => ['URL=https://x/#a', 'https://x/#a', false],
            'değerde = korunur'            => ['KEY=a=b=c', 'a=b=c', false],
            'export öneki'                 => ['export KEY=abc', 'abc', false],
            'kaçış yorumlanmaz'            => ['KEY="a\nb"', 'a\nb', true],
            'değer içinde tab'             => ["KEY=\"a\tb\"", "a\tb", true],
        ];
    }

    #[DataProvider('valueProvider')]
    public function testValue(string $line, string $expected, bool $quoted): void
    {
        $key   = str_starts_with($line, 'URL') ? 'URL' : 'KEY';
        $entry = Env::parse($line)[$key];

        self::assertSame($expected, $entry['value']);
        self::assertSame($quoted, $entry['quoted']);
    }

    public function testCommentsBlankLinesBomAndLineEndings(): void
    {
        $entries = Env::parse("\xEF\xBB\xBFA=1\r\n\r\n# yorum\r\n  # girintili yorum\rB=2\nC=3");

        self::assertSame(['A', 'B', 'C'], array_keys($entries));
        self::assertSame(5, $entries['B']['line']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            '= yok'                    => ['SECRET_KEY: abc', "'=' yok"],
            'rakamla başlayan anahtar' => ['1KEY=a', 'geçersiz anahtar'],
            'anahtarda tire'           => ['MY-KEY=a', 'geçersiz anahtar'],
            'boş anahtar'              => ['=a', 'geçersiz anahtar'],
            'kapanmamış tırnak'        => ['KEY="abc', 'tırnağı kapanmamış'],
            'tırnaktan sonra metin'    => ['KEY="a"b', 'kapanış tırnağından sonra'],
            'HTTPS'                    => ['HTTPS=on', 'sunucu/istek değişkeni'],
            'HTTP_ başlığı'            => ['HTTP_X_FORWARDED_FOR=1.2.3.4', 'sunucu/istek değişkeni'],
            'REMOTE_ADDR'              => ['REMOTE_ADDR=127.0.0.1', 'sunucu/istek değişkeni'],
            'iç işaret anahtarı'       => ['PHPFRAME_DOTENV_KEYS=A', 'sunucu/istek değişkeni'],
            'kontrol karakteri'        => ["KEY=\"a\x01b\"", 'kontrol karakteri'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidLineFailsWithLineNumber(string $line, string $reason): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^test\.env:2: .*' . preg_quote($reason, '/') . '/u');

        Env::parse("OK=1\n" . $line, 'test.env');
    }

    public function testDuplicateKeyFailsAndNamesFirstLine(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test.env:3: THRESHOLD ikinci kez tanımlanmış (ilki: satır 1)');

        Env::parse("THRESHOLD=10\nOTHER=1\nTHRESHOLD=15", 'test.env');
    }

    public function testErrorMessageNeverContainsTheValue(): void
    {
        try {
            Env::parse('DB_PASS="cok-gizli-sir', 'test.env');
            self::fail('Kapanmamış tırnak hata vermeliydi.');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('cok-gizli-sir', $e->getMessage());
        }
    }
}
