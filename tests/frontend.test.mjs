/* Souverain.ovh */
import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import { webcrypto } from "node:crypto";

const moduleDirectory = new URL("../", import.meta.url);
const source = fs.readFileSync(new URL("commentaires.js", moduleDirectory), "utf8");
const stylesheet = fs.readFileSync(new URL("commentaires.css", moduleDirectory), "utf8");
const html = fs.readFileSync(new URL("test.html", moduleDirectory), "utf8");
const returnKey = "federated-comments:https://journal.example.net/outils/discussions:atproto-return";
const ownerDID = "did:plc:author";
const article = "https://journal.example.net/article/";
const defaultConfig = {
    siteUrl: "https://journal.example.net",
    basePath: "/outils/discussions",
    baseUrl: "https://journal.example.net/outils/discussions",
    demoArticleUrl: "https://journal.example.net/demo-article/",
    maxPostsToSearch: 1000,
    bluesky: { handle: "auteur.example.net", api: "https://appview.example.net", pageSize: 50 }
};
const encodeHTML = value => String(value).replace(/&/g, "&amp;").replace(/</g, "&lt;")
    .replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#39;");

class Element {
    constructor(id = "") {
        this.id = id;
        this.dataset = {};
        this.hidden = false;
        this.value = "";
        this.listeners = new Map();
        this.children = [];
        this.classList = { add() {}, remove() {} };
        this._text = "";
        this._html = "";
        this.parentNode = null;
        this.tagName = "DIV";
    }
    set textContent(value) { this._text = String(value); this._html = encodeHTML(value); }
    get textContent() { return this._text; }
    set innerHTML(value) { this._html = String(value); }
    get innerHTML() { return this._html; }
    addEventListener(name, callback) { this.listeners.set(name, callback); }
    replaceChildren(...children) { this.children = children; this._html = ""; }
    appendChild(child) {
        if (child.parentNode) child.parentNode.children.splice(child.parentNode.children.indexOf(child), 1);
        child.parentNode = this;
        this.children.push(child);
    }
    insertBefore(child, reference) {
        if (child.parentNode) child.parentNode.children.splice(child.parentNode.children.indexOf(child), 1);
        child.parentNode = this;
        this.children.splice(this.children.indexOf(reference), 0, child);
    }
    get nextSibling() {
        return this.parentNode?.children[this.parentNode.children.indexOf(this) + 1] ?? null;
    }
    querySelector(selector) {
        for (const child of this.children) {
            if ((selector === ".signup-hint" && child.className === "signup-hint") ||
                (selector === "strong" && child.tagName === "STRONG")) return child;
            const nested = child.querySelector(selector);
            if (nested) return nested;
        }
        return null;
    }
}

