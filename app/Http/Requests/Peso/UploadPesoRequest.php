<?php

declare(strict_types=1);

namespace App\Http\Requests\Peso;

use Illuminate\Foundation\Http\FormRequest;

final class UploadPesoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            're12_pdf' => [
                'required',
                'file',
                'mimes:pdf',
                'max:10240',
            ],
        ];
    }
}
