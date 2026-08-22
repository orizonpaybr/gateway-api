<?php

namespace App\Helpers;

/**
 * Aritmética de dinheiro EXATA a 2 casas — evita o drift de float (0.1 + 0.2 !=
 * 0.3) em somas/subtrações de valores monetários (split de taxa, ajuste de saldo).
 * Usa bcmath (base-10) quando disponível, com fallback seguro para round().
 *
 * A fonte de verdade continua sendo as colunas DECIMAL no banco; este helper é
 * para a aritmética intermediária no PHP. Adoção incremental nos caminhos de
 * split/saldo — não é preciso reescrever tudo de uma vez.
 */
final class Money
{
    private const SCALE = 2;

    /** Arredonda para 2 casas (dinheiro). */
    public static function round(float|string $value): float
    {
        return (float) self::normalize($value);
    }

    /** Soma exata de dois valores monetários. */
    public static function add(float|string $a, float|string $b): float
    {
        if (function_exists('bcadd')) {
            return (float) bcadd(self::normalize($a), self::normalize($b), self::SCALE);
        }

        return round((float) $a + (float) $b, self::SCALE);
    }

    /** Subtração exata de dois valores monetários. */
    public static function sub(float|string $a, float|string $b): float
    {
        if (function_exists('bcsub')) {
            return (float) bcsub(self::normalize($a), self::normalize($b), self::SCALE);
        }

        return round((float) $a - (float) $b, self::SCALE);
    }

    /** Compara dois valores monetários: -1, 0 ou 1. */
    public static function compare(float|string $a, float|string $b): int
    {
        if (function_exists('bccomp')) {
            return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
        }

        return round((float) $a, self::SCALE) <=> round((float) $b, self::SCALE);
    }

    /** Converte para centavos inteiros (para armazenamento/soma sem float). */
    public static function toCents(float|string $value): int
    {
        return (int) round((float) self::normalize($value) * 100);
    }

    /** Converte centavos inteiros de volta para reais. */
    public static function fromCents(int $cents): float
    {
        return round($cents / 100, self::SCALE);
    }

    private static function normalize(float|string $value): string
    {
        return number_format((float) $value, self::SCALE, '.', '');
    }
}
