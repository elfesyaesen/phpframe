<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Config\DatabaseConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputOption;
use System\Database\Database;
use Throwable;

#[AsCommand(
    name: 'auth:purge',
    description: 'Süresi geçmiş parola sıfırlama token\'larını siler',
    usage: 'php frame auth:purge [--dry-run]'
)]
final class AuthPurgeCommand extends Command
{
    private const TABLE = 'password_reset';

    public function __construct(
        private readonly Database $database,
        private readonly DatabaseConfig $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Hiçbir şey silmez, yalnızca silinecek satır sayısını raporlar'
        );
    }

    protected function handle(): ExitCode
    {
        $this->title('Parola Sıfırlama Token Temizliği');

        try {
            $pdo = $this->database->getConnection();
        } catch (Throwable $e) {
            $this->error('Veritabanına bağlanılamadı: ' . $e->getMessage());
            return ExitCode::FAILURE;
        }

        $table     = $this->db->prefix . self::TABLE;
        $qualified = $this->database->getDriver()->quoteIdentifier($table);

        try {
            $pdo->query('SELECT 1 FROM ' . $qualified . ' WHERE 1 = 0');
        } catch (Throwable) {
            $this->error("Tablo yok: {$table}");
            $this->note('Çalıştırın: php frame migrate');
            return ExitCode::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $count = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $qualified . ' WHERE expires_at <= CURRENT_TIMESTAMP'
            )?->fetchColumn();

            $total = (int) $pdo->query('SELECT COUNT(*) FROM ' . $qualified)?->fetchColumn();

            $this->table(
                ['Tablo', 'Toplam satır', 'Silinecek'],
                [[$table, number_format($total), number_format($count)]]
            );
            $this->newLine();
            $this->note('Deneme çalışması — hiçbir satır silinmedi.');

            return ExitCode::SUCCESS;
        }

        try {
            $statement = $pdo->prepare('DELETE FROM ' . $qualified . ' WHERE expires_at <= CURRENT_TIMESTAMP');
            $statement->execute();
        } catch (Throwable $e) {
            $this->error('Silme başarısız: ' . $e->getMessage());

            return ExitCode::FAILURE;
        }

        $this->success(number_format($statement->rowCount()) . ' süresi geçmiş token silindi.');

        return ExitCode::SUCCESS;
    }
}
