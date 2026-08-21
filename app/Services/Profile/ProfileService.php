<?php

namespace App\Services\Profile;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileService
{
    private const AVATAR_DIRECTORY = 'avatars';

    private const MAX_AVATAR_KB = 2048;

    /** @var array<int, string> */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function changePassword(User $user, array $data): void
    {
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual no es correcta.'],
            ]);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['La nueva contraseña debe ser diferente a la actual.'],
            ]);
        }

        $user->password = $data['password'];
        $user->save();
    }

    public function updateAvatar(User $user, UploadedFile $file): User
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            throw ValidationException::withMessages([
                'avatar' => ['El archivo debe ser una imagen JPG, PNG o WEBP.'],
            ]);
        }

        if ($file->getSize() > self::MAX_AVATAR_KB * 1024) {
            throw ValidationException::withMessages([
                'avatar' => ['La imagen no puede superar los 2 MB.'],
            ]);
        }

        $previous = $user->avatar_path;

        $path = $file->store(self::AVATAR_DIRECTORY, 'public');

        $user->avatar_path = $path;
        $user->save();

        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return $user->fresh();
    }

    public function deleteAvatar(User $user): User
    {
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->avatar_path = null;
            $user->save();
        }

        return $user->fresh();
    }
}
