<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputArgument;
use System\Console\Input\InputOption;
use System\Engine\ModuleRegistry;
use System\Console\Commands\Concerns\GeneratesClassNames;

#[AsCommand(
    name: 'make:service',
    description: 'Domain servisi iskeleti oluşturur (katman sözleşmesine uygun)',
    usage: 'php frame make:service <module> <name> [--model=<Model>]',
    aliases: ['m:s']
)]
final class MakeServiceCommand extends Command
{
    use GeneratesClassNames;

    private const MODULE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    protected function configure(): void
    {
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'Modül adı (örn: api, admin)')
            ->addArgument('name', InputArgument::REQUIRED, 'Servis adı (örn: Invoice veya InvoiceService)')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Enjekte edilecek model sınıfı (örn: InvoiceModel)');
    }

    protected function handle(): ExitCode
    {
        $module = strtolower((string) $this->argument('module', ''));
        $name   = (string) $this->argument('name', '');

        if ($module === '' || $name === '') {
            $this->error('Modül ve servis adı gerekli!');
            $this->newLine();
            $this->writeln('Kullanım: php frame make:service <module> <name>');
            $this->writeln('Örnek:    php frame make:service api Invoice --model=InvoiceModel');
            return ExitCode::INVALID;
        }

        if (!preg_match(self::MODULE_PATTERN, $module)) {
            $this->error("Geçersiz modül adı: {$module}");
            $this->writeln('Modül adı küçük harfle başlamalı; yalnızca harf, rakam ve alt çizgi içerebilir.');
            return ExitCode::INVALID;
        }

        $className = $this->className($name, 'Service');
        $model     = $this->option('model') !== null ? $this->className((string) $this->option('model'), 'Model') : null;

        if ($className === null) {
            $this->invalidClassName($name);
            return ExitCode::INVALID;
        }

        if ($this->option('model') !== null && $model === null) {
            $this->invalidClassName((string) $this->option('model'));
            return ExitCode::INVALID;
        }

        $namespace = ModuleRegistry::namespace($module, 'Services');

        $directory = ModuleRegistry::path($module, 'services');
        $filePath  = $directory . '/' . $className . '.php';

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->error("Dizin oluşturulamadı: {$directory}");
            return ExitCode::FAILURE;
        }

        if (file_exists($filePath)) {
            $this->error("Servis zaten mevcut: {$className}");
            return ExitCode::FAILURE;
        }

        $content = $this->generateTemplate($namespace, $className, ModuleRegistry::namespace($module), $model);

        if (file_put_contents($filePath, $content) === false) {
            $this->error('Dosya oluşturulamadı!');
            return ExitCode::FAILURE;
        }

        $this->newLine();
        $this->success('Servis oluşturuldu!');
        $this->newLine();
        $this->writeln('  ' . $this->output->cyan('Dosya:') . '     ' . $filePath);
        $this->writeln('  ' . $this->output->cyan('Namespace:') . ' ' . $namespace);
        $this->writeln('  ' . $this->output->cyan('Sınıf:') . '     ' . $className);
        $this->newLine();

        $this->writeln('  Kayıt gerekmez: ' . $className . ' constructor\'a yazıldığı her yerde enjekte edilir.');
        $this->newLine();

        return ExitCode::SUCCESS;
    }

    private function generateTemplate(
        string $namespace,
        string $className,
        string $moduleNs,
        ?string $model,
    ): string {
        $modelImport   = $model !== null ? "use {$moduleNs}\\Models\\{$model};\n" : '';
        $modelProperty = $model !== null
            ? "        private readonly {$model} \$model,\n"
            : "        // TODO: gerekli model(ler)i buraya enjekte edin.\n";

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

{$modelImport}use System\\Exceptions\\NotFoundException;
use System\\Translation\\Contract\\TranslatorInterface;

/**
 * TODO: bu servisin sahip olduğu kullanım senaryosunu yazın.
 *
 * ── KATMAN SÖZLEŞMESİ (docs/api-layer.md) ───────────────────────────────
 *
 *   • Bir kullanım senaryosunu UÇTAN UCA sahiplenir; model'leri ve altyapıyı
 *     orkestre eder.
 *   • Transaction, hash'leme, mail ve dosya sıralamasının sahibi BURASIDIR.
 *     Çok adımlı yazmalar için `System\\Database\\Database::transaction()`.
 *   • Başarısızlığı `System\\Exceptions\\*` FIRLATARAK bildirir.
 *     ASLA `false` veya `['error' => ...]` döndürmez.
 *   • `Request`, `Response` veya PDO'ya DOKUNMAZ.
 *   • Kullanıcıya gidecek mesajları BURADA çevirir — `ExceptionHandler`
 *     singleton'dır ve aktif locale'i bilmez.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * SCOPED bağlanmalıdır: `TranslatorInterface` scoped'tur.
 */
final class {$className}
{
    public function __construct(
{$modelProperty}        private readonly TranslatorInterface \$translator,
    ) {
    }

    /**
     * TODO: örnek senaryo — silin veya kendinizinkiyle değiştirin.
     *
     * @throws NotFoundException kayıt bulunamazsa
     */
    public function find(string \$uuid): array
    {
        throw new NotFoundException(\$this->translator->trans('TODO.not_found'));
    }
}

PHP;
    }
}
