<?php
// Souverain.ovh

declare(strict_types=1);

/*
 * Federated Comments — configuration
 *
 * Modifiez uniquement les valeurs de ce fichier pour adapter le projet
 * à votre site. Aucun secret n'est stocké ici.
 */

return [

    /*
     * Origine publique HTTPS du site, sans slash final ni chemin.
     * Exemple : https://example.com
     */
    'site_url' => 'https://example.com',

    /*
     * Chemin public du dossier qui contient ce projet, éventuellement imbriqué.
     * Lettres ASCII, chiffres, tirets, _, . et ~ ; aucun segment . ou .. ni %.
     * Exemple : /_commentaires
     */
    'base_path' => '/_commentaires',

    /*
     * Nom affiché lors des autorisations OAuth.
     */
    'app_name' => 'Federated Comments',

    /*
     * Nom du cookie de session PHP, propre à cette installation.
     * Commencer par une lettre ; au plus 64 lettres, chiffres, _ ou -.
     */
    'session_name' => 'federated_comments',

    /*
     * URL HTTPS d’un article sur site_url, utilisée par test.html.
     * Le système cherche sur Mastodon et Bluesky la publication qui
     * contient cette URL.
     */
    'demo_article_url' => 'https://example.com/mon-article/',

    /*
     * Nombre maximal de publications originales à parcourir pour
     * retrouver l'article.
     */
    'max_posts_to_search' => 1000,

    'mastodon' => [

        /*
         * Origine HTTPS du serveur Mastodon du compte qui publie les articles.
         * Port 443 uniquement, sans chemin ni identifiants.
         */
        'instance' => 'https://mastodon.social',

        /*
         * Nom d'utilisateur du compte qui publie les articles,
         * sans @ ni nom d'instance.
         */
        'username' => 'votre_compte'
    ],

    'bluesky' => [

        /*
         * Handle ATProto/Bluesky du compte qui publie les articles.
         */
        'handle' => 'votre-compte.bsky.social',

        /*
         * Origine HTTPS de l’AppView publique utilisée pour lire le fil.
         */
        'api' => 'https://public.api.bsky.app',

        'page_size' => 100
    ]
];
