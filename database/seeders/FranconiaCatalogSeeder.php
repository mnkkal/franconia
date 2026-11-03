<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Lunar\FieldTypes\Text;
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
use Lunar\Models\Url;

/**
 * Franconia catalogue: 4 collections + 5 EDPs (Noble, Blaze, Petals, Pearls, Elixir)
 * with 20ML/50ML variants, INR prices (minor units), stock, URLs and tags.
 * BYOB pricing: 20ML ₹799 (MRP ₹899), 50ML ₹1599 (MRP ₹1799).
 * NOTE: no "also as" reference names are stored — those belong to other makers.
 */
class FranconiaCatalogSeeder extends Seeder
{
    private array $products = [
        [
            'slug' => 'noble', 'name' => 'NOBLE',
            'desc' => 'A refined citrus-aromatic composition with lavender, honey and tobacco warmth. Quiet confidence.',
            'badges' => ['Bestseller', 'On Sale'],
            'collections' => ['best-sellers'],
        ],
        [
            'slug' => 'blaze', 'name' => 'BLAZE',
            'desc' => 'An energetic amber-woody rush — blood orange and pink pepper over magnetic amber.',
            'badges' => ['New Launch', 'On Sale'],
            'collections' => ['new-arrivals', 'gifting'],
        ],
        [
            'slug' => 'petals', 'name' => 'PETALS',
            'desc' => 'A soft yet mysterious floral — peony and Turkish rose wrapped in dark musk.',
            'badges' => ['Bestseller'],
            'collections' => ['best-sellers', 'gifting'],
        ],
        [
            'slug' => 'pearls', 'name' => 'PEARLS',
            'desc' => 'Luminous white florals with sparkling aldehydes and creamy sandalwood.',
            'badges' => ['New Launch'],
            'collections' => ['new-arrivals'],
        ],
        [
            'slug' => 'elixir', 'name' => 'ELIXIR',
            'desc' => 'A rich, enveloping oriental — saffron, oud and amber wrapped in vanilla. Coming soon.',
            'badges' => ['Coming Soon'],
            'collections' => ['new-arrivals', 'gifting'],
        ],
    ];

    private array $collections = [
        ['slug' => 'new-arrivals', 'name' => 'New Arrivals', 'desc' => 'The latest drops from the house.'],
        ['slug' => 'best-sellers', 'name' => 'Best Sellers', 'desc' => 'Most loved — Noble and Petals.'],
        ['slug' => 'combos', 'name' => 'Combos & Offers', 'desc' => 'Curated sets and limited-period offers — more fragrance, better value.'],
        ['slug' => 'gifting', 'name' => 'Gifting', 'desc' => 'A thoughtful gift for someone you love — or yourself.'],
    ];

