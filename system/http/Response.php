<?php

declare(strict_types=1);

namespace System\Http;

use System\Helpers\Status;
use System\Http\Contract\LocaleProviderInterface;
use JsonException;
use LogicException;

/**
 * HTTP yanıtı gönderir.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * `exit` KALDIRILDI (eskiden üç metodun sonunda vardı).
 *
 * NEDEN VARDI: yanıt gönderildikten sonra controller'ın devam etmemesi
 * gerekiyordu. Ama bu, kontrol akışını TİP SİSTEMİNİN GÖREMEDİĞİ bir yan
 * etkiye bağlıyordu ve üç maliyeti vardı:
 *
 *   1. Controller'larda 63 çağrı noktasının HİÇBİRİNDE `return` yoktu; guard
 *      cümleleri yalnızca `exit` sayesinde çalışıyordu. `exit`'in naif
 *      kaldırılması, bir 401 guard'ının ardından ayrıcalıklı koda AKILMASI
 *      demekti — sessiz bir güvenlik açığı.
 *   2. "after" middleware yazılamıyordu: `Pipeline` bunu docblock'unda
 *      itiraf ediyordu.
 *   3. Controller'lar test edilemiyordu — test süreci de ölürdü.
 *
 * KALDIRMA NEDEN ŞİMDİ GÜVENLİ: servis katmanı refactor'ünde hata yolları
 * istisnalara çevrildi (63 → 24 çağrı noktası) ve KALAN her çağrı noktası
 * metodun son ifadesi olup `return;` ile bitiyor. Yani `exit` artık hiçbir
 * yerde kontrol akışını taşımıyor.
 *
 * `exit` HÂLÂ İKİ YERDE VAR ve kalmalı: `ExceptionHandler::sendResponse()`
 * (fatal sonrası; sonrasında çalışacak bir şey yok) ve `Kernel::fail()`
 * (boot hatası; henüz logger bile kurulmamış olabilir).
 * ─────────────────────────────────────────────────────────────────────────
 */
class Response
{
    /**
     * Bu istekte gövde zaten gönderildi mi?
     *
     * ÇİFT GÖNDERİM AĞI: `exit` kaldırıldıktan sonra, `return` unutulmuş bir
     * guard cümlesi iki gövde yazar ve istemci bozuk JSON alır. Bu bayrak
     * onu sessiz bozulma yerine GÜRÜLTÜLÜ bir hataya çevirir.
     *
     * SINIRI AÇIKÇA BELİRTİLMELİ: bu bir ağ, çözüm değil. `UserController`
     * gibi bir yerde 403 guard'ı düşerse, ayrıcalıklı işlem İKİNCİ yanıta
     * ulaşmadan ÖNCE çalışmış olur — istisna hasardan sonra patlar. Asıl
     * koruma her çağrı noktasının `return` ile bitmesidir.
     */
    private bool $sent = false;

    /**
     * Aktif locale sağlayıcısı — Content-Language başlığı için.
     *
     * Eskiden `setContainer()` ile container enjekte ediliyor ve buradan
     * `Api\Services\LocaleResolver` çekiliyordu. Artık bağımlılık
     * constructor'da BİLDİRİLİYOR: derleyici onu doğrulayabiliyor,
     * `container:debug Response` gerçek bağımlılığı gösteriyor ve framework
     * uygulama modülüne bağımlı değil (bkz. LocaleProviderInterface).
     */
    public function __construct(
        private readonly LocaleProviderInterface $localeProvider,
    ) {}

    /**
     * Gövde gönderimini işaretler; ikinci kez çağrılırsa hata verir.
     *
     * `Response` SCOPED olduğu için bayrak istekler arasında sızmaz.
     */
    private function markSent(string $method): void
    {
        if ($this->sent) {
            throw new LogicException(sprintf(
                'Yanıt zaten gönderildi; %s() ikinci kez çağrıldı. '
                . 'Muhtemel sebep: bir guard cümlesinden sonra `return` unutulmuş.',
                $method
            ));
        }

        $this->sent = true;
    }

    /**
     * JSON response gönder
     *
     * @param int|Status $status HTTP status code
     * @param mixed $data Response data
     * @throws JsonException
     */
    public function jsonResponse(int|Status $status, mixed $data): void
    {
        $this->markSent(__FUNCTION__);

        if ($status instanceof Status) {
            $status = $status->value;
        }

        // Güvenlik header'ları
        $this->sendSecurityHeaders();

        // CORS header'lari Router::dispatch icinde tek noktadan uygulanir.

        // Cache kontrolü
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        // Content type
        header('Content-Type: application/json; charset=utf-8');

        // Content-Language (i18n) — container varsa aktif locale'i ekle
        $this->sendContentLanguageHeader();

        // Status code
        http_response_code($status);

        // JSON encode (güvenli flags ile)
        $json = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        echo $json;
    }

    /**
     * Aktif locale icin Content-Language header'i gonder.
     *
     * Başlık opsiyonel: locale çözümlemesi patlarsa (örn. bozuk
     * Accept-Language) yanıtın kendisi başarısız olmamalı.
     */
    private function sendContentLanguageHeader(): void
    {
        try {
            header('Content-Language: ' . $this->localeProvider->locale());
        } catch (\Throwable) {
            // sessizce gec — header opsiyonel
        }
    }

    /**
     * Güvenlik header'larını gönder
     */
    private function sendSecurityHeaders(): void
    {
        // XSS koruması
        header('X-Content-Type-Options: nosniff');
        // X-XSS-Protection: legacy XSS auditor (Chrome/Edge'de kaldırıldı). '1; mode=block'
        // bazı durumlarda XS-leak açtığı için modern öneri (OWASP) onu kapatmaktır.
        // Asıl XSS koruması Content-Security-Policy ile sağlanır.
        header('X-XSS-Protection: 0');

        // Clickjacking koruması
        header('X-Frame-Options: SAMEORIGIN');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Permissions policy
        header('Permissions-Policy: interest-cohort=()');

        // HSTS (HTTPS zorunlu)
        if ($this->isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        // Content Security Policy (API için daha kısıtlayıcı)
        //header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none';");
    }

    /**
     * HTTPS mi kontrol et
     */
    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /**
     * HTML response gönder (escape edilmiş)
     */
    public function htmlResponse(int|Status $status, string $content): void
    {
        $this->markSent(__FUNCTION__);

        if ($status instanceof Status) {
            $status = $status->value;
        }

        $this->sendSecurityHeaders();

        // HTML için farklı CSP
       header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; frame-ancestors 'self';");

        header('Content-Type: text/html; charset=utf-8');
        http_response_code($status);

        echo $content;
    }

    /**
     * Redirect response
     */
    public function redirect(string $url, int $status = 302): void
    {
        $this->markSent(__FUNCTION__);

        // URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL) && !str_starts_with($url, '/')) {
            $url = '/';
        }

        http_response_code($status);
        header('Location: ' . $url);
    }
}
