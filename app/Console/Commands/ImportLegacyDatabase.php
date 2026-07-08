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
     * Ordem de importação respeitando dependências de FK.
     * Cada entrada: tabela => [colunas do destino => expressão SQL de origem].
     * Colunas ausentes aqui simplesmente não são preenchidas (ficam NULL/default).
     */
    private const TABLES = [
        'specialties' => [
            'id' => 'id', 'name' => 'name', 'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'councils' => [
            'id' => 'id', 'council_name' => 'council_name', 'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'plans' => [
            'id' => 'id', 'old_id' => 'id', 'name' => 'name', 'created_at' => 'created_at', 'updated_at' => 'updated_at',
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
            'id' => 'id', 'old_id' => 'id', 'unit_addresses_id' => 'unit_addresses_id', 'cpf' => 'cpf',
            'council_type' => 'council_type', 'council_number' => 'council_number', 'specialty_id' => 'specialty_id',
            'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'patients' => [
            'id' => 'id', 'old_id' => 'id', 'name' => 'name', 'birthday' => 'birthday', 'gender' => 'gender',
            'document' => 'document', 'phone' => 'phone', 'phone2' => 'phone2', 'email' => 'email',
            'created_at' => 'created_at', 'updated_at' => 'updated_at', 'deleted_at' => 'deleted_at',
        ],
        'addresses' => [
            'id' => 'id', 'street' => 'street', 'number' => 'number', 'complementary' => 'complementary',
            'neighborhood' => 'neighborhood', 'city' => 'city', 'state' => 'state', 'zip_code' => 'zip_code',
            'patient_id' => 'patient_id', 'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'cid' => [
            'id' => 'id', 'old_id' => 'id', 'description' => 'description', 'code' => 'code',
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
            'id' => 'id', 'old_id' => 'id', 'appointment_id' => 'appointment_id', 'date' => 'date', 'time' => 'time',
            'duration' => 'duration', 'status' => 'status', 'doctor_id' => 'doctor_id', 'patient_id' => 'patient_id',
            'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'report_tabs' => [
            'id' => 'id', 'old_id' => 'id', 'name' => 'name', 'doctor_id' => 'doctor_id',
            'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ],
        'report_fields' => [
            'id' => 'id', 'old_id' => 'id', 'name' => 'name', 'type' => 'type', 'columns' => 'columns',
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
        $this->info('Import concluído.');
        $this->table(['Tabela', 'Linhas importadas'], $summary);

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
