<!-- Souverain.ovh -->

🇫🇷 Français | [🇬🇧 English](INSTALLATION.en.md)

# Installer Souverain.ovh Commentaires 0.2.1

Les exemples utilisent `https://example.com` et `/_commentaires`. Remplacez-les par votre origine publique et votre chemin. L’archive contient directement le dossier `_commentaires` à copier. Le module fonctionne avec des pages HTML, un site statique ou un CMS : les étapes ci-dessous ne dépendent d’aucun outil de publication particulier.

Pour voir le résultat avant de l’installer, ouvrez [l’exemple en ligne sous « Génération LaSuite »](https://souverain.ovh/generation-lasuite/#discussion).

## 1. Vérifier l’hébergement

Il faut :

- un domaine public servi en HTTPS, avec un certificat valide ;
- PHP 8.1 ou plus récent, l’extension cURL et les sessions PHP fonctionnelles ;
- un emplacement de stockage des sessions et un répertoire temporaire accessibles en écriture par PHP ;
- les connexions HTTPS sortantes et la résolution DNS nécessaires aux réseaux configurés ;
- Apache avec `.htaccess` autorisé, notamment `mod_headers` et les règles d’autorisation utilisées dans le fichier fourni ;
- un navigateur récent avec JavaScript, cookies et stockage local disponibles.

Le dossier du module et les articles doivent partager la même origine publique. `https://www.example.com` et `https://example.com` sont deux origines différentes : choisissez celle de vos articles et gardez-la partout.

Avec Nginx ou un autre serveur, faites traduire les protections du `.htaccess` par la personne qui administre l’hébergement. Ce fichier n’y est pas appliqué automatiquement. Ne publiez jamais les sources PHP comme de simples fichiers téléchargeables.

Node.js n’est pas nécessaire pour installer le module : le bundle ATProto est déjà fourni.

## 2. Copier le dossier

Décompressez l’archive, puis copiez **le dossier `_commentaires` entier** à la racine publique du domaine, par FTP/SFTP ou avec votre outil habituel. Selon l’hébergement, cette racine peut s’appeler `www`, `public_html` ou `htdocs`.

```text
RACINE_PUBLIQUE/
├── index.html
├── mon-article/
│   └── index.html
└── _commentaires/
    ├── .htaccess
    ├── config.php
    ├── test.html
    ├── commentaires.css
    ├── commentaires.js
    ├── atproto-oauth.js
    ├── oauth-client-metadata.php
    ├── mastodon/
    └── atproto/
```

Activez l’affichage des fichiers cachés dans votre client FTP : **`.htaccess` doit être transféré**. Placez le dossier sur l’hébergement public qui exécute PHP. Si vos articles sont générés en HTML, gardez le module hors de cette conversion et préservez son dossier lors du déploiement.

## 3. Modifier `config.php`

Pour une première installation, le fichier fourni est un exemple générique. Ouvrez-le et renseignez ces valeurs, en conservant sa syntaxe PHP :

| Clé | Valeur à renseigner |
| --- | --- |
| `site_url` | Origine HTTPS des articles, sans chemin : `https://example.com`. |
| `base_path` | Chemin public du module, sans slash final : `/_commentaires`. |
| `app_name` | Nom de l’application affiché lors des autorisations OAuth. |
| `session_name` | Nom de session propre à cette installation ; gardez la valeur fournie si elle convient. |
| `demo_article_url` | URL publique complète d’un article de test : `https://example.com/mon-article/`, ou chaîne vide `''` pour ne pas configurer d’article de démonstration. |
| `max_posts_to_search` | Nombre maximal de publications originales parcourues ; 1 000 par défaut. |
| `mastodon.instance` | Origine HTTPS de l’instance du compte qui partage vos articles, sur le port 443. |
| `mastodon.username` | Nom de ce compte, sans `@` ni nom d’instance. |
| `bluesky.handle` | Identifiant du compte qui partage vos articles, par exemple `alice.bsky.social`. |
| `bluesky.api` | Origine de l’AppView publique ; gardez `https://public.api.bsky.app` sauf besoin particulier. |
| `bluesky.page_size` | Taille des pages de recherche ; 100 par défaut. |

L’instance Mastodon de configuration est celle du **propriétaire du fil**. Un visiteur peut se connecter avec son compte sur une autre instance publique.

La configuration actuelle attend les paramètres des deux réseaux. Si aucune publication n’existe sur l’un d’eux, sa discussion ne sera pas disponible ; le module n’offre pas de bouton de configuration pour désactiver entièrement un protocole.

Aucun mot de passe de compte, jeton personnel ou secret OAuth n’est à saisir dans ce fichier. L’application Mastodon est enregistrée lors de la connexion ; les métadonnées ATProto sont générées par `oauth-client-metadata.php`.

Vous pouvez changer `base_path`, par exemple en `/discussion` ou `/outils/discussion`. Utilisez des segments ASCII simples, sans espace, `%`, `.` ou `..` seuls ni double slash. Renommez ou déplacez alors le dossier public et adaptez les URL des styles et scripts de vos pages d’intégration. Le nom de session commence par une lettre et comporte au plus 64 caractères parmi lettres, chiffres, `_` et `-`.

Il n’est pas nécessaire de recompiler `atproto-oauth.js` pour changer de domaine ou de chemin. Conservez `test.html` dans le dossier du module.

## 4. Préparer l’article de test et ses publications

1. Choisissez un article accessible publiquement en HTTPS.
2. Copiez son URL canonique exacte dans `demo_article_url`, sur la même origine que `site_url`.
3. Partagez cette même URL dans une publication publique originale sur votre compte Mastodon configuré.
4. Partagez-la également sur votre compte Bluesky configuré.
5. Faites une réponse à chaque publication depuis le réseau correspondant pour disposer de contenu à afficher.

Gardez le même chemin, y compris le slash final si votre site l’utilise. Utilisez l’URL de l’article, sans paramètres de suivi, raccourcisseur ni adresse de prévisualisation locale. Les réponses et boosts Mastodon ne servent pas de publications racines à cette recherche.

Les URL doivent distinguer les articles par leur chemin, comme `/mon-article/`. Les liens de type `/?p=42` ne sont pas pris en charge pour l’intégration. Les paramètres de suivi `utm_*`, `fbclid` et `gclid` sont ignorés, mais une requête servant d’identifiant d’article est refusée afin de ne pas mélanger plusieurs conversations.

Vous pouvez laisser `demo_article_url` vide si vous testez directement une page d’article intégrée. Une visite ordinaire de `test.html` indique alors qu’aucun article n’est configuré. Cela n’empêche pas cette page de traiter le retour OAuth ATProto : elle peut reprendre l’article temporairement mémorisé, ou inviter le visiteur connecté à retourner à son article.

## 5. Vérifier le service avant l’intégration

Ouvrez ces adresses dans un navigateur :

- `https://example.com/_commentaires/test.html` : la démonstration doit afficher son interface et rechercher l’article configuré.
- `https://example.com/_commentaires/config-public.php` : une réponse JavaScript doit définir `window.FederatedCommentsConfig` avec votre domaine et vos comptes publics.
- `https://example.com/_commentaires/oauth-client-metadata.php` : une réponse JSON doit contenir un `client_id` visant ce fichier et un retour vers `https://example.com/_commentaires/test.html`.
- `https://example.com/_commentaires/config.php` : un accès HTTP doit être refusé ; son contenu reste utilisable par les scripts PHP du serveur.

Il ne faut pas créer de fichier personnel `oauth-client-metadata.json`. Cette distribution utilise les métadonnées PHP configurables et son bundle fourni.

Sur la démonstration, vérifiez d’abord la lecture des réponses. Testez ensuite séparément la connexion et la publication avec un compte de test Mastodon, puis ATProto. Ces essais publient de vrais messages sur le réseau choisi : utilisez un fil prévu pour cela. Après publication, vérifiez aussi le message sur le réseau d’origine.

Les vérifications locales du paquet ne remplacent pas ces essais sur votre domaine et votre hébergement.

## 6. Intégrer puis mettre en ligne les articles

Suivez [INTEGRATION.md](INTEGRATION.md). Le module installé seul n’ajoute pas automatiquement de bloc sous les articles d’un site existant. L’exemple `integration/article.html` fournit le DOM complet à placer dans votre gabarit.

## Résoudre les premiers problèmes

| Symptôme | Vérification utile |
| --- | --- |
| Erreur HTTP 500 | Journaux PHP/Apache, syntaxe de `config.php`, version PHP, cURL, permissions sessions/temporaire et autorisation des directives `.htaccess`. |
| Code PHP affiché ou téléchargé | Arrêtez la mise en ligne et faites activer le traitement PHP sur ce dossier par l’hébergeur. |
| Discussion introuvable | URL canonique et slash final, bon compte, publication publique originale, ancienneté et `max_posts_to_search`. |
| Un réseau échoue | Message du module, disponibilité de son API, visibilité de la publication, limites de requêtes et connexions sortantes. |
| Connexion refusée depuis un aperçu local | Testez depuis l’origine HTTPS publique ; les protections de même origine sont attendues. |
| Retour OAuth erroné | Domaine et chemin de `config.php`, `test.html` toujours en ligne, cookies/stockage disponibles, bundle de cette version et métadonnées PHP accessibles. |
| Mauvaise présentation après remplacement CSS | URL réellement chargée, cache navigateur/CDN et ordre des feuilles de style du site. |

Si votre site applique sa propre Content Security Policy, elle doit autoriser les ressources du module et les connexions HTTPS nécessaires aux API et aux fournisseurs OAuth. Les en-têtes du dossier `_commentaires` ne remplacent pas ceux des pages d’article ; ne désactivez pas les protections sans déterminer la règle qui bloque.
