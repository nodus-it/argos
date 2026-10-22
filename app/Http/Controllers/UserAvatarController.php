<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serves a person's profile picture to signed-in users. */
class UserAvatarController extends Controller
{
    public function __invoke(User $user): StreamedResponse
    {
        abort_if($user->avatar_path === null || ! Storage::disk(User::AVATAR_DISK)->exists($user->avatar_path), 404);

        // The URL carries a version (?v=…), so the picture may be cached for long.
        return Storage::disk(User::AVATAR_DISK)->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
