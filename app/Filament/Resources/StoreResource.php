<?php

namespace App\Filament\Resources;

use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Lunar\Admin\Models\Staff;

abstract class StoreResource extends Resource
{
    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof Staff && $user->admin;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Store operations';
    }
}