    public function run(): void
    {
        $language = Language::where('code', 'en')->firstOrFail();
        $inr = Currency::where('code', 'INR')->firstOrFail();
        $type = ProductType::firstOrFail();
        $brand = Brand::firstOrFail();
        $taxClass = TaxClass::firstOrFail();
        $group = CollectionGroup::where('handle', 'main')->firstOrFail();

        $collectionModels = [];
        foreach ($this->collections as $c) {
            $model = Collection::query()
                ->whereHas('urls', fn ($q) => $q->where('slug', $c['slug']))
                ->first();
            if (! $model) {
                $model = Collection::create([
                    'collection_group_id' => $group->id,
                    'type' => 'static',
                    'attribute_data' => collect([
                        'name' => new Text($c['name']),
                        'description' => new Text($c['desc']),
                    ]),
                ]);
                // Lunar's URL generator may auto-create a slug row on create —
                // drop extras and keep exactly our intended slug as default.
                $model->urls()->where('slug', '!=', $c['slug'])->delete();
                $url = $model->urls()->firstOrCreate(
                    ['slug' => $c['slug']],
                    ['language_id' => $language->id, 'default' => true]
                );
                $url->forceFill(['language_id' => $language->id, 'default' => true])->save();
            }
            $collectionModels[$c['slug']] = $model;
        }

        // Retired: the "Unisex" collection (client doesn't tag perfumes as unisex).
        $retired = Collection::query()
            ->whereHas('urls', fn ($q) => $q->where('slug', 'unisex'))
            ->first();
        if ($retired) {
            $retired->products()->detach();
            $retired->urls()->delete();
            $retired->delete();
        }

        $tagModels = [];
        foreach (['New Launch', 'On Sale', 'Bestseller', 'Coming Soon'] as $badge) {
            $tagModels[$badge] = Tag::firstOrCreate(['value' => strtoupper($badge)]);
        }

        foreach ($this->products as $p) {
            $product = Product::query()
                ->whereHas('urls', fn ($q) => $q->where('slug', $p['slug']))
                ->first();
            if (! $product) {
                $product = Product::create([
                    'product_type_id' => $type->id,
                    'brand_id' => $brand->id,
                    // Coming Soon stays published but out-of-stock so the
                    // storefront can show it with a Notify Me CTA.
                    'status' => 'published',
                    'attribute_data' => collect([
                        'name' => new Text($p['name']),
                        'description' => new Text($p['desc']),
                    ]),
                ]);
                $product->urls()->where('slug', '!=', $p['slug'])->delete();
                $url = $product->urls()->firstOrCreate(
                    ['slug' => $p['slug']],
                    ['language_id' => $language->id, 'default' => true]
                );
                $url->forceFill(['language_id' => $language->id, 'default' => true])->save();
            }

            // Refresh on re-run: clean description (no reference names), published status.
            $product->forceFill([
                'status' => 'published',
                'attribute_data' => collect([
                    'name' => new Text($p['name']),
                    'description' => new Text($p['desc']),
                ]),
            ])->save();

            $product->tags()->sync(
                collect($p['badges'])->map(fn ($b) => $tagModels[$b]->id)->all()
            );

            $isComingSoon = in_array('Coming Soon', $p['badges']);
            // Sizes: 20ML ₹799 (MRP ₹899), 50ML ₹1599 (MRP ₹1799). Retire old 100ML SKUs.
            $product->variants()->where('sku', 'like', '%-100')->delete();
            $variants = [
                ['label' => '50ML', 'sku' => 'FR-'.strtoupper(substr($p['slug'], 0, 3)).'-50', 'price' => 159900, 'mrp' => 179900, 'stock' => $isComingSoon ? 0 : 50],
                ['label' => '20ML', 'sku' => 'FR-'.strtoupper(substr($p['slug'], 0, 3)).'-20', 'price' => 79900, 'mrp' => 89900, 'stock' => $isComingSoon ? 0 : 65],
            ];
            foreach ($variants as $v) {
                $variant = ProductVariant::firstOrNew(['sku' => $v['sku']]);
                $variant->product_id = $product->id;
                $variant->tax_class_id = $taxClass->id;
                $variant->stock = $v['stock'];
                $variant->shippable = true;
                $variant->purchasable = $isComingSoon ? 'out-of-stock' : 'always';
                $variant->attribute_data = collect(['size' => new Text($v['label'])]);
                $variant->save();

                $variant->prices()->where('currency_id', $inr->id)->delete();
                $variant->prices()->create([
                    'currency_id' => $inr->id,
                    'price' => $v['price'],
                    'compare_price' => $v['mrp'],
                    'min_quantity' => 1,
                ]);
            }

            $sync = [];
            foreach ($p['collections'] as $cs) {
                if (isset($collectionModels[$cs])) {
                    $sync[$collectionModels[$cs]->id] = ['position' => 1];
                }
            }
            $product->collections()->sync($sync);
        }

        $this->command->info('Franconia catalogue seeded (5 products, 4 collections).');
    }
}
