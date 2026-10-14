# Plan — retours de la mise en production (14/10/2026)

Sources : la demande du propriétaire du 14/10/2026, `retest de selflow.docx`
(six images). Le second document, « des corrections à revoir pour
selflow.docx », **n'est pas parvenu** dans la session : il sera traité dès
réception.

Règle d'or : aucun fichier du périmètre FNE gelé n'est touché (voir
`CLAUDE.md`). Un lot = une branche, fusionnée dans `main` et poussée sur les
deux dépôts une fois la suite verte.

## Avancement au 14/10/2026

| Lot | État | Fusion sur `main` |
|---|---|---|
| 64 Mot de passe oublié, courriels | **Terminé** | `880fa4c` |
| 65 Écrans du retest | **Terminé** | `23dbd54` |
| 67 Écritures d'avoir | **Terminé** | `d7a89fa` |
| 66 Cycle d'achat | **Terminé** | `cbd50f2` |
| 68 Stickers par DC-Knowing | **Terminé** | `641d6fc` |
| 69 Vente pas à pas | **Terminé** | `91f052b` |
| 70 B2B approfondi | **Validé, à programmer** (étude ci-dessous) | — |
| 71 Erreur 500 de la fiche article, mot de passe, recherches, import du stock | **Terminé** | `3b6d3ac` |
| 72 Plan comptable modifiable, Gestion FNE | **Terminé** | `1376c21` |
| 73 Page d'accueil | **Terminé en partie** (textes des applications attendus) | — |

Poussé sur les deux dépôts (`IT-dcknowing/Selflow`, `meledjeabrahamagnimel-lgtm/Selflow`).

## Correctifs

| Lot | Branche | Ce qui est demandé | Ce qui a été trouvé | Ce qui sera fait |
|---|---|---|---|---|
| **64** | `correctifs-mot-de-passe-et-courriels` | Mot de passe oublié : aucun courriel, le bouton mène directement au changement ; vérifier aussi les courriels de création | **Faille** : le lien s'affichait à l'écran de quiconque tapait une adresse, en production. Transport par défaut `log` : sans `MAIL_MAILER`, rien ne part et l'écran dit « envoyé ». Aucun courriel à la création d'un compte | Lien affiché en local seulement ; même réponse que l'adresse existe ou non ; un serveur sans transport le dit ; jeton vérifié avant d'ouvrir le formulaire ; courriel HTML. Avis d'ouverture de compte (inscription, employé, responsable créé par le superadmin, admin interne), sans mot de passe. `php artisan selflow:verifier-courriel [adresse]` |
| **65** | `correctifs-ecrans-retest` | Fusée de la bannière ; avatar cassé ; domaine → étape suivante sans « Continuer » ; recherche du catalogue limitée à la page ; vente « ne passe pas », sans message | Avatar servi par une route réservée au rôle `admin` ; la recherche filtre la page affichée ; deux refus de la vente (stock insuffisant, lot périmé) posés sous une clé que le gabarit n'affiche pas — la page se recharge sans rien dire ; une rupture constatée dans la transaction tombe en page d'erreur ; le panier n'est jamais vidé | Fusée retirée ; image par défaut (initiales) si la photo manque ; clic sur le domaine = étape suivante ; recherche côté serveur sur tout le catalogue ; tout refus affiché ; message de confirmation qui disparaît en 3 s ; panier vidé après la vente |
| **66** | `cycle-achat-actions` | Un seul bouton selon l'ordre (« Bon de réception » ou « Confirmer la commande ») ; actions dans la liste DP / BC ; à la facturation, demander si la facture sera déclarée par le fournisseur | La liste n'a aucune action Confirmer / Facturer ; l'achat créé par le B2B s'écrit « Bon de Commande » (C majuscule) et échappe aux contrôles | Bouton selon l'étape (DP → Confirmer la commande ; BC → Réceptionner / Facturer) ; actions dans la liste ; question « déclarée par le fournisseur à la FNE ? » : **oui → la saisie reste informative, sans écriture ; c'est la facture reçue de la DGI qui passe en écriture** ; non → écriture comme aujourd'hui |
| **67** | `ecritures-avoir` | Vérifier que l'avoir passe en écriture, en négatif de l'écriture d'origine | L'avoir contre-passe bien, comptes d'origine et sens inversé. **Mais il ne contre-passait que le TTC** (taxes parafiscales restées au débit du client) ; le remboursement en espèces ne s'écrivait pas ; le retour de marchandise entrait au coût du jour | Taxes contre-passées au prorata, remboursement écrit (411 / caisse), retour au coût de la sortie. **Le timbre reste dû** (la plateforme ne le porte sur aucun avoir) — à confirmer si l'entreprise le rembourse aussi |
| **68** | `demande-de-stickers` | Remettre l'achat des stickers : DC-Knowing achète à la place du client ; choix stocké, envoyé par courriel et visible chez le superadmin | L'écran a été retiré au lot 40 ; il ne reste que les compteurs | Écran « Commander des stickers » (quantité, point de vente, commentaire) qui dit clairement que DC-Knowing achète auprès de la DGI ; demande enregistrée, courriel à DC-Knowing, page superadmin avec statut (reçue, achetée, livrée) |
| **69** | `vente-pas-a-pas` | Nouvel affichage de la vente en étapes, accessible par un bouton comme la bascule du catalogue | La caisse est une seule page | Bascule « Caisse / Pas à pas ». Étapes : 1 Articles, 2 Client et document, 3 Règlement, 4 Récapitulatif et facturation. Même formulaire, même enregistrement : rien ne change côté serveur ni FNE |

