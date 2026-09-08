<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputArgument;
use System\Console\Input\InputOption;

#[AsCommand(
    name: 'make:model',
    description: 'Yeni bir model oluşturur',
    usage: 'php frame make:model <module> <name> [--table=<table>]'
)]
final class MakeModelCommand extends Command
{
    /** Modül adı güvenlik formatı (whitelist YOK — herhangi bir modül). */
    private const MODULE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    protected function configure(): void
    {
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'Modül adı (örn: api, admin, catalog veya özel bir modül)')
            ->addArgument('name', InputArgument::REQUIRED, 'Model adı')
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, 'Tablo adı (varsayılan: otomatik tahmin)');
    }

    protected function handle(): ExitCode
    {
        $module = strtolower($this->argument('module', ''));
        $name = $this->argument('name', '');

        // Validasyon
        if (empty($module) || empty($name)) {
            $this->error('Modül ve model adı gerekli!');
            $this->newLine();
            $this->writeln('Kullanım: php frame make:model <module> <name>');
            $this->writeln('Örnek:    php frame make:model admin Product');
            $this->writeln('          php frame make:model api User --table=users');
            return ExitCode::INVALID;
        }

        if (!preg_match(self::MODULE_PATTERN, $module)) {
            $this->error("Geçersiz modül adı: {$module}");
            $this->writeln('Modül adı küçük harfle başlamalı; yalnızca harf, rakam ve alt çizgi içerebilir.');
            return ExitCode::INVALID;
        }

        // Model adını normalize et
        $className = $this->normalizeName($name);
        $namespace = ucfirst($module) . '\\Models';

        // Tablo adı
        $tableName = $this->option('table') ?: $this->guessTableName($className);

        // Dosya yolu
        $directory = APP_ROOT . '/' . $module . '/models';
        $filePath = $directory . '/' . $className . '.php';

        // Dizin oluştur
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Dosya var mı kontrol et
        if (file_exists($filePath)) {
            $this->error("Model zaten mevcut: {$className}");
            return ExitCode::FAILURE;
        }

        $content = $this->generateTemplate($namespace, $className, $tableName);

        // Dosyayı yaz
        if (file_put_contents($filePath, $content) === false) {
            $this->error("Dosya oluşturulamadı!");
            return ExitCode::FAILURE;
        }

        $this->newLine();
        $this->success("Model oluşturuldu!");
        $this->newLine();
        $this->writeln('  ' . $this->output->cyan('Dosya:') . '     ' . $filePath);
        $this->writeln('  ' . $this->output->cyan('Namespace:') . ' ' . $namespace);
        $this->writeln('  ' . $this->output->cyan('Sınıf:') . '     ' . $className);
        $this->writeln('  ' . $this->output->cyan('Tablo:') . '     ' . $tableName);
        $this->newLine();

        return ExitCode::SUCCESS;
    }

    private function normalizeName(string $name): string
    {
        // Model suffix yoksa ekle
        if (!str_ends_with($name, 'Model')) {
            $name .= 'Model';
        }

        // PascalCase yap
        $name = str_replace(['-', '_'], ' ', $name);
        $name = ucwords($name);

        return str_replace(' ', '', $name);
    }

    private function guessTableName(string $modelName): string
    {
        // Model suffix'ini kaldır
        $name = preg_replace('/Model$/', '', $modelName);

        // PascalCase'den snake_case'e çevir
        $name = preg_replace('/([a-z])([A-Z])/', '$1_$2', $name);
        $name = strtolower($name);

        // Basit çoğul kuralı
        if (!str_ends_with($name, 's')) {
            $name = match (true) {
                str_ends_with($name, 'y') => substr($name, 0, -1) . 'ies',
                str_ends_with($name, 'ch'), str_ends_with($name, 'sh'), str_ends_with($name, 'x') => $name . 'es',
                default => $name . 's',
            };
        }

        return $name;
    }

    private function generateTemplate(string $namespace, string $className, string $tableName): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use System\Engine\BaseModel;
use System\Database\Database;

class {$className} extends BaseModel
{
    protected string \$table = '{$tableName}';
    
    public function getAll(): array
    {
        \$sql = "SELECT * FROM " . \$this->prefix() . \$this->table;
        \$stmt = \$this->pdo()->query(\$sql);

        return \$stmt->fetchAll();
    }

    public function getById(int \$id): ?array
    {
        \$sql = "SELECT * FROM " . \$this->prefix() . \$this->table . " WHERE id = :id LIMIT 1";
        \$stmt = \$this->pdo()->prepare(\$sql);
        \$stmt->execute(['id' => \$id]);

        return \$stmt->fetch() ?: null;
    }

    public function create(array \$data): int
    {
        \$columns = implode(', ', array_keys(\$data));
        \$placeholders = ':' . implode(', :', array_keys(\$data));

        \$sql = "INSERT INTO " . \$this->prefix() . \$this->table . " ({\$columns}) VALUES ({\$placeholders})";
        \$stmt = \$this->pdo()->prepare(\$sql);
        \$stmt->execute(\$data);

        return (int) \$this->pdo()->lastInsertId();
    }

    public function update(int \$id, array \$data): bool
    {
        \$setParts = [];
        foreach (array_keys(\$data) as \$key) {
            \$setParts[] = "{\$key} = :{\$key}";
        }

        \$sql = "UPDATE " . \$this->prefix() . \$this->table . " SET " . implode(', ', \$setParts) . " WHERE id = :id";
        \$data['id'] = \$id;

        \$stmt = \$this->pdo()->prepare(\$sql);

        return \$stmt->execute(\$data);
    }

    public function delete(int \$id): bool
    {
        \$sql = "DELETE FROM " . \$this->prefix() . \$this->table . " WHERE id = :id";
        \$stmt = \$this->pdo()->prepare(\$sql);

        return \$stmt->execute(['id' => \$id]);
    }
}

PHP;
    }
}
