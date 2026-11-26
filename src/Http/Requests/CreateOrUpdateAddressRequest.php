<?php

namespace Thoughtco\Shopify\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Traits\Localizable;
use Statamic\Facades\Site;

class CreateOrUpdateAddressRequest extends FormRequest
{
    use Localizable;

    /**
     * Shopify's MailingAddressInput takes ISO codes (countryCode, provinceCode), not
     * names. Older forms that send codes as `country` / `province` still work.
     */
    public function rules(): array
    {
        return [
            'firstName' => ['required', 'string'],
            'lastName' => ['required', 'string'],
            'company' => ['nullable', 'string'],
            'address1' => ['required', 'string'],
            'address2' => ['nullable', 'string'],
            'city' => ['required', 'string'],
            'zip' => ['required', 'string'],
            'phone' => ['nullable', 'string'],
            'name' => ['nullable', 'string'],
            'countryCode' => ['required', 'string', 'size:2'],
            'provinceCode' => ['nullable', 'string', 'max:6'],
            'default' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $codes = [];

        foreach (['countryCode' => 'country', 'provinceCode' => 'province'] as $code => $legacy) {
            if (is_string($value = $this->input($code)) && trim($value) !== '') {
                $codes[$code] = strtoupper(trim($value));
            } elseif (is_string($value = $this->input($legacy)) && preg_match('/^[A-Z0-9-]{2,6}$/', trim($value))) {
                // only codes: a name such as "Kent" or "Ireland" can't be sent to Shopify
                $codes[$code] = trim($value);
            }
        }

        $this->merge($codes);
    }

    public function attributes(): array
    {
        return [
            'countryCode' => 'country',
            'provinceCode' => 'province',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function validateResolved()
    {
        $site = Site::findByUrl(URL::previous()) ?? Site::default();

        return $this->withLocale($site->lang(), fn () => parent::validateResolved());
    }
}
