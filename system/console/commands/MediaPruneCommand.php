<?php

declare(strict_types=1);

namespace System\Console\Commands;

use DateInterval;
use DateTimeImmutable;
use FilesystemIterator;
use PDO;
use System\Config\DatabaseConfig;
use System\Config\MediaConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputOption;
use System\Database\Database;
use System\Helpers\Uuid;
use System\Media\Media;
use System\Media\MediaDisk;
use Throwable;

#[AsCommand(
    name: 'media:prune',
    description: 'Silinmiş medya dosyalarını ve yetim dosyaları kalıcı olarak temizler',
    usage: 'php frame media:prune [--days=30] [--orphans] [--dry-run]'
)]
final class MediaPruneCommand extends Command
{
    private const BATCH = 500;

    private const ORPHAN_GRACE = 3600;

    public function __construct(
        private readonly Database $database,
        private readonly DatabaseConfig $db,
        private readonly MediaConfig $media,
        private readonly MediaDisk $disk,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Kaç gün önce silinmiş kayıtlar temizlensin', 30);
        $this->addOption('orphans', null, InputOption::VALUE_NONE, 'Veritabanında karşılığı olmayan klasörleri de sil');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Hiçbir şey silmez, yalnızca raporlar');
    }

    protected function handle(): ExitCode
    {
        $days = $this->option('days', 30);
        if (!(is_int($days) || (is_string($days) && ctype_digit($days))) || (int) $days < 1) {
            $this->error("--days pozitif bir tam sayı olmalı, verilen: '" . (is_scalar($days) ? $days : get_debug_type($days)) . "'");

            return ExitCode::INVALID;
        }

        $days = (int) $days;
        $dry  = (bool) $this->option('dry-run');

        $this->title('Medya Temizliği');

        try {
            $pdo = $this->database->getConnection();
        } catch (Throwable $e) {
            $this->error('Veritabanına bağlanılamadı: ' . $e->getMessage());

            return ExitCode::FAILURE;
        }

        $table = $this->database->getDriver()->quoteIdentifier($this->db->prefix . 'media');

        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' WHERE 1 = 0');
        } catch (Throwable) {
            $this->error('media tablosu yok. Çalıştırın: php frame migrate');

            return ExitCode::FAILURE;
        }

        $cutoff = $this->cutoff($pdo, $days);
        $this->writeln('Kesim tarihi: ' . $cutoff->format('Y-m-d H:i:s') . " ({$days} gün önce silinmiş)");
        $this->newLine();

        [$records, $files] = $this->pruneDeleted($pdo, $table, $cutoff, $dry);

        $rows = [['Silinmiş kayıt', number_format($records)], ['  dosya', number_format($files)]];

        if ($this->option('orphans')) {
            [$dirs, $orphanFiles] = $this->pruneOrphans($pdo, $table, $dry);
            $rows[] = ['Yetim klasör', number_format($dirs)];
            $rows[] = ['  dosya', number_format($orphanFiles)];
        }

        $this->table(['', $dry ? 'Silinecek' : 'Silindi'], $rows);
        $this->newLine();

        $dry
            ? $this->note('Deneme çalışması — hiçbir şey silinmedi.')
            : $this->success('Temizlik tamamlandı.');

        return ExitCode::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pruneDeleted(PDO $pdo, string $table, DateTimeImmutable $cutoff, bool $dry): array
    {
        $records = 0;
        $files   = 0;
        $afterId = 0;

        while (true) {
            $statement = $pdo->prepare(
                'SELECT id, uuid, collection, visibility, mime, size, sha256, variants, created_at, deleted_at FROM ' . $table
                . ' WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff AND id > :after ORDER BY id LIMIT ' . self::BATCH
            );
            $statement->execute(['cutoff' => $cutoff->format('Y-m-d H:i:s'), 'after' => $afterId]);
            $batch = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                $afterId = (int) $row['id'];
                $media   = Media::fromRow($row);
                $keys    = $media->keys();

                $records++;
                $files += count($keys);

                if ($dry) {
                    continue;
                }

                foreach ($keys as $key) {
                    $this->disk->delete($key);
                }

                $this->removeEmptyDirectory($keys[0] ?? null);

                $pdo->prepare('DELETE FROM ' . $table . ' WHERE id = :id')->execute(['id' => $media->id]);
            }
        }

        return [$records, $files];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pruneOrphans(PDO $pdo, string $table, bool $dry): array
    {
        $root = rtrim($this->media->storagePath, '/\\');
        $dirs = [];

        foreach (['public', 'private'] as $visibility) {
            foreach (glob($root . '/' . $visibility . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $uuid = basename($dir);

                if (Uuid::isValid($uuid) && (time() - (int) filemtime($dir)) > self::ORPHAN_GRACE) {
                    $dirs[strtolower($uuid)] = $dir;
                }
            }
        }

        $orphanDirs = 0;
        $orphanFiles = 0;

        foreach (array_chunk(array_keys($dirs), self::BATCH) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $statement    = $pdo->prepare('SELECT uuid FROM ' . $table . ' WHERE uuid IN (' . $placeholders . ')');
            $statement->execute($chunk);
            $known = array_flip(array_map('strtolower', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []));

            foreach ($chunk as $uuid) {
                if (isset($known[$uuid])) {
                    continue;
                }

                $orphanDirs++;
                $orphanFiles += $this->deleteDirectory($dirs[$uuid], $dry);
            }
        }

        return [$orphanDirs, $orphanFiles];
    }

    private function deleteDirectory(string $dir, bool $dry): int
    {
        $count = 0;

        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }

            $count++;

            if (!$dry) {
                @unlink($entry->getPathname());
            }
        }

        if (!$dry) {
            @rmdir($dir);
        }

        return $count;
    }

    private function removeEmptyDirectory(?string $key): void
    {
        if ($key === null) {
            return;
        }

        $dir = rtrim($this->media->storagePath, '/\\') . DIRECTORY_SEPARATOR . dirname($key);

        if (is_dir($dir) && (glob($dir . '/*') ?: []) === []) {
            @rmdir($dir);
        }
    }

    private function cutoff(PDO $pdo, int $days): DateTimeImmutable
    {
        $now = new DateTimeImmutable();

        try {
            $value = $pdo->query('SELECT CURRENT_TIMESTAMP')?->fetchColumn();

            if (is_string($value) && $value !== '') {
                $now = new DateTimeImmutable($value);
            }
        } catch (Throwable) {
            $this->warning('Veritabanı saati okunamadı; PHP saati kullanılıyor.');
        }

        return $now->sub(new DateInterval('P' . $days . 'D'));
    }
}
