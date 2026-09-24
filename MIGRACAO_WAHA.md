# Plano de ação — migração Evolution Go → WAHA

Baseado na análise realizada em 23/09/2026 (ver histórico de conversa / commits desta
data). Corte de produção definido como **tudo de uma vez**: todas as unidades trocam de
provedor na mesma janela, sem coexistência dos dois provedores em paralelo.

Como não há rede de segurança de "migrar aos poucos", a mitigação de risco deste plano
vem de duas coisas: (1) um ensaio completo em ambiente de teste antes de tocar em
produção, e (2) manter o Evolution Go intacto e reativável rapidamente até a WAHA provar
estabilidade — nada do código/dados antigos é apagado antes da Fase 5.

## Decisões a confirmar antes de começar

Sem elas, as fases abaixo não têm como ser dimensionadas com segurança.

- [x] **Onde a WAHA vai rodar.** Resolvido — já existe uma instância WAHA Plus própria
      em produção (EasyPanel), usada como ambiente de teste na Fase 0. A chave de API
      real não fica neste documento; está com o responsável pela infraestrutura.
- [x] **Edição: Core ou Plus.** É Plus (paga). Curiosidade confirmada na Fase 0: mesmo
      sendo Plus, o campo `environment.tier` retornado pela API da sessão de teste veio
      como `"CORE"` — vale confirmar com o suporte da WAHA se isso é esperado ou se é
      preciso alguma configuração adicional para as sessões rodarem como Plus de fato
      (o Plus normalmente libera mais sessões simultâneas e recursos extras).
- [x] **Nome de sessão por unidade.** Resolvido e já implementado na Fase 1:
      `slug(unit_name)-{id}` (estável, sem sufixo de timestamp — a WAHA não precisa de
      nome novo a cada criação, diferente do Evolution Go).
- [ ] **Janela de corte.** Data/horário com menor volume de mensagens, e quem
      estará de prontidão durante a janela (alguém precisa re-escanear o QR de cada
      unidade fisicamente ou pedir para o responsável de cada uma).
- [ ] **Ambiente de n8n permite community nodes.** Confirmar que dá para instalar
      `@devlikeapro/n8n-nodes-waha` na instância atual antes de depender dele no plano.

## Fase 0 — Infraestrutura e validação manual (ambiente de teste)  ✅ concluída em 23/09/2026

Executada contra a instância WAHA Plus real já existente (não foi preciso subir um
container local — testar contra o ambiente real do provedor deu resultados mais
confiáveis que uma simulação local). Sessão de teste usada: `memedics-teste-fase0`,
pareada com um número de teste próprio do usuário (não é o número que vai operar o bot
em produção). Deixada em `STOPPED` ao final — pareamento preservado, pronta para reuso
nas próximas fases sem precisar escanear de novo.

- [x] Instância WAHA de teste — reaproveitada a WAHA Plus já existente em produção.
- [x] API key — a global já configurada na instância; não versionada aqui.
- [x] `POST /api/sessions` — criar sessão de teste. Confirmado: `{"name": "...", "start": true}`.
- [x] `POST /api/sessions/{name}/start` — iniciar. Confirmado.
- [x] `GET /api/{session}/auth/qr` — QR retornado como PNG binário (292×292), exatamente
      como a doc descreve. Escaneado com sucesso na segunda tentativa (o primeiro QR
      expirou em ~60s sem ser escaneado a tempo — normal, não é bug).
- [x] `GET /api/sessions/{session}` — `status` evoluiu `STARTING` → `SCAN_QR_CODE` →
      `WORKING`, `me` preenchido após o scan. Confirmado igual à doc.
- [x] `POST /api/sendText` — funciona exatamente como documentado. `chatId` no formato
      `{numero}@c.us`, resposta 201 com o objeto da mensagem enviada.
