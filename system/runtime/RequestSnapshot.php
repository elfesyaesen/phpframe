<?php

declare(strict_types=1);

namespace System\Runtime;

/**
 * İsteğin, router çalışmadan ÖNCE alınması gereken anlık görüntüsü — SCOPED.
 *
 * Eski `MonitorBootstrapper::boot()` üç iş yapıyordu; ikisi burada:
 * `php://input` okuma ve istek başlangıç zamanı.
 *
 * NEDEN EAGER KURULMAK ZORUNDA (ve bu, tüm boot'taki tek haklı eager
 * çözümlemedir): `php://input` PHP'de TEK KEZ okunabilir bir akıştır. Router
 * veya Request onu tükettikten sonra monitor ham gövdeyi bir daha elde
 * edemez. Bu yüzden bu obje istek scope'u açılır açılmaz, dispatch'ten önce
 * kurulup scope'a yerleştirilir.
 *
 * Lazy olsaydı: monitor'ün ham gövdesi bazen dolu bazen boş olurdu —
 * hangi kod yolunun input'u önce okuduğuna bağlı olarak.
 */
final readonly class RequestSnapshot
{
    public function __construct(
        public float $startedAt,
        public string $rawInput,
        public bool $bufferingResponse,
    ) {}

    /**
     * Şimdiki isteğin görüntüsünü alır.
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function capture(array $server, bool $bufferingResponse, bool $readInput = true): self
    {
        return new self(
            // REQUEST_TIME_FLOAT gerçek istek başlangıcıdır; microtime()
            // bootstrap süresini kaçırır ve süre ölçümünü olduğundan kısa
            // gösterir.
            startedAt: isset($server['REQUEST_TIME_FLOAT'])
                ? (float) $server['REQUEST_TIME_FLOAT']
                : microtime(true),
            rawInput: $readInput ? self::readRawInput() : '',
            bufferingResponse: $bufferingResponse,
        );
    }

    /** CLI için boş görüntü. */
    public static function forCli(): self
    {
        return new self(microtime(true), '', false);
    }

    /**
     * Ham istek gövdesini okur.
     *
     * `php://input` bazı SAPI/enctype kombinasyonlarında (multipart form)
     * okunamaz; hata durumunda boş string döner — monitor'ün gövde
     * yakalayamaması, isteğin başarısız olmasından iyidir.
     */
    private static function readRawInput(): string
    {
        $input = @file_get_contents('php://input');

        return $input === false ? '' : $input;
    }
}
