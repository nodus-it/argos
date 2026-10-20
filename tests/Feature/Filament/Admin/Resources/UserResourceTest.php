<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\Admin\Resources\UserResource\Pages\CreateUser;
use App\Filament\Admin\Resources\UserResource\Pages\EditUser;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Services\User\UserService;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

        $this->get(route('filament.admin.resources.users.index'))->assertForbidden();
        $this->get(route('filament.admin.resources.users.create'))->assertForbidden();
    }

    public function test_is_admin_is_not_mass_assignable(): void
    {
        $user = User::query()->create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-pass', 'is_admin' => true]);

        $this->assertFalse($user->fresh()->isAdmin());
    }
}
