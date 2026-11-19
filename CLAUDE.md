# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

Release Note Manager : application Symfony 7.4 LTS (PHP 8.2, plateforme figée à 8.2.0 dans `composer.json`) qui importe les tickets d'une Fix Version Jira (Cloud et Server/DC), aide à rédiger trois release notes (IT interne, Fonctionnel, Communication) et un PV de recette, puis les publie dans Confluence (Cloud et Server/DC) ou les envoie par e-mail. Usage **local** sur un poste Windows (EasyPHP + MySQL), **sans authentification**, comme WSUse. Tout le code, les commentaires, l'interface, les messages de commit et le README sont **en français** : garder cette langue.

## Commandes

```bash
composer install
php bin/console doctrine:migrations:migrate -n
php bin/console cache:clear
php -S 127.0.0.1:8099 -t public            # serveur local (pas de routeur index.php : il casse les assets)
```

Tests et contrôles (pas de CI : les lancer tous avant de livrer) :

```bash
vendor/bin/phpunit                                        # toute la suite (pas de bin/phpunit)
vendor/bin/phpunit tests/Integration/ReleaseRefreshServiceTest.php
vendor/bin/phpunit --filter testNomDuTest
php bin/console lint:twig templates
php bin/console lint:container
php bin/console doctrine:schema:validate                  # sur MySQL : entités ↔ migrations
```

PHPUnit échoue sur les avertissements et les tests « risky ».

## Architecture

**Symfony sans Flex** : configuration écrite à la main (`config/`, `public/index.php`, `bin/console` classiques), aucune recette. Ajouter un bundle = `config/bundles.php` + son fichier dans `config/packages/`.

**Jira (`src/Service/Jira`)** : Cloud = API v3 (`POST /rest/api/3/search/jql`, pagination `nextPageToken`, ADF converti en texte), Server/DC = API v2 (`GET /rest/api/2/search`, `startAt`). La JQL d'une version exclut les sous-tâches. Epic via `parent` ou champ Epic Link configurable. Couverture de tests via l'extension Jira Octane (`OctaneCoverageService`, appels en parallèle).

**Appels HTTP** : toujours via `Http/AtlassianApiClient` (secret déchiffré à la volée, jamais journalisé, rejeu sur 429 avec `Retry-After`, `getMany` pour le parallèle, journal `integration`). Ne pas appeler `HttpClientInterface` directement.

**Release notes (`src/Service/ReleaseNote`)** : import → `SectionBuilder` (regroupement Epic → étiquette convertie (`LabelMapping`) → type (`IssueTypeMapping`)) ; actualisation **non destructive** (`ReleaseRefreshService` : plan présenté puis appliqué avec les mêmes données Jira, mémorisées 15 min par `JiraSnapshotStore` ; wording jamais modifié ; tickets classés à la main jamais déplacés). `ReleaseNoteViewBuilder` → rendus web (`templates/render/web`), Confluence storage (`templates/render/confluence`, macros natives, XHTML validé par `assertWellFormed`), e-mail (`templates/render/email`, tableaux + styles en ligne pour Outlook).

**Texte saisi** : `TextFormatter` (filtres Twig `rn_text`, `rn_inline`) échappe tout puis applique une syntaxe légère et lie les clés de tickets Jira connues. Le statut « sûr » d'un filtre Twig est décidé **à la compilation** : une valeur passée en argument de macro n'est plus vue comme sûre ; pour l'e-mail, utiliser `rn_email_text` (texte brut) plutôt que d'enchaîner les filtres.

**Charte** : couleurs du format Communication et de l'e-mail définies une seule fois dans `ReleaseNote/Design/CommunicationTheme.php` (variables CSS `--ds-*` côté web, paramètres des macros côté Confluence).

**Publication (`src/Service/Publication`, `Confluence`)** : page parente du projet → page d'année de MEP (`<Projet> — <Année>`, retrouvée par titre, déplacée si la page parente change) → page de regroupement de la version → pages de format. Pas de doublon : identifiant mémorisé, puis recherche par titre, création en dernier recours ; conflit 409 relu et rejoué.

**Écrans sensibles** : `LocalUpdate` (mise à jour depuis le zip GitHub puis composer / migrations / cache, repris de WSUse) et `Backup` ne sont accessibles qu'en local (`EventSubscriber/LocalOnlyListener`, `/updates` et `/backups`). Réglages `RNM_UPDATE_*` dans `.env`. Les secrets Jira/Confluence sont chiffrés (libsodium, `APP_ENCRYPTION_KEY`).

**Migrations** : dans `migrations/` (namespace `DoctrineMigrations`), générées avec `doctrine:migrations:diff` puis reprises à la main : description en français, garde `abortIf` « Cette migration cible MySQL/MariaDB. », `isTransactional(): false`. Les tester **up / down / up** sur un vrai MySQL. Index : colonnes texte indexées **≤ 150 caractères** (limite de 767 octets en utf8mb4 sur le serveur de l'utilisateur, format COMPACT).

## Tests

- `tests/Support/DatabaseTestCase` (services) et `WebTestCaseBase` (HTTP, `disableReboot()`) recréent la base SQLite `var/test.db` à chaque test.
- **Aucun appel réseau** : `test.mock_http_client` répond via `tests/Support/FakeAtlassianServer` (`->on('GET', '/regex/chemin', fn (array $json, string $url, array $query) => [...])`, requêtes reçues dans `->requests`). `JiraFixtures` fournit tickets, versions et parents.
- Un bug signalé se reproduit d'abord par un test qui échoue, puis se corrige. Pour les volumes et les appels Jira, compter les requêtes envoyées (`requestsMatching`).
- Écran modifié : vérifier aussi dans un navigateur (Playwright est installé : `require('playwright')`, `chromium.launch()`), en mode `prod` si le problème signalé peut en dépendre, et envoyer une capture.

## Documentation

`README.md` est le manuel utilisateur (installation EasyPHP, Jira, Confluence, chaque écran, chaque réglage). Le mettre à jour quand un comportement visible ou un réglage change.

## Façon de travailler avec l'utilisateur

- **Réponses en français**, concises : ce qui a été fait, ce qui a été vérifié (et ce qui n'a pas pu l'être), puis **comment mettre à jour** chez lui : écran *Mises à jour*, ou `git pull`, `composer install`, `php bin/console doctrine:migrations:migrate`, `php bin/console cache:clear` ; dire explicitement s'il y a une migration ou non.
- Les messages sont courts et parfois avec fautes de frappe (« j'irai », « jura » = Jira) : interpréter selon le contexte. Contexte : Jira Server/DC et Confluence Server d'entreprise (proxy, CA interne), Jira Cloud et Confluence Cloud aussi.
- **Git** : sur ce dépôt, l'utilisateur a validé de **committer et pousser directement sur `main`** une fois les tests passés. Messages de commit en français, titre court puis détail, terminés par les lignes d'attribution de la session.
- Ne jamais committer de secret (`.env.local` est ignoré) ni l'adresse e-mail de l'utilisateur ailleurs que dans l'identité de commit.
- Conteneur cloud éphémère : `vendor/`, MySQL (`service mysql start`, base `release_notes`, utilisateur `rnm`) et `.env.local` peuvent avoir disparu d'une session à l'autre ; les recréer avant de tester.
