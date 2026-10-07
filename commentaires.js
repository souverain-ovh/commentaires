/* Souverain.ovh */
(() => {
"use strict";

const widget = document.querySelector("#discussion.souverain-discussion");

if (!widget) {
    return;
}

// Les articles déjà exportés regroupent l’introduction et l’aide à l’inscription.
const joinIntro = widget.querySelector(".join-intro");
if (joinIntro && !joinIntro.querySelector(".signup-hint")) {
    const signupStart = joinIntro.querySelector("strong");
    if (signupStart) {
        const signupHint = document.createElement("span");
        signupHint.className = "signup-hint";
        joinIntro.insertBefore(signupHint, signupStart);
        while (signupHint.nextSibling) {
            signupHint.appendChild(signupHint.nextSibling);
        }
    }
}

// Actualise aussi la description sur les articles déjà exportés.
const atprotoDescription = widget.querySelector("#atproto-panel .protocol-description");
if (atprotoDescription) {
    atprotoDescription.textContent = "Connectez votre compte ATProto. Votre commentaire sera publié en réponse à la publication associée à cet article.";
}

const PUBLIC_CONFIG = window.FederatedCommentsConfig;
let SITE_ORIGIN;
let BASE_URL;
let BASE_PATH;

try {
    if (!PUBLIC_CONFIG) {
        throw new Error("La configuration publique n’est pas chargée.");
    }
    const site = new URL(String(PUBLIC_CONFIG.siteUrl || ""));
    const base = new URL(
        String(PUBLIC_CONFIG.baseUrl || PUBLIC_CONFIG.basePath || "/"),
        site.origin + "/"
    );
    if (site.protocol !== "https:" || site.username || site.password ||
        base.origin !== site.origin || base.username || base.password ||
        base.search || base.hash) {
        throw new Error("L’origine HTTPS et le chemin du module doivent correspondre au site.");
    }
    SITE_ORIGIN = site.origin;
    BASE_URL = base.href.replace(/\/+$/, "");
    BASE_PATH = base.pathname.replace(/\/+$/, "");
} catch (error) {
    const message = document.createElement("p");
    message.className = "status-message error";
    message.textContent = error.message || "La configuration publique est invalide.";
    widget.replaceChildren(message);
    return;
}

function commentsEndpoint(path) {
    return BASE_URL + "/" + String(path || "").replace(/^\/+/, "");
}

function isModulePath(path) {
    return BASE_PATH
        ? path === BASE_PATH || path.startsWith(BASE_PATH + "/")
        : path === "/test.html";
}

const DEMO_PATH = new URL(commentsEndpoint("test.html")).pathname;
const STORAGE_PREFIX = "federated-comments:" + BASE_URL + ":";
const RETURN_STORAGE_KEY = STORAGE_PREFIX + "atproto-return";
const PROTOCOL_STORAGE_KEY = STORAGE_PREFIX + "protocol";
const originalURL = new URL(window.location.href);
const isDemoPage = originalURL.pathname === DEMO_PATH;

// Le bundle livré utilise responseMode="fragment" et nettoie l’URL pendant init().
const atprotoCallbackParams = new URLSearchParams(originalURL.hash.slice(1));
const atprotoOAuthCallback = isDemoPage && atprotoCallbackParams.has("state") &&
    (atprotoCallbackParams.has("code") || atprotoCallbackParams.has("error"));
const atprotoOAuthReturn = atprotoOAuthCallback && atprotoCallbackParams.has("code");

function getWidgetElement(id) {
    return widget.querySelector("#" + id);
}

function readStorage(key) {
    try {
        return window.sessionStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeStorage(key, value) {
    try {
        window.sessionStorage.setItem(key, value);
        return true;
    } catch {
        return false;
    }
}

function removeStorage(key) {
    try {
        window.sessionStorage.removeItem(key);
    } catch {
        // Certains navigateurs refusent le stockage : la connexion reste utilisable.
    }
}

function normaliseArticleQuery(url) {
    for (const key of [...url.searchParams.keys()]) {
        if (/^utm_/i.test(key) || /^(?:fbclid|gclid)$/i.test(key)) {
            url.searchParams.delete(key);
        }
    }
    url.searchParams.sort();
}

function publicArticleURL(value) {
    try {
        const url = new URL(String(value || ""));
        if (url.origin !== SITE_ORIGIN || url.username || url.password ||
            isModulePath(url.pathname)) {
            return "";
        }
        normaliseArticleQuery(url);
        if (url.search) {
            return "";
        }
        url.hash = "";
        return url.href;
    } catch {
        return "";
    }
}

function validReturnPath(value) {
    if (typeof value !== "string" || !value.startsWith("/") ||
        value.startsWith("//") || /[\u0000-\u0020\u007f\\]/.test(value) ||
        /%(?:0[0-9a-f]|1[0-9a-f]|20|2f|5c|7f)/i.test(value) ||
        /%(?![0-9a-f]{2})/i.test(value)) {
        return "";
    }
    try {
        const url = new URL(value, SITE_ORIGIN);
        if (url.origin !== SITE_ORIGIN || url.username || url.password ||
            url.search || url.hash !== "#discussion" ||
            isModulePath(url.pathname)) {
            return "";
        }
        return url.pathname + "#discussion";
    } catch {
        return "";
    }
}

function articleReturnPath() {
    const url = new URL(CONFIG.articleURL);
    return validReturnPath(url.pathname + "#discussion");
}

function rememberAtprotoReturn() {
    writeStorage(RETURN_STORAGE_KEY, JSON.stringify({
        path: articleReturnPath(),
        createdAt: Date.now()
    }));
}

function pendingAtprotoReturn() {
    const saved = readStorage(RETURN_STORAGE_KEY);
    try {
        const data = JSON.parse(saved || "null");
        const age = Date.now() - Number(data?.createdAt);
        return data && Number.isFinite(age) && age >= 0 && age < 30 * 60 * 1000
            ? validReturnPath(data.path)
            : "";
    } catch {
        return "";
    }
}

function takeAtprotoReturn() {
    const returnTo = pendingAtprotoReturn();
    removeStorage(RETURN_STORAGE_KEY);
    return returnTo;
}

async function finishAtprotoCallbackWithoutArticle(message) {
    message.className = "status-message";
    message.textContent = "Finalisation de la connexion ATProto…";
    try {
        if (typeof window.FederatedCommentsATProto?.init !== "function") {
            throw new Error("Le module de connexion ATProto n’est pas chargé.");
        }
        const result = await window.FederatedCommentsATProto.init();
        removeStorage(RETURN_STORAGE_KEY);
        message.className = result?.connected ? "status-message success" : "status-message error";
        message.textContent = result?.connected
            ? "Compte ATProto connecté. Retournez à votre article pour répondre."
            : "La connexion ATProto n’a pas abouti. Retournez à votre article pour réessayer.";
    } catch (error) {
        removeStorage(RETURN_STORAGE_KEY);
        message.className = "status-message error";
        message.textContent = "ERREUR OAuth : " + error.message;
    }
}

const configuredArticle = publicArticleURL(widget.dataset.articleUrl);
const pendingReturnPath = atprotoOAuthCallback ? pendingAtprotoReturn() : "";
const pendingArticleURL = pendingReturnPath
    ? publicArticleURL(new URL(pendingReturnPath, SITE_ORIGIN).href)
    : "";
const articleURL = configuredArticle || (isDemoPage
    ? pendingArticleURL || publicArticleURL(PUBLIC_CONFIG.demoArticleUrl)
    : "");

if (!articleURL) {
    const message = document.createElement("p");
    message.className = "status-message error";
    message.textContent = "L’URL publique de cet article n’est pas configurée. Utilisez un permalien HTTPS par chemin, sans paramètres fonctionnels.";
    widget.replaceChildren(message);
    if (atprotoOAuthCallback) {
        finishAtprotoCallbackWithoutArticle(message);
    }
    return;
}

/* ==========================================================
   CONFIGURATION
   ========================================================== */

const CONFIG = {
    articleURL,
    maxPostsToSearch: Math.min(10000, Math.max(1,
        Math.floor(Number(PUBLIC_CONFIG.maxPostsToSearch) || 1000)
    )),
    bluesky: {
        handle: String(PUBLIC_CONFIG.bluesky?.handle || ""),
        api: String(PUBLIC_CONFIG.bluesky?.api || "https://public.api.bsky.app").replace(/\/+$/, ""),
        pageSize: Math.min(100, Math.max(1,
            Math.floor(Number(PUBLIC_CONFIG.bluesky?.pageSize) || 100)
        ))
    }
};


const state = {

    mastodonRoot:
        null,

    blueskyRoot:
        null,

    mastodonReplies:
        0,

    blueskyReplies:
        0,

    mastodonComments:
        [],

    blueskyComments:
        [],

    mastodonLoaded:
        false,

    blueskyLoaded:
        false,

    mastodonError:
        "",

    blueskyError:
        ""
};




/* ==========================================================
   OUTILS
   ========================================================== */

function escapeHTML(value) {

    const div =
        document.createElement("div");

    div.textContent =
        value ?? "";

    return div.innerHTML;
}


function safeHttpsURL(value) {

    try {

        const url =
            new URL(String(value || ""));

        return url.protocol === "https:"
            ? url.href
            : "";

    } catch {

        return "";
    }
}


function mastodonImageProxyURL(value) {

    const url =
        safeHttpsURL(value);

    if (!url) {
        return "";
    }

    return (
        commentsEndpoint("mastodon/image.php") + "?url=" +
        encodeURIComponent(url)
    );
}


function sanitizeMastodonHTML(value) {

    const parser =
        new DOMParser();

    const source =
        parser.parseFromString(
            String(value || ""),
            "text/html"
        );

    const output =
        document.createElement("div");


    function copySafeNode(node, parent) {

        if (node.nodeType === Node.TEXT_NODE) {

            parent.appendChild(
                document.createTextNode(
                    node.textContent || ""
                )
            );

            return;
        }


        if (node.nodeType !== Node.ELEMENT_NODE) {
            return;
        }


        const tag =
            node.tagName.toUpperCase();


        if (tag === "BR") {

            parent.appendChild(
                document.createElement("br")
            );

            return;
        }


        if (tag === "P") {

            const paragraph =
                document.createElement("p");

            for (const child of node.childNodes) {
                copySafeNode(child, paragraph);
            }

            parent.appendChild(paragraph);

            return;
        }


        if (tag === "A") {

            const href =
                safeHttpsURL(
                    node.getAttribute("href")
                );

            if (!href) {

                for (const child of node.childNodes) {
                    copySafeNode(child, parent);
                }

                return;
            }

            const link =
                document.createElement("a");

            link.href = href;
            link.target = "_blank";
            link.rel = "noopener noreferrer";

            for (const child of node.childNodes) {
                copySafeNode(child, link);
            }

            parent.appendChild(link);

            return;
        }


        if (tag === "IMG") {

            const alt =
                node.getAttribute("alt") || "";

            if (alt) {

                parent.appendChild(
                    document.createTextNode(alt)
                );
            }

            return;
        }


        /*
         * Tout autre élément (span, div, etc.) est supprimé,
         * mais son texte reste conservé.
         */

        for (const child of node.childNodes) {
            copySafeNode(child, parent);
        }
    }


    for (const child of source.body.childNodes) {
        copySafeNode(child, output);
    }


    return output.innerHTML;
}


async function readJsonResponse(response) {

    const contentType =
        (response.headers.get("content-type") || "")
            .toLowerCase();

    if (!contentType.includes("json")) {

        throw new Error(
            `Réponse serveur inattendue (HTTP ${response.status}).`
        );
    }

    return response.json();
}


function normaliseURL(url) {

    try {

        const u =
            new URL(url);

        if (u.protocol !== "https:" || u.username || u.password) {
            return "";
        }

        normaliseArticleQuery(u);

        return (
            u.origin.toLowerCase() +
            u.pathname.replace(/\/?$/, "/") +
            u.search
        );

    } catch {

        return "";
    }
}


const ARTICLE =
    normaliseURL(
        CONFIG.articleURL
    );




function updateCommentCount() {

    const total =
        state.mastodonComments.length +
        state.blueskyComments.length;

    state.mastodonReplies =
        state.mastodonComments.length;

    state.blueskyReplies =
        state.blueskyComments.length;

    getWidgetElement("total-comments")
        .textContent =

        total === 0
            ? "Aucun commentaire"

            : total === 1
                ? "1 commentaire"

                : `${total} commentaires`;
}


function mastodonCommentLevel(
    status,
    rootId,
    byId
) {

    let level = 1;
    let parentId =
        String(
            status?.in_reply_to_id || ""
        );

    const visited =
        new Set();


    while (
        parentId &&
        parentId !== rootId &&
        byId.has(parentId) &&
        !visited.has(parentId) &&
        level < 3
    ) {

        visited.add(parentId);
        level++;

        parentId =
            String(
                byId
                    .get(parentId)
                    ?.in_reply_to_id || ""
            );
    }


    return level;
}


function buildMastodonComments(
    root,
    descendants
) {

    const byId =
        new Map(
            descendants
                .filter(
                    status =>
                        status && status.id
                )
                .map(
                    status =>
                        [String(status.id), status]
                )
        );

    const rootId =
        String(root?.id || "");


    return descendants
        .filter(Boolean)
        .map(
            status => ({

                network:
                    "mastodon",

                createdAt:
                    status.created_at || "",

                level:
                    mastodonCommentLevel(
                        status,
                        rootId,
                        byId
                    ),

                payload:
                    status
            })
        );
}


function collectBlueskyComments(
    thread,
    level = 1,
    output = []
) {

    const replies =
        Array.isArray(thread?.replies)
            ? thread.replies
            : [];


    for (const reply of replies) {

        if (!reply?.post) {
            continue;
        }

        output.push({

            network:
                "atproto",

            createdAt:
                reply.post.record?.createdAt || "",

            level:
                Math.min(
                    Math.max(level, 1),
                    3
                ),

            payload:
                reply
        });

        collectBlueskyComments(
            reply,
            level + 1,
            output
        );
    }


    return output;
}


function renderUnifiedComments() {

    const container =
        getWidgetElement(
            "comments-feed"
        );


    const comments =
        [
            ...state.mastodonComments,
            ...state.blueskyComments
        ]
        .sort(
            (a, b) => {

                const aTime =
                    Date.parse(a.createdAt) || 0;

                const bTime =
                    Date.parse(b.createdAt) || 0;

                return aTime - bTime;
            }
        );


    updateCommentCount();

    const warnings = [
        state.mastodonError ? "Les commentaires Mastodon sont indisponibles pour le moment." : "",
        state.blueskyError ? "Les commentaires Bluesky sont indisponibles pour le moment." : ""
    ].filter(Boolean).map(message =>
        `<p class="network-warning" role="status">${escapeHTML(message)}</p>`
    ).join("");


    if (comments.length) {

        container.innerHTML = warnings +
            comments
                .map(
                    comment =>
                        comment.network === "mastodon"
                            ? renderMastodonComment(
                                comment.payload,
                                comment.level
                            )
                            : renderBlueskyComment(
                                comment.payload,
                                comment.level
                            )
                )
                .join("");

        return;
    }


    if (
        !state.mastodonLoaded ||
        !state.blueskyLoaded
    ) {

        container.innerHTML = warnings +
            `<div class="empty">
                Recherche des réponses…
            </div>`;

        return;
    }


    if (
        state.mastodonError &&
        state.blueskyError
    ) {

        container.innerHTML = warnings +
            `<div class="empty">
                Impossible de charger les réponses pour le moment.
            </div>`;

        return;
    }


    container.innerHTML = warnings +
        `<div class="empty">
            ${state.mastodonError || state.blueskyError
                ? "Aucune réponse disponible sur le réseau chargé."
                : "Aucune réponse pour le moment."}
        </div>`;
}

/* ==========================================================
   SÉLECTEUR DE PROTOCOLE
   ========================================================== */

const selectMastodon =
    getWidgetElement(
        "select-mastodon"
    );

const selectAtproto =
    getWidgetElement(
        "select-atproto"
    );

const mastodonPanel =
    getWidgetElement(
        "mastodon-panel"
    );

const atprotoPanel =
    getWidgetElement(
        "atproto-panel"
    );


function showMastodonPanel() {

    mastodonPanel.hidden =
        false;

    atprotoPanel.hidden =
        true;

    selectMastodon
        .classList
        .add("active");

    selectAtproto
        .classList
        .remove("active");
}


function showAtprotoPanel() {

    mastodonPanel.hidden =
        true;

    atprotoPanel.hidden =
        false;

    selectAtproto
        .classList
        .add("active");

    selectMastodon
        .classList
        .remove("active");
}


selectMastodon.addEventListener(
    "click",
    () => {

        writeStorage(
            PROTOCOL_STORAGE_KEY,
            "mastodon"
        );

        showMastodonPanel();
    }
);


selectAtproto.addEventListener(
    "click",
    () => {

        writeStorage(
            PROTOCOL_STORAGE_KEY,
            "atproto"
        );

        showAtprotoPanel();
    }
);


/* ==========================================================
   MASTODON : CHARGEMENT DU FIL
   La lecture de Mastodon passe par notre PHP afin que le
   navigateur ne contacte jamais directement l’instance Mastodon configurée.
   ========================================================== */

async function loadMastodon() {

    state.mastodonLoaded =
        false;

    state.mastodonError =
        "";


    try {

        const response =
            await fetch(
                commentsEndpoint("mastodon/thread.php"),
                {
                    method:
                        "POST",

                    credentials:
                        "same-origin",

                    cache:
                        "no-store",

                    headers: {

                        "Content-Type":
                            "application/json",

                        "X-Federated-Comments-Request":
                            "1"
                    },

                    body:
                        JSON.stringify({
                            article_url:
                                CONFIG.articleURL
                        })
                }
            );


        const data =
            await readJsonResponse(
                response
            );


        if (
            !response.ok ||
            !data.ok
        ) {

            throw new Error(
                data.error ||
                `Erreur HTTP ${response.status}`
            );
        }


        if (!data.found) {

            state.mastodonRoot =
                null;

            state.mastodonComments =
                [];

            return;
        }


        const root =
            data.root;

        const context =
            data.context || {};

        const descendants =
            Array.isArray(
                context.descendants
            )
                ? context.descendants
                : [];


        state.mastodonRoot =
            root;

        state.mastodonComments =
            buildMastodonComments(
                root,
                descendants
            );


    } catch(error) {

        console.error(error);

        state.mastodonRoot =
            null;

        state.mastodonComments =
            [];

        state.mastodonError =
            error.message ||
            "Impossible de charger Mastodon.";

    } finally {

        state.mastodonLoaded =
            true;

        renderUnifiedComments();
    }
}


/* ==========================================================
   AFFICHAGE MASTODON
   ========================================================== */

function renderMastodonComment(
    status,
    level = 1
) {

    const date =

        new Date(
            status.created_at
        )
        .toLocaleString(
            "fr-FR",
            {
                dateStyle:
                    "medium",

                timeStyle:
                    "short"
            }
        );


    const avatarURL =
        mastodonImageProxyURL(
            status.account?.avatar
        );


    const statusURL =
        safeHttpsURL(
            status.url
        );


    const avatarHTML =
        avatarURL
            ? `<img
                class="avatar"
                src="${escapeHTML(avatarURL)}"
                alt=""
                loading="lazy">`
            : "";


    const dateHTML =
        statusURL
            ? `<a
                href="${escapeHTML(statusURL)}"
                target="_blank"
                rel="noopener noreferrer">
                ${escapeHTML(date)}
               </a>`
            : `<span>${escapeHTML(date)}</span>`;


    const levelClass =
        ` comment-level-${Math.min(
            Math.max(level, 1),
            3
        )}`;


    return `

    <article
        class="post comment-post${levelClass}">


        ${avatarHTML}


        <div class="post-header">

            <div class="identity">

                <div class="author">

                    ${escapeHTML(
                        status.account?.display_name
                        ||
                        status.account?.username
                        ||
                        "Compte Mastodon"
                    )}

                </div>


                <div class="handle">

                    @${escapeHTML(
                        status.account?.acct || ""
                    )}

                </div>

            </div>

        </div>


        <div class="post-content">

            ${sanitizeMastodonHTML(
                status.content
            )}

        </div>


        <div class="post-meta">

            ${dateHTML}

            <span class="post-origin mastodon">
                🐘 Mastodon
            </span>

        </div>

    </article>`;
}

/* ==========================================================
   BLUESKY : DÉTECTION
   ========================================================== */

function blueskyContainsArticle(post) {

    const record =
        post.record || {};


    const textURLs = String(record.text || "").match(/https:\/\/[^\s<>"']+/giu) || [];
    if (textURLs.some(value => normaliseURL(
        value.replace(/[.,!?;:)\]}]+$/, "")
    ) === ARTICLE)) {
        return true;
    }


    if (
        Array.isArray(
            record.facets
        )
    ) {

        for (
            const facet
            of record.facets
        ) {

            for (
                const feature
                of facet.features || []
            ) {

                if (
                    feature.uri &&
                    normaliseURL(
                        feature.uri
                    ) === ARTICLE
                ) {
                    return true;
                }
            }
        }
    }


    const external =
        post.embed?.external;


    return !!(
        external?.uri &&
        normaliseURL(
            external.uri
        ) === ARTICLE
    );
}


/* ==========================================================
   BLUESKY : CHARGEMENT
   ========================================================== */

let blueskyAuthorDID = "";

async function resolveBlueskyAuthor() {
    if (blueskyAuthorDID) {
        return blueskyAuthorDID;
    }
    const response = await fetch(
        `${CONFIG.bluesky.api}/xrpc/app.bsky.actor.getProfile?actor=${encodeURIComponent(CONFIG.bluesky.handle)}`,
        { credentials: "omit", signal: AbortSignal.timeout(15000) }
    );
    const profile = await readJsonResponse(response);
    if (!response.ok || typeof profile.did !== "string" || !/^did:[a-z]+:.+$/i.test(profile.did)) {
        throw new Error("Le compte Bluesky configuré est introuvable.");
    }
    blueskyAuthorDID = profile.did;
    return blueskyAuthorDID;
}

function isBlueskyArticleRoot(item, expectedDID) {
    return !!item?.post &&
        item.reason?.$type !== "app.bsky.feed.defs#reasonRepost" &&
        !item.post.record?.reply &&
        item.post.author?.did === expectedDID &&
        blueskyContainsArticle(item.post);
}

async function loadBluesky() {

    state.blueskyLoaded =
        false;

    state.blueskyError =
        "";


    try {

        const expectedDID = await resolveBlueskyAuthor();

        let cursor =
            null;

        let examined =
            0;

        let page =
            0;

        let root =
            null;


        while (
            examined <
                CONFIG.maxPostsToSearch
            &&
            !root
        ) {

            page++;


            let url =

                `${CONFIG.bluesky.api}` +

                `/xrpc/app.bsky.feed.getAuthorFeed` +

                `?actor=${encodeURIComponent(
                    CONFIG.bluesky.handle
                )}` +

                `&limit=${CONFIG.bluesky.pageSize}` +

                `&filter=posts_no_replies`;


            if (cursor) {

                url +=
                    `&cursor=${encodeURIComponent(cursor)}`;
            }


            const response =
                await fetch(url, { credentials: "omit", signal: AbortSignal.timeout(15000) });


            if (!response.ok) {

                throw new Error(
                    `Erreur page ${page} (${response.status})`
                );
            }


            const data =
                await readJsonResponse(response);


            if (!data.feed?.length) {
                break;
            }


            for (
                const item
                of data.feed
            ) {

                examined++;


                if (
                    isBlueskyArticleRoot(
                        item,
                        expectedDID
                    )
                ) {

                    root =
                        item.post;

                    break;
                }


                if (
                    examined >=
                    CONFIG.maxPostsToSearch
                ) {
                    break;
                }
            }


            if (root) {
                break;
            }


            if (!data.cursor) {
                break;
            }


            cursor =
                data.cursor;
        }


        if (!root) {

            state.blueskyRoot =
                null;

            state.blueskyComments =
                [];

            return;
        }


        state.blueskyRoot =
            root;


        const threadResponse =
            await fetch(

                `${CONFIG.bluesky.api}` +

                `/xrpc/app.bsky.feed.getPostThread` +

                `?uri=${encodeURIComponent(root.uri)}` +

                `&depth=20`,
                { credentials: "omit", signal: AbortSignal.timeout(15000) }
            );


        if (!threadResponse.ok) {

            throw new Error(
                `Thread inaccessible (${threadResponse.status})`
            );
        }


        const threadData =
            await readJsonResponse(threadResponse);


        state.blueskyComments =
            collectBlueskyComments(
                threadData.thread
            );


    } catch(error) {

        console.error(error);

        state.blueskyRoot =
            null;

        state.blueskyComments =
            [];

        state.blueskyError =
            error.message ||
            "Impossible de charger Bluesky.";

    } finally {

        state.blueskyLoaded =
            true;

        renderUnifiedComments();
    }
}


/* ==========================================================
   BLUESKY : AFFICHAGE D'UN COMMENTAIRE
   ========================================================== */

function renderBlueskyComment(
    thread,
    level = 1
) {

    if (!thread?.post) {
        return "";
    }


    const post =
        thread.post;


    const author =
        post.author || {};


    const text =
        post.record?.text || "";


    const date =

        new Date(
            post.record?.createdAt
        )
        .toLocaleString(
            "fr-FR",
            {
                dateStyle:
                    "medium",

                timeStyle:
                    "short"
            }
        );


    const rkey =
        String(post.uri || "")
            .split("/")
            .pop() || "";


    const publicURL =

        `https://bsky.app/profile/` +
        `${encodeURIComponent(author.handle || "")}` +
        `/post/${encodeURIComponent(rkey)}`;


    const avatarURL =
        safeHttpsURL(
            author.avatar
        );


    const levelClass =
        ` comment-level-${Math.min(
            Math.max(level, 1),
            3
        )}`;


    return `

    <article
        class="post comment-post${levelClass}">


        ${
            avatarURL

            ?

            `<img
                class="avatar"
                src="${escapeHTML(avatarURL)}"
                alt=""
                loading="lazy">`

            :

            ""
        }


        <div class="post-header">

            <div class="identity">

                <div class="author">

                    ${escapeHTML(
                        author.displayName
                        ||
                        author.handle
                        ||
                        "Compte ATProto"
                    )}

                </div>


                <div class="handle">

                    @${escapeHTML(
                        author.handle || ""
                    )}

                </div>

            </div>

        </div>


        <div class="post-content">

            ${escapeHTML(text)
                .replace(
                    /\n/g,
                    "<br>"
                )}

        </div>


        <div class="post-meta">

            <a
                href="${escapeHTML(publicURL)}"
                target="_blank"
                rel="noopener noreferrer">

                ${escapeHTML(date)}

            </a>

            <span class="post-origin bluesky">
                🦋 Bluesky / ATProto
            </span>

        </div>

    </article>`;
}

/* ==========================================================
   MASTODON : INTERFACE
   ========================================================== */

const mastodonConnectionStatus =
    getWidgetElement(
        "mastodon-connection-status"
    );

const mastodonLogin =
    getWidgetElement(
        "mastodon-login"
    );

const mastodonAccount =
    getWidgetElement(
        "mastodon-account"
    );

const mastodonLoginButton =
    getWidgetElement(
        "mastodon-login-button"
    );

const mastodonConnectedAccount =
    getWidgetElement(
        "mastodon-connected-account"
    );

const mastodonConnectedAvatar =
    getWidgetElement(
        "mastodon-connected-avatar"
    );

const mastodonConnectedName =
    getWidgetElement(
        "mastodon-connected-name"
    );

const mastodonConnectedHandle =
    getWidgetElement(
        "mastodon-connected-handle"
    );

const mastodonLogout =
    getWidgetElement(
        "mastodon-logout"
    );

const mastodonCompose =
    getWidgetElement(
        "mastodon-compose"
    );

const mastodonReply =
    getWidgetElement(
        "mastodon-reply"
    );

const mastodonCounter =
    getWidgetElement(
        "mastodon-counter"
    );

const mastodonPublish =
    getWidgetElement(
        "mastodon-publish"
    );

const mastodonPublishStatus =
    getWidgetElement(
        "mastodon-publish-status"
    );


function resetMastodonInterface() {

    mastodonConnectedAccount.hidden =
        true;

    mastodonConnectedAvatar.hidden =
        true;

    mastodonConnectedAvatar.replaceChildren();

    mastodonConnectedName.textContent =
        "";

    mastodonConnectedHandle.textContent =
        "";

    mastodonCompose.hidden =
        true;

    mastodonLogin.hidden =
        false;
}


/* ==========================================================
   MASTODON : EXTRACTION INSTANCE
   ========================================================== */

function extractMastodonInstance(
    value
) {

    value =
        value.trim();


    if (!value) {

        throw new Error(
            "Indiquez votre compte Mastodon."
        );
    }


    value =
        value.replace(
            /^@/,
            ""
        );


    if (
        value.includes("@")
    ) {

        const parts =
            value.split("@");

        value =
            parts[
                parts.length - 1
            ];
    }


    value =
        value
            .replace(
                /^https?:\/\//i,
                ""
            )
            .replace(
                /\/.*$/,
                ""
            )
            .trim();


    if (!value) {

        throw new Error(
            "Impossible de déterminer l’instance Mastodon."
        );
    }


    return value;
}


/* ==========================================================
   MASTODON : SESSION
   ========================================================== */

async function initialiseMastodon() {

    resetMastodonInterface();


    mastodonConnectionStatus
        .className =
            "status-message";


    mastodonConnectionStatus
        .textContent =
            "Vérification de la session Mastodon…";


    try {

        const response =
            await fetch(
                commentsEndpoint("mastodon/me.php"),
                {
                    credentials:
                        "same-origin",

                    cache:
                        "no-store"
                }
            );


        const data =
            await readJsonResponse(response);


        if (
            !response.ok ||
            !data.ok
        ) {

            throw new Error(
                data.error ||
                `Erreur HTTP ${response.status}`
            );
        }


        if (!data.connected) {

            resetMastodonInterface();


            mastodonConnectionStatus
                .textContent =

                "";


            return;
        }


        const account =
            data.account;


        mastodonLogin.hidden =
            true;


        mastodonConnectedAccount.hidden =
            false;


        mastodonCompose.hidden =
            false;


        const mastodonAvatarURL =
            mastodonImageProxyURL(account.avatar);

        if (mastodonAvatarURL) {

            mastodonConnectedAvatar.replaceChildren();
            const mastodonAvatarImage = document.createElement("img");
            mastodonAvatarImage.src = mastodonAvatarURL;
            mastodonAvatarImage.alt = "";
            mastodonAvatarImage.width = 38;
            mastodonAvatarImage.height = 38;
            mastodonConnectedAvatar.appendChild(mastodonAvatarImage);

            mastodonConnectedAvatar.hidden =
                false;
        }


        mastodonConnectedName.textContent =

            account.display_name
            ||
            account.username;


        mastodonConnectedHandle.textContent =
            account.handle;


        mastodonConnectionStatus
            .className =
                "status-message success";


        mastodonConnectionStatus
            .textContent =
                "✓ Connecté avec Mastodon.";


    } catch(error) {

        console.error(error);


        resetMastodonInterface();


        mastodonConnectionStatus
            .className =
                "status-message error";


        mastodonConnectionStatus
            .textContent =

                "ERREUR : " +
                error.message;
    }
}


/* ==========================================================
   MASTODON : CONNEXION
   ========================================================== */

mastodonLoginButton.addEventListener(
    "click",
    async () => {

        mastodonConnectionStatus
            .className =
                "status-message";


        let instance;


        try {

            instance =
                extractMastodonInstance(
                    mastodonAccount.value
                );


        } catch(error) {

            mastodonConnectionStatus
                .className =
                    "status-message error";


            mastodonConnectionStatus
                .textContent =
                    error.message;


            return;
        }


        mastodonLoginButton.disabled =
            true;


        mastodonConnectionStatus
            .textContent =

                `Connexion à ${instance}…`;


        /*
         * On mémorise le protocole avant de quitter
         * la page pour l'autorisation OAuth.
         */

        writeStorage(
            PROTOCOL_STORAGE_KEY,
            "mastodon"
        );


        try {

            const response =
                await fetch(
                    commentsEndpoint("mastodon/register.php"),
                    {

                        method:
                            "POST",

                        credentials:
                            "same-origin",

                        headers: {

                            "Content-Type":
                                "application/json",

                            "X-Federated-Comments-Request":
                                "1"
                        },

                        body:
                            JSON.stringify({
                                instance,
                                return_to: articleReturnPath()
                            })
                    }
                );


            const data =
                await readJsonResponse(response);


            if (
                !response.ok ||
                !data.ok
            ) {

                throw new Error(
                    data.error ||
                    `Erreur HTTP ${response.status}`
                );
            }


            if (!data.authorize_url) {

                throw new Error(
                    "Aucune URL d’autorisation Mastodon n’a été reçue."
                );
            }


            window.location.href =
                data.authorize_url;


        } catch(error) {

            console.error(error);


            mastodonConnectionStatus
                .className =
                    "status-message error";


            mastodonConnectionStatus
                .textContent =

                    "ERREUR : " +
                    error.message;


            mastodonLoginButton.disabled =
                false;
        }
    }
);


/* ==========================================================
   MASTODON : DÉCONNEXION
   ========================================================== */

mastodonLogout.addEventListener(
    "click",
    async () => {

        mastodonLogout.disabled =
            true;


        mastodonConnectionStatus
            .className =
                "status-message";


        mastodonConnectionStatus
            .textContent =
                "Déconnexion…";


        try {

            const response =
                await fetch(
                    commentsEndpoint("mastodon/logout.php"),
                    {

                        method:
                            "POST",

                        credentials:
                            "same-origin",

                        headers: {
                            "X-Federated-Comments-Request": "1"
                        }
                    }
                );


            const data =
                await readJsonResponse(response);


            if (
                !response.ok ||
                !data.ok
            ) {

                throw new Error(
                    data.error ||
                    `Erreur HTTP ${response.status}`
                );
            }


            resetMastodonInterface();


            mastodonConnectionStatus
                .textContent =

                "";


        } catch(error) {

            console.error(error);


            mastodonConnectionStatus
                .className =
                    "status-message error";


            mastodonConnectionStatus
                .textContent =

                "ERREUR : " +
                error.message;


        } finally {

            mastodonLogout.disabled =
                false;
        }
    }
);


/* ==========================================================
   MASTODON : COMPTEUR
   ========================================================== */

mastodonReply.addEventListener(
    "input",
    () => {

        mastodonCounter.textContent =

            `${mastodonReply.value.length} / 500`;
    }
);


/* ==========================================================
   MASTODON : PUBLICATION
   ========================================================== */

mastodonPublish.addEventListener(
    "click",
    async () => {

        mastodonPublishStatus
            .className =
                "status-message";


        const text =
            mastodonReply
                .value
                .trim();


        if (!text) {

            mastodonPublishStatus
                .className =
                    "status-message error";


            mastodonPublishStatus
                .textContent =
                    "Écrivez d’abord une réponse.";


            return;
        }


        if (
            !state.mastodonRoot ||
            !state.mastodonRoot.url
        ) {

            mastodonPublishStatus
                .className =
                    "status-message error";


            mastodonPublishStatus
                .textContent =

                "Le message Mastodon associé à cet article n’a pas encore été trouvé.";


            return;
        }


        mastodonPublish.disabled =
            true;


        mastodonPublishStatus
            .textContent =

                "Publication en cours…";


        try {

            const response =
                await fetch(
                    commentsEndpoint("mastodon/reply.php"),
                    {

                        method:
                            "POST",

                        credentials:
                            "same-origin",

                        headers: {

                            "Content-Type":
                                "application/json",

                            "X-Federated-Comments-Request":
                                "1"
                        },

                        body:
                            JSON.stringify({

                                text:
                                    text,

                                status_url:
                                    state.mastodonRoot.url,

                                article_url:
                                    CONFIG.articleURL
                            })
                    }
                );


            const data =
                await readJsonResponse(response);


            if (
                !response.ok ||
                !data.ok
            ) {

                throw new Error(
                    data.error ||
                    `Erreur HTTP ${response.status}`
                );
            }


            mastodonReply.value =
                "";


            mastodonCounter.textContent =
                "0 / 500";


            mastodonPublishStatus
                .className =
                    "status-message success";


            mastodonPublishStatus
                .textContent =

                "✓ Réponse publiée. Actualisation de la discussion…";


            await new Promise(
                resolve =>
                    setTimeout(
                        resolve,
                        1800
                    )
            );


            await loadMastodon();


            mastodonPublishStatus
                .textContent =

                "✓ Réponse publiée et discussion actualisée.";


        } catch(error) {

            console.error(error);


            mastodonPublishStatus
                .className =
                    "status-message error";


            mastodonPublishStatus
                .textContent =

                "ERREUR : " +
                error.message;


        } finally {

            mastodonPublish.disabled =
                false;
        }
    }
);


/* ==========================================================
   ATPROTO : INTERFACE
   ========================================================== */

const atprotoConnectionStatus =
    getWidgetElement(
        "atproto-connection-status"
    );

const atprotoLogin =
    getWidgetElement(
        "atproto-login"
    );

const atprotoHandle =
    getWidgetElement(
        "atproto-handle"
    );

const atprotoLoginButton =
    getWidgetElement(
        "atproto-login-button"
    );

const atprotoConnectedAccount =
    getWidgetElement(
        "atproto-connected-account"
    );

const atprotoConnectedAvatar =
    getWidgetElement(
        "atproto-connected-avatar"
    );

const atprotoConnectedName =
    getWidgetElement(
        "atproto-connected-name"
    );

const atprotoConnectedHandle =
    getWidgetElement(
        "atproto-connected-handle"
    );

const atprotoLogout =
    getWidgetElement(
        "atproto-logout"
    );

const atprotoCompose =
    getWidgetElement(
        "atproto-compose"
    );

const atprotoReply =
    getWidgetElement(
        "atproto-reply"
    );

const atprotoCounter =
    getWidgetElement(
        "atproto-counter"
    );

const atprotoPublish =
    getWidgetElement(
        "atproto-publish"
    );

const atprotoPublishStatus =
    getWidgetElement(
        "atproto-publish-status"
    );


function resetAtprotoInterface() {

    atprotoConnectedAccount.hidden =
        true;


    atprotoConnectedAvatar.hidden =
        true;


    atprotoConnectedName.textContent =
        "";


    atprotoConnectedHandle.textContent =
        "";


    atprotoCompose.hidden =
        true;


    atprotoLogin.hidden =
        false;
}


/* ==========================================================
   ATPROTO : PROFIL PUBLIC DIRECT DEPUIS LE PDS
   ========================================================== */

async function loadAtprotoProfileFromRepo(did) {

    const response =
        await fetch(
            commentsEndpoint("atproto/profile.php"),
            {
                method:
                    "POST",

                credentials:
                    "same-origin",

                cache:
                    "no-store",

                headers: {

                    "Content-Type":
                        "application/json",

                    "X-Federated-Comments-Request":
                        "1"
                },

                body:
                    JSON.stringify({
                        did
                    })
            }
        );


    const data =
        await readJsonResponse(
            response
        );


    if (
        !response.ok ||
        !data.ok ||
        !data.profile
    ) {

        throw new Error(
            data.error ||
            `Erreur HTTP ${response.status}`
        );
    }


    return data.profile;
}


/* ==========================================================
   ATPROTO : INITIALISATION
   ========================================================== */

async function initialiseAtproto() {

    resetAtprotoInterface();


    atprotoConnectionStatus
        .className =
            "status-message";


    atprotoConnectionStatus
        .textContent =
            "Initialisation ATProto…";


    if (!window.FederatedCommentsATProto) {

        if (atprotoOAuthCallback) {
            removeStorage(RETURN_STORAGE_KEY);
        }

        atprotoConnectionStatus
            .className =
                "status-message error";


        atprotoConnectionStatus
            .textContent =

                "ERREUR : atproto-oauth.js n’est pas chargé.";


        return;
    }


    let result;


    try {

        result =
            await window
                .FederatedCommentsATProto
                .init();


    } catch(error) {

        console.error(error);


        resetAtprotoInterface();

        if (atprotoOAuthCallback) {
            removeStorage(RETURN_STORAGE_KEY);
        }


        atprotoConnectionStatus
            .className =
                "status-message error";


        atprotoConnectionStatus
            .textContent =

                "ERREUR OAuth : " +
                error.message;


        return;
    }


    if (
        !result ||
        !result.connected
    ) {

        if (atprotoOAuthCallback) {
            removeStorage(RETURN_STORAGE_KEY);
        }

        resetAtprotoInterface();


        atprotoConnectionStatus
            .textContent =

                "";


        return;
    }

    if (atprotoOAuthReturn) {
        const returnTo = takeAtprotoReturn();
        if (returnTo && window.location.origin === SITE_ORIGIN) {
            window.location.replace(returnTo);
            return;
        }
    }


    /*
     * Si une session ATProto vient d'être restaurée après
     * OAuth, on force l'affichage de l'onglet ATProto.
     */

    if (
        readStorage(
            PROTOCOL_STORAGE_KEY
        ) === "atproto"
    ) {

        showAtprotoPanel();
    }


    atprotoLogin.hidden =
        true;


    atprotoConnectedAccount.hidden =
        false;


    atprotoCompose.hidden =
        false;


    atprotoConnectedName.textContent =
        "Compte ATProto connecté";


    atprotoConnectedHandle.textContent =
        result.did || "";


    atprotoConnectionStatus
        .textContent =
            "Session ATProto active.";


    try {

        const profile =
            await window
                .FederatedCommentsATProto
                .profile();


        const atprotoAvatarURL =
            safeHttpsURL(profile.avatar);

        if (atprotoAvatarURL) {

            atprotoConnectedAvatar.replaceChildren();
            const atprotoAvatarImage = document.createElement("img");
            atprotoAvatarImage.src = atprotoAvatarURL;
            atprotoAvatarImage.alt = "";
            atprotoAvatarImage.width = 38;
            atprotoAvatarImage.height = 38;
            atprotoConnectedAvatar.appendChild(atprotoAvatarImage);


            atprotoConnectedAvatar.hidden =
                false;
        }


        atprotoConnectedName.textContent =

            profile.displayName
            ||
            `@${profile.handle}`;


        atprotoConnectedHandle.textContent =
            `@${profile.handle}`;


        atprotoConnectionStatus
            .className =
                "status-message success";


        atprotoConnectionStatus
            .textContent =
                "✓ Connecté avec ATProto.";


    } catch(error) {

        console.warn(
            "Profil via l’AppView indisponible, lecture directe du PDS :",
            error
        );


        try {

            const profile =
                await loadAtprotoProfileFromRepo(
                    result.did
                );


            const atprotoAvatarURL =
                safeHttpsURL(
                    profile.avatar
                );


            if (atprotoAvatarURL) {

                atprotoConnectedAvatar.replaceChildren();

                const atprotoAvatarImage =
                    document.createElement("img");

                atprotoAvatarImage.src =
                    atprotoAvatarURL;

                atprotoAvatarImage.alt =
                    "";

                atprotoAvatarImage.width =
                    38;

                atprotoAvatarImage.height =
                    38;

                atprotoConnectedAvatar.appendChild(
                    atprotoAvatarImage
                );


                atprotoConnectedAvatar.hidden =
                    false;
            }


            atprotoConnectedName.textContent =

                profile.displayName
                ||
                (
                    profile.handle
                        ? `@${profile.handle}`
                        : "Compte ATProto connecté"
                );


            atprotoConnectedHandle.textContent =

                profile.handle
                    ? `@${profile.handle}`
                    : result.did;


            atprotoConnectionStatus
                .className =
                    "status-message success";


            atprotoConnectionStatus
                .textContent =
                    "✓ Connecté avec ATProto.";


        } catch(fallbackError) {

            console.warn(
                "Profil ATProto public indisponible :",
                fallbackError
            );


            /*
             * Le profil reste purement décoratif :
             * la publication demeure disponible avec la session OAuth.
             */

            atprotoConnectionStatus
                .className =
                    "status-message success";


            atprotoConnectionStatus
                .textContent =

                    "✓ Connecté avec ATProto.";
        }
    }
}


/* ==========================================================
   ATPROTO : CONNEXION
   ========================================================== */

atprotoLoginButton.addEventListener(
    "click",
    async () => {

        const handle =
            atprotoHandle
                .value
                .trim();


        if (!handle) {

            atprotoConnectionStatus
                .className =
                    "status-message error";


            atprotoConnectionStatus
                .textContent =

                "Indiquez votre identifiant ATProto.";


            return;
        }


        atprotoLoginButton.disabled =
            true;


        atprotoConnectionStatus
            .className =
                "status-message";


        atprotoConnectionStatus
            .textContent =
                "Connexion à ATProto…";


        /*
         * IMPORTANT :
         *
         * L'information est enregistrée AVANT que login()
         * redirige le navigateur vers le serveur OAuth.
         *
         * sessionStorage survit à cette navigation et permet
         * donc de rouvrir ATProto au retour sur test.html.
         */

        writeStorage(
            PROTOCOL_STORAGE_KEY,
            "atproto"
        );

        rememberAtprotoReturn();


        try {

            await window
                .FederatedCommentsATProto
                .login(handle);


        } catch(error) {

            console.error(error);

            removeStorage(RETURN_STORAGE_KEY);


            atprotoConnectionStatus
                .className =
                    "status-message error";


            atprotoConnectionStatus
                .textContent =

                "ERREUR : " +
                error.message;


            atprotoLoginButton.disabled =
                false;
        }
    }
);


/* ==========================================================
   ATPROTO : DÉCONNEXION
   ========================================================== */

atprotoLogout.addEventListener(
    "click",
    async () => {

        atprotoLogout.disabled =
            true;


        atprotoConnectionStatus
            .className =
                "status-message";


        atprotoConnectionStatus
            .textContent =
                "Déconnexion…";


        try {

            await window
                .FederatedCommentsATProto
                .logout();


            resetAtprotoInterface();


            atprotoConnectionStatus
                .textContent =

                "";


        } catch(error) {

            console.error(error);


            atprotoConnectionStatus
                .className =
                    "status-message error";


            atprotoConnectionStatus
                .textContent =

                "ERREUR : " +
                error.message;


        } finally {

            atprotoLogout.disabled =
                false;
        }
    }
);


/* ==========================================================
   ATPROTO : COMPTEUR
   ========================================================== */

atprotoReply.addEventListener(
    "input",
    () => {

        atprotoCounter.textContent =

            `${atprotoReply.value.length} / 300`;
    }
);


/* ==========================================================
   ATPROTO : PUBLICATION
   ========================================================== */

atprotoPublish.addEventListener(
    "click",
    async () => {

        atprotoPublishStatus
            .className =
                "status-message";


        const text =
            atprotoReply
                .value
                .trim();


        if (!text) {

            atprotoPublishStatus
                .className =
                    "status-message error";


            atprotoPublishStatus
                .textContent =

                "Écrivez d’abord une réponse.";


            return;
        }


        if (
            !state.blueskyRoot ||
            !state.blueskyRoot.uri ||
            !state.blueskyRoot.cid
        ) {

            atprotoPublishStatus
                .className =
                    "status-message error";


            atprotoPublishStatus
                .textContent =

                "La publication ATProto associée à cet article n’a pas encore été trouvée.";


            return;
        }


        atprotoPublish.disabled =
            true;


        atprotoPublishStatus
            .textContent =
                "Publication en cours…";


        try {

            await window
                .FederatedCommentsATProto
                .publish(
                    text,
                    {

                        uri:
                            state.blueskyRoot.uri,

                        cid:
                            state.blueskyRoot.cid
                    }
                );


            atprotoReply.value =
                "";


            atprotoCounter.textContent =
                "0 / 300";


            atprotoPublishStatus
                .className =
                    "status-message success";


            atprotoPublishStatus
                .textContent =

                "✓ Réponse publiée. Actualisation de la discussion…";


            await new Promise(
                resolve =>
                    setTimeout(
                        resolve,
                        1800
                    )
            );


            await loadBluesky();


            atprotoPublishStatus
                .textContent =

                "✓ Réponse publiée et discussion actualisée.";


        } catch(error) {

            console.error(error);


            atprotoPublishStatus
                .className =
                    "status-message error";


            atprotoPublishStatus
                .textContent =

                "ERREUR : " +
                error.message;


        } finally {

            atprotoPublish.disabled =
                false;
        }
    }
);


/* ==========================================================
   RESTAURATION DE L'ONGLET APRÈS OAUTH
   ========================================================== */

const currentURL =
    new URL(
        window.location.href
    );


const mastodonOAuthReturn =
    currentURL.searchParams.get(
        "mastodon"
    ) === "connected";


if (mastodonOAuthReturn) {

    /*
     * Le callback PHP nous indique explicitement
     * qu'il s'agit d'un retour OAuth Mastodon.
     */

    writeStorage(
        PROTOCOL_STORAGE_KEY,
        "mastodon"
    );


    showMastodonPanel();


    /*
     * Nettoyage de ?mastodon=connected sans recharger.
     */

    currentURL.searchParams.delete(
        "mastodon"
    );


    history.replaceState(
        {},
        "",
        currentURL.pathname +
        currentURL.search +
        currentURL.hash
    );

} else {

    /*
     * Pour ATProto, le bundle OAuth consomme lui-même
     * les paramètres du callback.
     *
     * On utilise donc le protocole mémorisé avant
     * la redirection OAuth.
     */

    const rememberedProtocol =
        readStorage(
            PROTOCOL_STORAGE_KEY
        );


    if (
        rememberedProtocol ===
        "atproto"
    ) {

        showAtprotoPanel();

    } else {

        showMastodonPanel();
    }
}


/* ==========================================================
   LANCEMENT
   ========================================================== */

Promise.allSettled([

    loadMastodon(),

    loadBluesky()

]);


initialiseMastodon();

initialiseAtproto();

})();
