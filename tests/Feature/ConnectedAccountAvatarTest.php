<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\ConnectedAccounts;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConnectedAccountAvatarTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('avatarProvider')]
    public function test_normalize_avatar_url(?string $avatar, ?string $instanceUrl, string $appUrl, ?string $expected): void
    {
        config(['app.url' => $appUrl]);

        $this->assertSame($expected, ConnectedAccount::normalizeAvatarUrl($avatar, $instanceUrl));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: string, 3: ?string}>
     */
    public static function avatarProvider(): array
    {
        return [
            'null stays null' => [null, null, 'https://argos.test', null],
            'empty becomes null' => ['   ', 'https://gitlab.example.com', 'https://argos.test', null],
            'absolute https passes through' => ['https://gitlab.example.com/uploads/a.png', 'https://gitlab.example.com', 'https://argos.test', 'https://gitlab.example.com/uploads/a.png'],
            'relative resolves against instance' => ['/uploads/-/system/user/avatar/2/avatar.png', 'https://gitlab.example.com', 'https://argos.test', 'https://gitlab.example.com/uploads/-/system/user/avatar/2/avatar.png'],
            'relative trims trailing slash on instance' => ['/uploads/a.png', 'https://gitlab.example.com/', 'https://argos.test', 'https://gitlab.example.com/uploads/a.png'],
            'relative without instance is unusable' => ['/uploads/a.png', null, 'https://argos.test', null],
            'http upgraded on https app' => ['http://gitlab.example.com/uploads/a.png', 'https://gitlab.example.com', 'https://argos.test', 'https://gitlab.example.com/uploads/a.png'],
            'http kept on http app' => ['http://gitlab.local/uploads/a.png', 'http://gitlab.local', 'http://argos.local', 'http://gitlab.local/uploads/a.png'],
            'relative http instance upgraded on https app' => ['/uploads/a.png', 'http://gitlab.example.com', 'https://argos.test', 'https://gitlab.example.com/uploads/a.png'],
        ];
    }

    public function test_page_renders_relative_gitlab_avatar_as_absolute_url(): void
    {
        config(['app.url' => 'https://argos.test']);

        $user = User::factory()->create();
        $this->actingAs($user);

        ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'gitlab',
            'name' => 'Ada Quux',
            'instance_url' => 'https://gitlab.example.com',
            'avatar' => '/uploads/-/system/user/avatar/2/avatar.png',
        ]);

        Livewire::test(ConnectedAccounts::class)
            ->assertSuccessful()
            ->assertSee('https://gitlab.example.com/uploads/-/system/user/avatar/2/avatar.png')
            // initials fallback for "Ada Quux" and the onerror handler
            ->assertSee('AQ')
            ->assertSee('onerror');
    }

    public function test_page_shows_initials_when_account_has_no_avatar(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'github',
            'name' => 'Grace Xylo',
            'avatar' => null,
        ]);

        Livewire::test(ConnectedAccounts::class)
            ->assertSuccessful()
            ->assertSee('GX')
            // no avatar image element is rendered when there is no avatar
            ->assertDontSee('object-cover');
    }
}