function fixture(options = {}) {
    const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map(match => match[1]);
    const elements = new Map(ids.map(id => [id, new Element(id)]));
    const root = elements.get("discussion");
    if (options.article !== null) root.dataset.articleUrl = options.article ?? article;
    const description = new Element();
    const joinIntro = new Element();
    const intro = new Element();
    intro.textContent = "Publiez votre réponse depuis cette page.";
    const signup = new Element();
    signup.tagName = "STRONG";
    signup.textContent = "Pas encore de compte ?";
    const signupLink = new Element();
    signupLink.tagName = "A";
    signupLink.href = "https://mastodon.social/auth/sign_up";
    const tail = new Element();
    tail.textContent = " pour participer à la conversation.";
    joinIntro.appendChild(intro);
    if (options.legacyMarkup) {
        joinIntro.appendChild(signup);
        joinIntro.appendChild(signupLink);
        joinIntro.appendChild(tail);
    } else {
        const existingHint = new Element();
        existingHint.className = "signup-hint";
        existingHint.appendChild(signup);
        existingHint.appendChild(signupLink);
        existingHint.appendChild(tail);
        joinIntro.appendChild(existingHint);
    }
    root.querySelector = selector => selector === ".join-intro" ? joinIntro
        : selector === "#atproto-panel .protocol-description" ? description
        : elements.get(selector.slice(1)) ?? null;
    const url = new URL(options.url ?? article);
    const replacements = [];
    const requests = [];
    const storage = new Map(Object.entries(options.storage ?? {}));
    let loginCalls = 0;
    let initCalls = 0;
    const location = {
        href: url.href, origin: url.origin, pathname: url.pathname,
        search: url.search, hash: url.hash,
        replace(path) { replacements.push(path); }
    };
    const response = (data, status = 200) => ({
        ok: status >= 200 && status < 300, status,
        headers: { get() { return "application/json"; } },
        async json() { return data; }
    });
    const context = {
        URL, URLSearchParams, Date, AbortSignal,
        setTimeout(callback) { callback(); },
        console: { error() {}, warn() {} },
        history: { replaceState() {} },
        document: {
            querySelector() { return options.noWidget ? null : root; },
            createElement() { return new Element(); }
        },
        window: {
            FederatedCommentsConfig: options.noConfig ? undefined : { ...defaultConfig, ...options.config },
            location,
            sessionStorage: {
                getItem(key) { if (options.blockStorage) throw new Error("denied"); return storage.get(key) ?? null; },
                setItem(key, value) { if (options.blockStorage) throw new Error("denied"); storage.set(key, value); },
                removeItem(key) { if (options.blockStorage) throw new Error("denied"); storage.delete(key); }
            },
            FederatedCommentsATProto: {
                async init() {
                    initCalls++;
                    if (options.initError) throw new Error("bad OAuth callback");
                    location.search = "";
                    return { connected: !!options.connected, did: ownerDID };
                },
                async profile() { return { handle: "auteur.example.net", displayName: "Auteur", avatar: "" }; },
                async login() { loginCalls++; if (options.loginError) throw new Error("login failed"); },
                async logout() {},
                async publish() { throw new Error("No publishing in tests"); }
            }
        },
        async fetch(target, request = {}) {
            requests.push({ target: String(target), request });
            if (options.mastodonError && String(target).includes("thread.php")) {
                return response({ ok: false, error: "Mastodon down" }, 503);
            }
            if (String(target).includes("thread.php")) return response({ ok: true, found: false });
            if (String(target).includes("me.php")) return response({ ok: true, connected: false });
            if (String(target).includes("register.php")) return response({ ok: true, authorize_url: "https://mastodon.social/oauth/authorize" });
            if (String(target).includes("reply.php")) return response({ ok: true });
            if (String(target).includes("logout.php")) return response({ ok: true });
            if (String(target).includes("getProfile")) return response({ did: ownerDID });
            if (String(target).includes("getAuthorFeed")) return response({ feed: options.feed ?? [] });
            if (String(target).includes("getPostThread")) return response({ thread: { replies: [] } });
            throw new Error(`Unexpected URL in offline test: ${target}`);
        }
    };
    vm.createContext(context);
    const instrumented = source.replace(/\}\)\(\);\s*$/, `
        globalThis.hooks = { CONFIG, state, publicArticleURL, validReturnPath, commentsEndpoint, normaliseURL,
            blueskyContainsArticle, isBlueskyArticleRoot, renderUnifiedComments };
        })();`);
    vm.runInContext(instrumented, context);
    return {
        context, elements, root, requests, storage, replacements, joinIntro, intro, signup, signupLink, tail, description,
        get loginCalls() { return loginCalls; },
        get initCalls() { return initCalls; },
        hooks: context.hooks,
        async settled() { for (let i = 0; i < 5; i++) await new Promise(setImmediate); },
        async click(id) { await elements.get(id).listeners.get("click")(); }
    };
}

function post(url = article, extra = {}) {
    return { uri: "at://did:plc:author/app.bsky.feed.post/root", cid: "root-cid",
        author: { did: ownerDID }, record: { text: url }, ...extra };
}

test("a page without the widget makes no request and creates no globals", async () => {
    const page = fixture({ noWidget: true });
    await page.settled();
    assert.equal(page.requests.length, 0);
    assert.equal(page.context.CONFIG, undefined);
    assert.equal(page.context.state, undefined);
});

