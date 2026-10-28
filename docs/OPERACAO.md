# Guia de operação do MeMedics

Como publicar, configurar e manter o MeMedics funcionando: API, painel web, WhatsApp e bot.
Itens marcados com **(confirmar)** não puderam ser verificados pelo repositório; ajuste-os
quando souber o valor certo.

## Visão geral

| Peça | Onde roda | Endereço | Repositório |
|---|---|---|---|
| API (Laravel 8, PHP 8.1, MySQL) | AWS Elastic Beanstalk | `https://api.memedics.com.br` | `memedics-api` |
| Painel web (React + Vite) | Vercel | `https://app.memedics.com.br` | `memedics-web` |
| Bot de atendimento ("Claudia") e envio automático de confirmações | n8n | `https://n8n.memedics.com.br` | workflows no próprio n8n |
| WhatsApp | WAHA (WhatsApp HTTP API), uma sessão por unidade | valor de `WAHA_URL` | — |
| Atualização da agenda em tempo real | Pusher | — | — |

Como as peças conversam:

- O painel chama a API (`/api/...`) com o token do usuário logado.
- O paciente escreve no WhatsApp da unidade. A WAHA entrega a mensagem ao webhook da unidade
  no n8n, e o bot consulta e agenda pela API do bot (`/api/bot/...`, chave `X-Bot-Key`).
- A confirmação de consulta (botão no painel ou envio automático) sai pela API, que manda a
  mensagem pela sessão WAHA da unidade do médico.

## Variáveis de ambiente

### API (`.env` no Elastic Beanstalk)

O modelo completo está em `.env.example`. As que mais importam em produção:

| Variável | Valor em produção | Para que serve |
|---|---|---|
| `APP_ENV` | `production` | Modo de produção. |
| `APP_DEBUG` | `false` | Com `true`, as respostas de erro mostram código e caminhos do servidor. |
| `APP_URL` | `https://api.memedics.com.br` | Endereço da própria API. |
| `FRONTEND_URL` | `https://app.memedics.com.br` | Base dos links enviados ao paciente (confirmação de consulta) e de redefinição de senha. Sem ela, os links saem com `localhost`. |
| `DB_*` | dados do MySQL | Banco principal. |
| `BOT_API_KEY` | segredo | Chave que o n8n envia no cabeçalho `X-Bot-Key` para usar `/api/bot/...`. A mesma chave fica no nó `Variaveis` do workflow do bot e na credencial "MeMedics Bot API Key" do n8n. |
| `WAHA_URL` / `WAHA_APIKEY` | endereço e chave da WAHA | Criar e parear sessões, enviar confirmações. A chave é única para todas as sessões. |
| `BROADCAST_DRIVER` e `PUSHER_*` | `pusher` e as chaves do app no Pusher **(confirmar)** | Atualização da agenda em tempo real. |
| `QUEUE_CONNECTION` | **(confirmar)** | Fila de tarefas (importações). O supervisor já sobe 8 workers (`.ebextensions/002_install_supervisor.config`). |

