<?php

declare(strict_types=1);

namespace System\Runtime;

/**
 * Shutdown hook öncelikleri. Yüksek olan ÖNCE çalışır.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * BU SAYILARIN NEDEN BÖYLE SIRALANDIĞI — kod tabanındaki tek kayıt:
 *
 * PHP'de bir shutdown fonksiyonu `exit()` çağırırsa SIRADAKİ shutdown
 * fonksiyonları HİÇ ÇALIŞMAZ. `ExceptionHandler::handleShutdown()` bir fatal
 * error gördüğünde yanıtı gönderip `exit(1)` yapar.
 *
 * Eski mimaride monitor ve exception handler AYRI `register_shutdown_function`
 * çağrılarıydı ve monitor'ün önce kaydedilmesi gerekiyordu; yanlış sırada
 * kaydedilirse monitor, tam olarak en çok ihtiyaç duyulan durumda (fatal
 * error) hiçbir şey kaydetmezdi. Bu invariant bir YORUMLA korunuyordu
 * (eski MonitorBootstrapper docblock'u).
 *
 * Şimdi TEK bir shutdown fonksiyonu var (bkz. ShutdownRegistry) ve sıra bu
 * sabitlerde VERİ olarak duruyor. Kazanç: sıra artık provider listesindeki
 * satır konumuna veya kayıt sırasına bağlı değil — biri provider listesini
 * alfabetik sıralasa bile gözlemlenebilirlik ölmez.
 * ─────────────────────────────────────────────────────────────────────────
 */
final class ShutdownPriority
{
    /**
     * Monitor kaydını yaz. EN ÖNCE çalışmak zorunda: aynı shutdown
     * fonksiyonu içinde daha sonra çalışacak bir hook `exit()` yaparsa bu
     * çalışmış olur.
     *
     * Bedeli (eski mimaride de vardı): monitor çalıştığında henüz
     * `http_response_code(500)` çağrılmamış olabilir. FatalErrorCollector
     * bunu telafi eder ve durumu 500'e normalize eder.
     */
    public const MONITOR_FLUSH = 100;

    /**
     * Fatal error yanıtını gönder. `exit(1)` yapar — bu yüzden monitor'den
     * SONRA, scope teardown'dan ÖNCE.
     */
    public const FATAL_RESPONSE = 50;

    /**
     * Request scope'unu kapat. EN SON çalışmak zorunda: monitor'ün
     * shutdown-zamanı collector'ları scoped servisleri (RequestContext,
     * Recorder) hâlâ çözebilmeli. Scope daha önce kapatılırsa
     * HttpCollector'ın `get(RequestContext::class)` çağrısı patlardı.
     */
    public const SCOPE_DISPOSE = 0;

    private function __construct()
    {
    }
}
