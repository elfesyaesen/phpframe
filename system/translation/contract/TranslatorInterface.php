<?php

declare(strict_types=1);

namespace System\Translation\Contract;

/**
 * Çeviri cephesi.
 *
 * Framework ve controller'lar YALNIZCA `trans()` kullanır. Uygulama
 * katmanındaki somut sınıfın ek metotları (`resolver()`) bu sözleşmeye dahil
 * edilmez — arayüz gerçekten paylaşılan yüzeyi tanımlar, sınıfın tamamını
 * aynalamaz.
 *
 * Bu dar yüzeyin bedelini ödediği yer: uygulama `symfony/translation`'a
 * geçirilirken TEK dosya (`Api\Services\Translator`) değişti; `Validator`,
 * `BaseController`, `ControllerServices` ve tüm servisler dokunulmadan kaldı.
 */
interface TranslatorInterface
{
    /**
     * Anahtarı aktif dile çevirir; bulunamazsa anahtarın kendisini döndürür.
     *
     * @param array<string, string|int|float> $params ICU yer tutucuları —
     *        katalogda `{isim}` biçiminde yazılır (bkz. lang/*.php).
     */
    public function trans(string $key, array $params = []): string;
}
