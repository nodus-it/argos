<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\WorkerImageEntityStatus;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\CreateWorkerStack;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\EditWorkerStack;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\ListWorkerStacks;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\ViewWorkerStack;
use App\Models\WorkerStack;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WorkerStackResource extends Resource
{
    protected static ?string $model = WorkerStack::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-square-3-stack-3d';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.worker');
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getModelLabel(): string
    {
        return __('worker.stacks.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('worker.stacks.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('worker.stacks.sections.definition'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('worker.stacks.fields.name'))
                        ->helperText(__('worker.stacks.fields.name_help'))
                        ->required()
                        ->maxLength(64)
                        ->disabled(fn (?WorkerStack $record): bool => $record?->is_builtin === true),

                    TextInput::make('label')
                        ->label(__('worker.stacks.fields.label'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('base_image')
                        ->label(__('worker.stacks.fields.base_image'))
                        ->helperText(__('worker.stacks.fields.base_image_help'))
                        ->required()
                        ->maxLength(255),

                    TagsInput::make('capabilities')
                        ->label(__('worker.stacks.fields.capabilities'))
                        ->helperText(__('worker.stacks.fields.capabilities_help'))
                        ->reorderable(),

                    TagsInput::make('common_tools')
                        ->label(__('worker.stacks.fields.common_tools'))
                        ->reorderable(),

                    Select::make('status')
                        ->label(__('worker.stacks.fields.status'))
                        ->options(WorkerImageEntityStatus::class)
                        ->default(WorkerImageEntityStatus::Active->value)
                        ->required()
                        ->native(false),
                ]),

            Section::make(__('worker.stacks.sections.dockerfile'))
                ->schema([
                    Textarea::make('dockerfile_body')
                        ->label(__('worker.stacks.fields.dockerfile_body'))
                        ->required()
                        ->rows(18)
                        ->extraInputAttributes([
                            'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px;',
                            'spellcheck' => 'false',
                        ]),
                ]),

            Section::make(__('worker.stacks.sections.metadata'))
                ->collapsed()
                ->schema([
                    Toggle::make('is_builtin')
                        ->label(__('worker.stacks.fields.is_builtin'))
                        ->disabled()
                        ->dehydrated(false),

                    TextInput::make('installed_version')
                        ->label(__('worker.stacks.fields.installed_version'))
                        ->disabled()
                        ->dehydrated(false),

                    TextInput::make('upstream_version')
                        ->label(__('worker.stacks.fields.upstream_version'))
                        ->disabled()
                        ->dehydrated(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('worker.stacks.fields.name'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('label')
                    ->label(__('worker.stacks.fields.label'))
                    ->searchable(),

                IconColumn::make('is_builtin')
                    ->label(__('worker.stacks.fields.is_builtin'))
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->falseIcon('heroicon-o-pencil-square'),

                TextColumn::make('status')
                    ->label(__('worker.stacks.fields.status'))
                    ->badge(),

                IconColumn::make('has_update')
                    ->label(__('worker.stacks.fields.has_update'))
                    ->boolean()
                    ->trueColor('warning')
                    ->trueIcon('heroicon-o-arrow-up-circle')
                    ->falseIcon('heroicon-o-check-circle'),

                TextColumn::make('capabilities')
                    ->label(__('worker.stacks.fields.capabilities'))
                    ->state(fn (WorkerStack $r): string => implode(', ', $r->capabilities ?? []))
                    ->limit(40),

                TextColumn::make('last_built_at')
                    ->label(__('worker.stacks.fields.last_built_at'))
                    ->dateTime()
                    ->since()
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('is_builtin')
                    ->label(__('worker.stacks.fields.is_builtin')),
                SelectFilter::make('status')
                    ->options(WorkerImageEntityStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (WorkerStack $r): bool => ! $r->is_builtin),
                self::duplicateAction(),
                DeleteAction::make()->visible(fn (WorkerStack $r): bool => ! $r->is_builtin),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    /**
     * Clone a stack into a fresh user stack as a starting point. Works for
     * built-ins (the documented use case — "I want to tweak php-8.4")
     * AND for user stacks (handy for variants of an in-house base image).
     * The replica is forced to is_builtin=false and gets a unique name so
     * BuiltinSync can never accidentally overwrite it.
     */
    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label(__('worker.stacks.actions.duplicate'))
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->action(function (WorkerStack $record): void {
                $copy = $record->replicate([
                    'is_builtin',
                    'last_builtin_hash',
                    'last_built_at',
                    'last_checked_at',
                    'installed_version',
                    'upstream_version',
                    'has_update',
                ]);
                $copy->name = self::uniqueStackName($record->name.'-copy');
                $copy->label = $record->label.' (Kopie)';
                $copy->is_builtin = false;
                $copy->save();

                Notification::make()
                    ->title(__('worker.stacks.notifications.duplicated', ['name' => $copy->name]))
                    ->success()
                    ->send();

                redirect(static::getUrl('edit', ['record' => $copy]));
            });
    }

    /**
     * Append a numeric suffix to keep the slug unique against the
     * existing rows. Stops at -copy-99 to avoid runaway collisions.
     */
    private static function uniqueStackName(string $candidate): string
    {
        if (! WorkerStack::query()->where('name', $candidate)->exists()) {
            return $candidate;
        }

        for ($i = 2; $i <= 99; $i++) {
            $next = $candidate.'-'.$i;
            if (! WorkerStack::query()->where('name', $next)->exists()) {
                return $next;
            }
        }

        return $candidate.'-'.uniqid();
    }

    public static function canEdit(Model $record): bool
    {
        /** @var WorkerStack $record */
        return ! $record->is_builtin;
    }

    public static function canDelete(Model $record): bool
    {
        /** @var WorkerStack $record */
        return ! $record->is_builtin;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkerStacks::route('/'),
            'create' => CreateWorkerStack::route('/create'),
            'view' => ViewWorkerStack::route('/{record}'),
            'edit' => EditWorkerStack::route('/{record}/edit'),
        ];
    }
}
