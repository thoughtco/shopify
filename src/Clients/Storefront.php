<?php

namespace Thoughtco\Shopify\Clients;

use Illuminate\Support\Facades\Http;
use Thoughtco\Shopify\Exceptions\GraphqlException;

/**
 * A minimal server-side Storefront API client, for data only the Storefront API has
 * (e.g. selling plan prices in a market's currency). Uses the private token when one
 * is configured, otherwise the public storefront token.
 */
class Storefront
{
    protected string $apiVersion;

    public function __construct(
        protected string $domain,
        protected ?string $publicToken = null,
        protected ?string $privateToken = null,
        ?string $apiVersion = null,
    ) {
        $this->apiVersion = $apiVersion ?? (config('shopify.api_version') ?: Graphql::DEFAULT_API_VERSION);
    }

    /**
     * The client for the configured (single) store, or null without a URL or token.
     */
    public static function fromConfig(): ?static
    {
        $domain = config('shopify.url');
        $publicToken = config('shopify.storefront_token');
        $privateToken = config('shopify.storefront_private_token');

        if (! $domain || (! $publicToken && ! $privateToken)) {
            return null;
        }

        return new static($domain, $publicToken, $privateToken);
    }

    public function url(): string
    {
        return 'https://'.Graphql::shop($this->domain).'.myshopify.com/api/'.$this->apiVersion.'/graphql.json';
    }

    /**
     * Run a query and return its `data`.
     *
     * @throws GraphqlException when the request fails or returns errors without data
     */
    public function query(string $query, array $variables = []): array
    {
        $headers = $this->privateToken
            ? ['Shopify-Storefront-Private-Token' => $this->privateToken]
            : ['X-Shopify-Storefront-Access-Token' => $this->publicToken];

        $response = Http::withHeaders($headers)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout((int) config('shopify.api_timeout', 30))
            ->post($this->url(), array_filter([
                'query' => $query,
                'variables' => $variables ?: null,
            ]));

        if ($response->failed()) {
            throw new GraphqlException("Storefront API request failed with status {$response->status()}");
        }

        $body = $response->json() ?? [];

        if (empty($body['data'])) {
            throw new GraphqlException($body['errors'][0]['message'] ?? 'Storefront API returned no data');
        }

        return $body['data'];
    }
}
