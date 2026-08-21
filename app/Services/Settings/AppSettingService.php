<?php

namespace App\Services\Settings;

use App\Models\AppSetting;

final class AppSettingService
{
    public function getString(string $key, ?string $default = null): ?string
    {
        $row = AppSetting::query()->find($key);

        if ($row === null) {
            return $default;
        }

        $value = $row->value['value'] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function setString(string $key, string $value): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['value' => $value]],
        );
    }
}
