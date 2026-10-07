<!-- Souverain.ovh -->

# Licence du projet et bibliothèques tierces

Le code et la documentation propres au projet Souverain.ovh Commentaires sont distribués sous **0BSD**. Le texte complet est dans [LICENSE](LICENSE) ; la [version de référence](https://opensource.org/license/0bsd) est publiée par l’Open Source Initiative.

Vous pouvez utiliser, copier, modifier et redistribuer ce code pour tout usage, y compris commercial, sans demander d’autorisation à Souverain.ovh et sans obligation d’attribution ou de publication de vos modifications. Le logiciel est fourni sans garantie, conformément au texte de la licence.

Cette licence ne remplace pas celles des bibliothèques tierces. Le fichier `atproto-oauth.js` contient notamment `@atproto/oauth-client-browser`, `@atproto/api` et leurs dépendances. Leurs notices sont regroupées dans [THIRD-PARTY-NOTICES.txt](THIRD-PARTY-NOTICES.txt) et doivent accompagner la redistribution du bundle.

[BUILD-INFO.json](BUILD-INFO.json) liste les versions effectivement présentes dans le bundle. `package.json` et `package-lock.json` fixent les dépendances de reconstruction. La commande `npm run build` reconstruit le bundle et régénère les notices à partir des paquets installés ; Node.js et npm sont nécessaires uniquement pour cette reconstruction, pas pour héberger le module PHP.
