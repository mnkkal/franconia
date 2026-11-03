<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Attribute;
use Lunar\Models\AttributeGroup;
use Lunar\Models\Brand;
use Lunar\Models\Channel;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Country;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\Product;
use Lunar\Models\ProductType;
use Lunar\Models\TaxClass;
use Lunar\Models\TaxRate;
use Lunar\Models\TaxRateAmount;
use Lunar\Models\TaxZone;

/**
 * Base data for Franconia (mirrors lunar:install seeding, India-first):
 * channel, language, INR currency, customer group, collection group,
 * India country, GST tax class/zone/rate (18%), product attributes, type, brand.
 */
class FranconiaBaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! Channel::whereDefault(true)->exists()) {
            Channel::create([
                'name' => 'Webstore',
                'handle' => 'webstore',
                'default' => true,
                'url' => env('FRONTEND_URL', 'http://localhost:3000'),
            ]);
        }

        $language = Language::firstOrCreate(
            ['code' => 'en'],
            ['name' => 'English', 'default' => true]
        );

        $inr = Currency::firstOrCreate(
            ['code' => 'INR'],
            [
                'name' => 'Indian Rupee',
                'exchange_rate' => 1,
                'decimal_places' => 2,
                'default' => true,
                'enabled' => true,
            ]
        );
        // Demote USD if the stock installer created it first.
        Currency::where('code', 'USD')->where('id', '!=', $inr->id)
            ->update(['default' => false]);

        CustomerGroup::firstOrCreate(
            ['handle' => 'retail'],
            ['name' => 'Retail', 'default' => true]
        );

        $collectionGroup = CollectionGroup::firstOrCreate(
            ['handle' => 'main'],
            ['name' => 'Main']
        );

        Country::firstOrCreate(
            ['iso3' => 'IND'],
            [
                'name' => 'India',
                'iso2' => 'IN',
                'phonecode' => '91',
                'capital' => 'New Delhi',
                'currency' => 'INR',
                'native' => 'भारत',
                'emoji' => '🇮🇳',
                'emoji_u' => 'U+1F1EE U+1F1F3',
            ]
        );

        $taxClass = TaxClass::firstOrCreate(
            ['name' => 'GST 18%'],
            ['default' => true]
        );

        $india = Country::where('iso3', 'IND')->first();
        $zone = TaxZone::firstOrCreate(
            ['name' => 'India GST Zone'],
            [
                'zone_type' => 'country',
                'price_display' => 'tax_inclusive',
                'default' => true,
                'active' => true,
            ]
        );
        if ($india && ! $zone->countries()->where('country_id', $india->id)->exists()) {
            $zone->countries()->create(['country_id' => $india->id]);
        }

        $rate = TaxRate::firstOrCreate(
            ['name' => 'GST 18%', 'tax_zone_id' => $zone->id],
            []
        );
        TaxRateAmount::firstOrCreate(
            ['tax_rate_id' => $rate->id, 'tax_class_id' => $taxClass->id],
            ['percentage' => 18]
        );

        if (! Attribute::count()) {
            $group = AttributeGroup::create([
                'attributable_type' => Product::morphName(),
                'name' => collect(['en' => 'Details']),
                'handle' => 'details',
                'position' => 1,
            ]);
            $collectionGroup2 = AttributeGroup::create([
                'attributable_type' => Collection::morphName(),
                'name' => collect(['en' => 'Details']),
                'handle' => 'collection_details',
                'position' => 1,
            ]);
            foreach ([
                ['product', $group->id, 'Name', 'name', 1, true],
                ['collection', $collectionGroup2->id, 'Name', 'name', 1, true],
                ['product', $group->id, 'Description', 'description', 2, false],
                ['collection', $collectionGroup2->id, 'Description', 'description', 2, false],
            ] as [$type, $gid, $name, $handle, $pos, $required]) {
                Attribute::create([
                    'attribute_type' => $type,
                    'attribute_group_id' => $gid,
                    'position' => $pos,
                    'name' => ['en' => $name],
                    'handle' => $handle,
                    'section' => 'main',
                    'type' => TranslatedText::class,
                    'required' => $required,
                    'default_value' => null,
                    'configuration' => ['richtext' => $handle === 'description'],
                    'system' => $handle === 'name',
                    'description' => ['en' => ''],
                ]);
            }
        }

        if (! ProductType::count()) {
            $type = ProductType::create(['name' => 'Eau de Parfum']);
            $type->mappedAttributes()->attach(
                Attribute::whereAttributeType(Product::morphName())->pluck('id')
            );
        }

        Brand::firstOrCreate(
            ['name' => 'Franconia Fine Fragrances'],
            []
        );

        $this->command->info('Franconia base data seeded (INR, GST 18%, channel, attributes).');
    }
}
