<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputArgument;
use System\Console\Input\InputOption;

#[AsCommand(
    name: 'make:migration',
    description: 'Yeni bir migration dosyası oluşturur',
    usage: 'php frame make:migration <name> [--create=<table>] [--table=<table>]'
)]
final class MakeMigrationCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Migration adı (örn: create_users_table)')
            ->addOption('create', 'c', InputOption::VALUE_REQUIRED, 'Oluşturulacak tablo adı')
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, 'Değiştirilecek tablo adı');
    }

    protected function handle(): ExitCode
    {
        $name = $this->argument('name', '');

        if (empty($name)) {
            $this->error('Migration adı gerekli!');
            return ExitCode::INVALID;
        }

        // Migration adını normalize et
        $name = $this->normalizeName($name);

        // Dosya adı oluştur
        $timestamp = date('Y_m_d_His');
        $fileName = $timestamp . '_' . $name;

        // Migration dizini
        $directory = APP_ROOT . '/migration';

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filePath = $directory . '/' . $fileName . '.php';

        // Dosya var mı kontrol et
        if (file_exists($filePath)) {
            $this->error("Migration zaten mevcut: {$fileName}");
            return ExitCode::FAILURE;
        }

        // Template belirle
        $createTable = $this->option('create');
        $alterTable = $this->option('table');

        if ($createTable) {
            $content = $this->generateCreateTemplate($createTable);
        } elseif ($alterTable) {
            $content = $this->generateAlterTemplate($alterTable);
        } else {
            // İsimden tablo adını tahmin et
            $guessedTable = $this->guessTableName($name);
            if (str_starts_with($name, 'create_') && str_ends_with($name, '_table')) {
                $content = $this->generateCreateTemplate($guessedTable);
            } else {
                $content = $this->generateBlankTemplate();
            }
        }

        // Dosyayı yaz
        if (file_put_contents($filePath, $content) === false) {
            $this->error("Dosya oluşturulamadı!");
            return ExitCode::FAILURE;
        }

        $this->newLine();
        $this->success("Migration oluşturuldu!");
        $this->newLine();
        $this->writeln('  ' . $this->output->cyan('Dosya:') . ' ' . $filePath);
        $this->newLine();

        return ExitCode::SUCCESS;
    }

    private function normalizeName(string $name): string
    {
        // snake_case'e çevir
        $name = preg_replace('/([a-z])([A-Z])/', '$1_$2', $name);
        $name = preg_replace('/[\s\-]+/', '_', $name);
        $name = strtolower($name);

        return preg_replace('/[^a-z0-9_]/', '', $name);
    }

    private function guessTableName(string $migrationName): string
    {
        // create_users_table -> users
        // add_email_to_users_table -> users

        if (preg_match('/^create_(.+)_table$/', $migrationName, $matches)) {
            return $matches[1];
        }

        if (preg_match('/_to_(.+)_table$/', $migrationName, $matches)) {
            return $matches[1];
        }

        if (preg_match('/_from_(.+)_table$/', $migrationName, $matches)) {
            return $matches[1];
        }

        if (preg_match('/_in_(.+)_table$/', $migrationName, $matches)) {
            return $matches[1];
        }

        return 'table_name';
    }

    private function generateCreateTemplate(string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use System\Database\Migration;
use System\Database\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        \$this->schema->create('{$table}', function (Blueprint \$table) {
            \$table->id();

            // Kolonları buraya ekleyin

            \$table->timestamps();
        });
    }

    public function down(): void
    {
        \$this->schema->dropIfExists('{$table}');
    }
};

PHP;
    }

    private function generateAlterTemplate(string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use System\Database\Migration;
use System\Database\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        \$this->schema->table('{$table}', function (Blueprint \$table) {
            // Değişiklikleri buraya ekleyin
            // \$table->string('column_name');
        });
    }

    public function down(): void
    {
        \$this->schema->table('{$table}', function (Blueprint \$table) {
            // Geri alma işlemlerini buraya ekleyin
            // \$table->dropColumn('column_name');
        });
    }
};

PHP;
    }

    private function generateBlankTemplate(): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use System\Database\Migration;
use System\Database\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Migration işlemlerini buraya yazın
    }

    public function down(): void
    {
        // Geri alma işlemlerini buraya yazın
    }
};

PHP;
    }
}
