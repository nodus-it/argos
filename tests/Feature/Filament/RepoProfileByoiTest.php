<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RepoProfileByoiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_byoi_hides_the_stack_select(): void
    {
        Livewire::test(CreateRepoProfile::class)
            ->fillForm(['platform' => 'github', 'worker_source' => 'standard'])
            ->assertFormFieldVisible('worker_stack_id')
            ->fillForm(['worker_source' => 'byoi'])
            ->assertFormFieldHidden('worker_stack_id');
    }
}
