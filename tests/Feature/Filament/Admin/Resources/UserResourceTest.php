<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\App\Clusters\System\Resources\UserResource\Pages\CreateUser;
use App\Filament\App\Clusters\System\Resources\UserResource\Pages\EditUser;
use App\Filament\App\Clusters\System\Resources\UserResource\Pages\ListUsers;
use App\Filament\App\Pages\Auth\RequestAccessLink;
use App\Models\User;
use App\Services\User\UserService;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_admin_lists_and_creates_users(): void
    {
        Livewire::test(ListUsers::class)->assertSuccessful()->assertCanSeeTableRecords([$this->admin]);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Pair Partner', 'email' => 'pair@example.com', 'password' => 'secret-pass', 'is_admin' => false])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'pair@example.com')->firstOrFail();
        $this->assertFalse($user->isAdmin());
        $this->assertTrue(Hash::check('secret-pass', $user->password));
    }

    public function test_admin_can_grant_the_admin_role_and_keep_the_password(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['is_admin' => true, 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_the_last_admin_cannot_be_deleted_or_demoted(): void
    {
        $other = User::factory()->admin()->create();
        app(UserService::class)->remove($other);
        $this->assertModelMissing($other);

        try {
            app(UserService::class)->remove($this->admin);
            $this->fail('expected RuntimeException');
        } catch (RuntimeException) {
            $this->assertModelExists($this->admin);
        }

        $this->expectException(RuntimeException::class);
        app(UserService::class)->setAdmin($this->admin, false);
    }

    public function test_admins_cannot_demote_themselves_even_with_other_admins(): void
    {
        User::factory()->admin()->create();

        $this->expectException(RuntimeException::class);
        app(UserService::class)->update($this->admin, ['is_admin' => false]);
    }

    public function test_delete_action_removes_other_users(): void
    {
        $user = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($user);
    }

    public function test_non_admins_cannot_reach_user_management(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('filament.app.system.resources.users.index'))->assertForbidden();
        $this->get(route('filament.app.system.resources.users.create'))->assertForbidden();
    }

    public function test_is_admin_is_not_mass_assignable(): void
    {
        $user = User::query()->create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-pass', 'is_admin' => true]);

        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_a_user_created_without_password_gets_an_access_link_to_set_one(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'New Colleague', 'email' => 'new@example.com', 'locale' => 'en'])
            ->call('create')
            ->assertHasNoFormErrors();
        $user = User::query()->where('email', 'new@example.com')->firstOrFail();

        $text = Livewire::test(ListUsers::class)
            ->mountTableAction('accessLink', $user)
            ->assertTableActionDataSet(fn (array $data): bool => str_contains($data['text'], 'New Colleague'))
            ->instance()->mountedActions[0]['data']['text'] ?? '';

        $this->assertMatchesRegularExpression('#/password-reset/reset\?[^\s]*token=([^&\s]+)#', $text);
        preg_match('#token=([^&\s]+)#', $text, $m);
        $this->assertTrue(Password::broker('users')->tokenExists($user, urldecode($m[1])));
        $this->assertStringContainsString('claude setup-token', $text);
    }

    public function test_forgot_password_says_to_ask_an_admin_when_no_mail_goes_out(): void
    {
        auth()->logout();
        config(['mail.default' => 'log']);
        $user = User::factory()->create(['email' => 'someone@example.com']);

        Livewire::test(RequestAccessLink::class)
            ->assertSee(__('users.access_link.no_mail'))
            ->assertDontSee(__('filament-panels::auth/pages/password-reset/request-password-reset.form.actions.request.label'))
            ->set('data.email', 'someone@example.com')
            ->call('request')
            ->assertNotified(__('users.access_link.no_mail'));

        $this->assertFalse(DB::table('password_reset_tokens')->where('email', $user->email)->exists());
    }
}
