<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class Cpf implements Rule
{
    public function passes($attribute, $value): bool
    {
        return self::isValid($value);
    }

    public function message(): string
    {
        return 'O :attribute informado não é um CPF válido.';
    }

    public static function isValid(?string $value): bool
    {
        $cpf = preg_replace('/\D/', '', (string) $value);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;

            for ($c = 0; $c < $t; $c++) {
                $sum += ((int) $cpf[$c]) * (($t + 1) - $c);
            }

            $digit = ((10 * $sum) % 11) % 10;

            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
