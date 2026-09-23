<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Integração com a WAHA (WhatsApp HTTP API), substituindo o Evolution Go.
 *
 * Diferença estrutural relevante: a WAHA usa uma única API key global para toda a
 * instância — não uma credencial por sessão/unidade como o Evolution Go. Isolar as
 * unidades entre si é responsabilidade do controller (nunca deixar uma unidade agir
 * sobre a sessão de outra), não da credencial em si.
 */
class WahaService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.waha.url'), '/');
        $this->apiKey  = (string) config('services.waha.api_key');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        if (empty($this->baseUrl) || empty($this->apiKey)) {
            throw new RuntimeException('WAHA não configurada. Defina WAHA_URL e WAHA_APIKEY no .env.');
        }

        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->acceptJson()
            ->timeout(15);
    }

    private function handle(Response $response): array
    {
        if ($response->failed()) {
            $error = $response->json('exception.message') ?? $response->json('message') ?? $response->body();
            throw new RuntimeException("WAHA error [{$response->status()}]: {$error}");
        }

        return $response->json() ?? [];
    }

    // ── Sessões ───────────────────────────────────────────────────────────────

    /**
     * Lista todas as sessões da instância (todas as unidades).
     */
    public function listSessions(): array
    {
        return $this->handle(
            $this->http()->get('/api/sessions')
        );
    }

    /**
     * Atualiza a configuração de webhook de uma sessão existente. Provoca uma
     * reconexão breve (confirmado na Fase 0) mas preserva o pareamento — não exige
     * novo QR.
     *
     * @param string[] $events
     */
    public function updateWebhook(string $name, ?string $webhookUrl, array $events = ['message', 'session.status']): array
    {
        $webhooks = $webhookUrl ? [['url' => $webhookUrl, 'events' => $events]] : [];

        return $this->handle(
            $this->http()->put("/api/sessions/{$name}", [
                'config' => ['webhooks' => $webhooks],
            ])
        );
    }

    /**
     * Cria e inicia uma sessão nova.
     *
     * @param string      $name       Nome da sessão (estável, ex: slug da unidade — não
     *                                precisa ser único a cada criação como no Evolution Go).
     * @param string|null $webhookUrl Endpoint que receberá os eventos.
     * @param string[]    $events     Eventos a assinar. Default cobre o essencial para o bot.
     */
    public function createSession(string $name, ?string $webhookUrl = null, array $events = ['message', 'session.status']): array
    {
        $config = [];

        if ($webhookUrl) {
            $config['webhooks'] = [['url' => $webhookUrl, 'events' => $events]];
        }

        return $this->handle(
            $this->http()->post('/api/sessions', array_filter([
                'name'   => $name,
                'start'  => true,
                'config' => $config ?: null,
            ], fn ($v) => $v !== null))
        );
    }

    /**
     * Conecta (gera QR) uma sessão existente.
     *
     * Encapsula a lição da Fase 0: se a última tentativa expirou sem ser escaneada,
     * a sessão fica em FAILED e chamar /start diretamente não tem efeito — é preciso
     * parar antes. Sessões em qualquer outro estado só precisam do /start.
     */
    public function connectSession(string $name): array
    {
        $current = $this->getStatus($name);

        if (($current['status'] ?? null) === 'FAILED') {
            $this->http()->post("/api/sessions/{$name}/stop");
        }

        return $this->handle(
            $this->http()->post("/api/sessions/{$name}/start")
        );
    }

    /**
     * Status da sessão. `status` é um de STOPPED/STARTING/SCAN_QR_CODE/WORKING/FAILED;
     * `me` vem preenchido (com o número pareado) só quando WORKING.
     */
    public function getStatus(string $name): array
    {
        return $this->handle(
            $this->http()->get("/api/sessions/{$name}")
        );
    }

    /**
     * Pausa a sessão sem apagar o pareamento — reconecta sozinha ao reiniciar,
     * sem exigir novo QR (confirmado na Fase 0).
     */
    public function stopSession(string $name): array
    {
        return $this->handle(
            $this->http()->post("/api/sessions/{$name}/stop")
        );
    }

    /**
     * Apaga a sessão e o pareamento permanentemente.
     */
    public function deleteSession(string $name): array
    {
        return $this->handle(
            $this->http()->delete("/api/sessions/{$name}")
        );
    }

    /**
     * QR Code pronto para uso em <img src>, já como data URI.
     */
    public function getQrCode(string $name): array
    {
        $response = $this->handle(
            $this->http()->get("/api/{$name}/auth/qr")
        );

        $mimetype = $response['mimetype'] ?? 'image/png';
        $data     = $response['data'] ?? null;

        return [
            'qrcode' => $data ? "data:{$mimetype};base64,{$data}" : null,
        ];
    }

    // ── Mensagens ─────────────────────────────────────────────────────────────

    /**
     * Envia uma mensagem de texto.
     *
     * @param string $sessionName Nome da sessão que envia.
     * @param string $number      Número do destinatário, com DDI, só dígitos.
     */
    public function sendTextMessage(string $sessionName, string $number, string $text): array
    {
        return $this->handle(
            $this->http()->post('/api/sendText', [
                'session' => $sessionName,
                'chatId'  => "{$number}@c.us",
                'text'    => $text,
            ])
        );
    }

    /**
     * Envia uma mensagem com link. O endpoint dedicado da WAHA para preview
     * customizado (/api/send/link-custom-preview) retornou erro 500 nos testes da
     * Fase 0, reproduzido em múltiplas tentativas — reportado como problema conhecido
     * nesta versão (ver github.com/devlikeapro/waha/discussions/610, onde o próprio
     * mantenedor recomenda o mesmo contorno usado aqui). O WhatsApp gera o preview
     * automaticamente a partir da URL presente no texto, então basta enviar como
     * texto normal — a mensagem já deve trazer a URL embutida.
     */
    public function sendLinkMessage(string $sessionName, string $number, string $message): array
    {
        return $this->sendTextMessage($sessionName, $number, $message);
    }
}
