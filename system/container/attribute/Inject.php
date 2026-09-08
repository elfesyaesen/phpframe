<?php

declare(strict_types=1);

namespace System\Container\Attribute;

use Attribute;

/**
 * Parametrenin hangi servisle karşılanacağını AÇIKÇA belirtir (DI-plan §22).
 *
 *   public function __construct(
 *       #[Inject(PrimaryConnection::class)] Connection $db,
 *   ) {}
 *
 * NE ZAMAN GEREKLİ: tip adı ayırt edici olmadığında. Örneğin aynı arayüzden
 * iki farklı örnek isteyen bir sınıf — `Connection $primary, Connection $replica`.
 * Bunun alternatifi bağlamsal binding'dir (DI-plan §12); attribute, kararı
 * TÜKETİCİNİN YANINDA tutmayı tercih ettiğinizde kullanılır.
 *
 * DİKKAT — attribute YARDIMCI mekanizmadır (DI-plan §22 son satırı).
 * Ana DI yolu constructor injection + tip tabanlı autowiring'dir. Attribute'lar
 * yalnızca tipin yetmediği yerde devreye girer; her parametreye attribute
 * yazmak, DI'ı yapılandırmaya çevirir ve tip sisteminin sağladığı kontrolü
 * kaybettirir.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Inject
{
    /**
     * @param class-string $id Enjekte edilecek servis id'si
     */
    public function __construct(
        public string $id,
    ) {}
}
