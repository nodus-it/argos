<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Auth;

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * "Forgot password?": mails a reset link where mail is set up. Most
 * installations send none — there the page says to ask an administrator for
 * an access link (System → Users) instead of offering a form that mails
 * nothing.
 */
class RequestAccessLink extends RequestPasswordReset
{
    public static function sendsMail(): bool
    {
        return ! in_array((string) config('mail.default'), ['log', 'array'], true);
    }

    public function request(): void
    {
        if (! self::sendsMail()) {
            Notification::make()->title(__('users.access_link.no_mail'))->warning()->send();

            return;
        }

        parent::request();
    }

    public function content(Schema $schema): Schema
    {
        if (self::sendsMail()) {
            return parent::content($schema);
        }

        return $schema->components([Text::make(__('users.access_link.no_mail'))]);
    }
}
