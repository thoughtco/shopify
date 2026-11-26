<?php

namespace Thoughtco\Shopify\Http\Controllers\Actions;

use Illuminate\Http\Request;
use Statamic\Facades\User;
use Statamic\Http\Controllers\Controller;
use Statamic\Support\Arr;
use Thoughtco\Shopify\Support\Customers;
use Thoughtco\Shopify\Support\StoreConfig;

class BaseActionController extends Controller
{
    /**
     * The logged in user's customer ID, in the store the form was for (`_store`) on multi-store sites.
     */
    protected function customerId(Request $request): ?string
    {
        return ($user = User::current()) ? Customers::id($user, $this->customerStore($request)) : null;
    }

    protected function customerStore(Request $request): ?string
    {
        return Customers::store($request->input('_store'));
    }

    /**
     * Run a mutation and return its payload key, throwing on any GraphQL or user error.
     */
    protected function mutate(Request $request, string $operation, string $query, array $variables, string $resultKey): mixed
    {
        $response = StoreConfig::clientFor($this->customerStore($request))->query([
            'query' => $query,
            'variables' => $variables,
        ]);

        if ($message = $response->firstError($operation)) {
            throw new \Exception($message);
        }

        if (! $result = Arr::get($response->getDecodedBody(), "data.{$operation}.{$resultKey}")) {
            throw new \Exception(__('Shopify did not return a result'));
        }

        return $result;
    }

    protected function withSuccess(Request $request, array $data = [])
    {
        if ($request->wantsJson()) {
            $data = array_merge($data, [
                'status' => 'success',
                'message' => null,
            ]);

            return response()->json($data);
        }

        return ($redirect = $this->safeRedirect($request->_redirect))
            ? redirect($redirect)->with($data)
            : back()->with($data);
    }

    protected function withErrors(Request $request, string $errorMessage)
    {
        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'error',
                'message' => $errorMessage,
            ], 422);
        }

        return ($redirect = $this->safeRedirect($request->_error_redirect))
            ? redirect($redirect)->withErrors($errorMessage)
            : back()->withErrors($errorMessage);
    }

    /**
     * Only allow redirects that stay on this site, so a user-supplied
     * _redirect / _error_redirect field cannot be used as an open redirect.
     */
    private function safeRedirect($redirect): ?string
    {
        if (! is_string($redirect) || $redirect === '') {
            return null;
        }

        // Normalise backslashes some browsers treat as slashes.
        $normalised = str_replace('\\', '/', $redirect);

        // Relative path on this site (but not protocol-relative //evil.com).
        if (str_starts_with($normalised, '/') && ! str_starts_with($normalised, '//')) {
            return $redirect;
        }

        // Absolute URL: only if it points back at the current host.
        if (parse_url($normalised, PHP_URL_HOST) === request()->getHost()) {
            return $redirect;
        }

        return null;
    }
}
