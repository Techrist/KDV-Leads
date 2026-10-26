# Audit de sécurité — KDV Leads 0.1.0

Audit du 22/10/2026, corrections appliquées le 26/10/2026 après le signal de KDV, puis contre-attaque complète.
Périmètre : plugin `kdv-leads` (code et zip de release) et son installation locale (XAMPP, WordPress local).

## Méthode de vérification

Les attaques ont été rejouées sur **la copie livrable** (le zip de `dist/`) installée dans un bac à sable isolé
(base MariaDB séparée, mot de passe aléatoire, WordPress copié, PHP limité à 256 Mo comme un hébergement mutualisé).
La suite est conservée dans `tests/security/` (voir son `README.md`) et se rejoue en une commande.

| Contrôle | Résultat |
|---|---|
| Tests unitaires PHPUnit | 43 tests, 115 assertions, tous verts |
| Recette d'intégration (`tests/integration/recette.php`) | 37 réussis, 0 échec, 1 ignoré (accès HTTP au stockage : propre à Apache) |
| Suite d'attaque, groupes A à K | tous réussis après correction |
| Exposition de fichiers sur Apache | 403 sur `.git`, `composer.*`, `README.md`, `docs`, `dist`, `tests`, `vendor`, `includes`, `kdv-dev-mails` ; les fichiers CSS/JS restent servis (200) |

## Statut des constats

| # | Gravité | Faille | Statut | Correction |
|---|---|---|---|---|
| P1 | Moyenne | Anti-spam contournable (durée envoyée par le navigateur) | **Corrigé** | Jeton de formulaire signé (HMAC) émis par le serveur : l'heure de chargement est dans le jeton, impossible à falsifier ; validité 24 h, durée minimale mesurée côté serveur, 12 usages maximum. Champ piège conservé. |
| P2 | Moyenne | Course sur le limiteur | **Corrigé** | Compteurs atomiques (`kdv_counters`, un seul `INSERT … ON DUPLICATE KEY UPDATE`). Testé avec 20 processus simultanés : jamais plus de 5 dossiers. |
| P3 | Moyenne | Relais d'e-mails vers des tiers | **Corrigé** | Accusé limité à 2 par adresse et par jour (réglable) ; le contenu ne reprend plus aucun texte libre ; phrase « Vous n'êtes pas à l'origine de cette demande ? » ajoutée. |
| P4 | Moyenne | Déni de service par image | **Corrigé** | Plafond 24 Mpx et contrôle de la mémoire disponible **avant** le décodage (octets par pixel estimés par format, rotation comprise). Les JPEG et PNG de 36 Mpx sont refusés (422) sans erreur fatale. |
| P5 | Faible | Contenu hostile dans les documents du personnel | **Corrigé** | Liens d'annonces affichés en texte simple dans le PDF ; objets d'e-mail construits avec un texte assaini et tronqué. |
| P6 | Faible | Confusion de type (tableau à la place d'un texte) | **Corrigé** | `Validator::normalise()` ramène chaque champ à un type attendu avant toute validation. |
| P7 | Faible | Caractères Unicode de contrôle | **Corrigé** | `Validator::clean()` retire les caractères de contrôle et d'inversion de lecture. |
| P8 | Faible | Requêtes invalides illimitées | **Corrigé** | Limiteur de tentatives (60/heure/IP) placé avant la recherche d'idempotence. |
| P9 | Faible | Limite par IP fragile derrière proxy | **Corrigé** | Réglage « Adresse IP du visiteur » (direct / Cloudflare / en-tête transmis) ; les en-têtes falsifiés sont ignorés par défaut. La page Diagnostic montre l'IP vue par le serveur. |
| P10 | Faible | Turnstile « fail open » | **Accepté** | Choix assumé : mieux vaut un dossier de plus qu'un dossier perdu si Cloudflare est injoignable. L'incident est consigné. Turnstile reste recommandé en production (clés à saisir dans les réglages). |
| P11 | Faible | Course sur le nombre de photos par session | **Corrigé** | Réservation atomique d'un emplacement avant traitement : 12 envois simultanés donnent exactement 5 photos. Quotas par IP, par session et par fichier. |
| P12 | Faible | Divulgation de versions (`vendor`, `composer.*`) | **Corrigé** | `.htaccess` à la racine du plugin, dans `includes/` et `vendor/` (compatibles Apache 2.4 et 2.2), inclus dans le zip. |
| P13 | Faible | Aucun en-tête de sécurité | **Corrigé** | Sur les seules pages KDV : `X-Frame-Options`, `frame-ancestors 'self'`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, pas de cache. |
| P14 | Faible | Secrets en clair, erreurs SMTP exposées | **Corrigé** | Clés chiffrées (AES-256-GCM, préfixe `enc1:`), migration automatique ; le détail des erreurs d'envoi n'est visible que des administrateurs. |
| P15 | Faible | Stockage protégé uniquement par `.htaccess` | **Atténué** | Le dossier privé accepte `KDV_STORAGE_PATH` (hors racine web, recommandé sur nginx/LiteSpeed). Sinon `.htaccess` renforcé (+ `Options -Indexes`). À régler à l'hébergement. |
| P16 | Info | Référence séquentielle révélant le volume | **Accepté** | Format imposé par le cahier des charges. |
| P17 | Info | Notes internes dans l'export RGPD | **Accepté** | Décision juridique laissée à KDV (les notes sont des données personnelles à fournir sur demande d'accès). |
| P18 | Info | Copies d'e-mails conservées 90 jours | **Corrigé** | 30 jours, purge exécutée même si la conservation des dossiers est à 0. |
| P19 | Faible | *Nouveau, trouvé pendant les corrections* : `wp_http_validate_url` résolvait le DNS (1,5 s, requêtes sortantes déclenchables par un visiteur) | **Corrigé** | `Validator::is_safe_link()` : http/https seulement, sans identifiant, hôte ASCII avec point, aucune résolution DNS. |

## Environnement local

| # | Constat | Statut |
|---|---|---|
| E1 | Outil de test `kdv-dev-login.php` (connexion sans mot de passe) | **Supprimé**. Il n'existe plus (`?kdv_dev_login=` ne fait plus rien). |
| E2 | Apache servait `.git`, `composer.lock`, `docs`, `dist` | **Corrigé** par `.htaccess` (403 vérifié). |
| E3 | `wp-content/kdv-dev-mails/` listable | **Corrigé** (403). Le capteur d'e-mails de test `kdv-dev-mail.php` est désactivé (`.off`). |
| E4 | En-têtes `Server` / `X-Powered-By` | **Accepté** : relève de l'hébergement (`ServerTokens Prod`, `expose_php = Off`). |
| E5 | Disque plein | Libéré par KDV. |

## Avant la mise en ligne (liste de contrôle)

1. Saisir les clés **Turnstile** dans Réglages KDV.
2. Choisir « Adresse IP du visiteur » selon l'hébergeur (Cloudflare : `Cloudflare`). Contrôler la page Diagnostic.
3. Définir `KDV_STORAGE_PATH` hors de la racine web si l'hébergeur n'est pas Apache.
4. Vérifier en HTTPS : `curl -I https://…/vendor/composer/installed.json` doit répondre 403 ou 404.
5. Ne pas copier `kdv-dev-mail.php.off` ni aucun mu-plugin de test sur le site réel.
6. Régler `ServerTokens Prod` et `expose_php = Off` si l'hébergeur le permet.
