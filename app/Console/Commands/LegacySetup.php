<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Facilitador da migração do sistema legado (clava_legacy) para o schema atual.
 *
 * Encadeia as três etapas na ordem correta:
 *   1. migrations  — cria o schema novo do zero
 *   2. seeders     — popula as tabelas de referência (councils, specialties)
 *   3. import      — transfere os dados do legado preservando os IDs
 */
class LegacySetup extends Command
{
    protected $signature = 'legacy:setup
        {--fresh : Recria o banco de destino do zero (migrate:fresh) — APAGA todos os dados existentes}
        {--dry-run : Apenas mostra o plano e as contagens, sem escrever nada}';

    protected $description = 'Executa a migração completa do sistema legado: migrations, seeders e importação dos dados';

    /**
     * Seeders de dados de referência.
     *
     * A ordem importa: `councils` e `specialties` não dependem de nada, mas precisam existir
     * antes dos médicos chegarem, porque `doctors.specialty_id` aponta para `specialties.id`
     * e `doctors.council_type` casa com `councils.council_name`.
     *
     * Os demais seeders do projeto (Doctor, Patient, Plan, User, WorkTime…) são dados de
     * demonstração e ficam de fora: esses registros vêm do legado.
     */
    private const REFERENCE_SEEDERS = [
        \Database\Seeders\CouncilSeeder::class,
        \Database\Seeders\SpecialtySeeder::class,
    ];

    public function handle(): int
    {
        $legacy = config('database.connections.legacy.database');
        $target = config('database.connections.mysql.database');

        $this->info("Migração legado → atual:  {$legacy}  →  {$target}");
        $this->newLine();

        try {
            DB::connection('legacy')->getPdo();
        } catch (Throwable $e) {
            $this->error("Não foi possível conectar no banco legado ({$legacy}).");
            $this->line('Carregue o dump antes de rodar este comando:');
            $this->line("  mysql -u<user> -p -e 'CREATE DATABASE {$legacy}'");
            $this->line("  mysql -u<user> -p {$legacy} < backup-import/backup.sql");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->plan($legacy, $target);
        }

        if ($this->option('fresh')) {
            $this->warn("Recriando o banco {$target} do zero — todos os dados atuais serão perdidos.");

            if (!$this->confirm('Confirma?', false)) {
                $this->info('Cancelado.');

                return self::SUCCESS;
            }

            $this->step('1/3  Recriando o schema (migrate:fresh)');
            $this->call('migrate:fresh', ['--force' => true]);
        } else {
            $this->step('1/3  Aplicando as migrations pendentes');
            $this->call('migrate', ['--force' => true]);
        }

        $this->step('2/3  Populando as tabelas de referência');

        foreach (self::REFERENCE_SEEDERS as $seeder) {
            if ($this->alreadySeeded($seeder)) {
                $this->line("  {$seeder} — já populado, pulando.");
                continue;
            }

            $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        $this->step('3/3  Importando os dados do legado');

        return $this->call('import:legacy');
    }

    /**
     * Evita duplicar dados de referência quando o comando é reexecutado sem --fresh.
     */
    private function alreadySeeded(string $seeder): bool
    {
        $table = $seeder === \Database\Seeders\CouncilSeeder::class ? 'councils' : 'specialties';

        return DB::table($table)->exists();
    }

    private function plan(string $legacy, string $target): int
    {
        $this->line('Plano de execução (nada será escrito):');
        $this->newLine();
        $this->line('  1. migrations  — ' . count(glob(database_path('migrations/*.php'))) . ' arquivos, aplicados em ordem de nome');
        $this->line('  2. seeders     — ' . implode(', ', array_map('class_basename', self::REFERENCE_SEEDERS)));
        $this->line('  3. import      — dados do legado preservando os IDs originais');
        $this->newLine();

        $tables = DB::connection('legacy')->select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$legacy]
        );

        $rows = [];

        foreach ($tables as $table) {
            $rows[] = [$table->name, DB::connection('legacy')->table($table->name)->count()];
        }

        $this->table(["Tabela em {$legacy}", 'Linhas'], $rows);
        $this->info("Destino: {$target}. Rode sem --dry-run para executar.");

        return self::SUCCESS;
    }

    private function step(string $title): void
    {
        $this->newLine();
        $this->line("<fg=cyan>── {$title} " . str_repeat('─', max(0, 50 - strlen($title))) . '</>');
    }
}
