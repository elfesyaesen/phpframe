<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\Commands\Concerns\BuildsContainer;
use System\Console\ExitCode;
use System\Console\Input\InputOption;
use System\Container\Definition\Definition;
use System\Container\Lifetime\Lifetime;
use Throwable;

#[AsCommand(
    name: 'container:list',
    description: 'Kayıtlı servisleri listeler (lifetime, kurulum, kaynak)',
    usage: 'php frame container:list [--lifetime=scoped] [--filter=Api\\] [--uncompiled] [--compiled]',
    aliases: ['c:l']
)]
final class ContainerListCommand extends Command
{
    use BuildsContainer;

    protected function configure(): void
    {
        $this->addOption(
            'lifetime',
            'l',
            InputOption::VALUE_REQUIRED,
            'Lifetime\'a göre süz: transient|singleton|scoped|instance'
        );

        $this->addOption(
            'filter',
            null,
            InputOption::VALUE_REQUIRED,
            'Id öneki/parçasına göre süz (örn. Api\\)'
        );

        $this->addOption(
            'uncompiled',
            'u',
            description: 'Derleme kapsamına GİRMEYEN sınıfları ve sebeplerini listeler'
        );

        $this->addOption(
            'compiled',
            'c',
            description: 'Builder yerine canlı derlemenin metadata\'sını okur'
        );
    }

    protected function handle(): ExitCode
    {
        try {
            $result = $this->compiler()->compile(generate: false);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return ExitCode::FAILURE;
        }

        // ── --uncompiled: kapsam boşluğunu görünür kıl ─────────────
        //
        // Root-set seçimi compiler'ın en zor kararı: fazla dar seçmek gerçek
        // bir servisi kaçırır. Bu liste o boşluğu sessiz bırakmaz.
        if ((bool) $this->option('uncompiled')) {
            return $this->listUncompiled($result);
        }

        if ((bool) $this->option('compiled')) {
            return $this->listFromMetadata();
        }

        $definitions = $this->filter($result->definitions);

        if ($definitions === []) {
            $this->warning('Süzgeçlere uyan servis yok.');

            return ExitCode::SUCCESS;
        }

        $this->title('Servisler (' . count($definitions) . '/' . $result->serviceCount() . ')');

        $rows = [];
        foreach ($definitions as $id => $definition) {
            $rows[] = [
                $this->shorten($id),
                $this->lifetimeLabel($definition->lifetime),
                $this->shorten($definition->describeConstruction()),
                (string) count($definition->edges()),
                $definition->source ?? '—',
            ];
        }

        $this->table(['servis', 'lifetime', 'kurulum', 'dep', 'kaynak'], $rows);

        $this->newLine();
        $this->writeln($this->output->gray(
            '  ' . $this->lifetimeBreakdown($result->definitions)
        ));

        $this->renderPhaseFiveSummary();

        return ExitCode::SUCCESS;
    }

    /**
     * @param \System\Container\Compilation\CompilationResult $result
     */
    private function listUncompiled($result): ExitCode
    {
        $this->title('Derleme kapsamına girmeyenler (' . count($result->skipped) . ')');

        if ($result->skipped === []) {
            $this->success('Tüm erişilebilir sınıflar derlendi.');

            return ExitCode::SUCCESS;
        }

        $rows = [];
        foreach ($result->skipped as $id => $reason) {
            $rows[] = [$this->shorten($id), $reason];
        }

        $this->table(['sınıf', 'sebep'], $rows);

        $this->newLine();
        $this->note(
            'Atlanan bir sınıf production\'da istenirse SERVICE_NOT_COMPILED hatası' . "\n"
            . 'verir (reflection fallback yoktur). Gerçekten servis olması gerekiyorsa' . "\n"
            . 'bir provider\'da kaydet.'
        );

        return ExitCode::SUCCESS;
    }

    private function listFromMetadata(): ExitCode
    {
        $metadata = $this->compiledMetadata();

        if ($metadata === null) {
            $this->error(
                'Derlenmiş container yok veya metadata okunamadı.' . "\n"
                . '  php frame container:compile'
            );

            return ExitCode::FAILURE;
        }

        /** @var array<string, array<string, mixed>> $services */
        $services = $metadata['services'] ?? [];

        $lifetime = $this->option('lifetime');
        $filter = $this->option('filter');

        $rows = [];
        foreach ($services as $id => $info) {
            if (is_string($lifetime) && ($info['lifetime'] ?? '') !== strtolower($lifetime)) {
                continue;
            }

            if (is_string($filter) && !str_contains($id, $filter)) {
                continue;
            }

            $rows[] = [
                $this->shorten($id),
                (string) ($info['lifetime'] ?? '?'),
                $this->shorten((string) ($info['construction'] ?? '?')),
                (string) count((array) ($info['dependencies'] ?? [])),
                (string) ($info['root'] ?? '—'),
            ];
        }

        $this->title('Derlenmiş servisler (' . count($rows) . '/' . count($services) . ')');
        $this->writeln($this->output->gray(
            '  hash ' . ($metadata['hash'] ?? '?') . ' — ' . ($metadata['generated'] ?? '?')
        ));
        $this->newLine();

        $this->table(['servis', 'lifetime', 'kurulum', 'dep', 'kök'], $rows);

        return ExitCode::SUCCESS;
    }

