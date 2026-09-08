<?php

declare(strict_types=1);

namespace System\Container\Attribute;

use Attribute;

/**
 * Adlandırılmış varyant seçer (DI-plan §22).
 *
 *   public function __construct(
 *       #[Named('reporting')] Connection $db,
 *   ) {}
 *
 * `Connection` tipi ile `reporting` adının birleşimi, container'da
 * `Connection@reporting` id'si olarak aranır:
 *
 *   $b->singleton('System\Database\Connection@reporting', ReportingConnection::class);
 *
 * `Inject`'ten farkı: `Inject` SOMUT SINIFI yazar, `Named` mantıksal bir
 * ETİKET yazar. İkincisi, somut sınıf değişse bile tüketici kodunun
 * değişmemesini sağlar — `reporting` bağlantısının hangi sınıf olduğu
 * provider'ın kararı olarak kalır.
 *
 * `@` ayırıcısı PHP sınıf adlarında geçemez, dolayısıyla gerçek bir servis
 * id'siyle çakışması imkânsız.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Named
{
    public function __construct(
        public string $name,
    ) {}

    /**
     * Tip + ad birleşiminin servis id'si.
     */
    public static function idFor(string $type, string $name): string
    {
        return $type . '@' . $name;
    }
}
