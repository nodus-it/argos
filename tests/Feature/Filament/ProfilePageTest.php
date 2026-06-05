<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['locale' => 'en']);
        $this->actingAs($this->user);
    }

    public function test_profile_page_renders_successfully(): void
    {
        Livewire::test(Profile::class)
            ->assertSuccessful();
    }

    public function test_profile_page_uses_full_admin_layout(): void
    {
        // isSimple()=false ensures the page renders inside .fi-main (index layout)
        // so all Argos-scoped CSS applies — without this, the form would render
        // in the simple/login-style layout where no custom input borders show.
        $this->assertFalse(Profile::isSimple());
    }

    public function test_profile_form_prefills_current_user_data(): void
    {
        Livewire::test(Profile::class)
            ->assertFormSet([
                'name' => $this->user->name,
                'email' => $this->user->email,
                'locale' => 'en',
            ]);
    }

    public function test_profile_can_save_name_and_locale(): void
    {
        Livewire::test(Profile::class)
            ->fillForm([
                'name' => 'Updated Name',
                'email' => $this->user->email,
                'locale' => 'de',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(User::class, [
            'id' => $this->user->id,
            'name' => 'Updated Name',
            'locale' => 'de',
        ]);
    }
}
