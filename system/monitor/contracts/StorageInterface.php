<?php

declare(strict_types=1);

namespace System\Monitor\Contracts;

use DateTimeImmutable;
use System\Monitor\RequestTrace;

/**
 * Monitor kayıtlarının kalıcı depolanması.
 *
 * Arayüz sayesinde depolama hedefi (veritabanı, dosya, hiçbiri) izleme
 * mantığından bağımsız değişebilir; `NullStorage` monitor kapalıyken
 * çağıran tarafta if-guard'ı gerektirmez (Null Object).
 */
interface StorageInterface
{
    /**
     * Tamamlanmış bir izi saklar. Exception fırlatmaz; başarısızlıkta false.
     */
    public function store(RequestTrace $trace): bool;

    /**
     * $olderThan'dan eski kayıtları siler; silinen satır sayısını döner.
     *
     * Tek bir dev DELETE yerine $chunkSize'lık parçalarla çalışmalıdır:
     * uzun süren DELETE, InnoDB'de geniş kilit ve replikasyon gecikmesi
     * üretir. Retention olmadığı için kaynak projede log tablosu sınırsız
     * büyüyordu — bu metot o açığı kapatır.
     */
    public function purge(DateTimeImmutable $olderThan, int $chunkSize = 5000): int;

    /**
     * Teşhis/log için sürücü adı: 'database' | 'null'.
     */
    public function driver(): string;
}
