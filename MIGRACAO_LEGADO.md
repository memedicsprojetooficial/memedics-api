# Migração do sistema legado (clava-consult → MeMedics)

Guia da migração de dados do sistema antigo para o schema atual. O caminho curto é um
comando só; o resto do documento explica as decisões por trás dele.

```bash
php artisan legacy:setup
```

## Pré-requisito: carregar o dump no banco de trabalho

O dump (`backup-import/backup.sql`) é um `mysqldump` do banco de produção antigo e traz
os próprios `CREATE TABLE` do schema **velho**. Ele nunca deve ser carregado direto no
banco de destino — sobrescreveria o schema novo pelo antigo. Carregue-o num banco
separado, que serve de origem somente-leitura:

```bash
./vendor/bin/sail mysql -e "CREATE DATABASE IF NOT EXISTS clava_legacy"
```

```bash
docker exec -i memedics-api-mysql-1 mysql -usail -p<senha> --default-character-set=utf8mb4 clava_legacy < backup-import/backup.sql
```

A conexão `legacy` já existe em `config/database.php` e aponta para `clava_legacy`
(configurável por `LEGACY_DB_DATABASE`).

### Duas armadilhas ao carregar o dump

**Sempre passe `--default-character-set=utf8mb4`.** O `mysqldump` original traz um
`/*!50503 SET NAMES utf8 */;` no cabeçalho que resolve isso sozinho, mas se o dump for
editado à mão essa linha costuma se perder. Sem ela o cliente lê os bytes UTF-8 como latin1,
cada acento vira dois caracteres e a carga morre com
`Data too long for column 'comment'` — um erro que parece de schema, mas é de charset.

**Instruções privilegiadas.** O dump pode conter `SET @@SESSION.SQL_LOG_BIN` e
`SET @@GLOBAL.GTID_PURGED`, que exigem privilégio SUPER e falham com o usuário comum. Elas
só tratam de replicação e não afetam os dados; remova-as do arquivo ou filtre na carga:

```bash
grep -v "^SET @@GLOBAL.GTID_PURGED\|SQL_LOG_BIN" backup-import/backup.sql | docker exec -i memedics-api-mysql-1 mysql -usail -p<senha> --default-character-set=utf8mb4 clava_legacy
```

### Quais tabelas o dump precisa conter

Bastam as 18 que o importador consome: `addresses`, `appointments`, `blocked_times`, `cid`,
`doctors`, `employees`, `events`, `medical_reports`, `password_resets`, `patients`,
`payments`, `personal_access_tokens`, `plans`, `report_field_data`, `report_fields`,
`report_tabs`, `unit_addresses`, `users`.

`councils` e `specialties` podem ficar de fora (vêm dos seeders), assim como as tabelas de
infraestrutura do Laravel (`migrations`, `jobs`, `job_batches`, `failed_jobs`, `imports`).

## 1. Ordem de execução das migrations

**O Laravel resolve sozinho.** As migrations rodam em ordem alfabética do nome do arquivo,
e como todas têm prefixo de timestamp, isso equivale à ordem cronológica. As 68 migrations
do projeto aplicam-se de ponta a ponta em banco vazio sem nenhuma intervenção manual:

```bash
php artisan migrate
```

Duas observações que valem para quem for mexer nelas:

- `2026_05_25_182446_add_unit_addresses_id_column_to_users_table` e
  `2026_05_25_182446_create_doctor_plan_table` têm **timestamp idêntico**. Hoje o desempate
  é alfabético e funciona porque não dependem uma da outra, mas é frágil: se um dia uma
  precisar da outra, renomeie para separar os timestamps.
- `2026_05_19_120100_soft_delete_inactive_doctors` é uma migration de **dados**, não de
  schema: ela marca 57 médicos como inativos. Rodando em banco vazio ela não faz nada, por
  isso o import a reaplica no final (ver etapa 3).

O `RODA_SEED.txt` na raiz é uma lista de `migrate --path=...` avulsos — servia para colocar
em dia um banco que já existia. Para uma migração do zero ele não é necessário.

## 2. Ordem de criação dos seeders

Só dois seeders entram na migração, e nesta ordem:

1. `CouncilSeeder` — 19 conselhos profissionais
2. `SpecialtySeeder` — 93 especialidades

Ambos são **dados de referência** e precisam existir antes dos médicos, porque
`doctors.specialty_id` aponta para `specialties.id` e `doctors.council_type` casa com
`councils.council_name`.

Os demais seeders do projeto (`DoctorSeeder`, `PatientSeeder`, `PlanSeeder`, `UserSeeder`,
`WorkTimeSeeder`, `DoctorPlanSeeder`, `UnitBusinessHourSeeder`) criam dados de demonstração
e **não devem ser executados numa migração real** — esses registros vêm do legado.

