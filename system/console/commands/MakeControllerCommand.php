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
    name: 'make:controller',
    description: 'Yeni bir controller oluşturur',
    usage: 'php frame make:controller <module> <name> [--api] [--resource]'
)]
final class MakeControllerCommand extends Command
{
    use GeneratesClassNames;

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

        $className = $this->className((string) $name, 'Controller');

        if ($className === null) {
            $this->invalidClassName((string) $name);
            return ExitCode::INVALID;
        }
        $namespace = ModuleRegistry::namespace($module, 'Controllers');

        $directory = ModuleRegistry::path($module, 'controllers');
        $filePath = $directory . '/' . $className . '.php';

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (file_exists($filePath)) {
            $this->error("Controller zaten mevcut: {$className}");
            return ExitCode::FAILURE;
        }

        $isResource = $this->hasOption('resource');
        $isApi = $module === 'api' || $this->hasOption('api') || $isResource;

        $content = $this->generateTemplate($namespace, $className, $module, $isApi, $isResource);

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
