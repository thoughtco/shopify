# Documentation

These docs are published to [docs.tc/shopify](https://docs.tc/shopify). On each release, the `Docs` workflow asks docs.tc to sync this folder, and docs.tc opens a pull request with the changes.

## Layout

The folders mirror the docs.tc sidebar:

```
<n>.<group>/                  sidebar menu, e.g. 2.cms
    _group.yaml               title: CMS
                              icon: icon.svg (optional, sits beside it)
    <n>.<section>/            heading within the menu, e.g. 1.importing
        _section.yaml         title: Importing
        <n>.<page>.md         a page, e.g. 2.content.md
```

The numeric prefix sets the order and is dropped from the URL, so `2.cms/1.importing/2.content.md` is published at `/shopify/8x/cms/importing/content`. Pages must sit inside a section folder.

## Writing pages

Each page needs frontmatter with a `title`. An optional `description` is used for search.

```markdown
---
title: Content
description: How products, variants and collections map to Statamic entries
---
```

Then write standard GitHub-flavoured markdown:

- Use `##` for main headings (these appear in the "On this page" menu), and `###` / `####` below them. Don't use `#`, since the title comes from the frontmatter.
- Fence code blocks with a language: `php`, `bash`, `antlers`, `html`, `js`, `json` or `yaml`.
- Label consecutive code blocks to show alternatives, e.g. ` ```bash [Yarn] ` then ` ```bash [NPM] `.
- Use GitHub alerts for callouts: `> [!NOTE]` or `> [!WARNING]`.
- Link to other pages by their published path without the prefixes, e.g. `[webhooks](/cms/sync/webhooks#topics)`.

Raw HTML and images aren't supported yet. If anything can't be converted, the sync fails and lists every problem with its file and line.
