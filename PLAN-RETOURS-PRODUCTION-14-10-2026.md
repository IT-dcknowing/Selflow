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
| 70 B2B approfondi | **Terminé** — livré au lot 75 | — |
| 71 Erreur 500 de la fiche article, mot de passe, recherches, import du stock | **Terminé** | `3b6d3ac` |
| 72 Plan comptable modifiable, Gestion FNE | **Terminé** | `1376c21` |
| 73 Page d'accueil | **Terminé en partie** (textes des applications attendus) | `0ed7302` |
| 74 Étape 1 du pas à pas refaite, retour arrière du parcours, plan Excel à part | **Terminé** | `5888d61` |
| 75 B2B approfondi (70a à 70d) | **Terminé** | `541854f` |
| 76 Inscription sans blocage, fiscalité exigée à la normalisation | **Terminé** | `00c4ff3` |
| 77 Achat : aucun règlement sur une DP ou un BC | **Terminé** | `8dd00a1` |
| 78 Pas à pas : chaque ligne en boîte réglable | **Terminé** | `7ec712c` |
| 79 Page de maintenance, registre des pannes | **Terminé** | `17e9698` |
| 80 Création d'entreprise par le superadmin, alignée | **Terminé** | `a089b26` |
| 81 Offres d'abonnement | **Terminé** | `acb6b06` |
| 82 Habilitations mises à jour (urgent) | **Terminé** | `fe83cf4` |
| 83 Facturation, échéance, métier Poissonnerie | **Terminé** | `b985ccd` |
| 84 Suppression d'une entreprise (erreur 500 en production) | **Terminé** | `67f2c58` |
| 85 Parcours dans la section, fin du pass cadeau | **Terminé** | `4d821d1` |

**Le plan chiffré vit dans `PLAN-RETOURS-PRODUCTION.xlsx`**, à part du plan de
correction (même modèle, mêmes couleurs).

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

## Retours du 15/10/2026

