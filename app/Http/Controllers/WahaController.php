<?php

namespace App\Http\Controllers;

use App\Models\UnitAddress;
use App\Services\WahaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Controller novo, paralelo ao EvolutionGoController — não o substitui ainda.
 * Migração Evolution Go → WAHA, Fase 1: ver MIGRACAO_WAHA.md.
 */
class WahaController extends Controller
{
    public function __construct(private readonly WahaService $waha) {}

    // ── Listar todas as sessões (global) ──────────────────────────────────────

    public function index(): JsonResponse
    {
        return $this->respond(fn () => $this->waha->listSessions());
    }

    // ── Criar e vincular sessão a uma unidade ─────────────────────────────────

    public function storeForUnit(UnitAddress $unit): JsonResponse
    {
        if ($unit->waha_session_name) {
            return response()->json(['message' => 'Unidade já possui uma sessão vinculada.'], 409);
        }

        return $this->respond(function () use ($unit) {
            // Sufixo pelo ID (estável e único), não timestamp — nome de sessão na
            // WAHA não precisa ser regenerado a cada criação como no Evolution Go.
            $sessionName = Str::slug($unit->unit_name) . '-' . $unit->id;
            $result = $this->waha->createSession($sessionName);

            try {
                $unit->update(['waha_session_name' => $sessionName]);
            } catch (Throwable $e) {
                try {
                    $this->waha->deleteSession($sessionName);
                } catch (Throwable $cleanupError) {
                    Log::error('Falha ao remover sessão órfã na WAHA.', [
                        'unit_id'      => $unit->id,
                        'session_name' => $sessionName,
                        'error'        => $cleanupError->getMessage(),
                    ]);
                }

                throw $e;
            }

            return $result;
        }, 201);
    }

    // ── Conectar sessão de uma unidade (configura webhook e retorna QR) ───────

    public function connectUnit(Request $request, UnitAddress $unit): JsonResponse
    {
        if (!$unit->waha_session_name) {
            return response()->json(['message' => 'Unidade não possui sessão vinculada.'], 404);
        }

        $data = $request->validate([
            'webhook_url' => 'nullable|url',
            'subscribe'   => 'nullable|array',
            'subscribe.*' => 'string',
        ]);

        return $this->respond(function () use ($unit, $data) {
            if (!empty($data['webhook_url'])) {
                $this->waha->updateWebhook(
                    $unit->waha_session_name,
                    $data['webhook_url'],
                    $data['subscribe'] ?? ['message', 'session.status'],
                );
            }

            return $this->waha->connectSession($unit->waha_session_name);
        });
    }

    // ── Desconectar sessão de uma unidade ─────────────────────────────────────

    public function disconnectUnit(UnitAddress $unit): JsonResponse
    {
        if (!$unit->waha_session_name) {
            return response()->json(['message' => 'Unidade não possui sessão vinculada.'], 404);
        }

        return $this->respond(
            fn () => $this->waha->stopSession($unit->waha_session_name)
        );
    }

    // ── Deletar sessão de uma unidade ─────────────────────────────────────────

    public function destroyUnit(UnitAddress $unit): JsonResponse
    {
        if (!$unit->waha_session_name) {
            return response()->json(['message' => 'Unidade não possui sessão vinculada.'], 404);
        }

        // Mesma regra de negócio do Evolution Go: só exclui com o WhatsApp desconectado.
        try {
            $status = $this->waha->getStatus($unit->waha_session_name);

            if (($status['status'] ?? null) === 'WORKING') {
                return response()->json(['message' => 'Desconecte o WhatsApp antes de excluir a sessão.'], 409);
            }
        } catch (Throwable $e) {
            Log::warning('Não foi possível consultar o status antes de excluir a sessão.', [
                'unit_id' => $unit->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $this->respond(function () use ($unit) {
            $result = $this->waha->deleteSession($unit->waha_session_name);
            $unit->update(['waha_session_name' => null]);

            return $result;
        });
    }

    // ── QR Code da sessão de uma unidade ──────────────────────────────────────

    public function qrCodeUnit(UnitAddress $unit): JsonResponse
    {
        if (!$unit->waha_session_name) {
            return response()->json(['message' => 'Unidade não possui sessão vinculada.'], 404);
        }

        return $this->respond(
            fn () => $this->waha->getQrCode($unit->waha_session_name)
        );
    }

    // ── Status da sessão de uma unidade ───────────────────────────────────────

    public function statusUnit(UnitAddress $unit): JsonResponse
    {
        if (!$unit->waha_session_name) {
            return response()->json([
                'status' => 'STOPPED',
                'me' => null,
                'message' => 'no_instance',
            ]);
        }

        return $this->respond(
            fn () => $this->waha->getStatus($unit->waha_session_name)
        );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function respond(callable $action, int $successStatus = 200): JsonResponse
    {
        try {
            return response()->json($action(), $successStatus);
        } catch (RuntimeException $e) {
            Log::warning('WAHA respondeu com erro.', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Erro interno ao comunicar com a WAHA.'], 500);
        }
    }
}