    /**
     * @param array<string, Definition> $definitions
     * @return array<string, Definition>
     */
    private function filter(array $definitions): array
    {
        $lifetime = $this->option('lifetime');
        $filter = $this->option('filter');

        if (is_string($lifetime)) {
            $target = Lifetime::tryFrom(strtolower($lifetime));

            if ($target === null) {
                $this->warning(
                    "Bilinmeyen lifetime: '{$lifetime}' — "
                    . 'geçerli: transient, singleton, scoped, instance'
                );
            } else {
                $definitions = array_filter(
                    $definitions,
                    static fn(Definition $d): bool => $d->lifetime === $target
                );
            }
        }

        if (is_string($filter)) {
            $definitions = array_filter(
                $definitions,
                static fn(Definition $d, string $id): bool => str_contains($id, $filter),
                ARRAY_FILTER_USE_BOTH
            );
        }

        return $definitions;
    }

    /**
     * Faz 5 kayıtlarının özeti: etiketler, dekoratörler, bağlamsal binding'ler.
     *
     * Hiçbiri yoksa hiçbir şey basılmaz — bu özellikler opsiyoneldir ve
     * kullanılmadıklarında çıktıyı kirletmemeleri gerekir.
     */
    private function renderPhaseFiveSummary(): void
    {
        $builder = $this->builder();
        $tags = $builder->tags();
        $decorators = $builder->decorators();
        $contextual = $builder->contextual();

        if ($tags->isEmpty() && $decorators->isEmpty() && $contextual->isEmpty()) {
            return;
        }

        if (!$tags->isEmpty()) {
            $this->newLine();
            $this->section('Etiketler (§20)');

            foreach ($tags->all() as $tag => $members) {
                $this->writeln('  #' . $tag . $this->output->gray('  (' . count($members) . ' üye, sıralı)'));

                foreach ($members as $index => $member) {
                    $this->writeln('    ' . ($index + 1) . '. ' . $this->shorten($member));
                }
            }
        }

        if (!$decorators->isEmpty()) {
            $this->newLine();
            $this->section('Dekoratörler (§21)');

            foreach ($decorators->all() as $id => $chain) {
                // En dış SON eklenendir; okunurluk için dıştan içe yazılır.
                $layers = array_map(
                    static fn(array $layer): string => $layer['class'],
                    array_reverse($chain)
                );

                $this->writeln('  ' . $this->shorten($id) . $this->output->gray('  ← ')
                    . implode($this->output->gray(' → '), array_map(
                        fn(string $c): string => $this->shorten($c, 34),
                        $layers
                    )));
            }
        }

        if (!$contextual->isEmpty()) {
            $this->newLine();
            $this->section('Bağlamsal binding (§12)');

            foreach ($contextual->all() as $binding) {
                $this->writeln('  ' . $binding->describe());
            }
        }
    }

    /**
     * @param array<string, Definition> $definitions
     */
    private function lifetimeBreakdown(array $definitions): string
    {
        $counts = [];

        foreach (Lifetime::cases() as $lifetime) {
            $counts[$lifetime->value] = 0;
        }

        foreach ($definitions as $definition) {
            $counts[$definition->lifetime->value]++;
        }

        $parts = [];
        foreach ($counts as $label => $count) {
            $parts[] = $count . ' ' . $label;
        }

        return implode(', ', $parts);
    }

    private function lifetimeLabel(Lifetime $lifetime): string
    {
        return match ($lifetime) {
            // Scoped görsel olarak öne çıkarılır: scope hatalarının kaynağı
            // neredeyse her zaman bir servisin lifetime'ının yanlış olmasıdır.
            Lifetime::SCOPED    => $this->output->cyan($lifetime->value),
            Lifetime::SINGLETON => $this->output->yellow($lifetime->value),
            Lifetime::INSTANCE  => $this->output->gray($lifetime->value),
            Lifetime::TRANSIENT => $lifetime->value,
        };
    }

    private function shorten(string $value, int $max = 52): string
    {
        return strlen($value) <= $max ? $value : '…' . substr($value, -($max - 1));
    }
}