| Retour | Fait |
|---|---|
| **URGENT** — l'inscription exigeait l'accès FNE à l'étape 3 « facultative » | lot 76 : un NCC mal formé est écarté et signalé, l'entreprise est créée |
| Vendre, acheter, facturer sans information fiscale ; bloquer seulement à la normalisation, avec « Remplir » et « Fermer » | lot 76 : seul le point de vente retient la saisie ; la normalisation (manuelle, par lot, automatique) dit ce qui manque — NCC, régime, point de vente, accès FNE |
| Le NCC posé plus tard doit paraître sur les factures | déjà le cas (lu à l'impression) ; épreuve ajoutée |
| Achat : pas de moyen de paiement pour une demande de prix ou un bon de commande | lot 77 |
| Achat : refonte ? | **à décider** — la page est cohérente une fois le défaut levé ; un « pas à pas » comme la vente reste possible |
| Pas à pas : voir et régler TVA, remise par ligne, à la manière d'Odoo | lot 78 |
| Les quatre modes d'usage | voir ci-dessous |

### Les quatre modes d'usage

| Mode | Normalisation | Écritures | Comment |
|---|---|---|---|
| 1 | non | non | ne pas renseigner l'accès FNE (ou couper la normalisation automatique) ; comptabilité fermée |
| 2 | non | oui | idem, comptabilité ouverte (Paramètres › Modules, ou attribution du superadmin) |
| 3 | oui | non | situation fiscale et accès FNE renseignés ; comptabilité fermée |
| 4 | oui | oui | situation fiscale et accès FNE renseignés ; comptabilité ouverte |

Nuance : comptabilité fermée, Selflow **tient quand même les écritures en
arrière-plan** (décision du lot 5) — personne ne les voit ni ne les passe, et
ouvrir la comptabilité plus tard trouve l'historique. Les couper tout à fait
est possible : **à décider**.

## Retours du 15/10/2026 (soir)

| Retour | Fait |
|---|---|
| Une page qui tombe : « en maintenance », résolu dans un instant, contacts de l'assistance saisis par le superadmin | lot 79 |
| Le superadmin voit chaque erreur (code, adresse), le détail au clic ; la ligne disparaît une fois corrigée | lot 79 — à la main, ou d'elle-même quand le fichier fautif a été modifié |
| Création par le superadmin : secteurs → même parcours avec « Suivant » ; quota 3 ; modules retirés ; « Voir » et barre du mot de passe ; bloc FNE présentable et choix retirable ; alignement sur les décisions | lot 80 |
| Offres SOLO, BUSINESS, PREMIUM, SUR MESURE, PASS CADEAU en cartes, après l'inscription | lot 81 |

**À trancher** : le paiement des offres (aucun encaissement en ligne : une
offre payante reste « à régler ») et ce qui se passe à la fin du pass cadeau
(aujourd'hui, un bandeau seulement).

## Retours du 15/10/2026 (nuit)

| Retour | Fait |
|---|---|
| **URGENT** — mettre à jour les habilitations | lot 82 : Produits et Tiers revenus dans la liste, sept écrans avec leur propre droit, migration sans perte d'accès |
| Section Facturation dans les paramètres, cartes avec avantages, renouveler ou monter en gamme | lot 83 |
| À l'échéance : espace visible mais bloqué, message « abonnement à terme », bouton Continuer vers la facturation | lot 83 |
| Métier Poissonnerie dans la configuration | lot 83 |

**Attendu** : l'API de paiement. D'ici là, une demande de renouvellement est
notée et DC-Knowing repousse l'échéance depuis la fiche de l'entreprise.

## Retours du 15/10/2026 (suite)

| Retour | Fait |
|---|---|
| Fin du pass cadeau : bloquer ? | **oui** — l'accès est bloqué à la fin des 7 jours (lot 83, épreuve au lot 85) |
| « Suivant » du domaine bloquait la page | lot 85 : le parcours se déroule dans la section, sans soumettre |
| Erreur 500 à la suppression d'une entreprise | lot 84 ; **« eve disagn » a perdu ses utilisateurs avant l'erreur : relancer sa suppression une fois déployé** |

## Retours du 16/10/2026

| Retour | Fait |
|---|---|
| Comptabilité : habilitations proposées = ce que l'entreprise dispose | lot 86 |
| Immobilisations seulement sur attribution du superadmin | lot 87 |
| Annuler une facture non normalisée, stock réinjecté ; pas d'« Annuler » sur une normalisée | lot 87 |
| Moyens de paiement : pas de numéro de compte, Mobile Money à part, moyens = codes journaux | lot 88 |
| Stickers et abonnements payés par référence ; suivi superadmin En cours / Terminé / Échec ; heures, priorité, avis « stock renouvelé » | lot 89 |
| Normalisation refusée pour point de vente non activé : le dire clairement | lot 90 |
| Configuration des comptes : section Articles | lot 91 |
| Liaison Comptaflow : créer ou lier un dossier existant, exercices, déverser maintenant ou plus tard | lot 92 — **Comptaflow doit honorer `comptaflow_company_id` au provisionnement** |
| Mettre l'application hors ligne (maintenance évolutive) | lot 93 |
| Lenteur ; audit de sécurité complet | lot 94 — voir le journal ; **.env serveur à compléter** |

**À faire** : caisse paginée pour les grands catalogues ; CSP à nonces et
secret `external/*` par entreprise (moyen terme).

## Retours du 16/10/2026 (suite)

| Retour | Fait |
|---|---|
| Montants Comptaflow trop élevés | lot 95 : analyse (factures d'essai) + diagnostic ; la liaison ne multiplie rien |
| Facture modifiée : écritures recalculées ; annulée en rouge + motif | lots 95, 97 |
| Trésorerie : n'encaisser que le dû | lot 96 + `selflow:corriger-tresorerie` |
| Remise globale d'achat déséquilibrée | lot 98 |
| Config : rayons des seuls métiers ajoutés | lot 98 |
| Sur mesure renouvelé par référence | lot 98 |
| URGENT lettrage (erreur 1062) | lot 99 |
| Suppression d'entreprise lente → corbeille 30 j | lot 100 |
| Pannes en deux onglets paginés | lot 101 |
| Accès immédiat à l'inscription + avis d'activation | lot 102 |
| Alertes multi-canal (courriel + WhatsApp) + destinataires | lot 103 |
| Maintenance & traçabilité (trafic, KPI, IP) | lot 104 |
| Traçabilité par entreprise (audit) | lot 105 |
| URGENT deadlock 1213 à l'annulation (poissonnerie EDEN) | lot 106 |
| Normalisation automatique décochée par défaut | lot 107 |
| Pagination de l'audit (20 par page) | lot 107 |
| Facture affichée dès la vente, impression sans « Factures vente » | lot 108 |
| « Annuler » devient une habilitation | lot 108 |
| URGENT suppression d'admin interne (Auth introuvable) | lot 109 |
| Reçu en plus gros caractères | lot 110 |
| Volet Stock invisible (module fermé) + diagnostic | lot 110 |
| Soldes de départ clients / fournisseurs | lot 111 |
| KPI des factures vente et achat | lot 112 |
| Switch entreprise (assistance superadmin) | lot 113 |
| Le point de vente actif commande les saisies ; membre affecté lié à son site | lot 114 |
| Fiche d'entreprise, sites au Switch, diagnostic du stock par site | lot 115 |
| Présence des utilisateurs ; chaque site voit ses articles | lot 116 |
| Voir la dernière facture ; page d'accès refusé ; section Ventes | lot 117 |
| Prix et numéros par point de vente ; corbeille immédiate ; adresse libérée | lot 118 |
| Corbeille rapide ; créances sans comptabilité ; volet Stock ouvert | lot 119 |
| BAPA sur choix explicite ; créances, soldes et tiers par site | lot 120 |
| Choisir son point de vente ; clients de la caisse par site | lot 121 |
| Doublons de points de vente : clé d'identité, noms proches, fusion | lot 122 |
| Code du point de vente en chiffres (01, 02…) | lot 123 |
| Abonnement payé : bloqué « En attente de confirmation » jusqu'à la confirmation, même en échec ; « Abonnement confirmé » ; confirmer un paiement en échec (un mois) | lot 124 |
| Erreur 1054 `confirmation_vue_le` : code déployé avant la migration | lot 125 — **`php artisan migrate` sur le serveur** |
| Contre-attaque | **refusée** (illégal) ; défense outillée |
| Relais WhatsApp | à brancher (URL d'un relais) |
