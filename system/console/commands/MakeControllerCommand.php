<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputArgument;
use System\Console\Input\InputOption;

#[AsCommand(
    name: 'make:controller',
    description: 'Yeni bir controller oluşturur',
    usage: 'php frame make:controller <module> <name> [--api] [--resource]'
)]
final class MakeControllerCommand extends Command
{
    /**
     * Modül adı için güvenli format: küçük harfle başlar; harf/rakam/alt çizgi.
     * Whitelist YOK — herhangi bir modül oluşturulabilir; bu yalnızca dosya yolu
     * ve namespace güvenliği içindir (path traversal / geçersiz namespace engeli).
     */
    private const MODULE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    protected function configure(): void
    {
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'Modül adı (örn: api, admin, catalog veya özel bir modül)')
            ->addArgument('name', InputArgument::REQUIRED, 'Controller adı')
            ->addOption('api', 'a', InputOption::VALUE_NONE, 'API controller template kullan')
            ->addOption('resource', 'r', InputOption::VALUE_NONE, 'Resource metodları ekle (index, show, store, update, destroy)');
    }

    protected function handle(): ExitCode
    {
        $module = strtolower($this->argument('module', ''));
        $name = $this->argument('name', '');

        // Validasyon
        if (empty($module) || empty($name)) {
            $this->error('Modül ve controller adı gerekli!');
            $this->newLine();
            $this->writeln('Kullanım: php frame make:controller <module> <name>');
            $this->writeln('Örnek:    php frame make:controller admin Product');
            $this->writeln('          php frame make:controller api User --resource');
            return ExitCode::INVALID;
        }

        if (!preg_match(self::MODULE_PATTERN, $module)) {
            $this->error("Geçersiz modül adı: {$module}");
            $this->writeln('Modül adı küçük harfle başlamalı; yalnızca harf, rakam ve alt çizgi içerebilir.');
            return ExitCode::INVALID;
        }

        // Controller adını normalize et
        $className = $this->normalizeName($name);
        $namespace = ucfirst($module) . '\\Controllers';

        // Dosya yolu
        $directory = APP_ROOT . '/' . $module . '/controllers';
        $filePath = $directory . '/' . $className . '.php';

        // Dizin oluştur
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Dosya var mı kontrol et
        if (file_exists($filePath)) {
            $this->error("Controller zaten mevcut: {$className}");
            return ExitCode::FAILURE;
        }

        // Template seç
        $isApi = $module === 'api' || $this->hasOption('api');
        $isResource = $this->hasOption('resource') || $isApi;

        $content = $this->generateTemplate($namespace, $className, $module, $isApi, $isResource);

        // Dosyayı yaz
        if (file_put_contents($filePath, $content) === false) {
            $this->error("Dosya oluşturulamadı!");
            return ExitCode::FAILURE;
        }

        $this->newLine();
        $this->success("Controller oluşturuldu!");
        $this->newLine();
        $this->writeln('  ' . $this->output->cyan('Dosya:') . '     ' . $filePath);
        $this->writeln('  ' . $this->output->cyan('Namespace:') . ' ' . $namespace);
        $this->writeln('  ' . $this->output->cyan('Sınıf:') . '     ' . $className);
        $this->newLine();

        return ExitCode::SUCCESS;
    }

    private function normalizeName(string $name): string
    {
        // Controller suffix yoksa ekle
        if (!str_ends_with($name, 'Controller')) {
            $name .= 'Controller';
        }

        // PascalCase yap
        $name = str_replace(['-', '_'], ' ', $name);
        $name = ucwords($name);

        return str_replace(' ', '', $name);
    }

    private function generateTemplate(
        string $namespace,
        string $className,
        string $module,
        bool $isApi,
        bool $isResource
    ): string {
        if ($isApi) {
            return $this->getApiTemplate($namespace, $className, $isResource);
        }

        return $this->getWebTemplate($namespace, $className, $module);
    }

    private function getApiTemplate(string $namespace, string $className, bool $isResource): string
    {
        $methods = $isResource ? $this->getResourceMethods() : $this->getBasicApiMethod();

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use System\Engine\BaseController;
use System\Helpers\Status;

class {$className} extends BaseController
{
{$methods}
}

PHP;
    }

    private function getWebTemplate(string $namespace, string $className, string $module): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use System\Engine\BaseController;
use System\Helpers\Status;

class {$className} extends BaseController
{
    public function index(): void
    {
        \$data['title'] = '{$className}';

        // `view()` render SONUCUNU döndürür, yayınlamaz — yayınlamak
        // `Response`'ın işidir. Her yanıt `return` ile biter.
        \$html = \$this->view('{$module}', 'index', \$data);

        \$this->response()->htmlResponse(Status::OK, \$html);
        return;
    }
}

PHP;
    }

    /**
     * ── ŞABLON SÖZLEŞMESİ ───────────────────────────────────────────────
     *
     * Üretilen her metot:
     *   • TEK bir servis çağrısı yapar — model'e doğrudan konuşmaz,
     *   • yanıtı `Status::` enum'u ile gönderir (ham 200/404 DEĞİL),
     *   • `return;` ile biter.
     *
     * Son madde kozmetik değil: `Response` artık `exit` ETMİYOR. `return`
     * unutulan bir guard cümlesi, yanıt gönderildikten SONRA ayrıcalıklı
     * koda akmaya devam eder. Eski şablon tam olarak bu deseni üretiyordu.
     *
     * Hata yolları burada YOK ve bu kasıtlı: bulunamadı/çakışma/yetki
     * hataları servis katmanında `System\Exceptions\*` fırlatılarak bildirilir,
     * `ExceptionHandler` onları doğru durum koduna çevirir. Controller'da
     * `if (!$sonuc) { jsonResponse(404, ...) }` YAZILMAZ.
     * ─────────────────────────────────────────────────────────────────────
     */
    private function getResourceMethods(): string
    {
        return <<<'PHP'
    public function index(): void
    {
        // TODO: $this->service->all()
        $this->response()->jsonResponse(Status::OK, ['data' => []]);
        return;
    }

    public function show(string $uuid): void
    {
        // TODO: $this->service->find($uuid) — bulunamazsa servis
        // NotFoundException fırlatır; burada kontrol GEREKMEZ.
        $this->response()->jsonResponse(Status::OK, ['data' => ['uuid' => $uuid]]);
        return;
    }

    public function store(): void
    {
        // TODO: $validated = $this->validator->validate($this->request()->json(), [...]);
        //       $this->service->create($validated);
        $this->response()->jsonResponse(Status::CREATED, ['data' => $this->request()->all()]);
        return;
    }

    public function update(string $uuid): void
    {
        // TODO: doğrula, sonra $this->service->update($uuid, $validated);
        $this->response()->jsonResponse(Status::OK, ['data' => ['uuid' => $uuid]]);
        return;
    }

    public function destroy(string $uuid): void
    {
        // TODO: $this->service->delete($uuid);
        $this->response()->jsonResponse(Status::OK, ['data' => ['uuid' => $uuid]]);
        return;
    }
PHP;
    }

    private function getBasicApiMethod(): string
    {
        return <<<'PHP'
    public function index(): void
    {
        // TODO: tek bir servis çağrısı; model'e doğrudan konuşmayın.
        $this->response()->jsonResponse(Status::OK, ['data' => []]);
        return;
    }
PHP;
    }
}
