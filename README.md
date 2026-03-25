<p align="center">
  <img src="https://img.shields.io/badge/WBoard_Connector-Plugin_WordPress-5850EC?style=for-the-badge&logo=wordpress&logoColor=white" alt="WBoard Connector" />
</p>

<p align="center">
  <strong>Le pont entre vos sites WordPress et votre tableau de bord WBoard</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/github/v/release/willybahuaud/wboard-connector?style=flat-square&color=5850EC" alt="Version" />
  <img src="https://img.shields.io/badge/WordPress-6.0%2B-21759B?style=flat-square&logo=wordpress" alt="WordPress 6.0+" />
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.0+" />
  <img src="https://img.shields.io/badge/Multisite-Compatible-4CAF50?style=flat-square" alt="Multisite Compatible" />
</p>

---

## Pourquoi WBoard Connector ?

Vous gérez plusieurs sites WordPress ? **WBoard Connector** est le plugin compagnon qui permet à [WBoard](https://github.com/willybahuaud/wboard-site) de superviser vos sites en toute sécurité.

**Installez, configurez en 30 secondes, et c'est parti.**

### Ce que le plugin remonte automatiquement

| Donnée | Description |
|--------|-------------|
| **Versions** | WordPress, PHP, plugins, thèmes |
| **Mises à jour** | Core, plugins et thèmes avec versions disponibles |
| **WP-Cron** | État du cron, tâches en retard |
| **Multisite** | Réseau, nombre de sites, niveau d'activation des plugins |

### Backup intégré

Le plugin sert de relais pour le [backup-manager](https://github.com/willybahuaud/wboard-backup-manager) : scan des fichiers, streaming par batch, export SQL table par table. Tout est pensé pour les hébergements mutualisés (timeout 30s, 128 Mo RAM).

### Connexion en un clic

Plus besoin de jongler avec vos mots de passe. Depuis WBoard, connectez-vous instantanément au back-office de n'importe quel site supervisé.

---

## Installation

### Depuis les releases GitHub

1. Téléchargez la [dernière release](https://github.com/willybahuaud/wboard-connector/releases/latest)
2. Dans WordPress : Extensions > Ajouter > Téléverser
3. Activez le plugin
4. Allez dans **Réglages > WBoard**
5. Copiez la clé secrète et ajoutez-la dans votre board

### Mises à jour automatiques

Le plugin se met à jour automatiquement depuis GitHub. Vous recevez les notifications comme pour n'importe quel plugin WordPress.

---

## Sécurité

La sécurité n'est pas une option, c'est la fondation.

| Protection | Description |
|------------|-------------|
| **HMAC-SHA256** | Chaque requête est signée cryptographiquement |
| **Timestamps** | Fenêtre de validité de 5 minutes contre les replay attacks |
| **Rate limiting** | 30 requêtes/minute max pour éviter les abus |
| **Tokens éphémères** | Auto-login valide 30 secondes, usage unique |

### En multisite

- La clé secrète est stockée au niveau réseau
- Seuls les **super admins** peuvent accéder aux réglages
- Seuls les **super admins** peuvent utiliser l'auto-login

---

## Compatibilité

### WordPress Multisite

Support complet avec :
- Détection automatique du contexte réseau
- Niveau d'activation des plugins : `network`, `site`, `some_sites`, `none`
- Exclusion des sites archivés/supprimés/spam

---

## Endpoints API

Le plugin expose une API REST sécurisée. Tous les endpoints requièrent une signature HMAC valide.

### Supervision

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/wboard/v1/status` | GET | État complet du site |
| `/wboard/v1/autologin` | POST | Génère un token de connexion |
| `/wboard/v1/update-component` | POST | Met à jour un plugin ou thème |
| `/wboard/v1/test-session` | POST | Vérifie la session auto-login |
| `/wboard/v1/destroy-sessions` | POST | Invalide les sessions actives |

### Backup (utilisés par le backup-manager)

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/wboard/v1/backup/scan` | POST | Scan paginé des fichiers (manifeste) |
| `/wboard/v1/backup/stream` | POST | Streaming tar des fichiers demandés |
| `/wboard/v1/backup/db/tables` | POST | Liste des tables avec checksums |
| `/wboard/v1/backup/db/stream` | POST | Export SQL streaming (tar, batch par table) |
| `/wboard/v1/backup/status` | GET | Statut du dernier backup |

---

## Structure du plugin

```
wboard-connector/
├── wboard-connector.php        # Point d'entrée
├── readme.txt                  # Readme WordPress
├── includes/
│   ├── class-api.php           # Endpoints REST (supervision)
│   ├── class-autologin.php     # Connexion automatique
│   ├── class-backup.php        # Endpoints REST (backup)
│   ├── class-backup-scanner.php # Scan fichiers paginé
│   ├── class-backup-streamer.php # Streaming tar des fichiers
│   ├── class-backup-db.php     # Export SQL streaming
│   ├── class-backup-cleanup.php # Nettoyage fichiers temporaires
│   ├── class-collector.php     # Collecte des données site
│   ├── class-multisite.php     # Support multisite
│   ├── class-security.php      # Vérification HMAC
│   ├── class-settings.php      # Page de réglages
│   ├── class-test-session.php  # Test session auto-login
│   ├── class-remote-updater.php # MAJ composants à distance
│   └── class-updater.php       # Auto-update GitHub
└── admin/
    ├── settings-page.php       # Template réglages
    ├── css/admin.css
    └── js/admin.js
```

---

## Écosystème WBoard

Ce plugin fait partie de l'écosystème **WBoard** :

| Dépôt | Description |
|-------|-------------|
| [wboard-site](https://github.com/willybahuaud/wboard-site) | Dashboard Laravel + React |
| **wboard-connector** | Ce plugin WordPress |
| [wboard-backup-manager](https://github.com/willybahuaud/wboard-backup-manager) | Orchestrateur de sauvegardes incrémentales (Go) |
| [wboard-kuma](https://github.com/willybahuaud/wboard-kuma) | Uptime Kuma + proxy API REST (monitoring) |
| [wboard-backstop](https://github.com/willybahuaud/wboard-backstop) | Tests de régression visuelle (BackstopJS + Docker) |

---

## Changelog

Voir les [releases](https://github.com/willybahuaud/wboard-connector/releases) pour l'historique complet.

---

## Licence

GPL v2 or later

---

<p align="center">
  <sub>Fait avec soin pour les développeurs WordPress exigeants</sub>
</p>