### Por que semear em vez de importar essas duas tabelas

O legado também tem `councils` e `specialties`, então haveria conflito. A escolha foi ficar
com os seeders, e ela é segura porque foi verificada:

- **Especialidades:** os 93 IDs do legado existem no seeder com os mesmos nomes, incluindo
  os 25 efetivamente usados por médicos. Nenhum `doctors.specialty_id` fica órfão. O seeder
  ainda preenche a coluna nova `actuation`, que o legado não tinha.
- **Conselhos:** o legado tem 5 (IDs 1–5), o seeder tem 19 com IDs diferentes. Isso não
  quebra nada porque `doctors.council_type` guarda a **sigla como texto** ("CRM", "CRN"…),
  não o ID. Os 4 valores em uso existem no seeder, que ainda traz a coluna `description`.

## 3. Importação dos dados

```bash
php artisan import:legacy
```

O comando copia o legado para o schema novo **preservando os IDs originais**, o que mantém
válidas todas as referências entre tabelas sem precisar de tabela de/para.

A comparação dos dois schemas mostrou o cenário mais simples possível: nenhuma coluna do
legado foi removida ou renomeada no schema atual, e as 11 colunas novas
(`appointments.public_token`, `patients.plan_id`, `doctors.phone`, `unit_addresses.company_id`,
`evolution_*`, etc.) são todas nullable. Por isso a cópia é um `INSERT ... SELECT` por tabela,
com lista explícita de colunas.

Ordem de cópia (respeita as FKs; `unit_addresses` vem primeiro porque `doctors` depende dela):

```
unit_addresses → plans → users → employees → doctors → patients → addresses → cid
→ appointments → events → blocked_times → payments → medical_reports
→ report_tabs → report_fields → report_field_data
```

O que o comando faz além de copiar:

- **Cria uma empresa placeholder** ("Clava Consult (importado)") e vincula as unidades a ela,
  porque `unit_addresses.company_id` tem FK para `companies`, tabela que não existia no legado.
- **Reaplica o soft delete** dos 57 médicos inativos, compensando a migration de dados que
  rodou com o banco vazio.
- **Confere as contagens** origem × destino ao final e falha se alguma divergir.

Tabelas deixadas de fora de propósito: `specialties` e `councils` (vêm dos seeders) e
`password_resets` / `personal_access_tokens` (dados de sessão descartáveis, ambos vazios).

### Opções

| Comando | Efeito |
|---|---|
| `php artisan legacy:setup --dry-run` | Mostra o plano e as contagens da origem, sem escrever |
| `php artisan legacy:setup` | Migrations pendentes + seeders + import |
| `php artisan legacy:setup --fresh` | Recria o banco do zero (`migrate:fresh`) e refaz tudo — pede confirmação |
| `php artisan import:legacy --dry-run` | Só a etapa de dados, em modo consulta |

## Resultado verificado

Execução completa contra o dump de 14/08/2026, com as 16 tabelas conferindo linha a linha:

| Tabela | Linhas | | Tabela | Linhas |
|---|---|---|---|---|
| events | 77.537 | | medical_reports | 19.146 |
| appointments | 75.129 | | cid | 14.198 |
| report_field_data | 55.530 | | report_fields | 3.326 |
| patients | 30.550 | | blocked_times | 1.654 |
| addresses | 21.585 | | report_tabs | 630 |
| payments | 20.946 | | users | 108 |
| doctors | 98 (41 ativos) | | plans | 29 |
| employees | 10 | | unit_addresses | 2 |

Validado depois do import: acentuação preservada (utf8mb4), `old_id` do OnMed intacto em
10.043 pacientes, e a API respondendo 200 em `/api/user`, `/api/doctors`, `/api/patients`,
`/api/specialties`, `/api/councils` e na agenda de um médico com relacionamentos aninhados.

## Problemas de integridade herdados do legado

Encontrados na origem, **não** causados pela migração. Nenhum viola FK do schema novo, então
não bloqueiam o import — mas convém decidir o que fazer com eles:

| Situação | Registros |
|---|---|
| `report_tabs` apontando para médico inexistente | 12 |
| `payments` apontando para consulta inexistente | 4 |
| `appointments` apontando para paciente inexistente | 1 |
| `events` de bloqueio sem `blocked_time` correspondente | 751 (todos já soft-deleted, inofensivos) |

## Aviso sobre o dump

`backup-import/backup.sql` contém dados reais de pacientes (prontuários, CPF, telefones) e
hashes de senha, e **está versionado no git** (commit `392f4a4`). Sob a LGPD isso é exposição
de dado sensível de saúde, e apagar o arquivo não basta — sai do working tree mas continua no
histórico. A remoção exige reescrever o histórico (`git filter-repo` ou BFG) e forçar o push,
coordenando com quem já clonou o repositório.
