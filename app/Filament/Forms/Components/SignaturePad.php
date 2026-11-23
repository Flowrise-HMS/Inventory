<?php

namespace Modules\Inventory\Filament\Forms\Components;

use Filament\Forms\Components\Field;

/**
 * A drawn signature (finger, stylus or mouse). Its state is a PNG data URI,
 * or null when the pad is empty.
 */
class SignaturePad extends Field
{
    protected string $view = 'inventory::filament.forms.components.signature-pad';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules([
            'nullable',
            'string',
            'starts_with:data:image/png;base64,',
            // ~512 KB of PNG once base64-encoded.
            'max:700000',
        ]);

        $this->validationMessages([
            'starts_with' => __('The signature could not be read. Clear the pad and sign again.'),
            'max' => __('The signature image is too large. Clear the pad and sign again.'),
        ]);
    }
}
