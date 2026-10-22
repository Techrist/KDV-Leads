# KDV Leads — plugin WordPress de KDV Motors

Collecte les **demandes d'estimation** (dépôt-vente) et les **recherches personnalisées** de `kdv-motors.com`,
les enregistre en base, génère une fiche PDF et prévient KDV par e-mail. **Aucun prix n'est calculé ni affiché.**

| Parcours | Page | Shortcode | Référence |
|---|---|---|---|
| Estimation vendeur | `/vendre-mon-vehicule` | `[kdv_estimation]` | `KDV-EST-AAAAMMJJ-NNNN` |
| Recherche acheteur | `/recherche-personnalisee` (hors menu, `noindex`) | `[kdv_recherche]` | `KDV-REC-AAAAMMJJ-NNNN` |
| Pages de confirmation | `/estimation-merci`, `/recherche-merci` | `[kdv_estimation_merci]`, `[kdv_recherche_merci]` | — |

Règle d'or : **une panne d'e-mail ne fait jamais perdre un dossier.** Le dossier est enregistré avant le PDF et avant
les e-mails ; le succès affiché au client dépend uniquement de l'enregistrement en base.

---

## 1. Prérequis

- WordPress ≥ 6.0, **PHP ≥ 8.1** (*Outils → Santé du site*), extensions `gd`, `fileinfo`, `mbstring`, `exif`.
- `memory_limit` ≥ 256 Mo. Les limites d'envoi (`upload_max_filesize`, `post_max_size`) sont affichées dans **Dossiers KDV → Diagnostic**.
- Un plugin d'envoi SMTP (voir §3) et un plugin d'authentification à deux facteurs (voir §6).

## 2. Installation

1. *Extensions → Ajouter → Téléverser* le zip `kdv-leads-x.y.z.zip` (le dossier `vendor/`, avec mPDF, est inclus : ni SSH ni Composer requis).
   Si le zip dépasse la limite de téléversement de l'hébergement, le dézipper et l'envoyer par FTP dans `wp-content/plugins/`.
2. **Activer** l'extension. Elle crée : les 6 tables `kdv_*`, le rôle *Gestionnaire KDV*, le dossier de stockage privé et les 4 pages ci-dessus.
3. Ouvrir **Dossiers KDV → Diagnostic** : tout doit être ✅ (voir §4 pour les avertissements courants).
4. Ouvrir **Dossiers KDV → Réglages** et renseigner au minimum : destinataire(s) KDV, adresse expéditrice, e-mail de l'administrateur technique, URL de la politique de confidentialité.
5. *Réglages → Permaliens* : choisir « Titre de la publication » pour que les URL soient `/vendre-mon-vehicule`, etc.
6. Pointer les boutons existants du site vers `/vendre-mon-vehicule`. **Ne pas** ajouter `/recherche-personnalisee` au menu
   (le plugin la masque aussi des listes de pages automatiques des thèmes).

La **page d'accueil et le thème ne sont pas modifiés** par ce plugin.

## 3. E-mails (WP Mail SMTP + Brevo)

Le plugin envoie avec `wp_mail()` et **ne stocke aucun identifiant SMTP**. Configurer le transport avec le plugin **WP Mail SMTP**
relié à un service transactionnel par **API HTTPS** (Brevo par défaut), puisque le SMTP sortant est souvent filtré chez les hébergeurs.

1. Créer le compte Brevo, **authentifier le domaine `kdv-motors.com`** (enregistrements SPF, DKIM, DMARC dans la zone DNS).
2. Dans WP Mail SMTP : mailer *Brevo*, clé API, expéditeur = adresse du domaine (ex. `contact@kdv-motors.com`).
3. Réglages KDV : même adresse dans « Adresse expéditrice », adresse(s) de réception KDV dans « Destinataire(s) ».
4. **Diagnostic → Envoyer un e-mail de test**, puis faire une vraie demande de bout en bout.

Fonctionnement de la file d'attente (`kdv_email_queue`) :

