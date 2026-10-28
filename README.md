# MeMedics API

API do MeMedics, sistema de gestão de clínicas: agenda dos médicos, pacientes, prontuário,
pagamentos e o atendimento pelo WhatsApp (bot no n8n e confirmação de consulta pela WAHA).

- Laravel 8, PHP 8.1, MySQL.
- Painel web: repositório `memedics-web` (React + Vite).
- Em produção: `https://api.memedics.com.br`, na AWS Elastic Beanstalk.

## Documentação

- [Guia de operação](docs/OPERACAO.md): publicar, variáveis de ambiente, migrations, parear o
  WhatsApp de uma unidade, pausar o bot e resolver problemas comuns.
- [Migração para a WAHA](MIGRACAO_WAHA.md): histórico da integração com o WhatsApp e do bot.

## Rodar localmente

O projeto usa o Laravel Sail (Docker):

```bash
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```
