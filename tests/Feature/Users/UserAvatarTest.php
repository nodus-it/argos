<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Filament\Workspaces\Pages\Onboarding;
use App\Filament\Workspaces\Clusters\Account\Pages\Profile;
use App\Models\User;
use App\Services\User\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Profile pictures: upload (profile, onboarding), serving, replacing. */
class UserAvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(User::AVATAR_DISK);
    }

    public function test_a_picture_is_stored_served_and_replaced(): void
    {
        $user = User::factory()->create();
        app(UserService::class)->setAvatar($user, UploadedFile::fake()->image('me.jpg', 200, 200));
        $first = $user->fresh()->avatar_path;

        $this->assertNotNull($first);
        Storage::disk(User::AVATAR_DISK)->assertExists($first);
        $this->assertStringContainsString('/users/'.$user->id.'/avatar', (string) $user->fresh()->avatarUrl());
        $this->assertSame($user->fresh()->avatarUrl(), $user->fresh()->getFilamentAvatarUrl());

        $this->actingAs(User::factory()->create())->get((string) $user->fresh()->avatarUrl())->assertOk();

        app(UserService::class)->setAvatar($user->fresh(), UploadedFile::fake()->image('new.png'));
        Storage::disk(User::AVATAR_DISK)->assertMissing($first);

        app(UserService::class)->setAvatar($user->fresh(), null);
        $this->assertNull($user->fresh()->avatarUrl());
    }

    public function test_pictures_are_for_signed_in_people_only(): void
    {
        $user = User::factory()->create();
        app(UserService::class)->setAvatar($user, UploadedFile::fake()->image('me.jpg'));

        $this->get((string) $user->fresh()->avatarUrl())->assertRedirect();
        $this->actingAs($user)->get(route('users.avatar', ['user' => User::factory()->create()]))->assertNotFound();
    }

    public function test_the_onboarding_takes_a_picture_first(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Onboarding::class)
            ->set('avatarUpload', UploadedFile::fake()->image('me.jpg', 300, 300))
            ->assertHasNoErrors()
            ->assertSet('avatarUpload', null);

        $this->assertNotNull($admin->fresh()->avatar_path);

        Livewire::actingAs($admin)->test(Onboarding::class)
            ->set('avatarUpload', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->assertHasErrors('avatarUpload');
    }

    public function test_the_profile_offers_the_upload(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Profile::class)
            ->assertSee(__('common.profile.avatar_label'));
    }
}