test("the public data attribute selects the article and URL query parameters cannot replace it", async () => {
    const page = fixture({ url: `${article}?article=https://evil.example/hijack` });
    await page.settled();
    assert.equal(page.hooks.CONFIG.articleURL, article);
    const request = page.requests.find(item => item.target.includes("thread.php"));
    assert.equal(JSON.parse(request.request.body).article_url, article);
});

test("test.html keeps the production demo article; invalid production data stops loading", async () => {
    const demo = fixture({ url: "https://journal.example.net/outils/discussions/test.html?article=https://evil.example/", article: null });
    assert.equal(demo.hooks.CONFIG.articleURL, "https://journal.example.net/demo-article/");
    const page = fixture({ article: "https://evil.example/article/" });
    await page.settled();
    assert.equal(page.requests.length, 0);
    assert.match(page.root.children[0].textContent, /URL publique/);
});

test("Bluesky compares complete URLs, including facets and embeds", () => {
    const { hooks } = fixture();
    assert.equal(hooks.blueskyContainsArticle(post(article)), true);
    assert.equal(hooks.blueskyContainsArticle(post("https://journal.example.net/article-bis/")), false);
    assert.equal(hooks.blueskyContainsArticle(post("https://evil.example/?next=" + article)), false);
    assert.equal(hooks.blueskyContainsArticle(post(`Voir (${article}).`)), true);
    assert.equal(hooks.blueskyContainsArticle(post("", { record: { facets: [{ features: [{ uri: article + "?utm_source=bsky" }] }] } })), true);
    assert.equal(hooks.blueskyContainsArticle(post("", { embed: { external: { uri: article } } })), true);
});

test("a root must be an original post from the resolved author", () => {
    const { hooks } = fixture();
    assert.equal(hooks.isBlueskyArticleRoot({ post: post() }, ownerDID), true);
    assert.equal(hooks.isBlueskyArticleRoot({ post: post(), reason: { $type: "app.bsky.feed.defs#reasonRepost" } }, ownerDID), false);
    assert.equal(hooks.isBlueskyArticleRoot({ post: post(article, { record: { text: article, reply: {} } }) }, ownerDID), false);
    assert.equal(hooks.isBlueskyArticleRoot({ post: post(article, { author: { did: "did:plc:other" } }) }, ownerDID), false);
});

test("the complete feed loader skips wrong roots before loading the correct thread", async () => {
    const page = fixture({ feed: [
        { post: post(article), reason: { $type: "app.bsky.feed.defs#reasonRepost" } },
        { post: post(article, { author: { did: "did:plc:other" } }) },
        { post: post("https://journal.example.net/article-bis/") },
        { post: post(article, { uri: "at://did:plc:author/app.bsky.feed.post/correct" }) }
    ] });
    await page.settled();
    assert.match(page.hooks.state.blueskyRoot.uri, /correct$/);
    const thread = page.requests.find(item => item.target.includes("getPostThread"));
    assert.match(decodeURIComponent(thread.target), /correct/);
});

test("partial network failure stays visible beside the available comments", async () => {
    const page = fixture({ mastodonError: true });
    await page.settled();
    assert.match(page.elements.get("comments-feed").innerHTML, /Mastodon sont indisponibles/);
    assert.match(page.elements.get("comments-feed").innerHTML, /réseau chargé/);
    page.hooks.state.blueskyComments = [{ network: "atproto", level: 1, createdAt: "2026-01-01", payload: { post: post("Commentaire", { author: { handle: "alice.bsky.social" }, record: { text: "Commentaire", createdAt: "2026-01-01" } }) } }];
    page.hooks.renderUnifiedComments();
    assert.match(page.elements.get("comments-feed").innerHTML, /Mastodon sont indisponibles/);
    assert.match(page.elements.get("comments-feed").innerHTML, /Commentaire/);
});

