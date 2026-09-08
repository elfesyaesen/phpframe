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

/**
 * Süresi geçmiş parola sıfırlama token'larının temizliği.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN GEREKLİ: `password_reset` tablosuna her talepte bir satır yazılır ve
 * satır YALNIZCA link kullanıldığında silinir. Kullanılmayan linkler — ki
 * çoğunluk bunlar — tabloda süresiz kalır. `monitor:purge` ile aynı
 * operasyonel açık: temizlenmeyen bir tablo sınırsız büyür.
 *
 * Süresi geçmiş bir kayıt zaten İŞE YARAMAZ: `PasswordResetModel::findValid()`
 * `expires_at > NOW()` koşuluyla arar, yani eski satırlar güvenlik açığı
 * değildir. Ama tutmanın da bir faydası yok ve iki bedeli var: tablo büyür,
 * ve hangi hesapların ne zaman sıfırlama istediği bilgisi süresiz durur.
 *
 * Cron'a bağlanmalıdır:
 *   15 4 * * * cd /path/to/app && php frame auth:purge
 *
 * `frame` CLI'da Container kurulmadığı için bağımlılıklar elle kurulur —
 * `MonitorPurgeCommand` ile aynı kalıp.
 * ─────────────────────────────────────────────────────────────────────────
 */
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

        // Karşılaştırma DB saatinde (`NOW()`) yapılır, PHP saatinde DEĞİL.
        // `expires_at` bir TIMESTAMP kolonu; PHP tarafı APP_TIMEZONE'da
        // çalışır ve iki dilim farklıysa PHP'de üretilen bir kesim tarihi
        // yanlış aralığı siler. `findValid()` de aynı `NOW()`'u kullanıyor,
        // yani silme ölçütü geçerlilik ölçütüyle BİREBİR aynı çerçevede.
        if ((bool) $this->option('dry-run')) {
            $count = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $qualified . ' WHERE expires_at <= NOW()'
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

        // SQL BURADA, `PasswordResetModel`'de DEĞİL.
        //
        // `MonitorPurgeCommand` işi `DatabaseStorage::purge()`'a devrediyor;
        // buradaki karşılığı `PasswordResetModel::purgeExpired()` olurdu ama
        // o sınıf `Api\` katmanında. Katman sözleşmesi (docs/api-layer.md)
        // `System\`'in `Api\`'yi ASLA bilmemesini şart koşuyor — bir bakım
        // komutu bu kuralın istisnası değil. Tablo adı yalnızca bir string;
        // sınıf bağımlılığı doğmuyor.
        $statement = $pdo->prepare('DELETE FROM ' . $qualified . ' WHERE expires_at <= NOW()');
        $statement->execute();

        $this->success(number_format($statement->rowCount()) . ' süresi geçmiş token silindi.');

        return ExitCode::SUCCESS;
    }
}
