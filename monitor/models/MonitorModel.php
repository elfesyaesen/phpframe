<?php

declare(strict_types=1);

namespace Monitor\Models;

use System\Config\MonitorConfig;
use System\Cache\CacheInterface;
use System\Database\Database;
use System\Database\DatabaseDriver;
use System\Engine\BaseModel;
use System\Monitor\Storage\DatabaseStorage;

/**
 * Monitor kayıtlarının OKUMA tarafı.
 *
 * Yazma tarafı (`System\Monitor\Storage\StorageInterface`) ile bilinçli olarak
 * ayrıdır: dashboard'ın filtreleme/sayfalama ihtiyaçları, izleme sırasında
 * çalışan yazma yolunu hiç ilgilendirmez (ISP). Yazan okumaz, okuyan yazmaz.
 *
 * Performans kuralları:
 *  - Liste sorgusu gövde kolonlarını (headers/request_body/response_body) HİÇ
 *    seçmez. Bunlar LONGTEXT ve InnoDB'de satır dışında saklanır; seçilmediği
 *    sürece okunmazlar. Kaynak projenin tek geniş tablosunu `SELECT *` ile
 *    listelemesi, tablo büyüdüğünde dashboard'ı kilitleyen asıl darboğazdı.
 *  - Her liste/sayım sorgusu ZORUNLU bir zaman aralığı taşır. Aralıksız
 *    `LIKE '%...%'` + `COUNT(*)` kombinasyonu milyonlarca satırda tam tarama
 *    demektir; `created_at` indeksi aralığı önce daraltır.
 *  - Sonuçlar cache'lenMEZ: izleme verisi tanımı gereği tazedir ve bir hatayı
 *    araştıran kişi 5 dakika önceki durumu görmemelidir.
 */
final class MonitorModel extends BaseModel
{
    private readonly DatabaseDriver $driver;

    public function __construct(
        Database $database,
        CacheInterface $cache,
        private readonly MonitorConfig $monitor,
    ) {
        parent::__construct($database, $cache);

        // BaseModel Database'i private tutuyor; sürücüye (identifier quoting)
        // erişim için burada saklanır.
        $this->driver = $database->getDriver();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = 'SELECT id, request_id, method, uri, route_name, status,'
            . ' duration_ms, memory_kb, query_count, query_time_ms,'
            . ' cache_hits, cache_misses, n_plus_one, ip, user_uuid, created_at'
            . ' FROM ' . $this->table(DatabaseStorage::TABLE_REQUEST)
            . ' WHERE ' . $where
            . ' ORDER BY id DESC'
            // LIMIT/OFFSET interpolasyonu: değerler int'e cast edilip
            // sınırlandığı için enjeksiyon yüzeyi yok. Bazı sürücüler
            // LIMIT'te bind parametresini emulate-prepares kapalıyken
            // string olarak gönderip sözdizimi hatası verir.
            . ' LIMIT ' . max(1, min($limit, 200))
            . ' OFFSET ' . max(0, $offset);

        return $this->selectAll($sql, $params);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $row = $this->selectOne(
            'SELECT COUNT(*) AS total FROM ' . $this->table(DatabaseStorage::TABLE_REQUEST)
                . ' WHERE ' . $where,
            $params
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $requestId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM ' . $this->table(DatabaseStorage::TABLE_REQUEST)
                . ' WHERE request_id = :rid LIMIT 1',
            ['rid' => $requestId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function queriesFor(string $requestId): array
    {
        return $this->selectAll(
            'SELECT sql_text, bindings, duration_ms FROM ' . $this->table(DatabaseStorage::TABLE_QUERY)
                . ' WHERE request_id = :rid ORDER BY id ASC',
            ['rid' => $requestId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exceptionsFor(string $requestId): array
    {
        return $this->selectAll(
            'SELECT exception_class, message, file, line, trace FROM '
                . $this->table(DatabaseStorage::TABLE_EXCEPTION)
                . ' WHERE request_id = :rid ORDER BY id ASC',
            ['rid' => $requestId]
        );
    }

    /**
     * Üst şerit için özet sayılar.
     *
     * Tek sorguda toplanır: dört ayrı COUNT sorgusu atmak, izleme
     * dashboard'ının izlenen veritabanına gereksiz yük bindirmesi olurdu.
     *
     * @param array<string, mixed> $filters
     * @return array{total: int, errors: int, slow: int, n_plus_one: int, avg_ms: float, max_ms: float}
     */
    public function summary(array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $slowMs = $this->monitor->slowMs;

        $row = $this->selectOne(
            'SELECT COUNT(*) AS total,'
                . ' SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) AS errors,'
                . ' SUM(CASE WHEN duration_ms >= :slow THEN 1 ELSE 0 END) AS slow,'
                . ' SUM(CASE WHEN n_plus_one = 1 THEN 1 ELSE 0 END) AS n_plus_one,'
                . ' AVG(duration_ms) AS avg_ms, MAX(duration_ms) AS max_ms'
                . ' FROM ' . $this->table(DatabaseStorage::TABLE_REQUEST)
                . ' WHERE ' . $where,
            $params + ['slow' => $slowMs]
        );

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'errors'     => (int) ($row['errors'] ?? 0),
            'slow'       => (int) ($row['slow'] ?? 0),
            'n_plus_one' => (int) ($row['n_plus_one'] ?? 0),
            'avg_ms'     => round((float) ($row['avg_ms'] ?? 0), 2),
            'max_ms'     => round((float) ($row['max_ms'] ?? 0), 2),
        ];
    }

    /**
     * Filtreleri WHERE cümlesine çevirir.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        // Zaman aralığı ZORUNLU (varsayılan 24 saat): indeks kullanımını
        // garanti eder ve tablo büyüdükçe dashboard'ın yavaşlamasını önler.
        $hours  = max(1, min((int) ($filters['hours'] ?? 24), 24 * 90));
        $clauses = ['created_at >= :since'];
        $params  = ['since' => date('Y-m-d H:i:s', time() - ($hours * 3600))];

        if (!empty($filters['method'])) {
            $clauses[]        = 'method = :method';
            $params['method'] = strtoupper((string) $filters['method']);
        }

        // Durum sınıfı (2xx/4xx/5xx) tam koda göre daha kullanışlı ve
        // (status, created_at) indeksini aralık olarak kullanır.
        if (!empty($filters['status_class'])) {
            $class                  = (int) $filters['status_class'];
            $clauses[]              = 'status >= :status_min AND status < :status_max';
            $params['status_min']   = $class * 100;
            $params['status_max']   = ($class + 1) * 100;
        }

        if (!empty($filters['q'])) {
            $clauses[]   = 'uri LIKE :q';
            $params['q'] = '%' . (string) $filters['q'] . '%';
        }

        if (!empty($filters['min_duration'])) {
            $clauses[]              = 'duration_ms >= :min_duration';
            $params['min_duration'] = (float) $filters['min_duration'];
        }

        if (!empty($filters['n_plus_one'])) {
            $clauses[] = 'n_plus_one = 1';
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function table(string $name): string
    {
        return $this->driver->quoteIdentifier($this->prefix() . $name);
    }
}
