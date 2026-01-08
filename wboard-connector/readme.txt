=== WBoard Connector ===
Contributors: willybahuaud
Tags: monitoring, dashboard, backup, security, management, multisite
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.3.1
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

**Sécurité :**

Toutes les communications entre le board et ce plugin sont sécurisées par signature HMAC-SHA256. Chaque requête est vérifiée pour garantir son authenticité et son intégrité.

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

= 1.3.0 =
Ajout du support WordPress Multisite avec détection du niveau d'activation des plugins.

= 1.0.0 =
Version initiale du plugin.
