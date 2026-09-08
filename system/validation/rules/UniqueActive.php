<?php

declare(strict_types=1);

namespace System\Validation\Rules;

/**
 * Aktif Kayıtlar Arasında Benzersizlik Kuralı
 *
 * Unique kuralının soft-delete farkındalıklı varyantı: yalnızca
 * `deleted_at IS NULL` olan satırlar arasında benzersizlik aranır.
 * Soft-delete edilmiş kayıtlar göz ardı edilir, böylece silinmiş
 * bir kullanıcının email/username değeri yeniden kullanılabilir.
 *
 * Kullanım:
 * - 'unique_active:user,email' → aktif kayıtlar arasında email benzersiz olmalı
 * - 'unique_active:user,email,5,user_id' → user_id=5 olan kayıt hariç
 */
final class UniqueActive extends AbstractUniqueRule
{
    protected function extraWhere(\PDO $pdo): string
    {
        return ' AND ' . $this->quoteId($pdo, 'deleted_at') . ' IS NULL';
    }
}
