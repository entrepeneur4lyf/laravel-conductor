<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResumeWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resume_token' => ['required', 'string'],
            'payload' => ['sometimes', 'array'],
        ];
    }
}
