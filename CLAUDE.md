# Procédure de release & signature Nextcloud App Store

## Certificat de signature d'app

- Le certificat de signature (`markdownsite.crt`) est émis par Nextcloud via une
  demande (CSR) soumise au dépôt `nextcloud/app-certificate-requests`. Nextcloud
  signe la CSR et commit le `.crt` dans ce dépôt (événement déjà survenu pour
  `markdownsite`, certificat valide 2026-07-20 → 2036-10-25).
- La clé privée correspondante n'est **jamais** committée dans ce repo ni présente
  dans cet environnement d'exécution (containers éphémères) : elle reste côté
  utilisateur, en dehors de git.
- Rien dans le code de `markdownsite` ne change lors de l'émission/renouvellement
  du certificat — c'est un artefact externe utilisé uniquement au moment de signer
  une release.

## Construire le tarball de release

À chaque nouvelle version prête à publier sur apps.nextcloud.com :

1. Vérifier que `js/` (bundle webpack compilé) est à jour avec `src/` :
   `git log -1 --format=%H -- js/` doit correspondre à `git log -1 --format=%H -- src/`
   (sinon lancer `npm run build`).
2. Installer les dépendances PHP de prod uniquement :
   `COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction`
3. Assembler un dossier `markdownsite/` contenant seulement le runtime :
   - Inclus : `appinfo/`, `lib/`, `js/`, `img/`, `templates/`, `vendor/`,
     `composer.json`, `CHANGELOG.md`, `README.md`
   - Exclu : `src/` (sources Vue brutes, non nécessaires au runtime), `tests/`,
     `node_modules/`, `package.json`/`package-lock.json`, `webpack.config.js`,
     `phpunit.xml.dist`, `.git*`, `composer.lock`, `screenshots/` (déjà référencées
     via raw.githubusercontent dans `info.xml`)
4. Archiver : `tar -czf markdownsite-X.Y.Z.tar.gz --numeric-owner --owner=0 --group=0 markdownsite`
   — le dossier top-level de l'archive doit être `markdownsite/` avec
   `markdownsite/appinfo/info.xml` directement dedans.
5. Vérification rapide : `tar -tzf *.tar.gz | grep appinfo/info.xml`,
   lint PHP sur `lib/` (`find lib -name "*.php" -exec php -l {} \;`).

Tout se construit dans le scratchpad de session, jamais dans le repo lui-même.

## Signature + upload (toujours manuel, côté utilisateur)

Claude Code ne peut pas effectuer ces étapes (pas d'accès à la clé privée, upload
sur un service tiers) :

1. Décompresser le tarball, puis signer avec la clé privée + le certificat :
   ```
   php occ integrity:sign-app \
     --privateKey=/chemin/vers/markdownsite.key \
     --certificate=/chemin/vers/markdownsite.crt \
     --path=/chemin/vers/markdownsite-extrait/
   ```
2. Réarchiver le dossier signé.
3. Uploader sur https://apps.nextcloud.com (espace développeur) avec la signature
   générée par `occ`.
