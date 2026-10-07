<!-- Souverain.ovh -->

![Commentaires — ActivityPub / Mastodon + ATProto / Bluesky](assets/commentaires-banner.jpg)

[🇫🇷 Français](README.md) | 🇬🇧 English

# Souverain.ovh Commentaires — 0.2.1

A Mastodon and Bluesky / ATProto commenting module for articles on static or traditional websites.

The article remains on your website. Its discussions remain on social networks: the module finds posts containing the article's public URL, retrieves their replies, and presents them in a single thread. Visitors can read comments without an account. To reply, they use their Mastodon or ATProto account; their message is published on the selected network.

The module can be integrated into HTML pages, static websites, or CMS article templates, without depending on any particular publishing tool. It does not require a comments database; it uses PHP sessions and browser storage for authentication.

**Experimental version, distributed with a test page, a complete HTML example, and installation instructions.**

## Live example

**[See the module under the “Génération LaSuite” article](https://souverain.ovh/generation-lasuite/#discussion).** The link opens the “Discussion” section directly, below the article.

This example shows Mastodon and Bluesky / ATProto replies combined in a single display, with their original network indicated, followed by the two options for participating directly from the page. Reading is available without an account; publishing requires signing in to the selected network.

This public page demonstrates the module's rendering. For your own installation, enter your domain, accounts, and articles in `config.php`; the files provided use generic values by default.

## Getting started

1. Check that your public hosting provides HTTPS, PHP 8.1+, cURL, and PHP sessions.
2. Copy the `_commentaires` folder from the archive to the public root of your website, including its hidden files.
3. Enter your domain, accounts, and test article in `config.php`.
4. Publish the URL of that article from the configured accounts and open `/_commentaires/test.html`.
5. Integrate the complete block into your article template.

Follow [INSTALLATION.en.md](INSTALLATION.en.md) for the exact values and checks, then [INTEGRATION.en.md](INTEGRATION.en.md) to place the block in a page or article template. [integration/article.html](integration/article.html) provides a complete page that you can adapt.

## What must remain dynamic

Your article HTML can be static. The PHP files in the `_commentaires` folder must continue to be executed by the public hosting environment, on **the same HTTPS origin as the articles**: same protocol, hostname, and port.

Hosting limited to static files is not sufficient for the complete module. The archive does not include an adaptation for a service hosted on another domain. You may generate the pages using the tool of your choice; the module's PHP files must be executed on the public hosting environment.

## Configuration and files

| File | Purpose |
| --- | --- |
| `config.php` | Domain, accounts, and settings specific to your installation. |
| `config.example.php` | Generic configuration example. |
| `config-public.php` | Public JavaScript settings generated from `config.php`. |
| `oauth-client-metadata.php` | ATProto OAuth metadata generated for your domain and path. |
| `test.html` | Demonstration and ATProto OAuth return page; keep it online. |
| `commentaires.css` / `commentaires.js` | Presentation and behavior of the comments block. |
| `atproto-oauth.js` | Included ATProto bundle, usable without compilation. |
| `mastodon/` / `atproto/` | Public PHP endpoints for the service. |
| `.htaccess`, `lib.php`, `securite.php` | Apache configuration and shared functions. |
| `tests/` | Local command-line checks; HTTP access is denied by its `.htaccess`. |

The browser receives `window.FederatedCommentsConfig`, and the SDK exposes `window.FederatedCommentsATProto`. No personal OAuth secret should be placed in the HTML or in `config.php`.

## Presentation

The CSS rules are scoped to `.souverain-discussion` to avoid interfering with the page that hosts the module. The integrated block is left-aligned and can occupy the full width of the article. Reading text uses 22–24 px on desktop and 20 px on mobile; headings, buttons, and metadata retain a distinct hierarchy.

The theme variables `--font-text`, `--ink`, `--secondary`, `--rule`, `--sidebar`, and `--paper` are reused when available, with fallback values. See [INTEGRATION.en.md](INTEGRATION.en.md#customizing-the-presentation) to adapt them to another website.

## Limitations

- Each network requires a public root post, published by the configured account, containing the canonical URL of the article. The module does not create these posts.
- Articles must have URLs distinguished by their path, for example `/my-article/`. Permalinks that distinguish articles through a query such as `/?p=42` are not supported. Tracking parameters such as `utm_*`, `fbclid`, and `gclid` are ignored.
- The threads remain independent. Displaying them together does not turn a Mastodon reply into a Bluesky reply, or vice versa.
- The module searches account history on every load, without a local index or thread cache. The default limit is 1,000 original posts; older articles may not be found, and searches may be slow.
- Available data depends on APIs, rate limits, federation, and message visibility. The displayed thread is not guaranteed to be an exhaustive archive.
- Private accounts, servers accessible only on a local network, and integrations across multiple origins are not supported configurations.
- This distribution has undergone local syntax, presentation, and simulated-scenario checks, but not an end-to-end validation of a real OAuth sign-in and publication flow. It is not a complete audit and does not guarantee operation on every hosting environment.

## Development and rebuilding the bundle

This step is only for people who modify the SDK or want to rebuild the provided file. **Node.js and npm are not required on the PHP hosting environment.**

With Node.js 22 or newer and npm, open a terminal in the extracted `_commentaires` folder, then run:

```sh
npm ci
npm test
npm run build
```

`npm ci` installs the versions specified in `package-lock.json`. The direct dependencies are `@atproto/api` 0.22.0, `@atproto/oauth-client-browser` 0.5.8, and `esbuild` 0.28.2. The first installation requires access to the npm registry unless the packages are already available in the local cache.

`npm test` runs the simulated frontend scenarios with Node.js. For PHP checks, run these commands separately from the same folder, with PHP available on the command line:

```sh
php tests/backend.php
php -d disable_functions=mb_strlen,mb_substr tests/backend.php
```

The second command checks operation without the optional `mbstring` functions. The PHP tests use an isolated fixture configuration and do not replace your `config.php`. These tests are intended to be run from a terminal, not opened in a browser; their directory contains an `.htaccess` file that denies HTTP access.

The scenarios simulate external services. They do not publish comments and do not validate a real OAuth sign-in.

`scripts/build.mjs` rebuilds `atproto-oauth.js` from `src/atproto/entry.js` and `src/atproto/oauth.js`. It also regenerates:

- [THIRD-PARTY-NOTICES.txt](THIRD-PARTY-NOTICES.txt), containing the licenses of the libraries actually included in the bundle;
- [BUILD-INFO.json](BUILD-INFO.json), containing the version, size, SHA-256 fingerprint of the bundle, and the versions of its dependencies.

The bundle in this archive was rebuilt from the included source files and locked dependency versions. It is not presented as byte-for-byte identical to the bundle from the previous archive. Two consecutive local rebuilds produced the same fingerprint with these inputs. After any modification, verify the OAuth functions and retain the third-party notices when redistributing the project.

Do not copy `node_modules` to the hosting environment. For a normal installation, use the already-built files included in the package.

## License

The project's own code is released under the [0BSD license](LICENSE): use, copying, modification, and redistribution are permitted without prior authorization or mandatory attribution. It is provided without warranty. Third-party dependencies retain their own licenses; see [LICENSE-NOTICE.md](LICENSE-NOTICE.md) and [THIRD-PARTY-NOTICES.txt](THIRD-PARTY-NOTICES.txt).
