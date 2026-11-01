<?php

declare(strict_types=1);

namespace App\Monitor\Controllers;

use App\Monitor\Models\MonitorModel;
use System\Config\AppConfig;
use System\Config\MonitorConfig;
use System\Engine\BaseController;
use System\Exceptions\NotFoundException;

final class MonitorController extends BaseController
{
    private const PER_PAGE = 25;

    private const RANGES = [1, 6, 24, 72, 168];

    public function __construct(
        private readonly MonitorModel $MonitorModel,
        private readonly MonitorConfig $monitor,
        private readonly AppConfig $app,
    ) {}

    public function index(): void
    {
        $request = $this->request();

        $filters = [
            'hours' => (int) ($request->query('hours', 24) ?? 24),
            'live'  => $request->query('live') !== null,
        ];

        $html = $this->view('monitor', 'index', [
            'summary'    => $this->MonitorModel->summary($filters),
            'timeline'   => $this->decorateTimeline($this->MonitorModel->timeline($filters), $filters['hours']),
            'endpoints'  => $this->MonitorModel->slowestEndpoints($filters, 10),
        ] + $this->pageData('/monitor', $filters, 'report'));

        $this->response()->htmlResponse(200, (string) $html);
        return;
    }

    public function requests(): void
    {
        $request = $this->request();

        $filters = [
            'hours'        => (int) ($request->query('hours', 24) ?? 24),
            'method'       => (string) ($request->query('method', '') ?? ''),
            'status_class' => (string) ($request->query('status_class', '') ?? ''),
            'q'            => (string) ($request->query('q', '') ?? ''),
            'min_duration' => (string) ($request->query('min_duration', '') ?? ''),
            'n_plus_one'   => $request->query('n_plus_one') !== null,

            'live'         => $request->query('live') !== null,
        ];

        $total      = $this->MonitorModel->count($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = max(1, min((int) ($request->query('page', 1) ?? 1), $totalPages));

        $html = $this->view('monitor', 'requests', [
            'rows'        => $this->MonitorModel->paginate($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'maxMs'       => $this->MonitorModel->summary($filters)['max_ms'],
            'page'        => $page,
            'totalPages'  => $totalPages,
            'total'       => $total,
            'queryString' => $this->queryString($filters),
        ] + $this->pageData('/monitor/requests', $filters, $this->activeView($filters)));

        $this->response()->htmlResponse(200, (string) $html);
        return;
    }

    public function live(): void
    {
        $this->response()->jsonResponse(200, ['last_id' => $this->MonitorModel->latestId()]);
        return;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function pageData(string $base, array $filters, string $view): array
    {
        return [
            'filters'    => $filters,
            'ranges'     => self::RANGES,
            'base'       => $base,
            'rangeQuery' => $this->queryString(array_diff_key($filters, ['hours' => true])),
            'liveUrl'    => rtrim($base . '?' . ltrim($this->queryString(['live' => !$filters['live']] + $filters), '&'), '?'),
            'view'       => $view,
        ] + $this->layoutData();
    }

    public function show(string $request_id): void
    {
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
            'view'        => 'detail',
        ] + $this->layoutData());

        $this->response()->htmlResponse(200, (string) $html);
        return;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function activeView(array $filters): string
    {
        return match (true) {
            $filters['status_class'] === '5' => 'errors',
            $filters['status_class'] === '4' => 'client',
            $filters['n_plus_one']           => 'nplus',
            $filters['min_duration'] !== ''  => 'slow',
            default                          => 'all',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutData(): array
    {
        return [
            'production'  => $this->app->production,
            'slowMs'      => $this->monitor->slowMs,
            'slowQueryMs' => $this->monitor->slowQueryMs,
            'now'         => time(),
        ];
    }

    /**
     * @param list<array{start: int, ok: int, client: int, server: int}> $series
     * @return array{bars: list<array<string, mixed>>, peak: int}
     */
    private function decorateTimeline(array $series, int $hours): array
    {
        $peak = 0;
        foreach ($series as $bucket) {
            $peak = max($peak, $bucket['ok'] + $bucket['client'] + $bucket['server']);
        }

        $format = $hours <= 24 ? 'H:i' : 'd.m H:i';
        $bars   = [];

        foreach ($series as $bucket) {
            $total  = $bucket['ok'] + $bucket['client'] + $bucket['server'];
            $bars[] = $bucket + [
                'total'  => $total,
                'label'  => date($format, $bucket['start']),
                'pct_ok'     => $peak > 0 ? round($bucket['ok'] / $peak * 100, 2) : 0,
                'pct_client' => $peak > 0 ? round($bucket['client'] / $peak * 100, 2) : 0,
                'pct_server' => $peak > 0 ? round($bucket['server'] / $peak * 100, 2) : 0,
            ];
        }

        return ['bars' => $bars, 'peak' => $peak];
    }

    /**
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
