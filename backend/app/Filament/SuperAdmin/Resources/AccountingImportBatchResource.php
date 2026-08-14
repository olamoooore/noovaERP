<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Models\Accounting\AccountingImportBatch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountingImportBatchResource extends Resource
{
    protected static ?string $model = AccountingImportBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->orderBy('created_at', 'desc');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('import_type')
                    ->required()
                    ->maxLength(50),
                Forms\Components\TextInput::make('file_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('total_rows')->numeric(),
                Forms\Components\TextInput::make('valid_rows')->numeric(),
                Forms\Components\TextInput::make('error_rows')->numeric(),
                Forms\Components\TextInput::make('imported_rows')->numeric(),
                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'validated' => 'Validated',
                        'importing' => 'Importing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ])
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('import_type')->badge()->sortable(),
                TextColumn::make('file_name')->searchable()->limit(30),
                TextColumn::make('total_rows')->sortable(),
                TextColumn::make('valid_rows')->sortable(),
                TextColumn::make('error_rows')->sortable(),
                TextColumn::make('imported_rows')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('import_type')
                    ->options(AccountingImportBatch::IMPORT_TYPES),
                Tables\Filters\SelectFilter::make('status')
                    ->options(AccountingImportBatch::STATUSES),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => AccountingImportBatchResource\Pages\ManageAccountingImportBatches::route('/'),
        ];
    }
}
