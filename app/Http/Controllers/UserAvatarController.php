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
        $disk = Storage::disk(User::AVATAR_DISK);
        abort_if($user->avatar_path === null || ! $disk->exists($user->avatar_path), 404);
        // Only raster pictures are served — an SVG (or anything else) never renders on the Argos origin.
        abort_unless(in_array($disk->mimeType($user->avatar_path), User::AVATAR_TYPES, true), 404);

        // The URL carries a version (?v=…), so the picture may be cached for long.
        return $disk->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
