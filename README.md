# Release Note Manager

Application Symfony 7.4 LTS de production de release notes à partir de Jira, avec maîtrise
complète du wording, trois formats éditoriaux (IT interne, Fonctionnel, Communication) et
publication vers **Confluence Cloud** et **Confluence Server / Data Center** (jusqu'à 6 cibles).

- Connexions Jira / Confluence (Cloud et Server/DC) administrées depuis l'interface, secrets chiffrés.
- Import des tickets **de la seule Fix Version choisie**, pagination Cloud (`nextPageToken`) et Server (`startAt`).
- Regroupement automatique : une section par Epic, puis par type de ticket (correspondances administrables).
- Éditeur : sections manuelles, réordonnancement (glisser-déposer), déplacement / ajout / retrait de tickets,
  wording indépendant par format, aperçu immédiat.
- Actualisation depuis Jira **non destructive** (aucun texte éditorial ni classement modifié), avec plan préalable.
- Rendu web soigné + rendu au format de stockage Confluence (macros natives).
- Publication création/mise à jour sans doublon, statut par cible, historique, nouvelles tentatives, verrous.

---

## Sommaire

1. [Prérequis](#1-prérequis)
2. [Création de la base MySQL](#2-création-de-la-base-mysql)
3. [Installation des dépendances](#3-installation-des-dépendances)
4. [Configuration de `.env.local`](#4-configuration-de-envlocal)
5. [Clé de chiffrement](#5-clé-de-chiffrement)
6. [Migrations](#6-migrations)
7. [Token d'administration](#7-token-dadministration)
8. [Démarrage en local](#8-démarrage-en-local)
9. [Serveur web (Apache / EasyPHP)](#9-serveur-web-apache--easyphp)
10. [Jira Cloud](#10-jira-cloud)
11. [Jira Server / Data Center](#11-jira-server--data-center)
12. [Confluence Cloud](#12-confluence-cloud)
13. [Confluence Server / Data Center](#13-confluence-server--data-center)
14. [Test des connexions](#14-test-des-connexions)
15. [Import d'une première release](#15-import-dune-première-release)
16. [Publication et republication](#16-publication-et-republication)
17. [Tests](#17-tests)
18. [Diagnostic](#18-diagnostic)
19. [Proxy d'entreprise](#19-proxy-dentreprise)
20. [Architecture](#20-architecture)
21. [Modèle de données](#21-modèle-de-données)
22. [Sécurité](#22-sécurité)
23. [Limites connues](#23-limites-connues)

---

## 1. Prérequis

| Composant | Version |
|---|---|
| PHP | **8.2 minimum** (testé en 8.3) |
| Extensions PHP | `ctype`, `iconv`, `json`, `mbstring`, `sodium`, `pdo_mysql`, `intl` (recommandée), `curl` (recommandée), `pdo_sqlite` (tests) |
| Composer | 2.x |
| MySQL | 8.0+ (ou MariaDB 10.4+) |
| Serveur web | Apache 2.4 avec `mod_rewrite` (EasyPHP, WAMP, XAMPP…) ou serveur PHP intégré pour le développement |

Aucune chaîne de build front (Node, Webpack) n'est nécessaire : CSS et JavaScript sont servis tels quels
depuis `public/assets/`.

> **Symfony Flex n'est pas utilisé** : la configuration (`config/`) et les points d'entrée
> (`public/index.php`, `bin/console`) sont fournis complets. Cela évite toute réécriture de fichiers
> par des recettes et fonctionne même lorsque les plugins Composer sont interdits.

## 2. Création de la base MySQL

```sql
CREATE DATABASE release_notes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rnm'@'localhost' IDENTIFIED BY 'un_mot_de_passe_solide';
GRANT ALL PRIVILEGES ON release_notes.* TO 'rnm'@'localhost';
FLUSH PRIVILEGES;
```

Sous EasyPHP, ces commandes peuvent être exécutées dans phpMyAdmin (onglet SQL).

## 3. Installation des dépendances

```bash
cd release-note-manager
composer install            # développement (inclut PHPUnit et les fixtures)
composer install --no-dev --optimize-autoloader   # production
```

Le fichier `composer.lock` fourni fige des versions compatibles PHP 8.2 (`config.platform.php = 8.2.0`).

## 4. Configuration de `.env.local`

```bash
cp .env.example .env.local
```

Puis renseigner (voir les commentaires de `.env.example`) :

| Variable | Rôle |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `prod` / `0` en production |
| `APP_SECRET` | secret Symfony (CSRF, sessions) : `php -r "echo bin2hex(random_bytes(32));"` |
| `DATABASE_URL` | `mysql://rnm:MDP@127.0.0.1:3306/release_notes?serverVersion=8.0.32&charset=utf8mb4` |
| `ADMIN_TOKEN` | token de connexion à l'interface (≥ 32 caractères) |
| `APP_ENCRYPTION_KEY` | clé de chiffrement des secrets Jira/Confluence |
| `APP_HTTP_PROXY`, `APP_HTTP_NO_PROXY`, `APP_HTTP_CAFILE`, `APP_HTTP_VERIFY_PEER` | réseau sortant (proxy, CA d'entreprise) |
| `APP_HTTP_TIMEOUT`, `APP_HTTP_MAX_DURATION` | délais des appels Jira/Confluence (secondes) |

`.env.local` ne doit **jamais** être versionné (déjà exclu par `.gitignore`). Le fichier `.env` versionné
ne contient aucun secret.

## 5. Clé de chiffrement

```bash
php bin/console app:encryption:generate-key
# APP_ENCRYPTION_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=
```

Copier la ligne dans `.env.local` et **sauvegarder la clé** dans un coffre : sans elle, les tokens
enregistrés sont irrécupérables (il faudrait les ressaisir).

**Rotation de la clé** (tout ou rien, dans une transaction) :

```bash
php bin/console app:encryption:rotate-key --old-key=ANCIENNE --new-key=NOUVELLE --dry-run   # vérification
php bin/console app:encryption:rotate-key --old-key=ANCIENNE --new-key=NOUVELLE
# puis remplacer APP_ENCRYPTION_KEY dans .env.local et : php bin/console cache:clear
```

## 6. Migrations

```bash
php bin/console doctrine:migrations:migrate
php bin/console doctrine:schema:validate      # doit indiquer « in sync »
```

La migration initiale crée les 8 tables principales (les suivantes ajoutent `publication_container` et `acceptance_report`) et insère les correspondances de types par défaut
(Bug → Corrections, Story → Évolutions, Task → Tâches techniques, « * » → Autres changements, ainsi que
les libellés français : Bogue, Récit, Tâche…).

Données de démonstration (développement uniquement, **purge la base**) :

```bash
php bin/console doctrine:fixtures:load
```

Les connexions de démonstration sont **inactives** et pointent vers des domaines `.invalid` : aucun appel
réel n'est possible avec elles. Il n'existe pas d'autre « mode démo » : en usage normal, toutes les données
viennent des vraies API.

## 7. Token d'administration

```bash
php bin/console app:admin:generate-token
# ADMIN_TOKEN=…  (à placer dans .env.local ou dans une variable d'environnement du serveur)
```

- Le token n'est stocké que dans l'environnement du serveur ; jamais en base, dans les journaux ni dans
  les messages d'erreur. La comparaison est faite en temps constant.
- 5 tentatives échouées par IP → blocage 15 minutes (limiteur Symfony).
- **Rotation** : remplacer `ADMIN_TOKEN`, puis `php bin/console cache:clear`. Les sessions déjà ouvertes
  restent valides jusqu'à expiration (4 h d'inactivité) ; pour forcer une reconnexion immédiate, purger
  le répertoire des sessions PHP (`session.save_path`) ou modifier `APP_SECRET`.

## 8. Démarrage en local

```bash
php -S 127.0.0.1:8000 -t public
```

> Ne pas passer `public/index.php` comme script routeur : les fichiers CSS/JS ne seraient plus servis
> directement.

Ouvrir http://127.0.0.1:8000 et se connecter avec `ADMIN_TOKEN`.

## 9. Serveur web (Apache / EasyPHP)

La racine web **doit** être le dossier `public/` (jamais la racine du projet, qui contient `.env.local`).

**VirtualHost Apache :**

```apache
<VirtualHost *:80>
    ServerName release-notes.local
    DocumentRoot "C:/EasyPHP/eds-www/release-note-manager/public"
    <Directory "C:/EasyPHP/eds-www/release-note-manager/public">
        AllowOverride All
        Require all granted
        FallbackResource /index.php
    </Directory>
    ErrorLog  "logs/release-notes-error.log"
    CustomLog "logs/release-notes-access.log" common
</VirtualHost>
```

- `public/.htaccess` (fourni) gère la réécriture d'URL : activer `mod_rewrite` (et `mod_headers`
  pour les en-têtes de sécurité).
- EasyPHP : déposer le projet dans `eds-www`, pointer un alias ou un VirtualHost sur `public/`,
  activer `extension=sodium`, `extension=pdo_mysql`, `extension=intl`, `extension=curl` dans `php.ini`.
- Droits d'écriture du serveur web sur `var/` (cache, logs, verrous).
- Production : `APP_ENV=prod`, `APP_DEBUG=0`, puis `php bin/console cache:clear` ; HTTPS fortement
  recommandé (cookie de session `secure` automatique en HTTPS).
- Derrière un reverse proxy : définir `TRUSTED_PROXIES`.

## 10. Jira Cloud

1. Créer un API token : https://id.atlassian.com/manage-profile/security/api-tokens (compte technique recommandé).
2. *Connexions › Nouvelle connexion* : Outil **Jira**, type **Cloud**, URL `https://votre-site.atlassian.net`,
   authentification **Basic**, identifiant = **e-mail** du compte, token = API token.
3. Le compte doit pouvoir parcourir le projet (permission *Browse projects*).

Détails techniques : API REST v3, recherche via `POST /rest/api/3/search/jql` (pagination `nextPageToken`),
descriptions au format ADF converties en texte, Epic détecté via la relation `parent` (hiérarchie unifiée).

## 11. Jira Server / Data Center

1. **Personal Access Token** (Jira 8.14+) : *Profil › Jetons d'accès personnels*, puis connexion de type
   **Server / Data Center**, authentification **Personal Access Token (Bearer)**.
2. Sinon, si l'instance l'autorise : **Basic** avec identifiant + mot de passe.
3. URL de base complète, contexte inclus (ex. `https://jira.interne.local` ou `https://intranet/jira`).
4. **Champ Epic Link** : son identifiant varie selon les instances (`customfield_10100`, `customfield_12001`…).
   Laissez-le vide : il est **détecté et enregistré automatiquement** au premier test de connexion
   (recherche du champ de type `gh-epic-link`). Sinon saisissez-le manuellement.

Chaque instance a sa propre connexion : Finagora et l'autre projet interne peuvent utiliser des méthodes
d'authentification différentes.

## 12. Confluence Cloud

- Connexion **Confluence**, type **Cloud**, URL `https://votre-site.atlassian.net` (le suffixe `/wiki` est
  ajouté automatiquement s'il manque), Basic e-mail + API token.
- API REST v2 (`/wiki/api/v2/pages`). Le compte doit pouvoir créer et modifier des pages dans l'espace.
- Dans le projet : clé d'espace (ex. `CCS`) et, facultatif, l'identifiant numérique de la page parente
  (visible dans l'URL `…/pages/123456/…`).

## 13. Confluence Server / Data Center

- Connexion **Confluence**, type **Server / Data Center**, URL avec contexte (ex.
  `https://wiki.interne.local/confluence`), PAT (Confluence 7.9+) ou Basic.
- API REST v1 (`/rest/api/content`).
- Page parente : paramètre `pageId` de l'URL `viewpage.action?pageId=…`.

## 14. Test des connexions

- Bouton **Tester la connexion** sur chaque connexion (et dans la liste). Le résultat (date, statut,
  message) est conservé et affiché au tableau de bord en cas d'échec.
  - Jira : `/myself` + `/serverInfo` (+ détection Epic Link sur Server/DC).
  - Confluence Cloud : liste des espaces ; Server/DC : utilisateur courant (refus si anonyme) + espaces.
- Sur un projet : **Valider la configuration** contrôle la connexion Jira, l'accès au projet Jira,
  chaque connexion Confluence, l'espace et la page parente.
- En ligne de commande (pratique pour diagnostiquer un proxy) :

```bash
php bin/console app:connection:test        # toutes
php bin/console app:connection:test 3      # une seule
```

## 15. Import d'une première release

1. *Nouvelle release* › choisir le projet.
2. Les versions Jira du projet sont chargées (non livrées d'abord). Les versions ayant déjà une release
   proposent « Ouvrir la release existante ».
3. *Sélectionner* : informations de la version + **aperçu du regroupement** (aucune écriture).
   La JQL utilisée est affichée : `project = "CCS" AND fixVersion = <id> ORDER BY issuetype ASC, key ASC`
   (l'identifiant de version évite toute ambiguïté ; chaque ticket reçu est en outre contrôlé).
4. *Importer et créer le brouillon*.
5. Éditeur : onglets **IT interne / Fonctionnel / Communication**, sections (glisser-déposer ou ↑↓),
   *Modifier le wording* (formulaire à onglets + aperçu immédiat), déplacement de tickets (liste déroulante),
   retrait/réinclusion, ajout par clé, section manuelle.
6. **Actualiser depuis Jira** : un plan est présenté (ajouts, métadonnées, retraits potentiels, sections
   proposées, Epic modifiés) ; rien n'est appliqué avant confirmation. Les titres et textes ne sont jamais
   remplacés ; les tickets retirés restent en place (signalés) sauf si vous cochez explicitement
   « Détacher ».

Syntaxe des champs de texte : ligne vide = paragraphe, `- ` = puce, `1. ` = liste numérotée,
`**gras**`, `` `code` ``, URL → lien. Tout le reste est échappé (aucun HTML saisi n'est interprété).

## 16. Publication et republication

1. **Publier…** : matrice des 6 cibles (3 formats × 2 instances). Les cibles non configurées ou
   désactivées sont grisées. Indications « Jamais publiée », « À jour », « Modifiée depuis la publication ».
2. **Vérifier avant publication** : tableau de confirmation (release, format, destination, espace,
   titre de page, résultat attendu : création ou mise à jour de la page n°…, version n → n+1).
3. **Publier maintenant** : chaque cible est traitée indépendamment ; résultat affiché par cible.
4. **Historique** : statut par cible, lien vers la page, version, nombre de tentatives, journal détaillé,
   boutons **Réessayer** (échec) / **Republier**.

**Arborescence Confluence** (identique sur Cloud et Server/DC) : sur chaque instance, une page de
regroupement par release, placée sous la page parente du projet (ou à la racine de l'espace), contient les
trois release notes :

```
Page parente du projet (facultative)
└── CCS 2.4.0 — Release notes        ← créée automatiquement, liste ses sous-pages (macro « children »)
    ├── CCS 2.4.0 — Release note IT
    ├── CCS 2.4.0 — Release note fonctionnelle
    └── CCS 2.4.0 — Nouveautés
```

La page de regroupement est mémorisée par release et par instance, et n'est republiée que si son contenu
change. Les pages publiées avant cette organisation sont déplacées sous elle à leur prochaine republication.
Une page parente est utilisée plutôt qu'un « dossier » Confluence, qui n'existe que sur Cloud.

Règles anti-doublon : identifiant de page mémorisé par (release, format, cible) ; si la page a été supprimée,
recherche d'une page de même titre dans l'espace ; création seulement en dernier recours ; conflit de version
(409) → relecture et nouvelle tentative. Titre de page stable : `<Projet> <Version> — <Format>`.
Verrou par cible + statut « en cours » contre les publications concurrentes.

Statut de la release : **Publiée** quand toutes les cibles configurées ont été publiées avec succès,
**Partiellement publiée** sinon (au moins une).

Une release publiée ne peut être supprimée qu'en ressaisissant le nom de la version ; les pages Confluence
ne sont jamais supprimées par l'application. L'archivage (lecture seule) est préférable.

## 16 bis. PV de recette

Depuis l'éditeur d'une release : **PV de recette** (procès-verbal de tests système de la version).

1. **Créer le PV** : il est prérempli avec le **dernier PV du même projet** (titre du document, code,
   responsables, phases et environnements sans leurs dates, textes, préfixe, signataires), la version
   (« Projet - v X.Y.Z ») et la date de mise en production de la release, puis les données Jira sont lues.
2. **Données Jira** (instantané, rafraîchi par *Actualiser depuis Jira*, sans toucher aux textes saisis) :
   - **2.2 Périmètre** : tickets de la Fix Version hors Epics, sous-tâches et anomalies ;
   - **2.2.1 Exécution des tests** : commentaires de ces tickets commençant par le préfixe configuré
     (`TEST :` par défaut ; casse et espaces autour du « : » ignorés, `*TEST :*` accepté), préfixe retiré,
     regroupés par ticket (titre = clé + résumé), avec auteur et date ;
   - **2.2.2 Anomalies** : tickets de la version dont le type est rattaché à la catégorie « Correction »
     (*Types de tickets*), **plus les anomalies liées** (liens Jira : « bloque », « est lié à »…) aux fiches
     du périmètre, même sans la Fix Version (relues dans Jira, sans doublon). Elles sont présentées en deux
     tableaux, **non terminées** puis **terminées**, avec leur origine (« Version » ou « Liée à FIN-101
     (est bloqué par) »). Un commentaire libre complète la synthèse calculée.
   - **Couverture de tests Octane** (si activée dans le projet) : pour chaque fiche du périmètre, nombre de
     tests liés dans Octane (lien vers l'onglet Tests) et résultats des derniers passages (Passed, Failed,
     Requires Attention, Planned, Skipped, sans passage), plus un total. Les données sont lues via l'API de
     l'extension Jira « Core Software Delivery Platform » (`/rest/octane-coverage/1.0/coverage`, celle de
     l'encart « test coverage »), avec la connexion Jira du projet : aucune clé Octane n'est nécessaire.
     L'espace Octane est découvert automatiquement comme dans l'encart ; s'il y en a plusieurs, renseignez
     `workspace-config-id` et `workspace-id` dans le projet (valeurs visibles dans l'appel de l'encart, onglet
     Réseau du navigateur). Une indisponibilité de l'extension est signalée sans bloquer l'actualisation.
   - **Réserves par défaut** : liste des anomalies non terminées, mise à jour à chaque actualisation tant
     que vous n'avez pas modifié ce texte (un texte modifié n'est jamais remplacé).
   L'écran signale les fiches du périmètre sans commentaire de test.
3. **Compléter** : identification, responsables (un par ligne), déroulement (phases avec dates prévues /
   réelles — vides = « N/A » —, environnements, commentaires), hors périmètre, décision (acceptée sans
   réserve / avec réserves / refusée), réserves, commentaires, signatures, statut Brouillon / Définitif.
   Un PV définitif exige une décision ; « avec réserves » exige le texte des réserves.
4. **Publier** : le PV apparaît comme format « PV de recette » dans l'écran de publication (Cloud et/ou
   Server). Sa page « <Projet> <Version> — PV de recette » est rangée sous la page de regroupement de la
   version, avec les release notes, **ou** sous la **page parente dédiée aux PV** si elle est renseignée
   dans le projet (*Projets › Modifier*, un identifiant pour Cloud et un pour Server/DC). Changer ce réglage
   déplace la page à la prochaine republication. Republication = mise à jour de la même page. Le PV n'intervient pas
   dans le statut « Publiée » des release notes.

## 17. Tests

```bash
composer install                       # dépendances de dev incluses
php vendor/bin/phpunit                 # 74 tests (unitaires, intégration, fonctionnels)
php vendor/bin/phpunit --testsuite unit
php vendor/bin/phpunit --testsuite integration
php vendor/bin/phpunit --testsuite functional
composer lint                          # lint:container, lint:twig, lint:yaml, schema:validate
```

Les tests utilisent une base **SQLite** isolée (`var/test.db`, extension `pdo_sqlite`) et un
**MockHttpClient** (`tests/Support/FakeAtlassianServer.php`) : aucune instance Jira/Confluence réelle
n'est contactée.

Couverture fonctionnelle : authentification par token (succès, échec, CSRF, déconnexion), refus d'accès
anonyme, validation des formulaires, chiffrement/déchiffrement, regroupement par Epic et par type, respect de
la Fix Version (JQL + filtrage défensif), pagination Cloud et Server, absence de doublons à l'import et à
l'actualisation, actualisation sans perte de wording ni de classement, rendu des trois formats (et XHTML
Confluence bien formé), création / mise à jour de pages Cloud et Server, conflit de version, isolation des
erreurs entre cibles, nouvelles tentatives, parcours complet de l'éditeur via HTTP.

## 18. Diagnostic

| Symptôme | Piste |
|---|---|
| « Authentification refusée (401) » | e-mail + API token (Cloud) ; type Bearer vs Basic (Server) ; token expiré |
| « CAPTCHA » | se connecter une fois au navigateur avec le compte, ou utiliser un PAT |
| « Réponse HTML inattendue » | URL de base incorrecte (contexte `/jira`, `/confluence` manquant) ou page SSO/proxy |
| « Serveur injoignable » / délai dépassé | DNS, proxy (`APP_HTTP_PROXY`), certificats (`APP_HTTP_CAFILE`), `APP_HTTP_TIMEOUT` |
| « Secret illisible » | `APP_ENCRYPTION_KEY` modifiée sans rotation : ressaisir le token ou restaurer la clé |
| Tickets sans Epic sur Server | champ Epic Link absent : relancer le test de connexion ou le saisir |
| Publication en échec 403 | droits de création/modification de pages dans l'espace |
| Publication « déjà en cours » | une autre publication tourne ; sinon le statut est libéré après 10 min |

Journaux : `var/log/prod.log` (erreurs), `var/log/integration.log` (appels Jira/Confluence : connexion,
méthode, chemin, statut HTTP, durée — **jamais** d'en-têtes ni de secrets).

Autres commandes utiles : `php bin/console debug:router`, `php bin/console doctrine:migrations:status`,
`php bin/console about`.

## 19. Proxy d'entreprise

```dotenv
APP_HTTP_PROXY=http://utilisateur:motdepasse@proxy.entreprise.local:8080
APP_HTTP_NO_PROXY=jira.interne.local,.corp.local,localhost
APP_HTTP_CAFILE=C:/certs/ca-entreprise.pem      # si le proxy inspecte le TLS
APP_HTTP_VERIFY_PEER=1
```

- Typiquement : instances Cloud via le proxy, instances internes en `NO_PROXY`.
- Si `APP_HTTP_PROXY` est vide, les variables système `HTTPS_PROXY` / `NO_PROXY` sont utilisées par HttpClient.
- Encodez les caractères spéciaux de l'identifiant proxy (`@` → `%40`).
- Désactiver la vérification TLS (`APP_HTTP_VERIFY_PEER=0`) uniquement pour un diagnostic ponctuel.
- Vérification : `php bin/console app:connection:test` affiche la configuration réseau effective (sans
  identifiants de proxy).

## 20. Architecture

```
src/
├── Command/               commandes console (clé, rotation, token, test de connexion)
├── Controller/            contrôleurs minces (délèguent aux services)
├── DataFixtures/          données de démonstration (dev)
├── Entity/                entités Doctrine
├── Enum/                  statuts et types (enums PHP)
├── Form/                  formulaires Symfony
├── Repository/            requêtes Doctrine
├── Security/              AdminTokenAuthenticator, AdminTokenVerifier
├── Twig/                  filtres rn_text / rn_inline, fonction rn_links
└── Service/
    ├── Http/              AtlassianApiClient (auth, erreurs, proxy), HttpClientOptionsProvider
    ├── Security/          SecretEncryptionService
    ├── Jira/              JiraConnectionTester, JiraVersionService, JiraIssueService,
    │                      JiraEpicResolver, JiraIssueNormalizer, JiraJqlBuilder, AdfTextExtractor
    ├── Confluence/        ConfluenceConnectionTester, ConfluencePagePublisher,
    │                      ConfluenceCloudApi (v2), ConfluenceServerApi (v1), ConfluenceApiResolver
    ├── ReleaseNote/       ReleaseImportService, ReleaseRefreshService, ReleaseSectionService,
    │                      ReleaseDuplicationService, SectionBuilder, IssueTypeMappingResolver,
    │                      JiraTicketMapper, ReleaseNoteViewBuilder, ReleaseNoteRenderer,
    │                      TextFormatter, LinkListParser
    ├── Publication/       PublicationService
    ├── ConnectionTestService.php
    └── ProjectConfigurationChecker.php
templates/
├── base.html.twig, _components/ (macros UI), form/ (thème)
├── dashboard/ project/ connection/ issue_type_mapping/ release/ release_section/ ticket/ publication/ security/
└── render/web/ (aperçu HTML) et render/confluence/ (format de stockage)
public/assets/css/ (app.css, release-note.css) et public/assets/js/app.js
```

**Rendu** : un même modèle de vue (`ReleaseNoteViewBuilder`) alimente deux jeux de templates. L'aperçu web
utilise du CSS ; le rendu Confluence n'utilise que du XHTML et des macros natives (`panel`, `info`, `note`,
`warning`, `tip`, `status`, `toc`), identiques sur Cloud et Server/DC, car Confluence ignore les styles
navigateur. Le XHTML est validé (bien formé) avant envoi.

**Principales routes** (`php bin/console debug:router` pour la liste complète, 50 routes) :

| Route | Rôle |
|---|---|
| `GET /login`, `POST /logout` | connexion / déconnexion |
| `GET /` | tableau de bord |
| `/projects…`, `/connections…`, `/settings/issue-types…` | administration (CRUD, test, validation, activation) |
| `GET /releases/new` → `/releases/new/{projet}` → `/releases/new/{projet}/{version}` | création en 3 étapes |
| `GET /releases/{id}` | éditeur |
| `GET|POST /releases/{id}/refresh` | plan puis application de l'actualisation |
| `GET /releases/{id}/preview/{it|functional|communication}[/storage]` | aperçus |
| `/sections/{id}/…`, `/tickets/{id}/…` | édition, déplacement, réordonnancement |
| `GET /releases/{id}/publish`, `POST …/confirm`, `POST …/execute` | publication |
| `GET /releases/{id}/publications`, `POST /publications/{id}/retry` | historique, nouvelle tentative |
| `POST /releases/{id}/archive|unarchive`, `GET|POST …/delete`, `…/duplicate` | cycle de vie |

Toutes les routes (sauf `/login`) exigent `ROLE_ADMIN` ; toutes les actions de modification sont en POST
avec jeton CSRF.

## 21. Modèle de données

```mermaid
erDiagram
    EXTERNAL_CONNECTION ||--o{ PROJECT : "jira / confluence cloud / confluence server"
    PROJECT ||--o{ RELEASE_NOTE : contient
    PROJECT ||--o{ ISSUE_TYPE_MAPPING : "règles spécifiques (optionnel)"
    RELEASE_NOTE ||--o{ RELEASE_SECTION : contient
    RELEASE_NOTE ||--o{ JIRA_TICKET : importe
    RELEASE_SECTION ||--o{ JIRA_TICKET : regroupe
    RELEASE_NOTE ||--o{ PUBLICATION : "format x cible"
    RELEASE_NOTE ||--o{ PUBLICATION_CONTAINER : "page de regroupement par cible"
    RELEASE_NOTE ||--o| ACCEPTANCE_REPORT : "PV de recette"
    PUBLICATION ||--o{ PUBLICATION_ATTEMPT : historise

    EXTERNAL_CONNECTION {
        int id PK
        string name UK
        string provider "jira | confluence"
        string deployment_type "cloud | server"
        string base_url
        string authentication_type "basic | bearer"
        string username
        text encrypted_secret "libsodium, jamais en clair"
        string jira_epic_link_field
        bool active
        datetime last_tested_at
        string last_test_status
    }
    PROJECT {
        int id PK
        string code UK
        string name
        int jira_connection_id FK
        string jira_project_key
        int confluence_cloud_connection_id FK
        string confluence_cloud_space_key
        string confluence_cloud_parent_page_id
        int confluence_server_connection_id FK
        string confluence_server_space_key
        string confluence_server_parent_page_id
    }
    RELEASE_NOTE {
        int id PK
        int project_id FK
        string jira_version_id "UK avec project_id"
        string jira_version_name
        string title
        date release_date
        string status
        text it_intro
        text functional_intro
        text communication_intro
    }
    RELEASE_SECTION {
        int id PK
        int release_id FK
        string source_type "epic | issue_type | manual"
        string source_key
        string title
        text it_content
        text functional_content
        text communication_content
        text impact
        text action_required
        bool visible_it
        bool visible_functional
        bool visible_communication
        int position
    }
    JIRA_TICKET {
        int id PK
        int release_id FK
        int section_id FK "SET NULL"
        string jira_issue_id "UK avec release_id"
        string jira_key "UK avec release_id"
        string issue_type
        string summary
        string status
        string epic_key
        json components
        json labels
        json fix_versions
        json raw_data
        string sync_state
        bool excluded
        int position
    }
    PUBLICATION {
        int id PK
        int release_id FK
        string format "UK release+format+target"
        string target
        string status
        string confluence_page_id
        string confluence_page_url
        int confluence_page_version
        string content_hash
        string error_message
        int attempt_count
        datetime published_at
    }
    PUBLICATION_ATTEMPT {
        int id PK
        int publication_id FK
        string status
        string action
        string message
        int page_version
        datetime created_at
    }
    PUBLICATION_CONTAINER {
        int id PK
        int release_id FK "UK avec target"
        string target "cloud | server"
        string confluence_page_id
        string confluence_page_url
        string page_title
        int confluence_page_version
        string content_hash
    }
    ACCEPTANCE_REPORT {
        int id PK
        int release_id FK "UK"
        string document_title
        string document_status "draft | final"
        string application_name
        string code
        string version_label
        date mep_date
        json phases
        string test_comment_prefix
        string decision
        json jira_snapshot "périmètre, tests, anomalies"
    }
    ISSUE_TYPE_MAPPING {
        int id PK
        int project_id FK "NULL = global"
        string jira_issue_type "* = défaut"
        string section_title
        string category
        int position
    }
```

Correspondance avec le cahier des charges :

- `Connection` → table `external_connection` (nom évitant la confusion avec la connexion DBAL) ; `type` →
  `deployment_type`, `provider` → `provider`.
- `Release` → table `release_note` (`RELEASE` est un mot réservé MySQL).
- Les variantes éditoriales des sections sont des colonnes dédiées (titres/contenus IT, fonctionnel,
  communication, impact, action, risques, validations, audience, liens) ; les données de diagnostic Jira
  sont un sous-ensemble non sensible en JSON (`raw_data`).
- Contraintes : unicité (projet, version Jira) ; (release, clé Jira) et (release, id Jira) ; (release, format,
  cible) ; (projet, type Jira) ; nom de connexion ; code projet. Suppression d'une release → cascade sur
  sections, tickets, publications et historique ; suppression d'une connexion utilisée → refusée (RESTRICT) ;
  l'application propose la désactivation, pas la suppression.
- Toutes les tables portent `created_at` / `updated_at` (sauf l'historique, immuable : `created_at`).

## 22. Sécurité

- Authentification Symfony Security (`AdminTokenAuthenticator`, CSRF sur le formulaire de connexion),
  session régénérée après connexion, cookie `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS.
- Limitation des tentatives (5 / 15 min par IP), message d'erreur générique.
- CSRF sur tous les formulaires et toutes les actions POST ; actions de modification uniquement en POST.
- Requêtes Doctrine paramétrées ; JQL construite avec échappement et identifiants numériques ; clés Jira
  validées par expression régulière.
- Twig en échappement automatique ; texte éditorial échappé avant la conversion de syntaxe légère ; liens
  limités à `http(s)`.
- Secrets chiffrés (XSalsa20-Poly1305, libsodium) avec une clé externe ; jamais réaffichés (champ
  toujours vide) ; jamais journalisés ; masqués (`***`) s'ils apparaissent dans un message d'erreur d'API.
- En-têtes `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` (Apache, `.htaccess`) ;
  `noindex` sur toutes les pages.

## 23. Limites connues

- Les pages Confluence modifiées à la main sont **écrasées** à la republication (un avertissement figure en
  pied de page publiée).
- Si la page parente du projet change, la page de regroupement existante n'est déplacée que lorsque son
  contenu est republié (changement de titre, de date…) ; les pages de format, elles, restent sous la page de
  regroupement.
- La publication est synchrone (6 cibles maximum, délais bornés par `APP_HTTP_MAX_DURATION`) ; pas de file
  d'attente asynchrone.
- Verrous de publication sur fichiers locaux (`var/lock`) : adaptés à un seul serveur web ; en
  multi-serveurs, configurer un store partagé (`framework.lock`).
- Un seul compte administrateur (token partagé) : pas de traçabilité par utilisateur.
- Les liens vers Jira sont des liens hypertexte simples (pas de macro Jira, qui exigerait un *application
  link* configuré entre Confluence et Jira).
- Doctrine ORM 3.7 émet des avis de dépréciation (directions de tri en chaîne, à remplacer par l'enum
  `SortDirection` de PHP 8.5 en vue d'ORM 4) : sans effet fonctionnel.
- La recherche Jira est plafonnée à 5 000 tickets par version (sécurité).
- Non vérifié contre de vraies instances Jira/Confluence : les intégrations sont testées sur des réponses
  simulées conformes aux API documentées ; un test sur vos instances (bouton *Tester la connexion*,
  *Valider la configuration*, puis une publication dans un espace de test) est indispensable avant usage.
