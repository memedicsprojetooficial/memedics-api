<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Telefone no formato do WhatsApp: só dígitos, com DDI 55 (ex.: 5521981321890).
     * É o formato que o n8n recebe da WAHA e compara com a whitelist.
     */
    public static function forWhatsapp(?string $phone): ?string
    {
        $digits = ltrim(preg_replace('/\D/', '', $phone ?? ''), '0');

        if ($digits === '') {
            return null;
        }

        // Nacional tem 10–11 dígitos (DDD + número). Só considera que o DDI 55 já está presente
        // com 12–13 dígitos — senão um DDD 55 (RS) seria confundido com o DDI.
        return strlen($digits) >= 12 && str_starts_with($digits, '55') ? $digits : "55{$digits}";
    }

    /**
     * DDI + DDD + número: 12 (fixo) ou 13 (celular) dígitos. Sem DDD a WAHA monta outro
     * número (ex.: 984834726 vira 555584834726) — então não se envia nada.
     */
    public static function isComplete(?string $whatsapp): bool
    {
        return $whatsapp !== null && in_array(strlen($whatsapp), [12, 13], true);
    }
}
