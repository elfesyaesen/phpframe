<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputArgument;
use System\Console\Input\InputOption;

/**
 * Domain servisi iskeleti üretir.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN GEREKLİ: `api/` katmanında uzun süre servis YOKTU; controller'lar
 * doğrudan model'e konuşuyordu. Bunun sonucu 276 satırlık, 7 bağımlılıklı
 * bir `UserController` ve içine sıkışmış OAuth rotasyon protokolü oldu.
 *
 * Bir generator sorunu yeniden üretebilir de, engelleyebilir de: eski
 * `make:controller` şablonu `void` + `jsonResponse(...)` + `return` YOK
 * desenini üretiyordu — yani düzelttiğimiz hatayı her yeni dosyada yeniden
 * yaratıyordu. Bu komut doğru şekli üretir.
 *
 * BINDING SATIRINI DA YAZDIRIR: `RootCollector::allowedPrefixes()` yalnızca
 * `System\` ve modül öneklerini örtük autowire'a açtığı için, provider'a
 * eklenmemiş bir servis `container:validate`'te patlar. Bunu söylemeyen bir
 * generator, bozuk bir uygulama üretmiş olur.
 * ─────────────────────────────────────────────────────────────────────────
 */
#[AsCommand(
    name: 'make:service',
    description: 'Domain servisi iskeleti oluşturur (katman sözleşmesine uygun)',
    usage: 'php frame make:service <module> <name> [--model=<Model>]',
    aliases: ['m:s']
)]
final class MakeServiceCommand extends Command
{
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

        $className = $this->normalizeName($name);
        $namespace = ucfirst($module) . '\\Services';
        $model     = $this->option('model') ? $this->normalizeModel((string) $this->option('model')) : null;

        $directory = APP_ROOT . '/' . $module . '/services';
        $filePath  = $directory . '/' . $className . '.php';

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->error("Dizin oluşturulamadı: {$directory}");
            return ExitCode::FAILURE;
        }

        if (file_exists($filePath)) {
            $this->error("Servis zaten mevcut: {$className}");
            return ExitCode::FAILURE;
        }

        $content = $this->generateTemplate($namespace, $className, ucfirst($module), $model);

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

        // ── ZORUNLU SONRAKİ ADIM ──────────────────────────────────────
        // Bunu yazdırmamak, `container:validate` hatasıyla karşılaşana kadar
        // "neden çalışmıyor?" diye aranan bir dosya bırakmak demektir.
        $this->caution('Servis HENÜZ BAĞLI DEĞİL — container onu çözemez.');
        $this->newLine();
        $this->writeln('  ' . $this->output->cyan('1.') . ' Şu satırı ' . ucfirst($module) . "\\Provider\\" . ucfirst($module) . "Provider::register()'a ekleyin:");
        $this->newLine();
        $this->writeln('     $builder->scoped(' . $className . '::class);');
        $this->newLine();
        $this->writeln('  ' . $this->output->cyan('2.') . ' Aynı sınıfı provides() dizisine de ekleyin.');
        $this->writeln('  ' . $this->output->cyan('3.') . ' Doğrulayın: ' . $this->output->yellow('php frame container:validate'));
        $this->newLine();

        return ExitCode::SUCCESS;
    }

    /** `Invoice`, `InvoiceService`, `invoice` → `InvoiceService` */
    private function normalizeName(string $name): string
    {
        $name = ucfirst(trim($name));

        return str_ends_with($name, 'Service') ? $name : $name . 'Service';
    }

    /** `invoice`, `Invoice`, `InvoiceModel` → `InvoiceModel` */
    private function normalizeModel(string $name): string
    {
        $name = ucfirst(trim($name));

        return str_ends_with($name, 'Model') ? $name : $name . 'Model';
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
