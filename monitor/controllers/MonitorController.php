<?php

declare(strict_types=1);

namespace Monitor\Controllers;

use Monitor\Models\MonitorModel;
use System\Config\MonitorConfig;
use System\Engine\BaseController;
use System\Exceptions\NotFoundException;

/**
 * Monitor dashboard (salt-okunur).
 *
 * Erişim kontrolü buraya GÖMÜLMEZ: middleware zinciri `routes/routes.php`'de
 * `MONITOR_AUTH_MODE`'a göre kurulur ve monitor kapalıysa route hiç tanımlanmaz
 * (dashboard'ın varlığı sızmaz). Controller yalnızca veriyi hazırlar.
 *
 * Şablonlar `monitor/views/*.twig`. `TwigFactory` `csrf_token()`/`csrf_field()`
 * fonksiyonlarını kaydeder ama CSRF bu projede UYGULANMAMIŞTIR; çağrıldıkları
 * anda ne yapılması gerektiğini anlatan açık bir istisna fırlatırlar
 * (eskiden "class not found" fatal'i veriyorlardı — sebebi hiçbir yerde
 * yazmıyordu). Bu dashboard salt-okunur olduğu (POST formu içermediği) için
 * o fonksiyonlar hiç kullanılmaz. Monitor'e yazma yapan bir ekran
 * eklenecekse yapılacak iş `TwigFactory::addCsrfFunctions()` docblock'unda.
 */
final class MonitorController extends BaseController
{
    private const PER_PAGE = 25;

    /**
     * Zaman aralığı seçenekleri (saat).
     */
    private const RANGES = [1, 6, 24, 72, 168];

    public function __construct(
        private readonly MonitorModel $MonitorModel,
        private readonly MonitorConfig $monitor,
    ) {}

    public function index(): void
    {
        $request = $this->request();

        $filters = [
            'hours'        => (int) ($request->query('hours', 24) ?? 24),
            'method'       => (string) ($request->query('method', '') ?? ''),
            'status_class' => (string) ($request->query('status_class', '') ?? ''),
            'q'            => (string) ($request->query('q', '') ?? ''),
            'min_duration' => (string) ($request->query('min_duration', '') ?? ''),
            'n_plus_one'   => $request->query('n_plus_one') !== null,
        ];

        $total      = $this->MonitorModel->count($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = max(1, min((int) ($request->query('page', 1) ?? 1), $totalPages));

        $html = $this->view('monitor', 'index', [
            'rows'       => $this->MonitorModel->paginate($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'summary'    => $this->MonitorModel->summary($filters),
            'filters'    => $filters,
            'ranges'     => self::RANGES,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
            'queryString' => $this->queryString($filters),
            'slowMs'     => $this->monitor->slowMs,
            'slowQueryMs' => $this->monitor->slowQueryMs,
        ]);

        $this->response()->htmlResponse(200, (string) $html);
        return;
    }

    /**
     * Parametre adı snake_case: Router path'i lowercase'e normalize ediyor,
     * bu yüzden `{requestId}` camelCase'i metoda `$requestid` olarak gelir
     * ve "Unknown named parameter" hatası verir. Mevcut admin route'ları da
     * aynı nedenle `{user_uuid}` kullanıyor.
     */
    public function show(string $request_id): void
    {
        // request_id bootstrap'ta ya upstream'den (doğrulanmış desen) ya da
        // random_bytes'tan gelir; aynı deseni burada da uygula.
        if (preg_match('/^[A-Za-z0-9\-]{1,64}$/', $request_id) !== 1) {
            throw new NotFoundException(
                message: 'Kayıt bulunamadı',
                context: ['request_id' => $request_id]
            );
        }

        $record = $this->MonitorModel->find($request_id);

        if ($record === null) {
            throw new NotFoundException(
                message: 'Kayıt bulunamadı',
                context: ['request_id' => $request_id]
            );
        }

        $html = $this->view('monitor', 'show', [
            'record'      => $record,
            'headers'     => $this->prettyJson($record['headers'] ?? null),
            'requestBody' => $this->prettyJson($record['request_body'] ?? null),
            'responseBody' => $this->prettyJson($record['response_body'] ?? null),
            'queries'     => $this->decorateQueries($this->MonitorModel->queriesFor($request_id)),
            'exceptions'  => $this->MonitorModel->exceptionsFor($request_id),
            'slowMs'      => $this->monitor->slowMs,
            'slowQueryMs' => $this->monitor->slowQueryMs,
        ]);

        $this->response()->htmlResponse(200, (string) $html);
        return;
    }

    /**
     * Sorgulara "yavaş mı" ve okunabilir bindings bilgisi ekler.
     *
     * @param array<int, array<string, mixed>> $queries
     * @return array<int, array<string, mixed>>
     */
    private function decorateQueries(array $queries): array
    {
        $threshold = $this->monitor->slowQueryMs;

        foreach ($queries as $i => $query) {
            $queries[$i]['slow']     = ((float) ($query['duration_ms'] ?? 0)) >= $threshold;
            $queries[$i]['bindings'] = $this->prettyJson($query['bindings'] ?? null);
            $queries[$i]['sql_text'] = $this->squashWhitespace((string) ($query['sql_text'] ?? ''));
        }

        return $queries;
    }

    /**
     * JSON'ı okunabilir biçimde yazar; JSON değilse ham metni döner.
     *
     * Escape edilmez — Twig'in autoescape'i şablonda halleder. Burada escape
     * etmek çifte escape üretirdi.
     */
    private function prettyJson(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        $pretty = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $pretty === false ? $value : $pretty;
    }

    private function squashWhitespace(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
    }

    /**
     * Aktif filtreleri sayfalama linklerinde korumak için query string.
     *
     * @param array<string, mixed> $filters
     */
    private function queryString(array $filters): string
    {
        $pairs = [];

        foreach ($filters as $key => $value) {
            if ($value === '' || $value === false || $value === null) {
                continue;
            }

            $pairs[$key] = $value === true ? '1' : (string) $value;
        }

        return $pairs === [] ? '' : '&' . http_build_query($pairs);
    }
}
