<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * Aceita CPF (11 dígitos) ou CNPJ (14 dígitos), decidindo pelo tamanho —
 * mesmo critério usado por maskCpfCnpj no frontend. Usado no documento do
 * paciente, que pode ser pessoa física ou, em casos raros, jurídica.
 */
class CpfOuCnpj implements Rule
{
    public function passes($attribute, $value): bool
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if (strlen($digits) === 11) {
            return Cpf::isValid($digits);
        }

        if (strlen($digits) === 14) {
            return Cnpj::isValid($digits);
        }

        return false;
    }

    public function message(): string
    {
        return 'O :attribute informado não é um CPF ou CNPJ válido.';
    }
}
