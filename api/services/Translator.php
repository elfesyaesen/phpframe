<?php

declare(strict_types=1);

namespace Api\Services;

use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator as SymfonyTranslator;
use System\Translation\Contract\TranslatorInterface;

/**
 * Mesaj çevirisi — `symfony/translation` sarmalayıcısı.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN SARMALAYICI, DOĞRUDAN SYMFONY DEĞİL:
 *
 * Framework `System\Translation\Contract\TranslatorInterface` görür
 * (`trans(string $key, array $params = []): string`). Bu seam sayesinde
 * Symfony'ye geçiş TEK dosyada kaldı — `Validator`, `BaseController`,
 * `ControllerServices` ve tüm servisler değişmedi. Aynı seam, ileride başka
 * bir uygulamaya geçmeyi de aynı ölçüde ucuz tutar.
 *
 * NEDEN SYMFONY:
 *   • ICU MessageFormat — locale'e duyarlı sayı biçimlendirme ve gerektiğinde
 *     çoğul/seçim yapıları (bkz. lang/messages+intl-icu.tr.php).
 *   • Bakımı yapılan, `composer audit` kapsamındaki bir uygulama.
 *
 * ── DÜZELTİLEN İKİ PERFORMANS HATASI ────────────────────────────────────
 *
 * 1. ESKİDEN: constructor DESTEKLENEN TÜM locale'lerin dosyalarını `require`
 *    ediyordu. Sınıf SCOPED olduğu için bu, her istekte üç dosyanın okunup
 *    ayrıştırılması demekti — kullanılan yalnızca biri olmasına rağmen.
 *    ŞİMDİ: kaynaklar yalnızca KAYDEDİLİR (`addResource` tembeldir); Symfony
 *    kataloğu ilk `trans()` çağrısında ve yalnızca gereken locale için yükler.
 *
 * 2. ESKİDEN: `trans()` her çağrıda `LocaleResolver::locale()` çağırıyordu ve
 *    o da `Accept-Language` başlığını HER SEFERİNDE yeniden ayrıştırıyordu.
 *    10 mesajlık bir yanıt, başlığı 10 kez ayrıştırıyordu.
 *    ŞİMDİ: locale scope ömrü boyunca bir kez çözülür ve memoize edilir
 *    (SCOPED olduğu için istekler arası sızıntı yoktur).
 * ─────────────────────────────────────────────────────────────────────────
 */
final class Translator implements TranslatorInterface
{
    /**
     * ICU MessageFormat'ı etkinleştiren alan adı.
     *
     * Symfony `+intl-icu` sonekini gördüğünde `IntlFormatter`'a geçer:
     * yer tutucular `{ad}` biçiminde olur ve `{n, plural, ...}` yazılabilir.
     * Sonek olmasaydı `%ad%` biçimi geçerli olur, ICU yapıları düz metin
     * olarak basılırdı.
     */
    private const DOMAIN = 'messages+intl-icu';

    private ?SymfonyTranslator $translator = null;

    /** Scope ömrü boyunca tek kez çözülen aktif locale. */
    private ?string $locale = null;

    public function __construct(
        private readonly LocaleResolver $resolver,
        private readonly string $bundlePath,
    ) {
    }

    public function trans(string $key, array $params = []): string
    {
        return $this->translator()->trans($key, $params, self::DOMAIN, $this->locale());
    }

    // NOT: `resolver()` getter'ı SİLİNDİ — sıfır çağıranı vardı.
    //
    // Sızdıran bir arayüzdü: çağıranlar `Translator` üzerinden `LocaleResolver`'a
    // uzanabiliyordu. Locale'e ihtiyaç duyan bir sınıf `LocaleProviderInterface`'i
    // DOĞRUDAN enjekte etmeli; çeviri servisinin içinden geçmemeli.

    // ── İç kurulum ────────────────────────────────────────────────

    private function locale(): string
    {
        return $this->locale ??= $this->resolver->locale();
    }

    private function translator(): SymfonyTranslator
    {
        if ($this->translator !== null) {
            return $this->translator;
        }

        $translator = new SymfonyTranslator($this->locale());
        $translator->addLoader('php', new PhpFileLoader());

        // Bulunamayan anahtar için TR'ye düş — eski davranışla aynı.
        // Anahtarın kendisi son çare olarak Symfony tarafından döndürülür.
        $translator->setFallbackLocales([LocaleResolver::DEFAULT_LOCALE]);

        // `addResource` TEMBELDİR: dosyayı okumaz, yalnızca kaydeder. Bu
        // yüzden desteklenen tüm locale'leri kaydetmek maliyetsizdir; Symfony
        // yalnızca istenen (ve fallback) kataloğu yükler.
        foreach (LocaleResolver::SUPPORTED as $locale) {
            $file = $this->bundlePath . '/' . self::DOMAIN . '.' . $locale . '.php';

            if (is_file($file)) {
                $translator->addResource('php', $file, $locale, self::DOMAIN);
            }
        }

        return $this->translator = $translator;
    }
}
