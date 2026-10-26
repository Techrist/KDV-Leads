# Tests d'attaque (sécurité)

Suite rejouable qui attaque **la copie livrable** du plugin (le zip de `dist/`) dans un bac à sable isolé :
base MariaDB à part (port 3307, mot de passe aléatoire), WordPress copié, serveur PHP sur `127.0.0.1:8099`.
Rien n'est lu ni écrit dans le vrai site ou sa base. Ce dossier n'est pas livré dans le zip.

```powershell
php bin\build-release.php                                          # produit dist\kdv-leads-x.y.z.zip
powershell -ExecutionPolicy Bypass -File tests\security\setup-sandbox.ps1
php -d memory_limit=1024M $env:TEMP\kdv-sandbox\attack.php          # tous les groupes (ou : attack.php C)
powershell -ExecutionPolicy Bypass -File tests\security\setup-sandbox.ps1 -Stop
```

| Groupe | Contenu |
|---|---|
| A | En-têtes de sécurité, `.htaccess` livrés |
| B | Jeton de formulaire signé : falsifié, trop rapide, périmé, réutilisé |
| C | Limiteurs atomiques sous concurrence (20 processus), tentatives en rafale, en-têtes IP falsifiés |
| D | Accusés de réception : bombardement d'une victime, texte libre, objet, injection d'en-têtes |
| E | Images : bombe de pixels (JPEG/PNG), réservation simultanée, polyglotte PHP, SVG, faux JPEG |
| F | Types incohérents et caractères invisibles |
| G | Matrice de droits (visiteur, abonné, éditeur, Gestionnaire, administrateur), CSRF |
| H | XSS stocké de bout en bout (liste, fiches, e-mails), injections SQL chronométrées |
| I / K | Secrets chiffrés, détail des erreurs SMTP réservé aux administrateurs, PDF, conservation |
| J | Liens d'annonces : aucune résolution DNS, astuce `user@hôte` refusée |

Limites : le serveur PHP intégré ignore `.htaccess`. Les protections propres à Apache (403 sur `vendor/`, `includes/`,
stockage privé) se vérifient sur Apache : `curl -I http://localhost/<site>/wp-content/plugins/kdv-leads/vendor/composer/installed.json`.