## Évolutif — communication B2B (lot 70) : étude de faisabilité

**Verdict : faisable, sans toucher au périmètre FNE gelé**, en quatre lots
d'environ une journée chacun. Une bonne part existe déjà ; le reste est surtout
de l'orchestration entre deux pièces qui existent (la vente du fournisseur,
l'achat du client).

### Ce qui existe

| Brique | État |
|---|---|
| Mise en relation | par le NCC : un fournisseur (ou client) dont le NCC est celui d'une entreprise inscrite la reçoit dans son espace |
| `b2b_negotiations` | demande, lignes demandées, prix proposé, statut (RFQ, Negociation_Client, Negociation_Fournisseur, Valide, Refuse, Termine), historique de messages (JSON) |
| Écrans | « Négociations B2B (Achats) » côté client, « Demandes B2B reçues » côté fournisseur ; contre-proposition de prix avec message ; vérification du stock du fournisseur |
| Finalisation | le fournisseur « finalise » : il crée **directement sa facture** (certifiée FNE) et un achat « En attente B2B » chez le client, que celui-ci accepte |

### Ce qui manque au parcours voulu

| Étape voulue | Manque |
|---|---|
| Le client envoie une demande de prix, le fournisseur répond, le client **voit la réponse** | existe ; à rendre lisible (fil de messages au lieu d'un historique brut) |
| Le client n'est pas satisfait : il **écrit** au fournisseur, propose un prix | existe en partie (prix + message) ; manque un vrai fil de discussion, des notifications |
| Le client clique **« Commander »** → le fournisseur voit un **bon de commande** | manque : aujourd'hui la négociation saute directement à la facture |
| Le fournisseur **confirme le BC selon son stock** → statut **« BC confirmé — en attente de livraison »** des deux côtés | manque : aucun statut intermédiaire, aucune réservation |
| À la livraison, un bouton envoie en **facture** ; le client confirme la **réception** | manque : la facture part sans livraison ; la réception côté client n'existe pas |

### Défauts de l'existant à corriger en premier (relevés le 14/10)

1. **Le BC transmis depuis une vente inverse client et fournisseur**
   (`VenteControleur::transmettreLaCommandeEnB2b`) : l'acheteur le reçoit
   comme s'il était le vendeur. Le corriger seul ferait paraître le BC chez le
   vendeur comme une demande « à finaliser » et créerait une **seconde vente** :
   il faut le corriger avec 70b, pas avant.
2. Ses lignes n'ont pas de `reference` : la finalisation, qui la lit, tomberait.
3. L'achat créé par la finalisation n'est pas marqué « déclaré par le
   fournisseur » alors qu'il l'est toujours (le fournisseur certifie) : avec le
   lot 66, il doit l'être, et c'est la facture DGI qui portera l'écriture.

### Découpage proposé

| Lot | Contenu |
|---|---|
| **70a** Fil de discussion | messages horodatés des deux côtés, contre-proposition ligne à ligne, badge « nouveau message », courriel à l'autre partie |
| **70b** Commander et confirmer | bouton « Commander » côté client → BC chez le fournisseur (sa pièce `BC-`) et BC fournisseur chez le client (`BCF-`) ; le fournisseur confirme selon son stock (contrôle déjà écrit) → **« Confirmé — en attente de livraison »** des deux côtés ; refus motivé possible. Correction des défauts 1 et 2 |
| **70c** Livrer, facturer, réceptionner | le fournisseur livre (BL existant) puis facture (sa facture, certifiée FNE comme toute vente) ; chez le client l'achat passe « Livré — à réceptionner » ; le client clique « Réceptionné » → stock entré, facture d'achat **informative** (défaut 3), l'écriture venant de la facture DGI |
| **70d** Notifications | courriel et compteur au menu à chaque changement d'état |

**Tranché par le propriétaire le 14/10/2026** : un client B2B **peut
commander directement**, sans demande de prix. 70b ouvre donc deux portes :
« Commander » depuis une réponse à une demande de prix, et une commande
directe au fournisseur.

**Timbre d'une facture annulée** (21.10) : tranché le 14/10/2026 — **il n'est
pas remboursé** ; la règle du lot 67 est la bonne.

## Second retest (« des corrections à revoir pour selflow.docx ») — 14/10/2026

| Image | Retour | Fait |
|---|---|---|
| — | Erreur 500 en ligne à la création d'un article | lot 71 : catégorie « nouvelle » sans nom refusée par un message (MySQL refusait la chaîne en base) |
| 1 | Le menu de l'accueil n'est pas bon | lot 73 |
| 2 | La section « Le cabinet » est-elle nécessaire ? | **à décider** : la compléter, ou la masquer depuis Superadmin › Vitrine |
| 3 | Descriptions nettes et liens des applications | lot 73 pour Selflow et Comptaflow ; **textes et adresses des quatre autres attendus** |
| 4 | Préciser les caractères du mot de passe | lot 71 |
| 5-6 | Mot de passe oublié : lien affiché, aucun courriel, page 429 | lots 64 et 71 ; **le courriel part dès que le `.env` du serveur est renseigné** |
| 7 | La recherche recharge à chaque caractère | lot 71 (neuf écrans et le catalogue) |
| 8 | Import de l'inventaire : référence refusée | lot 71 : modèle rempli des articles de l'entreprise |
| 9 | Fiscalité & DGI : affichage, données du timbre | lot 72 ; **le défaut d'affichage des sous-modules est à préciser** |
| 10 | Modifier et supprimer les comptes créés | lot 72 |
