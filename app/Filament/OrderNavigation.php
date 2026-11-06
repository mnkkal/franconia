<?php

namespace App\Filament;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Lunar\Admin\Filament\Resources\OrderResource;

class OrderNavigation
{
    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        $groups = [];
        foreach (array_unique([...Filament::getCurrentPanel()->getPages(), ...Filament::getCurrentPanel()->getResources(), ...Filament::getCurrentPanel()->getClusters()]) as $class) {
            if ($class === OrderResource::class || ! $class::shouldRegisterNavigation() || ! $class::canAccess()) {
                continue;
            }
            foreach ($class::getNavigationItems() as $item) {
                $group = $item->getGroup();
                $label = $group instanceof \BackedEnum ? $group->value : ($group instanceof \UnitEnum ? $group->name : $group);
                $groups[$label ?: ''][] = $item;
            }
        }
        foreach ($groups as $label => $items) {
            usort($items, fn ($a, $b) => ($a->getSort() ?? 0) <=> ($b->getSort() ?? 0));
            if ($label === '') {
                $builder->items($items);
            } else {
                $builder->group($label, $items);
            }
        }

        return $builder;
    }
}