test("Mastodon login and reply carry the article and relative return target", async () => {
    const page = fixture();
    await page.settled();
    page.elements.get("mastodon-account").value = "@alice@mastodon.social";
    await page.click("mastodon-login-button");
    const registration = page.requests.find(item => item.target.includes("register.php"));
    assert.deepEqual(JSON.parse(registration.request.body), { instance: "mastodon.social", return_to: "/article/#discussion" });
    page.hooks.state.mastodonRoot = { url: "https://mastodon.social/@auteur/123" };
    page.elements.get("mastodon-reply").value = "Merci";
    await page.click("mastodon-publish");
    const reply = page.requests.find(item => item.target.includes("reply.php"));
    assert.equal(JSON.parse(reply.request.body).article_url, article);
});

test("ATProto login records the return before leaving and failed login clears it", async () => {
    const page = fixture();
    await page.settled();
    page.elements.get("atproto-handle").value = "alice.bsky.social";
    await page.click("atproto-login-button");
    assert.equal(page.loginCalls, 1);
    assert.equal(JSON.parse(page.storage.get(returnKey)).path, "/article/#discussion");
    const failed = fixture({ loginError: true });
    await failed.settled();
    failed.elements.get("atproto-handle").value = "alice.bsky.social";
    await failed.click("atproto-login-button");
    assert.equal(failed.storage.has(returnKey), false);
});

test("only a successful OAuth callback consumes a fresh return and redirects once", async () => {
    const saved = JSON.stringify({ path: "/article/#discussion", createdAt: Date.now() });
    const callback = fixture({ url: "https://journal.example.net/outils/discussions/test.html#code=callback&state=csrf", article: null, connected: true, storage: { [returnKey]: saved } });
    await callback.settled();
    assert.deepEqual(callback.replacements, ["/article/#discussion"]);
    assert.equal(callback.storage.has(returnKey), false);
    const normal = fixture({ url: "https://journal.example.net/outils/discussions/test.html", article: null, connected: true, storage: { [returnKey]: saved } });
    await normal.settled();
    assert.deepEqual(normal.replacements, []);
    // Le bundle fixe lit seulement le fragment : une query ne prouve pas un callback traité.
    const queryOnly = fixture({ url: "https://journal.example.net/outils/discussions/test.html?code=callback&state=csrf", article: null, connected: true, storage: { [returnKey]: saved } });
    await queryOnly.settled();
    assert.deepEqual(queryOnly.replacements, []);
    const expired = fixture({ url: "https://journal.example.net/outils/discussions/test.html#code=callback&state=csrf", article: null, connected: true, storage: { [returnKey]: JSON.stringify({ path: "/article/#discussion", createdAt: Date.now() - 3600000 }) } });
    await expired.settled();
    assert.deepEqual(expired.replacements, []);
    assert.equal(expired.storage.has(returnKey), false);
});

test("failed or disconnected OAuth callbacks do not redirect and clear stale state", async () => {
    for (const options of [{ initError: true }, { connected: false }, { initError: true, url: "https://journal.example.net/outils/discussions/test.html#error=access_denied&state=csrf" }]) {
        const page = fixture({ url: "https://journal.example.net/outils/discussions/test.html#code=callback&state=csrf", article: null, storage: { [returnKey]: JSON.stringify({ path: "/article/#discussion", createdAt: Date.now() }) }, ...options });
        await page.settled();
        assert.deepEqual(page.replacements, []);
        assert.equal(page.storage.has(returnKey), false);
    }
});

test("return validation rejects foreign destinations, escaped separators and callback loops", () => {
    const { hooks } = fixture();
    assert.equal(hooks.validReturnPath("/article/#discussion"), "/article/#discussion");
    for (const path of ["//evil.example/#discussion", "https://evil.example/#discussion", "/\\evil.example/#discussion", "/%2f%2fevil/#discussion", "/%0aevil/#discussion", "/%%#discussion", "/article/?next=x#discussion", "/outils/discussions/test.html#discussion", "/article/#other"]) {
        assert.equal(hooks.validReturnPath(path), "", path);
    }
});

