<!-- Souverain.ovh -->

🇫🇷 Français | [🇬🇧 English](INTEGRATION.en.md)

# Intégrer la discussion sous un article

Installez et vérifiez d’abord le service avec [INSTALLATION.md](INSTALLATION.md). Les exemples supposent `site_url = https://example.com` et `base_path = /_commentaires`.

Le bloc s’intègre dans une page HTML, qu’elle soit écrite à la main, produite par un CMS ou générée en fichiers statiques. Son service PHP doit rester exécutable sur un serveur public en HTTPS, à la **même origine que les articles** : même protocole, même nom d’hôte et même port. Un sous-domaine distinct ne convient pas à cette intégration.

Vous pouvez voir le module sous un article publié : [Génération LaSuite — exemple en ligne](https://souverain.ovh/generation-lasuite/#discussion). Utilisez les fichiers génériques de cette archive pour votre installation et renseignez votre propre configuration.

## Intégration HTML, CSS et JavaScript

Ouvrez [integration/article.html](integration/article.html). Cette page contient un article, le bloc complet de discussion et les ressources dans leur ordre de chargement. Elle sert de modèle à intégrer dans votre propre gabarit ; elle ne contient pas de données personnelles préconfigurées.

La feuille `integration/article.css` met en forme cette page d’exemple uniquement. Dans votre site, conservez les styles de votre thème pour l’article et utilisez `commentaires.css` pour le bloc de discussion.

1. Copiez les liens de ressources dans votre page, en remplaçant l’origine et le chemin par ceux de `config.php` :

   ```html
   <link rel="stylesheet" href="https://example.com/_commentaires/commentaires.css?v=0.2.1">
   <script src="https://example.com/_commentaires/config-public.php" defer></script>
   <script src="https://example.com/_commentaires/atproto-oauth.js?v=0.2.1" defer></script>
   <script src="https://example.com/_commentaires/commentaires.js?v=0.2.1" defer></script>
   ```

2. Copiez **toute la section** `id="discussion"` de l’exemple, avec ses sous-sections, champs et boutons, sous votre article. Conservez les identifiants internes. Un conteneur vide ne suffit pas : le JavaScript attend le DOM complet.
3. Faites produire par votre gabarit l’URL canonique publique de chaque article dans cet attribut :

   ```html
   <section id="discussion" class="souverain-discussion"
            aria-labelledby="discussion-title"
            data-article-url="https://example.com/mon-article/">
     <!-- Tous les éléments de la section fournie dans integration/article.html. -->
   </section>
   ```

   Ce fragment montre seulement le conteneur ; utilisez le contenu complet de l’exemple.

4. Gardez **une seule discussion par page**. Ne copiez pas un second `<html>`, `<head>`, `<body>` ou `<main>` dans votre page. Le bloc fourni utilise des titres h2, h3 et h4, sous le h1 de votre article.
5. Conservez l’ordre des trois scripts avec `defer`. Ne leur ajoutez pas `async`. Si votre outil optimise ou regroupe les scripts, assurez-vous qu’il préserve cet ordre et l’emplacement du bundle OAuth.
6. Publiez une page de test, puis vérifiez lecture, connexion, retour à l’article et publication depuis l’origine publique.

`config-public.php` est indispensable : il fournit la configuration publique au JavaScript. Gardez ce fichier, le bundle OAuth et `commentaires.js` issus du même paquet, avec les endpoints PHP installés à l’emplacement défini par `base_path`.

`data-article-url` doit utiliser la même origine HTTPS que `site_url`, avec le vrai chemin public de l’article. N’utilisez ni `localhost`, ni l’URL du module, ni une URL avec des paramètres de suivi. Une balise `<link rel="canonical">` ne remplace pas cet attribut.

Chaque article doit avoir un chemin distinct, par exemple `/mon-article/`. Les URL dont l’identité dépend d’une requête, comme `/?p=42`, sont refusées : elles ne sont pas converties silencieusement en `/`. Les paramètres de suivi `utm_*`, `fbclid` et `gclid` sont ignorés.

Les URL absolues sont particulièrement utiles lorsque le HTML est généré sur une machine locale : elles doivent viser le service public et rester correctes dans le résultat exporté.

## Gabarit d’un CMS ou d’un générateur

1. Créez un composant ou un fragment de gabarit contenant **la section complète** de [integration/article.html](integration/article.html), puis appelez-le après le contenu de chaque article public concerné.
2. Remplacez la valeur fixe de `data-article-url` par l’URL canonique publique fournie par votre CMS ou votre générateur. Utilisez son mécanisme d’échappement pour les attributs HTML. La syntaxe de variable dépend de votre outil ; la valeur rendue doit être une URL complète telle que `https://example.com/mon-article/`.
3. Si la production du site se fait en local ou sur une adresse de prévisualisation, configurez le gabarit avec l’origine et le chemin qui seront réellement publiés. Par exemple, une source locale `http://localhost:8080/apercu/blog/article/` peut produire `https://example.com/blog/article/` si `/apercu` n’existe pas sur le site public. Vérifiez cette correspondance dans le HTML final et conservez un chemin distinct pour chaque article.
4. Chargez les quatre ressources indiquées plus haut une seule fois sur les pages qui contiennent le bloc. Si le CMS possède une API de gestion des ressources, exprimez les dépendances dans cet ordre : configuration publique, bundle OAuth, script de discussion. Le résultat doit conserver cet ordre de chargement et ne pas utiliser `async`.
5. Placez `commentaires.css` après la feuille du site qui définit les variables CSS du module. Si vous ajoutez une feuille de personnalisation, chargez-la ensuite.

Le module n’ajoute pas automatiquement le bloc au site : c’est le gabarit qui produit son HTML. Il ne dépend pas du système de commentaires interne du CMS. Pour une page privée ou protégée, n’exportez pas le bloc et ses données dans une page publique.

## Génération statique et déploiement

Deux opérations distinctes sont nécessaires :

1. **Exclure le service de l’export.** Le générateur ne doit pas convertir les endpoints PHP de `/_commentaires/` en fichiers statiques, ni y enregistrer les réponses d’une session. Il doit conserver dans les articles leurs liens vers le service public. Configurez les exclusions et la conservation des URL selon votre version de l’outil, puis inspectez une page exportée.
2. **Préserver le dossier sur le serveur.** Une exclusion de génération ne protège pas automatiquement le dossier public lors d’une synchronisation FTP/SFTP ou d’un déploiement qui supprime les fichiers absents de l’export. Réglez aussi votre déploiement pour ne jamais supprimer ni écraser `/_commentaires/` avec l’export statique.

Installez d’abord le service PHP public. Exportez ensuite les articles, contrôlez leur `data-article-url` et les quatre URL de ressources, puis déployez le HTML en préservant le dossier du module.

Un aperçu local aide à vérifier le HTML et la présentation. La session et le parcours OAuth doivent être essayés sur le domaine HTTPS public, car l’origine locale ne partage pas les cookies et protections du service. Un hébergement qui ne sert que des fichiers statiques ne suffit pas à faire fonctionner les endpoints PHP : conservez un service PHP exécutable sous la même origine publique que les articles.

## Retours OAuth

Gardez les endpoints et `test.html` du module à leur emplacement configuré.

- Mastodon retourne sur `mastodon/callback.php`. Le serveur vérifie le retour avant de renvoyer le visiteur vers l’article mémorisé et autorisé. Sans destination mémorisée, il revient à `test.html`.
- ATProto utilise les métadonnées de `oauth-client-metadata.php`, qui déclarent `test.html` comme page de retour. Le SDK traite le fragment OAuth sur cette page ; après un retour réussi, le JavaScript reprend l’article mémorisé dans le même onglet.
- La mémoire de retour ATProto est temporaire, pour une durée maximale de 30 minutes. Une visite ordinaire de `test.html` reste une démonstration et ne doit pas renvoyer le lecteur vers une ancienne page.
- `demo_article_url` peut être vide sans empêcher le traitement du retour OAuth. Si aucun retour d’article valide n’est mémorisé, la page traite tout de même la connexion et invite le visiteur à retourner à son article, sans charger un fil de démonstration.

Ne remplacez pas la page de retour par chaque URL d’article dans les métadonnées. Il n’est pas nécessaire de recompiler le bundle pour personnaliser le domaine et le chemin : utilisez `config.php` et les ressources fournies ensemble.

## Personnaliser la présentation

Le module utilise `.souverain-discussion` comme racine. Toutes ses règles doivent rester limitées à ce bloc ; des règles globales `body` ou `.post` peuvent sinon modifier le thème de votre site.

La version fournie reprend les choix du module intégré : texte sérif, tailles de lecture de 22–24 px sur ordinateur et 20 px jusqu’à 740 px, bloc intégré aligné à gauche sur la largeur disponible. La démonstration autonome garde une largeur de lecture limitée.

Les variables suivantes permettent au thème de transmettre ses choix, avec des valeurs de secours dans le module :

```css
/* Exemple à adapter dans la feuille du site. */
:root {
  --font-text: Georgia, serif;
  --ink: #2c2c2b;
  --secondary: #595752;
  --rule: #d8d0c5;
  --sidebar: #eef1f4;
  --paper: #fffdf9;
}
```

Le module n’ajoute pas de fichiers de polices : une police personnalisée doit être installée ou chargée par votre site. Pour d’autres tailles, ajoutez une feuille de personnalisation chargée après `commentaires.css` plutôt que de disperser les corrections dans plusieurs fichiers. Gardez les titres distincts du corps et les métadonnées plus discrètes ; contrôlez aussi le mobile et les formulaires.

Une modification du seul CSS public ne nécessite pas de nouveau thème ni de nouvel export si les pages pointent déjà sur ce fichier. Purgez le cache à cette même URL. Pour changer le numéro de version dans les liens de ressources, republiez les pages qui contiennent ces liens.
