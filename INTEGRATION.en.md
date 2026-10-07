<!-- Souverain.ovh -->

[🇫🇷 Français](INTEGRATION.md) | 🇬🇧 English

# Integrate the discussion below an article

First install and verify the service with [INSTALLATION.en.md](INSTALLATION.en.md). The examples assume `site_url = https://example.com` and `base_path = /_commentaires`.

The block can be integrated into an HTML page, whether it is written by hand, produced by a CMS, or generated as static files. Its PHP service must remain executable on a public HTTPS server, on the **same origin as the articles**: same protocol, same hostname, and same port. A separate subdomain is not suitable for this integration.

You can see the module under a published article: [Génération LaSuite — live example](https://souverain.ovh/generation-lasuite/#discussion). Use the generic files in this archive for your own installation and enter your own configuration.

## HTML, CSS, and JavaScript integration

Open [integration/article.html](integration/article.html). This page contains an article, the complete discussion block, and the resources in their loading order. It is intended as a template to integrate into your own layout; it contains no preconfigured personal data.

The `integration/article.css` stylesheet formats this example page only. On your website, keep your theme styles for the article and use `commentaires.css` for the discussion block.

1. Copy the resource links into your page, replacing the origin and path with those from `config.php`:

   ```html
   <link rel="stylesheet" href="https://example.com/_commentaires/commentaires.css?v=0.2.1">
   <script src="https://example.com/_commentaires/config-public.php" defer></script>
   <script src="https://example.com/_commentaires/atproto-oauth.js?v=0.2.1" defer></script>
   <script src="https://example.com/_commentaires/commentaires.js?v=0.2.1" defer></script>
   ```

2. Copy **the entire `id="discussion"` section** from the example, including its subsections, fields, and buttons, below your article. Keep the internal identifiers unchanged. An empty container is not sufficient: the JavaScript expects the full DOM structure.
3. Have your template output the public canonical URL of each article in this attribute:

   ```html
   <section id="discussion" class="souverain-discussion"
            aria-labelledby="discussion-title"
            data-article-url="https://example.com/my-article/">
     <!-- All elements from the section provided in integration/article.html. -->
   </section>
   ```

   This fragment shows only the container; use the full content from the example.

4. Keep **only one discussion per page**. Do not copy a second `<html>`, `<head>`, `<body>`, or `<main>` into your page. The provided block uses h2, h3, and h4 headings below your article's h1.
5. Keep the order of the three scripts with `defer`. Do not add `async`. If your tool optimizes or bundles scripts, make sure it preserves this order and the location of the OAuth bundle.
6. Publish a test page, then verify reading, sign-in, return to the article, and publishing from the public origin.

`config-public.php` is required: it provides the public configuration to the JavaScript. Keep this file, the OAuth bundle, and `commentaires.js` from the same package, together with the PHP endpoints installed at the location defined by `base_path`.

`data-article-url` must use the same HTTPS origin as `site_url`, with the actual public path of the article. Do not use `localhost`, the module URL, or a URL with tracking parameters. A `<link rel="canonical">` tag does not replace this attribute.

Each article must have a distinct path, for example `/my-article/`. URLs whose identity depends on a query string, such as `/?p=42`, are rejected: they are not silently converted to `/`. Tracking parameters such as `utm_*`, `fbclid`, and `gclid` are ignored.

Absolute URLs are especially useful when the HTML is generated on a local machine: they must point to the public service and remain correct in the exported result.

## CMS or generator template

1. Create a component or template fragment containing **the complete section** from [integration/article.html](integration/article.html), then include it after the content of each relevant public article.
2. Replace the fixed value of `data-article-url` with the public canonical URL provided by your CMS or generator. Use its escaping mechanism for HTML attributes. The variable syntax depends on your tool; the rendered value must be a full URL such as `https://example.com/my-article/`.
3. If the site is built locally or on a preview address, configure the template with the origin and path that will actually be published. For example, a local source such as `http://localhost:8080/preview/blog/article/` may generate `https://example.com/blog/article/` if `/preview` does not exist on the public website. Check this mapping in the final HTML and keep a distinct path for each article.
4. Load the four resources shown above only once on pages that contain the block. If the CMS has a resource-management API, express the dependencies in this order: public configuration, OAuth bundle, discussion script. The result must preserve this loading order and must not use `async`.
5. Load `commentaires.css` after the website stylesheet that defines the module's CSS variables. If you add a customization stylesheet, load it afterward.

The module does not automatically add the block to the website: the template produces its HTML. It does not depend on the CMS's internal commenting system. For a private or protected page, do not export the block and its data into a public page.

## Static generation and deployment

Two separate operations are required:

1. **Exclude the service from the export.** The generator must not convert the PHP endpoints in `/_commentaires/` into static files or record session responses there. It must preserve the links in the articles to the public service. Configure exclusions and URL preservation according to your tool version, then inspect an exported page.
2. **Preserve the folder on the server.** Excluding the folder from generation does not automatically protect the public folder during FTP/SFTP synchronization or a deployment that deletes files missing from the export. Configure deployment so that it never deletes or overwrites `/_commentaires/` with the static export.

Install the public PHP service first. Then export the articles, check their `data-article-url` and the four resource URLs, and deploy the HTML while preserving the module folder.

A local preview helps verify the HTML and presentation. The session and OAuth flow must be tested on the public HTTPS domain because the local origin does not share the service's cookies and protections. Hosting that only serves static files is not sufficient to run the PHP endpoints: keep an executable PHP service under the same public origin as the articles.

## OAuth returns

Keep the module endpoints and `test.html` at their configured location.

- Mastodon returns to `mastodon/callback.php`. The server validates the return before redirecting the visitor to the remembered and authorized article. Without a remembered destination, it returns to `test.html`.
- ATProto uses the metadata from `oauth-client-metadata.php`, which declares `test.html` as the return page. The SDK processes the OAuth fragment on this page; after a successful return, the JavaScript resumes the remembered article in the same tab.
- The ATProto return memory is temporary, for a maximum of 30 minutes. An ordinary visit to `test.html` remains a demonstration and must not redirect the reader to an old page.
- `demo_article_url` may be empty without preventing OAuth return processing. If no valid article return is remembered, the page still processes the sign-in and invites the visitor to return to their article, without loading a demonstration thread.

Do not replace the return page with each article URL in the metadata. You do not need to rebuild the bundle to customize the domain and path: use `config.php` and the supplied resources together.

## Customizing the presentation

The module uses `.souverain-discussion` as its root. All of its rules should remain scoped to this block; global `body` or `.post` rules could otherwise alter your website theme.

The supplied version follows the integrated module's choices: serif body text, reading sizes of 22–24 px on desktop and 20 px up to 740 px, and an integrated block aligned to the left across the available width. The standalone demonstration keeps a limited reading width.

The following variables allow the theme to pass its own choices to the module, with fallback values defined by the module:

```css
/* Example to adapt in your website stylesheet. */
:root {
  --font-text: Georgia, serif;
  --ink: #2c2c2b;
  --secondary: #595752;
  --rule: #d8d0c5;
  --sidebar: #eef1f4;
  --paper: #fffdf9;
}
```

The module does not add font files: a custom font must be installed or loaded by your website. For other sizes, add a customization stylesheet loaded after `commentaires.css` rather than scattering overrides across several files. Keep headings visually distinct from body text and metadata more discreet; also check mobile rendering and forms.

Changing only the public CSS does not require a new theme or a new export if the pages already point to this file. Purge the cache for that same URL. To change the version number in resource links, republish the pages that contain those links.
