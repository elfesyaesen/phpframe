<?php

declare(strict_types=1);

namespace System\Console\Commands\Concerns;

use System\Container\Compilation\ContainerCompiler;
use System\Container\Compilation\Diagnostic;
use System\Container\Compilation\Severity;
use System\Container\Compiled\CompiledContainerLoader;
use System\Container\Core\ContainerBuilder;
use System\Runtime\RuntimeInstances;
use System\Runtime\Sapi;
use System\Runtime\ShutdownRegistry;

/**
 * Container komutlarının paylaştığı kurulum ve raporlama.
 *
 * KRİTİK: burada HİÇBİR SERVİS ÖRNEKLENMEZ. Yalnızca TANIMLAR okunur.
 * Derleme sırasında servis örneklemek DB bağlantısı açar, shutdown handler
 * kaydeder ve `ob_start()` çağırır — yani derleme, uygulamayı yan etkileriyle
 * birlikte çalıştırmış olur. Provider'ların `register()` metotlarının saf
 * olması bu yüzden zorunlu.
 *
 * Builder web bootstrap'ı ile AYNI config/providers.php listesinden kurulur;
 * "derlenen == koşan" garantisi buradan gelir.
 */
trait BuildsContainer
{
    private ?ContainerBuilder $cachedBuilder = null;

    /**
     * Provider'lardan tanım builder'ı kurar.
     *
     * @param Sapi|null $sapi null (varsayılan) = SAPI filtresi YOK, tüm
     *        provider'ların tanımları alınır.
     *
     *        TEK derlenmiş container hem HTTP hem CLI'ya hizmet ettiği için
     *        tanım kümesi BİRLEŞİM olmak zorunda: yalnızca Http için
     *        derlenirse production'da `php frame ...` CLI-only servisleri
     *        (Console\Application) bulamaz ve reflection fallback olmadığı
     *        için kurtarma da olmaz. Ayrıntılı gerekçe:
     *        ContainerBuilder::instantiateProviders().
     */
    protected function builder(?Sapi $sapi = null): ContainerBuilder
    {
        return $this->cachedBuilder ??= ContainerBuilder::fromProviders(
            appRoot: APP_ROOT,
            sapi: $sapi,
            // Kernel ile AYNI instance kümesi (typed config + ShutdownRegistry).
            // Ayrışırsa derleyici config objelerini autowire etmeye çalışır ve
            // "primitive çözülemedi" gibi gerçek sebebinden uzak hatalar verir.
            //
            // validate: false — `container:validate` bir GRAF hatası yüzünden
            // başarısız olmalı, production env doğrulaması yüzünden değil.
            // (Yapılandırma denetimi Kernel'in boot'unda yapılır.)
            instances: RuntimeInstances::build(APP_ROOT, new ShutdownRegistry(), validate: false),
        );
    }

    protected function compiler(?Sapi $sapi = null, bool $useContentHash = false): ContainerCompiler
    {
        return ContainerCompiler::fromBuilder(
            appRoot: APP_ROOT,
            builder: $this->builder($sapi),
            useContentHash: $useContentHash,
        );
    }

    protected function loader(): CompiledContainerLoader
    {
        return CompiledContainerLoader::forRoot(APP_ROOT);
    }

    /**
     * Canlı derlemenin metadata'sı — `container:list/debug/graph` derlenmemiş
     * çalıştırmada builder'a, `--compiled` ile buna bakar.
     *
     * @return array<string, mixed>|null
     */
    protected function compiledMetadata(): ?array
    {
        return $this->loader()->metadata();
    }

    /**
     * Bulguları önem derecesine göre gruplayıp basar.
     *
     * @param list<Diagnostic> $diagnostics
     * @param bool $full true ise tam mesaj (zincir + çözüm), false ise tek satır
     */
    protected function renderDiagnostics(array $diagnostics, bool $full = true): void
    {
        if ($diagnostics === []) {
            return;
        }

        foreach ([Severity::ERROR, Severity::WARNING, Severity::NOTICE] as $severity) {
            $group = array_values(array_filter(
                $diagnostics,
                static fn(Diagnostic $d): bool => $d->severity === $severity
            ));

            if ($group === []) {
                continue;
            }

            $this->newLine();
            $this->section($severity->label() . ' (' . count($group) . ')');

            foreach ($group as $diagnostic) {
                if ($full) {
                    $this->writeln($this->colorize($severity, $diagnostic->render()));
                    $this->newLine();
                } else {
                    $this->writeln('  ' . $this->colorize($severity, $diagnostic->summary()));
                }
            }
        }
    }

    private function colorize(Severity $severity, string $text): string
    {
        return match ($severity) {
            Severity::ERROR   => $this->output->red($text),
            Severity::WARNING => $this->output->yellow($text),
            Severity::NOTICE  => $this->output->gray($text),
        };
    }

    /** Bulgu sayılarını tek satırda özetler. */
    protected function diagnosticSummary(array $diagnostics): string
    {
        $counts = [Severity::ERROR->value => 0, Severity::WARNING->value => 0, Severity::NOTICE->value => 0];

        foreach ($diagnostics as $diagnostic) {
            $counts[$diagnostic->severity->value]++;
        }

        return $this->output->red($counts['error'] . ' hata') . ', '
            . $this->output->yellow($counts['warning'] . ' uyarı') . ', '
            . $this->output->gray($counts['notice'] . ' bilgi');
    }
}
