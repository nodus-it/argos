<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\Profile;
use App\Http\Middleware\SetUserLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_middleware_sets_locale_for_authenticated_user(): void
    {
        $user = User::factory()->create(['locale' => 'de']);
        $this->actingAs($user);

        $this->get('/admin/settings');

        $this->assertSame('de', App::getLocale());
    }

    public function test_middleware_falls_back_to_app_locale_when_no_locale_set(): void
    {
        $user = User::factory()->create(['locale' => null]);
        $this->actingAs($user);

        $this->get('/admin/settings');

        $this->assertSame(config('app.locale'), App::getLocale());
    }

    public function test_locale_is_saved_when_profile_form_submitted(): void
    {
        $user = User::factory()->create(['locale' => 'en']);
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->fillForm(['locale' => 'de'])
            ->call('save');

        $this->assertDatabaseHas(User::class, [
            'id' => $user->id,
            'locale' => 'de',
        ]);
    }

    public function test_locale_can_be_changed_back_to_english(): void
    {
        $user = User::factory()->create(['locale' => 'de']);
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->fillForm(['locale' => 'en'])
            ->call('save');

        $this->assertDatabaseHas(User::class, [
            'id' => $user->id,
            'locale' => 'en',
        ]);
    }

    public function test_locale_column_exists_on_users_table(): void
    {
        $user = User::factory()->create(['locale' => 'de']);

        $this->assertSame('de', $user->fresh()?->locale);
    }

    public function test_locale_can_be_null(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->assertNull($user->fresh()?->locale);
    }

    public function test_set_user_locale_middleware_applies_locale(): void
    {
        $user = User::factory()->create(['locale' => 'de']);
        $request = new Request;
        $request->setUserResolver(fn () => $user);

        $middleware = new SetUserLocale;
        $middleware->handle($request, fn () => response('ok'));

        $this->assertSame('de', App::getLocale());
    }

    public function test_set_user_locale_middleware_skips_when_no_locale(): void
    {
        $appLocale = config('app.locale');
        $user = User::factory()->create(['locale' => null]);
        $request = new Request;
        $request->setUserResolver(fn () => $user);

        $middleware = new SetUserLocale;
        $middleware->handle($request, fn () => response('ok'));

        $this->assertSame($appLocale, App::getLocale());
    }
}