test("denied sessionStorage does not prevent rendering or initiating login", async () => {
    const page = fixture({ blockStorage: true });
    await page.settled();
    page.elements.get("atproto-handle").value = "alice.bsky.social";
    await page.click("atproto-login-button");
    assert.equal(page.loginCalls, 1);
    assert.equal(page.hooks.state.blueskyLoaded, true);
    assert.equal(page.elements.get("mastodon-login-button").disabled, undefined);
});

test("all CSS rules stay inside the widget and the DOM has unique IDs", () => {
    const css = stylesheet.replace(/\/\*[\s\S]*?\*\//g, "");
    for (const match of css.matchAll(/([^{}]+)\{/g)) {
        const selector = match[1].trim();
        if (selector.startsWith("@")) continue;
        for (const group of selector.split(",")) assert.match(group, /(?:^|\s|main)\.souverain-discussion(?:\b|\s)/);
    }
    assert.doesNotMatch(css, /:root|\bbody\s*\{/);
    assert.match(css, /\.souverain-discussion \.join-discussion h3/);
    const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map(match => match[1]);
    assert.equal(new Set(ids).size, ids.length);
});

test("configuration controls every module endpoint, AppView and author with a custom path", async () => {
    const page = fixture();
    await page.settled();
    for (const request of page.requests.filter(item => item.target.includes(".php"))) {
        assert.ok(request.target.startsWith(defaultConfig.baseUrl + "/"), request.target);
    }
    const thread = page.requests.find(item => item.target.endsWith("mastodon/thread.php"));
    assert.equal(thread.request.headers["X-Federated-Comments-Request"], "1");
    const profile = new URL(page.requests.find(item => item.target.includes("getProfile")).target);
    assert.equal(profile.origin, "https://appview.example.net");
    assert.equal(profile.searchParams.get("actor"), "auteur.example.net");
    const feed = new URL(page.requests.find(item => item.target.includes("getAuthorFeed")).target);
    assert.equal(feed.searchParams.get("limit"), "50");
    assert.equal(page.hooks.commentsEndpoint("/mastodon/me.php"), defaultConfig.baseUrl + "/mastodon/me.php");
});

test("a missing or cross-origin module configuration stops requests with a visible error", async () => {
    for (const options of [{ noConfig: true }, { config: { baseUrl: "https://foreign.example/comments" } }]) {
        const page = fixture(options);
        await page.settled();
        assert.equal(page.requests.length, 0);
        assert.match(page.root.children[0].className, /error/);
        assert.ok(page.root.children[0].textContent.length > 0);
    }
});

test("basePath-only and root-directory installs build correct endpoint URLs", async () => {
    const custom = fixture({ config: { baseUrl: undefined, basePath: "/services/comments/" } });
    await custom.settled();
    assert.equal(custom.hooks.commentsEndpoint("mastodon/me.php"), "https://journal.example.net/services/comments/mastodon/me.php");
    const root = fixture({ config: { baseUrl: "https://journal.example.net/", basePath: "" } });
    await root.settled();
    assert.equal(root.hooks.commentsEndpoint("mastodon/me.php"), "https://journal.example.net/mastodon/me.php");
    assert.equal(root.hooks.validReturnPath("/article/#discussion"), "/article/#discussion");
    assert.equal(root.hooks.validReturnPath("/test.html#discussion"), "");
});

test("a return from another installation cannot redirect this installation", async () => {
    const page = fixture({
        url: "https://journal.example.net/another-comments/test.html#code=callback&state=csrf",
        article: null, connected: true,
        config: { baseUrl: "https://journal.example.net/another-comments", basePath: "/another-comments" },
        storage: { [returnKey]: JSON.stringify({ path: "/article/#discussion", createdAt: Date.now() }) }
    });
    await page.settled();
    assert.deepEqual(page.replacements, []);
});

test("legacy signup markup is regrouped without changing its nodes, links or introduction", () => {
    const page = fixture({ legacyMarkup: true });
    const hint = page.joinIntro.querySelector(".signup-hint");
    assert.ok(hint);
    assert.equal(page.joinIntro.children[0], page.intro);
    assert.equal(hint.children[0], page.signup);
    assert.equal(hint.children[1], page.signupLink);
    assert.equal(hint.children[2], page.tail);
    assert.equal(page.signupLink.href, "https://mastodon.social/auth/sign_up");
    const current = fixture();
    assert.equal(current.joinIntro.children.length, 2);
    assert.equal(current.joinIntro.querySelector(".signup-hint").children.length, 3);
});

test("neutral connection prompts stay empty after initialization and logout", async () => {
    const page = fixture();
    await page.settled();
    assert.equal(page.elements.get("mastodon-connection-status").textContent, "");
    assert.equal(page.elements.get("atproto-connection-status").textContent, "");
    await page.click("mastodon-logout");
    await page.click("atproto-logout");
    assert.equal(page.elements.get("mastodon-connection-status").textContent, "");
    assert.equal(page.elements.get("atproto-connection-status").textContent, "");
    assert.equal(page.description.textContent, "Connectez votre compte ATProto. Votre commentaire sera publié en réponse à la publication associée à cet article.");
});

test("latest typography refinements and generic integration markup are included", () => {
    assert.match(stylesheet, /\.protocol-description\s*\{\s*font-weight:\s*700/);
    assert.match(stylesheet, /\.signup-hint strong\s*\{\s*font-weight:\s*400/);
    assert.match(stylesheet, /\.form-label\[for="mastodon-account"\],[\s\S]*?\.form-label\[for="atproto-handle"\]\s*\{\s*font-weight:\s*400/);
    assert.match(stylesheet, /\.status-message:empty\s*\{\s*display:\s*none/);
    assert.match(stylesheet, /font-family:\s*var\(--font-text,/);
    assert.match(stylesheet, /--sc-text:\s*var\(--ink,/);
    assert.doesNotMatch(source + html + stylesheet, /souverain\.ovh|generation-lasuite|SouverainATProto|X-Souverain/);
    const example = fs.readFileSync(new URL("integration/article.html", moduleDirectory), "utf8");
    const expectedIds = [...html.matchAll(/\bid="([^"]+)"/g)].map(match => match[1]).sort();
    const actualIds = [...example.matchAll(/\bid="([^"]+)"/g)].map(match => match[1]).sort();
    assert.deepEqual(actualIds, expectedIds);
    assert.match(example, /<\/article>[\s\S]*<section id="discussion"[^>]+data-article-url="https:\/\/example.com\/mon-article\/"/);
    assert.match(example, /config-public\.php" defer>[\s\S]*atproto-oauth\.js\?v=0\.2\.1" defer>[\s\S]*commentaires\.js\?v=0\.2\.1" defer>/);
    assert.ok(example.indexOf("commentaires.css?v=0.2.1") < example.indexOf("config-public.php"));
});

test("the actual bundled SDK exposes its five methods without network or OAuth initialization", () => {
    const location = new URL(defaultConfig.baseUrl + "/test.html");
    const window = { location };
    let networkCalls = 0;
    const context = {
        window, location,
        document: { currentScript: { src: defaultConfig.baseUrl + "/atproto-oauth.js?v=0.2.1" } },
        URL, URLSearchParams, TextEncoder, TextDecoder, ReadableStream, TransformStream,
        Blob, Headers, Request, Response, AbortController, AbortSignal, DOMException,
        crypto: webcrypto,
        BroadcastChannel: class { postMessage() {} close() {} addEventListener() {} },
        setTimeout, clearTimeout,
        console: { warn() {}, error() {}, log() {} },
        fetch() { networkCalls++; throw new Error("No SDK network calls allowed in this test"); }
    };
    vm.createContext(context);
    vm.runInContext(fs.readFileSync(new URL("atproto-oauth.js", moduleDirectory), "utf8"), context, { timeout: 10000 });
    for (const method of ["init", "login", "publish", "profile", "logout"]) {
        assert.equal(typeof window.FederatedCommentsATProto?.[method], "function", method);
    }
    assert.equal(networkCalls, 0);
});

test("OAuth callback with a pending return works even when demoArticleUrl is empty", async () => {
    const page = fixture({
        url: defaultConfig.baseUrl + "/test.html#code=callback&state=csrf",
        article: null, connected: true, config: { demoArticleUrl: "" },
        storage: { [returnKey]: JSON.stringify({ path: "/article/#discussion", createdAt: Date.now() }) }
    });
    assert.equal(page.hooks.CONFIG.articleURL, article);
    // La destination n’est pas consommée avant le résultat de init().
    assert.equal(page.storage.has(returnKey), true);
    await page.settled();
    assert.equal(page.initCalls, 1);
    assert.deepEqual(page.replacements, ["/article/#discussion"]);
    assert.equal(page.storage.has(returnKey), false);
});

test("OAuth callback without a pending return or demo article still restores the session", async () => {
    const page = fixture({
        url: defaultConfig.baseUrl + "/test.html#code=callback&state=csrf",
        article: null, connected: true, config: { demoArticleUrl: "" }
    });
    await page.settled();
    assert.equal(page.initCalls, 1);
    assert.equal(page.requests.length, 0);
    assert.deepEqual(page.replacements, []);
    assert.match(page.root.children[0].textContent, /Compte ATProto connecté/);
    assert.match(page.root.children[0].textContent, /Retournez à votre article/);
});

test("OAuth errors without a pending return or demo article stay visible without article requests", async () => {
    const page = fixture({
        url: defaultConfig.baseUrl + "/test.html#error=access_denied&state=csrf",
        article: null, initError: true, config: { demoArticleUrl: "" }
    });
    await page.settled();
    assert.equal(page.initCalls, 1);
    assert.equal(page.requests.length, 0);
    assert.deepEqual(page.replacements, []);
    assert.match(page.root.children[0].textContent, /ERREUR OAuth/);
    assert.match(page.root.children[0].className, /error/);
});

test("a normal demo visit without a configured article does not initialize OAuth", async () => {
    const page = fixture({
        url: defaultConfig.baseUrl + "/test.html",
        article: null, connected: true, config: { demoArticleUrl: "" }
    });
    await page.settled();
    assert.equal(page.initCalls, 0);
    assert.equal(page.requests.length, 0);
    assert.deepEqual(page.replacements, []);
    assert.match(page.root.children[0].textContent, /URL publique de cet article n’est pas configurée/);
});

test("functional query permalinks are rejected explicitly rather than merging different articles", async () => {
    for (const parameter of ["?p=42", "?p=43", "?utm_source=bsky&p=42"]) {
        const page = fixture({ article: "https://journal.example.net/" + parameter });
        await page.settled();
        assert.equal(page.requests.length, 0);
        assert.match(page.root.children[0].textContent, /permalien HTTPS par chemin/);
    }
    const { hooks } = fixture();
    assert.notEqual(hooks.normaliseURL("https://journal.example.net/?p=42"), hooks.normaliseURL("https://journal.example.net/?p=43"));
    assert.equal(hooks.normaliseURL(article + "?b=2&a=1"), hooks.normaliseURL(article + "?a=1&b=2"));
});

test("functional queries on social root links do not match a query-free article", () => {
    const { hooks } = fixture();
    assert.equal(hooks.blueskyContainsArticle(post(article + "?p=42")), false);
    assert.equal(hooks.blueskyContainsArticle(post("", { record: { facets: [{ features: [{ uri: article + "?p=43" }] }] } })), false);
    assert.equal(hooks.blueskyContainsArticle(post("", { embed: { external: { uri: article + "?other=p" } } })), false);
});

test("tracking parameters alone are removed for canonical URLs and social links", async () => {
    const tracked = article + "?utm_source=bsky&utm_medium=social&fbclid=test&gclid=test";
    const page = fixture({ article: tracked });
    await page.settled();
    assert.equal(page.hooks.CONFIG.articleURL, article);
    assert.equal(page.hooks.publicArticleURL(tracked), article);
    assert.equal(page.hooks.blueskyContainsArticle(post(tracked)), true);
    assert.equal(page.hooks.normaliseURL(article + "?utm_source=test&p=42"), article + "?p=42");
});
