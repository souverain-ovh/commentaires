<!-- Souverain.ovh -->

![Commentaires — ActivityPub / Mastodon + ATProto / Bluesky](assets/commentaires-banner.jpg)

🇫🇷 Français | [🇬🇧 English](README.en.md)

# Souverain.ovh Commentaires — 0.2.1

Un module de commentaires Mastodon et Bluesky / ATProto pour les articles d’un site statique ou classique.

L’article reste sur votre site. Ses discussions restent sur les réseaux sociaux : le module retrouve les publications contenant son URL publique, récupère leurs réponses et les présente dans un même fil. Les visiteurs peuvent lire sans compte. Pour répondre, ils utilisent leur compte Mastodon ou ATProto ; leur message est publié sur le réseau choisi.

Le module s’intègre à des pages HTML, à un site statique ou au gabarit d’un CMS, sans dépendre d’un outil de publication particulier. Il ne nécessite pas de base de données de commentaires ; il utilise des sessions PHP et du stockage navigateur pour l’authentification.

**Version expérimentale, distribuée avec une page de test, un exemple HTML complet et les instructions d’installation.**

## Exemple en ligne

**[Voir le module sous l’article « Génération LaSuite »](https://souverain.ovh/generation-lasuite/#discussion).** Le lien mène directement au bloc « Discussion », situé sous l’article.

Cet exemple montre les réponses Mastodon et Bluesky / ATProto réunies dans un même affichage, avec leur réseau d’origine, puis les deux options pour participer depuis la page. La lecture est accessible sans compte ; la publication demande de se connecter au réseau choisi.

Cette page publique illustre le rendu du module. Pour votre propre installation, renseignez votre domaine, vos comptes et vos articles dans `config.php` ; les fichiers fournis gardent des valeurs génériques.

## Commencer

1. Vérifiez que votre hébergement public fournit HTTPS, PHP 8.1+, cURL et les sessions PHP.
2. Copiez le dossier `_commentaires` de l’archive à la racine publique du site, avec ses fichiers cachés.
3. Renseignez votre domaine, vos comptes et votre article de test dans `config.php`.
4. Publiez l’URL de cet article sur les comptes configurés et ouvrez `/_commentaires/test.html`.
5. Intégrez le bloc complet dans votre gabarit d’article.

Suivez [INSTALLATION.md](INSTALLATION.md) pour les valeurs exactes et les vérifications, puis [INTEGRATION.md](INTEGRATION.md) pour placer le bloc dans une page ou un gabarit d’article. [integration/article.html](integration/article.html) fournit une page complète à adapter.

## Ce qui doit rester dynamique

Le HTML de vos articles peut être statique. Les fichiers PHP du dossier `_commentaires` doivent continuer à être exécutés par l’hébergement public, sur **la même origine HTTPS que les articles** : même protocole, nom d’hôte et port.

Un hébergement limité aux fichiers statiques ne suffit pas pour le module complet. L’archive n’inclut pas d’adaptation à un service sur un autre domaine. Vous pouvez produire les pages avec l’outil de votre choix ; les fichiers PHP du module doivent être exécutés sur l’hébergement public.

## Configuration et fichiers

| Fichier | Rôle |
| --- | --- |
| `config.php` | Domaine, comptes et paramètres propres à votre installation. |
| `config.example.php` | Exemple générique de configuration. |
| `config-public.php` | Paramètres publics JavaScript générés à partir de `config.php`. |
| `oauth-client-metadata.php` | Métadonnées OAuth ATProto générées pour votre domaine et votre chemin. |
| `test.html` | Démonstration et page de retour OAuth ATProto ; à conserver en ligne. |
| `commentaires.css` / `commentaires.js` | Présentation et fonctionnement du bloc. |
| `atproto-oauth.js` | Bundle ATProto fourni, utilisable sans compilation. |
| `mastodon/` / `atproto/` | Endpoints PHP publics du service. |
| `.htaccess`, `lib.php`, `securite.php` | Configuration Apache et fonctions partagées. |
| `tests/` | Vérifications locales en ligne de commande ; accès HTTP interdit par son `.htaccess`. |

Le navigateur reçoit `window.FederatedCommentsConfig` et le SDK expose `window.FederatedCommentsATProto`. Aucun secret OAuth personnel n’est à mettre dans le HTML ou dans `config.php`.

## Présentation

Les règles CSS sont limitées à `.souverain-discussion`, pour préserver la page qui l’accueille. Le bloc intégré s’aligne à gauche et peut occuper la largeur de l’article. Les textes de lecture utilisent 22–24 px sur ordinateur et 20 px sur mobile ; les titres, boutons et métadonnées gardent une hiérarchie distincte.

Les variables de thème `--font-text`, `--ink`, `--secondary`, `--rule`, `--sidebar` et `--paper` sont réutilisées lorsqu’elles existent, avec des valeurs de secours. Voir [INTEGRATION.md](INTEGRATION.md#personnaliser-la-présentation) pour les adapter à un autre site.

## Limites

- Chaque réseau a besoin d’une publication racine publique, sur le compte configuré, contenant l’URL canonique de l’article. Le module ne crée pas ces publications.
- Les articles doivent avoir des URL distinctes par leur chemin, par exemple `/mon-article/`. Les permaliens qui distinguent les articles par une requête comme `/?p=42` ne sont pas pris en charge. Les paramètres de suivi `utm_*`, `fbclid` et `gclid` sont ignorés.
- Les fils sont indépendants. Leur affichage commun ne transforme pas une réponse Mastodon en réponse Bluesky, ni l’inverse.
- La recherche parcourt l’historique à chaque chargement, sans index local ni cache de fils. La limite par défaut est de 1 000 publications originales ; les anciens articles peuvent ne pas être retrouvés et la recherche peut être lente.
- Les données disponibles dépendent des API, des limites de requêtes, de la fédération et de la visibilité des messages. Le fil affiché n’est pas une archive exhaustive garantie.
- Les comptes privés, les serveurs accessibles uniquement sur un réseau local et une intégration entre plusieurs origines ne sont pas des configurations prises en charge.
- Cette distribution a fait l’objet de vérifications locales de syntaxe, de présentation et de scénarios simulés, sans validation de bout en bout d’une connexion et d’une publication OAuth réelles. Elle ne vaut pas audit complet ni garantie de fonctionnement sur tout hébergement.

## Développement et reconstruction du bundle

Cette étape concerne uniquement les personnes qui modifient le SDK ou souhaitent reconstruire le fichier fourni. **Node.js et npm ne sont pas nécessaires sur l’hébergement PHP.**

Avec Node.js 22 ou plus récent et npm, ouvrez un terminal dans le dossier décompressé `_commentaires`, puis exécutez :

```sh
npm ci
npm test
npm run build
```

`npm ci` installe les versions de `package-lock.json`. Les dépendances directes sont `@atproto/api` 0.22.0, `@atproto/oauth-client-browser` 0.5.8 et `esbuild` 0.28.2. La première installation nécessite l’accès au registre npm, sauf si les paquets sont déjà dans le cache local.

`npm test` exécute les scénarios frontend simulés avec Node.js. Pour les vérifications PHP, lancez séparément ces commandes dans le même dossier, avec PHP disponible en ligne de commande :

```sh
php tests/backend.php
php -d disable_functions=mb_strlen,mb_substr tests/backend.php
```

La seconde commande vérifie le fonctionnement sans les fonctions `mbstring` facultatives. Les tests PHP utilisent une configuration de fixture isolée : ils ne remplacent pas votre `config.php`. Ces tests s’exécutent en terminal, pas en ouvrant les fichiers dans le navigateur ; leur dossier comporte un `.htaccess` qui refuse l’accès HTTP.

Les scénarios simulent les services externes. Ils ne publient pas de commentaires et ne valident pas une connexion OAuth réelle.

`scripts/build.mjs` reconstruit `atproto-oauth.js` depuis `src/atproto/entry.js` et `src/atproto/oauth.js`. Il régénère également :

- [THIRD-PARTY-NOTICES.txt](THIRD-PARTY-NOTICES.txt), avec les licences des bibliothèques réellement intégrées au bundle ;
- [BUILD-INFO.json](BUILD-INFO.json), avec la version, la taille, l’empreinte SHA-256 du bundle et les versions de ses dépendances.

Le bundle de cette archive a été reconstruit depuis les sources livrées et les versions verrouillées. Il n’est pas présenté comme identique octet pour octet au bundle de l’ancienne archive. Deux reconstructions locales successives ont produit la même empreinte avec ces entrées. Après toute modification, vérifiez les fonctions OAuth et conservez les notices tierces lors de la redistribution.

Ne copiez pas `node_modules` sur l’hébergement. Pour l’installation normale, utilisez les fichiers déjà construits du paquet.

## Licence

Le code propre au projet est sous [licence 0BSD](LICENSE) : utilisation, copie, modification et redistribution permises sans autorisation préalable ni attribution obligatoire. Il est fourni sans garantie. Les dépendances tierces conservent leurs licences ; voir [LICENSE-NOTICE.md](LICENSE-NOTICE.md) et [THIRD-PARTY-NOTICES.txt](THIRD-PARTY-NOTICES.txt).
