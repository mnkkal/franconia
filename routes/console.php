<?php

use App\Support\ByobPricing;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Lunar\Models\Currency;
use Lunar\Models\ProductVariant;

Schedule::command('franconia:maintain')->everyMinute()->withoutOverlapping(15);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Apply prices to an existing catalogue without resetting stock or product content.
Artisan::command('franconia:update-prices', function () {
    $inr = Currency::where('code', 'INR')->firstOrFail();
    $updated = DB::transaction(function () use ($inr) {
        $count = 0;
        foreach (ProductVariant::whereIn('sku', [
            'FR-NOB-20', 'FR-NOB-50', 'FR-BLA-20', 'FR-BLA-50',
            'FR-PET-20', 'FR-PET-50', 'FR-PEA-20', 'FR-PEA-50',
            'FR-ELI-20', 'FR-ELI-50',
        ])->get() as $variant) {
            $size = $variant->attribute_data?->get('size')?->getValue();
            $tier = ByobPricing::tiers()[$size] ?? null;
            if (! $tier) {
                continue;
            }
            $variant->prices()->updateOrCreate([
                'currency_id' => $inr->id, 'customer_group_id' => null, 'min_quantity' => 1,
            ], ['price' => $tier['totals'][1], 'compare_price' => $tier['mrp']]);
            $count++;
        }

        return $count;
    });
    $this->info("Updated {$updated} variants. Stock and product content preserved.");
})->purpose('Apply BYOB single-bottle prices to the existing Franconia catalogue');
