# Rapport de livraison — Release Note Manager

Date : 12/10/2026 — Symfony 7.4.20 LTS, PHP ≥ 8.2, Doctrine ORM 3.7 / DBAL 4.5, PHPUnit 11.5.

## 1. Lots réalisés

| Lot | Contenu | État |
|---|---|---|
| 1 — Socle | `composer.json` + `composer.lock`, configuration Symfony sans Flex, sécurité par token, chiffrement des secrets, 8 entités, 11 enums, 8 repositories, migration MySQL | Terminé |
| 2 — Administration | Tableau de bord, CRUD projets / connexions / correspondances de types, activation-désactivation, test de connexion, validation de configuration projet | Terminé |
| 3 — Intégration Jira | Versions, Fix Version, JQL échappée, pagination Cloud (`nextPageToken`) et Server (`startAt`), normalisation v2/v3 (ADF), résolution des Epics (parent / Epic Link configurable / héritage des sous-tâches), regroupement | Terminé |
| 4 — Éditeur | Sections (création, wording par format, suppression, réordonnancement), tickets (déplacement, ordre, retrait, ajout par clé), statut, actualisation non destructive avec plan préalable, duplication vers une autre version, archivage, suppression protégée | Terminé |
| 5 — Présentation | Rendu web des 3 formats, rendu au format de stockage Confluence (macros natives), aperçu immédiat du wording, avertissements éditoriaux, interface responsive | Terminé |
| 6 — Publication | 6 cibles, sélection, écran de confirmation, création / mise à jour sans doublon, versions de page, conflit 409, statut par cible, historique, nouvelle tentative, verrous, statut de release calculé | Terminé |
| 7 — Validation | 59 tests, lints, migrations sur MySQL réel, mode prod, captures navigateur, archive ZIP | Terminé (voir §4) |

## 2. Fonctionnalités opérationnelles

- Connexion par token (`ADMIN_TOKEN`), CSRF, limitation des tentatives, déconnexion, toutes routes protégées.
- Connexions Jira / Confluence Cloud et Server/DC : Basic (e-mail/identifiant + token) ou Bearer (PAT),
  secrets chiffrés libsodium jamais réaffichés, test avec résultat mémorisé, détection du champ Epic Link.
- Projets : Jira + Confluence Cloud + Confluence Server (espaces, pages parentes), validation de bout en bout.
- Correspondances « type Jira → section » administrables (globales ou par projet, défaut « * »).
- Création d'une release depuis une version Jira (3 étapes avec aperçu), import des seuls tickets de la version.
- Éditeur à onglets IT / Fonctionnel / Communication, wording indépendant, visibilité par format,
  confirmation de livraison, tickets non classés, tickets retirés.
- Actualisation depuis Jira : plan (ajouts, mises à jour, retraits, inaccessibles, nouvelles sections, Epic
  modifiés) puis application sans modification du wording ni du classement.
- Aperçu web et source Confluence de chaque format ; détection « modifiée depuis la publication ».
- Publication / republication / nouvelle tentative, historique complet, statut Publiée / Partiellement publiée.
- Commandes : `app:encryption:generate-key`, `app:encryption:rotate-key`, `app:admin:generate-token`,
  `app:connection:test`.
- Fixtures de démonstration isolées (connexions inactives sur domaines `.invalid`).

## 3. Points dépendant de la configuration externe ou non couverts

- **Aucun appel à de vraies instances Jira/Confluence n'a pu être fait** depuis l'environnement de
  génération : les intégrations sont validées sur des réponses simulées fidèles aux API REST documentées
  (Jira v2/v3, Confluence v1/v2). À valider sur vos instances : test de connexion, validation du projet,
  import d'une version, publication dans un espace de test.
- Permissions requises côté Atlassian : *Browse projects* (Jira), création/modification de pages (Confluence).
- Disponibilité des PAT selon la version de vos instances Server/DC (Jira 8.14+, Confluence 7.9+).
- Proxy d'entreprise : paramétrage `APP_HTTP_*` (non testable ici).
- Limites assumées (détaillées dans le README §23) : republication écrasant les modifications manuelles
  faites dans Confluence, pas de déplacement de page existante, publication synchrone, verrous locaux
  (mono-serveur), compte administrateur unique, avis de dépréciation Doctrine ORM 3.7 sans effet.

## 4. Vérifications réellement exécutées

| Vérification | Commande | Résultat |
|---|---|---|
| Syntaxe PHP | `php -l` sur les 122 fichiers PHP (src, tests, migrations, config, public, bin) | aucune erreur |
| JSON de `composer.json` | `json_decode(..., JSON_THROW_ON_ERROR)` | valide |
| Composer | `composer validate --strict` | valide |
| Résolution des dépendances | `composer update` (plateforme PHP 8.2.0) puis `composer install` depuis le lock dans une copie propre de l'archive | réussi (voir §5) |
| Conteneur | `php bin/console lint:container` | OK |
| Templates | `php bin/console lint:twig templates` | 43 fichiers OK |
| YAML | `php bin/console lint:yaml config` | 11 fichiers OK |
| Mapping | `php bin/console doctrine:schema:validate` | mapping correct, base **synchronisée** |
| Migrations | `doctrine:migrations:migrate` puis `migrate first` puis `migrate` sur **MySQL 8.0.46** réel | montée / descente / remontée OK, données par défaut en UTF-8 correct |
| Fixtures | `doctrine:fixtures:load` sur MySQL | OK |
| Mode production | `APP_ENV=prod APP_DEBUG=0 bin/console cache:clear` | OK |
| Pages | 29 pages GET parcourues via le serveur PHP + MySQL après connexion | toutes HTTP 200 |
| Navigateur | captures Chromium (Playwright) desktop 1400 px et mobile 390 px | rendu correct, 0 erreur JavaScript |
| JavaScript | `node --check public/assets/js/app.js` | OK |
| Tests | `php vendor/bin/phpunit` | **59 tests, 278 assertions, OK** (33 unitaires, 9 intégration, 17 fonctionnels) |
| Archive | `unzip -t` + installation et tests depuis l'archive extraite | voir §5 |

Environnement de vérification : PHP 8.3.6 CLI (la contrainte de plateforme Composer garantit la compatibilité
des dépendances avec PHP 8.2 ; l'exécution sous un binaire PHP 8.2 n'a pas été faite), MySQL 8.0.46,
SQLite pour les tests.

## 5. Archive

Contenu : sources complètes, `composer.json`, `composer.lock`, configuration, migrations, templates, assets,
tests, README, ce rapport. Exclus : `vendor/` (reconstruit par `composer install`), `var/`, `.env.local`
(secrets), caches.

Contrôle d'intégrité effectué sur l'archive :

- `unzip -t` : aucune erreur ; absence vérifiée de `vendor/` et de `.env.local` dans l'archive.
- Extraction dans un dossier vierge → `composer install` depuis `composer.lock` : 107 paquets installés,
  autoload optimisé généré, script `cache:clear` OK.
- `doctrine:migrations:migrate` sur une base MySQL 8 vierge (`release_notes_ci`) : OK ;
  `doctrine:schema:validate` : mapping correct, base synchronisée.
- `php vendor/bin/phpunit` depuis la copie extraite : **OK (59 tests, 278 assertions)**.
