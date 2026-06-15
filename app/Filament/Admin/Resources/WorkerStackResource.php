<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\WorkerImageEntityStatus;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\CreateWorkerStack;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\EditWorkerStack;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\ListWorkerStacks;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\ViewWorkerStack;
use App\Models\WorkerStack;
use App\Services\Worker\WorkerStackService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
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
                ->description(__('worker.stacks.sections.definition_description'))
                ->icon('heroicon-o-cube')
                ->schema([
                    TextInput::make('name')
                        ->label(__('worker.stacks.fields.name'))
                        ->helperText(__('worker.stacks.fields.name_help'))
                        ->required()
                        ->maxLength(64)
                        ->disabled(fn (?WorkerStack $record): bool => $record?->is_builtin === true),

                    TextInput::make('label')
                        ->label(__('worker.stacks.fields.label'))
                        ->helperText(__('worker.stacks.fields.label_help'))
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
                        ->helperText(__('worker.stacks.fields.common_tools_help'))
                        ->reorderable(),

                    Select::make('status')
                        ->label(__('worker.stacks.fields.status'))
                        ->helperText(__('worker.stacks.fields.status_help'))
                        ->options(WorkerImageEntityStatus::class)
                        ->default(WorkerImageEntityStatus::Active->value)
                        ->required()
                        ->native(false),

                    Toggle::make('is_builtin')
                        ->label(__('worker.stacks.fields.is_builtin'))
                        ->helperText(__('worker.stacks.fields.is_builtin_help'))
                        ->disabled()
                        ->dehydrated(false),
                ]),

            Section::make(__('worker.stacks.sections.dockerfile'))
                ->description(__('worker.stacks.sections.dockerfile_description'))
                ->icon('heroicon-o-command-line')
                ->schema([
                    // Code-editor-style textarea, matched to the task-log
                    // pane (slate-950 surface, slate-100 text, mono).
                    // Filament's input wrapper carries its own bg-white
                    // classes — Tailwind's `!` prefix forces the rule
                    // past those. Tab inserts 4 spaces via Alpine, no
                    // syntax highlighting (zero new deps for wave 1).
                    Textarea::make('dockerfile_body')
                        ->hiddenLabel()
                        ->required()
                        ->rows(22)
                        ->extraAttributes([
                            'class' => 'rounded-xl overflow-hidden border border-slate-800 shadow-2xl shadow-black/50 !bg-slate-950',
                        ])
                        ->extraInputAttributes([
                            'spellcheck' => 'false',
                            'wrap' => 'off',
                            'autocorrect' => 'off',
                            'autocapitalize' => 'off',
                            'class' => 'font-mono text-xs leading-5 !bg-slate-950 !text-slate-100 !border-0 !shadow-none focus:!ring-0',
                            'style' => 'tab-size: 4; -moz-tab-size: 4; padding: 1rem;',
                            'x-data' => '{}',
                            // Tab inserts four spaces in place of the focus jump.
                            'x-on:keydown.tab.prevent' => "(\$el => {
                                const start = \$el.selectionStart;
                                const end = \$el.selectionEnd;
                                \$el.value = \$el.value.substring(0, start) + '    ' + \$el.value.substring(end);
                                \$el.selectionStart = \$el.selectionEnd = start + 4;
                                \$el.dispatchEvent(new Event('input'));
                            })(\$event.target)",
                        ]),
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
                    ->searchable()
                    ->visibleFrom('md'),

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
                    ->limit(40)
                    ->visibleFrom('md'),

                TextColumn::make('last_built_at')
                    ->label(__('worker.stacks.fields.last_built_at'))
                    ->dateTime()
                    ->since()
                    ->placeholder('—')
                    ->visibleFrom('md'),
            ])
            ->filters([
                TernaryFilter::make('is_builtin')
                    ->label(__('worker.stacks.fields.is_builtin')),
                SelectFilter::make('status')
                    ->options(WorkerImageEntityStatus::class),
            ])
            ->recordUrl(fn (WorkerStack $r): string => static::getUrl(
                $r->is_builtin ? 'view' : 'edit',
                ['record' => $r],
            ))
            ->recordActions([
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
                $copy = app(WorkerStackService::class)->duplicate($record);

                Notification::make()
                    ->title(__('worker.stacks.notifications.duplicated', ['name' => $copy->name]))
                    ->success()
                    ->send();

                redirect(static::getUrl('edit', ['record' => $copy]));
            });
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
            // Built-in stacks are read-only — they open the styled detail
            // (view) page; user stacks open edit directly (recordUrl branches).
            'view' => ViewWorkerStack::route('/{record}'),
            'edit' => EditWorkerStack::route('/{record}/edit'),
        ];
    }
}