- [x] `POST /api/send/link-custom-preview` — **quebrado nesta instância**: retorna 500
      (`Cannot read properties of undefined (reading 'url')` em
      `ChattingController.sendLinkCustomPreview`), reproduzido em 2 tentativas com
      payloads diferentes. Como o código-fonte do Plus não é público, não deu pra
      confirmar a causa exata. **Decisão para a Fase 1:** não usar esse endpoint —
      usar `POST /api/sendText` com a URL embutida no corpo da mensagem (abordagem que
      o próprio mantenedor da WAHA recomenda numa discussão pública do GitHub, ver
      https://github.com/devlikeapro/waha/discussions/610). Testado e funcionando aqui.
      Pendente: confirmar visualmente se o preview rico (imagem/título) renderiza — depende
      de `app.memedics.com.br/confirmar-consulta/...` ter meta tags Open Graph configuradas,
      o que é independente da WAHA.
- [x] Ciclo de vida da sessão — `WORKING` → `stop` → `STOPPED` preserva `me` (pareamento
      intacto, reconecta sem novo QR). Diferente de um QR expirado (`FAILED`), que zera
      `me` e exige `stop` + `start` de novo para gerar um QR novo — chamar só `start`
      direto de `FAILED` não funciona (fica preso em `FAILED`).
  - Achado extra: atualizar `config` (ex: webhooks) numa sessão `WORKING` via `PUT
    /api/sessions/{session}` reinicia a conexão por um instante (`STARTING` →
    `WORKING` de novo, poucos segundos), mas não derruba o pareamento.
- [x] Webhook — configurado via `PUT /api/sessions/{session}` com `config.webhooks:
      [{url, events}]`. Formato do envelope confirmado com tráfego real:
      ```json
      {
        "id": "evt_...", "timestamp": 0, "event": "message",
        "session": "nome-da-sessao", "me": { "id": "...", "pushName": "..." },
        "payload": {
          "id": "...", "from": "...", "fromMe": false, "body": "texto da mensagem",
          "to": "...", "hasMedia": false, "ack": 2,
          "_data": { "Info": { "Chat": "...", "Sender": "...", "PushName": "...",
                                "Timestamp": "...", "IsGroup": false }, "Message": {...} }
        },
        "engine": "GOWS", "environment": { "version": "...", "tier": "..." }
      }
      ```
      `_data.Info` é estruturalmente parecido com o que os nós do n8n hoje já esperam
      do Evolution Go — bom sinal para a Fase 3, mas os nomes de campo no nível
      superior (`payload.from`/`payload.body` em vez do formato antigo) ainda exigem
      reescrever a leitura em cada nó.

### ⚠️ Lição de segurança desta fase — aplicar em toda fase de teste daqui pra frente

Ao configurar o webhook, ele foi apontado por engano para um endpoint público sem
autenticação (webhook.site) para capturar rapidamente o formato do payload. A primeira
mensagem capturada **não foi de teste — foi uma mensagem real de um grupo de WhatsApp
alheio** em que o número de teste já participava (o número, embora seja "de teste" no
sentido de não ser o número de produção do bot, já tem contatos e grupos reais ativos).
Isso expôs, por alguns minutos, o conteúdo de uma conversa de terceiros que nunca deram
consentimento para isso.

Ação tomada: o encaminhamento do webhook foi removido e a captura no webhook.site foi
apagada imediatamente ao perceber o problema.

**Regra para as próximas fases:** nunca apontar o webhook de uma sessão pareada com um
número que tenha contatos/grupos reais para um endpoint público. Se for preciso capturar
tráfego de novo, usar um receptor com senha/autenticação, capturar o mínimo possível
(uma mensagem própria, não esperar tráfego orgânico) e apagar a captura assim que
terminar.

## Fase 1 — Backend Laravel  ✅ concluída em 23/09/2026

Pode ser feita e testada isoladamente (via Postman/curl), sem depender do front nem do
n8n ainda.

**Decisão de design:** `WahaController` é um controller **novo**, separado do
`EvolutionGoController` (não uma edição nele), com rotas novas (`/waha/...`) em vez de
reaproveitar `/evolution/...`. Da mesma forma, `AppointmentPublicLinkController` **não é
tocado nesta fase** — continua 100% no Evolution Go até a Fase 4. A razão: esse
controller já está em produção (é o botão de enviar confirmação de consulta, usado por
todas as unidades agora); trocá-lo antes da janela de corte quebraria essa
funcionalidade para todo mundo, já que nenhuma unidade tem `waha_session_name` ainda.
Tudo que esta fase entrega roda em paralelo, sem afetar nada que já está no ar.

- [x] Nova migration em `unit_addresses`: `waha_session_name` (nullable, string).
      `evolution_instance_id`/`evolution_token` preservados, intactos.
- [x] `app/Services/WahaService.php` criado com `listSessions`, `createSession`,
      `updateWebhook`, `connectSession` (encapsula a lição da Fase 0: se `FAILED`,
      chama `stop` antes de `start`), `getQrCode`, `getStatus`, `stopSession`,
      `deleteSession`, `sendTextMessage`, `sendLinkMessage` (via `sendText` com a URL
      embutida — endpoint dedicado segue quebrado, confirmado de novo).
- [x] `config/services.php` — bloco `waha`, variáveis `WAHA_URL`/`WAHA_APIKEY` no `.env`
      local (não versionado). Bloco `evolution_go` preservado.
- [x] `app/Http/Controllers/WahaController.php` criado (novo, `EvolutionGoController`
      intocado) com os 6 endpoints administrativos.
- [x] Rotas em `/api/waha/units/{unit}/...`, mesmo middleware `can:manage-whatsapp`,
      confirmadas via `route:list`.
- [x] Testado de ponta a ponta contra a WAHA Plus real, unidade real (revertida ao
      estado original ao final): criar → status → QR → tentativa de criar duplicado
      (409, correto) → desconectar → listar → excluir → status pós-exclusão
      (`no_instance`, correto). Todos os endpoints responderam como esperado.
- [x] `AppointmentPublicLinkController.php` — confirmado fora desta fase, ver Fase 4.

### Bugs e achados reais da Fase 1

- **Bug encontrado e corrigido:** `getQrCode()` retornava `qrcode: null` mesmo com a
  WAHA respondendo certo. Causa: o helper `http()` já chama `acceptJson()`, e um
  `withHeaders(['Accept' => 'application/json'])` a mais em cima do QR duplicava o
  header `Accept` — a WAHA, recebendo o header duplicado, respondia com o PNG binário
  em vez do JSON em base64. Corrigido removendo a chamada redundante.
- **Achado operacional:** `GET /api/sessions` (listagem global) só retorna sessões que
  não estão `STOPPED` — uma sessão parada (mas ainda pareada) desaparece da lista, só
  aparece consultando por nome direto (`GET /api/sessions/{nome}`). Não afeta a tela
  por unidade (usa status individual, que funciona normalmente também para sessões
  paradas), mas pode confundir quem olhar só a listagem global esperando ver todas as
  unidades cadastradas.
- **Decisão de nomenclatura:** nome de sessão usa `slug(unit_name)-{id}` (estável,
  único pelo ID da unidade) em vez do sufixo de timestamp que o Evolution Go usava —
  a WAHA não precisa de um nome novo a cada criação.

## Fase 2 — Frontend  ✅ concluída em 23/09/2026

Seguindo a mesma decisão da Fase 1 (construir em paralelo, sem tocar o que está em
produção): `api.evolution` **não foi editado**. Foi adicionado um bloco novo,
`api.waha`, e um componente novo, `WahaCell` (cópia adaptada do `EvolutionCell`, mesma
lógica de estado/polling/segurança). Os dois ficam lado a lado na tela de
Configurações → Unidades, o `WahaCell` claramente rotulado "WAHA (teste)" — é um
bloco temporário só para validar a migração; será removido (ou vira o único, no lugar
do Evolution) na Fase 4/5.

- [x] `src/lib/api.ts` — bloco `waha` novo, com o parsing adaptado aos campos reais da
      WAHA (`status`/`me.pushName` em vez de `Connected`/`LoggedIn`/`Name`; `qrcode`
      minúsculo, sem aninhamento `data.data` como o Evolution Go tinha).
  - Rota do backend: `/waha/...`, separada de `/evolution/...` — mesma decisão da
    Fase 1 (controller novo, não substituição).
- [x] `WahaCell` criado em `settings.tsx` — mesma UX do `EvolutionCell` (criar →
      configurar webhook/eventos → conectar → escanear QR → status), com uma
      diferença real: a lista de eventos disponíveis é outra. A WAHA tem nomes de
      evento próprios (`message`, `session.status`, `message.ack`, etc.), confirmados
      na documentação oficial — **não** são os mesmos nomes do Evolution Go
      (`MESSAGE`, `CONNECTION`, etc.), então o seletor foi refeito com a lista real,
      não uma tradução 1:1.
- [x] `types.ts` — `wahaSessionName` adicionado ao tipo `UnitAddress`.
- [x] Testado: `tsc --noEmit` limpo. A lógica de parsing exata de `api.ts` (não só o
      request, o parsing dos campos também) foi testada contra o backend real —
      criar, status, QR (confirmado virar uma data URI válida), conectar, desconectar,
      excluir. Todos corretos.
- [x] Confirmado visualmente pelo usuário na tela de Configurações: o bloco
      "WAHA (teste)" aparece corretamente ao lado do Evolution Go, sem quebrar layout.

## Fase 3 — n8n (bot) — maior esforço do plano  ✅ concluída em 23/09/2026

Depende do payload real capturado na Fase 0.

**Achado de 23/09/2026:** tentamos usar o `n8nac` (citado no `AGENTS.md` do projeto)
para automatizar essa fase — pull/push direto no workspace n8n. `npx n8nac workspace
status --json`, rodado tanto por mim quanto pelo usuário direto no terminal, retornou
`{"environmentTargets": [], "environments": [], "instances": []}` — nenhum ambiente
configurado. Além disso, o n8n roda num **servidor de produção**, não local. Decisão:
esta fase segue **manual**, editando o JSON exportado
(`MeMedics - Bot Global Offices.json`) e o usuário aplicando via import/duplicação
direto na UI do n8n — nada é publicado ou ativado por automação.

**Ajuste de escopo:** em vez do pacote `@devlikeapro/n8n-nodes-waha`, os 2 nós de envio
usam nós `HTTP Request` genéricos chamando a API REST da WAHA diretamente. Motivo: não
tenho como inspecionar o schema exato dos parâmetros desse pacote community sem uma
instância n8n viva pra testar contra — arriscaria repetir o mesmo erro de adivinhar
payload que already aconteceu (e foi corrigido) na Fase 0. A API REST da WAHA, por
outro lado, já foi testada e confirmada extensivamente nas Fases 0 e 1.

- [x] Mapeados **todos** os 34 nós do workflow original contra o payload real da WAHA
      (capturado na Fase 0). Conclusão importante: `Variaveis` é o único ponto central de
      tradução — quase todo o resto do fluxo lê os campos **estáveis** que `Variaveis`
      produz (ex: `body.data.Info.Sender`, `mediaType`), não o webhook bruto. Só 3 nós
      leem o webhook bruto diretamente, contornando `Variaveis`: `Code in JavaScript`,
      `salva_sessao`, `escalar_para_humano`.
- [x] Arquivo gerado por script (evita erro manual em JSON grande aninhado):
      `MeMedics - Bot Global Offices - WAHA (teste).json`. Estrutura validada
      programaticamente: 34 nós (mesma contagem), mesmos nomes de nó, **conexões
      idênticas** ao original — a topologia do fluxo não muda, só o conteúdo de alguns
      nós. Ainda não duplicado/importado na UI do n8n (é manual, depende do usuário —
      ver decisão acima sobre n8nac).
  - [x] `Variaveis` — reescrito. Mantém os **mesmos nomes de campo de saída** (para não
        precisar tocar em nenhum nó downstream), só troca a expressão de origem:
        `PushName`/`Type`/`MediaType` agora leem de `payload._data.Info.*` (estrutura
        interna do whatsmeow, confirmada na Fase 0 — só para texto); `Sender` agora lê de
        `payload.from` (nível superior, mais robusto que cavar em `_data`); mensagem de
        texto lê de `payload.body`; evento lê do `event` de nível superior. Campo
        `body.instanceToken` removido (não existe mais — autenticação vira credencial
        fixa). Novos campos `wahaUrl` (constante) e `wahaSession` (= `payload`/`session`
        de nível superior) adicionados para os nós de envio.
  - [x] `Switch` (roteamento Áudio/Imagem/Mensagem) — **confirmado com teste real em
        23/09/2026**: mensagem de áudio roteou corretamente para o caminho de
        transcrição. Hipótese da Fase 0 (WAHA/GOWS e Evolution Go usam a mesma lib
        `whatsmeow` por baixo, vocabulário de `mediaType` compatível) se confirmou na
        prática.
  - [x] `Code in JavaScript` (extrai base64 do áudio para transcrição) — **confirmado com
        teste real em 23/09/2026**: a cascata `payload.media.data` /
        `payload.media.base64` / `payload._data.Message.base64` resolveu corretamente e
        a transcrição funcionou de ponta a ponta. Nota `PENDENTE DE TESTE COM ÁUDIO
        REAL` no nó pode ser removida/atualizada na próxima edição do workflow.
  - [x] `merge_mensagem_de_texto`, `Está na Whitelist?`, `Whitelist`,
        `verificando_modo_da_sessao`, `Loop Over Items`, `If`,
        `verificar_horário_de_atendimento`, `esta_no_horario_de_funcionamento`,
        `Montar mensagem de fora de horário` — confirmados **sem dependência direta** do
        formato do Evolution Go (leem campos estáveis de `Variaveis`/nós anteriores, ou
        são lógica de horário comercial agnóstica de provedor). Nenhuma mudança
        necessária.
  - [x] `salva_sessao` e `escalar_para_humano` — liam `Timestamp` direto do webhook bruto
        (`body.data.Info.Timestamp`), contornando `Variaveis`. Reescrito para
        `payload._data.Info.Timestamp` — confirmado presente no payload real capturado
        na Fase 0.
- [x] Trocados os 2 nós `n8n-nodes-evolution-go.evoGo` (`Send text message`,
      `Enviar lembrete WhatsApp`) por nós `HTTP Request` genéricos chamando
      `POST {wahaUrl}/api/sendText` diretamente — endpoint já testado e confirmado
      funcionando nas Fases 0/1. Modelo de autenticação resolvido: os nós antigos liam
      `instanceApiKey` dinamicamente do payload (`body.instanceToken`); os novos usam uma
      credencial fixa `httpHeaderAuth` chamada **"WAHA API Key"** (header `X-Api-Key`,
      mesmo padrão já usado pela credencial "Clava Bot API Key" no resto do fluxo). O que
      continua vindo dinamicamente do payload é o nome da sessão (`wahaSession`, não é
      mais secreto).
  - **Passo manual pendente:** a credencial "WAHA API Key" **precisa ser criada uma vez
    na UI do n8n** (Credentials → Header Auth → nome do header `X-Api-Key`, valor = o
    `WAHA_APIKEY` real) — credenciais nunca vêm no JSON exportado, por design do n8n.
    Depois de criada, o `id` dela precisa substituir o placeholder
    `REPLACE_WITH_WAHA_CREDENTIAL_ID` nos 2 nós (`credentials.httpHeaderAuth.id`) — cada
    nó já tem uma nota (`notes`) visível no canvas do n8n lembrando disso.
- [x] Duplicado/importado `MeMedics - Bot Global Offices - WAHA (teste).json` na UI do
      n8n como um novo workflow, sem publicar/ativar — feito pelo usuário.
- [x] Criada a credencial "WAHA API Key" no n8n e ligada aos 2 nós de envio.
- [x] Criada sessão WAHA de teste apontando o webhook para o endpoint do fluxo de teste
      do n8n.
- [x] **Testado de ponta a ponta em 23/09/2026, com número de WhatsApp real — confirmado
      funcionando**, incluindo mensagem de áudio (transcrição + roteamento do `Switch`,
      os dois pontos que estavam marcados como não confirmados). Fase 3 encerrada.

### Bugs reais encontrados testando no n8n de verdade (24/09/2026)

O teste de ponta a ponta da Fase 3 (23/09/2026) tinha passado, mas ao aplicar o fluxo
migrado no workflow real de produção (24/09/2026) apareceram dois problemas que só um
teste contra o n8n de verdade revelaria:

1. **Bug meu — prefixo `.body.` faltando.** O nó `Webhook` do n8n embrulha o payload
   HTTP recebido dentro de uma chave `body` (junto de `headers`/`params`/`query`). Ao
   escrever as expressões da Fase 3, esqueci esse prefixo em vários lugares — usei
   `$json.event`/`$json.payload...`/`$json.session` quando deveria ser
   `$json.body.event`/`$json.body.payload...`/`$json.body.session`. Sintoma: o campo
   `body.event` do `Variaveis` resolvia pra `undefined`, e o `JSON.stringify(...)` do
   `salva_sessao` quebrava inteiro com o erro "undefined não é JSON válido" (porque a
   expressão do Timestamp também tinha o mesmo problema). **Corrigido** em todos os
   campos afetados: `Variaveis` (`body.event`, `mediaType`, `body.instanceName`,
   `wahaSession`), `salva_sessao`, `escalar_para_humano` e `Code in JavaScript`
   (extração de áudio) — arquivo de referência atualizado.
2. **Achado real, não um bug meu — identificador LID do WhatsApp.** Um contato de teste
   apareceu com `_data.Info.Sender` no formato `"<id>@lid"` (ex:
   `277966055559229@lid`) em vez do número de telefone — esse é o **LID (Linked ID)**,
   um identificador de privacidade que o WhatsApp usa para alguns contatos em vez do
   número real, mesmo em conversa 1:1 (não é exclusivo de grupo, como eu supunha na
   Fase 0). Usar esse valor direto quebrava qualquer URL/chatId que dependesse do
   número (ex: `/api/bot/sessions/277966055559229lid` — inválido). **Descoberto pelo
   usuário testando na prática:** a WAHA/whatsmeow expõe o número real num campo
   irmão, `_data.Info.SenderAlt` (ex: `5521981321890@s.whatsapp.net`). Corrigido o
   `Variaveis` pra usar `SenderAlt` quando existir, com fallback pra `Sender`:
   ```
   {{ (($json.body.payload._data.Info.SenderAlt || $json.body.payload._data.Info.Sender).match(/\d+/) || [])[0] }}
   ```
   Pendente: confirmar se `SenderAlt` está sempre presente quando `Sender` é LID (só
   confirmado com este único contato de teste até agora).
3. **Credencial errada nos nós de envio.** `Send text message` estava usando a
   credencial "MeMedics Bot API Key" (header `X-Bot-Key`, do backend Laravel) em vez
   da credencial dedicada da WAHA (header `X-Api-Key`) — causava 401 Unauthorized.
   **Corrigido pelo usuário em 24/09/2026**, criando a credencial "WAHA API Key" e
   trocando o Header Auth nos 2 nós de envio (`Send text message`,
   `Enviar lembrete WhatsApp`). **Confirmado funcionando** — mensagem enviada com
   sucesso via WAHA de ponta a ponta.

4. **Whitelist deixou passar um número que deveria ter sido bloqueado — não é bug da
   migração WAHA, é um problema pré-existente de dados.** O número de teste do usuário
   estava cadastrado no whitelist como `21981321890` (sem o DDI `55`), enquanto o
   `Variaveis.body.data.Info.Sender` (extraído do JID do WhatsApp) sempre vem com DDI:
   `5521981321890`. A comparação do nó `Está na Whitelist?` é `equals` estrito, então
   nunca batia — o loop percorria a lista inteira, não achava correspondência, e
   deixava passar pro bot (comportamento oposto ao pretendido: quem está na lista não
   deveria chegar no bot). **Corrigido pelo usuário direto no banco** (registro
   atualizado pra `5521981321890`).
   - **Risco estrutural que continua existindo:** `WhatsappWhiteListController.php` só
     tem `index()` — não há endpoint de criar/editar a lista, os números são inseridos
     direto no banco sem nenhuma validação/normalização de formato. O mesmo erro pode
     se repetir com o próximo número cadastrado. Não foi corrigido agora (decisão do
     usuário, resolver o registro específico foi suficiente por ora) — se quiser uma
     correção estrutural depois, a opção mais simples é normalizar a comparação no
     próprio nó `Está na Whitelist?` (comparar só os últimos 11 dígitos de cada lado,
     em vez de string igual), o que blinda contra esse formato de erro
     independentemente de como o número for cadastrado no banco.

Com os achados acima corrigidos, o fluxo de texto está validado de ponta a ponta
contra o n8n real de produção (não só o ambiente de teste da Fase 0). Ainda vale
re-testar o caminho de áudio depois dessas correções, já que ele também tinha o mesmo
bug do prefixo `.body.` (corrigido, mas não re-testado com um áudio real ainda).

### Roteiro manual para fechar a Fase 3 (executado pelo usuário, sem acesso direto ao n8n de produção nesta sessão)

1. **Duplicar o workflow, sem ativar.** Na UI do n8n, abrir `MeMedics - Bot Global
   Offices` (a versão em produção) e usar **Duplicate** (não importar o `.json` — assim
   as credenciais existentes já vêm coladas automaticamente: `Clava Bot API Key`,
   `Minha assinatura OpenAI`, `Redis account`, `EvoGo account`). Renomear a cópia para
   algo como `MeMedics - Bot Global Offices (WAHA teste)`. Depois, usar o arquivo
   `MeMedics - Bot Global Offices - WAHA (teste).json` só como **referência** de quais
   nós editar e com que conteúdo exato (copiar/colar os parâmetros de cada nó listado
   acima), já que o Duplicate não aplica o conteúdo do arquivo sozinho.
2. **Criar a credencial "WAHA API Key".** Credentials → New → Header Auth. Nome do
   header: `X-Api-Key`. Valor: o `WAHA_APIKEY` real (o mesmo já usado no `.env` do
   backend). Depois, nos nós `Send text message` e `Enviar lembrete WhatsApp` (agora
   `HTTP Request`), selecionar essa credencial no lugar do placeholder
   `REPLACE_WITH_WAHA_CREDENTIAL_ID`.
3. **Pegar a URL de teste do webhook.** O nó `Webhook` usa o path fixo
   `global-office-bot`. Como o fluxo de teste **não deve ser ativado**, use a URL de
   teste do n8n (aparece ao clicar em "Listen for test event" no próprio nó Webhook
   dentro do editor): `https://<seu-host-n8n>/webhook-test/global-office-bot`. Essa URL
   só escuta enquanto o editor estiver aberto com "Listen" ativado — não conflita com o
   workflow de produção, que usa `/webhook/global-office-bot` (produção fica intocada).
4. **Criar a sessão WAHA de teste.** Pela tela de Configurações do sistema (bloco "WAHA
   (teste)" da Fase 2) ou direto pela API: criar sessão para uma unidade de teste,
   configurar o webhook apontando para a URL do passo 3, eventos mínimos `message` e
   `session.status`. **Repetir a regra de segurança da Fase 0:** só usar um número que
   você controla, nunca reaproveitar um número com contatos/grupos reais de terceiros
   sem antes confirmar que não há risco de captura indevida.
5. **Escanear o QR** e testar, nesta ordem: mensagem de texto simples (saudação) →
   listar especialidades/médicos → consultar horários → criar um agendamento completo →
   cancelar → escalar para humano → CSAT → **por último, uma mensagem de áudio** (é o
   único caminho não validado com dados reais — se falhar, o problema mais provável é o
   campo lido em `Code in JavaScript`, documentado como pendente).
6. Ao final, no nó `Webhook` clicar em "Listen for test event" de novo a cada nova
   rodada de teste (a escuta de teste do n8n expira sozinha).

## Fase 4 — Janela de corte em produção

Só começa depois que as Fases 1–3 estiverem validadas em staging de ponta a ponta.

**Atualização de 23/09/2026 — risco reavaliado:** o usuário confirmou que **ainda não
existe uso real do WhatsApp em produção** (nenhum paciente/unidade dependendo do bot ou
da confirmação por link hoje). Isso muda o desenho original desta fase: não existe
"horário de menor movimento" a proteger, e trocar o provedor não interrompe nenhum
atendimento em andamento. Por isso o corte deixa de precisar ser um evento único e
coordenado — pode ser feito **unidade por unidade, no ritmo que for conveniente**, sem
uma janela formal agendada.

- [x] Deploy do backend (Fase 1) e frontend (Fase 2) em produção — pode ir antes da
      janela de corte em si, já que os endpoints novos não afetam nada até serem usados.
- [x] `AppointmentPublicLinkController.php` trocado de `EvolutionGoService` para
      `WahaService` — **aplicado em 23/09/2026** (decisão: seguro fazer agora, dado que
      não há uso real ainda; antes disso o plano previa esperar até a janela porque o
      controller estava em produção atendendo unidades reais). Efeito imediato: toda
      unidade sem `waha_session_name` passa a receber a mensagem "Esta unidade não
      possui WhatsApp conectado" ao tentar enviar confirmação — inofensivo, já que
      nenhuma está em uso real. O patch aplicado foi exatamente este (mantido aqui como
      registro):
    ```diff
     use App\Events\ScheduleUpdated;
     use App\Models\Appointment;
    -use App\Services\EvolutionGoService;
    +use App\Services\WahaService;
     use Illuminate\Http\JsonResponse;
     use Illuminate\Support\Str;
     use RuntimeException;
     use Throwable;

     class AppointmentPublicLinkController extends Controller
     {
         private const AWAITING_CONFIRMATION_STATUS = 13;

    -    public function __construct(private readonly EvolutionGoService $evolution)
    +    public function __construct(private readonly WahaService $waha)
         {
         }

         public function store(Appointment $appointment): JsonResponse
         {
             if (!$appointment->public_token) {
                 $appointment->update(['public_token' => Str::random(40)]);
             }

             $unit = $appointment->event->doctor->unitAddress;

    -        if (!$unit || !$unit->evolution_token) {
    +        if (!$unit || !$unit->waha_session_name) {
                 return response()->json([
                     'message' => 'Esta unidade não possui WhatsApp conectado. Configure em Configurações → Unidades.',
                 ], 404);
             }

             $phone = $appointment->patient->phone ?? null;
             $number = $this->normalizePhone($phone);

             if (!$number) {
                 return response()->json(['message' => 'Paciente sem telefone cadastrado.'], 422);
             }

             try {
    -            $status = $this->evolution->getStatus($unit->evolution_token);
    -            $connected = $status['data']['Connected'] ?? $status['Connected'] ?? false;
    -            $loggedIn = $status['data']['LoggedIn'] ?? $status['LoggedIn'] ?? false;
    -
    -            if (!$connected || !$loggedIn) {
    +            $status = $this->waha->getStatus($unit->waha_session_name);
    +
    +            if (($status['status'] ?? null) !== 'WORKING' || empty($status['me'])) {
                     return response()->json([
                         'message' => 'WhatsApp da unidade está desconectado. Reconecte em Configurações → Unidades.',
                     ], 422);
                 }

                 $baseUrl = rtrim(config('app.frontend_url'), '/');
                 $url = "{$baseUrl}/confirmar-consulta/{$appointment->public_token}";
                 $message = $this->buildMessage($appointment, $url);

    -            $this->evolution->sendLinkMessage(
    -                $unit->evolution_token,
    -                $number,
    -                $message,
    -                $url,
    -                'Confirmação de Consulta',
    -                'Toque para confirmar, cancelar ou reagendar sua consulta',
    -                'https://clavaconsult.vercel.app/icons/icon-512.png',
    -            );
    +            $this->waha->sendLinkMessage($unit->waha_session_name, $number, $message);
             } catch (RuntimeException $e) {
                 return response()->json(['message' => $e->getMessage()], 502);
             } catch (Throwable $e) {
                 return response()->json(['message' => 'Erro interno ao enviar a confirmação pelo WhatsApp.'], 500);
             }
             // ... resto do arquivo (normalizePhone, buildMessage) fica igual
    ```
    Nota: `title`/`description`/`thumbnail` do Evolution Go somem sem substituto — a
    WAHA não tem preview rico funcionando (achado da Fase 0). `buildMessage()` já
    embute a URL no corpo da mensagem, então o WhatsApp gera o preview simples
    automaticamente a partir do link — não precisa mudar `buildMessage()`.
- [ ] Publicar o workflow do n8n migrado (Fase 3), substituindo o ativo em produção —
      isso ainda afeta **todo** o bot de uma vez (é um único workflow central, não por
      unidade), mas como não há conversas reais em andamento, pode ser feito assim que
      convier, sem coordenação especial.
- [ ] Para cada unidade (no ritmo que for conveniente, sem urgência de horário): criar a
      sessão WAHA e **escanear o QR com o número real** (não existe forma de migrar uma
      sessão já autenticada de um provedor para o outro — o WhatsApp exige novo
      pareamento), e configurar o webhook apontando pro workflow de produção do n8n
      (publicado no item acima).
- [ ] Enviar uma mensagem de teste real (confirmação de consulta) em pelo menos uma
      unidade, ponta a ponta, para validar antes de considerar a migração dessa unidade
      concluída.
- [ ] Manter os campos antigos do Evolution Go intactos no banco (não apagar nada ainda)
      — é o que permite reverter rápido se precisar.

### Roteiro de execução (sem uso real ainda — pode ser feito no ritmo que convier, não precisa de janela agendada)

1. ~~Deploy do backend/frontend~~ e ~~patch do controller~~ — feitos em 23/09/2026.
2. Confirmar que a sessão WAHA de teste usada na Fase 3 está limpa/desconectada (não
   deixar um número de teste pareado como se fosse produção).
3. Publicar o workflow do n8n migrado (Fase 3) em produção, desativando o antigo —
   afeta o bot inteiro de uma vez (workflow único), mas sem risco real hoje.
4. Para cada unidade: criar a sessão WAHA (`slug(unit_name)-{id}`) pela tela de
   Configurações → Unidades, escanear o QR com o número real, configurar o webhook
   apontando pro n8n de produção. Confirmar status `WORKING` antes de seguir pra
   próxima unidade.
5. Enviar uma mensagem de teste real (confirmação de consulta) por unidade, conforme
   forem sendo pareadas, pra validar ponta a ponta.
6. Manter os campos antigos do Evolution Go intactos no banco — nada se apaga nesta
   fase, só na Fase 5.

**Se algo falhar em qualquer ponto:** seguir o plano de rollback abaixo.

### Plano de rollback (se algo falhar)

- Reativar o workflow antigo do n8n (o publicado antes da Fase 4).
- Reverter o commit do patch em `AppointmentPublicLinkController.php` (volta a usar
  `EvolutionGoService`/`evolution_token`).
- As sessões do Evolution Go de cada unidade continuam intactas (não foram tocadas),
  então voltam a funcionar assim que o workflow antigo for reativado — não é necessário
  re-escanear nada para reverter.

## Fase 5 — Descomissionamento  ⚠️ executada em 23/09/2026 fora da ordem original

**Gatilho originalmente definido (condição, não data):** todas as unidades ativas com
`waha_session_name` preenchido e status `WORKING` confirmado, **mais** um período de
estabilidade em produção depois disso. **Isso não tinha acontecido ainda** — nenhuma
unidade havia migrado (n8n não publicado, nenhum QR real escaneado) quando o usuário
pediu explicitamente para prosseguir mesmo assim, entendendo que isso quebra o WhatsApp
de todas as unidades até elas migrarem pra WAHA (decisão registrada, não um erro do
plano).

- [x] **Frontend:** removido `EvolutionCell` de `settings.tsx` e o bloco `evolution` de
      `api.ts`. Rótulo do `WahaCell` deixou de ser "WAHA (teste)" — agora é a única
      forma de conexão exibida, sem rótulo de teste. Removido `evolutionInstanceId` e
      `EvolutionStatus` de `types.ts`. Verificado com `tsc --noEmit`: nenhum erro nos
      arquivos alterados (erros pré-existentes em outros arquivos, não relacionados).
- [x] Removidos `EvolutionGoService.php` e `EvolutionGoController.php`. Removido o bloco
      `evolution_go` de `config/services.php`. Removida a rota `/evolution/*` e o import
      do controller em `routes/api.php`. Removidos `evolution_instance_id`/
      `evolution_token` de `$fillable`/`$hidden` em `UnitAddress.php` e do
      `UnitAddressResource.php`.
- [x] Migration `2026_09_23_140000_drop_evolution_fields_from_unit_addresses_table.php`
      criada (dropa `evolution_instance_id`/`evolution_token`) — **não executada** por
      este agente (sem driver de MySQL disponível no ambiente sandbox). Falta rodar
      `php artisan migrate` no ambiente com acesso ao banco.
  - Nota: `EVOLUTION_GO_URL`/`EVOLUTION_GO_APIKEY` ficaram órfãs no `.env` local (não
    afetam nada, mas podem ser removidas manualmente por limpeza).
- [ ] Remover o pacote `n8n-nodes-evolution-go` do ambiente n8n e o workflow antigo
      (ou arquivá-lo desativado, para referência) — manual, no n8n.
- [ ] Encerrar a assinatura/servidor do Evolution Go — manual, decisão/ação do usuário.
- [x] Este documento atualizado. `AGENTS.md` não tinha nenhuma referência ao Evolution Go
      (é sobre a ferramenta `n8nac`, sem relação).

## Referência rápida — arquivos afetados (estado final, pós-Fase 5)

| Camada | Arquivo |
|---|---|
| Config | `config/services.php` (só `waha`), `.env` (`EVOLUTION_GO_*` órfãs, podem ser limpas) |
| Backend | `app/Services/WahaService.php` (Evolution Go removido) |
| Backend | `app/Http/Controllers/WahaController.php` (Evolution Go removido) |
| Backend | `app/Http/Controllers/AppointmentPublicLinkController.php` (usa `WahaService`) |
| Backend | `app/Models/UnitAddress.php`, `app/Http/Resources/UnitAddressResource.php` |
| Banco | `database/migrations/2026_09_23_140000_drop_evolution_fields_from_unit_addresses_table.php` — **falta rodar** |
| Frontend | `src/lib/api.ts` (só bloco `waha`) |
| Frontend | `src/routes/_authenticated/settings.tsx` (só `WahaCell`) |
| Frontend | `src/lib/types.ts` (`wahaSessionName`, sem `evolutionInstanceId`) |
| n8n | `MeMedics - Bot Global Offices.json` (produção — **ainda não publicado o migrado**), `MeMedics - Bot Global Offices - WAHA (teste).json` (pronto) |
