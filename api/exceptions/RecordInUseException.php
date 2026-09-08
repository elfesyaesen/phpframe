<?php

declare(strict_types=1);

namespace Api\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Kayıt başka kayıtlarca referans verildiği için silinemiyor.
 *
 * Veritabanı karşılığı: `ON DELETE RESTRICT` yabancı anahtar ihlali —
 * MySQL 1451, PostgreSQL SQLSTATE 23503.
 *
 * `DuplicateNameException` ile aynı gerekçeyle ayrı bir tip: model katmanı
 * HTTP bilmez, yalnızca OLGUYU bildirir ("bu kayıt kullanımda"). Durum kodu
 * ve çevrilmiş mesaj kararı servise aittir.
 *
 * Bu tipin var olması bir hatayı da kapatıyor: `RoleModel::deleteRole()`
 * FK ihlalini `($e->errorInfo[0] ?? '') === '23000'` ile tespit ediyordu.
 * MySQL'de 23000 SQLSTATE'i FK ihlalinin yanı sıra UNIQUE ihlalini de
 * kapsar — yani bir benzersizlik çakışması "rol kullanımda" (409) diye
 * raporlanabilirdi. Tespit artık `BaseModel::isForeignKeyViolation()`
 * üzerinden, sürücü koduna kadar iniyor.
 */
final class RecordInUseException extends RuntimeException
{
    public function __construct(
        /** Silinemeyen kaydın kimliği — servis mesajı bununla kurabilir. */
        public readonly string $identifier,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Kayıt kullanımda, silinemez: %s', $identifier), 0, $previous);
    }
}
