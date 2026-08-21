<?php

namespace App\Services\Billing;

use App\Services\Settings\AppSettingService;
use App\Support\Billing\BillingPdfTemplate;
use Illuminate\Validation\ValidationException;

final class BillingDocumentPdfService
{
    public function __construct(
        private readonly AppSettingService $appSettings,
    ) {}

    /**
     * @return array<int, array{code: string, name: string, selected: bool}>
     */
    public function catalog(): array
    {
        return BillingPdfTemplate::catalog($this->selectedTemplateCode());
    }

    public function updateSelectedTemplate(string $code): array
    {
        $normalized = BillingPdfTemplate::normalize($code);
        if (! BillingPdfTemplate::isValid($normalized)) {
            throw ValidationException::withMessages([
                'template' => ['La plantilla seleccionada no es válida.'],
            ]);
        }

        $this->appSettings->setString(BillingPdfTemplate::SETTING_KEY, $normalized);

        return BillingPdfTemplate::catalog($normalized);
    }

    public function selectedTemplateCode(): string
    {
        $stored = $this->appSettings->getString(
            BillingPdfTemplate::SETTING_KEY,
            BillingPdfTemplate::defaultCode(),
        );

        if ($stored !== null && BillingPdfTemplate::isValid($stored)) {
            return BillingPdfTemplate::normalize($stored);
        }

        return BillingPdfTemplate::defaultCode();
    }
}
