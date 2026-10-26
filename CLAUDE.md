# Selflow — consignes de développement

> **Avant toute chose : lire `JOURNAL-DE-BORD.md`.** Il porte l'état du projet,
> les décisions arrêtées, les anomalies déjà constatées et l'ordre des lots. Une
> session qui démarre sans lui refera des analyses déjà faites et rediscutera des
> décisions déjà prises. Le tenir à jour à chaque lot terminé fait partie du lot.


## Méthode de travail

- **Une branche par section de travaux**, au nom français tiré de la section
  (`abonnement-en-attente-de-confirmation`, `export-inventaire-pdf-excel`…).
- Une fois la section terminée et la suite d'épreuves verte : fusion dans
  `main`, puis poussée sur les deux dépôts (`IT-dcknowing/Selflow` et
  `meledjeabrahamagnimel-lgtm/Selflow`).
- Le journal de bord et le plan des retours se tiennent à jour dans la même
  branche.
- Une migration nouvelle ne doit jamais faire tomber l'application tant
  qu'elle n'est pas jouée sur le serveur (erreur 1054 du 22/10/2026).

## Règle d'or : la conformité FNE est gelée

**Ne plus modifier le code, les champs ni les données de la FNE (DGI) pendant
le reste du développement.** L'interfaçage a été validé contre le référentiel
et contre des pièces réellement certifiées ; toute retouche remet en jeu une
conformité fiscale acquise au prix de nombreuses corrections.

Sont gelés :

| Périmètre | Fichiers |
|---|---|
| Construction et envoi des payloads | `app/Modules/Admin/Services/FneService.php` |
| Barème du timbre de quittance | `app/Modules/Admin/Services/TimbreQuittanceService.php` |
| Code QR de vérification | `app/Modules/Admin/Services/QrCodeFneService.php` |
| Alerte de stickers | `app/Modules/Admin/Services/AlerteStickersService.php` |
| Codes TVA, taux et régimes d'exonération | `Produit::CODES_TVA`, `TAUX_TVA_DGI`, `REGIMES_EXONERATION_LEGALE` |
| Colonnes `fne_*`, `numero_fne`, `qr_code_data`, `signature_dgi`, `normalise`, `est_rne`, `numero_rne`, `type_piece` | migrations et modèles `Vente` / `Achat` |
| Blocs de certification des documents imprimés | `Vues/factures/*.blade.php` |
| Tests de conformité | `tests/Feature/FnePayloadTest.php` |

Trois exceptions, et trois seulement :

1. la DGI publie une nouvelle version du référentiel ;
2. la plateforme rejette une pièce et le journal le prouve ;
3. le propriétaire du projet le demande explicitement.

Hors de ces cas, une modification qui traverse ce périmètre doit être signalée
et refusée, même si elle paraît anodine ou si elle sert une refonte en cours.

### Pourquoi cette règle

Chacun des écarts ci-dessous a produit une pièce certifiée différente de celle
établie dans Selflow, sans que rien ne le signale :

- un taux de TVA à 5 % partait sous le code `TVA`, que la plateforme applique
  à 18 % ;
- l'avoir recalculait ses lignes à 18 %, exonérations comprises, faute d'une
  colonne `montant_ht` qui n'existe pas ;
- le timbre de quittance était estimé à 1,5 %, taux qui ne figure dans aucun
  texte, là où l'article 873 du CGI fixe un barème forfaitaire par tranche ;
- le bordereau d'achat appliquait la TVA du catalogue alors que le payload
  n'en transmet aucune ;
- les régimes d'exonération légale retenaient `RNE`, sigle du reçu normalisé,
  au lieu de `TCE` et `RME` ;
- l'adresse de l'environnement de test était inventée.

Les tests de `FnePayloadTest` verrouillent ces points. Ils ne sont pas une
formalité : ils sont la mémoire de ce qui a été vérifié.

## Ce qui reste ouvert

Le reste de l'application — stocks, comptabilité, imputations, journaux,
règlements, paramétrage des modules par secteur d'activité — est en cours de
refonte et n'est couvert par aucune de ces restrictions.

Un point de contact demande de l'attention : `ComptabiliteService` lit
`montant_autres_taxes` et le timbre pour établir ses écritures. Faire évoluer
la comptabilité est libre ; changer ce que la FNE transmet ne l'est pas.