Depois de mudar qualquer variável: `php artisan config:cache` (veja [Publicar a API](#publicar-a-api)).

### Painel (variáveis do projeto na Vercel)

| Variável | Valor em produção | Para que serve |
|---|---|---|
| `VITE_API_URL_PROD` | `https://api.memedics.com.br/api` | URL da API usada no build de produção (com `/api` no final). |
| `VITE_API_URL_LOCAL` | `http://localhost/api` **(confirmar)** | URL da API no desenvolvimento local. |
| `VITE_PUSHER_APP_KEY` / `VITE_PUSHER_APP_CLUSTER` | as do Pusher | Tempo real; têm de ser as mesmas da API. |
| `VITE_APP_NAME` / `VITE_APP_VERSION` | nome e versão | Título da página e versão exibida. |
| `VITE_LOCALSTORAGE_VALUE` | prefixo fixo | Prefixo da sessão salva no navegador. Trocar desloga todo mundo. |

As variáveis `VITE_*` entram no build: depois de mudar, publique o painel de novo.

## Publicar a API

A configuração do servidor fica no repositório: `.ebextensions/` (supervisor e HTTPS) e
`.platform/nginx/` (nginx). A forma de enviar a nova versão ao Elastic Beanstalk (EB CLI,
console da AWS ou pipeline) **(confirmar)**.

Depois que a nova versão estiver no ar, entre no servidor (por exemplo, `eb ssh`) e, na pasta da
aplicação (`/var/app/current` **(confirmar)**; o supervisor usa `/var/www/html`), rode:

```bash
php artisan migrate:status          # mostra as migrations pendentes ("Pending")
php artisan migrate --force         # roda todas as pendentes
php artisan config:cache            # aplica as variáveis de ambiente
php artisan route:cache
php artisan queue:restart           # os workers recarregam o código novo
```

Antes de uma migration que altera dados (não só estrutura), faça backup do banco.

O agendador do Laravel não tem tarefas: não é preciso configurar cron. O envio automático de
confirmações roda no n8n.

## Publicar o painel

O painel está na Vercel (`vercel.json`: build `npm run build`, saída `dist`, todas as rotas
caem no `index.html`). Envie para a branch de produção ou publique pelo painel da Vercel
**(confirmar)**.

Antes de publicar, confira localmente:

```bash
npx tsc --noEmit
npm run build
```

O painel é um app instalável (PWA): quem estiver com ele aberto recebe a versão nova ao
recarregar a página.

## WhatsApp de uma unidade

Cada unidade tem a própria sessão na WAHA, ligada a um número de WhatsApp. O nome da sessão
fica em `unit_addresses.waha_session_name` e é criado automaticamente a partir do nome e do id
da unidade (ex.: `plaza-office-1`). Cada sessão manda as mensagens recebidas para o webhook do
bot daquela unidade:

| Unidade | Webhook do bot |
|---|---|
| Global Offices | `https://n8n.memedics.com.br/webhook/global-office-bot` |
| Plaza Offices | `https://n8n.memedics.com.br/webhook/plaza-offices-bot` |

Tudo abaixo é feito em **Configurações → Unidades**, na coluna de WhatsApp, por um
administrador.

### Parear um número

1. Se a unidade mostra **Sem sessão**, clique em **Criar**.
2. Clique em **Conectar**. Informe o webhook da unidade (tabela acima) e deixe marcados os
   eventos `message` e `session.status`.
3. Aparece o QR Code. No celular do número da unidade: WhatsApp → Aparelhos conectados →
   Conectar um aparelho, e leia o QR. O QR troca sozinho a cada 30 s.
4. Quando o status mudar para **Conectado**, a janela fecha sozinha.
5. Teste: mande uma mensagem de outro número para o WhatsApp da unidade e veja se o bot
   responde.

### Trocar o webhook ou os eventos

Ícone de engrenagem na linha da unidade. Salvar reinicia a conexão por alguns segundos, sem
perder o pareamento.

### Desconectar ou trocar o número

**Desconectar** encerra a sessão no WhatsApp. Para usar outro número, desconecte, exclua a
sessão (ícone de lixeira) e pareie de novo a partir do passo 1.

### A sessão sumiu da WAHA

Se a WAHA for reiniciada sem guardar as sessões em disco, as sessões somem: a unidade fica
**Desconectado**, o bot não responde e as confirmações falham com "WhatsApp da unidade está
desconectado". O certo é a WAHA guardar a pasta de sessões (`/app/.sessions`) num volume
persistente **(confirmar onde a WAHA está hospedada)**.

Para recuperar:

1. Veja quais sessões existem: `GET {WAHA_URL}/api/sessions` com o cabeçalho
   `X-Api-Key: {WAHA_APIKEY}`.
2. Se a sessão da unidade não está na lista, a lixeira do painel não consegue excluí-la (a
   WAHA responde que a sessão não existe). Desvincule pelo banco:

   ```sql
   UPDATE unit_addresses SET waha_session_name = NULL WHERE id = <id da unidade>;
   ```

3. Pareie de novo seguindo [Parear um número](#parear-um-número).

## Bot de atendimento

### Pausar e retomar

Na linha da unidade, **Pausar bot** e escolha 1 hora, 4 horas, 8 horas ou **Até eu retomar**.
Durante a pausa o bot não responde nada naquela unidade; o atendente conversa direto pelo
WhatsApp da unidade. A pausa com prazo termina sozinha. Para terminar antes: **Retomar bot**.

### Contatos que o bot não atende (whitelist)

Menu **Whitelist do bot** (administradores): o bot ignora as mensagens desses números, em
qualquer unidade. No perfil do médico, o botão **Adicionar à whitelist** ao lado do telefone
faz o mesmo. Informe o telefone com DDD; o sistema grava no formato do WhatsApp (com 55).

### Quando o bot não responde

Confira nesta ordem:

1. A unidade está **Conectado** em Configurações → Unidades?
2. O bot da unidade está pausado?
3. O número de quem escreveu está na whitelist?
4. O workflow do bot está ativo no n8n? Em **Executions** do workflow, a mensagem chegou? Se
   chegou e parou, o nó com erro mostra o motivo.
5. O webhook da sessão (engrenagem) aponta para o endereço certo da unidade?

## Confirmação de consulta

A mensagem vai pelo WhatsApp da unidade do médico, com data, horário, médico, local e o link
para o paciente confirmar ou cancelar. Ao enviar, o status da consulta muda para "Aguardando
Confirmação".

### Textos da unidade

Em **Configurações → Unidades → editar (lápis)**, seção "Confirmação de consulta pelo
WhatsApp":

- **Endereço por extenso:** sai como `📍End: ...` abaixo do local.
- **Aviso:** sai num parágrafo próprio antes do link (ex.: tolerância de atraso).

Em branco, a linha não aparece.

### Envio manual

Na agenda, abra a consulta e clique em **Enviar Confirmação**.

### Envio automático

- **Por médico:** no perfil do médico, card "Configuração do calendário" → Confirmação
  automática: quantos dias antes enviar (1 a 7) ou Desligado. Sem configuração, fica desligado.
- **Quando:** todo dia às 9h (horário de Brasília), pelo workflow "MeMedics - Confirmação
  automática de consultas" no n8n (pasta "Bot"). O workflow precisa estar **ativo**.
- **Ritmo:** uma mensagem por vez, com intervalo aleatório de 45 a 90 segundos, para não
  arriscar bloqueio do número.
- **Quem entra:** consultas com status "Agendado" de médicos com o envio ligado, de amanhã até
  N dias à frente. Quem ficou de fora por falha entra na rodada seguinte, sem duplicar.

### Erros ao enviar

| Mensagem | O que fazer |
|---|---|
| "Esta unidade não possui WhatsApp conectado" | Parear um número na unidade do médico. |
| "WhatsApp da unidade está desconectado" | Reconectar a unidade (veja [A sessão sumiu da WAHA](#a-sessão-sumiu-da-waha)). |
| "Telefone do paciente incompleto" | Corrigir o telefone no cadastro, com DDD. |
| "Paciente sem telefone cadastrado" | Cadastrar o telefone do paciente. |
| "Não foi possível enviar pelo WhatsApp da unidade: ..." | O texto depois dos dois-pontos é o erro da WAHA. |
| Link chega com `localhost` | Definir `FRONTEND_URL` na API e rodar `php artisan config:cache`. |

## Agenda

O intervalo dos horários na agenda e no bot segue o **Intervalo de atendimento** de cada
médico (perfil do médico → "Configuração do calendário"). Médico sem configuração usa 20
minutos.

## Onde olhar quando algo falha

| O quê | Onde |
|---|---|
| Erros da API | `storage/logs/laravel.log` no servidor da API |
| Workers da fila | `/var/www/logs/worker.log` no servidor da API |
| Bot e envio automático | n8n → workflow → **Executions** |
| Sessões do WhatsApp | Configurações → Unidades, ou `GET {WAHA_URL}/api/sessions` |
| Erros no painel | console do navegador (F12) e aba Rede |

## Histórico

- `MIGRACAO_WAHA.md`: migração do Evolution Go para a WAHA e registro das mudanças no bot, com
  decisões e testes de cada etapa.
- `BACKEND_SCHEDULE_CONFIG.md`: configuração do calendário por médico.
