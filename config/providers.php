<?php

declare(strict_types=1);

/**
 * Servis tanımlarının TEK KAYNAĞI.
 *
 * Bu listeyi hem web bootstrap'ı (Kernel) hem `php frame container:compile`
 * okur. Paylaşılması "derlenen == koşan" garantisinin ta kendisidir: derleme,
 * runtime'ın kullanacağı tanım kümesinin aynısı üzerinde çalışır.
 *
 * NEDEN AÇIK LİSTE, DOSYA SİSTEMİ TARAMASI DEĞİL:
 * Tarama sırası dosya sistemine göre değişir (Windows vs ext4 vs APFS) ve
 * DI-plan §4'ün "deterministik resolution" hedefini bozar. Açık liste ayrıca
 * hangi provider'ların yüklü olduğunu okunur kılar.
 *
 * SIRA HAKKINDA — ÖNEMLİ:
 * `register()` sırası ÖNEMSİZDİR. Tanımlar bildirimseldir ve çözümleme
 * lazy'dir, dolayısıyla bir provider kendisinden sonra gelen bir provider'ın
 * tanımladığı servise güvenle bağımlı olabilir.
 *
 * `boot()` sırası bu listeyi izler, AMA bir DOĞRULUK invariant'ını buna
 * dayandırmayın: sıralamaya ihtiyaç duyan yan etkiler `ShutdownRegistry` +
 * `ShutdownPriority` kullanır, orada sıra yorumla değil veriyle korunur.
 * (Eski bootstrap'ta "monitor, exception handler'dan önce kurulmalı" kuralı
 * satır sırasına gömülüydü; biri listeyi alfabetik sıralasa fatal-error
 * gözlemlenebilirliği sessizce ölürdü.)
 *
 * Dosya içeriği derleme hash'ine girer (DI-plan §31): provider eklemek/
 * çıkarmak derlemeyi bayatlatır ve `container:validate --check` yakalar.
 */

return [
    // ── Çalışma bağlamı ──
    System\Container\Provider\RuntimeProvider::class,
    System\Container\Provider\LoggingProvider::class,

    // ── HTTP ──
    System\Container\Provider\HttpProvider::class,

    // ── Gözlemlenebilirlik ──
    // Monitor ve hata yönetimi: boot() sırası burada ÖNEMSİZ, shutdown
    // sıralaması ShutdownPriority'de veri olarak duruyor.
    System\Container\Provider\MonitorProvider::class,
    System\Container\Provider\ErrorHandlingProvider::class,

    // ── Altyapı ──
    System\Container\Provider\DatabaseProvider::class,
    System\Container\Provider\CacheProvider::class,
    System\Container\Provider\ViewProvider::class,
    // NOT: LocalizationProvider KALDIRILDI. `LocaleResolver` ve `Translator`
    // somut `Api\Services\*` sınıfları; kayıtları ApiProvider'a taşındı.
    // Bir framework provider'ının uygulama sınıflarını `use` etmesi, aşağıda
    // yazılı "System\ asla Api\'yi bilmez" kuralının ihlaliydi.
    System\Container\Provider\ValidationProvider::class,
    System\Container\Provider\StorageProvider::class,
    System\Container\Provider\SecurityProvider::class,
    System\Container\Provider\RoutingProvider::class,
    System\Container\Provider\ConsoleProvider::class,

    // ── Uygulama modülleri (framework'ten SONRA) ──
    // Uygulama katmanı framework arayüzlerini somut sınıflara bağlar
    // (GateInterface → Gate, LocaleProviderInterface → LocaleResolver).
    Api\Provider\ApiProvider::class,
];