- chaque message est enregistré puis envoyé immédiatement ; en cas d'échec, relances à 5 min, 15 min, 1 h, 3 h, 12 h, 24 h, puis abandon ;
- au **premier échec** d'une notification KDV et à tout **abandon**, une alerte part vers l'administrateur technique et un bandeau rouge s'affiche dans l'administration WordPress ;
- sur la fiche d'un dossier : état de chaque e-mail et bouton **Renvoyer**.

L'accusé de réception client n'est envoyé que si le client a donné une adresse e-mail (facultative pour l'estimation).

## 4. Tâches planifiées (cron)

Le worker d'e-mails tourne toutes les 5 minutes via WP-Cron. Sur un site peu visité, WP-Cron peut prendre du retard :
**recommandé** — désactiver WP-Cron et appeler `wp-cron.php` par un cron système (ou un ping externe) toutes les 5 minutes.

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```

```
*/5 * * * * curl -s https://kdv-motors.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

Autres tâches : nettoyage des photos non rattachées (toutes les heures, > 24 h), anonymisation selon la durée de conservation (quotidienne), régénération des PDF en échec.

## 5. Stockage privé des fichiers (PDF, photos)

Les PDF et photos ne sont **jamais servis directement** : seul l'administration les délivre, après contrôle des droits et d'un nonce.

- **Idéal** : définir dans `wp-config.php` un dossier **hors de la racine web** :
  ```php
  define('KDV_STORAGE_PATH', '/home/UTILISATEUR/kdv-private');
  ```
- **Repli** (par défaut) : `wp-content/uploads/kdv-private-<aléatoire>/` protégé par `.htaccess` (`Require all denied`) et `index.php`.
  *Diagnostic → Accès public au stockage* doit répondre « refusé (HTTP 403/404) ». Si ce test échoue (serveur sans prise en compte des `.htaccess`, ex. Nginx), définir `KDV_STORAGE_PATH`.

## 6. Accès et sécurité

- Rôle **Gestionnaire KDV** : voit et traite les dossiers (liste, fiche, export CSV), pas les Réglages ni le Diagnostic. À la connexion il arrive sur « Dossiers KDV ».
  Les **administrateurs** ont en plus les Réglages, le Diagnostic et l'anonymisation d'un dossier.
- **Double authentification obligatoire** pour administrateurs et gestionnaires : installer **Wordfence Login Security** ou **WP 2FA**, activer l'exigence 2FA pour ces rôles, remettre les codes de secours à chaque utilisateur.
- Anti-spam : champ piège, délai minimal de remplissage (8 s), limite d'envois par IP (5/heure), **Cloudflare Turnstile** activable (clés dans Réglages).
  Derrière un proxy/CDN, vérifier que l'IP vue par PHP est bien celle du visiteur, sinon la limite par IP s'applique à tous.
- Les e-mails peuvent contenir des données personnelles : l'accès à la boîte de réception KDV doit être protégé.

## 7. RGPD

- Case de consentement au recontact obligatoire, texte versionné (Réglages → version du texte). Consentement marketing : désactivé par défaut (option).
- **Conservation** : 36 mois après la dernière activité (Réglages), puis anonymisation automatique ; les e-mails envoyés sont purgés de la file après 90 jours.
- **Accès / effacement** : outils WordPress *Outils → Exporter / Effacer les données personnelles* (recherche par e-mail) ; pour une demande **par téléphone**,
  utiliser la fiche du dossier : *Exporter les données (JSON)* et *Anonymiser ce dossier* (administrateur).
- L'anonymisation efface nom, téléphone, e-mail, notes, PDF, photos et copies d'e-mails ; elle garde la référence, le véhicule et les dates pour les statistiques.
- Mesure d'audience : l'événement de conversion (`dataLayer` : `kdv_lead`) n'est émis **que sur la page de confirmation, après un envoi réussi**, sans donnée personnelle.
  Il respecte le bandeau de consentement du site (`gtag` / `fbq` ne sont appelés que s'ils sont déjà chargés).
- Compléter la **politique de confidentialité** (rédigée par KDV) : finalités, base légale, durée, destinataires (KDV, prestataire e-mail, hébergeur, futur fournisseur d'immatriculation), droits.

## 8. Sauvegardes

Inclure dans la sauvegarde de l'hébergeur : la base (tables `<préfixe>kdv_*`) **et** le dossier de stockage privé (§5). Tester une restauration avant la mise en production.

## 9. Maintenance

| Besoin | Où |
|---|---|
| Vérifier l'hébergement, le stockage, les e-mails en échec | Dossiers KDV → Diagnostic |
| Un e-mail n'est pas arrivé | Fiche du dossier → bloc *E-mails* → *Renvoyer* |
| Régénérer un PDF | Fiche du dossier → *Régénérer* |
| Modifier les textes de la FAQ | Réglages → *Questions fréquentes* |
| Exporter | Liste → *Exporter en CSV* (filtres appliqués, séparateur `;`, UTF-8 avec BOM, journalisé) |
| Journal d'un dossier | Fiche → *Historique* ; erreurs techniques dans `wp-content/debug.log` si `WP_DEBUG_LOG` est actif |

**FAQ** : une question dont le texte contient encore `[À FOURNIR]` n'est **pas publiée**. Compléter la fin de la question 5 et la question 6 dans les Réglages.

**Désinstallation** : par défaut, les données sont conservées. L'option « Supprimer toutes les données à la désinstallation » (Réglages) est volontairement désactivée.

## 10. Extensions et hooks

```php
do_action('kdv_lead_created', $lead);                       // après l'enregistrement d'un dossier
do_action('kdv_lead_status_changed', $lead, $old, $new);    // changement de statut
add_filter('kdv_plate_lookup', fn () => new MonFournisseur()); // reconnaissance par immatriculation (P1)
```

La recherche par immatriculation est **préparée mais inactive** : `GET /wp-json/kdv/v1/plate/{plaque}` répond 501 tant qu'aucun fournisseur
(`KdvLeads\Vehicle\PlateLookup`) n'est branché. Fournisseur et budget : décision KDV.

## 11. Développement

```bash
composer install            # avec les outils de test
vendor/bin/phpunit          # 31 tests unitaires (sans WordPress)
php tests/integration/recette.php /chemin/wp-load.php http://localhost/site   # recette automatisée dans un vrai WordPress
php bin/build-release.php   # produit dist/kdv-leads-<version>.zip (vendor sans dev, polices mPDF réduites)
```

Visuels fournis par KDV : `assets/img/logo-kdv.png` (logo), `ill-suv.webp`, `ill-wheel.webp`, `ill-m5.webp` (photos détourées) (illustrations des étapes, affichées sur ordinateur uniquement). Le style du back office est dans `assets/css/kdv-admin.css`.

Aperçu statique des composants : `tests/preview-estimation.html`. Maquette de référence : `docs/maquettes/`.
Code : PHP (préfixe `kdv_`, nonces, `$wpdb->prepare`, échappement), JavaScript sans framework ni build, CSS préfixé `.kdv-`.

## 12. Coûts récurrents (à renseigner par KDV)

| Poste | Estimation mensuelle |
|---|---|
| Hébergement GoDaddy | existant |
| Service d'e-mails transactionnels (Brevo) | à vérifier sur la grille tarifaire du fournisseur (volume attendu : quelques dizaines d'e-mails par jour) |
| API d'immatriculation (P1, non activée) | selon fournisseur et nombre de requêtes |
| Cloudflare Turnstile | gratuit (à vérifier) |

## 13. Points ouverts

Voir le tableau « Décisions en attente » du cahier des charges (§17) : rouge exact du logo, finition obligatoire ou non, texte de l'accusé client,
numéros étrangers, consentement marketing, indexation de la page recherche, durée de conservation, fournisseur d'immatriculation, illustrations, textes FAQ manquants.
Chaque valeur provisoire est centralisée dans `includes/Config.php` et modifiable dans les Réglages.
