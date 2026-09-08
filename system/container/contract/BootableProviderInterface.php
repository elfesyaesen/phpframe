<?php

declare(strict_types=1);

namespace System\Container\Contract;

/**
 * Container kurulduktan SONRA yan etki çalıştıran provider.
 *
 * Yan etkiler derlenemez — `ob_start()`, `header()`, shutdown handler kaydı
 * gibi işler build-time'da yapılamaz. Bu yüzden ServiceProviderInterface
 * saf tutulur ve tüm yan etkiler buraya toplanır.
 *
 * boot() sıralaması hakkında ÖNEMLİ NOT:
 *
 * boot() metotları config/providers.php sırasına göre çalışır, ama bir
 * DOĞRULUK invariant'ını asla bu sıraya dayandırma. Örneğin "monitor,
 * exception handler'dan önce kurulmalı" kuralını provider sırasına kodlamak,
 * onu bir config dosyasındaki satır konumuna emanet etmek demektir — biri
 * listeyi alfabetik sıralar ve fatal error gözlemlenebilirliği sessizce ölür.
 *
 * Sıralamaya ihtiyaç duyan yan etkiler ShutdownRegistry gibi öncelikli bir
 * kayıt mekanizması kullanmalı; orada sıra, yorum değil VERİ'dir.
 */
interface BootableProviderInterface
{
    public function boot(ContainerInterface $container): void;
}
