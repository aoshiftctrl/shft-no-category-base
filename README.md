# CatRmove

> Lightweight WordPress plugin that removes the category base from category URLs (`/category/news/` → `/news/`) and redirects old URLs with a 301.

## Why?

WordPress puts `/category/` in front of every category URL. That makes URLs longer and less readable. This plugin removes the base without a catch-all rule, so pages, posts and all other URLs stay untouched.

## Features

- Category links without the base
- One explicit rewrite rule per category path (including feed, embed and pagination)
- 301 redirect from old URLs, including feeds, pagination and query strings
- With `/%category%/%postname%/` permalinks: old post URLs are redirected to the canonical permalink
- If the URL without the base already belongs to a published page, a post or a WordPress path, the existing content wins and the category keeps its base
- Rewrite rules are rebuilt automatically on category changes
- No settings

## Installation

1. Download the latest release ZIP
2. Upload via *Plugins → Add New → Upload Plugin*
3. Activate

## Requirements

- WordPress 6.2+
- PHP 8.0+

## Changelog

See [readme.txt](readme.txt).

## License

CC BY-ND 4.0 See https://creativecommons.org/licenses/by-nd/4.0/.
