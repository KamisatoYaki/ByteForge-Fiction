<?php

namespace App\Helpers;

/**
 * Server-side monetary helpers for Purchase Request line/totals.
 * Matches OrderItem decimal(20,4) conventions without floating drift.
 */
class PurchaseRequestMoney
{
    public const SCALE = 4;

    public static function multiply(int|string $quantity, int|float|string $unitCost): string
    {
        $qty = (string) (int) $quantity;
        $cost = self::normalize($unitCost);

        if (function_exists('bcmul')) {
            return bcmul($qty, $cost, self::SCALE);
        }

        return number_format(((float) $qty) * ((float) $cost), self::SCALE, '.', '');
    }

    public static function add(string $left, string $right): string
    {
        if (function_exists('bcadd')) {
            return bcadd($left, $right, self::SCALE);
        }

        return number_format(((float) $left) + ((float) $right), self::SCALE, '.', '');
    }

    public static function normalize(int|float|string $value): string
    {
        if (is_string($value) && $value !== '' && function_exists('bcadd')) {
            return bcadd($value, '0', self::SCALE);
        }

        return number_format((float) $value, self::SCALE, '.', '');
    }

    public static function sum(iterable $amounts): string
    {
        $total = self::normalize(0);
        foreach ($amounts as $amount) {
            $total = self::add($total, self::normalize($amount));
        }

        return $total;
    }
}
