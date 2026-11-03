<?php

namespace App\Support;

class ByobPricing
{
    public static function sizeForSku(string $sku): string
    {
        return preg_match('/^FR-(NOB|BLA|PET|PEA|ELI)-(20|50)$/', $sku, $matches) ? $matches[2].'ML' : '';
    }

    public static function tiers(): array
    {
        return json_decode(file_get_contents(config_path('byob.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function total(string $size, int $qty): ?int
    {
        $tier = self::tiers()[$size] ?? null;

        return $tier ? intdiv($qty, 5) * $tier['totals'][5] + $tier['totals'][$qty % 5] : null;
    }

    public static function apply(array $lines): array
    {
        foreach (array_keys(self::tiers()) as $size) {
            $qty = array_sum(array_column(array_filter($lines, fn ($l) => $l['size'] === $size), 'qty'));
            if (! $qty) {
                continue;
            }
            $total = self::total($size, $qty);
            $used = 0;
            foreach ($lines as &$line) {
                if ($line['size'] !== $size) {
                    continue;
                }
                $line['line_total'] = intdiv($total * ($used + $line['qty']), $qty) - intdiv($total * $used, $qty);
                $line['unit_price'] = intdiv($line['line_total'], $line['qty']);
                $used += $line['qty'];
            }
            unset($line);
        }

        return $lines;
    }
}
