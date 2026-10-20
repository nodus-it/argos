<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Filament\Admin\Concerns\HasArgosEditHeading;
use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Support\Pages\EditRecord;
use App\Models\User;
use App\Services\EntityService;
use App\Services\User\UserService;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditUser extends EditRecord
{
    use HasArgosEditHeading;

    protected static string $resource = UserResource::class;

    protected function service(): EntityService
    {
        return app(UserService::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            UserResource::deleteAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    /**
     * @return array{icon?: string, label: string}|null
     */
    protected function argosHeadingChip(): ?array
    {
        /** @var User $record */
        $record = $this->getRecord();

        return $record->isAdmin() ? ['icon' => 'heroicon-o-shield-check', 'label' => __('users.fields.is_admin')] : null;
    }
}
