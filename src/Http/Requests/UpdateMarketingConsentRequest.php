<?php

namespace Thoughtco\Shopify\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Traits\Localizable;
use Statamic\Facades\Site;

class UpdateMarketingConsentRequest extends FormRequest
{
    use Localizable;

    /**
     * Only the channels a form sends are changed. A checkbox isn't sent when it's
     * unticked, so forms put a hidden `email` / `sms` of 0 before it.
     */
    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'boolean'],
            'sms' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'email' => 'email marketing',
            'sms' => 'SMS marketing',
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
