<?php

namespace App\Filament\Resources;

use App\Models\Feature;
use App\Models\TenantFeature;
use Database\Seeders\ModuleFeatureSeeder;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FeatureResource extends Resource
{
    protected static ?string $model = Feature::class;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Modules';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderBy('is_core', 'desc')->orderBy('name');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('key')->label('Key')->badge()->sortable(),
                TextColumn::make('is_core')
                    ->label('Type')
                    ->state(fn (Feature $record) => $record->is_core ? 'core' : 'addon')
                    ->badge()
                    ->color(fn (Feature $record) => $record->is_core ? 'success' : 'warning'),
                IconColumn::make('enabled')
                    ->label('Enabled')
                    ->state(fn (Feature $record) => $record->isEnabledForTenant())
                    ->boolean(),
            ])
            ->actions([
                Action::make('enable')
                    ->label('Enable')
                    ->visible(fn (Feature $record) => !$record->is_core && !$record->isEnabledForTenant())
                    ->action(function (Feature $record) {
                        TenantFeature::query()->updateOrCreate(
                            ['feature_id' => $record->id],
                            ['is_enabled' => true, 'enabled_at' => now(), 'expires_at' => null]
                        );

                        Notification::make()
                            ->title('Enabled')
                            ->body($record->name.' enabled for this tenant')
                            ->success()
                            ->send();
                    }),

                Action::make('disable')
                    ->label('Disable')
                    ->color('danger')
                    ->visible(fn (Feature $record) => !$record->is_core && $record->isEnabledForTenant())
                    ->requiresConfirmation()
                    ->action(function (Feature $record) {
                        TenantFeature::query()->updateOrCreate(
                            ['feature_id' => $record->id],
                            ['is_enabled' => false]
                        );

                        Notification::make()
                            ->title('Disabled')
                            ->body($record->name.' disabled for this tenant')
                            ->warning()
                            ->send();
                    }),

                Action::make('sync')
                    ->label('Sync from module.json')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function () {
                        // Ensure the feature catalog matches discovered modules.
                        app(ModuleFeatureSeeder::class)->run();

                        Notification::make()
                            ->title('Synced')
                            ->body('Module catalog synced from module manifests')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => FeatureResource\Pages\ManageFeatures::route('/'),
        ];
    }
}
