<?php

declare(strict_types=1);

namespace System\Runtime;

use System\Config\ConfigFactory;

/**
 * Container'a INSTANCE olarak verilen canlı objelerin TEK kaynağı.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN AYRI BİR SINIF:
 *
 * Bu kümenin id'leri İKİ yerde aynı olmak zorunda:
 *   • Kernel — runtime'da container'ı kurar
 *   • BuildsContainer (CLI) — derleme/doğrulama için builder'ı kurar
 *
 * Ayrışırsa sessiz ve kafa karıştırıcı bir hata sınıfı doğar: derleyici
 * `AppConfig`'i tanım olarak görmez, onu AUTOWIRE etmeye çalışır ve
 * `string $root` parametresinde "primitive çözülemedi" der. Yani hata,
 * gerçek sebebinden (instance kaydı eksik) çok uzakta raporlanır.
 *
 * Tek fonksiyon hâline getirmek bu ayrışmayı imkânsız kılar.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Bu objeler derlenmiş PHP dosyasına GÖMÜLEMEZ (canlı obje), dolayısıyla
 * `EXTERNALS` olarak bildirilir ve container kurulurken dışarıdan verilir.
 * Güvenlik açısından bu bir kazanç: DB_PASS / SECRET_KEY üretilen dosyaya
 * asla yazılmaz (DI-plan §30).
 */
final class RuntimeInstances
{
    /**
     * @param bool $validate Production'da eksik/zayıf env anahtarları için
     *        fail-fast. CLI derleme/raporlama yollarında false geçilebilir:
     *        `container:validate` bir yapılandırma hatası yüzünden değil,
     *        GRAF hatası yüzünden başarısız olmalı.
     *
     * @return array<class-string, object>
     */
    public static function build(
        string $appRoot,
        ShutdownRegistry $shutdown,
        bool $validate = true,
    ): array {
        $configs = ConfigFactory::forRoot($appRoot)->all($validate);

        return [
            ...$configs,
            ShutdownRegistry::class => $shutdown,
        ];
    }
}
