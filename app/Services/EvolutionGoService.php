<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class EvolutionGoService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.evolution_go.url'), '/');
        $this->apiKey  = config('services.evolution_go.api_key');

        if (empty($this->baseUrl) || empty($this->apiKey)) {
            throw new RuntimeException('Evolution Go não configurado. Defina EVOLUTION_GO_URL e EVOLUTION_GO_APIKEY no .env.');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function http(string $instanceApiKey = null): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['apikey' => $instanceApiKey ?? $this->apiKey])
            ->acceptJson()
            ->timeout(15);
    }

    private function handle(Response $response): array
    {
        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new RuntimeException("Evolution Go error [{$response->status()}]: {$error}");
        }

        return $response->json() ?? [];
    }

    // ── Instâncias ────────────────────────────────────────────────────────────

    /**
     * Lista todas as instâncias.
     */
    public function listInstances(): array
    {
        return $this->handle(
            $this->http()->get('/instance/all')
        );
    }

    /**
     * Cria uma nova instância.
     *
     * @param string      $name   Nome único da instância
     * @param string|null $token  Token customizado (opcional — gerado automaticamente se omitido)
     * @param string|null $webhookUrl  Webhook para receber eventos
     */
    public function createInstance(string $name, ?string $token = null, ?string $webhookUrl = null): array
    {
        $payload = array_filter([
            'name'       => $name,
            'token'      => $token ?? Str::uuid()->toString(),
            'webhookUrl' => $webhookUrl,
        ]);

        return $this->handle(
            $this->http()->post('/instance/create', $payload)
        );
    }

    /**
     * Conecta a instância e retorna dados de conexão.
     * Se `immediate` = false, retorna QR code para escanear.
     *
     * @param string      $instanceApiKey  Token da instância (retornado na criação)
     * @param bool        $immediate       true = autenticação imediata via número; false = QR code
     * @param string|null $phone           Número do WhatsApp (obrigatório se immediate = true)
     * @param string|null $webhookUrl      URL do webhook (opcional)
     */
    public function connectInstance(
        string $instanceApiKey,
        bool $immediate = false,
        ?string $phone = null,
        ?string $webhookUrl = null,
        ?array $subscribe = null,
    ): array {
        $payload = array_filter([
            'immediate'  => $immediate,
            'phone'      => $phone,
            'webhookUrl' => $webhookUrl,
            'subscribe'  => $subscribe,
        ], fn ($v) => $v !== null);

        return $this->handle(
            $this->http($instanceApiKey)->post('/instance/connect', $payload)
        );
    }

    /**
     * Desconecta a instância (mantém a instância criada, apenas encerra a sessão WhatsApp).
     *
     * @param string $instanceApiKey  Token da instância
     */
    public function disconnectInstance(string $instanceApiKey): array
    {
        return $this->handle(
            $this->http($instanceApiKey)->post('/instance/disconnect')
        );
    }

    /**
     * Deleta a instância permanentemente.
     *
     * @param string $instanceId  UUID da instância
     */
    public function deleteInstance(string $instanceId): array
    {
        return $this->handle(
            $this->http()->delete("/instance/delete/{$instanceId}")
        );
    }

    /**
     * Retorna o QR code em base64 para conectar a instância via WhatsApp.
     *
     * @param string $instanceApiKey  Token da instância
     */
    public function getQrCode(string $instanceApiKey): array
    {
        return $this->handle(
            $this->http($instanceApiKey)->get('/instance/qr')
        );
    }

    /**
     * Retorna o status de conexão da instância.
     *
     * @param string $instanceApiKey  Token da instância
     */
    public function getStatus(string $instanceApiKey): array
    {
        return $this->handle(
            $this->http($instanceApiKey)->get('/instance/status')
        );
    }

    // ── Mensagens ─────────────────────────────────────────────────────────────

    /**
     * Envia uma mensagem de texto pelo WhatsApp da instância.
     *
     * @param string $instanceApiKey  Token da instância
     * @param string $number          Número do destinatário (com DDI, ex: 5511999999999)
     * @param string $text            Texto da mensagem
     */
    public function sendTextMessage(string $instanceApiKey, string $number, string $text): array
    {
        return $this->handle(
            $this->http($instanceApiKey)->post('/send/text', [
                'number' => $number,
                'text'   => $text,
            ])
        );
    }

    /**
     * Envia uma mensagem com link (preview) pelo WhatsApp da instância.
     *
     * @param string      $instanceApiKey  Token da instância
     * @param string      $number          Número do destinatário (com DDI, ex: 5511999999999)
     * @param string      $text            Texto/legenda da mensagem
     * @param string      $url             URL do link
     * @param string|null $title           Título do card de preview do link
     * @param string|null $description     Descrição do card de preview do link
     * @param string|null $imgUrl          Imagem do card de preview do link
     */
    public function sendLinkMessage(
        string $instanceApiKey,
        string $number,
        string $text,
        string $url,
        ?string $title = null,
        ?string $description = null,
        ?string $imgUrl = null,
    ): array {
        $payload = array_filter([
            'number'      => $number,
            'text'        => $text,
            'url'         => $url,
            'title'       => $title,
            'description' => $description,
            'imgUrl'      => $imgUrl,
        ], fn ($v) => $v !== null);

        return $this->handle(
            $this->http($instanceApiKey)->post('/send/link', $payload)
        );
    }
}
