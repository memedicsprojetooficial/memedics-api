<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportLegacyDatabase extends Command
{
    protected $signature = 'import:legacy {--dry-run : Only show row counts, do not write anything} {--force : Import even if destination tables already have data}';

    protected $description = 'Importa os dados do banco legado (clava_legacy) para o schema atual, preservando os IDs originais';

    /**
     * IDs dos médicos inativos, replicados da migration 2026_05_19_120100_soft_delete_inactive_doctors,
     * que roda antes dos dados existirem e por isso não tem efeito durante a migração.
     */
    private const INACTIVE_DOCTOR_IDS = [4, 32, 89, 90, 91, 6, 7, 10, 12, 13, 15, 16, 17, 18, 19, 20, 21, 23, 24, 25, 27, 28, 29, 30, 31, 33, 34, 38, 39, 40, 41, 43, 42, 44, 45, 46, 48, 49, 50, 51, 52, 54, 56, 57, 58, 59, 62, 63, 65, 67, 69, 71, 72, 73, 74, 77, 80];

    /**
     * `specialties` e `councils` ficam de fora de propósito: vêm dos seeders, que trazem as mesmas
     * chaves do legado mais as colunas novas (`actuation` e `description`). Ver LegacySetup.
     *
     * Ordem de importação respeitando dependências de FK.
     * Cada entrada: tabela => [colunas do destino => expressão SQL de origem].
     * Colunas ausentes aqui simplesmente não são preenchidas (ficam NULL/default).
     */
    private const TABLES = [
        'plans' => [
            'id' => 'id', 'old_id' => 'old_id', 'name' => 'name', 'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'users' => [
            'id' => 'id', 'name' => 'name', 'email' => 'email', 'email_verified_at' => 'email_verified_at',
            'password' => 'password', 'two_factor_secret' => 'two_factor_secret',
            'two_factor_recovery_codes' => 'two_factor_recovery_codes', 'type' => 'type',
            'profile_id' => 'profile_id', 'admin' => 'admin', 'remember_token' => 'remember_token',
            'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'employees' => [
            'id' => 'id', 'access_all_schedules' => 'access_all_schedules',
            'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'doctors' => [
            'id' => 'id', 'old_id' => 'old_id', 'unit_addresses_id' => 'unit_addresses_id', 'cpf' => 'cpf',
            'council_type' => 'council_type', 'council_number' => 'council_number', 'specialty_id' => 'specialty_id',
            'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'patients' => [
            'id' => 'id', 'old_id' => 'old_id', 'name' => 'name', 'birthday' => 'birthday', 'gender' => 'gender',
            'document' => 'document', 'phone' => 'phone', 'phone2' => 'phone2', 'email' => 'email',
            'created_at' => 'created_at', 'updated_at' => 'updated_at', 'deleted_at' => 'deleted_at',
        ],
        'addresses' => [
            'id' => 'id', 'street' => 'street', 'number' => 'number', 'complementary' => 'complementary',
            'neighborhood' => 'neighborhood', 'city' => 'city', 'state' => 'state', 'zip_code' => 'zip_code',
            'patient_id' => 'patient_id', 'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'cid' => [
            'id' => 'id', 'old_id' => 'old_id', 'description' => 'description', 'code' => 'code',
            'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'appointments' => [
            'id' => 'id', 'patient_id' => 'patient_id', 'plan_id' => 'plan_id', 'type' => 'type',
            'comment' => 'comment', 'status' => 'status', 'created_at' => 'created_at',
            'updated_at' => 'updated_at', 'deleted_at' => 'deleted_at',
            'previously_scheduled' => 'previously_scheduled',
        ],
        'events' => [
            'id' => 'id', 'date' => 'date', 'time' => 'time', 'duration' => 'duration', 'doctor_id' => 'doctor_id',
            'type' => 'type', 'event_id' => 'event_id', 'created_at' => 'created_at', 'updated_at' => 'updated_at',
            'deleted_at' => 'deleted_at',
        ],
        'blocked_times' => [
            'id' => 'id', 'reason' => 'reason', 'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'payments' => [
            'id' => 'id', 'appointment_id' => 'appointment_id', 'amount' => 'amount', 'description' => 'description',
            'created_at' => 'created_at', 'updated_at' => 'updated_at', 'deleted_at' => 'deleted_at',
        ],
        'medical_reports' => [
            'id' => 'id', 'old_id' => 'old_id', 'appointment_id' => 'appointment_id', 'date' => 'date', 'time' => 'time',
            'duration' => 'duration', 'status' => 'status', 'doctor_id' => 'doctor_id', 'patient_id' => 'patient_id',
            'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'report_tabs' => [
            'id' => 'id', 'old_id' => 'old_id', 'name' => 'name', 'doctor_id' => 'doctor_id',
            'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'report_fields' => [
            'id' => 'id', 'old_id' => 'old_id', 'name' => 'name', 'type' => 'type', 'columns' => 'columns',
            'report_tab_id' => 'report_tab_id', 'hidden' => 'hidden',
            'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'report_field_data' => [
            'id' => 'id', 'report_field_id' => 'report_field_id', 'report_id' => 'report_id', 'value' => 'value',
            'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
    ];

    public function handle(): int
    {
        $legacyDb = config('database.connections.legacy.database');
        $mainDb = config('database.connections.mysql.database');

        if (!DB::connection('legacy')->getDatabaseName()) {
            $this->error('Conexão "legacy" não configurada.');

            return self::FAILURE;
        }

        $this->info("Origem: {$legacyDb}  →  Destino: {$mainDb}");

        $counts = [];

        foreach (array_keys(self::TABLES) as $table) {
            $legacyCount = DB::connection('legacy')->table($table)->count();
            $destCount = DB::table($table)->count();
            $counts[] = [$table, $legacyCount, $destCount];
        }

        $this->table(['Tabela', 'Linhas no legado', 'Linhas no destino (antes)'], $counts);

        if ($this->option('dry-run')) {
            $this->info('Dry-run: nenhuma alteração foi feita.');

            return self::SUCCESS;
        }

        $nonEmpty = array_filter($counts, fn($row) => $row[2] > 0);

        if ($nonEmpty && !$this->option('force')) {
            $this->error('As tabelas de destino abaixo já possuem dados. Use --force para importar mesmo assim (pode gerar duplicatas/erros de chave única).');
            $this->table(['Tabela', 'Linhas existentes'], array_map(fn($row) => [$row[0], $row[2]], $nonEmpty));

            return self::FAILURE;
        }

        $companyId = $this->createPlaceholderCompany();

        $this->copyUnitAddresses($companyId);
        $summary = [['unit_addresses', DB::table('unit_addresses')->count()]];

        foreach (self::TABLES as $table => $columns) {
            $summary[] = [$table, $this->copyTable($table, $columns)];
        }

        $this->newLine();
        $this->table(['Tabela', 'Linhas importadas'], $summary);

        $this->softDeleteInactiveDoctors();

        return $this->verify();
    }

    /**
     * A migration que marca os médicos inativos roda com o banco ainda vazio,
     * então o soft delete precisa ser reaplicado depois que os dados chegam.
     */
    private function softDeleteInactiveDoctors(): void
    {
        $affected = DB::table('doctors')
            ->whereIn('id', self::INACTIVE_DOCTOR_IDS)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        $this->info("Médicos inativos marcados como excluídos: {$affected}");
    }

    /**
     * Confere linha a linha se o destino recebeu tudo o que existia na origem.
     */
    private function verify(): int
    {
        $rows = [];
        $ok = true;

        foreach (array_merge(['unit_addresses' => []], self::TABLES) as $table => $columns) {
            $source = DB::connection('legacy')->table($table)->count();
            $dest = DB::table($table)->count();
            $matches = $source === $dest;
            $ok = $ok && $matches;

            $rows[] = [$table, $source, $dest, $matches ? 'OK' : 'DIVERGENTE'];
        }

        $this->newLine();
        $this->table(['Tabela', 'Origem', 'Destino', 'Conferência'], $rows);

        if (!$ok) {
            $this->error('Import concluído com divergências de contagem. Verifique as tabelas marcadas acima.');

            return self::FAILURE;
        }

        $this->info('Import concluído e conferido: todas as tabelas batem com a origem.');

        return self::SUCCESS;
    }

    private function createPlaceholderCompany(): int
    {
        $company = Company::query()->firstOrCreate(
            ['cnpj' => '00000000000000'],
            ['company_name' => 'Clava Consult (importado)']
        );

        $this->info("Company placeholder: #{$company->id} - {$company->company_name}");

        return $company->id;
    }

    private function copyTable(string $table, array $columns): int
    {
        $destColumns = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($columns)));
        $sourceColumns = implode(', ', array_values($columns));

        return DB::transaction(function () use ($table, $destColumns, $sourceColumns) {
            DB::statement("INSERT INTO `{$table}` ({$destColumns}) SELECT {$sourceColumns} FROM `clava_legacy`.`{$table}`");

            return DB::table($table)->count();
        });
    }

    private function copyUnitAddresses(int $companyId): void
    {
        DB::transaction(function () use ($companyId) {
            DB::statement(
                'INSERT INTO `unit_addresses` (id, company_id, unit_name, street, number, complementary, neighborhood, city, state, zip_code, created_at, updated_at) '
                . "SELECT id, {$companyId}, unit_name, street, number, complementary, neighborhood, city, state, zip_code, created_at, updated_at FROM `clava_legacy`.`unit_addresses`"
            );
        });
    }
}
