<?php

namespace App\Console\Commands;

use App\Services\SyncSizeOptions;
use App\Support\ByobPricing;
use Database\Seeders\FranconiaBaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Lunar\FieldTypes\Text;
use Lunar\Models\Attribute;
use Lunar\Models\Brand;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Currency;
use Lunar\Models\Language;
use Lunar\Models\Product;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;
use Lunar\Models\Tag;
use Lunar\Models\TaxClass;

class SyncCatalogue extends Command
{
    protected $signature = 'franconia:sync-catalogue';

    protected $description = 'Add missing website products and variants without overwriting existing catalogue data';

    public function handle(): int
    {
        $catalogue = json_decode(file_get_contents(config_path('catalogue.json')), true, 512, JSON_THROW_ON_ERROR);
        $counts = DB::transaction(function () use ($catalogue) {
            $this->call('db:seed', ['--class' => FranconiaBaseSeeder::class, '--force' => true]);
            $language = Language::where('code', 'en')->firstOrFail();
            $currency = Currency::where('code', 'INR')->firstOrFail();
            $brand = Brand::where('name', 'Franconia Fine Fragrances')->firstOrFail();
            $type = ProductType::firstOrCreate(['name' => 'Eau de Parfum']);
            $type->mappedAttributes()->syncWithoutDetaching(Attribute::whereAttributeType(Product::morphName())->pluck('id'));
            $tax = TaxClass::where('name', 'GST 18%')->firstOrFail();
            $group = CollectionGroup::where('handle', 'main')->firstOrFail();
            $counts = ['products' => 0, 'variants' => 0];
            $collections = [];

            foreach ($catalogue['collections'] as $data) {
                $collection = Collection::whereHas('urls', fn ($q) => $q->where('slug', $data['slug']))->first();
                if (! $collection) {
                    $collection = Collection::create([
                        'collection_group_id' => $group->id, 'type' => 'static',
                        'attribute_data' => collect(['name' => new Text($data['name']), 'description' => new Text($data['desc'])]),
                    ]);
                    $collection->urls()->where('slug', '!=', $data['slug'])->delete();
                    $collection->urls()->updateOrCreate(['slug' => $data['slug']], ['language_id' => $language->id, 'default' => true]);
                }
                $collections[$data['slug']] = $collection;
            }

            foreach ($catalogue['products'] as $data) {
                $skuPrefix = 'FR-'.strtoupper(substr($data['slug'], 0, 3));
                $skus = [$skuPrefix.'-50', $skuPrefix.'-20'];
                $product = Product::withTrashed()->whereHas('urls', fn ($q) => $q->where('slug', $data['slug']))->first()
                    ?? Product::withTrashed()->whereHas('variants', fn ($q) => $q->withTrashed()->whereIn('sku', $skus))->first();
                if ($product?->trashed()) {
                    $this->warn($data['name'].' is archived and was left unchanged. Restore it manually if required.');

                    continue;
                }
                if (! $product) {
                    $product = Product::create([
                        'product_type_id' => $type->id, 'brand_id' => $brand->id, 'status' => 'published',
                        'attribute_data' => collect(['name' => new Text($data['name']), 'description' => new Text($data['desc'])]),
                    ]);
                    $product->urls()->where('slug', '!=', $data['slug'])->delete();
                    $product->urls()->updateOrCreate(['slug' => $data['slug']], ['language_id' => $language->id, 'default' => true]);
                    $product->tags()->sync(collect($data['badges'])->map(fn ($badge) => Tag::firstOrCreate(['value' => strtoupper($badge)])->id));
                    $product->collections()->sync(collect($data['collections'])->filter(fn ($slug) => isset($collections[$slug]))->mapWithKeys(fn ($slug) => [$collections[$slug]->id => ['position' => 1]])->all());
                    $counts['products']++;
                }

                foreach (['50', '20'] as $size) {
                    $sku = $skuPrefix.'-'.$size;
                    $variant = ProductVariant::withTrashed()->where('sku', $sku)->first();
                    if ($variant?->trashed()) {
                        $this->warn($sku.' is archived and was left unchanged.');

                        continue;
                    }
                    if ($variant && (int) $variant->product_id !== (int) $product->id) {
                        throw new \RuntimeException('SKU '.$sku.' belongs to another product; import stopped without reassigning it.');
                    }
                    if (! $variant) {
                        $variant = ProductVariant::create([
                            'product_id' => $product->id, 'tax_class_id' => $tax->id, 'sku' => $sku,
                            'stock' => 0, 'shippable' => true,
                            'purchasable' => in_array('Coming Soon', $data['badges'], true) ? 'out-of-stock' : 'always',
                            'attribute_data' => collect(['size' => new Text($size.'ML')]),
                        ]);
                        $counts['variants']++;
                    }
                    $tier = ByobPricing::tiers()[$size.'ML'];
                    $variant->prices()->firstOrCreate([
                        'currency_id' => $currency->id, 'customer_group_id' => null, 'min_quantity' => 1,
                    ], ['price' => $tier['totals'][1], 'compare_price' => $tier['mrp']]);
                }
                foreach (app(SyncSizeOptions::class)->sync($product) as $sku) {
                    $this->warn($sku.' already has a different Size option; left unchanged. Review it in admin.');
                }
            }

            return $counts;
        }, 3);

        $this->info("Added {$counts['products']} products and {$counts['variants']} variants. Existing product data, prices and stock preserved.");
        if ($counts['variants']) {
            $this->warn('New variants start with stock 0. Enter actual stock in admin before accepting orders.');
        }

        return self::SUCCESS;
    }
}
