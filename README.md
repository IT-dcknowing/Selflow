# Selflow

Gestion commerciale pour les entreprises ivoiriennes : ventes, achats, stocks
au CUMP (Coût Unitaire Moyen Pondéré), points de vente, B2B, trésorerie et
créances, avec la certification des factures auprès de la plateforme FNE de la
DGI. Selflow produit les écritures comptables et les déverse dans
**Comptaflow**, l'application comptable de DC-Knowing.

## À lire avant de travailler

| Fichier | Contenu |
|---|---|
| `CLAUDE.md` | Consignes de développement : la méthode de travail et la règle d'or (la conformité FNE est gelée) |
| `JOURNAL-DE-BORD.md` | La mémoire du projet : décisions arrêtées, état de chaque lot, anomalies connues |
| `PLAN-RETOURS-PRODUCTION-14-10-2026.md` | Les retours de la production depuis le 14/10/2026 et le lot qui répond à chacun |
| `api_mobille_selflow.md` | La spécification de l'API mobile (Laravel Sanctum) |
| `docs/passerelle-comptaflow/` | Les fichiers de la passerelle à appliquer côté Comptaflow |

## Pile technique

- PHP 8.3, Laravel 13, MySQL en production (SQLite pour les épreuves)
- Code organisé en modules : `app/Modules/Admin`, `app/Modules/Authentification`
- PDF par dompdf, Excel par PhpSpreadsheet, code QR par bacon-qr-code

## Installer et éprouver

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan test
```

## Déployer

Après **chaque** mise en ligne, dans cet ordre :

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
```

- `migrate` joue les migrations. Une migration oubliée ne fait plus tomber
  les pages (lot 125), mais la fonction qui en dépend reste inactive.
- `optimize:clear` puis `optimize` régénèrent les caches de routes, de
  configuration et de vues. Le serveur tourne avec ces caches : sans cette
  étape, une page qui appelle une route nouvelle tombe en
  « Route [...] not defined » (panne du 26/10/2026 sur
  `admin.ventes.annuler_commande`).

## Méthode

Une branche par section de travaux, au nom français, fusionnée dans `main` une
fois la suite verte, puis poussée sur les deux dépôts
(`IT-dcknowing/Selflow` et `meledjeabrahamagnimel-lgtm/Selflow`).
