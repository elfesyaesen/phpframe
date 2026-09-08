<?php

declare(strict_types=1);

/**
 * HTTP bootstrap — ince kernel girişi.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * BU DOSYA ESKİDEN ~290 SATIRDI ve ~25 servisi closure ile kaydediyordu.
 * Closure'lar var_export edilemez, dolayısıyla DERLENEMEZDİ — DI-plan'ın
 * "compiled container" hedefi o yapıyla ulaşılamazdı.
 *
 * Tüm tanımlar `config/providers.php` üzerinden bildirimsel provider'lara
 * taşındı. Kazançlar:
 *
 *   • Tanımlar derlenebilir: production'da tek üretilmiş dosya, sıfır
 *     reflection (DI-plan §16).
 *   • Sıra-kritik iki kısıt ortadan kalktı veya yapısal hâle geldi:
 *       – "Request monitor'den önce kaydedilmeli" → iki fazlı provider
 *         modelinde temsil edilemez bir sorun (tüm register()'lar herhangi
 *         bir get()'ten önce biter).
 *       – "monitor, exception handler'dan önce boot edilmeli" → tek
 *         shutdown fonksiyonu + ShutdownPriority; sıra artık yorumla değil
 *         VERİYLE korunuyor.
 *   • Scope ihlalleri, döngüler ve middleware alias hataları artık
 *     `php frame container:validate` ile compile-time'da yakalanıyor.
 * ─────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config/config.php';

$kernel = System\Runtime\Kernel::create(System\Runtime\Sapi::Http, APP_ROOT);

$container = $kernel->container();
