<?php

namespace Thoughtco\Shopify\Actions;

use Statamic\Support\Arr;

class ParseMetafields
{
    /**
     * Parse any metafields
     *
     * @return array
     */
    public function execute(array $metafields, string $context)
    {
        return collect($metafields)
            ->mapWithKeys(fn ($field) => [$field['key'] => $this->value($field)])
            ->all();
    }

    /**
     * A metafield's raw value, or for a resolved metaobject reference the metaobject's
     * fields (a list of them for list.metaobject_reference).
     */
    protected function value(array $field): mixed
    {
        if ($this->isMetaobject($field['reference'] ?? null)) {
            return $this->metaobject($field['reference']);
        }

        if ($nodes = $this->metaobjectNodes($field)) {
            return array_map(fn ($node) => $this->metaobject($node), $nodes);
        }

        return $field['value'];
    }

    /**
     * A metaobject as an array of its field values keyed by field key, plus its
     * handle and type (the type doubles as a replicator set handle).
     */
    public function metaobject(array $metaobject): array
    {
        $fields = collect($metaobject['fields'] ?? [])
            ->mapWithKeys(fn ($field) => [$field['key'] => $this->metaobjectFieldValue($field)])
            ->all();

        return array_merge($fields, [
            'handle' => $metaobject['handle'] ?? null,
            'type' => $metaobject['type'] ?? null,
        ]);
    }

    /**
     * A metaobject field's value: nested metaobjects become arrays, media their URL,
     * lists arrays; anything else is the raw value, as for metafields.
     */
    protected function metaobjectFieldValue(array $field): mixed
    {
        if (is_array($reference = $field['reference'] ?? null) && $reference) {
            return $this->referenceValue($reference) ?? $field['value'] ?? null;
        }

        if (is_array($nodes = Arr::get($field, 'references.nodes')) && $nodes) {
            return array_values(array_filter(array_map(fn ($node) => is_array($node) ? $this->referenceValue($node) : null, $nodes), fn ($value) => $value !== null));
        }

        // lists are JSON encoded; an array suits Statamic's list fields
        if (str_starts_with($field['type'] ?? '', 'list.') && is_array($list = json_decode($field['value'] ?? '', true))) {
            return $list;
        }

        return $field['value'] ?? null;
    }

    protected function referenceValue(array $reference): mixed
    {
        if ($this->isMetaobject($reference)) {
            return $this->metaobject($reference);
        }

        return Arr::get($reference, 'image.url') ?? $reference['url'] ?? null;
    }

    private function metaobjectNodes(array $field): array
    {
        $nodes = Arr::get($field, 'references.nodes');

        if (! is_array($nodes) || ! $nodes) {
            return [];
        }

        $metaobjects = array_values(array_filter($nodes, fn ($node) => $this->isMetaobject($node)));

        // only a list made entirely of metaobjects is converted
        return count($metaobjects) === count($nodes) ? $metaobjects : [];
    }

    private function isMetaobject(mixed $node): bool
    {
        return is_array($node) && isset($node['fields']) && is_array($node['fields']);
    }
}
