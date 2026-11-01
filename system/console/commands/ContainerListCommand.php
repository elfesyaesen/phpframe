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
    usage: 'php frame container:list [--lifetime=scoped] [--filter=App\\Api] [--skipped]',
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
            'Id parçasına göre süz, büyük/küçük harf duyarsız (örn. App\\Api)'
        );

        $this->addOption(
            'skipped',
            's',
            description: 'Analiz kapsamına GİRMEYEN sınıfları ve sebeplerini listeler'
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

        if ((bool) $this->option('skipped')) {
            return $this->listSkipped($result);
        }

        $lifetime = $this->option('lifetime');
        if (is_string($lifetime) && Lifetime::tryFrom(strtolower($lifetime)) === null) {
            $this->error("Bilinmeyen lifetime: '{$lifetime}' — geçerli: transient, singleton, scoped, instance");

            return ExitCode::INVALID;
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
    private function listSkipped($result): ExitCode
    {
        $this->title('Analiz kapsamına girmeyenler (' . count($result->skipped) . ')');

        if ($result->skipped === []) {
            $this->success('Tüm erişilebilir sınıflar analiz edildi.');

            return ExitCode::SUCCESS;
        }

        $rows = [];
        foreach ($result->skipped as $id => $reason) {
            $rows[] = [$this->shorten($id), $reason];
        }

        $this->table(['sınıf', 'sebep'], $rows);

        $this->newLine();
        $this->note(
            'Atlanan sınıflar çalışma anında yine autowire ile kurulabilir, ama' . "\n"
            . 'container:validate onları denetleyemez. Denetlenmesi gerekiyorsa' . "\n"
            . 'bir provider\'da kaydet.'
        );

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

        $target = is_string($lifetime) ? Lifetime::tryFrom(strtolower($lifetime)) : null;

        if ($target !== null) {
            $definitions = array_filter(
                $definitions,
                static fn(Definition $d): bool => $d->lifetime === $target
            );
        }

        if (is_string($filter)) {
            $definitions = array_filter(
                $definitions,
                static fn(Definition $d, string $id): bool => stripos($id, $filter) !== false,
                ARRAY_FILTER_USE_BOTH
            );
        }

        return $definitions;
    }

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
