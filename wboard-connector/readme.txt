=== WBoard Connector ===
Contributors: willybahuaud
Tags: monitoring, dashboard, backup, security, management, multisite
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.3
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connecteur pour WBoard - Permet la supervision centralisée de votre site WordPress.

== Description ==

WBoard Connector est un plugin compagnon pour WBoard, un outil de gestion de parc WordPress.

**Fonctionnalités :**

* Remonte les informations de version (WordPress, PHP, plugin)
* Collecte les mises à jour disponibles (core, plugins, thèmes)
* Intègre les données de sauvegarde (Vivid Backup Pro, WPVivid)
* Remonte les alertes de sécurité (SecuPress Pro)
* Permet la connexion en un clic au back-office
* Mise à jour automatique depuis le board
* **Support WordPress Multisite** : détection réseau, niveau d'activation des plugins
* Détection WP_DEBUG actif (alerte sur sites de production)
* Mise à jour des plugins et thèmes à distance depuis le board
* **Backup streaming** : export fichiers et base de données piloté par le backup-manager
* **Sessions de test** : sessions courte durée pour tests visuels (BackstopJS/Playwright)

**Sécurité :**

Toutes les communications entre le board et ce plugin sont sécurisées par signature HMAC-SHA256 (v2 : signature sur payload brut + nonce anti-rejeu). Chaque requête est vérifiée pour garantir son authenticité et son intégrité.

== Installation ==

1. Téléchargez et installez le plugin sur votre site WordPress
2. Activez le plugin
3. Allez dans Réglages > WBoard
4. Copiez la clé secrète affichée
5. Collez cette clé dans la configuration de votre site sur WBoard

== Frequently Asked Questions ==

= Comment régénérer ma clé secrète ? =

Allez dans Réglages > WBoard et cliquez sur "Régénérer la clé". Attention, vous devrez mettre à jour la clé dans votre board WBoard.

= Le plugin est-il sécurisé ? =

Oui. Toutes les communications sont signées avec HMAC-SHA256 et les timestamps sont vérifiés pour éviter les attaques par rejeu.

= Quels plugins de backup sont supportés ? =

Vivid Backup Pro et WPVivid Backup sont actuellement supportés.

= Quels plugins de sécurité sont supportés ? =

SecuPress Pro est actuellement supporté pour la collecte des alertes de sécurité.

== Changelog ==

= 2.0.2 =
* Fix : les routes backup étaient conditionnées à une option locale (wboard_backup_config). Suppression du guard is_enabled(), la sécurité repose sur HMAC.
* Feat : support des patterns glob dans les exclusions de répertoires (*cache* exclut object-cache, et-cache, etc.)

= 2.0.1 =
* Fix : le plugin restait désactivé après une mise à jour de lui-même via le board
* Nouveau mécanisme de réactivation forcée en 2 niveaux (activate_plugin + fallback direct option)
* Détection automatique des self-updates pour toujours forcer la réactivation

= 2.0.0 =
* **Backup streaming** : module complet d'export fichiers (tar) et base de données (SQL gzippé) piloté par le backup-manager
* Scanner de fichiers wp-content avec gestion symlinks, exclusions et état incrémental
* Cron de nettoyage automatique des fichiers temporaires de backup
* **Signature HMAC v2** : vérification sur le payload brut (plus de decode/re-encode), rétrocompatible v1
* **Nonce anti-rejeu** : chaque requête porte un nonce unique (transient 5 min), optionnel pour rétrocompatibilité
* Fix SQLi : primary_key déduit côté serveur via INFORMATION_SCHEMA
* Répertoire temporaire déplacé hors webroot (sys_get_temp_dir), fallback sécurisé dans wp-content
* Suppression du code mort (class-backup-uploader, ancienne route /backup/db/export)
* Sessions de test courte durée pour tests visuels BackstopJS/Playwright (endpoints /test-session et /destroy-sessions)

= 1.6.2 =
* Fix sécurité : rate limiting basé sur REMOTE_ADDR (non spoofable)
* Fix sécurité : validation stricte du slug lors des MAJ à distance (regex alphanum)
* Refactor : remplacement des fonctions anonymes par des méthodes nommées

= 1.6.1 =
* Fix sécurité : suppression endpoint /regenerate-key (clé exposée en réponse)
* Fix sécurité : endpoint /backup-credentials ne retourne plus les secrets B2
* Fix : alignement génération de clé sur alphanumériques uniquement
* Fix : compatibilité PHP 7.3 (remplacement str_ends_with)
* Requires PHP aligné sur 7.3

= 1.6.0 =
* Détection du statut WP_DEBUG (remontée vers le board)
* Mise à jour de plugins et thèmes à distance depuis le board

= 1.5.0 =
* Endpoint de mise à jour des composants (plugins/thèmes) à distance

= 1.4.0 =
* Auto-update du plugin via GitHub Releases sans blocage

= 1.3.1 =
* Fix : Validation de l'existence des tables avant requête SQL UNION multisite
* Évite les erreurs SQL si des tables ont été supprimées manuellement

= 1.3.0 =
* **Support WordPress Multisite complet**
* Détection du contexte multisite (réseau, nombre de sites)
* Niveau d'activation des plugins : network, site, some_sites, none
* Comptage optimisé des sites avec plugin actif (requête SQL UNION)
* Exclusion automatique des sites archivés/supprimés/spam

= 1.2.0 =
* Fix détection schedule incrémental WPVivid Pro
* Fix date dernier backup (prend en compte les itérations incrémentales)

= 1.1.0 =
* Monitoring WP-Cron (type cron, tâches en retard, dernier run)

= 1.0.0 =
* Version initiale
* Collecte des informations système (versions WP/PHP)
* Collecte des mises à jour disponibles
* Support Vivid Backup Pro et WPVivid
* Support SecuPress Pro
* Auto-login sécurisé
* Page de réglages

== Upgrade Notice ==

= 2.0.2 =
Correctif : les routes backup sont maintenant toujours disponibles (suppression du guard is_enabled). Support glob dans les exclusions.

= 2.0.1 =
Correctif : le plugin reste maintenant actif après une mise à jour de lui-même via le board.

= 2.0.0 =
Mise à jour majeure : module backup streaming, signature HMAC v2 avec nonce anti-rejeu, correctifs de sécurité. Rétrocompatible avec le board existant.

= 1.6.2 =
Renforcement rate limiting et validation des slugs de MAJ.

= 1.6.1 =
Correctifs de sécurité suite à audit.

= 1.6.0 =
Détection WP_DEBUG + mise à jour de composants à distance.

= 1.3.0 =
Ajout du support WordPress Multisite avec détection du niveau d'activation des plugins.

= 1.0.0 =
Version initiale du plugin.
