# Recette — KDV Leads 0.1.0

Environnement de test : WordPress 6.x local (XAMPP, PHP 8.2), e-mails capturés dans des fichiers (aucun envoi réel), navigateur de bureau.
Légende : ✅ vérifié · 🟡 vérifié en partie · ⬜ **à faire** sur l'hébergement réel ou sur un appareil réel.

Tests automatisés : `vendor/bin/phpunit` (31 tests) et `php tests/integration/recette.php …` (37 contrôles dans un vrai WordPress) — tous passent.

## TEST 1 — Parcours complet depuis un téléphone

| Contrôle | État |
|---|---|
| URL directe `/vendre-mon-vehicule` → 4 étapes → envoi → `/estimation-merci` | ✅ (navigateur, mode mobile émulé 390 px) |
| E-mail KDV : délai en tête, coordonnées, téléphone cliquable, véhicule, état, équipements, source, lien vers la fiche | ✅ (contenu vérifié dans l'e-mail capturé) |
| PDF joint exact, nom `KDV_ESTIMATION_AAAAMMJJ_NNNN_MARQUE_Modele.pdf` | 🟡 généré, joint et nommé correctement ; **rendu visuel à valider par KDV** (`docs/exemples/`) |
| Accusé client reçu (si e-mail fourni), absent sinon | ✅ |
| Réception dans la **vraie boîte KDV** depuis un **vrai téléphone** | ⬜ nécessite Brevo + DNS (SPF/DKIM/DMARC) et le site de production |
| Bouton existant du site → `/vendre-mon-vehicule` | ⬜ à configurer sur le site (hors périmètre) |

## TEST 2 — RETOUR, étapes bloquantes, validations

| Contrôle | État |
|---|---|
| RETOUR à chaque étape sans perte de données ; brouillon conservé après rechargement | ✅ |
| Étape 2 bloquée tant que les 4 questions ne sont pas répondues | ✅ |
| Étape 3 passable sans rien cocher | ✅ |
| Étape 4 bloquée sans délai, nom, téléphone ou consentement | ✅ |
| Validations téléphone, e-mail (si rempli), kilométrage, immatriculation (si remplie) — côté navigateur **et** serveur | ✅ |

## TEST 3 — Panne réseau et panne e-mail

| Contrôle | État |
|---|---|
| Réseau coupé côté client : message clair, données conservées, aucun faux succès, bouton réactivé | ✅ |
| Panne SMTP simulée : dossier enregistré, relances 5 min → 24 h, abandon au 7ᵉ échec, alerte à l'administrateur technique, bandeau d'admin, reprise automatique à la remise en service | ✅ (panne simulée, pas un vrai SMTP) |
| Double envoi : un seul dossier (4 requêtes parallèles, même clé) | ✅ |

## TEST 4 — Recherche personnalisée

| Contrôle | État |
|---|---|
| Accès par URL directe, page absente du menu et des listes de pages automatiques, `noindex` | ✅ |
| PDF distinct (`KDV_RECHERCHE_…`), objet distinct, confirmation `/recherche-merci` | ✅ |
| Dossier absent de la liste des estimations | ✅ |
| Photos : redimensionnement navigateur, contrôle MIME réel, ré-encodage (métadonnées supprimées), suppression, limite de 5, nettoyage > 24 h | ✅ (photos PNG/JPEG de test) |
| Photos d'iPhone (HEIC) | ⬜ le champ n'accepte que JPEG/PNG/WebP : iOS convertit alors la photo en JPEG au moment du choix. À vérifier sur un iPhone réel |

## TEST 5 — Responsive, accessibilité, sécurité

| Contrôle | État |
|---|---|
| Affichage 390 px et ordinateur, aucun défilement horizontal | ✅ |
| iPhone / Android / tablette **réels** | ⬜ |
| Navigation clavier : flèches sur les cartes radio, focus visible, focus sur le titre à chaque étape | 🟡 flèches et focus vérifiés ; parcours complet au clavier à refaire à la main |
| Lecteur d'écran (stepper, FAQ, annonces d'étape) | ⬜ non testé (balisage `aria-current`, `aria-live`, `aria-describedby` en place) |
| URL directe vers un PDF refusée (403) | ✅ sur Apache local ; **à refaire sur GoDaddy** (page Diagnostic) |
| Conversion déclenchée uniquement après succès réel (pas sur visite directe ni rechargement) | ✅ |
| Tri de l'administration par délai (dès que possible → … → je me renseigne, dossiers clos en dernier) | ✅ |
| Accès : Gestionnaire sans Réglages/Diagnostic/anonymisation ; actions sans nonce refusées | ✅ |

## Points à traiter avant la mise en production

1. Installer le zip sur l'hébergement, vérifier **Diagnostic** (version PHP, GD, limites, stockage privé, test d'accès public).
2. Configurer WP Mail SMTP + Brevo, authentifier le domaine, faire les TEST 1 et 3 en réel.
3. Activer la double authentification (administrateurs et gestionnaires).
4. Fournir : fin de la FAQ n°5 et FAQ n°6, rouge exact du logo, politique de confidentialité, décisions du §17 du cahier des charges. (Logo et photos : intégrés — formulaires, PDF, e-mails, back office.)
5. Valider le rendu des deux PDF et le texte de l'accusé client.
6. Mettre en place le cron système (README §4) et la sauvegarde base + stockage privé.
7. Back office « Dossiers KDV » modernisé (logo, indicateurs, liste, fiche) : à valider visuellement par KDV sur grand écran.
