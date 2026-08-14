<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Models\Accounting\AccountingFinding;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountingFindingResource extends Resource
{
    protected static ?string $model = AccountingFinding::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 1;

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
                Forms\Components\Select::make('finding_type')
                    ->options([
                        'balance_mismatch' => 'Balance Mismatch',
                        'duplicate_entry' => 'Duplicate Entry',
                        'missing_entry' => 'Missing Entry',
                        'unusual_amount' => 'Unusual Amount',
                        'policy_violation' => 'Policy Violation',
                        'reconciliation_issue' => 'Reconciliation Issue',
                    ])
                    ->required(),
                Forms\Components\Select::make('severity')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                        'critical' => 'Critical',
                    ])
                    ->required(),
                Forms\Components\Select::make('status')
                    ->options([
                        'open' => 'Open',
                        'acknowledged' => 'Acknowledged',
                        'under_investigation' => 'Under Investigation',
                        'resolved' => 'Resolved',
                        'dismissed' => 'Dismissed',
                        'escalated' => 'Escalated',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('entity_type'),
                Forms\Components\TextInput::make('entity_id'),
                Forms\Components\Textarea::make('description')->rows(3),
                Forms\Components\TextInput::make('risk_score')->numeric(),
                Forms\Components\Textarea::make('resolution_notes')->rows(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('finding_type')->badge()->sortable(),
                TextColumn::make('severity')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('entity_type')->searchable(),
                TextColumn::make('entity_id')->searchable(),
                TextColumn::make('risk_score')->numeric()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('finding_type')
                    ->options(AccountingFinding::FINDING_TYPES),
                Tables\Filters\SelectFilter::make('severity')
                    ->options(AccountingFinding::SEVERITY_LEVELS),
                Tables\Filters\SelectFilter::make('status')
                    ->options(AccountingFinding::STATUSES),
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
            'index' => AccountingFindingResource\Pages\ManageAccountingFindings::route('/'),
        ];
    }
}
