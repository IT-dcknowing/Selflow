# Selflow — journal de bord

**Ce fichier est la mémoire du projet.** Une session d'assistant qui démarre à
froid n'a pas l'historique de nos conversations : elle a ce dépôt, `CLAUDE.md`,
et ce fichier. Tout ce qui a été décidé, tout ce qui a été écarté et pourquoi,
tout ce qui reste à faire doit donc figurer ici — et y être tenu à jour à chaque
lot terminé.

Dernière mise à jour : 30 août 2026 — lot 20 côté Selflow : le refus FNE
s'affiche en pop-up, se corrige d'un bouton qui certifie dans la foulée, et la
correction ne duplique plus un point de vente — elle bascule sur l'existant.

---

## 1. Les deux applications

| | Rôle | Dépôt |
|---|---|---|
| **Selflow** | Gestion commerciale : ventes, achats, stocks, points de vente, certification FNE auprès de la DGI. Produit les écritures comptables. | `meledjeabrahamagnimel-lgtm/Selflow` |
| **Comptaflow** | Application comptable complète : balance, grand livre, états SYSCOHADA, analytique. Reçoit les écritures de Selflow. | `guysergekouassi/COMPTAFLOW` |

Selflow écrit, Comptaflow exploite. Un client sans abonnement Comptaflow dispose
malgré tout d’une balance de contrôle dans Selflow — faite au lot 4.5.

---

## 2. La règle d'or : la conformité FNE est gelée

Voir `CLAUDE.md`, qui fait foi. En résumé : ne plus toucher aux payloads, aux
champs `fne_*`, au barème du timbre, au code QR, aux blocs de certification des
documents imprimés ni à `FnePayloadTest`. Trois exceptions seulement — nouvelle
version du référentiel DGI, rejet prouvé par le journal, demande explicite du
propriétaire.

Seul point de contact autorisé : l'écriture comptable du BAPA, en lecture seule
de ce que la plateforme a déjà retourné.

### La seule modification autorisée à ce jour — quantités décimales

**Exception 3 : demande explicite du propriétaire, le 8 août 2026**, après
vérification du référentiel technique.

`FneService` transmettait les quantités par `intval($detail->quantite)`, à six
endroits. Depuis le lot 3.1 les lignes portent des décimales : une vente de
12,5 kg de cacao partait certifiée pour **12 kg**, et la pièce certifiée
divergeait de la pièce établie dans Selflow, sans que rien ne le signale.

`CONFIGURATION FNE/Procedure_technique_integration_API.txt` est formel — le
champ est un **`number`**, dans les trois tableaux de champs :

| Endpoint | Ligne | Déclaration |
|---|---|---|
| Certification de facture | 272 | `quantity \| number \| Quantité \| O` |
| Reçu normalisé | 508 | `quantity \| number \| Quantité \| O` |
| Avoir / remboursement | 684 | `quantity \| number \| La quantité de l'article à retourner \| Y` |

Un `number` JSON n'est pas un entier. `FneService::quantiteFne()` arrondit
désormais au millième — la précision de la colonne `decimal(15,3)`, car
transmettre plus de décimales que la base n'en garde rendrait la pièce certifiée
irreproductible depuis nos données.

**La forme sur le fil ne change pas pour ce qui passait déjà** : `json_encode`
écrit `30` et non `30.0` pour un flottant sans partie décimale. Les pièces déjà
certifiées partent à l'identique ; seul ce qui était tronqué change.

Trois tests le verrouillent dans `FnePayloadTest` — une quantité fractionnée,
une quantité entière (dont la forme ne doit pas bouger), et l'alignement sur les
trois décimales.

---

## 3. Décisions arrêtées

Elles ne se rediscutent pas sans raison neuve.

| Sujet | Décision |
|---|---|
| Inventaire | **Permanent**, valorisé au **CUMP** (Coût Unitaire Moyen Pondéré) |
| Sortie de stock | **À la livraison**. La vente au comptant émet une livraison implicite immédiate |
| Données existantes | **Supprimées** — le développement repart de zéro, aucune donnée réelle |
| Numérotation des comptes | **Six chiffres** pour toute la partie locale (`7011` → `701100`) |
| Rôle du superadmin | **Active tout par défaut**, quel que soit l'abonnement. L'utilisateur choisit ensuite ce qu'il veut voir |
| Comptes, produits, journaux | **Livrés par défaut** avec l'application, indépendamment de Comptaflow, tous archivables |
| Journaux de trésorerie | Nommés librement : MTN, Orange, Caisse, ou le nom de la banque |
| Identifiants dans l'URL | **Masqués** par un identifiant public opaque — après les contrôles d'appartenance, jamais avant |
| États comptables | Comptaflow. Selflow garde une balance de contrôle |
| Souscription | Parcours qui se déplie étape par étape, **sans intervention du superadmin** |
| Choix hors référentiel | Un champ **« Autre »** à saisie libre partout où l'utilisateur choisit dans une liste fermée |
| Sigle CUMP | Toujours écrit **CUMP (Coût Unitaire Moyen Pondéré)**, définition entre parenthèses, à chaque occurrence — code, commentaires, journal, échanges |
| Secrets | Aucun mot de passe ni clé dans le code versionné. Ils viennent de l'environnement, ou sont tirés au hasard et affichés une seule fois |
| Impression du reçu | **Par le navigateur** (`window.print()`, format 80 mm) pendant les tests, boîte de dialogue comprise, pour voir le rendu. **À retirer sur ordre du propriétaire**, pas avant |
| TERNE | **Pas de terminal fiscal.** Selflow passe par l'API de la DGI, qui renvoie les trois éléments du sticker. L'imprimante de caisse est un périphérique ordinaire |
| Achats et DGI | **Le BAPA est le seul achat transmis à la plateforme**, et il ne collecte aucune TVA — ce que le payload d'achat traduit déjà, et qui reste **gelé**. Les autres achats ne sont enregistrés **que pour la comptabilité** : c'est la finalité visée, et elle confirme le sens du lot 11.3 — 24/08/2026, propriétaire du projet |
| Taxes supportées à l'achat | **La colonne est retirée**, pas ouverte. À l'achat, une taxe supportée est une charge dont le compte dépend de sa nature ; le deviner reviendrait à en choisir un au hasard — 24/08/2026, propriétaire du projet |
| Libellés d'écriture | **Paramétrables par entreprise**, deux gabarits par type d'opération. Les défauts reproduisent l'ancien texte au caractère près, et **les écritures passées ne sont jamais réécrites** — 24/08/2026 |
| Ventilation analytique | **Un seul axe : le point de vente**, celui que l'application renseigne réellement. Aucune clé de répartition : une charge de siège reste au site où elle a été saisie, et l'écran le dit — 24/08/2026 |
| Secteur d'activité | **Ne se saisit plus à la main.** Il se déduit du parcours de configuration — domaine à l'étape 1, réaligné sur les métiers souscrits à l'étape 4. Deux écrans posaient la même question sans se parler — 24/08/2026, propriétaire du projet |
| Configuration acquise | **Additive.** Ajouter un domaine, un métier ou un rayon est toujours possible ; ce qui est souscrit ne se retire pas. **Un module qui porte des données ne se referme plus** — le fermer ne supprimerait rien mais ferait disparaître les écrans où ces données se lisent. Le verrou ne s'applique qu'à ce qui est vérifiable par un comptage — 24/08/2026, propriétaire du projet |
| Photos d'articles | Servies par le lien `public/storage` quand il existe, **par une route de l'application sinon**. Sans ce repli, une installation où `php artisan storage:link` n'a pas été lancé rendait des adresses en 404 (Not Found — introuvable) que seul le fond de carte laissait voir — 24/08/2026 |
| Article archivé | **Il disparaît des écrans de saisie** : vente, achat, production, consignation, alertes de rupture, ouverture d'un point de vente. Il **reste** visible au stock tant qu'il porte une quantité : la marchandise est là, et les écritures la portent — 25/08/2026, propriétaire du projet |
| Formulaire d'inscription | **L'étape 1 ne demande que le nom de l'entreprise.** Trois étapes, dont la dernière est facultative ; l'adresse électronique, qui est l'identifiant de connexion, est demandée avec le responsable. La forme juridique et le domaine d'activité se renseignent depuis l'application — 25/08/2026, propriétaire du projet |
| Illustration d'article | **Vingt-deux dessins au trait tenus dans le dépôt**, choisis d'après le nom de l'article. Ce ne sont pas des photos et cela ne prétend pas l'être : aller chercher une image du commerce montrerait une marchandise que l'entreprise ne vend pas. La vraie photo passe toujours devant — 25/08/2026 |
| Clé de liaison Comptaflow | **Délivrée, jamais saisie.** L'entreprise demande, le superadministrateur valide, **Comptaflow génère la clé** et la renvoie. Elle est chiffrée en base, retirée de `$fillable`, et n'apparaît jamais entière à l'écran. C'est elle — et non le secret partagé, qui ne dit pas qui appelle — qui authentifie chaque déversement — 26/08/2026, propriétaire du projet |
| Accès Selflow et Comptaflow | **Un seul compte pour les deux** : même adresse, même mot de passe, dans les deux sens. Ce qui voyage est l'**empreinte** `bcrypt`, jamais le mot de passe — personne ne le lit, et le superadministrateur n'en choisit aucun. **Pas de lien d'activation** dans le cas normal : il ferait choisir un second mot de passe à qui n'a rien demandé. Il reste le repli quand l'empreinte manque — 26/08/2026, propriétaire du projet |
| Avis d'ouverture d'un dossier | **Un courriel prévient le titulaire**, avec en-tête, corps et pied. Un compte ne s'ouvre pas au nom de quelqu'un sans qu'il l'apprenne. Il ne porte **ni mot de passe ni clé de liaison** : on dit lequel est le mot de passe, jamais quel il est — 26/08/2026, propriétaire du projet |
| Divergence des deux mots de passe | **Acceptée et dite**, plutôt que corrigée. Le courriel écrit « au jour de l'ouverture » : changer son mot de passe Selflow ne change pas celui de Comptaflow, et promettre « c'est le même » deviendrait faux sans que rien ne le signale. La propagation par la passerelle reste possible, elle n'est pas retenue — 26/08/2026, propriétaire du projet |
| Point de vente | **Il ne se crée jamais tout seul, et il en faut au moins un.** Son nom part tel quel à la DGI, qui refuse la pièce s'il ne correspond à aucun site déclaré sur l'espace FNE : en inventer un décidait à la place de l'entreprise du nom sous lequel ses factures seraient certifiées. Il figure désormais parmi les éléments réclamés avant toute vente — 26/08/2026, propriétaire du projet |
| Point de vente actif | **Retenu au-delà de la session**, sur une colonne à part de l'affectation du caissier. Il mourait avec la déconnexion, et un responsable de trois magasins repartait chaque matin sur le premier venu — 26/08/2026, propriétaire du projet |
| Durée de vie d'une clé de liaison | **Trente jours.** Rotation à la main par le superadministrateur, et automatique le 1ᵉʳ de chaque mois. Rien n'est écrit tant que la nouvelle clé n'est pas en main, et Comptaflow tient une période de grâce pour les requêtes déjà parties — 26/08/2026, propriétaire du projet |
| Adresse publique de Comptaflow | **`https://comptaflow.dc-knowing.com/`**, réglable par `COMPTAFLOW_APP_URL`. En `https` et non `http` : l'adresse part dans un courriel, vers une page où l'utilisateur saisira son mot de passe — 26/08/2026, propriétaire du projet |
| Plan comptable par défaut | **Le plan de l'acte uniforme en entier**, et non les 41 comptes usuels. Un compte manquant se créait à la main, son numéro deviné, et l'imputation fausse traversait la balance, le grand livre et la liasse. Deux boutons rejouent la dotation à tout moment — 26/08/2026, propriétaire du projet |
| Compte d'un journal | **Seuls les journaux de trésorerie en portent un** — le `521` de la banque, le `571` de la caisse. La contrepartie d'une vente ou d'un achat est le tiers de la pièce, et elle change à chaque écriture — 26/08/2026 |
| Identifiants fiscaux d'un client | **Retirés pour un B2C.** Un particulier n'a ni NCC, ni RCCM, ni régime d'imposition ; les lui demander en les grisant laissait trois champs vides à jamais — 26/08/2026, propriétaire du projet |


---

## 4. Le référentiel de préparamétrage

Source : `sellflow_parametrage_activites_1.xlsx` (version 1.2, 31/07/2026).

Cinq niveaux, du plus large au plus fin :

| Niveau | Nombre | Rôle |
|---|---|---|
| **Catégorie** | 12 | Regroupement d'affichage. C'est l'équivalent de l'ancien « secteur d'activité ». Ne décide de rien |
| **Profil** | 71 | Le métier réel. Ce que l'utilisateur choisit ; décide des modules à ouvrir. **Niveau neuf** |
| **Famille** | 197 | Le rayon. **Porte les quatre comptes** : vente, achat, stock, variation |
| **Article** | 616 | Le produit. Hérite des comptes de sa famille |
| **Type d'article** | 10 | Transversal. **Détermine les comptes** ; la famille subdivise la racine qu'il impose |

Les 71 profils ne produisent que **cinq combinaisons de modules** :

| Combinaison | Profils |
|---|---|
| Stock seul | 31 |
| Stock + Production | 15 |
| Aucun module | 10 |
| Stock + Cycles agricoles | 8 |
| Stock + Chantiers | 7 |

---

## 5. État des lots

### Lot 0 — Fermer les portes ouvertes — **TERMINÉ**

Poussé sur **Selflow**, fusionné dans `main`.

- Contrôle du propriétaire sur `VenteControleur::normaliser()` — c'était la seule
  action du projet qui chargeait une pièce sans vérifier à qui elle appartient.
- **34 règles `exists:` cloisonnées** par `App\Modules\Admin\Regles\Appartenance`,
  dans 12 contrôleurs. Une table dont le rattachement n'est pas déclaré lève une
  exception plutôt que de laisser passer.
- Secret de synchronisation : plus de valeur de repli dans le code,
  comparaison par `hash_equals`. **`EXTERNAL_SYNC_SECRET` doit être posé dans
  `.env`**, sinon la synchronisation refuse tout appel.
- Neuf `addslashes` remplacés par `@js()`.
- `php artisan verifier:variables` — détecte les variables lues sans avoir été
  écrites, la faute que PHP ne révèle qu'au clic de l'utilisateur.
- `tests/Feature/CloisonnementTest.php` — 7 tests.

### Lot 1 — Le référentiel en base — **TERMINÉ**

Poussé sur **Selflow**. Ce qui est fait :

- Six tables `referentiel_*` : catégories, profils, types d'article, familles,
  articles, comptes. Elles portent le catalogue livré avec l'application et ne
  contiennent aucune donnée client.
- Le classeur converti en JSON versionné dans `database/data/referentiel/`. Le
  JSON est au dépôt, pas le tableur : un fichier de tableur ne se relit pas dans
  une revue de code et ne se compare pas d'une version à l'autre.
- `ReferentielSeeder`, idempotent — il peut tourner à chaque déploiement et à
  chaque nouvelle version du classeur sans rien dupliquer.
- Conversion des racines SYSCOHADA vers six chiffres : `701` → `701000`,
  `7011` → `701100`, `60311` → `603110`. Elle colle à la convention déjà en
  place dans `config/selflow.php` (`411000`, `601000`).
- `tests/Feature/ReferentielTest.php` — 10 tests, dont la vérification que les
  cinq combinaisons de modules couvrent bien les 71 profils.

- Le **plan comptable OHADA complet** — 1 256 comptes, 9 classes — chargé comme
  dictionnaire depuis l'acte uniforme. Il ne remplit pas le plan des
  entreprises : il sert à nommer. Sans lui, la famille « Vivres » imputait en
  `701100` sans que rien ne nomme ce compte, et la balance à venir aurait été
  illisible. `Compte::nommer()` résout un numéro absent par sa racine la plus
  longue.

- La numérotation des comptes du référentiel **corrigée et alignée sur l'acte
  uniforme** — voir la section 5 bis.

- Le **trousseau de départ** d'une entreprise : `TrousseauEntrepriseService`
  pose les 34 comptes communs et 9 journaux dès la création, sur les quatre
  chemins qui créent une entreprise — inscription, Google, superadmin,
  synchronisation Comptaflow. Sans lui, la première vente s'imputait sur des
  comptes inventés à la volée.

  **Les dix journaux livrés :**

  | Code | Type | Compte | Intitulé | |
  |---|---|---|---|---|
  | `VTE` | Vente | — | Journal des ventes | |
  | `ACH` | Achat | — | Journal des achats | |
  | `OD` | OD | — | Opérations diverses | |
  | `RAN` | RAN | — | Report à nouveau | *système* |
  | `CAI` | Caisse | `571000` | Caisse | |
  | `BQE` | Banque | `521000` | Banque | |
  | `MTN` | Banque | `521500` | MTN Mobile Money | |
  | `OMY` | Banque | `521600` | Orange Money | |
  | `MOOV` | Banque | `521700` | Moov Money | |
  | `WAVE` | Banque | `521800` | Wave | |

  Seuls les journaux de trésorerie portent un compte général : `VTE`, `ACH`,
  `OD` et `RAN` n'en ont aucun, et la colonne est devenue facultative pour
  cela. La caisse pointe sur `571000` et la banque sur `521000` — deux racines
  de même rang ; `521100` aurait désigné « Banque X », une des places que
  l'acte uniforme réserve aux banques de l'entreprise.

  Le report à nouveau est marqué **`systeme`** : la clôture l'écrit, personne
  ne le saisit. Il disparaît des listes de saisie — portée `saisissables()` —
  et reste visible en consultation, le grand livre devant afficher les
  écritures de clôture.

  Le mobile money est rangé en subdivision de `521` « Banques locales », à
  partir de `521500` : c'est un avoir détenu chez un établissement agréé, pas
  de l'espèce en caisse. L'acte uniforme est antérieur à ces moyens de paiement
  et n'en prévoit aucun compte ; `5211` à `5214` restent libres pour les
  banques de l'entreprise.

  Rien n'écrase ce que l'utilisateur a modifié, et **ce qui ne sert pas
  s'archive** — `archive_le` sur `plan_comptable` et `codes_journaux`, avec les
  portées `actifs()` et `archives()`. Un compte supprimé après avoir servi
  laisserait des écritures orphelines.

- `tests/Feature/TrousseauEntrepriseTest.php` — 10 tests.

- L'**écran superadmin de consultation** : `/superadmin/referentiel`. Il montre
  les types d'article et leurs comptes, les dix journaux livrés, et les profils
  filtrables par catégorie ; le détail d'un profil liste ses familles avec leurs
  quatre comptes et ses articles types. Consultation seule — le référentiel se
  modifie dans le classeur puis se recharge par le seeder.

**Lot 1 terminé.** Les produits par défaut viendront avec la souscription d'un
profil (lot 2), puisqu'ils en dépendent.

### Lot 2 — Le parcours de souscription — **TERMINÉ**

Poussé sur **Selflow**.

- Table `entreprise_profils` : une entreprise souscrit à un ou plusieurs
  profils, et l'on garde trace de ce que chaque souscription a créé.
- **Droits et préférences séparés** : `modules_autorises` (superadmin, tout
  ouvert par défaut) et `modules_actifs` (choix de l'utilisateur). Un module
  n'est actif que s'il est aussi autorisé. Personne ne savait jusqu'ici si un
  module absent venait d'un abonnement restreint ou d'une préférence.
- `souscription_etape` et `souscription_terminee_le` : le parcours se quitte
  et se reprend.
- `activite_autre` : ce que l'utilisateur saisit quand aucun profil ne lui
  convient, plutôt que d'être forcé dans une case. Alimentera les versions
  suivantes du classeur.
- `SouscriptionProfilService::souscrire()` copie chez l'entreprise les familles
  (en `categories`), les articles (en `produits`) et les quatre comptes de
  chaque famille (au `plan_comptable`), puis ouvre les modules du profil dans
  la limite des droits. Le trousseau est posé au passage s'il ne l'était pas.
- `TypeArticle::typeProduit()` traduit les dix natures comptables du référentiel
  vers les six types du catalogue. Travaux, services, sous-traitance et
  financements se rejoignent sous « service » : aucun ne se stocke.
- `tests/Feature/SouscriptionProfilTest.php` — 12 tests.

Trois garanties tenues par des tests : une activité mixte cumule sans doubler
(ni catégorie, ni préfixe en collision), ce que l'utilisateur a modifié survit
à une nouvelle souscription, et un code de profil inconnu est refusé sans rien
créer — les codes viennent d'un formulaire.

- **Le parcours de configuration** : `/admin/souscription`, cinq étapes qui se
  déplient — domaine, métier, modules, rayons, prix. Chacune fait quelque chose ;
  je n'en ai pas gardé sept, trois se réduisaient à de la consultation que les
  écrans ordinaires assurent déjà.

  | Étape | Ce qu'elle fait |
  |---|---|
  | 1 · Domaine | Restreint la liste suivante |
  | 2 · Métier | Choix multiple — activité mixte — ou champ **« Autre »** |
  | 3 · Modules | Ce que les métiers ouvrent, décochable |
  | 4 · Rayons | Tous cochés ; décocher retire les articles et les comptes |
  | 5 · Prix et stock | Renommer, fixer les montants que le classeur laisse vides, et compter l'inventaire d'ouverture |

  La souscription s'effectue à l'étape 4 : tout ce qui précède n'est qu'un choix.
  L'étape atteinte est retenue sur l'entreprise, pas en session — un changement
  de poste ne fait pas tout recommencer. Le parcours vit hors du groupe
  « modules » : une entreprise qui n'a rien configuré n'a aucun module actif, et
  le middleware la rejetterait de son propre écran de configuration.

- **Le stock d'ouverture, à l'étape 5** : une colonne de plus, qui reçoit les
  quantités comptées le jour du démarrage sur le site actif. Elle n'apparaît que
  si les trois conditions sont réunies — module `stock` ouvert, un point de vente
  existant, et au moins un article qui se compte. Sans site, le champ n'aurait
  nulle part où écrire et avalerait la saisie en silence ; pour un cabinet
  comptable, dont tous les articles sont des missions, ce serait une colonne de
  tirets. Le formulaire et l'enregistrement lisent la même méthode
  (`siteDuStock()`) : la colonne affichée est exactement celle qui sera écrite.
  Un identifiant de site resté en session après un changement de compte est
  vérifié contre l'entreprise avant d'être retenu.

- `tests/Feature/ParcoursSouscriptionTest.php` — 16 tests.

**Sécurité du parcours**, chaque point tenu par un test :

- une étape non atteinte est refusée — sans quoi un formulaire forgé sauterait
  le choix des métiers et souscrirait à des familles qui n'appartiennent à
  aucun d'eux ;
- un module non autorisé ne s'active pas en l'ajoutant à la requête ;
- fixer le prix d'un produit d'une autre entreprise est refusé par
  `Appartenance`, avec la vérification de propriétaire en second rideau.

- **La bannière de configuration** : une entreprise qui n'a pas fait son
  parcours part d'une page blanche. Le tableau de bord le dit, et annonce le
  point que l'utilisateur doit connaître avant sa première facture — le
  catalogue arrive rempli, mais **sans prix**, eux seuls variant selon la zone
  et la période. Elle disparaît une fois la configuration faite, et ne
  s'affiche pas pendant le parcours lui-même.

- **La visite guidée de première utilisation** : une main désigne l'élément,
  une bulle explique à quoi il sert. Six étapes — configuration, catalogue,
  vente, clients, FNE, paramètres. Elle se retient **par utilisateur et en
  base** : un vendeur qui rejoint une entreprise déjà configurée la voit à son
  tour, et changer de poste ne la fait pas recommencer.

  Les cibles sont des attributs `data-visite` posés exprès dans le gabarit :
  une classe CSS change au gré des retouches, un repère posé pour cela ne bouge
  pas. Une étape dont la cible est absente — un module fermé — est sautée.
  `Échap` ferme, et `prefers-reduced-motion` coupe les animations.

  Elle se relance depuis le menu du profil — **« Revoir la visite guidée »** —
  par un formulaire ordinaire et non par un appel JavaScript : une visite doit
  pouvoir se rouvrir même quand un script a échoué, ce qui est précisément le
  moment où l'utilisateur en a besoin. La route répond en JSON à un appel de
  script, et par un retour au tableau de bord sinon.

- `tests/Feature/VisiteGuideeTest.php` — 11 tests.

- **Les unités restent à saisie libre**, avec des suggestions. Un `datalist`
  (`admin::partials.unites-suggerees`) et non une liste fermée : le tâcheron
  facture au « voyage », le vétérinaire à la « tête », l'école à l'« élève », et
  le premier conditionnement absent d'une liste fermée bloquerait la fiche. Les
  unités déjà employées par l'entreprise passent en tête, suivies de
  `Produit::UNITES_COURANTES` ; la comparaison ignore la casse, ce qui évite
  d'avoir « pièce », « piece » et « Pièce » comme trois unités distinctes.

**Deux défauts trouvés et corrigés en cours de lot :**

- une ligne de l'étape 5 sans clé `nom` — corps de requête partiel, et non le
  formulaire — produisait un 500 sur `$ligne['nom']`. Chaque clé est désormais
  facultative, une valeur absente laissant l'existante en place ;
- `Produit::stockSur()` lit la relation déjà chargée quand elle l'est : sans
  cela, un catalogue de deux cents lignes déclenchait deux cents requêtes à
  l'affichage de l'étape 5.

### Lot 3 — Le stock suit l'événement — **TERMINÉ**

| Étape | État |
|---|---|
| 3.1 · Quantités décimales de bout en bout | **TERMINÉ** |
| 3.2 · `StockService` unique, journal en écriture seule | **TERMINÉ** |
| 3.3 · Rebrancher les appelants, fermer la double porte d'achat | **TERMINÉ** |
| 3.4 · Sort de la marchandise sur les avoirs | **TERMINÉ** |
| 3.5 · Module d'inventaire physique | **TERMINÉ** |
| 3.6 · Engagements calculés, plus stockés | **TERMINÉ** |

#### 3.1 — Les quantités cessent d'être entières

`quantite_disponible` était un `integer`. Le référentiel livre pourtant des
kilos, des litres et des mètres carrés : 12,5 kg de cacao entraient en stock
pour 12, sans erreur ni trace. Au bout d'un an de réceptions, l'écart entre le
stock théorique et le comptage physique n'avait plus d'explication.

La correction porte sur **toute la chaîne**, pas seulement sur la fiche de
stock — une quantité tronquée sur la ligne de vente l'est déjà avant d'atteindre
le stock :

| Niveau | Ce qui a changé |
|---|---|
| Base | Six tables passées en `decimal(15,3)` : `stocks`, `mouvements_stock`, `vente_details`, `achat_details`, `produits`, `transferts_stock` |
| Modèles | Casts en `float`, `Produit::stockActuel()` et ses voisines retypées `int` → `float` |
| Contrôleurs | Quatre `intval` / `(int)` qui tronquaient à la lecture ou à l'écriture |
| Validation | `App\Modules\Admin\Regles\Quantite`, énoncée une fois pour onze contrôleurs |
| Navigateur | `parseInt` → `quantiteSaisie()`, et `step="0.001"` sur onze champs |

**Trois décimales**, et pas davantage : le gramme sur le kilo, le millilitre sur
le litre. Deux colonnes gardent leurs quatre décimales,
`fiche_technique_details.quantite` et `ordres_production.quantite_cible` : la
première n'est pas une quantité mais un coefficient de nomenclature — 0,0125 kg
de colorant par unité produite — et arrondir un coefficient fausse toute la
série qu'il multiplie.

`Quantite::physique()` refuse le zéro, le négatif et la quatrième décimale.
Refuser plutôt qu'arrondir : la colonne n'en garde que trois, et un arrondi
silencieux est précisément ce que ce lot corrige. Le sens d'un mouvement est
porté par son type — jamais par le signe de sa quantité.

**Un défaut de saisie trouvé au passage** : les champs de quantité des avoirs
portaient `min="0.01"` sans `step`. En HTML, la base du pas est `min` : les
seules valeurs acceptées étaient 0,01 / 1,01 / 2,01… et « 12 » était refusé par
le navigateur, sans message compréhensible.

**Un point signalé et laissé au propriétaire** : `FneService` transmet
`intval($detail->quantite)`. Voir la section 2 — code gelé.

- `tests/Feature/QuantitesDecimalesTest.php` — 15 tests.

#### 3.2 — Une seule porte, un journal qui ne s'efface pas

Le couple « modifier la fiche, puis écrire le mouvement » était recopié dans
plus de douze endroits, sur sept contrôleurs et deux contrôleurs d'API. Trois
conséquences, toutes constatées dans le dépôt :

- **rien ne garantissait la paire.** Une exception entre les deux gestes
  laissait un stock modifié sans trace, ou une trace sans stock ; plusieurs de
  ces blocs n'étaient même pas dans une transaction ;
- **`stock_avant` était lu sans verrou.** Deux ventes simultanées sur le même
  article lisaient la même valeur, écrivaient chacune leur `stock_apres`, et le
  journal annonçait un stock que la fiche démentait ;
- **les libellés divergeaient.** Six formes différentes pour quatre notions.

`App\Modules\Admin\Services\StockService` rend les trois gestes indissociables —
verrouiller sous `lockForUpdate`, modifier la fiche, journaliser — et les motifs
deviennent des constantes de `MouvementStock`.

| Méthode | Ce qu'elle fait |
|---|---|
| `entree` / `sortie` | Le geste unique, avec son motif |
| `transferer` | Les deux jambes, verrous pris dans un ordre stable pour éviter l'interblocage |
| `inventorier` | L'écart d'un comptage physique, dans le sens qu'il faut ; rien si le comptage est conforme |
| `contrePasser` | L'écriture de sens inverse, qui désigne celle qu'elle annule |
| `contrePasserLaPiece` | Toutes celles d'une vente ou d'un achat |
| `disponible` | La quantité lue **sous le verrou** qui servira à l'écriture |

**Le journal ne s'efface pas.** `MouvementStock` refuse la suppression et la
modification des colonnes qui font foi ; `reference_document` reste modifiable,
c'est un libellé d'affichage. Contre-passer deux fois le même mouvement est
refusé : cela fabriquerait de la marchandise qui n'a jamais existé.

Une pièce se rattache désormais vraiment à ses mouvements
(`piece_type` / `piece_id`). `reference_document` est une chaîne libre :
renuméroter une facture rompait le lien sans que rien ne le signale.

- `tests/Feature/StockServiceTest.php` — 20 tests.

#### 3.3 — Les douze copies disparaissent

Sept contrôleurs et deux contrôleurs d'API passent par le service. Il ne reste
aucun `MouvementStock::create` hors du service, et `Produit::incrementStock()` /
`decrementStock()` sont marquées `@deprecated` — elles ne subsistent que pour le
jeu de données de démonstration.

**La double porte de l'achat est fermée.** `AchatControleur` incrémentait le
stock à la facture mais ne marquait pas la ligne reçue : la commande restait
dans la file « Réceptions à traiter », et valider la réception incrémentait
**une seconde fois**. Rien ne l'interdisait. La facture vaut désormais réception
immédiate — `quantite_receptionnee` est posée — exactement comme la vente au
comptant vaut livraison immédiate.

**La modification d'une vente contre-passe au lieu d'effacer.**
`VenteControleur` faisait
`MouvementStock::where('reference_document', ...)->delete()` : le stock revenait
juste, mais la sortie de dix sacs disparaissait du journal, et avec elle toute
chance d'expliquer un écart d'inventaire six mois plus tard.

**Quatre motifs inventés ne s'affichaient nulle part.** `B2bControleur`
écrivait `vente` et `achat`, `BonLivraisonControleur` n'écrivait aucun
sous-type, `ProductionControleur` écrivait `Entree` sans accent. L'écran des
mouvements compare la chaîne exacte : ces lignes existaient, tombaient dans le
défaut, s'affichaient en gris — et une entrée de production apparaissait en
rouge, précédée d'un signe moins.

#### 3.4 — Le sort de la marchandise rendue

Le choix existait déjà à l'écran (`reinject` / `scrap` / `none`), mais il était
consigné dans une clé **`notes` que `mouvements_stock` n'a pas** : Eloquent la
laissait tomber sans rien dire. Et le rebut écrivait une entrée fantôme —
quantité N, `stock_avant` 0, `stock_apres` 0 — qui ne changeait rien mais se
lisait comme une entrée. Un retour vendable et un retour défectueux étaient donc
**indiscernables**, et le rebut invisible.

| Choix | Ce qui s'écrit |
|---|---|
| Revient vendable | Une entrée, motif `retour_client` |
| Revient inutilisable | Une entrée `retour_client`, **puis** une sortie `rebut` |
| N'est pas revenue | Rien : geste commercial, la marchandise est restée chez le client |

Deux écritures pour le rebut plutôt qu'une seule : le stock ne bouge pas au
total, mais les deux faits sont vrais, et le rebut apparaît là où on le cherche.
C'est aussi ce qui permettra de l'imputer en perte au lot 4.

Côté achat, `rendreAuFournisseur()` fait le symétrique. Le mot `reinject` y
désigne l'inverse de ce qu'il désigne côté vente — une **sortie** de stock ; le
nom vient de l'écran des avoirs de vente et prête à confusion, il est documenté
là où il est lu.

- `tests/Feature/CycleStockTest.php` — 9 tests, qui passent par les routes et
  non par le service : les défauts corrigés ici n'étaient pas dans le service,
  ils étaient dans la façon dont douze endroits l'imitaient chacun à sa manière.

#### 3.5 — L'inventaire physique

Le stock théorique est ce que l'application croit ; l'inventaire est ce que l'on
trouve dans le magasin. L'écart existe toujours — casse non déclarée, erreur de
saisie, vol — et il n'avait aucun moyen d'entrer dans Selflow : la seule
correction possible était de passer un rebut, ce qui ne sait pas dire « il y en
a **plus** que prévu ».

`/admin/stock/inventaire` : un site, la liste des articles qui se comptent, le
théorique en regard, un champ de saisie, et l'écart affiché à la frappe — c'est
le moment où l'on peut encore recompter.

Deux règles portées par le code, pas par la consigne :

- **un champ vide veut dire « pas compté », pas « zéro »**. Confondre les deux
  viderait le magasin de tout ce que l'inventaire n'a pas parcouru ;
- **un comptage à zéro reste légitime** : un rayon vide se compte. C'est pour
  cela que le calcul d'écart n'emploie pas `quantiteSaisie()`, qui ramène toute
  valeur nulle au plus petit pas.

#### 3.6 — Les engagements cessent d'être des compteurs

`produits.quantite_commandee` et `quantite_a_receptionner` existaient depuis
l'origine, s'affichaient sur trois écrans, entraient dans le prévisionnel — et
**rien ne les écrivait jamais**. Seul le jeu de démonstration les posait, à
zéro. Le prévisionnel valait donc toujours le stock disponible, et la colonne
« Commandé » d'un magasin qui attend trente sacs affichait 0.

Deux défauts, pas un : personne ne les alimentait, et **elles étaient sur
`produits`, donc globales** — trente sacs commandés à Abidjan seraient apparus
comme engagés à Bouaké.

La correction n'est pas de les écrire partout où il aurait fallu : un compteur
dénormalisé doit être incrémenté à la commande, décrémenté à la livraison,
corrigé à l'annulation, à la modification et à l'avoir — cinq occasions de
dériver. Les colonnes sont supprimées ; l'engagement se déduit des lignes qui
l'ont créé, par site. Une valeur déduite ne dérive pas.

Un devis n'engage rien : c'est une proposition. Seul le bon de commande engage.

#### Simulation d'attaques — `tests/Feature/AttaquesStockTest.php`

16 tentatives, du point de vue d'un utilisateur **légitime** d'une entreprise A
qui forge des requêtes contre l'entreprise B. C'est la surface réelle : un
formulaire ne protège rien, il ne fait que suggérer.

| Tentative | Résultat |
|---|---|
| Inventorier le dépôt du voisin (`point_de_vente_id` forgé) | refusé par `Appartenance` |
| Compter un article du voisin sur son propre site | ignoré, aucun mouvement |
| Lire le stock du voisin par `?point_de_vente_id=` | site non retenu, page vide |
| Mettre au rebut l'article du voisin | refusé |
| Rebut de quantité négative — une entrée déguisée | refusé |
| Quantité à seize chiffres — erreur SQL et page 500 | refusé avant la base |
| `1' OR '1'='1` dans une quantité | refusé |
| Supprimer une ligne du journal | refusé par le modèle |
| Réécrire la quantité d'un mouvement | refusé par le modèle |
| Écrans de stock sans connexion / module fermé | redirection / 403 |
| Nom d'article contenant `<script>` | échappé |
| Ranger son article dans le rayon du voisin | **faille trouvée et corrigée** |

**La faille trouvée** : `ProduitApiControleur` validait `categorie_id` et
`sous_categorie_id` par un `exists:` **sans cloisonnement**. Un appelant de
l'API pouvait rattacher son article à un rayon du concurrent, dont le nom
ressortait ensuite dans ses propres écrans. `Appartenance` reçoit un troisième
mode, `RATTACHEMENT_PAR_CATEGORIE` : `sous_categories` ne porte pas
`entreprise_id`, elle pend à une catégorie qui en porte un.

#### Simulation d'attaques — élévation de privilèges et vol d'accès

`tests/Feature/AttaquesElevationTest.php` — 25 tentatives. C'est l'attaque qui
compte le plus : les autres sont bornées, elles atteignent une donnée. Un compte
`superadmin` volé atteint **toutes les entreprises de la plateforme** — leurs
chiffres d'affaires, leurs clients, leurs clés FNE.

Le principe éprouvé : **rien de ce qui décide des droits ne vient de la
requête.** Le rôle, l'entreprise d'appartenance, les habilitations et le jeton
d'API sont décidés par le serveur.

| Tentative | Résultat |
|---|---|
| Poster `role: superadmin` sur « Mon profil » | ignoré — le contrôleur construit un tableau explicite |
| Poster `entreprise_id` sur « Mon profil » | ignoré |
| Se poser un `jeton_api` choisi | ignoré |
| Créer un compte `superadmin` depuis la gestion du personnel | refusé par `Rule::in` |
| Promouvoir un employé en `superadmin` | refusé |
| Créer un employé chez le voisin (`entreprise_id` forgé) | le serveur impose l'entreprise |
| Modifier l'employé d'une autre entreprise | 403 |
| Atteindre les 4 écrans de la plateforme en tant qu'admin | 302 / 403 |
| Idem sans être connecté | redirection |
| Gestion du personnel en tant que caissier | 302 / 403 |
| Jeton d'API affiché dans une liste | absent |
| Mot de passe haché affiché | absent |
| Jeton inventé sur l'API | 401 |
| Jeton d'autrui : voir au-delà de son entreprise | cloisonné |
| `' OR '1'='1`, `admin@…' --`, `' OR 1=1 #` à la connexion | aucune session |
| Poser `role` en session | sans effet — le rôle se lit en base |
| L'adresse en dur `superadmin@gmail.com` avec un rôle d'admin | refusée |

**La faille la plus grave du dépôt, trouvée et corrigée.** Trois seeders
créaient le compte de la plateforme avec un **mot de passe en clair dans le code
versionné** — `12345678SUPER@` — et `doit_changer_password => false` : rien
n'obligeait jamais à le changer. `SeedMassiveCommand` allait jusqu'à afficher un
avertissement disant que le mot de passe était public, sans rien y changer.

Quiconque avait lu le dépôt disposait d'un accès superadmin sur tout
déploiement ayant exécuté un seeder. Les comptes de démonstration
(`ADMIN@@@###123`, `Caissier@2025`) avaient le même défaut.

Désormais : les mots de passe viennent de l'environnement, ou sont **tirés au
hasard et affichés une seule fois**, à l'exécution. Tous partent avec
`doit_changer_password => true`.

| Variable | Rôle | Défaut si absente |
|---|---|---|
| `SUPERADMIN_EMAIL` | Adresse du compte de la plateforme | `superadmin@gmail.com` |
| `SUPERADMIN_PASSWORD` | Son mot de passe | tiré au hasard, affiché une fois |
| `DEMO_PASSWORD` | Comptes de démonstration | tiré au hasard, affiché une fois |
| `EXTERNAL_SYNC_SECRET` | Passerelle Comptaflow | **aucun** — la synchronisation refuse tout |

**Un point noté, non corrigé** : `VerifierHabilitationRoute` court-circuite tout
contrôle d'habilitation pour l'adresse `superadmin@gmail.com`, écrite en dur.
Ce n'est pas exploitable — le middleware `role:superadmin` passe avant, et un
test le vérifie — mais une identité en dur reste une clé publiée. À remplacer
par un drapeau sur le compte quand l'occasion se présentera.

**Une limite documentée plutôt que tue** : `MouvementStock::where(...)->delete()`
court-circuite les événements Eloquent — le garde-fou du modèle ne s'y applique
pas. Aucun code du dépôt ne le fait plus, et le seul appelant qui le faisait a
été corrigé. Une contrainte de base de données serait le cran suivant, si le
besoin s'en fait sentir. Le test le constate au lieu de le passer sous silence.

### Lot 4 — Les imputations — **TERMINÉ**

| Étape | État |
|---|---|
| 4.1 · Chaîne d'imputation article → rayon → défaut | **TERMINÉ** |
| 4.2 · CUMP (Coût Unitaire Moyen Pondéré) et inventaire permanent | **TERMINÉ** |
| 4.3 · Lettrage | **TERMINÉ** |
| 4.6 · Grand livre | **TERMINÉ** |
| 4.4 · BAPA sans TVA déductible | **TERMINÉ** |
| 4.5 · Balance de contrôle dans Selflow | **TERMINÉ** |

#### 4.1 — L'imputation se lit sur le rayon

La question « sur quel compte s'impute cet article » se résolvait par une paire,
recopiée à chaque endroit :

    $detail->produit?->compte_vente ?? config('…vente_defaut')

Deux niveaux là où le référentiel en prévoit trois, et le plus utile — **le
rayon** — sautait. `SouscriptionProfilService` copiait en effet les comptes de
la famille **sur chaque produit**, et la catégorie de l'entreprise n'en gardait
aucun. Trois conséquences :

- un article créé à la main après la souscription n'héritait de rien et tombait
  sur le compte générique `701000` : la balance d'un magasin qui a soigneusement
  réparti ses rayons n'avait **qu'une seule ligne de ventes** ;
- changer le compte d'un rayon obligeait à rouvrir chacun de ses articles ;
- le lien entre le rayon et son imputation — la règle métier — ne figurait plus
  nulle part une fois la copie faite.

Les quatre comptes rejoignent `categories`, et `ImputationService` résout la
chaîne :

| Rang | Source | Ce que cela veut dire |
|---|---|---|
| 1 | `produits.compte_*` | L'exception que l'utilisateur assume, article par article |
| 2 | `categories.compte_*` | Le rayon — la règle métier, celle du référentiel |
| 3 | `config('selflow.plan_comptable_defaut')` | Le filet, quand rien n'est renseigné |

**Le stock n'a pas de filet** : il n'existe pas de « compte de stock générique »
qui voudrait dire quelque chose. Les marchandises vont en 31, les matières en
32, les produits finis en 36 ; les confondre rendrait le bilan **faux** plutôt
qu'imprécis. Un article sans compte de stock ne produit donc pas d'écriture
d'inventaire, et `manqueUnCompte()` permet aux écrans de le dire — un article
mal imputé ne se voit pas avant la balance, et à ce moment-là le mois est passé.

- `tests/Feature/ImputationTest.php` — 12 tests.

#### 4.2 — Le stock prend une valeur

`produits.prix_achat` tenait lieu de coût : un prix de catalogue, figé, saisi
une fois. **La marge affichée était fausse** dès que le fournisseur changeait
ses prix — un sac acheté 12 000 puis 15 000 restait valorisé au prix de la
fiche, et la vente à 17 000 annonçait une marge qu'on n'avait pas faite. Et le
bilan ne pouvait pas être établi : valoriser un stock demande un coût, pas un
prix indicatif.

Le **CUMP** (Coût Unitaire Moyen Pondéré) se recalcule à chaque entrée :

    CUMP = (Q_ancienne × CUMP_ancien + Q_entrée × coût_entrée) ÷ (Q_ancienne + Q_entrée)

À la sortie, rien ne bouge : une sortie **consomme** le coût moyen, elle ne le
déplace pas. C'est la définition du procédé, et ce qui le rend indépendant de
l'ordre des ventes.

Il est porté par la **fiche de stock** — le couple article / site — et non par
l'article : le même sac de riz arrive à des coûts différents à Abidjan et à
Bouaké, transport compris. Un CUMP (Coût Unitaire Moyen Pondéré) global
mélangerait les deux et fausserait les deux. Quatre décimales, une de plus que
les quantités : le coût unitaire d'un article vendu au gramme se joue au centime
du millier.

Le coût d'entrée est le **prix réellement payé**, remise de ligne déduite, et
non le prix de catalogue. Trois situations demandent une décision :

| Situation | Décision | Pourquoi |
|---|---|---|
| Entrée sans coût connu — transfert, retour, écart d'inventaire | On reprend le CUMP en place | La marchandise n'a pas changé de valeur en changeant de main |
| Première entrée sur une fiche vide, sans coût | Repli sur `prix_achat` | Faute de mieux, mais mieux que zéro |
| Stock nul ou négatif après l'entrée | Le coût de l'entrée devient le CUMP | La moyenne pondérée n'a plus de sens sur une quantité qui ne peut pas la porter |

**Les écritures d'inventaire permanent.** Aucun compte de classe 3 n'était
mouvementé — ni 31, ni 32, ni 36, ni les variations 603 et 736. Le stock
existait en quantité, pas en valeur.

| Mouvement | Débit | Crédit |
|---|---|---|
| **Entrée** — le stock grossit | Compte de stock (3x) | Compte de variation (603x, 736) |
| **Sortie** — le stock diminue | Compte de variation | Compte de stock |

L'écriture n'est pas une *conséquence* du mouvement : en inventaire permanent,
elle en **fait partie**. Elle se déclenche donc depuis `StockService`, la porte
unique du lot 3, et non depuis les huit endroits qui déplacent de la
marchandise. Le libellé porte le motif — « Mise au rebut — Riz sac 25 kg » —
pour qu'on retrouve au grand livre la raison du mouvement sans revenir au
journal de stock.

Un ajustement d'inventaire n'a pas d'autre pièce que lui-même : il porte alors
sa propre référence, `MVT-{id}`. La colonne n'accepte pas le vide, et une
écriture sans référence serait introuvable au grand livre.

- `tests/Feature/InventairePermanentTest.php` — 16 tests.

#### 4.4 — Le bordereau d'achat ne déduit plus de TVA

Un **BAPA** constate un achat auprès d'un tiers **non immatriculé** : il ne
facture aucune TVA, et il n'y a donc rien à déduire. `ventilationAchat`
recalculait pourtant la TVA depuis `produits.taux_tva`, le taux du catalogue.

L'écriture débitait donc un compte `445` de TVA déductible **sur une taxe que
personne n'avait payée**. Ce n'est pas une imprécision comptable : c'est une
déduction indue, et c'est l'entreprise qui en répond devant l'administration.

C'est le même défaut que celui corrigé au lot 1 sur le document imprimé et sur
le payload FNE — il subsistait dans la troisième sortie, l'écriture comptable.
Le repli sur `achats.montant_tva` est écarté lui aussi : un bordereau dont la
pièce porterait une TVA, saisie à tort ou héritée d'une conversion, la verrait
sinon revenir par la fenêtre.

Deux tests, et le second compte autant que le premier : écarter la TVA partout
serait aussi faux que la déduire partout, donc un achat ordinaire doit continuer
de déduire la sienne.

#### 4.5 — La balance de contrôle

Selflow écrit les écritures, Comptaflow les exploite. Mais **un client sans
abonnement Comptaflow n'avait aucun moyen de vérifier ce que Selflow avait
écrit** : les écritures existaient en base, et nulle part un écran ne les
totalisait. Une erreur d'imputation ne se voyait donc jamais.

`/admin/comptabilite/balance` répond à trois questions, et à trois seulement :

1. **Les débits égalent-ils les crédits ?** Si non, une écriture est incomplète,
   et tout ce qui en découle — résultat, bilan — est faux. C'est le contrôle qui
   prime sur les autres, et il s'affiche en tête.
2. **Quels comptes ont bougé, et de combien ?**
3. **Quelque chose est-il tombé sur un compte générique ?** Une ligne en
   `701000` alors que les rayons portent leurs comptes signale un article créé à
   la main, sans rayon — exactement ce que le lot 4.1 corrige, et ce contrôle
   dit s'il en reste.

Chaque écriture porte **un compte au débit et un compte au crédit**, sur la même
ligne : ce n'est pas une ligne par compte. La balance agrège donc les deux
colonnes séparément avant de les réunir par numéro.

Le solde est **une seule colonne signée** — positif débiteur, négatif créditeur.
L'écran présente ce qu'il veut ; le calcul n'a pas à choisir pour lui.

Ce n'est **pas un état comptable au sens légal**, et l'écran le dit : les états
SYSCOHADA — grand livre, bilan, compte de résultat — restent produits par
Comptaflow.

- `tests/Feature/BalanceTest.php` — 15 tests, dont le filtre de site confronté à
  l'identifiant d'une autre entreprise : sans ce contrôle, `?pdv_id=`
  afficherait le chiffre d'affaires du concurrent.

#### 4.3 et 4.6 — Lettrage et grand livre, d'après Comptaflow

Le dépôt `guysergekouassi/comptaflow` a servi de référence, sur demande du
propriétaire. Ce que j'y ai lu et repris :

| Chez Comptaflow | Dans Selflow |
|---|---|
| `lettrages` : `code`, `date_lettrage`, `user_id`, `company_id` | idem, avec `entreprise_id` et `utilisateur_id` |
| `ecriture_comptables.lettrage_id` | `ecritures_comptables.lettrage_id` |
| Grand livre sur une **plage de comptes**, du compte A au compte B | idem |
| `fetchSoldesInitiaux()` : cumul de tout ce qui précède `date_debut` | `GrandLivreService::soldesInitiaux()` |
| Colonne de lettrage au grand livre (`leftJoin lettrages`) | idem, par relation Eloquent |

**Une différence de structure, et elle décide de tout le reste.** Chez
Comptaflow, une écriture porte **un** compte — `plan_comptable_id`, avec son
débit et son crédit ; une opération compte donc plusieurs écritures. Dans
Selflow, une écriture porte **les deux** comptes sur la même ligne.

Conséquence directe : **une écriture de Selflow produit deux lignes de grand
livre**, une sur le compte débité, une sur le compte crédité, chacune portant
l'autre compte en contrepartie. C'est aussi la traduction que le déversement du
lot 5 devra faire — et c'est ici qu'elle se lit le plus clairement.

**Le défaut que cette différence a failli me faire écrire.** J'avais d'abord
contrôlé l'équilibre d'un lettrage par `SUM(debit) − SUM(credit)`, comme le
ferait Comptaflow. Dans Selflow, chaque écriture porte un débit et un crédit
**égaux** sur la même ligne : cette somme vaut donc toujours zéro, et
**n'importe quel ensemble aurait passé pour équilibré** — une facture de 100 000
se serait lettrée avec un acompte de 40 000, déclarant éteinte une créance dont
il restait 60 000 dus. Le test l'a montré avant le premier utilisateur.

L'équilibre se juge donc **du point de vue du compte lettré** : la facture le
débite, le règlement le crédite, et l'écart est ce qui reste dû. C'est pourquoi
`LettrageService::lettrer()` prend le compte en paramètre — ce n'est pas un
détail de signature.

Deux règles portées par le code :

- **un lettrage équilibre** — un règlement partiel se lettre quand le solde est
  atteint, pas avant ;
- **un lettrage ne se réécrit pas** — on le défait et on recommence. Un code
  n'est jamais recyclé : il désignerait deux rapprochements différents dans
  l'histoire du compte, et le grand livre d'un exercice clos deviendrait faux.

Les codes suivent la convention comptable — `A`, `B`, … `Z`, puis `AA` — et non
un identifiant numérique : c'est ce qu'un comptable s'attend à lire.

**La balance gagne son solde initial** au passage, de la même source. Sans lui,
la balance d'un mois de mars donnerait le solde *de mars* et non le solde du
compte. Le premier jour de la période n'entre pas dans le report : il serait
compté deux fois.

**Le lettrage se pose de lui-même au règlement.** Demander le rapprochement en
seconde manipulation reviendrait à ne jamais l'obtenir, et le compte client
redeviendrait illisible en trois mois. `LettrageService::lettrerLaPiece()` est
appelé après chaque règlement, client comme fournisseur : si l'encaissement
éteint la créance, il lettre ; sinon il **ne fait rien** plutôt que de mal faire.
Un règlement partiel reste ouvert, et c'est le bon résultat — il reste dû.

- `tests/Feature/GrandLivreLettrageTest.php` — 25 tests.

### Lot 5 — La passerelle Comptaflow — **CÔTÉ SELFLOW TERMINÉ**

Le dépôt `guysergekouassi/comptaflow` a servi de référence **et** de cible. Le
côté Selflow est poussé ; **le côté Comptaflow est écrit, vérifié, et attend
d'être appliqué** — la session de développement n'autorise pas l'écriture sur un
dépôt d'un autre propriétaire. Le correctif est versionné dans
`PASSERELLE-COMPTAFLOW/`, avec son mode d'emploi.

#### Un défaut que le lot 4.2 avait introduit, trouvé en lisant le déversement

`ComptabiliteService` écrit **un compte par ligne** depuis l'origine — comme
Comptaflow. Mon `InventairePermanentService` en écrivait **deux sur la même
ligne**. Cela fonctionnait pour la balance et le grand livre de Selflow, qui
gèrent les deux formes, mais le point d'entrée de Comptaflow retient
`compte_debit` s'il est présent et **ignore `compte_credit`** : les deux montants
se seraient imputés sur le seul compte de stock, le compte de variation serait
resté vide, et les deux balances auraient divergé sans que rien ne le signale.

Corrigé : deux lignes, comme partout ailleurs.

#### Le secret partagé, des deux côtés

`config/selflow.php` ne portait plus de valeur de repli depuis le lot 0 — mais
**six sites passaient leur propre repli à l'appel**, ce qui annulait la
correction. `config('selflow.comptaflow_api_secret', 'selflow-comptaflow-secret-2026')`
rend la chaîne en dur quand la variable manque.

Même défaut chez Comptaflow, à sept endroits, et une comparaison par `!==` qui
révèle le secret caractère par caractère au chronomètre.

#### Ce que Selflow envoie désormais

| Champ | Pourquoi il manquait |
|---|---|
| `compte_tiers` | Comptaflow le cherchait dans son plan de tiers depuis le compte général, ne le trouvait pas, et rattachait tout au compte collectif. **Le relevé d'un client était impossible à établir** |
| `cle_selflow` | `n_saisie` recevait la référence de pièce : rejouer une synchronisation dupliquait tout, et la balance doublait |
| `point_de_vente` | Les points de vente sont les axes analytiques, leurs noms les sections. Sans lui, aucune ventilation par magasin |
| `exercice_debut` / `exercice_fin` | Comptaflow prenait le sien sans comparer : une pièce d'un exercice clos se rangeait dans l'exercice courant |

- `tests/Feature/PasserelleComptaflowTest.php` — 9 tests.

#### Ce que le correctif Comptaflow corrige

Secret sans repli et `hash_equals` ; idempotence par `cle_selflow` avec
contrainte d'unicité ; compte de tiers retenu sans jamais créer de fiche ;
exercices comparés, refus en 409 s'ils sont disjoints ; journal inconnu signalé
au lieu de retomber sur le premier de la liste ; `type_de_compte` déduit de la
classe SYSCOHADA au lieu d'`actif` en dur ; compte rendu portant `count`,
`ignorees` et `refus`.

**Le principe posé par le propriétaire est respecté** : code journal, plan
comptable et plan de tiers déjà en place ne sont jamais réécrits par Selflow. Ce
qui manque est créé en dessous, en respectant la configuration initiale de
Comptaflow.

#### Ce qui reste

Pousser le correctif — voir `PASSERELLE-COMPTAFLOW/LISEZ-MOI.md` — et poser
`EXTERNAL_SYNC_SECRET` des deux côtés. Sans elle, la synchronisation refuse
désormais tout appel, ce qui est le comportement voulu.

Reste aussi, côté Selflow : le déversement se fait dans un `created()` de modèle,
par un appel HTTP synchrone de trois secondes. Chaque vente attend donc la
réponse de Comptaflow. À passer en file d'attente.

### Lot 6 — Les manques métier

Devis B2B opposable, nomenclature de production, lots et péremption,
immobilisations, emballages consignés, modèles d'importation complets.

#### Lot 6.1 — La fabrication ne compte plus double — **TERMINÉ**

Le lot 4.2 a fait écrire l'inventaire permanent à la porte unique du stock.
`ProductionControleur::validerOrdre()` appelait **en plus**
`ComptabiliteService::genererEcritureProduction()`, qui écrivait les deux mêmes
paires. Trois conséquences, toutes silencieuses :

| Défaut | Effet |
|---|---|
| **Double écriture** | Le coût de production ressortait au double. Les matières deux fois en charge, le compte de stock crédité deux fois pour une seule sortie physique. Sur un atelier qui produit tous les jours, le stock de matières partait en négatif au bilan |
| **Comptes en dur** | `311000` et `351100` quelle que soit la famille. Une boulangerie et une quincaillerie imputaient au même compte, et `351100` n'existe dans aucun plan — le stock de produits finis est en 36 |
| **Produit fini valorisé à `prix_achat`** | Le prix d'achat d'une chose qu'on ne rachète pas : presque toujours nul. Les matières partaient en charge, rien n'entrait en face, et la fabrication apparaissait en **perte sèche** |

**Corrigé.** `genererEcritureProduction()` est retirée — le commentaire qui prend
sa place dans `ComptabiliteService` dit pourquoi, pour qu'elle ne renaisse pas.
Le produit fini entre au coût de ce qui l'a fabriqué : la somme des sorties
valorisées au CUMP (Coût Unitaire Moyen Pondéré), divisée par la quantité
produite. Les comptes viennent de la chaîne article → rayon → défaut.

**Un garde-fou nouveau.** Chaque mouvement écrit sa paire équilibrée ou n'écrit
rien : une fabrication dont les matières s'imputent mais dont le produit fini ne
s'impute pas reste équilibrée au bilan et **fausse au compte de résultat**. Ce
cas précis — et lui seul — arrête désormais la validation avec le nom du compte
manquant. Un atelier qui n'a rien paramétré du tout fabrique sans écriture, ce
que rien ne lui interdit.

Les deux générateurs de données de démonstration — `SeedMassiveCommand` et
`seed_mega.php` — passaient par `decrementStock()` et par des écritures directes
dans `mouvements_stock`, hors de la porte unique. Leur bloc de production passe
par `StockService` : la balance de démonstration cesse d'être fausse.

Corrigé au passage : `ProductionControleur` initialisait les fiches de stock
d'un produit fini créé à la volée avec une colonne `quantite` qui n'existe pas
et n'est pas dans `$fillable` — la valeur passait à la trappe sans rien signaler.

- `tests/Feature/ProductionTest.php` — 16 tests.

#### Lot 6.2 — Le devis opposable — **TERMINÉ**

Le devis existait déjà comme étape de la vente — Devis → Bon de commande →
Facture — et ne touchait ni le stock, ni la comptabilité, ni la plateforme. Tout
cela était juste. **Mais il n'engageait personne**, et quatre manques
l'expliquaient :

| Manque | Ce qu'il produisait |
|---|---|
| **Aucune date de validité** | Un devis de janvier restait présentable en décembre, aux prix de janvier. Le client qui l'accepte a raison de le faire : rien n'y dit le contraire |
| **Aucune trace de l'acceptation** | Ni la date, ni le nom de qui a accepté. En cas de contestation, rien à opposer |
| **La conversion se rejouait** | `archived` disait qu'elle avait eu lieu sans dire en quoi, et n'empêchait pas la seconde : **le même devis produisait deux bons de commande**, donc deux livraisons et deux factures |
| **La pièce née héritait de la date de son aînée** | `replicate()` recopiait `date_vente` : une facture de juin issue d'un devis de janvier était **datée de janvier**, se rangeait dans la période de janvier et entrait dans la déclaration de TVA du mauvais mois |

**Ce que le lot pose.** Trois colonnes — `date_validite`, `date_acceptation`,
`accepte_par` — et une quatrième, `converti_en_id`, qui dit ce qu'une offre est
devenue. Trente jours de validité par défaut : l'usage commercial courant, et le
délai que retiennent les tribunaux quand l'offre est muette. Le jour du terme
est compris.

Une offre acceptée ou convertie **se relit, elle ne se réécrit pas** : c'est ce
qui fait la différence entre un document opposable et une note. La correction
passe par un nouveau devis, ce qui laisse les deux versions lisibles. Une offre
convertie ne se supprime pas non plus — elle fonde la pièce qui en découle.

Prolonger reste possible tant que l'offre n'a rien produit : c'est refaire
l'offre, donc un geste explicite et tracé. Une offre expirée ne s'accepte plus —
l'accepter après coup ferait croire à un engagement qui n'existe pas.

**Le terme figure sur le document remis**, non seulement dans la base : un bloc
de validité s'affiche sur les quatre modèles d'impression, et porte l'accord du
client dès qu'il est enregistré. Rien de tout cela ne touche à la certification :
un devis n'est ni normalisé, ni transmis, ni certifié, et le lot ne lit ni
n'écrit aucune colonne `fne_*`.

- `2026_08_12_000001_devis_opposable.php`
- `tests/Feature/DevisOpposableTest.php` — 36 tests, dont quatre sur ce que le
  client lit et deux sur le cloisonnement entre entreprises.

#### Lot 6.3 — Les lots et la péremption — **TERMINÉ**

`produits.date_peremption` porte **une seule date par article**. Une pharmacie
qui reçoit trois arrivages de paracétamol — mars, juin, novembre — n'en
enregistre qu'un : la saisie du troisième écrase les deux premiers, et les
boîtes de mars restent en rayon sans que rien ne les signale. Le même défaut
vaut pour un dépôt de boissons, une boulangerie, un magasin de cosmétiques.

| Manque | Ce qu'il produisait |
|---|---|
| **Une date par article** | Le troisième arrivage écrase les deux premiers |
| **Aucune traçabilité** | Un rappel de lot du fabricant est impossible à honorer : rien ne dit quel arrivage est parti chez quel client |
| **Aucun ordre de sortie** | Rien n'impose de servir d'abord ce qui périme le plus tôt ; la marchandise la plus ancienne reste au fond du rayon |
| **`bientotPerime()` était faux** | `diffInDays()` rend une différence **signée** : `-200 <= 30` est vrai, donc l'écran des rebuts annonçait **le catalogue entier** comme proche de la péremption. Une alerte qui crie tout le temps ne se lit plus |
| **Le formulaire ne sauvait pas** | `date_arrivee` et `date_peremption` figuraient sur la fiche article et **n'étaient jamais enregistrées** : l'écran les reprenait vides à la visite suivante |

**La structure.** Un lot est un arrivage : un numéro, une date, une quantité, sur
un site. `mouvement_lots` dit quels lots un mouvement a consommés — **le
mouvement reste un seul mouvement**, la comptabilité, le CUMP (Coût Unitaire
Moyen Pondéré) et le journal ne changent pas. Faire des lots une seconde
valorisation donnerait deux vérités sur la valeur du stock, qui divergeraient au
premier arrondi, et la balance ne saurait plus laquelle croire.

**FEFO, et non FIFO** — *First Expired, First Out*. Les deux règles coïncident
souvent, jamais toujours : un arrivage récent à date courte doit partir avant un
arrivage ancien à date longue, et le FIFO laisserait périmer le premier.

**Le refus de servir du périmé.** Vendre un produit périmé engage la
responsabilité du commerçant, et aucun écran ne rattrape cela après coup. La
marchandise périmée quitte le stock par le rebut, le retour fournisseur, la
contre-passation ou l'inventaire — jamais par la livraison. La vente le dit
**avant** d'écrire quoi que ce soit.

Le suivi s'active article par article : un sac de ciment n'a pas de date, et
imposer un numéro de lot à sa réception ferait perdre du temps sans rien
apporter. Le préavis suit l'article — trente jours conviennent à l'alimentaire ;
un médicament se retire des rayons bien plus tôt, un cosmétique bien plus tard.

Ce qui n'a pas de lot n'est pas bloqué : le stock a pu être posé avant
l'activation du suivi. La ventilation est alors partielle, et l'écart entre le
stock et la somme des lots dit ce qui reste à régulariser.

- `2026_08_13_000001_lots_et_peremption.php`
- `Lot`, `MouvementLot`, `LotService`
- `tests/Feature/LotsEtPeremptionTest.php` — 35 tests, dont cinq de simulation
  d'attaque : vente de périmé par la route, préavis démesuré tronqué en base,
  lecture des lots d'une autre entreprise.

#### Lot 6.4 — Les immobilisations et leur amortissement — **TERMINÉ**

**Rien n'existait.** Un camion, un four, un ordinateur achetés par l'entreprise
passaient en charge de l'exercice, ou ne passaient nulle part. Trois
conséquences, et la troisième coûte de l'argent :

- **le bilan était faux** — l'actif immobilisé, la classe 2, restait vide. Une
  entreprise qui possède un camion de dix millions présentait un bilan qui n'en
  portait pas trace ;
- **le résultat était faux** — un investissement passé en charge écrase le
  résultat de l'année où il est fait, et l'allège indûment les suivantes ;
- **la charge d'amortissement, déductible, n'était pas prise.** Une entreprise
  qui n'amortit pas **paie l'impôt sur un bénéfice qu'elle n'a pas.**

**Le plan se calcule d'avance**, à la mise en service — c'est lui que le
comptable présente au contrôle. C'est la **mise en service** qui déclenche
l'amortissement, non l'acquisition : un matériel acheté en novembre et installé
en janvier ne s'amortit pas sur novembre et décembre. La dernière annuité solde
le plan, sinon les arrondis laisseraient quelques francs non amortis et le bien
resterait indéfiniment au bilan pour ce reliquat.

Les écritures, en SYSCOHADA révisé — les numéros viennent du relevé OHADA du
dépôt, non d'une mémoire :

| Écriture | Débit | Crédit |
|---|---|---|
| **Dotation** | 681x | 28x |
| **Cession** — solde de l'amortissement | 28x | — |
| **Cession** — valeur comptable nette | 810000 | — |
| **Cession** — sortie du bien | — | 2x |
| **Cession** — le prix | 485000 | 820000 |

**La plus-value ne s'écrit pas** : elle apparaît d'elle-même comme différence
entre le 82 et le 81 au compte de résultat. L'inscrire doublerait le résultat de
cession — erreur qu'on retrouve dans beaucoup de logiciels.

**La dotation de l'exercice de sortie est due jusqu'au jour de la sortie.** Le
bien a servi ; l'omettre gonflerait la valeur nette, donc minorerait la charge
et **majorerait la plus-value, sur laquelle l'entreprise serait imposée**.

Une dotation ne se passe qu'une fois — `comptabilise_at` le garantit. La
repasser doublerait la charge et amortirait le bien au double de sa valeur, et
l'erreur ne se verrait qu'au bilan de l'année suivante. Une fiche dont une
dotation est passée ne se retouche plus.

**La seule convention d'usage du lot est isolée et nommée.** Le prorata temporis
se compte en jours sur une année commerciale de 360 jours, chaque mois valant 30
jours — usage OHADA courant. C'est le seul point qui ne vient pas d'un texte du
dépôt : il tient dans `AmortissementService::JOURS_PAR_AN`, pour qu'un cabinet
qui compte en mois entiers change une constante et non le service.
**Confirmé par le propriétaire du projet.**

**Le dégressif n'est pas calculé.** Ses coefficients relèvent d'un texte que le
dépôt ne contient pas, et les supposer donnerait un plan faux que rien ne
signalerait — c'est exactement l'écart qui a produit un timbre de quittance à
1,5 %, taux qui ne figure dans aucun texte.

- `2026_08_14_000001_immobilisations_et_amortissements.php`
- `Immobilisation`, `DotationAmortissement`, `AmortissementService`,
  `ImmobilisationControleur`, trois vues
- `tests/Feature/AmortissementTest.php` — 37 tests, dont dix par les écrans :
  code déjà pris, mise en service antérieure à l'acquisition, valeur résiduelle
  supérieure à la valeur d'acquisition, fiche engagée, sortie antérieure à la
  mise en service, et la fiche d'un bien d'une autre entreprise.

#### Lot 6.5 — Les emballages consignés — **TERMINÉ**

**Rien n'existait**, et c'est le quotidien d'un dépôt de boissons, d'un
distributeur de gaz, d'un grossiste en eau minérale — c'est-à-dire d'une part
considérable du commerce ivoirien.

| Manque | Ce qu'il produisait |
|---|---|
| **La consignation passait en vente, ou nulle part** | Une caisse consignée 2 000 francs gonflait le chiffre d'affaires de 2 000 francs que l'entreprise devra rendre. Ce n'est pas un produit, c'est **une dette** |
| **Rien ne disait ce qui est dehors** | Un dépôt ne savait pas combien de casiers dorment chez ses clients, ni depuis quand, ni chez qui |
| **Le non-retour ne se constatait pas** | La consignation gardée restait indéfiniment en dette au bilan alors qu'elle était devenue un produit |

Les comptes viennent du relevé OHADA du dépôt :

| Sens | Compte | Intitulé |
|---|---|---|
| Consigné **au client** | `419400` | Clients, dettes pour emballages et matériels consignés |
| Consigné **par un fournisseur** | `409400` | Fournisseurs, créances pour emballages et matériels à rendre |
| Gain à la reprise ou au non-retour | `707400` | Bonis sur reprises et cessions d'emballages |
| Perte sur ce qu'on ne rend pas | `622400` | Malis sur emballages |

**Une dette d'un côté, une créance de l'autre** : les confondre met le bilan à
l'envers. Chez le fournisseur, tout s'inverse — ce qu'on ne rend pas devient un
*mali*, une charge, et non un boni.

Les retours partiels sont la règle : un client rend huit casiers sur dix et
garde les deux autres. La reprise à prix réduit laisse un boni — c'est ce qui se
pratique quand l'emballage revient abîmé. Rembourser **plus** que le prix
consigné est refusé : l'entreprise rendrait plus qu'elle n'a reçu, et perdrait
de l'argent sans qu'aucune ligne ne le dise.

**Le service n'établit aucune facture, et c'est délibéré.** Le non-retour est
une vente, soumise à la TVA et à la certification de la plateforme : elle passe
par l'écran de vente ordinaire, dont la conformité est acquise et **gelée**.
Fabriquer ici une seconde route vers la FNE remettrait cette conformité en jeu
pour un gain nul. Le service constate le boni en comptabilité, et l'écran
renvoie l'utilisateur vers la facture pour la part fiscale.

- `2026_08_15_000001_emballages_consignes.php`
- `Consignation`, `ConsignationService`, `ConsignationControleur`, une vue
- `tests/Feature/ConsignationTest.php` — 35 tests, dont sept par les écrans :
  remboursement supérieur au prix consigné tenté par la route, reprise de plus
  que ce qui est dehors, consignation sans tiers, et la consignation d'une autre
  entreprise.

#### Lot 6.6 — Les modèles d'importation — **TERMINÉ**

L'import existait pour cinq modules. Quatre défauts, dont deux arrêtaient net
une migration :

| Défaut | Ce qu'il produisait |
|---|---|
| **Une ligne plus longue que l'en-tête tuait tout le fichier** | `array_combine` lève une erreur dès que les deux tableaux n'ont pas la même taille. **Un simple point-virgule en fin de ligne suffisait** — et Excel comme LibreOffice en produisent. Le fichier entier partait en « Erreur critique », sans dire quelle ligne |
| **`firstOrCreate(['email' => …])` cherchait sur l'adresse seule** | L'adresse est unique sur toute la plateforme : celle d'un autre inscrit faisait que rien n'était créé, et **la ligne comptait pour un succès**. L'administrateur annonçait un accès à un salarié qui ne pourrait jamais se connecter. C'était aussi un **oracle d'existence** silencieux et gratuit : importer une liste d'adresses et lire le compteur suffisait à savoir lesquelles sont inscrites |
| **Un service ne pouvait pas s'importer** | `prix_achat > 0` était exigé de tous. Un cabinet comptable, dont tous les articles sont des missions, ne passait aucune ligne |
| **Le modèle des articles portait douze colonnes** | La fiche en compte deux fois plus. Stock d'ouverture, comptes de stock, suivi par lot, consignation : tout se ressaisissait fiche par fiche après l'import |

**Deux modèles nouveaux.** `stock-initial` alimente un catalogue déjà en place,
site par site et lot par lot ; `immobilisations` reprend un parc avec son
antériorité et établit chaque plan. Le stock passe par `StockService`, la porte
unique — un stock posé à côté n'aurait ni trace au journal, ni valeur au bilan —
et le motif retenu est l'**inventaire** : une ouverture est un comptage, pas une
réception, il n'y a pas de fournisseur derrière.

**L'import des immobilisations ne passe aucune dotation en comptabilité.** Il
établit le plan ; c'est la clôture qui écrit. Passer ici des dotations
antérieures produirait des charges sur des exercices déjà arrêtés.

Corrigé aussi : les en-têtes se lisent quels que soient la casse, les accents et
les doublons ; les nombres à la française — « 12 000,50 », dont `(float)`
rendait **12** — se lisent ; les dates acceptent jj/mm/aaaa comme aaaa-mm-jj ; et
**le taux de TVA ne se rattrape plus en silence.** Une liste `[0, 9, 18]`
recopiée dans l'import aurait dérivé de la source ; c'est `Produit::TAUX_TVA_DGI`
qui fait foi, et un taux hors barème est refusé plutôt que ramené à 18 %.

#### Le défaut du lot 6.4 que ce lot a mis au jour

Un test d'import a fait tomber `AmortissementService::etablirLePlan()`. Pour un
bien dont la durée vaut zéro — un terrain, un fonds de commerce —, la règle « la
dernière annuité solde le plan » s'appliquait à l'unique exercice du parcours et
écrivait une dotation **égale à la valeur entière du bien** : un terrain de 25
millions passait 25 millions en charge.

**Le défaut ne se voyait pas sur une mise en service au 1er janvier** — ce
jour-là, la borne de fin tombe l'année précédente et la boucle ne s'exécute pas
du tout, ce qui est exactement le cas que le test du lot 6.4 couvrait. Un plan
vide est désormais rendu dès que la durée ou la base amortissable est nulle, et
deux tests verrouillent les deux situations.

- `tests/Feature/ImportTest.php` — 36 tests, dont sept de simulation d'attaque :
  adresse d'une autre entreprise, rôle `superadmin` glissé dans le fichier,
  `entreprise_id` injecté dans une colonne, et stock d'ouverture posé sur
  l'article d'un concurrent.

### Lot 7 — La vitrine — **INFRASTRUCTURE TERMINÉE, CONTENU EN ATTENTE**

Landing page, documentation, politique, présentation de DC-Knowing et de ses
produits, écran superadmin de gestion de la vitrine.

Le contenant est livré ; **le contenu ne l'est pas, et ne peut pas l'être
d'ici.** Le texte d'une vitrine engage l'entreprise qu'elle présente : il vient
de son propriétaire, jamais d'un exemple laissé là. Une vitrine vide affiche
une page d'attente honnête plutôt que du faux texte qui finirait en production
le jour où personne ne penserait à le remplacer.

Ce qui existe :

| Élément | Détail |
|---|---|
| Deux tables | `vitrine_sections` et `vitrine_cartes`, cartes en cascade |
| Cinq gabarits | bandeau, colonnes, liste, tarifs, texte — un gabarit inconnu est refusé |
| Page publique | `/presentation`, route `vitrine`, **hors du groupe `guest`** : un connecté la lit aussi |
| Écran superadmin | création, modification, ordre, publication réversible, cartes avec image |
| Publication | une section **naît hors ligne** : publier est un geste délibéré |
| Habilitation | `gestion_vitrine`, accordée aux superadministrateurs en place par migration — le contrôle ferme par défaut, sans elle l'écran serait refusé à tout le monde le jour de sa mise en ligne |
| Liens | `http(s)://` ou chemin interne `/…` seulement : un `javascript:` déposé là s'exécuterait chez chaque visiteur |
| Épreuves | `tests/Feature/VitrineTest.php` — 19, dont 3 simulations d'attaque |

**La vitrine est branchée — 15/08/2026.** Elle existait à `/presentation`,
mais **rien n'y menait** : la racine conduisait le visiteur droit au
formulaire de connexion, et il fallait connaître l'adresse pour lire la
présentation — le contraire d'une vitrine. `/` la sert désormais à qui n'est
pas connecté ; `/presentation` reste valable.

L'état d'attente porte les deux liens qui manquaient — se connecter, créer un
compte. Sans eux, un visiteur arrivé avant la publication n'avait nulle part
où aller.

**La vraie page d'accueil — 15/08/2026.** La première version savait afficher
des cartes en colonnes ; ce n'était pas une page de présentation. Elle a été
refaite :

| Élément | Détail |
|---|---|
| Neuf dispositions | bandeau, colonnes, liste, **produits**, **équipe**, **chiffres**, **média**, tarifs, texte |
| Entrée au défilement | `IntersectionObserver`, une observation par élément, décalage progressif entre les cartes |
| Mouvement réduit | `prefers-reduced-motion` respecté en CSS **et** en JavaScript — tout s'affiche d'emblée |
| La facture qui part à la DGI | entièrement dessinée en CSS : ondes, flottement, piste de transmission, code QR, pastille « Normalisée ». Aucune image à charger |
| Médias | une image ou une vidéo par section, fichier déposé (20 Mo) ou adresse. La vidéo est `muted`, sans quoi le navigateur refuse la lecture automatique |
| Fonds | clair, blanc, sombre — c'est l'alternance qui découpe la page à l'œil |
| Menu | ancres vers les sections, repliable sous 700 px, se referme derrière soi |
| Responsive | trois paliers ; les grilles passent de 4 à 2 à 1 colonne |

**Le semeur `VitrineSeeder` pose la charpente et les textes dictés par le
propriétaire** : les six applications — Selflow, Comptaflow, RHFlow, LegalFlow,
Agent-AI, CGA-Connect —, la fiche du développeur, celle du cabinet, et les
deux entrées documentation et politique. Il passe par `firstOrCreate` sur
chaque clé : le relancer après une saisie n'efface rien, ce qui permet de le
laisser dans `DatabaseSeeder`.

**Ce qui reste vide, et pourquoi.** Photos, vidéos, noms des autres membres :
aucun n'a été donné. Et trois descriptions se réduisent à leur domaine —
RHFlow, LegalFlow, Agent-AI — parce que c'est tout ce que leur nom permet
d'affirmer. **Elles sont à relire.**

La présentation de DC-Knowing ne porte que ce que le propriétaire a dit :
cabinet comptable, et M. Keyman Constant directeur général. Une recherche
publique ne trouve la maison que dans la liste 2023 des Centres de Gestion
Agréés de la DGI, sous un autre nom de responsable — rien qui permette
d'écrire son histoire sans l'inventer.

### Les accès délégués — **TRANCHÉ ET LIVRÉ**

`admin_secondaire` et `responsable_pdv` n'étaient rattachés à aucun espace :
`role:admin` compare à l'identique, et la racine les renvoyait dans la boucle
de redirection. La question restait ouverte de savoir s'il fallait les garder.

**Décision du propriétaire du projet, 15/08/2026 :** ce sont des **accès
délégués**. « C'est comme si je veux déléguer mon travail, donc je crée un
accès pour une autre personne pour avoir accès à mon espace. » Le même espace
que le propriétaire, avec les habilitations qu'il accorde.

Deux corrections, et la seconde est la plus importante :

- **`role:admin` accepte désormais les rôles délégués.** Le middleware dit
  dans quel espace on travaille ; `habilitation`, qui s'exécute juste après et
  ferme par défaut, dit ce qu'on y fait. La racine les conduit au tableau de
  bord d'administration ;
- **`aHabilitation()` rendait `true` pour tout à `admin_secondaire`**, au même
  titre que le propriétaire. Déléguer revenait donc à **céder l'entreprise
  entière** : le compte créé « pour aider aux ventes » atteignait la
  comptabilité, les paramètres fiscaux, et l'écran qui distribue les droits —
  d'où il pouvait s'en accorder d'autres, ou en retirer au propriétaire. Seuls
  le superadministrateur et le propriétaire ont tout.

**Conséquence à connaître :** un compte délégué sans habilitation ne voit
rien, et reçoit un 403 (Forbidden — accès interdit) explicite. C'est le sens
d'un contrôle qui ferme par défaut ; les droits se cochent depuis l'onglet des
habilitations, écran du personnel. Les comptes `admin_secondaire` existants
perdent leur accès total : il faut leur accorder ce qu'ils doivent avoir.

Neuf épreuves dans `AiguillageRacineTest`.

### Lot 8 — Stabilisation

Identifiants opaques dans les URL, habilitations, limitation de débit, un
scénario de bout en bout par combinaison de modules.

#### Lot 8.1 — Les habilitations ferment par défaut — **TERMINÉ**

Le dictionnaire vivait dans le middleware, et la vérification s'écrivait :

```php
if (isset($correspondances[$route])) { … }
```

**Une route absente du dictionnaire passait donc sans contrôle.** Ce n'était pas
une décision, c'était un oubli qui s'aggravait à chaque lot : au moment de
l'audit, **quatre-vingt-huit routes** n'y figuraient pas.

**Ce qui était réellement atteignable, et ce qui ne l'était pas.** L'espace du
caissier compte vingt-six routes ; **neuf d'entre elles** étaient dans les
quatre-vingt-huit, et un caissier n'ayant que `nouvelle_vente` pouvait donc les
emprunter : transformer une commande en facture, enregistrer l'acceptation d'un
client, prolonger une offre, et surtout **créer puis valider des bons de
livraison — qui font sortir la marchandise du stock**. Le reste des
quatre-vingt-huit vit sous `admin.`, derrière `role:admin`, et n'était pas
atteignable ; ce qui les rendait dangereuses n'est pas ce qu'elles ouvraient
hier, mais qu'elles se seraient ouvertes sans bruit le jour où l'espace du
caissier se serait élargi.

**Le sens est inversé : ce qui n'est pas classé est refusé.** Le classement vit
désormais dans `App\Modules\Authentification\Regles\Habilitations`, en deux
tableaux — `PAR_ROUTE` et `OUVERTES`, chaque route ouverte portant sa raison
écrite. Et **le test qui compte échoue tant qu'une route nouvelle n'a pas été
rangée** : la dérive devient impossible au lieu d'être corrigée une fois de plus
dans six mois. Il a d'ailleurs immédiatement trouvé deux routes
`superadmin.referentiel.*` que le dictionnaire d'administration ignorait aussi.

#### L'adresse écrite en dur

```php
if ($utilisateur->email === 'superadmin@gmail.com') return $next($request);
```

Elle n'était pas exploitable — `role:superadmin` s'exécute avant — mais elle
publiait dans le dépôt **l'identité du compte le plus puissant de la
plateforme**, ce qui suffit à en faire la cible de toute tentative. Elle est
retirée.

Le privilège devient une **donnée** : `Habilitations::PLATEFORME` porte les six
habilitations d'administration, les semeurs les attribuent au compte principal,
et une migration les donne aux superadministrateurs déjà en place — sans quoi ils
se retrouveraient enfermés dehors au premier déploiement, personne ne pouvant
leur rendre leurs droits puisque ce sont ces écrans-là qui les distribuent. La
migration ne touche que les comptes dont la colonne est vide : un
superadministrateur volontairement restreint garde sa restriction.

- `App\Modules\Authentification\Regles\Habilitations`
- `2026_08_16_000001_habilitations_des_superadministrateurs.php`
- `tests/Feature/HabilitationsTest.php` — 17 tests.

#### Lot 8.2 — Les limites de débit — **TERMINÉ**

La connexion comptait ses échecs — cinq essais par couple adresse/adresse IP, et
c'était juste. **Tout le reste était libre.**

| Porte | Ce qui n'était borné nulle part |
|---|---|
| **Les 51 routes d'API** | **Le groupe `api` n'existait pas.** Les modules posent leurs routes avec `Route::middleware('api')`, mais `withRouting()` n'ayant pas de volet `api`, le groupe n'était jamais défini : l'authentification par jeton — une chaîne dans une colonne — pouvait être éprouvée sans compter |
| **`api/external/*`** | **La porte la plus précieuse de l'application** : aucune authentification, un secret partagé, et `list-companies` qui rend **toutes les entreprises de la plateforme avec leur administrateur**. Le secret est bien comparé en temps constant depuis le lot 5 — mais un secret se devine, et rien ne comptait les essais |
| **La réinitialisation de mot de passe** | Demander un lien envoie un courriel : on inonde une boîte, on épuise le quota d'envoi, et l'on apprend au passage quelles adresses existent |
| **L'import** | Un fichier de cinq mégaoctets, lu ligne à ligne, qui écrit articles, stock, immobilisations et comptes |
| **La normalisation par lot, les stickers, les tests de connexion** | Ils appellent la plateforme de la DGI et Comptaflow. Les marteler expose l'entreprise à voir **sa propre clé** ralentie ou coupée : la conséquence est chez elle, pas chez nous |

**Le principe des clés.** Une limite se compte par **acteur** — l'utilisateur
authentifié, l'adresse IP sinon — et non par route : compter par route
laisserait un même acteur épuiser trente portes voisines l'une après l'autre
sans jamais franchir une limite. La réinitialisation porte deux bornes : l'une
par acteur, l'autre par adresse IP, qui seule arrête le balayage d'adresses
électroniques.

Les limiteurs sont déclarés dans un fournisseur dédié, chargé **avant** celui
des modules : `throttle:api` sur une route dont le limiteur n'est pas déclaré
lève une erreur au premier appel, non au démarrage — le défaut ne se verrait
qu'en production, sur la première requête. Un test le vérifie pour chacun.

Les épreuves les plus importantes sont **de comportement, non de
configuration** : c'est la réponse du serveur qui fait foi. Soixante-dix appels
à une route d'API finissent bornés ; vingt-cinq tentatives de secret sur la
synchronisation externe aussi ; et un caissier qui enchaîne quarante ventes
n'est pas arrêté.

- `App\Providers\LimitesDeDebit`
- `tests/Feature/LimitesDeDebitTest.php` — 15 tests.

#### Lot 8.3 — L'oracle de volume, et les identifiants opaques — **TERMINÉ**

**Le cloisonnement tenait.** Les soixante-cinq gardes d'appartenance faisaient
leur travail, et aucune donnée d'autrui n'était rendue — l'audit statique n'a
relevé que des faux positifs, `AchatControleur` passant par un helper partagé
que la recherche ne reconnaissait pas, et les routes `superadmin.` agissant sur
toutes les entreprises par construction.

**Mais ces gardes répondaient 403 là où une pièce inexistante répond 404**, et
la différence se lit. Les identifiants étant séquentiels, il suffisait de
demander `/admin/ventes/facture/1`, `/2`, `/3` … et de compter les 403 pour
connaître **le nombre de factures de toute la plateforme** — puis, en
recommençant une semaine plus tard, son rythme de croissance. Ce n'était pas une
faille d'autorisation, c'était une **fuite de volume** ; et pour une plateforme
vendue à des entreprises concurrentes, le volume est une information
commerciale.

Soixante-cinq gardes converties, dans dix-huit contrôleurs. Une pièce qui n'est
pas la vôtre répond désormais comme tout ce qui n'existe pas.

**Le 404 vaut pour l'appartenance, non pour le droit.** Dire à un caissier
« introuvable » sur un écran qui existe et que son administrateur peut lui
ouvrir le laisserait chercher une panne là où il n'y a qu'un droit manquant :
les refus d'habilitation restent des 403, et un test le vérifie.

Un second test empêche la dérive : il relit les contrôleurs et échoue si une
garde d'appartenance neuve est écrite avec un 403.

##### Les identifiants opaques — faits

Le 404 supprimait l'oracle, non le besoin d'identifiants opaques : une adresse
qui porte `4213` dit encore quelque chose à qui la voit passer — dans un courriel
transféré, une capture d'écran, un billet d'assistance, l'historique d'un
navigateur partagé.

**Le propriétaire a tranché pour l'identité unique**, et signalé que le projet
est encore en développement : aucune donnée réelle, donc aucune période de
compatibilité à ménager. Le changement est donc net.

| Décision | Raison |
|---|---|
| **La clé primaire ne change pas** | L'entier reste ce qui porte les jointures, les index et les clés étrangères, où il est plus rapide et plus compact |
| **`getRouteKeyName()` vaut pour le web et l'API** | Deux identités — le numéro sur le mobile, l'`uuid` sur le web — auraient rendu impossible de rapprocher un journal du serveur, un billet d'assistance et une capture d'écran. Et la moitié du problème serait restée entière pour toujours |
| **L'identifiant n'est pas `fillable`** | Le trait le pose lui-même à la création : une requête ne peut donc pas choisir l'identifiant d'une ressource, ni le connaître avant de la créer |
| **L'API publie l'`uuid` à côté de l'`id`** | L'application mobile construit ses adresses depuis ce que l'API rend : sans l'identifiant dans la charge utile, elle n'a rien pour désigner une ressource |

Dix-neuf modèles, dix-neuf tables. Neuf adresses construites dans les vues avec
`$piece->id` et trois construites en JavaScript ont été reprises : elles
généraient l'entier là où la route attend l'identifiant, et **le défaut ne se
serait vu qu'au clic**.

**Un défaut trouvé en chemin.** `replicate()` recopiait l'`uuid` : la contrainte
d'unicité refusait l'enregistrement — un bon de commande ne pouvait plus naître
d'un devis — et si elle ne l'avait pas refusé, **l'adresse du devis aurait
désigné sa commande**, ce qui est exactement ce que l'identifiant sert à
empêcher. L'exclusion est posée dans le trait, non dans les appelants : un
`replicate()` écrit demain ailleurs hériterait sinon du même défaut.

Un test relit les vues et échoue si une adresse est construite avec un numéro de
ligne ; un autre vérifie que chaque modèle désigné dans une adresse porte bien
son identifiant et sa colonne.

- `App\Modules\Admin\Regles\Cloisonnement`,
  `App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque`
- `2026_08_17_000001_identifiants_opaques.php`
- `tests/Feature/CloisonnementTest.php` — 11 tests ;
  `tests/Feature/IdentifiantOpaqueTest.php` — 31 tests.

#### Lot 8.3 bis — La page de panne — **TERMINÉ**

Laravel affichait sa propre page de trace. Sur un écran de caisse, un
utilisateur voyait le nom des fichiers du serveur, les versions des
bibliothèques et le contenu des variables : **illisible pour lui, et trop
lisible pour qui passait par là** — un chemin absolu dit le système
d'exploitation et l'arborescence du déploiement, une version de bibliothèque dit
quelles failles connues essayer.

La page porte désormais le message d'attente demandé, une **référence** à donner
au service informatique, et le détail technique **replié**, qu'on ouvre d'un
clic. Le repli est un `<details>` : il s'ouvre sans une ligne de script et
fonctionne même si le navigateur en refuse.

**La référence se calcule depuis l'endroit exact où la panne s'est produite** —
type, fichier, ligne. Deux occurrences du même défaut portent donc le même
numéro, ce qui permet de les regrouper, et un défaut nouveau se distingue tout
de suite d'un défaut connu. Elle tient en trois groupes courts (`SF-260816-A3F9C1`),
dictables au téléphone, et figure aussi dans le courriel d'alerte : c'est par
elle que les deux bouts se rejoignent.

**Le fond est dessiné dans la page**, en SVG et en dégradés, à la palette de
l'application — le bleu royal `#002B5C`, le gris-bleu `#F4F6F9`, la police
Inter. Aucun fichier à déployer, aucune requête à faire : le jour où le serveur
va mal est le pire moment pour dépendre d'une image qui doit se charger.

**La suite des appels est visible de tous** — décision du propriétaire. Les
chemins sont ramenés à la racine du projet : la pile dit quel fichier a appelé
quel autre, sans dire sous quel compte le serveur tourne ni où le projet est
installé. Un bouton copie tout le détail avec sa référence, puisque le recopier
à la main depuis un écran de caisse n'arrive jamais.

- `resources/views/errors/500.blade.php`, `App\Exceptions\Panne::reference()`
- `tests/Feature/PageDePanneTest.php` — 12 tests.

#### Le tableau de bord général — variable manquante, corrigé

`AdminControleur::tableauDeBordGeneral()` calculait `$totalVentesHTPeriode`
pour en tirer la marge brute et le taux de marge, mais oubliait de la
transmettre à la vue dans le `compact(...)` final. La carte « CA HT » de
`/admin/general` levait donc une erreur 500 (*Internal Server Error* —
erreur interne du serveur) dès le premier chargement, sur toute entreprise —
et rien ne l'avait couvert : aucun test ne touchait cet écran.

- `app/Modules/Admin/Controleurs/AdminControleur.php`
- `tests/Feature/TableauDeBordGeneralTest.php` — 1 test (nouveau : l'écran
  n'était couvert par rien avant).

#### Le déversement Comptaflow — du hook synchrone à la file d'attente

`EcritureComptable::booted()` appelait Comptaflow en HTTP direct dans son
hook `created()` : chaque vente, achat ou mouvement de stock qui produit une
écriture attendait la réponse de Comptaflow — jusqu'à 3 secondes de délai
réseau — avant de rendre la main à l'utilisateur. Un Comptaflow lent ou
indisponible ralentissait donc la caisse elle-même, sur toute entreprise
liée.

L'appel part désormais par `App\Jobs\DeverserEcritureComptaflow`
(`ShouldQueue`, 3 tentatives, 10 secondes de délai entre chacune) — la
clé d'idempotence `cle_selflow` rend le renvoi automatique sans risque de
doublon. Le rattrapage par lot (`php artisan selflow:sync-ecritures`) reste
le filet de sécurité pour ce qui échouerait encore après ces tentatives.
Format du payload et clé d'idempotence inchangés.

- `app/Jobs/DeverserEcritureComptaflow.php`
- `app/Modules/Admin/Modeles/EcritureComptable.php`
- `tests/Feature/PasserelleComptaflowTest.php` — 2 tests ajoutés (11 au
  total) : le déversement part en file, une entreprise non liée n'y met
  rien.

#### Lot 8.4 — Un scénario de bout en bout par combinaison de modules — **TERMINÉ pour les combinaisons atteignables**

`VerifierModulesActifs` ferme les écrans d'un module que l'entreprise n'a pas
souscrit — mais n'était couvert par **aucun test**, dans un sens comme dans
l'autre. Rien ne garantissait que le blindage marchait vraiment, ni qu'une
entreprise à modules réduits pouvait mener un cycle complet avec ce qu'elle
a réellement activé.

Deux choses désormais vérifiées :

- **le blindage** : un module absent de `modules_actifs` ferme ses écrans
  (403 — *Forbidden*, accès interdit), un module présent les ouvre, et le
  superadmin traverse le contrôle lui-même (c'est `role:admin`, en amont,
  qui lui ferme `/admin/*` — pas ce middleware) ;
- **la combinaison « Services »** — une entreprise sans module `stock` ni
  `achats`, qui vend une prestation de type `service`. `estStockable()`
  renvoie faux pour ce type : la vente ne crée aucun mouvement de stock,
  quel que soit l'état du module, et pose tout de même son écriture
  comptable puisque `comptabilite` reste actif.

La combinaison « Commerce » (vente + stock + achats + comptabilité
ensemble) était déjà couverte par `CycleStockTest` et `BalanceTest` — elle
n'est pas dupliquée ici.

**La combinaison « Production »** (production + stock + ventes, sans
achats) est vérifiée de bout en bout : un atelier reçoit sa matière première
sans passer par un achat (le module est éteint), la fabrication consomme la
matière et pose son écriture, le produit fini entre en stock, la vente le
sort et pose la sienne — et l'écran `achats` reste fermé pendant tout le
cycle.

**Une anomalie trouvée en écrivant le scénario B2B.** Contrairement à
`ventes`, `achats`, `stock`, `production` et `comptabilite`, le groupe de
routes `admin.b2b.*` ne porte **aucun** middleware `modules:b2b` : une
entreprise dont `modules_actifs` ne contient pas `b2b` atteint quand même
ces écrans, du moment qu'elle a `ventes` ou `achats`. Le menu fait la même
chose — les liens B2B sont rangés sous les sections Ventes et Achats, pas
sous un module propre. `b2b` figure pourtant dans
`Entreprise::TOUS_LES_MODULES`, comme s'il s'agissait d'un module
souscriptible à part. Non corrigé : c'est une décision de produit (le B2B
est-il un module à part, ou une fonctionnalité incluse dans ventes/achats ?),
pas un bogue technique évident. Un test verrouille le comportement actuel
pour qu'un futur changement soit délibéré — voir section 6, sous-section
B2B.

**Hors de portée pour l'instant** : la combinaison « BTP/chantiers ». Les
modules `chantiers` et `cycles` figurent dans `Entreprise::TOUS_LES_MODULES`
mais **aucune route, aucun contrôleur ne les implémente** — il n'y a rien à
scénariser tant que ces modules ne sortent pas du référentiel.

- `tests/Feature/ModulesActifsTest.php` — 6 tests.

### Lot 9 — Ce que le propriétaire a relevé — **TERMINÉ**

Quatre points signalés dans un même message, le 21 août. Aucun n'était couvert
par une épreuve : c'est pour cela qu'ils vivaient là depuis le début.

#### Lot 9.1 — Les écritures de vente — **TERMINÉ**

Le propriétaire écrit : « les écritures sont mal passées, prenons le cas d'une
écriture de vente avec TVA, normalement on doit avoir 3 lignes — 706/701 crédit,
4432 TVA crédit, 4478 timbre quittance crédit, 411 débit, et si c'est réglé
directement on fait le règlement. Ou bien je me trompe ? »

**Il ne se trompait pas, et le défaut était plus large que ce qu'il décrivait.**

| Défaut | Ce qu'il produisait |
|---|---|
| **La vente comptant ne passait pas par le 411** | Une seule opération « caisse contre produits ». Le compte du client ne bougeait jamais sur ses achats comptant, **le numéro de tiers n'était transmis à Comptaflow sur aucune de ces ventes** — l'écriture y retombait sur le seul compte collectif — et le journal des ventes, celui qui justifie le chiffre d'affaires en cas de contrôle, ne les contenait pas |
| **Le droit de timbre n'entrait dans aucune écriture** | Le client le payait, `net_a_payer` le comptait, la facture l'imprimait, la plateforme le certifiait — mais **la caisse était débitée de moins que ce qui y était réellement entré**, et la dette envers l'État n'apparaissait nulle part |
| **Toute la TVA collectée partait en 4431** | SYSCOHADA distingue la marchandise (4431), la prestation de services (4432) et les travaux (4433). Pour un garage qui vend des pièces et facture de la main-d'œuvre, la déclaration était fausse |

La facturation passe désormais **toujours** par le compte client, pour le net à
payer — TTC fiscal, taxes parafiscales et timbre compris — et le règlement fait
l'objet d'une opération distincte. La TVA se ventile ligne à ligne d'après la
racine du compte de produit ; l'écart d'arrondi est reporté sur le compte le
plus chargé, parce que **c'est le montant de la pièce qui fait foi** — c'est lui
que la plateforme certifie.

**Quatre comptes manquaient au plan des entreprises**, dont le `447000` que le
service imputait déjà sans qu'il figure nulle part : la balance affichait un
numéro sans intitulé. Ils entrent au référentiel (38 comptes communs au lieu de
34) et une migration les pose aux entreprises existantes.

Le timbre reste lu depuis `TimbreQuittanceService`, qui n'est pas touché : le
barème de l'article 873 est gelé.

- `ComptabiliteService::genererEcrituresVente()`, `compteTvaCollectee()`, `accorderTva()`
- `2026_08_23_000001_comptes_de_taxes_collectees.php`
- `tests/Feature/EcrituresVenteTest.php` — 14 tests, dont **six échouent contre
  l'ancien code**, exactement sur les trois défauts.

#### Lot 9.2 — Le domaine d'activité — **TERMINÉ**

« Mets à jour la page `/inscription` car elle affiche l'ancien choix de saisie. »

**Trois listes écrites en dur cohabitaient**, et aucune ne correspondait aux
douze catégories du référentiel :

| Écran | Ce qu'il proposait |
|---|---|
| `/inscription` | dix valeurs — Commercial, Industriel, Agro-industrie, Finance… |
| Paramètres de l'entreprise | douze autres — Agricole, Artisanat, BTP / Construction… |
| Superadministrateur (création, modification, validation) | les mêmes douze |
| **Souscription, étape 1** | **les vraies** — Commerce, E-commerce, Production… |

Une entreprise cochait donc « Commercial » à l'inscription pour choisir
« Commerce » à l'écran suivant. Et l'écran « secteurs ↔ modules » du
superadministrateur rangeait sa configuration sous des clés qu'aucune entreprise
ne portait : **elle ne servait jamais**.

Les cinq écrans et les validations lisent désormais `Categorie::domaines()`. La
sortie libre « Autre » reste offerte — le référentiel couvre douze domaines, il
n'en couvrira jamais treize.

- `Referentiel\Categorie::domaines()`, `Categorie::AUTRE`
- `tests/Feature/DomainesActiviteTest.php` — 7 tests.

#### Lot 9.3 — Le bouton « Configuration » — **TERMINÉ**

« Ajoute un bouton *configuration* dans les paramètres pour pouvoir revenir au
niveau de la configuration, compléter son domaine et autre, **sans changer ce
qui a été coché**. »

Le bouton seul aurait été pire que rien. **Le parcours ne lisait que la
session** : y revenir après une reconnexion — donc toujours — affichait tout
décoché. Le domaine à rechoisir, les métiers oubliés, et surtout **les modules
tous cochés**, si bien que valider l'étape 3 rouvrait ce que l'utilisateur avait
volontairement fermé.

Les choix se relisent en base — métiers souscrits, domaine du premier d'entre
eux, modules actifs — et la session ne fait que les compléter pendant le
parcours. Souscrire deux fois ne double ni les rayons ni les articles : le
service l'écartait déjà.

- `SouscriptionControleur::choix()`, `choixDejaRetenus()`
- Bouton « Configuration » dans les paramètres, bouton « Quitter » dans le parcours
- `tests/Feature/ParcoursSouscriptionTest.php` — 8 tests de plus, dont trois
  échouent contre l'ancien code.

#### Lot 9.4 — Le sens de la passerelle — **CÔTÉ SELFLOW TERMINÉ**

« Selflow ne fait que déverser ses données dans Comptaflow et non construire sur
Comptaflow. Chaque entreprise Selflow avec ses données se déverse dans Comptaflow
comme une importation ; Comptaflow ne fait que recevoir si la liaison existe.
Selflow doit avoir par défaut ses comptes, ses codes journaux et les tiers
divers. »

**Le code faisait exactement l'inverse.** `synchroniserDepuisComptaflow()`
appelait Comptaflow, **recevait** son plan comptable, ses codes journaux et ses
tiers, les recopiait dans Selflow — puis **supprimait** toute ligne Selflow
marquée `source = comptaflow` absente de la réponse. Une entreprise dont le
comptable n'avait pas encore rempli son plan **se retrouvait dépouillée du
sien**, sans que rien ne le lui dise.

Ce que le propriétaire demande par ailleurs est déjà vrai : le trousseau donne à
chaque entreprise, dès sa création, ses 38 comptes, ses 10 journaux et ses deux
tiers de passage — `410000` client divers, `400000` fournisseur divers — sans
rien demander à personne.

La méthode est retirée. `DeversementReferentielService` envoie le référentiel
**sous les colonnes exactes des modèles d'import de Comptaflow**, pour que le
déversement emprunte la logique d'import déjà écrite plutôt qu'une seconde voie
à maintenir. Ce que ces colonnes ne portent pas — téléphone, adresse, NCC —
voyage à côté, sans contrôle, puisque rien de comptable n'en dépend.

Ne partent pas : les comptes et journaux **archivés**, qui réapparaîtraient dans
les listes de Comptaflow sans que personne sache pourquoi ; et les tiers **sans
numéro**, qui s'y rangeraient sous une chaîne vide.

**Ce qui reste chez Comptaflow**, hors de portée depuis ce dépôt : écrire
`POST /api/external/referentiel/deverser`, qui crée le plan comptable, les codes
journaux et les tiers **à partir de ceux de Selflow quand Comptaflow est vide**,
et qui n'écrase ni ne supprime rien quand il ne l'est pas. La consigne complète —
charge utile, ordre de traitement, contrôle du secret, amorçage — est dans
`PASSERELLE-COMPTAFLOW/RECEVOIR-LE-REFERENTIEL.md`. En attendant, le déversement
échoue proprement et le dit à l'utilisateur.

- `DeversementReferentielService`
- `tests/Feature/DeversementReferentielTest.php` — 13 tests.

#### Lot 9.5 — Qui configure la FNE — **TERMINÉ**

Le propriétaire tranche : « **seul le superadmin gère les clés API, seul le
superadmin gère tout ce qui concerne la configuration de la FNE**, et non les
entreprises ; les entreprises offrent uniquement certaines informations. »

L'essentiel était déjà en place — l'entreprise ne voit jamais sa clé, seulement
un statut — mais **un réglage échappait à la règle**, et pas le moins cher.

**Le timbre de quittance.** Il vivait dans les paramètres de l'entreprise, sous
une étiquette « Informatif » qui promettait que « cocher ou décocher ici ne
change aucun montant ». C'était faux, et de plus en plus faux :

- `TimbreQuittanceService::estApplicable()` lit cette colonne pour décider si le
  droit est dû ;
- `net_a_payer` l'ajoute au TTC, et c'est ce total que la facture imprime ;
- depuis le lot 9.1, il commande aussi le débit du compte client et le crédit du
  `447800`.

Coché à tort, il faisait **payer au client un droit que la plateforme ne
retenait pas** ; décoché à tort, l'entreprise le payait de sa poche. Et la case
était à la portée de n'importe quel administrateur d'entreprise.

Le réglage rejoint le tableau FNE du superadministrateur, avec les clés.
L'entreprise continue de **voir** son état — le retirer sans rien dire serait
pire : elle doit pouvoir constater un désaccord avec son espace FNE et le
signaler — mais ne peut plus le poser.

**Ce que l'entreprise fournit, le superadministrateur le voit.** Le tableau FNE
gagne deux colonnes : l'entreprise a-t-elle déclaré un compte FNE, et combien
d'informations lui manquent encore pour en ouvrir un. Sans elles, il fallait
ouvrir les paramètres de chaque entreprise une par une. La liste des dix
informations exigées vit désormais sur le modèle, `Entreprise::informationsFne()`,
parce que **deux écrans la lisent** — écrite deux fois, elle aurait divergé au
premier champ ajouté.

**Un manque du lot 9.2, refermé au passage.** La validation des secteurs dans
les paramètres de l'entreprise acceptait encore n'importe quelle chaîne de
soixante caractères, alors que l'écran proposait déjà le référentiel. Elle
accepte maintenant `Categorie::domainesAcceptesPour($entreprise)` — le
référentiel **plus ce que l'entreprise porte déjà** : sans ce second terme, une
entreprise enregistrée sous l'ancien vocabulaire n'aurait plus pu enregistrer
aucune modification, même sans toucher à son domaine.

Rien de ce qui est gelé n'est touché : le barème de l'article 873 reste dans
`TimbreQuittanceService`, et le payload ne change pas. Seul change **qui a le
droit de poser la colonne**.

- `SuperadminFneControleur::basculerTimbre()`, route `superadmin.fne.timbre`
- `Entreprise::informationsFne()`, `informationsFneManquantes()`
- `Categorie::domainesAcceptesPour()`
- `tests/Feature/ConfigurationFneTest.php` — 9 tests, dont deux échouent contre
  l'ancien code.

### Lot 10 — Le plan complet du propriétaire — **TERMINÉ**

Le message du 21 août avait été envoyé inachevé ; sa version complète ajoute
quatre points, tous vérifiés dans le code avant correction.

#### Lot 10.1 — Le bouton « créer un avoir » — **TERMINÉ**

Signalé tel quel :

```
:8003/admin/ventes/facture-details/169  →  404 (Not Found)
factures?type=avoir:1  Uncaught SyntaxError: Unexpected token '<',
"<!DOCTYPE "... is not valid JSON
```

**Le bouton ne fonctionnait pas du tout**, ni pour les ventes ni pour les
achats. La recherche de factures rendait l'identifiant **numérique**, l'écran le
remettait dans une adresse qui attend un `uuid` depuis le lot 8.3, et la requête
tombait sur un 404 — que le script, lisant la réponse en JSON, recevait sous
forme de page HTML.

La recherche et le détail rendent désormais l'identifiant public, et le
formulaire d'avoir l'attend. Au passage, cela cesse de publier dans une page
l'identifiant séquentiel que le lot 8.3 avait précisément retiré des adresses.

- `VenteControleur::rechercherFactures()`, `detailsFacturePourAvoir()`, `creerAvoirNouveau()`
- `AchatControleur` — le même code y vivait
- `tests/Feature/IdentifiantOpaqueTest.php` — 5 tests de plus, dont trois
  échouent contre l'ancien code.

#### Lot 10.2 — La page d'accueil, visible chez l'un et pas chez l'autre — **TERMINÉ**

Le propriétaire ouvrait `127.0.0.1:8003` et voyait « Cette page est en
préparation » ; un collaborateur, à la même adresse, voyait la page complète.

**Le contenu n'arrivait que par `VitrineSeeder`**, appelé depuis `db:seed` — que
personne ne relance sur une base en service, puisqu'il repose des données de
démonstration par-dessus les vraies. Les installations antérieures à l'écriture
de la vitrine restaient donc vides, sans qu'aucune erreur ne soit levée.

`migrate`, lui, se lance sur une base en service : c'est fait pour ça. Le
contenu suit désormais le même chemin que le schéma, et n'écrase rien — le
semeur crée par clé, et une vitrine déjà remplie n'est pas retouchée. Si aucune
section n'est publiée, le superadministrateur connecté voit désormais pourquoi
et où aller.

- `2026_08_23_000002_poser_le_contenu_de_la_vitrine.php`

#### Lot 10.3 — La création d'un compte, des deux côtés — **TERMINÉ**

« Au niveau du superadmin, lors de la création d'une entreprise et de son
gérant, on a oublié de mettre le mot de passe du gérant. »

**C'était pire que cela.** L'écran créait une entreprise **et personne pour s'y
connecter** : aucun `Utilisateur` n'était enregistré, et le formulaire ne
demandait pas même un mot de passe. Toute entreprise créée par cette voie était
inutilisable, sans qu'aucune erreur ne le signale — il fallait s'en apercevoir,
puis lui fabriquer un compte à la main.

Le compte est désormais créé avec l'entreprise. `doit_changer_password` est posé :
le mot de passe vient d'un tiers, non de son propriétaire.

**Les deux écrans exigeaient par ailleurs des choses différentes** pour créer la
même chose — le domaine était obligatoire chez le superadministrateur et
facultatif à l'inscription. Les étapes vivent maintenant dans
`EtapesCreation`, et les deux écrans les suivent.

| Étape | Bloque la création ? |
|---|---|
| L'entreprise — nom, forme, régime | **oui** |
| Le responsable — identité, mot de passe | **oui** |
| La facture normalisée | non |
| Le domaine d'activité | non |

Le formulaire d'inscription faisait vingt champs d'un seul tenant : on le
remplit mal, ou pas. Il se parcourt pas à pas, avec un bouton « Suivant ». Ce
qui n'est pas rempli ne se perd pas pour autant : `estInscriptionComplete()` le
signale dans les paramètres, et le garde `inscription.complete` retient ventes
et achats tant que la situation fiscale manque.

#### Lot 10.4 — Le compte FNE à la création — **TERMINÉ**

« Pour tous les formulaires lors de la création, fais un bouton à cocher pour
demander s'il a un compte FNE ; si oui, faire afficher deux champs qui demandent
le NCC et le mot de passe FNE ; si non, on va demander les informations fiscales
de l'entreprise. »

Une seule question décide de la suite, et le même bloc sert aux deux écrans
(`admin::partiels.compte-fne`) :

- **elle a un compte** — son NCC et le mot de passe de son espace suffisent.
  Tout le reste est déjà chez la DGI ; le lui faire ressaisir n'introduirait que
  des écarts entre les deux ;
- **elle n'en a pas** — on relève ce que la DGI exige pour lui en ouvrir un.

**Le mot de passe est traité comme une clé d'API**, parce que c'en est une :
c'est un accès à un service de l'État, pas un réglage. Il rejoint
`fne_credentials`, chiffré au repos par `APP_KEY` ; **aucun écran ne le rend**,
pas même au superadministrateur — seulement la date à laquelle il a été fourni ;
et `AccesFneService::oublier()` l'efface une fois le paramétrage relevé.

L'entreprise ne configure toujours rien : elle fournit une information.

- `2026_08_23_000003_acces_fne_de_l_entreprise.php`, `AccesFneService`
- `app/Modules/Admin/Regles/EtapesCreation.php`
- `app/Modules/Admin/Vues/partiels/compte-fne.blade.php`
- `tests/Feature/CreationDeCompteTest.php` — 14 tests, dont cinq échouent contre
  l'ancien code.

### Lot 11 — Ce que l'usage a montré — **TERMINÉ**

Quatre points signalés le 21 août, dont un que le propriétaire ne pouvait pas
voir depuis l'application.

#### Lot 11.1 — Le `.gitignore` avalait la passation — **TERMINÉ**

GitHub répondait `404 (Not Found — introuvable)` sur
`PASSERELLE-COMPTAFLOW/RECEVOIR-LE-REFERENTIEL.md`, alors que le dossier
figurait bien dans l'arbre.

`*.md` et `*xlsx` ignorent tout le dépôt ; la ligne `!PASSERELLE-COMPTAFLOW/`
ne rétablissait que **le répertoire**. En git, la négation d'un répertoire ne
réinclut pas les fichiers qu'il contient. Le dépôt affichait donc un dossier
sans ce qu'on venait y chercher.

N'avaient jamais quitté le poste où ils ont été écrits :

- `RECEVOIR-LE-REFERENTIEL.md`, la consigne du point d'entrée que Comptaflow
  doit écrire — **le seul point bloquant du déversement** ;
- `CONSIGNES-POUR-COMPTAFLOW.md` ;
- les quatre classeurs d'import refaits, dans `modeles-import/`.

`!PASSERELLE-COMPTAFLOW/**` réinclut le contenu. Rien d'autre n'était perdu :
la vérification a passé en revue tous les fichiers ignorés du dépôt.

#### Lot 11.2 — Un service n'est pas en rupture de stock — **TERMINÉ**

Sur l'écran de vente d'un cabinet comptable, **chaque prestation s'affichait
« Rupture de stock » en rouge**.

L'application le savait pourtant là où cela comptait : `estStockable()` existe,
et aucun contrôleur ne décrémente le stock d'une prestation. **Mais aucun écran
ne le lisait.**

| Écran | Ce qu'il faisait |
|---|---|
| Vente (nouvelle et modification) | « Rupture de stock » sous chaque prestation, **et une modale d'alerte à chaque ligne ajoutée**, puis à chaque incrément de quantité |
| Stock, et l'API mobile | tout le catalogue listé, services compris ; le compteur d'alertes comptait chaque prestation pour une rupture |
| Rebut | les prestations y figuraient, avec un bouton de retrait |
| Liste des articles | sa propre copie, écrite en dur, des types sans stock — **à trois endroits dans le même fichier** |

Rien n'était jamais bloqué — le serveur écartait déjà ces articles — mais rien
ne le disait, et l'écran affirmait le contraire. Un cabinet, qui ne vend que des
services, voyait son catalogue entier en rouge et une modale par article.

`etatStock()` rend désormais `Sans stock`, un scope `stockables()` borne les
requêtes, et les cartes portent `data-stockable`.

- `Produit::ETAT_SANS_STOCK`, `getEstStockableAttribute()`, `scopeStockables()`
- `tests/Feature/ArticlesSansStockTest.php` — 11 tests, dont **huit échouent
  contre l'ancien code**.

#### Lot 11.3 — Les achats et le 401 — **TERMINÉ**

« J'espère que les achats aussi sont pris en compte 401 lors des écritures. »
**Ils ne l'étaient pas.** L'achat portait, à l'identique, le défaut que le lot
9.1 avait corrigé sur la vente — et personne ne l'avait regardé.

L'achat comptant écrivait une seule opération, « caisse contre charges », sans
aucune ligne 401 :

- le compte du fournisseur ne bougeait jamais sur ce qu'on lui payait comptant ;
- **son numéro de tiers n'était transmis à Comptaflow sur aucun de ces achats** —
  l'écriture y retombait sur le seul compte collectif, et le relevé d'un
  fournisseur donné devenait impossible à établir ;
- le journal des achats ne les contenait pas.

La facturation passe désormais **toujours** par le compte du fournisseur, et le
règlement fait l'objet d'une opération distincte. Le moyen bancaire et la
référence de paiement, jusqu'ici perdus à la facturation comptant, lui sont
transmis.

**Second défaut, symétrique de celui de la TVA collectée.** Toute la TVA
déductible partait en `445200`, « TVA récupérable sur achats », y compris celle
d'un loyer, d'honoraires ou d'un billet de transport. SYSCOHADA distingue :

| Compte | Nature | Racine de charge |
|---|---|---|
| `445100` | sur immobilisations | `2x` |
| `445200` | sur achats | `60x` |
| `445300` | sur transports | `61x` |
| `445400` | sur services extérieurs et autres charges | `62x`, `63x`… |

Une entreprise qui n'achète que des marchandises ne voyait pas la différence ;
un cabinet, dont l'essentiel des charges est en 62 et 63, la voyait entièrement.
La ventilation s'applique à la facturation comme à l'avoir — sans quoi une
charge de service verrait sa TVA débitée en 4454 et recréditée en 4452, et les
deux comptes dériveraient.

Trois comptes entrent au référentiel (**41 comptes communs au lieu de 38**) et
une migration les pose aux entreprises existantes.

**Ce qui ne bouge pas :** le bordereau d'achat ne déduit toujours aucune TVA,
puisque le tiers n'en facture aucune, et rien de ce qui est gelé n'est touché.

- `ComptabiliteService::genererEcrituresAchat()`, `compteTvaDeductible()`
- `2026_08_24_000001_comptes_de_tva_deductible.php`
- `tests/Feature/EcrituresAchatTest.php` — 14 tests, dont six échouent contre
  l'ancien code.

**Signalé, non corrigé :** `Achat` déclare une colonne `montant_autres_taxes`
qu'**aucun écran n'alimente et qu'aucune écriture ne lit**. Sur la vente, elle
porte les taxes parafiscales collectées pour l'État. À l'achat, une taxe
supportée est une charge, pas une dette : le compte à retenir dépend de sa
nature, et le deviner serait pire que l'omettre. À trancher.

#### Lot 11.4 — Aucune information fiscale avant la question FNE — **TERMINÉ**

« Avant toute information fiscale, demander au client, lors de la création, s'il
a déjà un compte FNE. »

Le bloc du lot 10.4 posait bien la question — mais **le régime d'imposition
était réclamé à la première étape des deux écrans**, obligatoire à
l'inscription, avant même qu'on la pose. C'est une information fiscale : elle
rejoint l'étape de la facture normalisée, et le volet de celles qui n'ont pas
encore de compte. Le faire ressaisir à une entreprise dont l'espace FNE le porte
déjà, c'est ouvrir un écart entre les deux.

**En le déplaçant, quatre listes de régimes écrites en dur sont apparues, avec
quatre contenus différents :**

| Écran | Ce qu'il proposait |
|---|---|
| Superadministrateur | « Réel Normal », « Réel Simplifié », « Bénéfice Forfaitaire », « Micro-Entreprise », « Exonéré » |
| Inscription | TEE, RNE, RSI, RNI — TCE et RME manquaient |
| Paramètres | les six, seule liste juste, en dur |
| Clients / fournisseurs | « TEE (Taxe sur l'Entreprise Employeuse) » |

**Le régime n'est pas une étiquette :** `deduireCodeTva()` le compare aux
régimes d'exonération légale pour choisir entre TVAC et TVAD. Une entreprise
créée par le superadministrateur et enregistrée « Exonéré » voyait ses lignes à
0 % partir en exonération conventionnelle, quel que soit son régime réel — et sa
validation acceptait n'importe quelle chaîne de quatre-vingts caractères.

Une seule source désormais : `Entreprise::REGIMES_IMPOSITION`, avec les
définitions que l'inscription gardait dans son JavaScript pour quatre régimes
sur six. `regimesAcceptesPour()` tolère ce que l'entreprise porte déjà — même
raisonnement que `Categorie::domainesAcceptesPour()`.

**Troisième écart, celui-là avec le périmètre gelé.** Trois vues portaient leur
propre copie des régimes d'exonération légale — `['TEE', 'RNE']` — quand la
constante gelée `Produit::REGIMES_EXONERATION_LEGALE` retient `TEE`, `TCE` et
`RME`. **L'écran annonçait donc un code TVA que le payload ne transmettait
pas** : une entreprise en RME voyait TVAC là où la pièce partait en TVAD, et
l'inverse pour une entreprise en RNE. Les copies sont remplacées par une lecture
de la constante ; **celle-ci n'est pas touchée** — c'est l'écran qu'on remet
d'accord avec elle, non l'inverse.

- `Entreprise::REGIMES_IMPOSITION`, `REGIMES_NOTICES`, `regimesAcceptesPour()`
- `tests/Feature/CreationDeCompteTest.php` — 6 tests de plus, dont trois
  échouent contre l'ancien code.

### Lot 12 — Ce qui dormait — **TERMINÉ**

Trois chantiers, tous autorisés le 24 août : la colonne à trancher, et les deux
chantiers proposés que le propriétaire a débloqués d'un coup.

#### Lot 12.1 — Les taxes supportées à l'achat — **RETIRÉ**

`achats.montant_autres_taxes` était déclarée en `fillable` et en `casts`, et
**rien ne l'écrivait ni ne la lisait** : ni formulaire, ni `ventilationAchat()`,
ni `montant_ttc`. Le propriétaire a tranché : elle est retirée.

La symétrie avec la vente ne tenait pas. À la vente, une taxe additionnelle est
collectée pour le compte de l'État — une **dette**, créditée au `447000`, et
reversée. À l'achat, une taxe supportée est une **charge**, et le compte dépend
de sa nature : droit d'enregistrement, taxe non récupérable, redevance. Il n'y a
pas de compte unique à deviner, et en choisir un au hasard dans la classe 6
aurait été pire que l'omission.

`ventes.montant_autres_taxes` **n'est pas touchée**.

**Trouvé en chemin, non corrigé :** les tables `achat_taxes` et
`achat_detail_taxes` existent, les relations sont déclarées sur `Achat` et
`AchatDetail`, et **aucun écran ne les alimente non plus**. C'est la même
plomberie dormante, posée par symétrie avec la vente. Les retirer suppose de
supprimer deux tables — geste plus lourd qu'une colonne jamais remplie, et que
le propriétaire n'a pas demandé. À décider.

- `2026_08_24_000002_retirer_autres_taxes_de_l_achat.php`
- `tests/Feature/EcrituresAchatTest.php` — 3 tests de plus (17 au total).

#### Lot 12.2 — Les libellés d'écriture — **TERMINÉ**

**Le libellé était l'intitulé du compte.** L'opération d'une facture de vente
portait « Vente de marchandises » — le nom SYSCOHADA du compte mouvementé. Or
le compte porte déjà ce nom : le répéter dépense la **seule colonne de texte
libre du journal**. Un grand livre du `701` dont chaque ligne redit l'en-tête de
la page n'apprend rien à qui le relit ; ce qu'on veut y trouver, c'est quelle
pièce, quel client, quels articles, quel site.

Chaque entreprise pose ses gabarits, deux par type d'opération — celui de
l'opération, celui de ses lignes — pour les six types que `ComptabiliteService`
écrit depuis une pièce commerciale. Neuf jetons : `{piece}`, `{tiers}`,
`{produits}`, `{point_de_vente}`, `{date}`, `{nature}`, `{journal}`,
`{reference}`, `{role}`.

| Décision | Raison |
|---|---|
| **Les défauts reproduisent l'ancien texte au caractère près** | Une entreprise qui ne touche à rien ne doit voir aucun changement dans son journal. Les épreuves comparent aux chaînes exactes d'avant |
| **Les écritures passées ne sont pas réécrites** | Un journal se lit tel qu'il a été tenu ; le regraver effacerait ce que le comptable a relu |
| **Un jeton vide emporte son séparateur** | `Rglt/{piece}/{reference}/Vente {produits}` sur un règlement sans référence donnerait `Rglt/FV-1//Vente…`. Le premier séparateur d'une suite est conservé **avec ses espaces** : c'est ce qui laisse `Rglt/FV-1/Vente` collé et `FV-1 / Facturation` espacé |
| **Un gabarit qui ne produit rien retombe sur le défaut** | Une écriture sans libellé rendrait le journal illisible |
| **Vider les deux champs supprime la ligne** | Enregistrer deux chaînes vides empêcherait une évolution future du défaut de rattraper cette entreprise |
| **`{role}` ne rend rien sur l'opération** | Seule une ligne porte un rôle. L'aperçu promettrait un texte que l'écriture ne produira jamais |
| **`$contexte` prime sur le gabarit au règlement** | « Acompte à la facturation » dit que le règlement ne solde pas la pièce — aucun jeton ne saurait le produire |

**Un défaut trouvé en écrivant les épreuves.** Le règlement construisait ses
jetons avec `$base + ['reference' => $ref]`. L'union de tableaux **garde la
valeur de gauche** sur une clé déjà présente, et `reference` y valait `null` :
le numéro de chèque n'atteignait jamais le libellé. `array_merge` a remplacé le
`+`. Le défaut ne se voyait qu'à la lecture du journal, sur les seuls règlements
par chèque ou virement.

- `2026_08_24_000003_modeles_de_libelle.php`, `ModeleLibelle`,
  `LibelleEcritureService`, `ModeleLibelleControleur`, une vue
- `tests/Feature/LibellesEcritureTest.php` — 22 tests, dont deux simulations
  d'attaque : type d'opération inventé, `entreprise_id` injecté.

#### Lot 12.3 — Le résultat par site — **TERMINÉ**

**La donnée était là depuis toujours, et personne ne la lisait.**
`point_de_vente_id` est porté par chaque écriture — la vente, l'achat, le
règlement, le mouvement de stock, la dotation d'amortissement, la consignation,
et jusqu'à l'écriture manuelle. Il partait vers Comptaflow, qui l'ignore ; la
balance et le grand livre savaient s'y restreindre. Mais **aucun écran ne
mettait les sites côte à côte**, ce qui est la seule question qui compte quand
on en tient plusieurs : lequel gagne de l'argent, lequel en perd.

| Décision | Raison |
|---|---|
| **Classes 6 et 7 seulement** | Le résultat ne se lit pas sur le bilan. Une ligne de trésorerie, de tiers ou de TVA n'y a pas sa place |
| **Les deux sens sont pris** | Un produit vit au crédit, une charge au débit — mais l'avoir et la contre-passation écrivent dans l'autre sens. Ne retenir que la colonne naturelle ferait apparaître un avoir comme une charge, et gonflerait les deux totaux d'un même montant |
| **Aucune clé de répartition** | Une charge de siège reste au site où elle a été saisie. La clé n'existe nulle part dans l'application, et l'inventer donnerait un résultat faux que rien ne signalerait. **L'écran le dit**, et une épreuve vérifie qu'il le dit |
| **Les écritures sans site sont montrées** | Les taire ferait que la somme des sites ne vaudrait pas le résultat de l'entreprise, sans que rien ne l'explique. Elles figurent sous « Sans site », et un bandeau les compte |
| **Le lien n'apparaît qu'à partir de deux sites** | Comparer un magasin à lui-même n'apprend rien |
| **Le comptage des écritures est une requête à part** | Une ligne qui porte une charge au débit et un produit au crédit serait comptée deux fois par les deux passes d'agrégation, et le total annoncé dépasserait le journal |

Ce n'est **pas** une comptabilité analytique complète : pas de sections, pas de
clés, pas de charges indirectes réparties au prorata. C'est la ventilation par
le seul axe que l'application renseigne réellement — le lieu où la pièce a été
établie. Prétendre davantage supposerait des clés que personne n'a données.

- `AnalytiqueService`, `ComptabiliteControleur::analytique()`, une vue
- `tests/Feature/AnalytiqueParSiteTest.php` — 12 tests, dont un de
  cloisonnement et un de simulation d'attaque (caissier sans habilitation).

#### Lot 12.4 — La photo de l'article en fond de carte — **TERMINÉ**

Demandé par le propriétaire le 24/08. Sur un écran de caisse, on reconnaît un
article à son image avant de lire son nom. La photo existait déjà sur la fiche,
et **l'écran de vente ne l'utilisait nulle part** : les cartes se ressemblaient
toutes.

| Décision | Raison |
|---|---|
| **L'image d'attente n'est pas une photo** | `photo_url` rend toujours quelque chose — c'est ce qu'il faut pour une vignette. En arrière-plan, ce serait le même placeholder gris sous toutes les cartes : cela n'apprendrait rien et brouillerait le texte. `photoReelle()` rend `null` quand il n'y a pas de fichier |
| **Un fichier absent du disque ne compte pas** | La colonne peut survivre à la suppression du fichier. Rendre son adresse afficherait une image cassée |
| **Le voile est pris sur le fond de la carte** | `var(--bg3)`, non une couleur écrite en dur : le texte reste lisible quel que soit le thème. Le dégradé s'épaissit vers le bas, où sont le nom, le prix et le stock ; le haut de la photo reste dégagé, c'est là qu'on reconnaît l'article |
| **L'image s'éclaircit au survol** | 45 % au repos, 62 % au survol : la carte visée se distingue sans que la grille devienne illisible |

**Simulation d'attaque.** Le chemin de la photo entre dans un attribut `style`,
entre apostrophes : `style="--fond-produit: url('…')"`. Une apostrophe dans le
nom du fichier refermerait l'attribut et laisserait écrire du HTML dans la page
— une porte ouverte à qui peut téléverser une image. Blade échappe l'apostrophe
en `&#039;` ; **une épreuve le vérifie** plutôt que de le supposer.

- `Produit::photoReelle()`, `ventes/nouvelle.blade.php`, `ventes/modifier.blade.php`
- `tests/Feature/PhotoDeLArticleTest.php` — 9 tests, dont trois échouent contre
  l'ancien code.

#### Lot 12.6 — Les taxes de l'achat — **RETIRÉES**

Le propriétaire a tranché : les tables `achat_taxes` et `achat_detail_taxes`
sont supprimées.

**Le constat du lot 12.1 était incomplet, et je le corrige ici.** J'avais
signalé « la même plomberie dormante ». C'était vrai de
`achat_detail_taxes` — `enregistrerTaxesDeLigne()` n'est appelée que depuis la
vente. Ce ne l'était **pas** de `achat_taxes` : le formulaire d'achat proposait
bien un bloc « Taxes sur total TTC », et `AchatControleur:219` l'enregistrait.

Ce qui est pire que dormant :

| Ce qui se passait | Effet |
|---|---|
| La taxe était **saisie et enregistrée** | L'utilisateur avait toute raison de la croire prise en compte |
| **Rien ne la relisait** | Ni le payload du bordereau d'achat — qui ne transmet aucune taxe, l'un des six écarts corrigés au moment de la conformité, **gelé** —, ni `ventilationAchat()`, ni `montant_ttc`, ni le document imprimé |
| L'écran **l'ajoutait au total affiché** | `const total = htNet + totalAutresTaxes`. Le total montait sous les yeux de l'utilisateur, et la pièce enregistrée l'ignorait, avec sa comptabilité |

Le commentaire de la relation annonçait « → `customTaxes` à la racine du
payload FNE ». C'était faux depuis le lot 3.

**Le bloc de saisie part avec la table.** Garder un champ qui n'écrit plus
nulle part serait pire que le défaut d'origine.

##### Le défaut que ce retrait a mis au jour — la TVA manquait au pavé

En retirant la ligne « Autres taxes » du pavé de totaux, il est apparu que
**l'écran d'achat n'affichait aucune ligne de TVA**, et que son total valait le
seul HT net. Sur un achat de marchandises à 18 %, **l'écran annonçait 18 % de
moins que la pièce enregistrée** — le serveur, lui, calcule bien
`montant_ttc = HT net + TVA`.

La ligne « TVA » prend la place de « Autres taxes », et le calcul de l'écran
suit celui du serveur au même endroit : taux du catalogue par ligne, aucune TVA
sur un bordereau d'achat ni sur une ligne libre, puis réduction au prorata de
la remise globale. Le taux atteint l'écran par un `data-tva` sur chaque option
du sélecteur d'article ; `bapaActive` est déclaré en tête de script, `recalculer()`
le lisant depuis sa zone morte sinon, et la bascule BAPA rafraîchit le pavé.

- `2026_08_24_000004_retirer_les_taxes_de_l_achat.php`
- Modèles `AchatTaxe` et `AchatDetailTaxe` supprimés, relations retirées de
  `Achat` et `AchatDetail`
- `AchatControleur` — l'appel et le chargement anticipé retirés
- `achats/nouveau.blade.php` — bloc, JS et ligne de total
- `tests/Feature/EcrituresAchatTest.php` — 4 tests de plus (21 au total)

#### Lot 12.5 — La passation Comptaflow, prête à ouvrir — **TERMINÉ**

`PASSERELLE-COMPTAFLOW/COMMENCER-ICI.md` : une page d'entrée pour une session
qui reçoit le dossier **sans aucun autre contexte**. Elle porte le principe de
la passerelle, les quatre travaux par ordre d'urgence, ce qu'il ne faut pas
faire, et le rôle de chaque fichier du dossier.

Les six documents précédents disaient chacun une partie ; aucun ne disait par
où commencer.

---

### Lot 13 — Le paramétrage ne se saisit plus deux fois — **TERMINÉ**

Trois demandes du propriétaire, faites le même jour : le fond de carte des
articles ne se voyait toujours pas ; l'ancien choix des secteurs d'activité
était toujours là ; et ce qui est déjà configuré ne devait plus pouvoir se
défaire dès lors que l'entreprise porte des données.

#### 13.1 — La photo de fond ne s'affichait pas — le vrai motif

Le lot 12.4 avait posé la photo en arrière-plan de la carte, et le code était
bien en place. Il ne s'affichait pas pour deux raisons, et la première n'a rien
à voir avec le style.

**L'adresse tombait en 404 (Not Found — introuvable).** `photoReelle()` rendait
`asset('storage/…')`, qui ne vaut que si `public/storage` existe — le lien que
pose `php artisan storage:link`. Sans ce lien, le fichier est bien sur le
disque, `Storage::exists()` répond oui, et l'adresse rendue ne désigne rien.

Le défaut ne se voyait nulle part ailleurs : la vignette d'un article a un
`onerror` qui bascule sur l'image d'attente, et l'écran des articles paraissait
donc normal. **Un fond de carte n'a pas d'`onerror`** — une image introuvable
ne laisse rien, sans un mot.

Correction : quand le lien manque, l'image est servie par une route de
l'application (`GET /admin/produits/{produit}/photo`), qui vérifie
l'appartenance à l'entreprise avant de rendre le fichier. Le chemin rapide
reste le lien de stockage, servi sans passer par PHP.

**Et le voile mangeait ce qui restait.** La photo était posée à `opacity: .45`
sous un dégradé à `.88` : il n'en restait presque rien. La photo est passée à
pleine opacité, le voile ne s'épaissit qu'à partir de 64 % de la hauteur — là
où se trouvent le nom, le prix et le stock —, et la carte a désormais une
hauteur minimale qui laisse voir l'image.

Les deux écrans — nouvelle vente et modification — portent le même bloc.

#### 13.2 — Le secteur d'activité ne se coche plus dans les paramètres

Deux écrans posaient la même question sans se parler : les paramètres de
l'entreprise proposaient de cocher un « secteur d'activité » dans une liste,
pendant que le parcours de configuration demandait un domaine à sa première
étape puis des métiers à la deuxième. **On pouvait déclarer « Santé » d'un côté
et souscrire au métier « Boulangerie » de l'autre** ; les deux réponses
cohabitaient, et rien ne le signalait.

Le bloc de cases est retiré. À sa place, un panneau « Votre configuration » en
lecture seule — domaine, métiers, modules ouverts — et un bouton qui rouvre le
parcours. La colonne `secteur_activite` se déduit désormais du parcours : le
domaine choisi à l'étape 1 la remplit aussitôt, et l'étape 4 la réaligne sur
les métiers réellement souscrits.

Deux pièges évités, chacun gardé par une épreuve :

- **la ligne qui l'enregistrait valait `$request->secteurs_activite ?? []`.**
  Retirer le champ de l'écran sans retirer cette ligne aurait **vidé** la
  colonne à chaque enregistrement — et une entreprise sans secteur retombe en
  « inscription incomplète », bannière comprise. La ligne est supprimée, pas
  neutralisée ;
- **la bannière d'inscription incomplète ne disait plus où aller.** Le secteur
  conditionne la complétude, et l'écran où on le cochait n'existe plus. Elle
  renvoie maintenant au parcours quand c'est lui qui manque.

#### 13.3 — Ce qui porte des données ne se défait plus

Le parcours est **additif presque partout** : rechoisir un domaine ou cocher un
métier de plus n'enlève rien — `souscrire()` passe sur ce qui existe déjà sans
y toucher. Un utilisateur qui vient ajouter une activité la trouve ajoutée.

Deux points demandaient tout de même correction.

**Les métiers déjà souscrits paraissaient décochables.** Décocher n'a jamais
rien retiré, mais l'utilisateur croyait avoir fermé un métier qui restait
ouvert, avec ses rayons et ses articles. Ils reviennent désormais cochés,
désactivés, marqués « déjà en place » — et le contrôleur les remet dans le
choix retenu, pour que l'écran et la base racontent la même chose. La règle
`required_without` ne s'applique plus qu'à la première fois : sans cela, une
entreprise déjà souscrite ne pouvait plus traverser l'étape 2 sans recocher un
métier.

**L'étape des modules, elle, défait réellement.** Elle écrit `modules_actifs`
par intersection : refermer « Comptabilité » sur six mois d'écritures ne
supprime rien, mais fait disparaître de la barre latérale l'écran où ces
écritures se lisent. `VerrouConfigurationService` compte ce que chaque module
porte, et un module qui porte des données ne se referme plus — case désactivée,
compte affiché (« 142 ventes enregistrées »), et le contrôleur le rétablit
quand la liste postée l'omet.

Le comptage a demandé de l'attention : **une vente n'appartient pas à une
entreprise mais à un point de vente.** Un verrou posé sur `entreprise_id`
partout aurait laissé trois modules sur cinq — ventes, achats, stock — sans
protection, sans rien dire. Le service passe par `points_de_vente` pour
ceux-là.

Le verrou ne s'applique qu'à ce qui est vérifiable. Ce qu'on ne sait pas
compter reste libre, plutôt que verrouillé « au cas où » : un verrou sans motif
se contourne, et le suivant ne serait plus cru.

#### Ce que les épreuves gardent

`ConfigurationVerrouilleeTest` — 14 épreuves : la déduction du secteur, son
réalignement, le fait qu'un parcours sans souscription ne le vide pas, les
métiers acquis, le verrou par point de vente, l'isolement entre entreprises, et
deux simulations d'attaque — un module posté sans sa case verrouillée, un
secteur posté à la main dans un formulaire qui ne le propose plus.

`PhotoDeLArticleTest` passe de 9 à 12 épreuves, dont les deux branches de
l'adresse — avec et sans lien de stockage — et le refus de la photo d'une
autre entreprise.

#### 13.4 — Trois reprises, le même jour

**Les modules ouverts n'étaient pas à jour.** Le panneau annonçait « Stock
porte vos données » juste au-dessus d'une liste de modules ouverts où Stock ne
figurait pas — et la barre latérale n'avait pas de section Stock. Le module
avait été fermé avant que le verrou existe : la marchandise était comptée,
valorisée, reprise dans les écritures, et l'écran où elle se lit avait disparu.

Le verrou empêche d'en refermer un ; il ne répare pas ceux qui l'ont été avant.
`VerrouConfigurationService::reconcilier()` rouvre un module fermé qui porte
des données, à l'affichage des écrans de configuration, et le dit. L'opération
est idempotente et n'ouvre **que** ce dont elle a compté les lignes ; une
épreuve le vérifie. Réparer sur une lecture est inhabituel — l'alternative
était de laisser des données hors d'atteinte jusqu'à ce que quelqu'un pense à
rouvrir la bonne case.

**Les noms des modules étaient fabriqués à partir du code.**
`ucfirst(str_replace('_', ' ', $module))` donnait « Comptabilite » sans accent
et « Fne » pour la section que le menu appelle « Fiscalité & DGI ».
L'utilisateur devait deviner quelle case commandait quelle section.
`Entreprise::LIBELLES_MODULES` porte la liste de la barre latérale, et une
épreuve vérifie qu'aucun module n'y manque.

**Le bouton menait à la fin du parcours.** `route('admin.souscription.index')`
ouvre la dernière étape atteinte — la cinquième pour une entreprise déjà
configurée, c'est-à-dire l'écran des prix. Le bouton servait donc à tout sauf à
ce qu'on lui demandait. Il repart de l'étape 1, s'appelle « Ajouter une
configuration », et la première étape dit maintenant, quand un domaine est déjà
souscrit, qu'en choisir un autre **n'enlève rien**.

#### 13.5 — Pourquoi une photo ne s'affiche pas : `selflow:photos`

Trois causes possibles, et **aucune ne se distingue à l'écran** : l'article n'a
pas de photo, le fichier n'est plus sur le disque, ou le lien `public/storage`
manque. Dans les trois cas la vignette montre l'image d'attente — qui ressemble
à une photo — et le fond de carte reste vide.

`php artisan selflow:photos` dit laquelle des trois s'applique, entreprise par
entreprise, avec les noms des articles concernés.

**885 épreuves, 885 vertes, 3 767 vérifications.**

---

### Lot 14 — Ce que six écrans disaient encore de travers — **TERMINÉ**

Six remarques du propriétaire, faites d'un coup. Cinq portaient sur un défaut
réel ; la sixième — les images de cartes — a ouvert une question qui n'avait
jamais été posée.

#### 14.1 — Archiver un article ne le retirait de rien

**Le mot n'avait d'effet que sur un écran.** Le catalogue rangeait les archivés
dans un second onglet ; partout ailleurs, l'article continuait de se proposer
comme avant. La caisse l'affichait en carte, prix compris, et on pouvait le
vendre. Le formulaire d'achat le proposait au réapprovisionnement. La fiche
technique l'acceptait en ingrédient. L'écran des consignations le comptait
parmi les emballages. Le tableau de bord le portait en rupture — **poussant à
commander ce qu'on avait décidé de ne plus vendre**. L'ouverture d'un point de
vente lui créait une fiche de stock neuve.

Le filtre était pourtant écrit : `scopeActifs()` existait, et un seul écran
l'appelait.

| Décision | Raison |
|---|---|
| **Un nom pour la règle, `selectionnables()`** | Et non `actifs()` répété à dix endroits. Quand une raison de plus d'écarter un article apparaîtra — périmé, suspendu —, elle se posera à un seul endroit |
| **Le stock garde l'archivé qui porte une quantité** | La marchandise est là, et les écritures la portent. La taire ferait tomber la valeur de l'inventaire sans que rien ne l'explique, et l'écart resterait sans moyen de le solder. `visiblesEnStock()` le retient tant qu'il n'est pas à zéro |
| **La modification d'une pièce garde ce qu'elle porte déjà** | Un devis établi avant l'archivage porte peut-être l'article rangé depuis. Le taire retirerait la ligne du formulaire, donc de la pièce, à la première modification |
| **Les alertes de rupture l'écartent** | Il ne se réapprovisionne pas |

- `Produit::scopeSelectionnables()`, `Produit::scopeVisiblesEnStock()`
- Vente, achat, production, consignation, points de vente, tableau de bord
- `tests/Feature/ArticleArchiveTest.php` — 8 épreuves, dont 7 tombent contre
  l'ancien code.

#### 14.2 — L'inscription demandait quatre choses pour une

**L'étape 1 réclamait le nom, la forme juridique, l'adresse électronique et le
téléphone.** Une seule est indispensable — et l'adresse électronique, qui est
l'**identifiant de connexion**, était demandée une étape avant qu'on sache qui
se connecte.

Elle rejoint le responsable, avec le téléphone. La forme juridique quitte le
formulaire : elle se renseigne désormais depuis les paramètres, où elle
manquait (voir 14.6).

**Un champ était demandé puis jeté.** `telephone_gerant` — « Téléphone
personnel » — était validé et **jamais enregistré**, ni sur l'entreprise ni sur
l'utilisateur. Retiré plutôt que branché : un seul numéro suffit à joindre le
responsable.

**Le bouton de sortie ne se voyait pas.** « Terminer sans remplir la suite »
était un lien souligné, gris clair, de douze pixels, sous le bouton principal.
C'est pourtant la sortie de l'inscription — les étapes qui suivent sont
facultatives. Il devient un vrai bouton, avec la phrase qui dit ce qu'il fait.

**Le bloc Google se referme après le responsable.** S'inscrire par Google
remplace le formulaire ; passé cette étape, il n'y a plus rien à remplacer, et
la bascule perdrait la saisie.

#### 14.3 — L'étape 4 était l'ancien mécanisme

Elle posait le domaine d'activité par une grille de cases à cocher — celle-là
même qui a été retirée des paramètres au lot 13.2. Deux écrans posaient la même
question sans se parler : **on pouvait déclarer « Santé » à l'inscription et
souscrire « Boulangerie » au parcours**, et les deux réponses cohabitaient.

L'étape part, la règle `secteurs_activite` avec elle, et l'inscription se
parcourt en trois étapes. Une épreuve vérifie qu'un domaine posté à la main ne
s'écrit pas — le champ n'est plus proposé, rien n'empêche de le poster.

**L'écran du superadministrateur garde le sien.** Il crée une entreprise pour
elle et n'a pas le parcours sous la main ; c'était déjà la décision du lot 13.

#### 14.4 — Le reçu s'annonçait non certifié alors qu'il l'était

L'écran de vente affichait, sous le bouton « Reçu » : « Normalisation RNE en
attente. Sa certification auprès de la DGI reste suspendue tant que la FNE n'a
pas fourni les champs de mappage du reçu normalisé électronique. »

C'était vrai avant la refonte du reçu. **Depuis, le reçu emprunte la porte de
la facture** — mêmes champs, même envoi, même sticker consommé —, et rien ne le
retient. Le texte est resté : il disait à l'utilisateur que ses reçus
n'étaient pas certifiés alors qu'ils l'étaient. Le commentaire du code juste
au-dessus disait déjà le contraire.

L'encadré dit maintenant ce qui se passe, et lit le réglage de normalisation
automatique des reçus pour annoncer si la pièce part tout de suite ou attend.

**Rien de la FNE n'a été touché** : ni payload, ni champ, ni colonne. Seul le
texte d'un formulaire.

#### 14.5 — Un seul sac gris pour tout le catalogue

L'image d'attente était la même sous chaque article : trente cartes identiques
où **seul le texte distinguait un sac de riz d'une prestation de conseil**. Sur
un écran de caisse, on cherche l'article à sa forme avant de lire son nom.

| Décision | Raison |
|---|---|
| **Des dessins au trait, tenus dans le dépôt** | Vingt-deux illustrations. Aller chercher sur internet « une bouteille qui ressemble à la vôtre » montrerait une marchandise que l'entreprise ne vend pas — c'est la règle des contenus inventés, appliquée au catalogue |
| **Le choix se fait par mots, du plus précis au plus large** | Le nom de l'article, puis sa famille, puis sa catégorie. L'ordre fait la règle : « eau de javel » doit rencontrer « javel » avant « eau », sans quoi le produit d'entretien s'illustrerait d'une goutte d'eau minérale |
| **Rien n'est deviné au-delà** | Un article dont aucun mot ne parle reçoit le dessin de son type — marchandise ou prestation — plutôt qu'une image prise au hasard qui raconterait autre chose |
| **Les relations ne sont pas chargées à la volée** | La méthode est appelée une fois par carte ; trente cartes feraient soixante requêtes de plus à chaque ouverture de la caisse. Ce qui a été chargé sert, le reste est absent du texte examiné |
| **La photo passe toujours devant** | Elle tient tout le fond de la carte ; le dessin, à défaut, se pose en filigrane au coin. Les deux ne cohabitent pas |

**Simulation d'attaque.** Le nom de l'article traverse le service et revient
dans un attribut `style` entre apostrophes. Un nom bien choisi refermerait
l'attribut. L'adresse ne porte rien du nom — elle vient d'une liste fermée de
vingt-deux dessins — et le nom, qui voyage bien dans la carte, y est échappé.
Une épreuve le vérifie sur les deux points.

Une épreuve relit aussi les dix-sept articles du catalogue réel du propriétaire,
et une autre vérifie que **chaque dessin annoncé existe sur le disque** : un nom
sans fichier rendrait une adresse en 404 (Not Found — introuvable) et la carte
resterait vide sans un mot, exactement le défaut du lot 13.

#### 14.6 — Les paramètres ne disaient pas l'état réel

Le propriétaire a demandé de relire chaque carte à partir de « DGI & Local
professionnel ». Quatre écarts, dont deux qui empêchaient de travailler.

**Le régime d'imposition se refusait à l'enregistrement.** L'écran proposait les
six régimes du modèle ; la règle de validation en portait **quatre, écrits en
dur**, dont `RNE` — qui n'est pas un régime mais le sigle du reçu normalisé, la
confusion déjà corrigée au lot 3. Une entreprise au **TCE** ou au **régime des
microentreprises** choisissait son régime, enregistrait, et se voyait refuser
sans comprendre — les deux régimes que la DGI cite pourtant pour l'exonération
légale. La règle lit désormais `Entreprise::regimesAcceptesPour()`, comme les
quatre autres écrans.

**La forme juridique ne se corrigeait nulle part.** Demandée au seul formulaire
d'inscription, elle n'était plus modifiable ensuite, et la passerelle Comptaflow
retombait sur « SARL » pour tout le monde. Elle rejoint le RCCM.

**Une carte s'annonçait « Lecture seule »** au-dessus de cinq champs qui se
saisissent tous, dont le NCC et le régime — ceux-là mêmes qui décident du code
TVA transmis à la DGI. Annoncer qu'on ne peut rien changer là où tout se change
est la pire des indications. Elle s'appelle « Identité fiscale ».

**La procédure de conformité ne vérifiait rien.** Six points, dont **cinq
portaient `fait => null`** : autant de pastilles grises numérotées,
indiscernables d'un travail non fait. Et sa phrase d'introduction — « Selflow
établit vos factures et les certifient directement aupres de la DGI . Les deux
doivent dire la même chose. » — restait d'une version où Selflow et la
plateforme étaient deux systèmes à tenir d'accord.

| Décision | Raison |
|---|---|
| **Trois états, et non deux** | Vérifié, à corriger, et « nous ne pouvons pas le vérifier d'ici ». Les confondre faisait passer six points pour autant de travaux en retard |
| **Ce qui se compte porte son constat** | Les articles dont le taux de TVA sort du barème DGI, les clients qui portent un NCC, les points de vente déclarés, le seuil d'alerte de stickers. Un nombre vaut mieux qu'une consigne |
| **Ce qui ne se vérifie pas d'ici le dit** | L'API ne communique ni les noms de points de vente déclarés chez la DGI, ni les options cochées sur l'espace. Le point porte « à vérifier sur votre espace FNE » plutôt qu'une pastille qui ressemble à un manquement |
| **L'article archivé ne compte pas dans les taux hors barème** | Il ne part sur aucune facture : le signaler enverrait corriger ce qui n'a plus d'effet |

**La page se parcourt.** Onze cartes sur deux colonnes et trois écrans de haut :
on y cherchait un réglage en faisant défiler. Une barre d'ancres dit d'un coup
d'œil ce que la page contient, et y mène.

- `tests/Feature/ParametresEntrepriseTest.php` — 14 épreuves
- `tests/Feature/IllustrationArticleTest.php` — 27 épreuves
- `tests/Feature/ArticleArchiveTest.php` — 8 épreuves

**942 épreuves, 942 vertes, 3 921 vérifications.**

---

### Lot 15 — La page des paramètres occupe sa largeur — **15.1 TERMINÉ**

#### 15.1 — Huit cartes sur dix tenaient dans la colonne de gauche

Le propriétaire : « à partir de *DGI & Local professionnel* jusqu'à *Impression
des factures*, tout est aligné sur la ligne gauche, ça fait long, l'espace de
droite n'est pas occupé. »

La grille avait bien deux colonnes. Seules **les logos et le récapitulatif**
étaient à droite ; les huit autres cartes s'empilaient à gauche. La moitié
droite de la page restait blanche sur quatre écrans de défilement.

| Colonne | Cartes |
|---|---|
| Gauche | Identité, Identité fiscale (avec la liaison Comptaflow), DGI & local, Numérotation des tiers |
| Droite | Logos, Compte FNE, Options fiscales, Impression des factures, Récapitulatif |
| Pleine largeur | Procédure de conformité FNE |

La conformité est la plus longue des cartes : sur une demi-largeur, ses six
points formaient une colonne de texte de deux écrans de haut. Elle passe sous
les deux colonnes, en pleine largeur, ses points sur deux colonnes internes et
le barème du timbre de quittance à droite — la place qui restait vide.

**Ce que le déplacement a mis au jour : la grille ne se repliait pas.** Elle
portait son `grid-template-columns` en style écrit dans la balise, et un style
de balise l'emporte sur la feuille, media query comprise. Aucune règle de
largeur n'était donc possible : sur un portable, les deux colonnes restaient
côte à côte et les champs devenaient illisibles. Les quatre classes vivent
maintenant dans `@section('styles')`, et la page se replie à 1 024 px.

L'ordre de la barre d'ancres suit celui des cartes à l'écran — sans quoi un
raccourci renvoyait plus haut que le précédent.

- `app/Modules/Admin/Vues/entreprise/parametres.blade.php`
- `tests/Feature/ParametresEntrepriseTest.php` — 4 épreuves ajoutées (18 au total)

Les quatre tombent sans le correctif. **946 épreuves, 946 vertes,
3 945 vérifications.**

#### 15.2 — Une clé de liaison par entreprise — **CÔTÉ SELFLOW TERMINÉ**

Demande du propriétaire : au lieu d'un secret unique partagé, une clé par
entreprise, délivrée quand le superadministrateur valide la demande de dossier
comptable, la liaison s'établissant sans que l'entreprise manipule quoi que ce
soit.

L'état de départ, à ne pas confondre avec ce qui était demandé :

| Élément | Avant ce lot |
|---|---|
| `EXTERNAL_SYNC_SECRET` | **Un seul secret**, la même valeur dans les deux `.env`. Il authentifie Selflow auprès de Comptaflow, et réciproquement |
| `entreprises.comptaflow_sync_key` | Déjà **une valeur par entreprise** — mais **saisie à la main** : champ libre dans les paramètres, avec la consigne « obtenir depuis Comptaflow → Configuration → Liaison Selflow » |

##### Le défaut, et il n'était pas d'ergonomie

`EntrepriseControleur::enregistrerParametres()` acceptait
`comptaflow_sync_key` comme un champ de formulaire ordinaire et déclenchait le
déversement dès qu'il changeait. **Une entreprise qui obtenait la clé d'une
autre la collait dans ses propres paramètres : la liaison s'ouvrait, et son
référentiel puis toutes ses écritures partaient dans les livres de l'autre.**
Le secret partagé n'y changeait rien — il est détenu par le serveur, part sur
tous les appels, et ne dit pas qui appelle.

Quatre autres écrans mentaient sur le même sujet :

| Écran | Ce qu'il faisait |
|---|---|
| « Lancer la synchronisation test » | Annonçait « Synchronisation bidirectionnelle réussie ! Les écritures comptables et les statuts des factures ont été synchronisés » **sans qu'aucun appel ne parte** — et écrivait `comptaflow_sync_status = 'Actif'`, valeur qu'aucun autre code ne reconnaît : le déversement, qui attend `active`, s'arrêtait net après cette « réussite » |
| « Lier manuellement » (superadmin) | Sa route pointait sur une méthode absente du contrôleur : **500 (Internal Server Error) depuis un renommage** que personne n'avait remarqué |
| « Créer compte COMPTAFLOW » | Demandait au superadministrateur de **choisir le mot de passe** du compte d'un client, transmis en clair, et tirait `Str::random(40)` en le déclarant « clé de synchronisation » — Selflow inventait une clé que Comptaflow devait accepter sur parole |
| « Délier » | Effaçait la clé chez Selflow **sans rien dire à Comptaflow**, où elle continuait d'ouvrir le dossier |

Un cinquième, trouvé en chemin : le statut de liaison se déduisait de la
présence du champ dans la requête, si bien qu'enregistrer les paramètres sans y
toucher pouvait faire passer une liaison active à `inactive` — les écritures
cessaient de partir sans que rien ne le dise.

##### La procédure retenue

1. L'entreprise demande. Un bouton, aucun champ.
2. Le superadministrateur voit la file, avec ce qui manque à l'identité fiscale
   signalé — un livre va s'ouvrir au nom de quelqu'un. Il valide, ou refuse
   avec un motif que l'entreprise lit.
3. Selflow appelle `POST /api/external/companies/provision` avec le secret
   **serveur**.
4. **Comptaflow crée le dossier, génère la clé, la renvoie.**
5. Selflow la range chiffrée et déverse le référentiel dans la foulée.
6. L'entreprise lit « Liaison active ». Elle n'a rien manipulé.

C'est Comptaflow qui génère : la clé désigne un dossier chez lui, c'est lui qui
doit la reconnaître. Selflow ne fait que la conserver.

##### Ce que la clé change au modèle d'authentification

Une fois délivrée, **c'est elle qui authentifie chaque déversement**, en
en-tête `X-Company-Key`, et Comptaflow vérifie que la clé et l'entreprise
annoncée dans le corps désignent le même dossier. Le secret partagé se rétracte
alors sur le seul provisionnement. **Un secret volé ne suffit plus à écrire
dans les livres de n'importe qui.**

##### Les garde-fous posés

| Décision | Raison |
|---|---|
| **La clé n'est pas `$fillable`** | Aucune requête ne peut l'y glisser. `LiaisonComptaflowService` est le seul endroit qui la pose, hors affectation en masse |
| **Chiffrée en base** (cast `encrypted`) | Une sauvegarde égarée livrait toutes les clés en clair, donc l'écriture dans les livres de chaque entreprise. La migration chiffre l'existant, et sait reconnaître ce qui l'est déjà pour ne pas chiffrer deux fois |
| **Jamais affichée entière** | Quatre derniers caractères, précédés de points. De quoi reconnaître une clé, pas de quoi s'en servir |
| **Un provisionnement en échec ne lie rien** | La demande reste en attente et se rejoue. Une liaison à moitié ouverte enverrait des écritures qui n'arrivent nulle part, en les disant déversées |
| **404 et 405 sont reconnus comme « point d'entrée absent »** | Le message envoie au déploiement en retard, pas à chercher une clé perdue |
| **Délier révoque des deux côtés** | Une clé oubliée chez l'autre est une clé qui marche |

##### Fichiers

- `app/Modules/Admin/Migrations/2026_08_26_000001_liaison_comptaflow_delivree_et_non_saisie.php`
- `app/Modules/Admin/Services/LiaisonComptaflowService.php` — demander, valider, refuser, révoquer, en-tête
- `EntrepriseControleur`, `SuperadminLiaisonControleur`, `SuperadminControleur`,
  `ExternalSyncControleur`, `DeversementReferentielService`,
  `DeverserEcritureComptaflow`, `Entreprise`
- `Vues/entreprise/parametres.blade.php`, `Vues/superadmin/liaisons/index.blade.php`,
  `Vues/superadmin/entreprises/creer.blade.php`
- `tests/Feature/LiaisonComptaflowTest.php` — 21 épreuves, **20 tombent sans le
  correctif**

**967 épreuves, 967 vertes, 4 007 vérifications.**

##### La moitié Comptaflow — livrée en fichiers, non appliquée

Ce dépôt n'a pas accès à `guysergekouassi/COMPTAFLOW` : une session Claude Code
ne peut pas ajouter un dépôt d'un autre propriétaire que celui avec lequel elle
a démarré. Le travail est donc écrit et rangé dans `docs/passerelle-comptaflow/`,
à remettre à la session Comptaflow :

| Fichier | Contenu |
|---|---|
| `README.md` | Le défaut, le modèle d'authentification visé, la procédure |
| `01-migration-cles-de-liaison.php` | Clé, révocation, index, unicité |
| `02-VerifieCleEntreprise.php` | Middleware `X-Company-Key`, avec la distinction 401 / 403 |
| `03-ExternalCompanyController.php` | `provision`, `revoke`, `verify` |
| `04-routes-api.php` | Les routes et leurs limitations de débit |
| `05-modifications-des-points-existants.md` | Les retouches sur les deux déversements |
| `06-tests-attendus.md` | Ce que les épreuves doivent établir |

Les noms de table et de modèle y sont ceux que la passerelle laisse deviner ;
chaque endroit à contrôler porte un `// À VÉRIFIER`.

**Tant que le middleware n'est pas en place chez Comptaflow, le secret partagé
suffit toujours à écrire dans n'importe quel dossier.** La moitié Selflow ferme
le chemin par le formulaire ; elle ne peut pas fermer celui par l'API.

---

### Lot 16 — Neuf écrans relus par le propriétaire — **TERMINÉ**

#### 16.1 — Trois adresses portaient le numéro de ligne

Deux erreurs 404 (Not Found — introuvable) signalées :
`POST /admin/produits/156/photo` et `POST /admin/clients/52`.

Même cause, et une troisième occurrence trouvée en cherchant. Trois écrans
construisaient une adresse en collant `$modele->id` à un chemin. Or les
adresses de l'application portent l'`uuid` depuis le lot 8.3 — précisément pour
ne pas publier le nombre de pièces de la plateforme. Le lien de route ne
résolvait donc aucun modèle :

| Écran | Ce qui tombait |
|---|---|
| Fiche article et catalogue | **Changer la photo d'un article** |
| Liste des clients | **Modifier un client** |
| Liste des fournisseurs | **Modifier un fournisseur** — personne ne l'avait signalé |

Les trois construisent maintenant leur adresse par le routeur, qui ne peut pas
se tromper de clé.

#### 16.2 — Le compte comptable était exigé sur les cinq types de journal

Il n'a de sens que sur un journal de **trésorerie** : le `521` de la banque, le
`571` de la caisse — celui que chaque écriture du journal mouvemente. Un
journal de ventes ou d'achats n'en a pas : sa contrepartie est le tiers de la
pièce, et elle change à chaque écriture. **Pour créer un journal de ventes, il
fallait inventer une valeur.**

Le champ n'apparaît plus que pour Banque et Caisse. Le propriétaire avait
demandé « uniquement pour Banque » ; la caisse pose le même besoin, et
l'exemple affiché sous le champ le disait déjà — « Ex: 571000, 521000 ». Un
journal de caisse sans compte laisserait la contrepartie de chaque
encaissement indéterminée.

Trouvé en chemin : **la liste des types n'était vérifiée nulle part.** Elle
vivait en dur dans le `<select>`, et la règle de validation acceptait
`string|max:255` — n'importe quelle chaîne entrait en base. Elle vit désormais
sur le modèle, que l'écran et la règle lisent tous deux.

#### 16.3 — On demandait ses identifiants fiscaux à un particulier

NCC, RCCM et régime d'imposition étaient seulement **grisés à 45 % d'opacité**
pour un B2C : trois champs lisibles, cliquables, saisissables — et vides à
jamais, puisqu'un particulier n'en a aucun. Sur la fiche la plus souvent
ouverte de la caisse.

Ils disparaissent pour un B2C, et reviennent pour B2B, B2G et B2F, qui
désignent tous une personne morale. Le compte comptable, lui, reste : il vaut
pour tout le monde. Ce qui n'est pas demandé n'est plus envoyé — un NCC saisi
puis le type basculé sur B2C partait avec le formulaire, et la pièce suivante
était établie en B2B chez la DGI.

#### 16.4 — Le tableau des clés FNE était coupé, sans barre de défilement

La carte portait `overflow:hidden`. Sur sept colonnes, la fin du tableau — les
clés, et la colonne qui permet de les poser — **n'était atteignable par aucun
moyen**, et c'est justement la partie qui sert. La carte laisse défiler, et la
colonne Actions reste collée à droite.

#### 16.5 — Le plan comptable livré n'était pas un plan

L'entreprise recevait les **41 comptes** marqués « communs ». Les **1 256
comptes** de l'acte uniforme restaient un dictionnaire du référentiel, servant
à nommer une subdivision sans jamais entrer dans le plan de personne.

Le compte manquait donc dès qu'on sortait de l'ordinaire — une immobilisation,
un emprunt, une charge de personnel, un impôt autre que la TVA. Il fallait le
créer à la main, en devinant son numéro. **Une imputation sur un compte
inventé ne se rattrape pas** : elle traverse la balance, le grand livre et la
liasse fiscale.

L'entreprise reçoit maintenant le plan **en entier**, posé par lots de 200
lignes. L'intitulé contextuel prime toujours : « État, TVA facturée (18 % —
régime réel) » dit plus que « État, TVA facturée ».

**Ce que ce changement a mis au jour :** la souscription d'un métier inscrivait
ses comptes de famille *s'ils n'existaient pas*. Le plan complet les posant
désormais d'avance, `311100` serait resté « Marchandises A » au lieu de
« Vivres et alimentation ». Le nom du métier prime, et il est maintenant posé
même sur une ligne existante — mais seulement si elle porte encore l'intitulé
générique du référentiel : ce que l'entreprise a renommé à la main n'est jamais
touché.

#### 16.6 — Le trousseau ne se posait qu'une fois

À la création de l'entreprise, et jamais plus. Une entreprise créée avant qu'un
compte ou un journal entre au référentiel — le mobile money, par exemple — ne
l'obtenait plus par aucun chemin.

Deux boutons, l'un sur le plan comptable et l'autre sur les codes journaux,
rejouent la dotation. Rien n'est écrasé : seul ce qui manque est ajouté.

#### 16.7 — Les accès Comptaflow sont les accès Selflow

Décision du propriétaire : **un seul compte pour les deux applications** —
même adresse, même mot de passe. Dans les deux sens : une entreprise qui a
Comptaflow et veut Selflow retrouve les siens.

Ce qui voyage n'est pourtant **jamais le mot de passe** : c'est son empreinte
`bcrypt`, telle qu'elle est en base. Comptaflow la range comme elle arrive, et
le même mot de passe y ouvre le compte — sans que personne, ni le
superadministrateur, ni le réseau, ni le journal d'application, ne l'ait jamais
lu. La route `register-enterprise` de Selflow accepte l'empreinte de la même
façon, pour le sens inverse.

C'est ce qui distingue cette version de celle d'avant, retirée au lot 15 : la
route faisait **choisir par le superadministrateur le mot de passe du compte
d'un client**, et le transportait en clair.

#### 16.8 — Le superadministrateur lie sans attendre de demande

Un client qui souscrit Comptaflow par téléphone n'a pas à cliquer dans un écran
pour que son dossier s'ouvre. Le bouton « Lier maintenant » provisionne
directement.

Ce n'est **pas** le retour de l'ancien « Lier manuellement » : la clé continue
d'être délivrée par Comptaflow, et personne ne la saisit. Seul le déclencheur
change.

#### 16.9 — L'adresse de Comptaflow, et les identifiants, à l'écran

L'entreprise apprenait qu'elle avait un dossier comptable **sans savoir où le
consulter, ni avec quels identifiants**. Les deux manquaient. La carte de
liaison porte maintenant l'adresse — `http://comptaflow.dc-knowing.com/`,
réglable par `COMPTAFLOW_APP_URL` — son adresse de connexion, et la mention que
le mot de passe est celui de Selflow.

- `tests/Feature/EcransDuLotSeizeTest.php` — 15 épreuves
- `tests/Feature/TrousseauALaDemandeTest.php` — 8 épreuves
- `tests/Feature/LiaisonComptaflowTest.php` — 6 épreuves ajoutées (27 au total)

19 des 23 épreuves nouvelles tombent sans le correctif.
**996 épreuves, 996 vertes, 4 068 vérifications.**

---

### Lot 17 — La clé borne aussi ce que l'API rend — **TERMINÉ**

La session Comptaflow a livré sa moitié du lot 15 et signalé, dans son
rapport, que trois de ses points d'entrée restaient sur le seul secret
partagé. **C'était vrai chez nous aussi**, et personne ne l'avait relevé : le
lot 15 avait fermé le chemin par le formulaire, pas celui par l'API.

#### Ce qu'un seul secret volé ouvrait

Le secret est le même pour toutes les entreprises, détenu par le serveur, et
il **ne dit pas qui appelle**. Simulation d'attaque — il fuit par un ancien
salarié, un journal de requêtes, un `.env` recopié sur un poste de
développement :

| Point d'entrée | Ce qu'il rendait |
|---|---|
| `list-companies` | **Toutes les entreprises de la plateforme**, avec adresse, NCC, RCCM, nom du gérant et **adresse électronique de l'administrateur**. L'annuaire complet des clients, et de quoi monter un hameçonnage crédible contre le compte le plus puissant de chaque entreprise |
| `company-info` | La fiche de n'importe laquelle |
| `tier-info` | Le téléphone, l'adresse et le NCC de n'importe quel client de n'importe quelle entreprise — son carnet d'adresses commercial |

#### Ce qui le ferme

La clé du dossier désigne une entreprise et une seule. Présentée en en-tête
`X-Company-Key`, elle borne la réponse à cette entreprise. Une clé qui en
désigne une autre reçoit **403 (Forbidden — accès interdit)** ; une clé
inconnue ou révoquée, **401 (Unauthorized — non authentifié)**. Les confondre
rendrait le journal illisible — on ne distinguerait plus un déploiement mal
configuré d'une tentative de lecture croisée.

`list-companies` ne rend plus que ce qui sert à **rapprocher** un dossier —
identifiant, nom, date, état de liaison. Le détail se demande par
`company-info`, clé en main.

#### Ce qui reste ouvert, et il faut le dire

**La tolérance de transition est encore en place, des deux côtés.** Un appel
sans en-tête passe toujours sur le seul secret. Trois blocs, marqués en
majuscules, à retirer **ensemble** le jour du déploiement conjoint :

| Où | Quoi |
|---|---|
| Selflow | `ExternalSyncControleur::entrepriseDeLaCle()` |
| Comptaflow | `VerifieCleEntreprise::handle()` |
| Comptaflow | le `??` de `ExternalSyncController::entrepriseDeLaRequete()` |

En retirer un ou deux laisse la porte ouverte du côté qu'on n'a pas fermé.
`PasserelleEntranteTest::test_la_tolerance_de_transition_est_encore_ouverte`
documente la porte et **tombera** le jour où elle sera fermée : c'est ce qui
forcera à activer l'épreuve qui la remplace, écrite juste en dessous et
commentée.

#### Ce que la session Comptaflow a rapporté

| Point | Ce qui a été trouvé chez elle |
|---|---|
| Stockage de la clé | Haché pour la recherche, **plus une copie chiffrée** — le haché seul interdisait l'idempotence de `provision`, qui suppose de pouvoir retourner la clé. Elle n'est en clair nulle part |
| `referentiel/deverser` | **N'existait pas dans `main`** : il vivait sur une branche non fusionnée, reprise telle quelle plutôt que réécrite |
| `tier_digits` | **Valait 8, et non 6.** Deux migrations se contredisaient — celle qui posait 6 ne s'exécutait que si la colonne n'existait pas, donc jamais. Corrigé, et nos 6 caractères de `NumerotationTiersService::LONGUEUR` sont bien la référence |
| Lien d'activation | Aucune infrastructure de réinitialisation n'existait ; elle a été construite. Elle sert de repli — le cas normal est l'empreinte du mot de passe Selflow, transmise au lot 16 |

- `tests/Feature/PasserelleEntranteTest.php` — 10 épreuves, **6 tombent** sans
  le correctif (les 4 autres décrivent un comportement déjà juste)

**1 006 épreuves, 1 006 vertes, 4 086 vérifications.**

---

### Lot 18 — L'avis d'ouverture du dossier comptable — **TERMINÉ**

Question du propriétaire : lien d'activation où le client choisit un mot de
passe, ou mot de passe identique à celui de Selflow ?

**Tranché : le mot de passe reste celui de Selflow, et un courriel prévient.**

Le lien d'activation fait choisir un second mot de passe à quelqu'un qui n'a
rien demandé, il expire, il se perd, et il produit exactement ce qu'on voulait
éviter — deux mots de passe pour la même personne. Il reste le **repli** quand
l'empreinte manque.

Mais l'avis, lui, n'est pas du confort. **Un compte s'ouvrait au nom du client,
chez une autre application, sans qu'il en soit informé.** Le
superadministrateur le voyait, l'écran des paramètres le disait à qui allait le
lire ; le titulaire ne savait rien.

#### Ce que le message ne contient pas, et ne contiendra jamais

| Ce qui n'y est pas | Pourquoi |
|---|---|
| **Le mot de passe** | On dit *lequel* c'est, jamais *quel* il est. Un courriel traverse des serveurs qu'on ne choisit pas, se range dans une boîte parfois partagée, et dort des années dans une sauvegarde |
| **La clé de liaison** | Elle ne concerne pas le client : la lui montrer ferait d'un secret d'infrastructure une donnée qui traîne |

Le message porte en revanche **« Vous n'attendiez pas ce message ? »** — une
personne qui reçoit l'avis d'un dossier ouvert à son nom sans l'avoir demandé
doit savoir à qui s'adresser.

#### La réserve, écrite dans le message

Les deux mots de passe sont identiques **au jour de l'ouverture**, et le
courriel le dit ainsi. Si le client change celui de Selflow ensuite, celui de
Comptaflow ne suit pas : **ils divergent en silence**, et le client à qui on a
dit « c'est le même » ne comprendra pas. Trois issues, à trancher plus tard :
l'accepter et le dire (fait), propager le changement par la passerelle
(recommandé, petit lot), ou une connexion unique (chantier).

#### Ce qui a été soigné en chemin

- **Une messagerie en panne ne défait pas la liaison.** L'envoi est enveloppé :
  le contraire laisserait la demande en attente alors que le dossier existe
  déjà chez Comptaflow, et le rejeu en ouvrirait un second ;
- la mise en page tient sur des **tableaux imbriqués et des styles écrits dans
  les balises** — Outlook rend le HTML avec le moteur de Word, Gmail retire les
  feuilles de style. Ce qui serait une faute sur une page l'est ici l'inverse.

- `app/Mail/CompteComptaflowOuvert.php`
- `resources/views/emails/comptaflow/compte-ouvert.blade.php`
- `tests/Feature/LiaisonComptaflowTest.php` — 4 épreuves ajoutées (31 au total)

**1 010 épreuves, 1 010 vertes, 4 091 vérifications.**

---

### Lot 19 — Le point de vente, et la clé qui tourne — **TERMINÉ**

#### 19.1 — Le point de vente ne s'invente plus

**Le nom du point de vente part tel quel à la plateforme de la DGI**, avec
chaque facture, et elle refuse la pièce s'il ne correspond à aucun site
déclaré sur l'espace FNE. Ce n'est pas une commodité d'organisation : c'est
une donnée fiscale, et elle appartient à l'entreprise.

**Quatre endroits en créaient un d'office** — la création par le
superadministrateur, la passerelle entrante, et la caisse **deux fois**, dans
`nouvelle()` et dans `enregistrer()` :

```
nom : « Siège » · ville : l'adresse coupée à la première virgule
commune : « Cocody » ou « Plateau » · responsable : « Superviseur »
```

Trois informations inventées, sous un nom qui n'a aucune raison d'être celui
de l'espace FNE. **La première facture partait à ce nom-là.** L'entreprise
crée le sien ; les quatre créations d'office sont retirées.

#### 19.2 — Le point de vente entre dans les blocages

Il n'y figurait pas, et c'est le plus déterminant de tous : une entreprise
sans point de vente ne peut rien certifier, et le découvrait au premier
encaissement — ou ne le découvrait pas, puisque la caisse en fabriquait un.

`estInscriptionComplete()` devient un comptage :
`elementsInscriptionManquants()` rend la liste, avec pour chaque manque **son
libellé et l'écran où il se règle**.

**Ce que le changement a mis au jour :** l'écran de blocage disait « Terminer
votre inscription… renseigner toutes les informations réglementaires » sans
jamais dire **lesquelles**, et son bouton menait toujours aux paramètres —
même quand ce qui manquait se réglait ailleurs. Il liste maintenant les
manques et mène au premier : les paramètres, le parcours, ou l'écran des
points de vente.

#### 19.3 — Le point de vente actif survit à la déconnexion

Il ne vivait que dans `session('point_de_vente_actif_id')`, et
`deconnecter()` appelle `session()->invalidate()` — ce qui est juste. Le choix
partait avec.

Un responsable de trois magasins **repartait donc chaque matin sur le premier
venu**, sans que rien ne le dise, et pouvait encaisser au nom d'un magasin où
il n'était pas. Une pièce certifiée sous le mauvais site ne se corrige pas :
elle s'annule par un avoir.

Une colonne à part, `utilisateurs.point_de_vente_actif_id`, et non
`point_de_vente_id` : pour un caissier, celui-ci est son **affectation**,
décidée par son responsable. Y écrire le dernier choix ferait qu'un caissier
qui bascule d'écran changerait son affectation. Deux idées, deux colonnes — et
l'affectation prime toujours pour un caissier.

Le point de vente retenu est **revérifié à chaque reprise** : il peut avoir
été supprimé, ou appartenir à une autre entreprise si la valeur a été écrite à
la main.

#### 19.4 — La clé de liaison tourne

Décision du propriétaire : le superadministrateur renouvelle quand il veut, et
une rotation automatique passe chaque mois.

Une clé posée une fois et jamais changée ouvre le dossier comptable aussi
longtemps que l'entreprise existe. La rotation ne rend pas une fuite
impossible : **elle borne sa durée de vie à un mois.**

| Ce qui garantit que rien ne casse | Comment |
|---|---|
| Un appel qui échoue ne coupe rien | **Rien n'est écrit tant que la nouvelle clé n'est pas en main.** L'ancienne reste active, et la rotation se rejoue |
| Les écritures en file repartent bien | `DeverserEcritureComptaflow` relit le modèle en base au moment de s'exécuter : elle lira la nouvelle clé |
| Une requête déjà en vol est acceptée | **Période de grâce tenue par Comptaflow** — l'ancienne clé vaut encore quelques minutes. Sans elle, un déversement parti à l'instant du renouvellement échouerait, rarement et sans qu'on comprenne |
| Comptaflow pas encore déployé | 404 et 405 sont reconnus comme « ce point d'entrée n'existe pas », le message le dit, la clé en place continue de servir |
| Une panne ne déclenche pas une boucle | Un échec est **daté** et met le dossier **au repos douze heures** |
| Un dossier en échec n'arrête pas les autres | Chaque renouvellement dans son propre `try`, et la commande rend toujours la main |

`php artisan selflow:renouveler-cles-comptaflow`, avec `--a-blanc` pour voir
sans appeler. Planifiée le 1ᵉʳ du mois à 3 h — heure où aucune caisse
n'encaisse, donc où une clé qui change ne croise aucun déversement.

L'écran du superadministrateur montre la date de la dernière rotation, signale
celles qui ont dépassé leur durée, et affiche le dernier échec s'il y en a un.

**Ce qui reste dû chez Comptaflow :** le point d'entrée
`POST /api/external/companies/rotate-key` et sa période de grâce. Spécifiés
dans `docs/passerelle-comptaflow/05-…`. Tant qu'il n'existe pas, la rotation
le dit et ne casse rien.

- `tests/Feature/PointDeVenteObligatoireTest.php` — 13 épreuves, **11 tombent**
  sans le correctif
- `tests/Feature/LiaisonComptaflowTest.php` — 11 épreuves ajoutées (42 au total)

**1 034 épreuves, 1 034 vertes, 4 141 vérifications.**

---

### Lot 20 — Choisir la facture d'origine d'un avoir — **TERMINÉ**

Signalé par le propriétaire, console du navigateur à l'appui :

```
GET /admin/ventes/facture-details/169  →  404 (Not Found — introuvable)
SyntaxError: Unexpected token '<', "<!DOCTYPE "... is not valid JSON
```

Le second message découle du premier : le script lisait la réponse en JSON et
recevait la page d'erreur en HTML. **Choisir une facture d'origine ne faisait
rien** — aucun message à l'écran, la modale restait vide, et seule la console
en disait quelque chose.

La liste déroulante rendait `$f->id`, le numéro de ligne, quand les adresses
de l'application portent l'`uuid` depuis le lot 8.3. Et si la requête avait
abouti, l'envoi du formulaire aurait échoué au coup d'après : `parent_id` est
validé `['required', 'uuid', …]`.

**Pourquoi le défaut avait survécu.** Le même écran porte **deux** façons de
choisir la pièce — une liste déroulante et un champ de recherche. Le champ de
recherche avait été corrigé, et le commentaire de son contrôleur décrit
exactement ce défaut ; la liste était restée au numéro de ligne. **Une moitié
réparée cachait l'autre.**

**Le même défaut existait sur l'avoir d'achat**, où personne ne l'avait
rencontré. Corrigé aussi.

C'est la troisième occurrence de la même famille — après la photo d'article,
la fiche client et la fiche fournisseur au lot 16. L'épreuve
`test_le_numero_de_ligne_ne_resout_aucune_facture` fixe la raison plutôt que
le symptôme : ce n'est pas la route qu'il faut assouplir, c'est l'écran qui
doit donner l'identifiant public.

- `tests/Feature/AvoirChoixDeLaPieceTest.php` — 6 épreuves, **2 tombent** sans
  le correctif (les 4 autres décrivent un comportement déjà juste, dont le
  refus de traverser les entreprises)

**1 040 épreuves, 1 040 vertes, 4 152 vérifications.**

---

### Lot 21 — Le déploiement s'arrêtait sur une colonne absente — **TERMINÉ**

Signalé par le propriétaire, depuis le serveur :

```
php artisan migrate
2026_08_09_000003_engagements_calcules ......................... FAIL
SQLSTATE[42000] 1091 Can't DROP COLUMN `quantite_commandee`;
check that it exists
```

**« Tout passe en local, pourquoi ça bloque en ligne ? »** — la question était
la bonne, et la réponse ne se lit pas dans le message d'erreur.

#### La cause, et elle n'est pas dans le fichier qui échoue

`produits.quantite_commandee` et `quantite_a_receptionner` **n'ont jamais
existé sur ce serveur**. Elles ont été ajoutées à
`2026_06_05_000004_creer_table_produits` le **20 juillet** — commit `d6a2227`,
deux lignes —, soit **quarante-cinq jours après la date de cette migration**,
donc longtemps après qu'elle eut été appliquée en production.

**Une migration déjà jouée ne se rejoue pas.** Retoucher son fichier ne change
rien à la base qui l'a passée. Le serveur a gardé un `produits` sans ces
colonnes, pendant qu'en local chaque `migrate:fresh` les recréait. D'où l'écart
exact que le propriétaire décrit :

| | Ce qui se passe |
|---|---|
| **En local** | `migrate:fresh` rejoue tout dans l'ordre, avec les fichiers d'aujourd'hui. Les colonnes sont créées, puis supprimées. Suite verte |
| **En production** | La base porte l'histoire réelle des migrations **telles qu'elles étaient le jour où elles sont passées**. Les colonnes n'ont jamais été là, et le retrait échoue |

Une suite verte ne dit rien de ce second cas. C'est ce que le lot ajoute.

#### Le même écart s'était déjà produit, et avait été traité

Le **même commit du même jour** ajoutait `secteur_activite` et `modules_actifs`
à `2026_06_05_000001_creer_table_entreprises`. Là, quelqu'un avait vu le
problème et écrit
`2026_07_20_133019_add_missing_columns_to_entreprises` — une migration de
rattrapage, datée du jour, qui pose les colonnes sur les bases qui ne les ont
pas.

**`produits` a reçu la retouche, jamais le rattrapage.** Le défaut a dormi
trente-huit jours, jusqu'à ce qu'une migration du 9 août veuille supprimer ce
qui n'existait pas.

#### Ce qui a été corrigé

Pas de migration de rattrapage ici : **ces colonnes doivent disparaître, pas
revenir**. C'est le retrait qui devait tenir compte de leur absence.

Une migration décrit **un état**, non un geste : « ces colonnes ne doivent plus
être là ». Là où elles n'ont jamais été, il n'y a rien à faire.

Quatre autres migrations portaient le même défaut latent, trouvées en
cherchant :

| Migration | Ce qu'elle retirait sans vérifier |
|---|---|
| `2026_06_29_000001_creer_table_stocks` | `produits.stock_actuel`, `stock_minimum` |
| `2026_06_29_000002_creer_categories…` | `produits.categorie` et son index |
| `2026_06_19_000001_ajouter_entreprise_id…` | l'unicité globale de `plan_comptable.numero` |
| `2026_07_24_000002_corriger_unicite_code_ordre…` | l'unicité globale de `ordres_production.code_ordre` |

Toutes ont déjà passé en production ; la correction est préventive. La
migration sœur `2026_07_24_000001` était, elle, déjà gardée — par `try/catch`
et un `indexExists()`.

#### Ce que les épreuves établissent

- **La reproduction** : `up()` de `2026_08_09_000003` est appelée sur une base
  qui n'a jamais eu les colonnes. Sans le correctif, elle échoue avec l'erreur
  de production transposée à SQLite — `no such column: "quantite_commandee"` ;
- **le rejeu** : les colonnes remises, `up()` passe deux fois de suite — ce que
  fait un déploiement relancé après interruption, MySQL n'annulant pas ses
  ordres de structure ;
- **la famille** : aucune migration ne retire une colonne ou un index dans son
  `up()` sans vérifier qu'il est là. Le message d'échec dit pourquoi.

- `tests/Feature/MigrationsRejouablesTest.php` — 5 épreuves, **4 tombent** sans
  le correctif

**1 045 épreuves, 1 045 vertes, 4 166 vérifications.**

---

### Lot 22 — Les deux applications côte à côte — **TERMINÉ**

Premier lot mené avec **les deux dépôts sur la même machine**, et les deux
applications servies en même temps. Ce qui se vérifiait jusqu'ici par des
épreuves et par le rapport d'une autre session s'est vérifié pour de bon. Trois
choses tenues pour acquises se sont révélées fausses.

#### 22.1 — Le travail de Comptaflow n'était pas à faire : il était égaré

Le rapport du 27 août annonçait le point d'entrée de rotation des clés comme
**le** point bloquant. Il existe. Il est écrit, avec sa période de grâce, dans
`993ba92` — « La clé de liaison se renouvelle, et l'ancienne vit cinq minutes
de plus ».

Neuf commits vivaient sur `claude/liaison-cle-par-entreprise`, **jamais
fusionnés**, pendant que `main` avançait de treize commits de son côté —
packs, gouvernance, habilitations, exercice comptable.

**Une fusion de branche aurait écrasé ce travail.** C'est la deuxième fois que
cette passerelle manque de disparaître : la première, un `git push --force` du
12 août l'avait effacée de l'historique.

Les neuf commits ont donc été **reportés un par un** sur le `main` courant,
sans un seul conflit. Le résultat se lit au chiffre : 36 fichiers, 4 215
ajouts, **305 retraits — dont la seule suppression de fichier est
`.env.example2`**. Une fusion à l'aveugle en annonçait 119 et 6 421.

| Ce qui est arrivé dans le `main` de Comptaflow | |
|---|---|
| Réception du référentiel Selflow | le point bloquant des six éditions précédentes |
| Clé par dossier, hachée **et** copie chiffrée | le haché seul interdisait l'idempotence du provisionnement |
| Filtre `X-Company-Key` | 401 si l'on ne sait pas qui appelle, 403 si l'on écrit chez un autre |
| `provision`, `revoke`, `verify`, **`rotate-key`** | et la grâce de cinq minutes |
| L'empreinte du mot de passe | un seul compte pour les deux applications |
| `tier_digits` à 6 | deux migrations se contredisaient ; celle qui posait 6 ne s'exécutait jamais |

#### 22.2 — La suite de Comptaflow était rouge avant de commencer

Et depuis assez longtemps pour qu'on ne sache plus distinguer une régression
d'une panne installée. Quatre causes, aucune liée à la passerelle.

**Trois migrations écrivaient du SQL propre à MySQL** — `UPDATE ... JOIN` et
`ALTER TABLE ... MODIFY`. La suite tourne sur SQLite : elle s'arrêtait sur
« syntax error near "ec" », puis « near "MODIFY" », et rien ne s'exécutait
derrière. La voie MySQL est conservée pour la production ; les autres moteurs
passent par le Blueprint, qui sait changer une colonne sans doctrine/dbal
depuis Laravel 11.

**`UserFactory` était restée le modèle livré par Laravel.** Elle posait
`email`, `email_verified_at` et `remember_token`, que la table `users` n'a pas,
et taisait `last_name`, `role`, `pack`, `is_online`, `is_active` et
`is_blocked`, qui sont obligatoires. `User::factory()` échouait donc à la
première insertion — **et c'est le point de départ de presque toute épreuve à
écrire**.

**`SyscohadaTftDemoTest` avait été écrit contre un schéma antérieur** : `name`
et `email` pour une fiche qui porte `company_name` et `email_adresse`, pas de
`user_id` sur l'exercice ni sur l'écriture, `journal_id` là où la colonne
s'appelle `code_journal_id`, et 1 en « dummy » pour deux clés étrangères qui
sont tenues. Le rapport TFT qu'il vérifie rend désormais les bons montants.

**57 épreuves vertes, 200 vérifications.** La seule ignorée l'est
volontairement : elle attend la chute de la tolérance de transition.

#### 22.3 — La clé chiffrée ne tenait pas dans sa colonne

Première liaison lancée pour de vrai, les deux applications côte à côte :

```
SQLSTATE[22001] 1406 Data too long for column 'comptaflow_sync_key'
```

Comptaflow avait créé le dossier et rendu la clé. **Selflow n'a pas pu la
ranger.** La colonne est un `varchar(255)`, posée en juin quand la clé s'y
écrivait **en clair**. Le lot 15 a posé le chiffrement — cast `encrypted` —
sans toucher à la colonne. Or une clé chiffrée pèse **288 caractères** : elle
n'y est jamais entrée, et n'y serait jamais entrée.

**Pourquoi quarante-deux épreuves vertes ne disaient rien.** SQLite **ignore
la longueur déclarée d'un `varchar`** : il range la chaîne entière sans un mot.
MySQL refuse. La liaison passait au vert sur un chemin que la production ne
pouvait pas emprunter.

C'est la famille du lot 21 prise par l'autre bout : là une colonne absente,
ici une colonne trop étroite. Dans les deux cas, **la base des épreuves ne
ressemble pas à celle de la production**.

`text`, et non `varchar(1024)` : la taille d'un chiffré dépend de la clé, du
vecteur d'initialisation et de l'empreinte. On ne repose pas un plafond qu'il
faudra relever. Les trois colonnes chiffrées de `fne_credentials` étaient déjà
en `text` ; celle-ci était la seule restée en arrière.

**L'épreuve ne tente pas d'écrire** — SQLite accepterait. Elle lit **le type
déclaré** du schéma, que SQLite conserve fidèlement même s'il ne l'applique
pas. Et elle découvre les colonnes chiffrées à la lecture des modèles : une
colonne chiffrée ajoutée demain est couverte sans que personne y pense.

#### 22.4 — La liaison, éprouvée de bout en bout

| Étape | Constat |
|---|---|
| Provisionnement | Comptaflow crée le dossier, génère la clé, la rend |
| **Rejeu après l'échec** | **le même dossier est rendu, pas un second** — l'idempotence tient |
| Rangement chez Selflow | clé chiffrée en base, 50 caractères en clair après déchiffrement |
| Déversement du référentiel | 45 comptes, 10 journaux, arrivés et comptés chez Comptaflow |
| `tier_digits` | vaut bien **6** |
| Rotation | la clé change, l'indice passe de `WOVc` à `Noco` |
| Période de grâce | l'ancienne clé vit encore **289 secondes** sur les 300 annoncées |

#### 22.5 — Deux épreuves qui ne tenaient qu'à la machine

**`PhotoDeLArticleTest` ne passait que sur une machine mal installée.** Pour
éprouver le chemin sans `public/storage`, elle supprimait le dossier — mais
seulement si elle l'avait elle-même créé, précaution juste. Sur tout poste où
`php artisan storage:link` a été lancé, c'est-à-dire l'installation normale,
« retirer » ne retirait rien et l'épreuve tombait. Elle déplace désormais le
dossier public au lieu de toucher au vrai.

**Le tableau de bord général répondait 500 (Internal Server Error — erreur
interne du serveur) selon la machine.** Il appelait `CONCAT()`, que **SQLite ne
connaît qu'à partir de la 3.44**. Le nom d'un employé se compose maintenant en
PHP, comme `RapportControleur` le faisait déjà.

**Quatre autres emplois trouvés en cherchant** — `DATE_FORMAT()`, `YEAR()` et
`MONTH()`, propres à MySQL. Ceux-là marchent en production. Leur coût est
ailleurs : **aucune épreuve ne pouvait couvrir les deux rapports qui les
portent**, l'épreuve tombant avant d'avoir rien vérifié.
`ExpressionSqlPortable` ne garde que ce que la base doit calculer, parce qu'il
sert à regrouper ; le reste se compose en PHP.

- `tests/Feature/ColonnesChiffreesTest.php` — 3 épreuves
- `tests/Feature/SqlPortableTest.php` — 2 épreuves
- Chez Comptaflow : `tests/Feature/AucunSecretVersionneTest.php` — 2 épreuves

---

### Lot 23 — Un déversement est un import — **TERMINÉ**

Trois demandes du propriétaire, le 11 septembre, et une décision qui défait
celle de la veille.

#### 23.1 — L'exercice à l'ouverture, et le refus qui se taisait

Le provisionnement créait le dossier, l'administrateur et la clé, **mais aucun
exercice comptable**. Comptaflow refusait alors chaque écriture en 422
(Unprocessable Content — contenu non traitable), et côté Selflow la tâche de
déversement marquait l'écriture en échec **sans écrire nulle part pourquoi**.
Six écritures « parties », zéro arrivée. Selflow annonce désormais son
exercice ouvert, Comptaflow l'ouvre à la création comme au rejeu, et le refus
se journalise avec son motif (`d5b29c5`).

#### 23.2 — La convention du dossier appartient à Comptaflow

Le soir du 11, le dossier 101 recevait des comptes à six chiffres dans une
configuration à huit, et des journaux de deux à quatre caractères dans une
configuration à quatre. `342d776` avait réglé l'écart **en alignant le
dossier sur Selflow**.

Le propriétaire a tranché dans l'autre sens, et c'est la bonne lecture :
**un déversement est un import.** Tout ce qui entre dans Comptaflow passe par
sa machine d'uniformisation, prend la convention du dossier, et garde le
numéro d'origine rangé dessous. Selflow ne change rien chez lui.

| | Selflow envoie | Le dossier range | Dessous |
|---|---|---|---|
| Compte, dossier à 8 chiffres | `411100` | `41110000` | `411100` |
| Journal, dossier à 4 caractères | `VTE`, `OD` | `VTE1`, `OD01` — voir 24.1 | `VTE`, `OD` |
| Tiers | `410001` | un numéro **régénéré** par Comptaflow | `410001` |

Les écritures désignent toujours les comptes à la manière de Selflow : elles
retrouvent leurs lignes par ce numéro d'origine. C'est le principe de
fixation que l'écran d'importation applique déjà.

La règle vit dans `UniformisationImport`, chez Comptaflow. Elle était écrite
quatre fois dans ses classes d'import, **et les copies avaient divergé** :
l'une complétait un code journal court, l'autre non. Le déversement n'en
avait aucune. Les quatre copies n'ont pas été rebranchées : elles servent
l'écran d'importation, qui marche.

**Le défaut trouvé en éprouvant.** Chercher sur le numéro à la convention,
puis sur `numero_original`, ne retrouvait plus **les dossiers liés avant la
règle** : le déversement y avait rangé `VTE` et `411000` bruts, sans numéro
d'origine. Chaque écriture de ces dossiers serait tombée en « journal
inconnu », et chaque compte se serait créé une seconde fois. La recherche
essaie maintenant trois clés, **une à une et dans l'ordre** — la convention,
l'origine, le brut. Un seul `OR` aurait rendu la ligne que la base voulait
bien rendre.

Le provisionnement cesse d'aligner quoi que ce soit, et un rejeu ne défait
plus le réglage du comptable. Le dossier 101 reste à six chiffres : c'est
désormais son réglage, et seul le comptable le change.

#### 23.3 — Les quatre portes sont fermées

Le propriétaire a demandé pourquoi on ne faisait pas simplement ce qui est
juste. Il n'y avait pas de raison technique de plus : le journal de
Comptaflow ne montrait **aucun appel réel** passé par une tolérance, et
Comptaflow présentait déjà la clé à `company-info` et `tier-info`.

Les quatre sont tombées ensemble. Un appel sans `X-Company-Key` reçoit 401
(Unauthorized — non authentifié), des deux côtés.

**Une exception, voulue** : `list-companies`, chez Selflow, reste accessible
sans clé. Il sert à rapprocher un dossier qui n'est pas encore lié, donc qui
n'a pas de clé à présenter, et ne rend qu'un nom et un identifiant.

**L'ordre de déploiement n'est pas indifférent : Selflow d'abord, Comptaflow
ensuite.** Un Comptaflow ancien ignore l'en-tête que Selflow envoie ; un
Comptaflow à jour refuserait un Selflow qui ne l'envoie pas.

#### 23.4 — L'avoir d'un BAPA est fermé

**La DGI ne normalise pas l'avoir d'un bordereau d'achat aux producteurs
agricoles.** Selflow le proposait : la pièce se serait établie dans les
livres, avec écritures et mouvement de stock, et rien ne serait parti à la
plateforme.

**Deux chemins mènent au bordereau, et un seul se lit dans `type_facture`.**
Une facture d'achat ordinaire dont le fournisseur n'a pas de NCC part aussi
en normalisation BAPA. Fermer sur le seul type aurait laissé passer le cas le
plus courant. `Achat::estBapa()` tient les deux.

La fermeture est posée **au contrôleur**, sur les trois routes de l'avoir
d'achat, et pas seulement à l'écran : masquer un bouton ne ferme pas une
route. Les deux listes de choix écartent les bordereaux, le préfixe `BA-`
compris, et l'écran le dit en une ligne.

**Le périmètre FNE n'est pas touché.** Rien dans `FneService` ni dans les
blocs de certification : on empêche de créer une pièce, on ne change pas ce
qui part à la DGI.

#### 23.5 — Ce qui reste

| Point | Chez qui |
|---|---|
| ~~Les codes journaux s'affichent `VTE0`, `OD00`~~ — faux, corrigé au lot 24 : `VTE1`, `OD01` | — |
| ~~Comptaflow appelle `link-company` chez Selflow, route qui n'existe pas~~ | fait au lot 24 |
| Les quatre copies de la règle dans les imports de Comptaflow | à rebrancher sur `UniformisationImport` |
| ~~Les deux PDF, dont les scripts sont perdus~~ | refaits au lot 24, en HTML imprimé par Chrome |

**Selflow : 1 063 épreuves, 4 197 vérifications, toutes vertes.**

- `tests/Feature/AvoirDeBapaTest.php` — 11 épreuves
- `tests/Feature/PasserelleEntranteTest.php` — la tolérance remplacée par trois refus
- Chez Comptaflow : `DeversementReferentielTest` gagne la convention du dossier
  et les dossiers d'avant la règle ; le refus 401 n'est plus ignoré. **69
  épreuves, 242 vérifications, aucune ignorée.**

---

### Lot 24 — Laisser Comptaflow faire son import — **TERMINÉ**

Le propriétaire a relu le lot 23 et l'a corrigé sur le point qui comptait :
**Comptaflow ne complète pas `VTE` en `VTE0`, il le complète en `VTE1`**, et
`OD` en `OD01`. La normalisation du lot 23 était une copie, et une copie
fausse. « Ne change pas la logique, laisse Comptaflow faire son import. »

Quatre demandes : les deux PDF à jour, la liaison lancée depuis Comptaflow à
réparer, les lignes déjà reçues à renuméroter, et la configuration du dossier
qui suit celle de Comptaflow pour tout — comptes, codes journaux, tiers.

#### 24.1 — Le déversement passe par l'import réel

La règle de Comptaflow vit dans `AdminConfigController::importStaging()` —
deux mille lignes — et ce qu'il y a de décisif n'est pas dans la
normalisation seule :

| Étape de l'import | Ce qu'elle fait d'un code journal |
|---|---|
| `standardizeJournalCode()` | `VTE` sur quatre caractères devient `VTE1`, `OD` devient `OD01`, `BQ1` devient `BQ01` |
| Code déjà valide | gardé tel quel — `MOOV` reste `MOOV` |
| Code invalide | séquence par type, via `CodeJournalController::getNextSequentialCode()` |
| Collision | renuméroté : si le comptable a déjà `VTE1`, celui de Selflow devient `VTE2` |

Et pour un tiers : un numéro déjà conforme — six caractères, bon préfixe,
numérique — est **gardé**, sinon régénéré. Le lot 23 régénérait tout.

Aucune copie ne pouvait tenir cela. Le référentiel de Selflow est donc
**déposé comme l'écran d'import le dépose** : une ligne `ImportStaging`, la
correspondance de colonnes que l'écran ferait choisir — elle ne change jamais,
Selflow envoie toujours les mêmes champs —, puis `ImportCommitJob`, lancé de
façon synchrone. La préparation est lue une fois de plus pour rapporter ce
que l'import écarte et pourquoi : le job ne le dit pas ligne à ligne.

Pour les écritures, `UniformisationImport` ne normalise plus rien : il appelle
`standardizeAccountNumber()` et `standardizeJournalCode()`, rendues publiques.
Le journal se cherche **par le code d'origine d'abord** : chercher `VTE1` en
premier rattacherait les ventes de Selflow au journal du comptable.

La configuration : le provisionnement ne pose plus `tier_digits` ni
`tier_id_type` depuis Selflow. Un dossier neuf prend les défauts de
Comptaflow, que le comptable règle ensuite.

#### 24.2 — Les lignes déjà reçues

`php artisan selflow:aligner-sur-import`, chez Comptaflow, avec `--simuler`
et `--dossier=`. Elle fait tourner **la préparation de l'import** sur les
lignes sans numéro d'origine des dossiers liés.

Le piège : soumise telle quelle, une ligne existante est reconnue par
l'import et déclarée « déjà présente », sans rien générer. Son numéro est
donc remplacé, le temps de la préparation et dans une transaction, par
`~` suivi de l'identifiant — qu'aucune règle ne produit et qu'aucune
recherche par préfixe ne rencontre.

Sur la machine de développement, dossier 101 : `ACH`, `BQE`, `CAI`, `MTN`,
`OMY`, `RAN`, `VTE` deviennent `ACH1` … `VTE1`, `OD` devient `OD01`, `MOOV`
et `WAVE` restent, le tiers `410001` reste. Les écritures ne bougent pas.
La trésorerie, qui recopie le code du journal au lieu de le désigner, suit.

Deux comptes qui tomberaient sur le même numéro **ne sont pas fusionnés** :
des tables renvoient à un compte en cascade, et supprimer l'un peut effacer
des données. La commande le signale.

#### 24.3 — La liaison lancée depuis Comptaflow

Comptaflow appelait `POST /api/external/link-company` chez Selflow depuis ses
deux écrans de liaison. **La route n'existait pas** : 404 (Not Found —
introuvable). L'écran de création ne lisait pas la réponse et annonçait
« créé et lié avec succès ».

`ExternalSyncControleur::lierDossier()` range la clé comme la validation
d'une demande la range — écriture directe, hors `$fillable`, chiffrée — et
envoie le référentiel par `DeverserReferentielComptaflow`, **hors de la
requête** : Comptaflow attend encore la réponse, et le rappeler dans la même
requête bloque un serveur à un seul processus.

Simulation d'attaque : le secret seul autorise l'appel. Il ne doit pas
suffire à envoyer les livres d'une entreprise liée vers un autre dossier.
Une entreprise déjà liée à un **autre** dossier est refusée en 409
(Conflict — conflit) ; le même dossier peut reposer sa clé.

Chez Comptaflow, l'écran de création lit la réponse et dit quand la clé n'a
pas été rangée.

#### 24.4 — Les deux PDF

Les scripts `plan.py` et `etat.py` vivaient dans une session distante et sont
perdus, et reportlab n'est pas sur cette machine. Les deux documents sont
refaits en HTML, imprimés par Chrome sans affichage, à partir de leur texte
extrait et de ce journal. Les sources restent dans le répertoire de travail,
non versionnées : c'est le PDF qui fait foi.

#### 24.5 — Les épreuves

- Selflow : `LiaisonOuverteParComptaflowTest` — 5 épreuves ; la suite entière, 1068 épreuves et 4216 vérifications, toutes vertes.
- Comptaflow : les schémas d'épreuve gagnent les tables que l'import lit —
  `import_stagings`, `sections_analytiques`, `tresorerie`, et ce que les
  modèles filtrent en silence. Les assertions suivent la règle de l'import :
  `VTE1`, `MTN1`, un tiers conforme gardé. 69 épreuves, 239 vérifications.

---

### Lot 25 — Tout sur `main`, et les comptes qui passent en ligne — **TERMINÉ**

Deux demandes du propriétaire : fusionner sur `main` tout ce qui vivait dans
d'autres branches — dont le travail de son collaborateur —, et pouvoir poser
en ligne **le même compte superadmin, avec le même mot de passe**, et le même
compte d'entreprise avec sa configuration, sans ses données.

#### 25.1 — Les branches

| Dépôt | Branche | Contenu | Suite |
|---|---|---|---|
| Selflow | `ServiceScrapping` (Guysergekouassi) | 22 commits : le portail FNE — relevés, rejets, correction, points de vente, factures reçues | fusionnée |
| Selflow | `fix/comptabilite-imputations`, `nouveau-repo/main` | déjà contenues dans `main` | rien à faire |
| Comptaflow | `claude/app-audit-corrections-u2d5x5` | 1 commit : contrôle d'accès côté serveur (`admin`, `superadmin`, `permission`) | fusionnée, `f8a4923` |
| Comptaflow | trois autres branches `claude/*` | leurs commits sont déjà dans `main` sous d'autres empreintes | rien à faire |

**La règle d'or FNE, vérifiée avant la fusion.** La branche du collaborateur
ne touche ni `FneService.php`, ni le timbre, ni le QR, ni les codes TVA, ni
les blocs de certification, ni `FnePayloadTest`. Les jobs de normalisation
gagnent la consignation des refus (`FneRejet::consigner` / `resoudre`) et une
relance quand la plateforme est injoignable : **ce qui part à la DGI ne change
pas**. Deux migrations déjà appliquées sont retouchées, mais sur une seule
comparaison (`(string) $numero`) : sans effet sur une base déjà migrée.

Conflits Selflow : `Achat.php` garde `estBapa()` et `rejets()` ; `api.php`
garde `link-company` et `fne/load-file` ; `AdminControleur` garde la version
de `main`, identique sur le fond. Le journal de la branche est repris sous
« 5 ter » : **sa numérotation de lots lui est propre**, et son titre
« tolérance OUVERTE » était périmé depuis le lot 23.

**Ce que la branche portait de cassé, et que la fusion a réparé.** Sa propre
suite ne passait pas : douze épreuves rouges une fois fusionnée, aucune due à
la résolution des conflits.

| Constat | Correction |
|---|---|
| La route `admin.pdv.load_file_fne` visait `chargerFichierFne`, méthode qui n'existe pas : le chargement à la main d'un relevé rendait 500 (Internal Server Error — erreur interne du serveur) | elle vise `LoadFileFne`, la méthode réelle |
| **Deux routes d'API hors de toute authentification** : `/charger-fichier-fne` vers une méthode inexistante, `/external/fne/load-file` vers une méthode **vide** qui ne vérifiait aucun secret | retirées, avec la méthode vide. Remplie un jour, elle aurait écrit des données fiscales pour qui l'appelle. Le chargement reste possible par l'écran, derrière la connexion |
| Le dernier commit du collaborateur (`2e50585`) pose la règle « un point de vente existant n'est jamais renommé : le point du portail est créé, la pièce lui est rattachée ». Il avait aligné `BoutonCorrigerMaintenantTest`, pas cinq autres épreuves qui attendaient encore le renommage | alignées sur sa règle, comme il l'avait fait : l'ancien point garde son nom, la pièce part sous celui du portail |

Conflits Comptaflow : `main` avait retiré la création de sous-entreprise ; la
branche d'audit la ramenait. Le retrait est gardé. L'archive des suppressions
passe sous l'habilitation de la traçabilité.

#### 25.2 — Le superadmin qui ne passait pas en ligne

Les seeders tirent son mot de passe au hasard quand `SUPERADMIN_PASSWORD`
manque. En ligne, le compte existait, et personne n'en connaissait le secret.
Écrire le mot de passe dans un seeder l'aurait mis dans le dépôt — ce que le
lot de sécurité avait justement retiré.

Deux commandes :

| Où | Commande | Ce qu'elle fait |
|---|---|---|
| Poste local | `php artisan selflow:exporter-comptes --entreprise=<id ou NCC>` | écrit `storage/app/transfert/comptes-selflow.json` |
| Serveur | `php artisan selflow:importer-comptes <fichier> --simuler` puis sans `--simuler`, avec `--effacer` | pose les comptes, dans une transaction |

Ce qui voyage : **l'empreinte bcrypt**, jamais le mot de passe — le même mot
de passe ouvre donc le compte des deux côtés. L'identité fiscale et les
réglages de l'entreprise, son administrateur (`--equipe` pour toute l'équipe),
ses points de vente, ses taxes. Le plan et les journaux se posent par
`TrousseauEntrepriseService::doter()`, comme à toute inscription. L'adresse
FNE de test, qui vit dans l'environnement, est comparée à celle du serveur et
la commande dit quoi poser dans le `.env` si elles diffèrent.

**Le NCC n'est pas unique.** Sur le poste de développement, trois entreprises
portent le même NCC : `DC-KNOWING CGA` (n° 9), `ENTREPRISE TEST 4` (n° 8), et
`DC-KNOWING` sous `1864699 A`. Désignée par son NCC, l'export prenait la
première venue — l'entreprise d'essai. Il refuse désormais un NCC partagé et
demande l'identifiant : `--entreprise=9`. L'import, en ligne, départage par le
nom ; s'il en reste plusieurs, il s'arrête, les nomme, et `--vers=<identifiant>`
tranche. Trois épreuves de plus.

Ce qui ne voyage pas : la clé FNE — **le superadmin la tape en ligne** —, la
liaison Comptaflow, chiffrée avec l'`APP_KEY` du poste, les soldes constatés
par la DGI, les logos, les jetons de session, et toutes les données.

**Simulation d'attaque.** Le fichier est intercepté et le rôle d'un gérant
passe à `superadmin`. Une empreinte SHA-256 du contenu est vérifiée avant
toute écriture : l'import refuse, rien n'entre. Le fichier est écrit sous
`storage/app`, que git ignore ; il ne se joint à aucun courriel.

#### 25.2 bis — Ce que la première mise en ligne a montré

Le propriétaire a importé les comptes en ligne. Trois choses ne tenaient pas.

| Constat | Cause | Correction |
|---|---|---|
| Le modal des clés FNE répondait « Erreur réseau », en ligne comme en local : aucune clé ne pouvait être saisie | le bouton « Gérer » passait le **numéro de ligne** de l'entreprise, là où ses routes se lient par l'identifiant public (`IdentifiantOpaque`) : 404 (Not Found — introuvable) sur chaque appel. La page 404 n'étant pas du JSON, `res.json()` levait, et le `catch` parlait de réseau | le bouton passe `getRouteKey()`. Une réponse illisible est dite telle quelle, avec son code, et la session expirée — 419 — l'est en clair |
| **La production contenait des données de démonstration** — DC-KNOWING, B-HOME, leurs articles et leurs ventes | `deploy-production.sh` lançait `SelflowCompleteSeeder` **à chaque déploiement**. Ce jeu commence par **vider** les entreprises, les utilisateurs, les clés FNE, les ventes et les écritures, puis recrée deux entreprises fictives et un superadmin au mot de passe tiré au hasard. C'est la vraie raison du « superadmin qui ne passe pas en ligne » | la ligne est retirée du script. Le jeu refuse une base qui porte des entreprises, et toujours un serveur de production ; `selflow:seed-massif` refuse la production, `--force` compris. `deploy-seed.sh` refuse `APP_ENV=production` |
| Les scripts de déploiement **imprimaient des mots de passe en clair** : `12345678SUPER@`, `ADMIN@@@###123`, `Selflow2026@` | versionnés depuis juillet, sur un dépôt distant | retirés. Si l'un d'eux sert encore quelque part, il est à changer |

Chez Comptaflow, le tableau de bord superadmin tombait en 500 (Internal Server
Error — erreur interne du serveur) : la fusion de l'audit avait apporté un
commentaire CSS qui disait « après @vite », et Blade compile une directive
partout, commentaires compris. `ccf4f6d`.

- `GestionDesClesFneTest` — 8 épreuves.

**La suite qui ne finissait pas.** Deux passages de la suite se sont figés —
dix heures pour le premier —, toujours sur `ReleveDesQuUnePieceEstRefuseeTest`.
L'épreuve pointe exprès le scraper vers `node-qui-n-existe-pas`. Sous Windows,
`start /B` sur un programme introuvable ouvre **une fenêtre d'erreur système
qui attend un clic**, et `pclose()` attend avec elle. La suite fusionnée la
veille n'était passée que parce que quelqu'un avait fermé la fenêtre. Un
Selflow servi sous Windows sans Node aurait figé la normalisation de la même
façon. `ScraperPortailFneService::detacher()` vérifie désormais que le
programme existe avant de le lancer ; sinon il lève, `lancerPourLogin()`
journalise, et la demande reste en file.

Le déploiement charge désormais le référentiel (`ReferentielSeeder`, qui ne
supprime rien) : en ligne, « Configurer mon entreprise » s'arrêtait sur
« le catalogue des domaines d'activité n'est pas chargé ».

#### 25.2 ter — La suite du collaborateur (`suite-scrapping`)

Guysergekouassi avait continué après le 01/09 sans pousser. Sa première
tentative a échoué en 403 (Forbidden — accès interdit) : son `origin` visait
IT-dcknowing/Selflow, où il n'écrit pas. **Le dépôt de base est
meledjeabrahamagnimel-lgtm/Selflow** ; IT-dcknowing/Selflow est un miroir,
alimenté à chaque poussée.

Deux commits (08/09 et 15/09), 28 fichiers : les factures reçues du portail
entrent dans l'écran des factures d'achat et portent un site, leur document
DGI s'affiche, le relevé des achats passe toutes les cinq minutes, un canal de
journal `portail_fne` distinct, deux routes d'API sous `hub.token`.

| Vérifié | Constat |
|---|---|
| Règle d'or FNE | aucun fichier gelé ; les deux migrations ne touchent que `portail_fne_factures_recues` (`point_de_vente_id`, `fichier_pdf`) et vérifient ce qu'elles posent |
| Avoir de BAPA | toujours fermé à ses trois points dans le résultat fusionné |
| Routes d'API | `POST /api/portail-fne/achats/relever` et `GET /api/portail-fne/achats` sous `hub.token`, qui refuse en 401 (Unauthorized — non authentifié) sans jeton **et** sans jeton configuré |
| Conflit | `ScraperPortailFneService::detacher()` : la vérification du programme (15/09) et la fermeture du journal avant l'enfant (08/09) se complètent — gardées toutes deux |

Six de ses épreuves attendaient qu'un lancement vers un Node introuvable
« réussisse » (202, verrou posé) : elles ne passaient que parce que `start /B`
ne signale pas l'échec, au prix de la fenêtre qui figeait la suite. Elles
lancent désormais `php`, qui existe partout où la suite tourne et s'arrête
aussitôt sur un script absent.

#### 25.3 — Les épreuves

- `TransfertDesComptesTest` — 8 épreuves : même mot de passe en ligne, rien
  de ce qui ne doit pas voyager, relance sans doublon, mot de passe réaligné,
  simulation, fichier retouché refusé, export sans entreprise, effacement.
- Selflow, suite entière après fusion : **1221 épreuves, 4836
  vérifications**, toutes vertes.
- Comptaflow après fusion : 69 épreuves, 239 vérifications.

---

## 5 ter. Le chantier du portail FNE — branche `ServiceScrapping`

Mené en parallèle par Guysergekouassi, du 24/08 au 01/09/2026, et fusionné
dans `main` le 14/09/2026. **Sa numérotation de lots lui est propre** : son
« lot 22 » n'est pas le lot 22 ci-dessus. Les lots sont repris tels qu'il les
a écrits. Ce chantier n'a touché ni `FneService.php` ni le payload : il
consigne les refus, relève le portail et corrige les points de vente.

### Lot 9 — Les relevés du portail FNE — **TERMINÉ (import), RAPPROCHEMENT EN ATTENTE**

Le portail de la DGI porte, par entreprise, des informations qu'aucune API ne
rend : l'adresse déclarée, la commune, le quartier, le solde d'alerte des
stickers, l'activation du timbre de quittance et du bordereau d'achat agricole,
et la liste des points de facturation ouverts. Elles se relèvent à l'écran. Le
relevé arrive sous forme de **deux fichiers par entreprise**, nommés
`<login>_<date>.json` (la fiche) et `<login>_<date>.xlsx` (les points de
facturation). Le login est le NCC.

| Élément | Détail |
|---|---|
| Migration | `2026_08_23_000001_portail_fne_scraping` — trois tables |
| `portail_fne_imports` | un fichier lu, une ligne : nom, date, empreinte SHA-256, contenu brut, statut |
| `portail_fne_fiches` | le JSON interprété, quatorze champs, plus un fourre-tout `champs_inconnus` |
| `portail_fne_points_facturation` | une ligne par point : nom, outil, terminal, statut, établissement, dates DGI |
| Service | `ImportPortailFneService` — `importerDossier()`, `importerFichier()` |
| Commande | `php artisan portail-fne:importer [--dossier=] [--fichier=]` |
| Dossier | `config('selflow.portail_fne.dossier_import')`, variable `PORTAIL_FNE_DOSSIER_IMPORT` |
| Tests | `tests/Feature/ImportPortailFneTest.php` — 7 tests |

**Ce que l'import ne fait pas, et c'est le point important.** Il n'écrit rien
dans `entreprises`. Trois des champs relevés — `timbre_quittance`, `bapa`,
`sticker_solde_alerte` — commandent le comportement fiscal de l'application :
les recopier automatiquement ferait changer une facture parce qu'un fichier a
été déposé dans un dossier, sans que personne ne l'ait décidé, et sur la foi
d'un relevé dont rien ne garantit la fraîcheur.
`PortailFneFiche::ecartsAvecEntreprise()` montre les différences ; **les
appliquer reste à faire, et doit se voir avant de s'appliquer.**

Trois décisions de lecture, prises contre des façons de faire plus simples :

- **le nom du fichier fait foi pour le rattachement.** Un fichier hors
  nomenclature est refusé et signalé plutôt que rattaché au hasard : ranger le
  relevé fiscal d'un client chez un autre ne se répare pas ;
- **l'empreinte du contenu tient lieu de marque de traitement**, pas le
  déplacement du fichier. Le dossier d'origine reste intact et la commande se
  relance sans précaution ;
- **la lecture du tableur suit les en-têtes, jamais les positions.** Le portail
  peut réordonner ses colonnes ; un import qui compte les colonnes rangerait
  alors un statut dans un identifiant d'établissement.

**Le ramassage est planifié**, dans `routes/console.php` :
`portail-fne:importer` toutes les heures, sans chevauchement, sortie dans
`storage/logs/portail-fne.log`. Toutes les heures et non toutes les minutes :
un relevé se produit au mieux une fois par jour. Le passage est sans effet
quand rien n'a changé, l'empreinte reconnaissant un fichier déjà lu — il n'y a
donc rien à préparer avant le premier passage, et rien à déplacer après.

Le scraper, lui, reste extérieur à Selflow : l'application ne va chercher les
relevés nulle part, elle lit un dossier. Ce qui dépose les fichiers dans ce
dossier — un script lancé à la main, une tâche planifiée, un dossier
synchronisé — ne la regarde pas.

Le premier relevé réel (`1864699A`, DC-KNOWING CGA, 21/08/2026) est en base. Le
rapprochement montre déjà sept écarts, dont le timbre de quittance et le
bordereau d'achat actifs au portail et inactifs dans Selflow, et un solde
d'alerte de 5 000 stickers contre 5. **À arbitrer avec le propriétaire** : c'est
Selflow qui a tort, ou c'est le portail qui porte une valeur par défaut.

---

### Lot 10 — Du rejet de la DGI au relèvement du portail — **POSÉ, MIGRATION À APPLIQUER**

Le lot 9 rangeait des relevés que rien ne consultait. Celui-ci relie les deux
bouts : une pièce refusée par la plateforme déclenche un relèvement, et le
relevé qui arrive sert à expliquer le refus.

**Le point de départ était un trou.** `FneService::messageRejet()` assemblait
déjà un message précis — champ fautif, valeur envoyée, raison de la DGI — et ce
message finissait dans un `Log::warning` (`NormaliserFactureFne.php:107`). Un
rejet survenu la nuit ne laissait, au matin, qu'une ligne dans un fichier que
personne ne relit. **On ne diagnostique pas ce qu'on n'a pas gardé.**

| Élément | Détail |
|---|---|
| Migration | `2026_08_24_000001_fne_rejets_et_demandes_releve` — deux tables |
| `fne_rejets` | une pièce refusée : pièce, login, message, champs mis en cause, statut, diagnostic |
| `portail_fne_demandes` | la file des relevés attendus : login, motif, statut, import qui l'a servie |
| Service | `DiagnosticFneService::diagnostiquer()` — lit, compare, n'écrit rien |
| Commandes | `portail-fne:demandes [--json]`, `fne:diagnostiquer-rejets [--rejet=] [--tous]` |
| Planification | `fne:diagnostiquer-rejets` à la 10e minute, juste après le ramassage |
| Tests | `tests/Feature/DiagnosticRejetFneTest.php` — 10 tests |

**Ce que le rapprochement produit.** À la place de la piste générique écrite en
dur dans `pisteDeCorrection()` :

> « Le nom du point de vente doit être déclaré à l'identique sur votre espace FNE. »

il rend un constat :

> « Vous avez envoyé « FACTURATION SIEGE ». Le portail, relevé le 21/08/2026,
> déclare « FACTURATION SIÈGE ». Le plus proche est « FACTURATION SIÈGE ». »

**Rien ne se corrige tout seul, et c'est le point du lot.** Le service lit et
compare ; il n'écrit ni dans la pièce, ni dans l'entreprise, ni dans le
paramétrage. Trois raisons, dont la première est bloquante :

1. la règle d'or l'interdit — `timbre_quittance`, `bapa` et
   `sticker_solde_alerte` commandent le comportement fiscal ;
2. **on ne sait pas qui a raison.** Les sept écarts de DC-KNOWING le montrent :
   un solde d'alerte à 5 000 au portail contre 5 dans Selflow, où 5 000
   ressemble fort à une valeur par défaut jamais touchée ;
3. **une facture certifiée à tort est pire qu'un rejet.** Un rejet se voit et se
   répare ; une correction automatique qui « fait passer » la pièce produit un
   document opposable, transmis à la DGI, différent de ce que l'entreprise croit
   avoir émis. C'est exactement ce que les six écarts de la règle d'or décrivent.

Quatre décisions de conception, prises contre des façons de faire plus simples :

- **`FneService` n'est pas touché.** Les champs rejetés se relisent dans le
  corps brut de la réponse, que le service conserve déjà sous `errors.api_error`
  — plutôt que de découper le message français, qui est fait pour être lu. Les
  quatre points de rejet (`NormaliserFactureFne`, `NormaliserAchatBapaJob`,
  `BatchNormalisationJob`, `FneDashboardControleur`) reçoivent une ligne chacun ;
- **la demande de relevé s'ouvre dans `FneRejet::consigner()`**, pas chez
  l'appelant. Confier cette ouverture aux quatre endroits qui normalisent une
  pièce, c'est s'assurer qu'un cinquième l'oubliera ;
- **une demande n'est fermée que par l'arrivée d'un fichier**, jamais par la
  parole du scraper. Un scraper qui échoue en silence laisse sa demande ouverte,
  et c'est le seul endroit où l'on verra qu'il ne fonctionne plus ;
- **un champ que le portail n'affiche pas est dit hors de portée**, pas passé
  sous silence : `clientNcc` met en cause le NCC du *client*, absent du portail
  de l'entreprise. Un diagnostic muet se lit comme un diagnostic favorable.

**Le contrat avec le scraper tient en une commande.**
`php artisan portail-fne:demandes --json` rend la liste des logins attendus, et
rien d'autre :

    ["1864699A", "2201455B"]

Selflow ne sait pas comment le portail est consulté — script lancé à la main,
tâche planifiée, navigateur piloté — et n'a pas à le savoir. Il dit ce qu'il
attend ; le scraper vient le lui demander et dépose ses fichiers dans le
dossier d'import. Le scraper lui-même reste à écrire, et arrive prochainement.

**Migration appliquée** le 24/08/2026 : `fne_rejets` et `portail_fne_demandes`
sont en base réelle, et les deux commandes répondent.

#### L'environnement du scraper — posé le 24/08/2026

- **`SCRAPER-PORTAIL-FNE/`** accueillera le script, sur le modèle de
  `PASSERELLE-COMPTAFLOW/`. Il porte déjà `CONSIGNES-POUR-LE-SCRAPER.md` : la
  file à lire, la nomenclature des fichiers, les quatorze clés du JSON avec un
  exemple réel, les en-têtes du tableur, et ce que le scraper n'a **pas** à
  faire — ne rien déplacer, ne fermer aucune demande, ne toucher à aucune table.
  Une exception `.gitignore` a été ajoutée : la règle `*.md` masquait le fichier,
  comme elle avait masqué `CLAUDE.md` en son temps.
- **Le dossier d'import a déménagé** de `Pictures/k` vers
  `storage/app/portail-fne/`, l'endroit que `config/selflow.php` désignait déjà
  par défaut. Les deux relevés réels de DC-KNOWING l'ont suivi ; l'import les a
  reconnus à leur empreinte et n'a rien redoublé — la preuve, au passage, que le
  mécanisme tient même quand les fichiers changent de place. `.env` pointe
  désormais dessus.
- **`deploy-production.sh` dit enfin les deux choses qui manquaient** : créer
  `storage/app/portail-fne` (son contenu n'est pas versionné, donc le dossier
  n'arrive pas avec le dépôt), et poser la ligne cron du planificateur. Sans
  elle, ni la reprise des écritures Comptaflow, ni le ramassage des relevés, ni
  le rapprochement des rejets ne tournent — et rien ne le signale.

**Reste à recevoir :** le script du scraper lui-même. Il n'a qu'à lire
`portail-fne:demandes --json` et déposer ses fichiers ; tout le reste est
branché. — **Reçu le 26/08/2026, voir le lot 12.**

### Lot 11 — L'écran des pièces refusées — **TERMINÉ**

Le rapprochement du lot 10 écrivait son constat chaque heure. **Personne ne
pouvait le lire.** Une facture refusée la nuit, un diagnostic posé à 1 h 10, et
rien à l'écran : tout le mécanisme était aveugle.

| Élément | Détail |
|---|---|
| Contrôleur | `RejetFneControleur` — `index`, `diagnostiquer`, `appliquer`, `resoudre` |
| Vue | `Vues/fne/rejets.blade.php` |
| Routes | `admin.fne.rejets*`, dans le groupe `modules:comptabilite` |
| Barre latérale | « Pièces refusées », avec le compte des rejets ouverts en pastille |
| Tests | `tests/Feature/EcranRejetsFneTest.php` — 8 tests |

**Le rejet se referme enfin tout seul.** `STATUT_RESOLU` était déclaré et rien ne
le posait : une facture qui repartait et passait laissait son refus ouvert pour
toujours. `FneRejet::resoudre()` est appelée aux six points de succès. Une file
qui ne se vide jamais cesse d'être lue, et c'est ainsi qu'on rate le rejet
suivant.

**Un seul geste de correction est offert, et il est descriptif.** Renommer un
point de vente pour l'aligner sur ce que le portail déclare — le même geste que
depuis l'écran des points de vente, à ceci près que la valeur du portail est
affichée en face. Il ne s'offre **que** si le portail ne déclare qu'un seul nom :
avec deux, la machine ne choisit pas à la place de qui a établi la pièce.

Appliquer la correction **rouvre le rejet et efface son diagnostic** : celui-ci
décrivait un écart qui n'existe plus, et un constat périmé affiché comme actuel
est pire que pas de constat.

**Les écarts de fiche restent montrés et non appliqués.** Ils ont leur tableau en
bas d'écran, avec la raison écrite en clair et un lien vers les paramètres de
l'entreprise. Aucune route ne permet de les recopier ; une épreuve le vérifie.

Deux prises au vol par des épreuves déjà en place, et c'est ce qu'elles valent :

- **`HabilitationsTest`** a refusé les quatre routes neuves, non classées. En les
  rangeant, un choix s'est imposé : `appliquer` va sous **`gestion_pdv`** et non
  `factures_vente` comme ses trois voisines. La ranger avec les factures
  ouvrirait à qui saisit des ventes une porte latérale vers le renommage des
  points de vente de l'entreprise.
- **`FIELD()` n'existe que chez MySQL.** L'ordre d'affichage — ouverts d'abord,
  classés en dernier — passe par un `CASE`. C'est le piège qui fait déjà tomber
  `TableauDeBordGeneralTest` avec `CONCAT` : une requète qui ne passe pas sur
  SQLite n'est jamais éprouvée.

Le cloisonnement répond **404 et non 403**, comme le lot 8.3 l'a établi : un 403
confirmerait que la pièce existe, et les identifiants sont séquentiels. Vérifié
par mutation — la garde retirée, l'épreuve tombe.

**Ce que les épreuves ne couvrent pas, et il faut le savoir :** `resoudre()` est
éprouvée comme méthode, pas comme branchement. Les six appels aux points de
succès ont été posés à la lecture ; les éprouver demanderait de simuler la
plateforme à chacun d'eux.

#### Deux fins de course — 24/08/2026

**Un diagnostic ne vieillit plus en silence.** Un rejet passé à `diagnostique`
n'était plus jamais repris : si le relevé du jour était périmé, le constat
restait périmé avec lui, et l'écran affichait comme actuel un rapprochement fait
sur des données mortes. Le diagnostic porte désormais **l'identité du relevé**
(`releve.fiche_id`) et non sa seule date — deux relevés du même jour existent, et
une date formatée se compare mal. La commande horaire reprend les rejets
diagnostiqués et écarte ceux qui décrivent déjà le dernier état connu, pour ne
pas réécrire la même chose toutes les heures. `--tous` force la réécriture.

Un diagnostic sans identité de relevé — ceux écrits avant que le champ n'existe —
est tenu pour dépassé et rejoué une fois.

**Une demande qui traîne se voit.** Une demande ouverte est un signal voulu :
c'est ainsi qu'on voit qu'un scraper ne répond plus. Encore fallait-il que
quelqu'un le voie — sans l'âge, une demande de mars ressemble à une demande de
ce matin, et la seule façon de s'en apercevoir était de remarquer un chiffre qui
ne bouge pas, ce qui suppose de l'avoir remarqué la veille.

| Où | Quoi |
|---|---|
| `config('selflow.portail_fne.delai_alerte_heures')` | 24 h par défaut — un relevé se produit au mieux une fois par jour |
| Écran des rejets | bandeau rouge, login par login, avec l'âge et les trois causes possibles |
| `portail-fne:demandes` | colonne « Attend depuis » et avertissement |
| `fne:diagnostiquer-rejets` | avertissement à l'écran **et** `Log::warning` dans `portail-fne.log` |

Les trois causes sont nommées à l'écran parce qu'aucune ne se corrige toute
seule : le relevé n'est pas lancé, il dépose ailleurs que dans le dossier
d'import, ou le NCC de l'entreprise ne correspond pas au login du portail.

**`STATUT_ABANDONNEE` a enfin un sens.** Une demande est ouverte par un rejet ;
quand tous les rejets de ce login sont refermés — les pièces sont passées — le
relevé n'a plus d'objet. La laisser ouverte ferait passer pour une panne du
scraper ce qui n'est qu'une demande devenue sans cause. L'abandon est
conditionnel : dix rejets ne partagent qu'une demande, et en refermer un seul ne
l'éteint pas.

Le signalement passe aussi quand il n'y a **aucun** rejet à rapprocher : une
demande peut traîner seule, le rejet qui l'avait ouverte ayant été classé
entre-temps. C'est même le cas où personne ne regarde.

Trois épreuves de plus, vérifiées par mutation : l'ancien comportement rétabli,
le rafraîchissement tombe.

### Lot 12 — Le scraper du portail — **TERMINÉ, ÉPROUVÉ SUR LE PORTAIL LE 27/08/2026**

Le script qui manquait depuis le lot 10 est arrivé. Il vient d'un script
d'automatisation écrit à part (`Documents/scraping/fne.js`), qui faisait déjà
l'essentiel : Playwright, connexion, page Paramétrage, export Excel, et la
bonne nomenclature `<login>_<AAAAMMJJ>`. Il vit désormais dans
`SCRAPER-PORTAIL-FNE/`, branché sur la file et sur le dossier d'import.

| Périmètre | Fichier |
|---|---|
| Le scraper | `SCRAPER-PORTAIL-FNE/fne.js` |
| Vérification hors portail | `SCRAPER-PORTAIL-FNE/verifier-extraction.js` — 18 contrôles |
| Accès aux portails | `SCRAPER-PORTAIL-FNE/identifiants.json` — **ignoré par git** |
| Configuration | `SCRAPER-PORTAIL-FNE/.env`, modèle dans `.env.exemple` |
| Mode d'emploi | `CONSIGNES-POUR-LE-SCRAPER.md`, section « Le scraper livré » |

**Six écarts corrigés entre le script d'origine et le contrat.**

- **Il ne lisait pas la file.** Il prenait un login en argument. Il appelle
  maintenant `php artisan portail-fne:demandes --json` depuis la racine du
  projet, et boucle. Une file vide n'ouvre même pas le navigateur.
- **Il déposait par HTTP vers `URL_SERVER`, qui n'existe pas.** Selflow n'a
  aucune route d'upload : il lit un dossier. Le dépôt local est devenu le mode
  normal, `PORTAIL_FNE_DOSSIER_IMPORT` étant lu dans le `.env` du projet —
  rien à recopier. L'envoi HTTP reste possible pour le jour où le scraper
  tournera ailleurs que sur le poste de Selflow.
- **La clé d'API partait dans le fichier.** Son en-tête promettait qu'elle
  n'était « jamais consignée », mais le filtre n'écartait que les champs de
  type `password` — or le portail la rend dans un champ texte ordinaire. Le
  fichier déposé est lu, archivé en `contenu_brut` et conservé : ce qui y entre
  y reste. Elle est désormais écartée par son libellé.
- **Les interrupteurs et les listes déroulantes n'étaient pas lus** :
  l'extraction ne regardait que `input` et `textarea`. « Timbre de quittance »
  et « Bordereau d'achat de produits agricoles » commandent le comportement
  fiscal ; les manquer revenait à relever une fiche muette sur l'essentiel.
  Piège rencontré à la vérification : un `<button role="switch">` porte
  `type="submit"` par défaut et se faisait écarter comme un bouton ordinaire.
  **Le rôle prime maintenant sur le type.**
- **Les libellés tombent sur les clés du référentiel.** Le portail écrit ce
  qu'il veut ; la comparaison se fait sans accents, sans casse et sans
  ponctuation, puis la clé canonique est réécrite au caractère près. Vérifié :
  les quatorze clés produites correspondent exactement, ordre compris, à
  `ImportPortailFneService::CHAMPS_FICHE`. Un libellé inconnu n'est pas perdu —
  il part tel quel et Selflow le range dans `champs_inconnus`.
- **Un échec ne se lit plus comme un succès.** En dessous de quatre champs
  reconnus, rien n'est déposé et la demande reste ouverte : sans ce garde-fou,
  une page changée aurait produit une fiche de quatorze `null`, servi la
  demande, et affiché quatorze écarts au rapprochement. Un login qui échoue
  n'arrête pas les autres et laisse une capture dans `erreurs/`. Une file
  illisible remonte sa cause — « base injoignable » — au lieu de passer pour
  une file vide.

**Un contexte de navigateur neuf par login.** Deux entreprises ne partagent
jamais une session : ranger le relevé de l'une chez l'autre ne se répare pas.

**Ce que le scraper ne fait toujours pas**, et c'est le contrat : il ne ferme
aucune demande, ne déplace rien, ne touche à aucune table. Une demande n'est
servie que lorsque `portail-fne:importer` range réellement un fichier portant
ce login.

**Les mots de passe vivent dans `identifiants.json`, côté scraper.** Selflow ne
transmet que des logins — c'est écrit dans les consignes et ce n'est pas une
commodité : mettre des mots de passe de portail en clair dans la base, ou les
faire sortir d'une commande artisan, aurait étendu la surface d'un cran pour
rien. Le fichier est ignoré par git, comme le `.env` du scraper.

#### Le lancement automatique — posé le 26/08/2026

Accroché au planificateur déjà en place (`routes/console.php`), et non à une
seconde tâche Windows que personne ne penserait à surveiller. Tout finit dans
`storage/logs/portail-fne.log`, avec le ramassage et le rapprochement.

| Quand | Quoi |
|---|---|
| Toutes les heures, minute 40 | `node fne.js` — sert la file |
| 02:30 chaque nuit | `node fne.js --tous` — tient les fiches à jour |

Le cycle : un rejet à 15:05 ouvre une demande, le scraper la sert à 15:40,
`portail-fne:importer` range à 16:00, `fne:diagnostiquer-rejets` rapproche à
16:10.

- **Toutes les heures et non toutes les dix minutes.** File vide — le cas
  ordinaire — le passage s'arrête sans ouvrir le navigateur et ne coûte rien.
  File pleine, il ouvre une session sur le portail de la DGI : y retourner six
  fois par heure avec un mot de passe éventuellement faux ferait bloquer le
  compte.
- **`runInBackground`**, sans quoi la minute du planificateur reste occupée
  pendant les dizaines de secondes d'un relevé. Verrou expirant à 30 min (2 h la
  nuit) : un navigateur planté ne doit pas condamner tous les passages suivants.
- **Allumé sur ce poste** (`PORTAIL_FNE_SCRAPER_ACTIF=true`), après vérification
  que le drapeau était trop prudent : sans `identifiants.json`, les deux
  passages sortent en code 0 sur une seule ligne — « rien à relever » — et non
  en erreur. Le journal ne se remplit donc pas pour rien, et le mot de passe
  manquant n'est signalé qu'au moment où une demande le réclame vraiment. Le
  drapeau reste à `false` dans la configuration par défaut, pour un poste où le
  scraper n'existe pas.
- **Deux chemins absolus, en barres obliques.** Le lanceur VBS de la tâche
  Windows portait déjà le chemin absolu de PHP : son environnement n'a pas le
  PATH d'un terminal. `PORTAIL_FNE_NODE` et `PHP_BINAIRE` suivent la même
  logique — `node` ou `php` tout court marche à l'essai et échoue une fois
  planifié, le pire des deux mondes. Barres obliques parce que phpdotenv
  interprète les échappements entre guillemets : `"C:\Program Files\nodejs\…"`
  y perdrait son `n`.

**Vérifié de bout en bout**, et pas seulement listé :

- avec un environnement entièrement vide (`env -i`), le scraper trouve PHP, lit
  la file et rend « rien à relever » — c'est exactement la commande que le
  planificateur exécute ;
- `schedule:list` montre les deux lignes quand le drapeau est levé, rien quand
  il ne l'est pas ;
- `schedule:test` a lancé la tâche **par le planificateur**, en arrière-plan, et
  sa sortie est arrivée dans `storage/logs/portail-fne.log`, à la suite du
  ramassage et du rapprochement. La chaîne entière tourne.

**Il n'est pas figé sur une entreprise.** `portail-fne:demandes` n'est filtré par
aucune entreprise : le NCC de n'importe laquelle entre dans la file dès qu'une
de ses pièces est refusée. Éprouvé sur quatre logins d'un coup — chacun avec son
contexte de navigateur, son échec propre et sa capture nommée à son login,
aucun n'ayant bloqué les autres.

**Une entreprise sans NCC ne peut pas être relevée — tranché, sans suite.**
`FneRejet::consigner()` pose `login = entreprise->ncc`, et
`PortailFneDemande::pour()` rend `null` sur un login vide : un rejet d'une
entreprise sans NCC n'ouvre aucune demande, et rien ne le signale. Six des douze
entreprises en base sont dans ce cas. **Le propriétaire a répondu le 26/08/2026 :
toutes les entreprises actuellement en base sont des jeux d'essai, supprimés
après le développement.** Le trou est donc sans objet sur les données réelles —
en production, une entreprise sans NCC ne peut de toute façon rien normaliser.
Aucune correction n'est faite. Si le cas devait se présenter un jour, deux
issues : signaler à l'écran des rejets, ou refuser la normalisation en amont
avec `CAUSE_LOCALE`.

**Conséquence pour `identifiants.json` :** il ne porte que `1864699A`, le seul
compte dont un relevé réel existe. Y inscrire les NCC d'essai aurait fait ouvrir
chaque nuit des sessions vouées à échouer sur des comptes qui n'existent pas au
portail — et disparaîtront de la base.

**Ce qui reste ouvert :** le scraper reste extérieur à Selflow. Ces deux lignes
de planification sont une commodité, pas une dépendance — les débrancher ne
casse rien, les fichiers arriveront autrement ou n'arriveront pas, et une
demande qui traîne le dira.

**Reste à faire :** un premier passage réel sur le portail. Playwright et
Chromium sont installés, `verifier-extraction.js` passe, les clés correspondent,
la file répond et la commande planifiée fonctionne à vide. Ce qui n'est pas
prouvé, c'est la navigation dans le portail lui-même depuis ce dossier — il y
faut `identifiants.json` et le mot de passe de `1864699A`.

### Lot 13 — Distinguer un refus de la DGI d'une coupure réseau — **POSÉ, MIGRATION À APPLIQUER**

Le propriétaire a décrit le parcours attendu : on lance la normalisation ; si
elle passe, la facture est normalisée ; sinon le scraper va vérifier au portail
— **mais avant cela, on regarde si ce n'est pas simplement un souci réseau.**
Cette dernière étape n'existait pas.

`FneRejet::consigner()` traitait **tout** `success: false` comme un refus de la
DGI et ouvrait une demande de relevé. Or `FneService` rend `success: false`
pour cinq causes sans rapport :

| Message rendu | La DGI a-t-elle examiné la pièce ? |
|---|---|
| `Exception lors de l'appel API FNE : …` (`FneService.php:276`, BAPA `:414`) | non — transport |
| `… a échoué (HTTP 5xx) : …` | non — panne de leur côté |
| `… la réponse de l'API est incomplète` (`:257`) | non — répondu sans verdict |
| `aucune clé API FNE active`, `Normalisation refusée`, `Impossible de normaliser` (`:50, :79, :630`) | non — rien n'est parti |
| `… a échoué (HTTP 4xx) : …` avec `errors` | **oui** |

Une coupure de trente secondes ouvrait donc une demande : le scraper partait
sur le portail, relevait quatorze champs, et le rapprochement comparait ce
qu'aucune DGI n'avait mis en cause. Une file d'alertes sans objet cesse d'être
lue — et c'est ainsi qu'on rate le vrai rejet.

**Second défaut, dans le même endroit.** Les jobs déclarent `tries = 3` et
`backoff = 30`, mais `FneService` attrape lui-même l'exception réseau et rend
`success: false` au lieu de la relancer. Le job n'y voyait qu'un refus métier,
ne relançait pas, et **la mécanique de réessai ne servait jamais** — précisément
dans le seul cas où elle aurait servi.

| Périmètre | Fichier |
|---|---|
| Classification | `FneRejet::classer()`, constantes `CAUSE_DGI` / `CAUSE_RESEAU` / `CAUSE_LOCALE` |
| Colonne | `2026_08_26_000001_cause_des_rejets_fne` — `fne_rejets.cause`, indexée |
| Réessai | `NormaliserFactureFne`, `NormaliserAchatBapaJob` |
| Tests | `tests/Unit/FneRejetCauseTest.php` — 10 tests, **sans base de données** |

**`FneService` n'est pas touché** — la règle d'or tient. La classification lit
ce que le service rend, elle ne change pas ce qu'il envoie. Le prix de ce choix
est qu'elle s'appuie sur le texte des messages : le test fige donc les six
formulations réelles, copiées depuis le service. Si l'une change, le test tombe
au lieu que la classification retombe en silence sur « la DGI a refusé ».

**En cas de doute, c'est `CAUSE_DGI`.** Un relevé de trop fait travailler le
scraper pour rien ; un relevé manquant laisse une facture refusée sans
explication, et c'est le plus cher des deux.

**Le réessai ne consigne rien tant qu'il reste des tentatives.** Trois rejets
pour une seule coupure rempliraient l'écran de trois refus qui n'en font qu'un ;
seule la dernière tentative laisse une trace, avec `cause = 'reseau'` et sans
demande de relevé.

**Les lignes déjà en base restent à `cause = NULL`.** Elles ont été consignées
avant que la distinction existe ; leur prêter une cause après coup serait
inventer un constat.

#### Le filtre par cause, à l'écran — ajouté le 26/08/2026

Un rejet réseau et un refus de la DGI se ressemblaient à l'écran : même
étiquette « À traiter », même bouton « Rapprocher ». On cherchait un écart de
paramétrage là où il n'y avait eu qu'une connexion perdue.

| Ce qui change | Où |
|---|---|
| Quatre onglets — Toutes, Refus DGI, Réseau, Bloqué ici, Cause inconnue | `RejetFneControleur::index()` |
| Une pastille de cause sur chaque rejet, avec son explication en infobulle | `Vues/fne/rejets.blade.php` |
| Un bandeau qui dit quoi faire, par cause | idem |
| Tests | `tests/Feature/FiltreCauseRejetsFneTest.php` — 9 tests |

Cinq choix qui viennent d'un défaut réel, et non d'un goût :

- **une cause inventée dans l'URL ne filtre rien**, au lieu de rendre une liste
  vide. Un écran vide se lit « aucun rejet », soit exactement le contraire de
  ce qu'il faut comprendre ;
- **le vide filtré et le vide réel ne disent pas la même chose.** « Aucun rejet
  pour cette cause — 12 au total » n'est pas « aucune pièce refusée » ;
- **la pagination emporte le filtre** (`withQueryString`), sans quoi la page 2
  revient sur la liste entière et l'on croit que le filtre a lâché ;
- **les rejets sans cause restent atteignables** par un onglet dédié. Les
  lignes d'avant la migration ont `cause = NULL` ; sans entrée qui les désigne,
  elles disparaissaient dès qu'on filtrait ;
- **le bouton « Rapprocher » est retiré des rejets réseau**, et la route les
  refuse aussi — un navigateur poste ce qu'il veut. Rapprocher une pièce que la
  DGI n'a jamais lue déguiserait un incident de transport en écart de données.

**Un mensonge d'interface corrigé au passage.** Quand aucun relevé n'était
disponible, l'écran répondait « Une demande a été déposée ; le rapprochement se
fera dès son arrivée ». **Rien ne la déposait sur ce chemin** : la demande naît
à la consignation du rejet, dans `FneRejet::consigner()`, et elle existe déjà ou
n'existera pas. Vrai par accident pour un refus DGI, faux pour tout le reste.
Une interface qui annonce un geste qu'elle n'a pas fait se paie au moment où
l'on attend le résultat.

**Épreuves :** 9 tests neufs, et les 115 tests du périmètre FNE — `FnePayloadTest`
compris — repassent. Sur le lot complet : 757 tests verts sur 758. L'échec
restant, `TableauDeBordGeneralTest`, est **antérieur et sans rapport** :
`AdminControleur.php:266` emploie `CONCAT`, que SQLite ne connaît pas, alors que
les épreuves tournent sur SQLite en mémoire. À corriger un jour, dans un autre
lot. Le lot complet demande aussi `-d memory_limit=512M` : à 128 Mo,
`ImportPortailFneTest` épuise la mémoire en écrivant son tableur.

**Migration appliquée** le 26/08/2026 : `fne_rejets.cause` est en base réelle.

Deux points laissés en l'état
et à trancher : `BatchNormalisationJob` consigne toujours sans classer (un
`throw` avorterait le lot entier), et le chemin synchrone de
`FneDashboardControleur` rend déjà l'erreur à l'écran. Ni l'un ni l'autre
n'ouvre de fausse demande — `consigner()` classe désormais dans tous les cas —
mais ni l'un ni l'autre ne réessaie.

### Lot 14 — Surveiller le portail, et non plus seulement le lire — **TERMINÉ**

Deux comparaisons existaient. `PortailFneFiche::ecartsAvecEntreprise()` répond à
« le portail et Selflow disent-ils la même chose ? ».
`fne:diagnostiquer-rejets` répond à « pourquoi cette pièce a-t-elle été
refusée ? ». **Aucune ne répondait à « quelqu'un a-t-il touché au portail depuis
hier ? »** — un timbre de quittance désactivé un mardi soir n'apparaissait donc
nulle part, jusqu'au jour où une facture était refusée, et l'on cherchait alors
ce qui avait bougé sans savoir quand.

| Périmètre | Fichier |
|---|---|
| Écarts d'un relevé au précédent | `PortailFneFiche::precedente()`, `::ecartsAvecPrecedente()`, `CHAMPS_SUIVIS` |
| Points apparus, disparus, modifiés | `PortailFnePointFacturation::changementsDepuisLePrecedent()` |
| Commande | `portail-fne:changements [--login=] [--silencieux]` |
| Planification | toutes les heures, minute 15, `--silencieux` |
| Tests | `tests/Feature/ChangementsPortailFneTest.php` — 11 tests |

**La comparaison porte sur le contenu, jamais sur l'empreinte du fichier.**
Le tableur du portail embarque un horodatage de génération — vérifié dans le
relevé réel : `dcterms:created` vaut `2026-08-20T09:26:26Z` pour un fichier
téléchargé le 21/08. Deux exports identiques peuvent donc différer octet pour
octet, et se fier aux octets annoncerait un changement chaque nuit. Un test
fige ce point : les empreintes des deux relevés y sont volontairement
différentes, et rien n'est signalé quand le contenu est le même.

Cinq décisions, chacune contre un faux signal :

- **un premier relevé n'annonce rien.** Sinon chaque entreprise afficherait
  quatorze changements le jour de son arrivée, et le signal deviendrait bruit ;
- **`null` et `''` ne se signalent pas l'un l'autre** — le portail dit « rien »
  de deux façons ;
- **un champ qui passe à vide compte pourtant comme un changement**, à la
  différence du rapprochement avec l'entreprise : ici les deux valeurs viennent
  du même portail lu par le même scraper, et une valeur qui disparaît est soit
  un changement réel, soit un défaut d'extraction — les deux méritent d'être
  vus ;
- **l'identité d'un point est son `etablissement_id`**, pas son nom : un point
  renommé reste le même point. Le voir comme une disparition suivie d'une
  apparition noierait le renommage — or c'est la cause du rejet le plus
  fréquent, « le nom du point de vente doit être déclaré à l'identique » ;
- **`--silencieux` au passage planifié.** Un journal qui répète chaque heure
  « aucun changement » cesse d'être lu, et c'est le jour où il dit quelque chose
  qu'on ne le lira pas.

**Les trois champs fiscaux sont nommés à part.** Quand `timbre_quittance`,
`bapa` ou `sticker_solde_alerte` bougent au portail, le rapport le dit
explicitement — et ne recopie rien. Un test vérifie que l'entreprise ressort
inchangée, octet pour octet, après le passage. C'est la règle d'or : **un
constat, pas une décision.**

Éprouvé sur un cas réel simulé — déménagement de commune, timbre de quittance
désactivé, point de facturation renommé — puis la base rendue à son état
d'origine (2 imports, 1 fiche, 1 point).

**Le cycle horaire complet :**

| Minute | Quoi |
|---|---|
| :00 | `portail-fne:importer` — range les fichiers déposés |
| :10 | `fne:diagnostiquer-rejets` — rapproche les pièces refusées |
| :15 | `portail-fne:changements --silencieux` — dit ce que le portail a changé |
| :40 | le scraper — sert la file |
| 02:30 | le scraper, passage complet |

### Lot 15 — Le relevé ne s'enregistre plus quand il ne dit rien — **TERMINÉ**

**Le premier relevé réel a eu lieu le 27/08/2026**, et il est passé du premier
coup : connexion, navigation, 14/14 champs reconnus, export du tableur. Le JSON
déposé est **identique octet pour octet** à celui posé à la main le 21/08 —
le scraper reproduit exactement le fichier de référence. Le lot 12 passe donc
de « premier relevé réel à faire » à éprouvé.

Ce relevé a mis en évidence ce que ce lot corrige.

#### Ce qui n'allait pas

Chaque passage écrivait une fiche et un jeu de points, **que le portail ait
bougé ou non**. L'empreinte SHA-256 du fichier ne pouvait rien y faire : le
tableur du portail embarque un horodatage de génération (`dcterms:created`), et
deux exports identiques diffèrent donc octet pour octet. Vérifié en
décompressant les deux : seul `docProps/core.xml` change, toutes les feuilles
de données sont identiques.

La conséquence coûteuse n'était pas la taille de la table. C'est que
`DiagnosticFneService::diagnosticEstAJour()` compare l'identifiant de la
dernière fiche : **une fiche neuve, fût-elle identique au mot près, périmait
chaque nuit tous les diagnostics de rejets**, rejoués pour aboutir au même
constat.

#### Ce qui a été fait

La comparaison porte sur le **contenu lu**, jamais sur les octets — le même
principe que `changementsDepuisLePrecedent()`, qui l'avait déjà tranché pour
les points.

Un relevé qui redit ce que la base sait déjà **ne crée plus aucune ligne, nulle
part** — pas même une ligne d'import. La ligne existante est *confirmée* :
`dernier_releve_le` avance, `releves` monte d'une unité.

| Table | Règle |
|---|---|
| `portail_fne_imports` | une ligne par **contenu**, pas par passage. Un relevé identique confirme la ligne existante |
| `portail_fne_fiches` | écrite seulement quand le contenu change |
| `portail_fne_points_facturation` | **tout le jeu ou rien**. N'écrire que le point modifié ferait répondre « le portail ne déclare qu'un point de vente » là où il y en a cinq |

**Trois dates à ne pas confondre** sur une ligne d'import :

| Colonne | Ce qu'elle dit |
|---|---|
| `date_scraping` | depuis quel relevé le portail affiche **ce** contenu |
| `dernier_releve_le` | quand on l'a vu pour la dernière fois |
| `created_at` | quand Selflow l'a rangé |

Qui veut savoir si le scraper tourne encore lit `dernier_releve_le`. Qui veut
savoir depuis quand un paramétrage est en place lit `date_scraping`. Écraser la
seconde effacerait l'ancienneté du paramétrage, qui est justement ce qu'on
cherche quand une pièce est refusée.

`releves` ne monte **qu'au changement de date de relevé** : le dossier d'import
est relu toutes les heures, et compter chaque relecture ferait dire à ce
compteur le nombre de passages du planificateur plutôt que le nombre de relevés.

La comparaison se fait sur l'empreinte du contenu **canonicalisé**
(`empreinteDuContenu()`), pas sur les octets : `"5000"` ou `5000`, `"*"` ou
`null`, `true` ou `"true"`, colonnes du tableur réordonnées, ligne vide en fin
de feuille — autant de libertés que le portail s'autorise et qui ne sont pas
des changements. Un champ **inédit**, lui, en est un.

Elle porte sur le **dernier** relevé du login, jamais sur n'importe lequel : un
portail qui passe de A à B puis revient à A a changé deux fois, et rattacher ce
troisième relevé à la ligne A d'origine laisserait B comme état le plus récent
en base — c'est-à-dire un état que le portail n'affiche plus.

Trois conséquences traitées avec :

- **La date d'une fiche n'est plus celle du dernier passage**, mais celle du
  dernier *changement*. L'écran des rejets lit désormais `portail_fne_imports`
  pour dater le relevé, sinon il afficherait « relevé du 15/08 » un 27/08 sur
  un scraper qui tourne parfaitement.
- **`portail-fne:changements --silencieux`** ne rapporte que si le dernier
  changement est celui du dernier passage. Sans ce filtre, le passage planifié
  annoncerait chaque heure une nouvelle vieille de trois semaines — et le
  drapeau, qui existe pour qu'un journal reste lisible, ne servirait plus à
  rien. Lancée à la main, la commande continue de montrer le dernier changement
  connu quelle que soit sa date.
- **Une fiche orpheline se rattache après coup.** Un relevé arrivé avant que
  l'entreprise n'existe portait un `entreprise_id` nul, et le rattachement se
  faisait tout seul au relevé suivant. Ne plus rien écrire l'aurait laissée
  orpheline pour toujours.

| Périmètre | Fichier |
|---|---|
| L'empreinte du contenu | `ImportPortailFneService::empreinteDuContenu()`, `ficheCanonique()`, `pointsCanoniques()` |
| La confirmation sans écriture | `ImportPortailFneService::dernierReleveDeMemeContenu()`, `confirmerLeReleve()` |
| Les colonnes et la reprise | `2026_08_27_000001_relever_sans_redire` — reprise des lignes déjà en base et repli des doublons |
| Les deux dates | `RejetFneControleur`, `Vues/fne/rejets.blade.php` |
| Le silence du planificateur | `ChangementsPortailFne::changementDuDernierPassage()` |
| Tests | `ImportPortailFneTest` — 6 nouveaux, 13 au total |

**Éprouvé sur le portail réel le 27/08.** Trois relevés d'affilée : la base
n'a pas gagné une ligne, et `releves` est passé à 3 sur la ligne du 21/08. La
migration a replié les doublons laissés par la version précédente — 4 lignes
d'import ramenées à 2, un seul jeu de points.

#### Ce que seul l'essai réel a trouvé

La première version comparait les points stockés via `attributesToArray()`, qui
rend les dates sous leur forme sérialisée (« 2026-07-30T10:38:40.000000Z ») là
où un relevé frais porte des objets `Carbon`. Les deux ne pouvaient jamais être
égaux : **un tableur identique revenait indéfiniment comme un changement.**

Les tests ne l'ont pas vu, parce que leurs tableurs n'avaient aucune colonne de
date — les deux côtés valaient `null`, et la comparaison croyait tout comparer.
C'est le relevé du 27/08 sur le vrai portail qui l'a montré, en ressortant
« importé » là où « inchangé » était attendu.

Les valeurs sont désormais relues par accesseur, et `Créé à` / `Mise à jour à`
figurent dans les tableurs de test — vérifié en réintroduisant le défaut : le
test échoue.

#### Trois constats à part, non corrigés

- **`php artisan test` s'arrête à 423 tests sur 775** — mémoire épuisée dans
  `zipstream-php` pendant `ImportPortailFneTest`, et le rapport annonce
  « passed » quand même. Reproduit avant comme après ce lot, donc antérieur.
  Contournement : `php -d memory_limit=1G vendor/bin/phpunit` passe la suite
  entière — 775 tests, 774 verts.
- **`TableauDeBordGeneralTest` échoue** : `CONCAT` n'existe pas en SQLite. Le
  tableau de bord marche sous MySQL, le test ne peut pas le vérifier. Sans
  rapport avec ce lot.
- **Les noms des points de vente s'affichent en double** dans le diagnostic
  quand deux relevés tombent le même jour : `DiagnosticFneService` déduplique
  les identifiants d'établissement (`array_unique`) mais pas les noms.
  Cosmétique.

### Lot 16 — Le relevé des factures reçues — **CHAÎNE COMPLÈTE, EN ATTENTE DE DONNÉES**

`SCRAPER-PORTAIL-FNE/achats.js`. `fne.js` n'a pas été touché : seule sa liste
d'exports a été complétée, pour que la connexion — qui porte l'attente
d'hydratation Next.js — ne soit pas recopiée. `verifier-extraction.js` continue
de passer ses 17 contrôles, ce qui le prouve.

#### Ce que la reconnaissance a trouvé, le 27/08/2026

Un mode `--reconnaissance` explore le portail et écrit un rapport, au lieu
d'exiger un aller-retour à la main dans les outils de développement. Il a rendu
tout ce qui manquait :

| | |
|---|---|
| Page | `/fr/invoice-management?type=received` (et `?type=issued` pour les pièces émises) |
| Liste | `GET /ws/invoices?page=&perPage=&fromDate=&toDate=&sortBy=-date&listing=received&complete=true` |
| Enveloppe | `{ data: [...], page, perPage, total }` |
| Totaux | `GET /ws/invoices/details?…&listing=received` — les KPI du tableau de bord, pas la liste |

**L'API est celle que Selflow connaît déjà** : `http://54.247.95.108/ws`, la
même base que `FNE_API_URL_SANDBOX`. À vérifier un jour : la clé d'API de
l'entreprise ouvre-t-elle `/ws/invoices` ? Si oui, le navigateur devient inutile
pour ce relevé.

#### Le moule d'un enregistrement

Relevé sur les pièces **émises** (112 en base) faute de pièce reçue :

| Champ | Ce qu'il porte |
|---|---|
| `reference` | le numéro FNE — `B1864699A26000000016` |
| `token` | le code de vérification, celui du QR |
| `type` / `subtype` | `invoice` / `purchase_slip`, `normal`, `refund`, `proforma` |
| `isRne`, `rne` | la distinction reçu / facture, que Selflow connaît déjà |
| `date` | ISO |
| `amount`, `vatAmount`, `fiscalStamp`, `discount`, `totalBeforeTaxes`, `totalTaxes`, `totalAfterTaxes`, `totalCustomTaxes`, `totalDue` | des **nombres**, pas des libellés formatés |
| `items[]` | **le détail des lignes** — un achat pourra donc mouvementer un stock |
| `company{}` | l'entreprise émettrice, avec son `declarantNumber` |
| `clientNcc`, `clientCompanyName` | la contrepartie |
| `customTaxes[]` | les taxes personnalisées |

#### Ce qui a été appris en chemin

- **Le clic sur un menu Next.js rend la main avant la navigation.** Le premier
  essai a relevé le tableau de bord en croyant relever les factures. La
  navigation se fait désormais par URL, le menu ne servant que de repli.
- **`page.request` ne porte pas le jeton.** Le portail l'envoie en en-tête
  `Authorization`, posé par son JavaScript ; rejouer un appel sans lui rend 401.
  Il est capté au vol sur un appel que la page fait elle-même, **vit en mémoire
  et n'est écrit nulle part** — ni journal, ni fichier déposé, ni rapport.

#### Le dépôt

`storage/app/portail-fne/achats/<login>_<AAAAMMJJ>.json` — un **sous-dossier**,
et non un suffixe : la découpe du nom se fait au dernier `_`, qu'un login peut
contenir. L'import actuel ne lit que la racine du dossier : il ignore ces
fichiers, et la chaîne éprouvée n'est pas perturbée.

Aucun horodatage de génération dans le fichier, conformément à la leçon du lot 15.

#### Où ça en est

**Le relevé fonctionne.** Mais `DC-KNOWING CGA` n'a **aucune facture reçue** —
`total: 0` sur toute la fenêtre 2024-2026. Le fichier déposé porte donc
`factures: []`. Ce n'est pas une panne : l'API répond 200 avec un total franc.

#### Le côté Selflow

| Périmètre | Fichier |
|---|---|
| Les tables | `2026_08_27_000002_factures_recues_du_portail` — `portail_fne_factures_recues` et ses lignes |
| Les modèles | `PortailFneFactureRecue`, `PortailFneFactureRecueLigne` |
| L'import | `ImportFacturesRecuesService` — à côté de `ImportPortailFneService`, jamais dedans |
| La commande | `portail-fne:importer-achats`, planifiée à la minute **05** |
| Tests | `ImportFacturesRecuesTest` — 10 cas, bâtis sur la forme **réelle** de l'API |

**Une facture est un fait, pas un état.** C'est la différence de fond avec les
fiches d'entreprise, que le même dossier historise à chaque relevé. Une fiche est
une photographie qu'on reprend pour voir ce qui a bougé ; une facture est émise,
certifiée, et ne change plus. L'unicité porte donc sur `(login, reference)` — le
numéro FNE — et un second relevé **met à jour** au lieu de dupliquer. La ligne
d'import, elle, suit la même règle qu'au lot 15 : contenu identique, aucune
ligne créée, la précédente est confirmée.

**Table à part, jamais les colonnes d'`achats`.** `achats.numero_fne`,
`achats.normalise`, `achats.fne_*` veulent dire « Selflow a émis cette pièce et
la DGI l'a certifiée » — le cas du bordereau agricole. Une facture reçue a été
certifiée par le fournisseur. Y écrire ferait mentir Selflow sur ce qu'il a émis,
devant un contrôle.

**Aucun achat n'est créé, aucune écriture produite, aucun fournisseur inventé.**
Le rapprochement se propose (`rapprochementPropose()`), il ne s'applique pas :
il rend le fournisseur probable — retrouvé par **NCC**, jamais par le nom —,
l'achat candidat, et l'**écart TTC** s'il y en a un. Cet écart est ce qui vaut
de l'argent. Un test le verrouille : `test_le_releve_ne_cree_aucun_achat`.

Trois précautions qui viennent de fautes déjà commises ailleurs dans le projet :

- `tvaDeductible()` rend **faux** pour un `purchase_slip` et pour un RNE. Un
  bordereau constate un achat auprès d'un non-assujetti : il ne facture aucune
  TVA, et la déduire serait l'erreur que `ventilationAchat` a déjà corrigée.
- Les taxes d'une ligne sont conservées **brutes**. Rien ne garantit que le
  portail parle le langage de `Produit::CODES_TVA` ; deviner un code à partir
  d'un montant reviendrait à inventer une information fiscale.
- Une pièce **sans numéro FNE** est écartée et journalisée : sans identité, elle
  ne pourrait ni être reconnue au relevé suivant, ni détecter un doublon.

#### Ce qu'il manque encore

- **Des données.** `DC-KNOWING CGA` n'a aucune facture reçue. La chaîne tourne
  à vide et le dit : `0 facture(s)`, aucune erreur.
- **Le scraper n'est pas planifié.** `achats.js` se lance à la main. L'accrocher
  au planificateur avant d'avoir vu une seule facture réelle ferait tourner un
  navigateur chaque heure pour rien.
#### L'écran

`admin/fne/factures-recues`, sixième onglet de la barre FNE et entrée du menu
latéral. Quatre filtres — **à rapprocher**, **rapprochées**, **fournisseur
inconnu**, **écartées** — et par pièce : l'émetteur avec son NCC, les montants,
la mention **déductible / non déductible**, et le détail des lignes.

Le rapprochement est **calculé à l'affichage**, jamais stocké : un fournisseur
créé ce matin doit être vu ce matin, sans attendre le relevé de la nuit.

Trois gestes, et aucun ne crée quoi que ce soit :

| Geste | Ce qu'il fait |
|---|---|
| **Rattacher** | pose `portail_fne_factures_recues.achat_id` vers un achat **déjà saisi**. Sans achat en face, il refuse et le dit — il n'en fabrique pas |
| **Détacher** | défait le lien, la pièce retourne à rapprocher |
| **Écarter** | range la pièce sans la supprimer : le portail la redéposera au prochain relevé, et une pièce écartée qui revient chaque jour finirait par masquer celles qui comptent |

**L'écart de montant est ce qui vaut de l'argent.** L'achat saisi dit 11 000, la
DGI détient 11 800 : l'écran le montre et le conserve dans
`note_rapprochement`. Personne ne le voyait avant.

Rien n'est écrit dans les colonnes gelées d'`achats` — un test le vérifie
explicitement (`assertNull($achat->numero_fne)`).

`EcranFacturesRecuesTest` — 7 cas, dont l'isolation entre entreprises : une
pièce fiscale lue par le mauvais client ne se répare pas.

#### Ce qu'il manque encore

- **Des données.** `DC-KNOWING CGA` n'a aucune facture reçue. La chaîne tourne
  à vide et le dit : `0 facture(s)`, aucune erreur.
- **Le scraper n'est pas planifié.** `achats.js` se lance à la main. L'accrocher
  au planificateur avant d'avoir vu une seule facture réelle ferait tourner un
  navigateur chaque heure pour rien.

#### Une trouvaille, laissée telle quelle

`FneControleur` existe depuis le lot I et porte exactement cette intention —
*« Recherche de documents fiscaux ENTRANTS »*. **Aucun écran ne l'appelle** :
`fne.rechercher` et `fne.attacher` sont des routes orphelines. Et son code porte
trois défauts, non corrigés parce qu'ils traversent le périmètre gelé :

1. l'URL `GET /api/v1/documents/{ref}` est **inventée** — le docblock l'avoue
   (« une supposition raisonnable »). La vraie est `/ws/invoices` ;
2. le repli d'URL en dur porte encore `https://fne-sandbox.dgi.gouv.ci`, l'hôte
   inexistant que le lot 9 avait corrigé dans `config/selflow.php` ;
3. `attacherFneAchat` écrit dans `achats.numero_fne` — colonne gelée signifiant
   « Selflow a émis et la DGI a certifié ». Y mettre la référence d'un
   fournisseur ferait mentir Selflow devant un contrôle.

Le nouvel écran ne passe par aucun de ces chemins.

### Lot 17 — Le cycle FNE joué sur ses trois cas — **TERMINÉ**

Demandé par le propriétaire du projet le 29/08/2026 : vérifier que la chaîne
réagit bien aux trois situations qu'une facture rencontre, et non seulement que
chaque maillon fonctionne isolément.

`tests/Feature/CycleFneTroisCasTest.php` les enchaîne comme la production les
enchaîne — envoi réel par `FneService`, seul le réseau est simulé, et le portail
dépose ses deux fichiers dans le dossier convenu comme le scraper le fait.

| Cas | Ce que la DGI fait | Ce qui se produit | Vérifié |
|---|---|---|---|
| 1 | elle certifie | pièce normalisée, aucun rejet, file du scraper vide | oui |
| 2 | elle refuse la pièce | demande ouverte, relevé rangé, écart nommé, reprise certifiée | oui |
| 3 | elle ne répond pas | le job rejoue ; épuisé, il consigne un rejet `reseau` **sans** ouvrir de relevé | oui |

**Le cas 2 se corrige tout seul depuis le lot 18** — voir plus bas. La première
version de ce lot s'arrêtait à l'écart montré, et le propriétaire du projet a
demandé, le même jour, qu'il se referme sans intervention.

#### Ce que la simulation a trouvé — la fiche et les points ne se retrouvaient plus

`DiagnosticFneService::pointsDuReleve()` appariait les points de facturation à
la fiche **par égalité de `date_scraping`**. Or ce sont deux fichiers, donc deux
relevés indépendants, et depuis le lot 15 l'import **ne réécrit pas un contenu
inchangé** : leurs dates divergent dès le deuxième passage. C'est le cas
ordinaire, pas le cas rare.

Les deux constats faux qui en sortaient ont été reproduits, l'un et l'autre :

| Ce qui bouge au portail | Ce que le rapprochement disait |
|---|---|
| les points seuls (un point renommé) | il lisait ceux de la veille et **maintenait un écart déjà corrigé** — pour toujours, car `diagnosticEstAJour()` ne comparait que la fiche, inchangée, et la commande horaire sautait le rejet |
| la fiche seule (un solde d'alerte) | aucun point ne portait la date de la fiche : « **le relevé ne déclare aucun point de facturation actif** », à une entreprise qui en déclare un |

Le second est le plus courant — le tableur des points ne bouge presque jamais —
et le plus trompeur : il envoie chercher une déclaration manquante là où il n'en
manque aucune.

**Trois corrections, toutes dans `DiagnosticFneService`, hors périmètre gelé :**

1. les points retenus sont le **dernier jeu connu** du login, quelle que soit sa
   date. Un jeu est complet à sa date — `rangerPoints()` les écrit tous ou aucun
   — donc le plus récent décrit ce que le portail déclare. C'est déjà la règle
   que suit `PortailFnePointFacturation::changementsDepuisLePrecedent()` ;
2. le diagnostic porte `releve.points_le` en plus de `releve.fiche_id`, et
   `diagnosticEstAJour()` compare les deux : des points neufs sous une fiche
   inchangée refont désormais le constat ;
3. la phrase cite la date **des points**, non celle de la fiche : celui qui va
   vérifier au portail doit savoir de quand date ce qu'on lui montre.

Les deux épreuves qui tiennent ces cas tombent si l'on retire le correctif —
vérifié, pas supposé.

#### Au passage : la suite mourait de faim

`php artisan test` s'arrêtait sur `Allowed memory size of 134217728 bytes
exhausted` dès qu'une épreuve écrivait un classeur — ce que font celles qui
simulent le dépôt du scraper : le compresseur réclame 16 Mo d'un coup, et la
suite était déjà à plus de 110 Mo après quelques centaines d'épreuves. Le
processus de PHPUnit n'hérite pas du `-d memory_limit` de la ligne de commande :
la limite se pose dans `phpunit.xml`, désormais à 512 Mo.

#### Deux échecs qui ne venaient pas d'ici — **CORRIGÉS au lot 18**

Constatés en passant la suite entière, tous deux hors du cycle FNE :

- `HabilitationsTest` — les quatre routes `admin.achats.factures_recues*` du lot
  16 n'exigeaient aucune habilitation et n'étaient pas déclarées ouvertes. Elles
  sont rangées sous **`factures_achat`** : une facture relevée sur le portail est
  une facture fournisseur, et le rattachement n'écrit que dans
  `portail_fne_factures_recues.achat_id` — il ne crée aucun achat et ne touche à
  aucune colonne gelée ;
- `TableauDeBordGeneralTest` — `AdminControleur.php:280` appelait **`CONCAT`, que
  SQLite n'a pas**. Le palmarès des vendeurs faisait rendre 500 à tout le tableau
  de bord partout où la base n'est pas MySQL — à commencer par la suite
  d'épreuves, qui ne l'a donc jamais vu passer. Le nom complet s'assemble
  désormais en PHP, à partir de deux colonnes.

### Lot 18 — Le cas 2 se referme tout seul — **TERMINÉ**

Demandé par le propriétaire du projet le 29/08/2026, après la démonstration du
lot 17 : *« rendre le cas 2 automatique »*.

**Ce que Selflow fait désormais sans qu'on le lui demande.** Quand la DGI refuse
une pièce sur `pointOfSale` et que le relevé du portail ne déclare **qu'un seul**
point de facturation actif, le passage horaire renomme le point de vente comme le
portail l'écrit, puis renvoie à la DGI **toutes** les pièces que ce nom faisait
refuser. Le cycle complet — refus, demande, relevé, rapprochement, correction,
renvoi, certification — se déroule sans personne.

C'est `CorrectionFneService`, et l'interrupteur est
`selflow.portail_fne.correction_auto` (`PORTAIL_FNE_CORRECTION_AUTO`), allumé par
défaut. L'écran des rejets garde son bouton : la correction peut être éteinte, et
un rejet arrivé entre deux passages n'attend pas l'heure ronde. **Les deux lisent
la même règle**, dans le même service — le journal garde le précédent de la liste
des modules socle qui vivait en double et avait perdu `points_de_vente` des deux
côtés.

**Ce qui borne l'automatisme, et pourquoi :**

| Garde-fou | Raison |
|---|---|
| Un seul champ : le nom du point de vente | c'est un libellé descriptif, dont le portail est la source de vérité. `establishment` ne s'y ajoutera pas : le portail n'en publie que l'identifiant, et corriger sur un identifiant reviendrait à deviner |
| **Jamais** `timbre_quittance`, `bapa`, `sticker_solde_alerte` | ils commandent ce que la facture contient. La règle d'or ne bouge pas : l'automatisation porte sur un **nom**, jamais sur un **montant** |
| Un seul point déclaré au portail | à plusieurs, la machine ne sait pas dans lequel la pièce a été établie ; choisir renommerait un site sur une supposition |
| Rien si le nom est déjà celui du portail | c'est ce qui empêche la boucle : après un renommage, le rapprochement conclut « concordant » et ne propose plus rien. Pas de compteur à tenir |
| Une entreprise en certification manuelle est corrigée, pas renvoyée | elle vérifie ses pièces avant de les certifier, et **une pièce certifiée ne se reprend pas**. Corriger pour elle est un service ; envoyer à sa place passerait outre un choix explicite |
| Le renommage part au journal en `warning` | il touche toutes les pièces à venir de ce point de vente, et personne n'est devant l'écran quand la tâche planifiée passe |

**Toutes les pièces, et non la seule du rejet traité.** Un nom mal orthographié
fait refuser tout ce qui part de ce point de vente : une soirée de saisie laisse
dix pièces refusées pour une seule cause. Ne renvoyer que la première les
laisserait refusées — et le rapprochement suivant les dirait *concordantes*,
puisque la valeur envoyée se relit sur le point de vente, qui vient d'être
corrigé. Elles seraient restées en plan sans que rien ne le rappelle.

**Un défaut de plus, mis au jour par ce lot.** `fne:diagnostiquer-rejets`
écrivait `statut = diagnostique` sans regarder l'état courant. Sans conséquence
tant que rien ne résolvait un rejet pendant le passage ; depuis que la correction
renvoie les pièces, la commande **rétrogradait de « résolu » à « diagnostiqué »**
les rejets refermés une seconde plus tôt, et l'écran affichait en souffrance des
pièces certifiées. Elle applique désormais la règle que l'écran suivait déjà : un
rejet résolu reste résolu.

Sept épreuves de plus dans `CycleFneTroisCasTest` — quatorze en tout. Suite
entière : **1 141 épreuves, 1 141 passantes, 4 564 vérifications.**

### Lot 19 — Les trois cas joués sur la vraie plateforme — **FAIT le 29/08/2026**

Les lots 17 et 18 étaient éprouvés en simulation. Le propriétaire du projet a
demandé la répétition en réel. Elle a eu lieu sur l'environnement de test de la
DGI (`54.247.95.108` — le `.env` y pointe pour le bac à sable **comme** pour la
production), avec l'entreprise **DC-KNOWING CGA** (NCC `1864699A`).

Préalable : `fne_credentials` était **vide**, aucune clé API n'existait en base.
La clé de test a été relevée sur la page Paramétrage du portail — où elle
s'affiche en clair — et posée chiffrée dans `fne_credentials`. Elle n'est jamais
passée par un fichier déposé : `fne.js` l'écarte par son libellé, et c'est bien.

**Les trois cas, et ce que la DGI a réellement répondu :**

| Cas | Ce qui a été fait | Ce que la plateforme a rendu |
|---|---|---|
| **2** | `VTE-290826-001` émise avec le point de vente nommé `Siège` | **HTTP 400 sur `pointOfSale`** — « Point of sale is invalid ». Rejet classé `dgi`, demande ouverte |
| | `node fne.js` sans argument | il a lu la file de Selflow, s'est connecté au portail, relevé 14/14 champs, déposé |
| | `fne:diagnostiquer-rejets` | « Vous avez envoyé *Siège*. Le portail déclare *FACTURATION SIEGE*. » puis **renommage automatique** et renvoi |
| | le renvoi | **certifiée : `1864699A26000000079`**, signature, UUID, PDF. Rejet refermé seul |
| **1** | `VTE-290826-002`, nom désormais aligné | **certifiée du premier coup : `1864699A26000000080`**. Aucun rejet, file du scraper vide |
| **3** | `VTE-290826-003`, envoi dirigé vers `10.255.255.1` (coupure **induite**, réglage changé dans le seul processus) | vrai délai de **10,1 s**, `cURL error 28`. 1<sup>re</sup> tentative : relance demandée, **rien consigné**. Dernière : rejet `reseau`, **zéro demande** — le scraper est resté au repos |
| | reprise sur la vraie adresse | **certifiée : `1864699A26000000081`** |

État final : trois pièces certifiées, deux rejets refermés, aucune demande en
attente, point de vente `FACTURATION SIEGE`. Aucun client ni produit n'a été
créé — les pièces sont parties en B2C avec une ligne libre à 0 %, cohérente avec
le régime TEE que la FNE code `TVAD`.

#### Ce que le réel a confirmé du lot 17

L'import de la fiche porte `dernier relevé = 21/08`, celui des points `29/08` :
**les deux dates ont bel et bien divergé dans les données réelles.** Avant le
correctif, le rapprochement aurait cherché des points datés du 21 alors que les
derniers portaient le 29. Le défaut n'était pas théorique.

#### Ce que le réel a trouvé — **la file n'est traitée par personne**

C'est le constat le plus grave de la journée, et seule une répétition réelle
pouvait le donner.

`QUEUE_CONNECTION=database`, et **aucun `queue:work` ne tourne** : ni tâche
Windows, ni entrée au planificateur — les huit lignes de `schedule:list` n'en
portent aucune. La table `jobs` contenait **deux jobs en souffrance depuis le
22 et le 24 juillet**.

Or **sept endroits** normalisent par `dispatch()` — `VenteControleur`,
`AchatControleur`, `B2bControleur`, `BonLivraisonControleur`,
`VenteApiControleur`, et désormais `CorrectionFneService`. Conséquence : **une
facture émise depuis l'application ne part jamais à la DGI**. Elle reste
`normalise = false`, aucun rejet n'est consigné, et rien ne le signale — le job
attend dans une table que personne ne lit.

Seuls deux appels échappent au piège, en `dispatchSync()` : les boutons
« normaliser » manuels. C'est ce qui a masqué la panne — à la main, tout marche.

**Ce qui reste à poser**, et qui n'a pas été fait ici parce que cela engage
l'exploitation : un `queue:work` permanent. Deux formes possibles — une entrée
au planificateur (`php artisan queue:work --stop-when-empty` chaque minute, dans
l'esprit des tâches déjà en place) ou un service permanent. Les deux jobs de
juillet ont été **laissés intacts** : les traiter enverrait à la DGI des pièces
vieilles d'un mois, et c'est une décision du propriétaire.

### Lot 20 — Le refus se voit, se corrige d'un bouton, et ne duplique plus — **TERMINÉ le 30/08/2026**

Demandé par le propriétaire du projet le 30/08/2026, à partir de son écran :
*« quand je normalise avec un point de vente qui n'existe pas côté DGI, le pop-up
doit m'informer d'abord, puis lancer le scraping »*, puis *« un vrai pop-up, pas
un bandeau »*, *« un bouton pour lancer la correction »*, et enfin *« ne plus
créer le même point de vente — basculer sur celui qui existe »*.

**Le refus parle, à l'instant du clic.** `VenteControleur::normaliser()` rendait
un flash **« succès »** même quand la DGI refusait : un message vert rassurant sur
une pièce non normalisée. Il lit désormais l'état réel après le job et distingue
trois issues — certifiée (vert), refusée par la DGI (avertissement), plateforme
injoignable (erreur). Sur un refus `pointOfSale`, il **lance le scraper dans la
foulée** (`ScraperPortailFneService`, `node fne.js <login>` détaché en arrière-
plan) : la relève ne dépend plus du seul passage horaire. Le lancement est borné
par `selflow.portail_fne.scraper.actif`, ne lève jamais d'exception — un échec
laisse la demande en file, l'ordonnanceur prend le relais.

**Le flash devient un pop-up.** La gabarit rendait les messages en bandeau, en
tête d'un écran chargé, où ils passaient inaperçus — et deux clés, `erreur` et
`info`, n'étaient même **pas rendues** : le message se perdait en silence. À la
place, un **toast** en surimpression, coin haut-droit : succès et information
s'effacent seuls, avertissement et erreur restent jusqu'à ce qu'on les ferme —
on ne rate pas un refus. Le message est posé en `textContent`, jamais interprété
comme du HTML. Un toast peut porter des **boutons d'action**, colorés selon le
type ; le refus DGI en porte deux : *« Lancer la correction »* et *« Voir les
rejets FNE »*.

**Le bouton fait tout, en un clic.** `RejetFneControleur::corrigerMaintenant()`
enchaîne ce que l'utilisateur ferait à la main : ranger le relevé
(`portail-fne:importer`), rapprocher le rejet, puis appliquer la correction. Si
le relevé n'est pas encore arrivé — le scraper tourne encore —, il le dit et ne
fait rien de faux.

**La correction ne duplique plus un point de vente.** C'était un défaut réel,
constaté à l'écran : **trois** « FACTURATION SIEGE » dans trois villes, parce que
la correction *renommait* à chaque fois un point de test différent vers le même
nom. Désormais, si un point de vente porte **déjà** le nom déclaré au portail, on
ne renomme pas — on **rattache la pièce au point existant** (`basculerSurExistant`)
et le point mal nommé reste tel quel. Un seul point par nom, comme le portail n'en
déclare qu'un. Le renommage ne subsiste que pour le premier alignement, quand
aucun homonyme n'existe. Les trois doublons déjà en base ont été fusionnés sur le
plus ancien (ventes certifiées et écritures rattachées, stocks de test retirés,
références redirigées, le tout en transaction) — un seul « FACTURATION SIEGE »
subsiste.

**Le bouton certifie tout de suite.** Rappel du lot 19 : `QUEUE_CONNECTION=database`
et aucun `queue:work` ne tourne, donc un `dispatch()` reste en souffrance.
`CorrectionFneService::corriger()` prend un paramètre `synchrone` : les **deux
boutons** (`appliquer`, `corrigerMaintenant`) passent `true` et renvoient en
`dispatchSync()` — la certification a eu lieu quand le message s'affiche.
L'ordonnanceur nocturne, lui, garde la file (`false` par défaut) : il ne doit pas
bloquer sur des dizaines d'appels réseau. Cela ne referme pas la question du
`queue:work` permanent (lot 19), mais les gestes déclenchés par un humain ne
tombent plus dans le trou.

**Deux migrations réparées au passage.** `comptes_de_taxes_collectees` et
`comptes_de_tva_deductible` avaient une garde d'idempotence cassée : les clés
numériques de leur table de comptes (`'443200'`) sont transtypées en entier par
PHP, quand `pluck('numero')` rend des chaînes ; la comparaison stricte échouait
toujours, la garde ne sautait jamais un compte présent, et la migration se
brisait sur l'index unique du plan comptable dès la deuxième entreprise. Comparées
en chaîne, elles sont de nouveau idempotentes.

Un test permanent de plus, `CorrectionSansDoublonPdvTest` — la pièce est rattachée
au point existant sans le dupliquer. `phpunit.xml` épingle deux valeurs pour que la
suite ne dépende plus du `.env` du poste : `PORTAIL_FNE_CORRECTION_AUTO=true` (le
défaut que supposent les épreuves du cas 2) et `PORTAIL_FNE_SCRAPER_ACTIF=false`
(aucune épreuve ne lance Node).

### Lot 21 — Une plateforme injoignable rendait une erreur 500 — **TERMINÉ le 31/08/2026**

Demandé par le propriétaire du projet le 31/08/2026, après avoir cherché comment
rejouer le **cas 3** en réel : le bouton « Normaliser » ne montrait pas ce que le
lot 17 avait éprouvé.

**Le défaut.** Le job décidait de relancer sur `attempts() < $tries`. Or les deux
boutons travaillent en synchrone (`dispatchSync`, depuis le lot 20), et
`SyncJob::attempts()` rend **toujours 1** : la condition était vraie pour
toujours. Sur une plateforme injoignable, le job levait donc son exception,
`SyncQueue::handleException` la relançait telle quelle, et l'écran rendait une
**erreur 500** — aucun rejet consigné, et le message « la plateforme FNE est
injoignable » que `VenteControleur` tenait prêt depuis le lot 20 était
**inatteignable**. Reproduit avant correction : `RuntimeException`, HTTP 500.

Les quatorze épreuves du cycle ne le voyaient pas : elles jouent `handle()` à la
main ou avec un message de file simulé, jamais le chemin du bouton.

**La correction, dans les deux jobs** (`NormaliserFactureFne`,
`NormaliserAchatBapaJob`) : la relance est décidée par `uneAutreTentativeViendra()`,
qui rend `false` dès que le job tourne en `SyncJob`. En synchrone personne ne
rejouera : c'est là, et à cet instant, qu'il faut consigner. Hors file — `handle()`
appelé directement — la règle ne change pas. **Rien du périmètre gelé n'est
touché** : ni payload, ni champ, ni colonne `fne_*`.

**Ce que l'écran dit maintenant.** Le message promettait une reprise automatique
qui n'existe pas — aucune tâche planifiée ne rejoue un rejet `reseau`, et l'écran
des rejets le dit déjà lui-même (« la pièce est à renvoyer »). Il annonce
désormais que la pièce **n'a pas été envoyée**, que la DGI n'a rien refusé, et
porte un bouton **« Réessayer »** (`erreur_action`, le mécanisme du pop-up du lot
20), dirigé vers la route du parcours en cours — admin ou caissier.

**Le bouton des achats portait en plus le défaut corrigé au lot 20 côté ventes** :
il rendait « succès » en vert quoi qu'il arrive, y compris sur un bordereau resté
non normalisé. Sans cela, la correction ci-dessus l'aurait rendu franchement
faux — un rejet consigné sous un message vert. Il distingue à son tour trois
issues : certifié, plateforme injoignable (avec « Réessayer »), refus de la DGI
(renvoi vers l'écran des rejets).

Deux épreuves de plus dans `CycleFneTroisCasTest` — **seize en tout** —, qui
passent par la route, pas par le job : elles tombent toutes deux si l'on retire
le correctif (vérifié : HTTP 500). Suite entière : **1 144 épreuves, 1 144
passantes, 4 585 vérifications.**

**Ce qui reste, et n'a pas bougé** : le `queue:work` permanent du lot 19. Ce lot
répare le geste humain, pas la file — une facture émise depuis l'application part
toujours en `dispatch()` dans une table que personne ne lit.

### Lot 22 — Le relevé des factures reçues entre au planificateur — **TERMINÉ le 31/08/2026**

Demandé par le propriétaire du projet le 31/08/2026. Le lot 16 avait laissé
`achats.js` hors du planificateur, faute d'avoir vu une seule facture réelle.

**Le rendez-vous : une fois par jour, à 04:15** (`PORTAIL_FNE_SCRAPER_HEURE_ACHATS`),
`--tous`, en arrière-plan, verrou de deux heures — comme le passage nocturne de
`fne.js`. Le chemin du script est `selflow.portail_fne.scraper.script_achats`.

**Pourquoi pas toutes les heures, comme `fne.js`.** `fne.js` consulte d'abord la
file de Selflow et s'arrête **sans ouvrir de navigateur** quand elle est vide —
le cas ordinaire. `achats.js` n'a rien qui le retienne : chaque passage ouvre une
session sur le portail de la DGI. Toutes les heures, ce serait vingt-quatre
connexions par jour pour une liste qui bouge au rythme des fournisseurs. Et une
facture reçue est un **fait acquis** — certifiée par son émetteur, elle ne
changera plus : la relever une heure plus tôt ne change rien à ce qu'elle dit.

**L'heure choisie** tient dans la journée déjà remplie : après le passage
nocturne de `fne.js` (02:30) et la rotation des clés Comptaflow (03:00), et
cinquante minutes avant le ramassage de `portail-fne:importer-achats` (05:05),
ce qui laisse au navigateur le temps de finir.

**Vérifié en réel le jour même**, sans attendre le rendez-vous :

| Geste | Résultat |
|---|---|
| `schedule:list` | la ligne `15 4 * * * node achats.js --tous` y figure |
| `node achats.js --tous` | connexion au portail, `0 facture(s) reçue(s) du 2024-01-01 au 2026-08-31`, dépôt de `1864699A_20260831.json` |
| `portail-fne:importer-achats` | « inchangé — identique au relevé du 27/08/2026 » : la règle du lot 15 tient, un contenu identique ne recrée aucune ligne |

**La chaîne est donc complète et éprouvée de bout en bout ; ce qui manque reste
la matière.** `DC-KNOWING CGA` n'a toujours aucune facture reçue au portail — le
`total: 0` est celui de la DGI, pas un défaut de Selflow. L'écran
`admin/fne/factures-recues` affiche `0 facture(s)` sans erreur, et se remplira au
premier fournisseur qui certifiera une pièce à son NCC.

Le planificateur de Windows, lui, tourne bien : la tâche « Selflow —
planificateur » appelle `schedule:run` chaque minute par
`storage/app/planificateur-selflow.vbs` (dernier résultat 0). Le trou du lot 19
porte sur la **file** (`queue:work`), pas sur l'ordonnanceur.

#### Un relevé d'exemple, à retirer — **DONNÉES DE DÉMONSTRATION EN BASE**

À la demande du propriétaire du projet, pour voir l'écran avant qu'un
fournisseur ait certifié quoi que ce soit, un relevé d'exemple a été déposé à la
main le 31/08/2026 dans `achats/1864699A_20260831.json` — **il a écrasé le
relevé réel du jour, qui était vide** ; `node achats.js 1864699A` le rétablit en
une minute.

Quatre pièces, choisies pour montrer chacun des états de l'écran :

| Référence | Émetteur | Ce qu'elle montre |
|---|---|---|
| `B0000001X26000000042` | FOURNITURES DEMO SARL | fournisseur retrouvé par NCC, achat candidat, **écart de 800 F** (Selflow dit 11 000, la DGI détient 11 800) |
| `B0000002Y26000000018` | GRANDS MOULINS DEMO | aucun fournisseur ne porte ce NCC — pièce orpheline |
| `B0000003Z26000000007` | COOPERATIVE AGRICOLE DEMO | bordereau d'achat agricole : **TVA non déductible** |
| `B0000001X26000000043` | FOURNITURES DEMO SARL | un avoir |

S'y ajoutent, créés pour que le rapprochement ait de quoi se proposer : le
fournisseur **FOURNITURES DEMO SARL** (NCC `0000001X`) et l'achat
**`AC-DEMO-0001`** (28/08/2026, TTC 11 000). Aucune écriture comptable, aucun
mouvement de stock — les deux lignes ont été posées directement.

**Tout se retire d'une commande** — les lignes suivent par cascade :

```
php artisan tinker --execute="use App\Modules\Admin\Modeles\PortailFneFactureRecue as F; use App\Modules\Admin\Modeles\Achat; use App\Modules\Admin\Modeles\Fournisseur; F::where('reference','like','B00000%')->get()->each->delete(); Achat::where('numero_facture','AC-DEMO-0001')->delete(); Fournisseur::where('ncc','0000001X')->delete();"
```

### Lot 23 — Les points du portail repris dans Selflow — **TERMINÉ le 31/08/2026**

Demandé par le propriétaire du projet le 31/08/2026 : *« si dans Selflow je n'ai
pas encore un point de vente et que le scraper fait un relevé, peut-il créer le
même point de vente dans Selflow ? normalement c'est ce qui devrait se faire »*.

**Le sens, et un seul : du portail vers Selflow.** L'inverse reste exclu —
déclarer un point de facturation à la DGI est un acte du contribuable, pas une
écriture technique, et le scraper ne fait que lire. Ce qui est repris est **le
nom que le portail écrit**, celui-là même que `pointOfSale` doit porter pour
qu'une facture soit certifiée : le reprendre plutôt que le ressaisir retire
l'accent et la casse du chemin d'une certification. C'est le refus du lot 19,
pris à sa racine.

**Un geste, pas un automatisme.** Un point de vente porte des utilisateurs, du
stock, des ventes, un site comptable : il ne doit pas naître à 4 h 15 parce
qu'un fichier est arrivé. L'écran des points de vente montre donc ce que le
portail déclare — relevé daté, un badge *Dans Selflow* ou *À créer* par point —
et un bouton **« Reprendre N point(s) manquant(s) »**. La comparaison se calcule
à l'affichage et ne se range nulle part : un point créé ce matin se voit ce
matin.

**L'appariement porte sur l'identifiant, pas sur le nom.** Nouvelle colonne
`points_de_vente.etablissement_fne_id`, migration `2026_08_31_000001`. Le lot 18
renomme les points d'après le portail et le lot 20 a dû fusionner à la main
trois « FACTURATION SIEGE » nés d'un appariement par nom : un point repris se
reconnaît désormais même renommé des deux côtés. Le nom ne sert qu'au **premier**
appariement — c'est lui qui fait *adopter* un point déjà saisi à la main, au lieu
d'en créer un second à côté. **Cette colonne ne part pas à la DGI** : `FneService`
n'est pas touché, le périmètre gelé non plus.

**Ce que la reprise ne fait pas** :

| Garde-fou | Raison |
|---|---|
| Ni ville, ni commune inventées | le portail n'en publie aucune ; les deux restent vides, à compléter — comme à l'import CSV |
| Le nom d'un point déjà saisi n'est pas récrit | aligner un nom est le geste du rapprochement des rejets, qui sait de quelle pièce il parle |
| Le quota d'abonnement borne la reprise | un relevé du portail n'ouvre pas l'abonnement |
| Rien pour une autre entreprise | le relevé est retenu par `entreprise_id`, celui que l'import a résolu depuis le NCC |

**Une duplication supprimée au passage.** L'ouverture des fiches de stock d'un
site neuf vivait dans `PointDeVenteControleur::creer()`. Un second endroit crée
désormais des points de vente ; la règle est passée sur le modèle
(`PointDeVente::initialiserLesFichesDeStock()`), et les deux chemins l'appellent.
Le journal garde le précédent de la liste des modules socle qui vivait en double
et avait perdu `points_de_vente` des deux côtés.

`PointsDeVenteDuPortailTest` — 8 épreuves : création, adoption sans doublon,
second passage après renommage, absence de relevé, cloisonnement entre
entreprises, quota, fiches de stock, et l'écran. Suite entière : **1 152
épreuves, 1 152 passantes, 4 611 vérifications.**

**Sur les données réelles**, l'écran dit aujourd'hui : relevé du 21/08,
`FACTURATION SIEGE` **dans Selflow** (#12), rien à créer — et, en dessous,
`hgf` (#18) **inconnu du portail**, avec la phrase qui explique qu'une facture
émise depuis ce point sera refusée tant qu'il n'aura pas été déclaré sur
l'espace FNE.

#### Ce que le premier vrai point de facturation créé a trouvé — **un relevé qui se disait « inchangé »**

Le propriétaire du projet a créé un point au portail dans la foulée, à 12 h 27,
et le relevé l'a bien rapporté : le tableur déposé porte deux lignes,
`FACTURATION TEST 2` et `FACTURATION SIEGE`. **L'import a pourtant répondu
« identique au relevé du 21/08/2026 »**, et le point n'est jamais entré en base.

La cause tient en une clé. `ImportPortailFneService::pointsCanoniques()` — et,
sous la même convention, `PortailFnePointFacturation::changementsDepuisLePrecedent()`
— indexaient les points sur `etablissement_id`. Or **le portail donne le même
identifiant d'établissement à tous les points d'un même établissement** : les
deux lignes portent `42200613-f402-40a8-bd4d-a778bb5b96f0`. Elles s'écrasaient
donc l'une l'autre, le relevé se réduisait à la dernière lue, l'empreinte de
contenu ne bougeait pas, et le portail pouvait déclarer un point de plus sans
que Selflow s'en aperçoive. Aucune épreuve ne le voyait : toutes donnaient un
identifiant **par point** — une forme inventée, qui cachait le défaut au lieu
de le montrer.

**Ce qui identifie un point de facturation est la paire établissement + date de
création.** La date de création est propre à chaque point et ne bouge pas quand
l'intitulé change : l'intention d'origine — « un point renommé reste le même
point » — est conservée. La règle vit désormais en un seul endroit,
`PortailFnePointFacturation::identite()`, que l'import et la détection de
changements appellent tous les deux.

Côté points de vente, l'appariement suit la même paire : migration
`2026_08_31_000002`, colonne `points_de_vente.point_fne_cree_a`. La migration du
matin n'a pas été retouchée — elle est appliquée, et une migration appliquée ne
se rejoue pas ; son en-tête porte la correction, la colonne manquante est posée
par une migration nouvelle.

Deux épreuves de plus dans `ImportPortailFneTest`, bâties sur le relevé réel :
deux points d'un même établissement ne s'écrasent pas, et un point créé au
portail ne passe pas pour un relevé inchangé. Elles tombent l'une et l'autre si
l'on remet la clé d'avant. Suite entière : **1 154 épreuves, 1 154 passantes,
4 617 vérifications.**

**Après correction, sur les données réelles** : relevé du 31/08,
`FACTURATION SIEGE` dans Selflow (#12), **`FACTURATION TEST 2` à créer**, et
`hgf` toujours inconnu du portail.

### Lot 24 — Une session ouverte, un relevé complet — **TERMINÉ le 31/08/2026**

Demandé par le propriétaire du projet le 31/08/2026, après avoir constaté qu'un
point de facturation déclaré au portail à 12 h 27 n'était pas visible de
Selflow : *« ce que le scraper doit faire : à chaque fois que la plateforme
s'ouvre, il fait un relevé »*. Retenu : **A + B, avec le bouton**.

#### A — tant qu'on est dedans, on relève tout

Ce qui coûte n'est pas le relevé, c'est **la connexion** : une session sur le
portail de la DGI se paie d'une authentification avec le mot de passe du client,
et y retourner souvent est le meilleur moyen de faire bloquer un compte. Le
relevé des factures reçues rouvrait pourtant une seconde session pour la même
entreprise, quelques heures après celle de `fne.js`.

`achats.js` expose désormais `releverDansLaSession()` — le corps de son relevé,
sans la connexion — et `fne.js` l'appelle **dans la session qu'il vient
d'ouvrir**, juste après les points de facturation. Un passage rapporte donc la
fiche, les points **et** les factures reçues. L'échec des factures reçues ne
perd rien : la fiche et les points sont déjà déposés, et c'est eux que la
certification attend ; le passage le dit et rend la main.

Le rendez-vous de 04:15 **disparaît, absorbé** — il avait vécu une matinée. Ses
deux réglages partent avec lui. `achats.js` reste lançable seul, pour un relevé
ciblé ou une reconnaissance. Le jeton `/ws/` continue de se capter au vol, et
l'écoute est posée sur la page **avant** toute navigation ; il ne vit qu'en
mémoire.

Vérifié en réel, en un seul passage :

```
-- 1864699A --
   Connexion...
   Parametrage...
   Fiche : 14/14 champs reconnus -> 1864699A_20260831.json
   Points de facturation -> 1864699A_20260831.xlsx
   Factures reçues...
   0 facture(s) reçue(s) du 2024-01-01 au 2026-08-31 -> 1864699A_20260831.json
```

`verifier-extraction.js` passe toujours ses 17 contrôles.

#### B — un relevé à l'ouverture de Selflow

Le passage horaire de :40 ne va au portail **que si la file des demandes n'est
pas vide** — c'est-à-dire que si une pièce a été refusée. Le journal le disait à
chaque heure : *« Rien à relever (la file de Selflow est vide) »*. Le passage
complet, lui, attend 02:30. Une modification faite au portail dans la journée
n'était donc visible que le lendemain matin.

`ScraperPortailFneService::relancerSiLeReleveEstVieux()` est appelé à la
connexion (`ConnexionControleur`), et lance le relevé en arrière-plan si le
portail n'a pas été lu depuis plus de `PORTAIL_FNE_FRAICHEUR_HEURES` (12 par
défaut). Trois garde-fous, dans cet ordre :

| Garde-fou | Raison |
|---|---|
| `PORTAIL_FNE_RELEVE_A_LA_CONNEXION` | l'interrupteur, qui éteint tout — et n'écrit pas le verrou, sans quoi rallumer ne servirait à rien pendant douze heures |
| Un verrou de cache posé **avant** d'aller voir | dix employés qui se connectent à huit heures ne lancent qu'un seul navigateur ; un relevé qui échoue ne se rejoue pas en boucle. `Cache::add()`, pas `put` : c'est l'écriture atomique qui décide |
| La fraîcheur | lu il y a moins de douze heures, on n'y retourne pas |

Le lancement est détaché : l'ouverture de session n'attend pas le navigateur, et
le service ne lève jamais.

#### Le bouton

**« Relever le portail maintenant »**, sur l'écran des points de vente, à côté
de « Reprendre les points manquants ». C'est le seul moyen d'avoir le portail
sous les yeux à la seconde où l'on vient d'y déclarer quelque chose. Éteint, le
scraper le dit plutôt que de laisser croire qu'un relevé part.

Et « Reprendre » **range d'abord** (`portail-fne:importer`) : un relevé lancé
trente secondes plus tôt ne doit pas attendre l'heure ronde. Même geste que le
« corriger maintenant » des rejets.

#### Une épreuve qui lisait les vrais relevés du poste

Ajouter ce rangement a fait tomber six épreuves : `portail-fne:importer` lisait
le dossier de dépôt **réel**, et rangeait dans la base de test le relevé du
portail — un point de facturation apparaissait que personne n'avait écrit.
`phpunit.xml` épingle désormais `PORTAIL_FNE_DOSSIER_IMPORT` sur un dossier de
test, comme il épingle déjà l'interrupteur du scraper et la correction
automatique. L'épreuve concernée se donne en plus son propre dossier.

Quatre épreuves de plus dans `PointsDeVenteDuPortailTest` — **douze en tout** :
le bouton quand le scraper est éteint, un relevé frais qui ne renvoie personne
au portail, le verrou, et l'interrupteur. Suite entière : **1 158 épreuves,
1 158 passantes, 4 623 vérifications.**

#### L'écran s'enfermait — trois défauts pris au vol

Le propriétaire du projet crée un troisième point au portail, « teste », à
13 h 00, clique, et rien n'entre. Trois causes, dans l'ordre où elles se sont
montrées :

1. **Le rangement était accroché au mauvais bouton.** « Reprendre les points
   manquants » rangeait le dépôt — mais il **n'apparaît que s'il y a un point à
   créer**, et il n'y en avait aucun *parce que* le dépôt n'était pas rangé.
   Celui qui venait de lancer un relevé n'avait donc aucun bouton pour le faire
   entrer, jusqu'au ramassage de l'heure ronde. `portail-fne:importer` est
   remonté dans `index()` : l'écran range avant de comparer, et le passage reste
   sans effet quand rien n'a changé — un fichier déjà lu est reconnu à son
   empreinte, sans être ouvert.

2. **Deux relevés du même jour s'empilaient.** Le passage nocturne, puis un clic
   sur « Relever maintenant » : deux jeux, une seule date. Retenus par leur
   date, ils se cumulaient et l'écran montrait chaque point **deux fois**. Le
   jeu est identifié par son **import**, désormais —
   `PortailFnePointFacturation::dernierJeu()` —, et les trois lecteurs le
   suivent : l'écran des points de vente, le rapprochement des rejets
   (`DiagnosticFneService`, qui citait deux fois le même nom au responsable venu
   comprendre un refus) et `changementsDepuisLePrecedent()`, qui comparait des
   points n'ayant jamais coexisté.

3. **Un dossier d'import absent faisait tomber la commande.** La ligne d'erreur
   « Le dossier d'import n'existe pas » était bâtie à la main, sans `lignes` ni
   `import_id` : la commande qui met le rapport en tableau rendait *Undefined
   array key « lignes »*. Sans conséquence tant que le dossier existait ; une
   erreur 500 sur l'écran des points de vente dès que l'écran s'est mis à
   ranger. Elle passe par `resultat()`, comme toutes les autres.

Deux épreuves de plus — deux relevés du même jour ne montrent pas les points en
double, et l'écran range le dépôt avant de comparer. **Quatorze en tout** dans
`PointsDeVenteDuPortailTest`. Suite entière : **1 160 épreuves, 1 160 passantes,
4 628 vérifications.**

**Sur les données réelles** : relevé du 31/08, `FACTURATION SIEGE` (#12) et
`FACTURATION TEST 2` (#19) dans Selflow, **`teste` à créer**, `hgf` toujours
inconnu du portail.

#### « Rechargez dans une minute » — ce qu'il ne fallait pas dire

Le propriétaire du projet, devant le message du bouton : *« normalement cela
doit se faire rapidement, pas de temps d'attente, voilà ce que je veux
éviter »*. Il a raison : demander à quelqu'un de guetter puis de recharger, ce
n'est pas une fonctionnalité, c'est une corvée déguisée.

Le relevé ne peut pas être plus rapide — il ouvre un vrai navigateur sur le
portail de la DGI, et cela dure des dizaines de secondes. Ce qui peut
disparaître, c'est **l'attente à tenir**. Le bouton lance désormais le relevé en
arrière-plan puis **surveille** : toutes les trois secondes, l'écran redemande
`admin.pdv.etat_du_portail`, qui range le dépôt et rend une **empreinte** — la
date du relevé, et pour chaque point déclaré son nom et le point de vente qui
lui correspond. Dès qu'elle change, la page se recharge d'elle-même, et les
points manquants apparaissent avec leur bouton.

| Détail | Raison |
|---|---|
| Une empreinte, et non un simple compteur | un relevé qui rapporte un point de plus, comme une reprise qui vient d'en créer un, changent l'écran ; un relevé identique ne le fait pas clignoter pour rien |
| Trois secondes, quarante essais | deux minutes au plus : un relevé qui n'arrive pas rend la main plutôt que d'interroger le serveur sans fin |
| Le lancement répond en JSON | le bouton attend une réponse qu'il peut lire, pas une page entière. Éteint, le scraper le dit en 409 |
| Le formulaire reste un vrai formulaire | sans JavaScript, il part normalement, le relevé tourne quand même, et le prochain affichage le rangera |

Deux épreuves de plus — l'empreinte change quand le dépôt apporte un point, et
le lancement répond au bouton sans page à recharger. **Seize en tout.** Suite
entière : **1 162 épreuves, 1 162 passantes, 4 636 vérifications.**

**Et le sablier ne s'affichait pas.** Une apostrophe : `'… mise à jour de
l'écran…'`, en guillemets simples, fermait la chaîne au milieu du mot. Le
navigateur rejetait **tout le pilote** sur une erreur de syntaxe, le clic
repartait donc en formulaire classique — et rendait le message d'attente qu'on
venait justement de retirer. Rien ne le signalait : une épreuve qui vérifie que
le script est *dans la page* ne dit pas qu'il *s'exécute*.

Le script est désormais relu mécaniquement : on l'extrait de la vue, on
remplace les expressions Blade par des valeurs plausibles, et `node --check` le
lit. C'est la seule façon de tenir un script inline sans navigateur sous la
main, et cela prend dix secondes.

### Lot 25 — Le relevé se charge à la main — **TERMINÉ le 31/08/2026**

#### Ce qui a été cherché d'abord

Le propriétaire du projet a signalé une erreur au bouton *« Lancer la
correction »*. Recherche faite, **le bouton n'a pas de défaut** : le portail de
l'entreprise déclare deux points de facturation — `FACTURATION SIEGE` et
`FACTURATION TEST 2` —, et le garde-fou d'ambiguïté de `CorrectionFneService`
(`count($declares) === 1`) rend la main sans rien toucher. C'est voulu : la
machine ne choisit pas le point de vente à la place de qui a établi la pièce.

Ce qui trompait était ailleurs. **Les deux points d'entrée du même bouton ne
portaient pas le même garde-fou** : l'écran des rejets masque le bouton quand le
portail déclare plusieurs points (`rejets.blade.php`), le pop-up de refus le
proposait toujours (`VenteControleur`). Le pop-up promettait donc un geste que
le service refusait, et l'utilisateur restait devant « aucune correction
automatique applicable », sans rien à faire de cette phrase.

#### S'abstenir n'est pas se taire

Trois issues ont été proposées au propriétaire du projet : corriger d'office
sur le nom le plus proche, masquer le bouton, ou **laisser choisir**. Il a
retenu la troisième le 31/08/2026 : *« le pop-up liste les noms déclarés et
l'utilisateur clique celui qui convient »*.

C'est la seule des trois qui ne déplace pas la frontière. La règle n'est pas
« il n'y a rien à faire quand le portail déclare deux points », elle est **la
machine ne choisit pas le point de vente à la place de qui a établi la
pièce**. Un humain qui clique un nom ne l'enfreint pas : il l'applique.

| Décision | Raison |
|---|---|
| Le pop-up sert la liste, il n'ouvre pas d'écran | le mécanisme des boutons de toast existait déjà (`{cle}_action`) ; un écran de plus à tenir pour trois boutons n'en valait pas le prix |
| Ce qui revient du navigateur est un **rang**, jamais un nom | le clic **désigne** une valeur que le rapprochement a écrite ; il ne peut pas en introduire une. Un formulaire forgé renomme au pire avec un nom que le portail déclare déjà |
| `CorrectionFneService` reverifie le nom de son côté | le contrôleur n'est pas le dernier point avant le renommage ; le service l'est |
| Cinq noms au plus dans le pop-up | un toast qui en aligne quinze ne se lit plus ; au-delà, le bouton renvoie à l'écran des rejets, qui porte le détail |
| Un rang périmé ne renomme rien | le rapprochement a pu être refait depuis que le pop-up s'est affiché ; appliquer d'après une liste ancienne renommerait sur un constat qui n'a plus cours |

`corriger()` prend désormais un troisième paramètre, `$choisi`, **que la machine
ne remplit jamais** : l'automatisme horaire continue de s'abstenir exactement
comme avant. Le renommage, la bascule sur un point existant et le renvoi des
pièces restent un seul chemin de code, quel que soit celui qui a décidé du nom.

*L'écran des rejets, lui, masque toujours la correction quand le portail
déclare plusieurs points. Le choix ne lui a pas été porté — il n'a pas été
demandé.*

`corrigerMaintenant` n'était couvert par **aucune épreuve** — seul `appliquer`,
le geste unitaire de l'écran, l'était. `BoutonCorrigerMaintenantTest` couvre
désormais neuf issues : un seul nom déclaré (renommé, pièce repartie), plusieurs
noms (la liste proposée, rien touché), chacun des deux noms cliqué, un rang qui
ne désigne rien, aucun relevé, rejet réseau, et deux fois le rejet d'autrui.

#### `LoadFileFne` — charger un relevé sans le scraper

Le scraper dépose ses relevés sur le serveur et le passage horaire les range.
Là où il ne tourne pas — poste sans Node, second facteur au portail —, **rien
ne pouvait entrer**, alors que l'entreprise a le fichier sous les yeux, exporté
depuis son espace FNE. `PointDeVenteControleur::LoadFileFne()` comble ce trou.

Elle **ne fait que cela** : lire le fichier déposé et écrire ses lignes. Aucune
entreprise n'est résolue, aucun login n'est vérifié, rien n'est comparé au
paramétrage — le rapprochement et la correction sont ailleurs et le restent.
Elle n'appelle pas `ImportPortailFneService`, qui garde son propre chemin pour
le scraper : les deux voies ne se gênent pas.

| Extension | Ce que le portail exporte | Modèle écrit |
|---|---|---|
| `.json` | la fiche de l'entreprise | `PortailFneFiche` |
| `.xlsx`, `.xls` | les points de facturation | `PortailFnePointFacturation` |

| Décision | Raison |
|---|---|
| Nomenclature **exigée** : `NCC_AAAAMMJJ.<ext>` | le NCC vient du nom du fichier, et de lui seul ; un nom hors nomenclature est refusé plutôt que deviné |
| Le NCC est écrit dans chaque ligne, colonne `login` | la migration la décrit déjà comme « le login tel qu'il figure dans le nom du fichier — en pratique le NCC ». Aucune colonne nouvelle : le NCC a déjà la sienne |
| Mise à jour si la ligne existe, création sinon | demandé par le propriétaire du projet. La fiche n'existe qu'une fois par NCC |
| Les points se distinguent par **NCC + nom** | le NCC seul ne peut pas être la clé d'un classeur qui déclare plusieurs points : chaque ligne écraserait la précédente, et seul le dernier point du fichier subsisterait. Arbitré par le propriétaire du projet le 31/08/2026 |
| Le dossier de dépôt porte le **NCC** | les relevés d'une entreprise se retrouvent d'un coup d'œil ; le passage horaire n'y descend pas et ne relit pas ce qui est déjà enregistré |
| Une ligne `portail_fne_imports` est écrite | la clé étrangère `import_id` des deux tables n'est pas nulle. Retrouvée à l'empreinte du fichier, qui est unique : recharger le même fichier réutilise sa ligne au lieu d'échouer |

**Ce qui n'est pas fait, et qui se verra** : `entreprise_id` reste nul sur les
lignes chargées à la main — aucun login n'étant vérifié, rien ne rattache le
relevé à une entreprise. Les écrans qui filtrent par entreprise (points de
vente, rejets) ne montrent donc pas ces lignes. C'est la fonctionnalité
demandée, sans un pas de plus ; le rattachement est à demander s'il est voulu.

`LoadFileFneTest` suit le trajet complet — du dépôt par le navigateur à la
ligne en base — sur l'export réel `FNE.xlsx` versé au dépôt, plus le JSON, le
`.xls`, la mise à jour sans doublon des deux côtés, deux NCC qui ne se mêlent
pas, le dossier au nom du NCC, un nom hors nomenclature, une date impossible,
un PDF et un JSON illisible.

**Une épreuve de garde a mordu** : `HabilitationsTest` exige que toute route des
espaces admin et caissier soit classée. `admin.pdv.load_file_fne` ne l'était
pas — rangée sous `gestion_pdv`, comme les trois autres routes du portail.

Vingt épreuves de plus (9 + 11). Suite entière : **1 183 épreuves, 1 183
passantes, 4 729 vérifications.**

### Lot 26 — Le scraper part au refus, et le constat cesse de mentir — **TERMINÉ le 31/08/2026**

Le propriétaire du projet renomme un point au portail à 20 h 44, relance sa
facture, et l'écran répond : *« le portail confirme les valeurs envoyées : la
cause du refus est ailleurs »*. Trois défauts distincts étaient derrière cette
seule phrase.

#### 1. Deux relevés du même jour étaient invisibles

`diagnosticEstAJour()` jugeait la fraîcheur des points sur leur **date**. Le
relevé de 20 h 45 portait la même date que celui du matin : le rapprochement
s'est cru à jour et **n'a jamais refait le constat**. Il aurait continué
d'affirmer « le portail confirme » indéfiniment.

Le fichier portait pourtant déjà la remarque, appliquée à la fiche et pas aux
points : *« deux relevés du même jour existent »*. Les points comparent
désormais l'identité du jeu (`points_id`, l'import qui l'a écrit), pas sa date.
Un diagnostic antérieur à ce champ est tenu pour dépassé et refait une fois.

**L'épreuve a été vérifiée à l'envers** : correctif retiré, elle rend
`'concordant'` là où on attend `'ecart'` — mot pour mot ce que l'écran
affichait. Une première version passait des deux côtés : elle créait une fiche
neuve à chaque relevé et empruntait donc l'autre chemin. Le second relevé doit
être **sans fiche** — c'est le cas réel, le tableur bouge et la fiche non.

#### 2. Le bouton rangeait le dépôt sans jamais aller le chercher

Quand le rapprochement dit « concordant » sur un champ que la DGI vient de
refuser, les deux ne peuvent pas avoir raison — **et le message de la plateforme
fait foi**. Il ne reste qu'une explication : le relevé décrit le portail d'avant.
« Lancer la correction » lance désormais une relève dans ce cas précis, et ne
le promet que s'il a pu la lancer.

#### 3. Un seul écran sur six réveillait le scraper

*« Dès qu'il y a une erreur le scraper se met en action »*, demandé le
31/08/2026. Le geste vivait dans `VenteControleur` : une facture normalisée
depuis cet écran-là déclenchait un relevé, **et rien d'autre**. Ni un bordereau
d'achat, ni une normalisation par lot, ni le tableau de bord FNE, ni le job en
file. Six chemins mènent à un refus, un seul agissait.

Le lancement est descendu dans `FneRejet::consigner()`, le point par lequel ils
passent tous — ce qui s'y trouve est vrai partout et n'est à maintenir qu'une
fois.

| Décision | Raison |
|---|---|
| Cause DGI seulement | une coupure réseau veut dire que la DGI n'a pas vu la pièce : le portail n'a rien à en dire, et une session sur l'espace FNE se paie d'une connexion avec le mot de passe du client |
| Un verrou de cache par login, deux minutes | une normalisation par lot consigne vingt refus en dix secondes ; sans lui, vingt navigateurs s'ouvriraient pour lire vingt fois la même chose |
| Court, deux minutes | qui corrige son portail puis relance doit obtenir un relevé neuf, pas celui d'avant |
| La demande en file reste ouverte | elle est la trace, et le filet si le lancement échoue |
| Ne lève jamais | consigner un rejet ne doit pas échouer parce que le scraper est mal armé. Le rejet compte, le relevé est un confort |

`ReleveDesQuUnePieceEstRefuseeTest` : refus sur une vente, refus sur un achat,
vingt refus qui n'ouvrent qu'un relevé, coupure réseau qui n'ouvre rien, scraper
éteint qui consigne quand même.

#### Ce que la soirée a prouvé en production

Le pop-up de choix livré à 20 h 30 a servi à 20 h 50 : deux points déclarés,
l'automatisme s'abstient, l'utilisateur clique « FACTURATION SIEGES », le point
est renommé et la facture VTE-310826-005 repart — **certifiée
`1864699A26000000088`**.

Sept épreuves de plus (2 + 5). Suite entière : **1 190 épreuves, 1 190
passantes, 4 748 vérifications.**

### Lot 27 — Les factures reçues sortent de leur écran — **TERMINÉ le 07/09/2026**

#### La première facture réelle

`achats.js` lancé à la main sur le login `1864699A` a rapporté la **première
facture reçue réelle** — les relevés précédents ne portaient que des jeux de
démonstration :

| | |
|---|---|
| Référence FNE | `1431650A26000000588` |
| Émetteur | CENTRE IVOIRIEN D'ARCHIVAGE NUMERIQUE, NCC `1431650A` |
| Date | 04/09/2026, `invoice` / `normal`, payée en espèces |
| Montant | 244 000 F HT = 244 000 F TTC, **TVA nulle** — exonération conventionnelle (`TVAC`) |
| Lignes | Tilapia 500/800 20KG ×4 @ 11 000 · Poisson Thon 30 KG ×10 @ 20 000 |

La forme correspond trait pour trait à ce que `ImportFacturesRecuesService`
attend. La chaîne complète — relevé, dépôt, import, écran — a donc été éprouvée
de bout en bout sur une pièce que la DGI détient réellement.

#### La clé d'API d'un tiers voyageait avec la facture

Le portail joint à chaque facture reçue la **fiche entière de l'entreprise qui
l'a émise** : sa clé d'API en clair (`apiKey`, avec `isApiKeyEnabled: true`), sa
référence bancaire, son solde de compte, ses soldes de stickers. Rien n'a été
demandé, rien ne s'en sert, et `contenu_brut` les conservait indéfiniment — de
quoi facturer à la place du fournisseur.

`SECRETS_DE_L_EMETTEUR` écarte ces dix champs du bloc `company` avant l'écriture.
Le fourre-tout garde sa raison d'être — un champ nouveau du portail ne doit pas
être perdu en silence — mais pas de quoi usurper un tiers. Le NCC, le nom et le
RCCM, qui servent au rapprochement, restent intacts. La ligne déjà écrite le
07/09 à 11 h 05, avant le correctif, a été nettoyée en base. **Rien n'était parti
au dépôt** : `storage/app/.gitignore` ignore tout, `identifiants.json` aussi.

#### Ce que le portail ne dit pas, et qu'on a failli croire

Le relevé porte `clientEstablishment: "CIAN SIEGE"` et `clientPointOfSale:
"FACTURATION SIEGE"` — et « FACTURATION SIEGE » **figure dans nos propres points
de facturation**. La tentation était de lire là le point de vente de la facture.

`FneService.php:193-194` tranche : quand c'est **nous** qui émettons, Selflow
envoie `pointOfSale` = le nom de *notre* point de vente et `establishment` = *notre*
raison sociale. Le portail les restitue préfixés `client*` dans la liste des
pièces reçues. Ces deux champs décrivent donc **l'émetteur**, et la ressemblance
est fortuite : « FACTURATION SIEGE » est un nom que deux entreprises emploient.

**Le portail ne dit pas à quel site de chez nous une facture reçue se rattache.**
La seule source honnête est l'achat auquel on la rapproche.

#### Les deux écrans

Les factures relevées n'étaient visibles que sur `/admin/achats/factures-recues`,
un écran séparé que rien n'obligeait à ouvrir. Elles entrent désormais là où l'on
regarde ses achats tous les jours :

| Écran | Ce qui change | Le filtre par site |
|---|---|---|
| `/admin/achats/factures` | les pièces non rattachées ouvrent le tableau, en bleu, avec « Rattacher » (quand un achat correspond) ou « Rapprocher », « Écarter », et le sélecteur de site | **ne s'applique pas** — l'écran ne totalise rien, et les masquer les rendrait invisibles partout |
| `/admin/fne/factures`, onglet Achats → « Factures Reçues » | mêmes pièces, badgées « Portail DGI » ; un achat retrouvé au portail est badgé « Rapprochée » et porte le numéro FNE du fournisseur | **s'applique au site affecté** — l'écran somme le HT et le TTC, et y verser des montants sans site fausserait le total de ce site |

Deux règles différentes, et voulues : c'est la présence d'un total qui les
sépare.

**Aucun doublon.** Une facture du portail rattachée à un achat n'est pas rendue :
c'est le même document, et le compter deux fois gonflerait le total TTC. C'est la
ligne de l'achat qui le porte.

**Aucune colonne gelée touchée.** `achats.numero_fne` et `achats.normalise`
veulent dire « Selflow a émis cette pièce et la DGI l'a certifiée ». Une facture
reçue a été certifiée par le fournisseur : le numéro FNE affiché vient de
`portail_fne_factures_recues.reference`, lu à l'affichage, jamais écrit dans
`achats`. Une épreuve le vérifie.

#### Le site d'une facture reçue — demandé le même jour

« Le point de vente Selflow est aligné sur la DGI, maintenant la facture achat
doit être associée au PDV dans Selflow. » L'alignement des noms règle nos
**émissions** ; il ne donne pas de site à une pièce **reçue**, pour la raison
dite plus haut. La colonne porte donc **notre** décision, jamais le relevé —
`2026_09_07_000001`, `portail_fne_factures_recues.point_de_vente_id`, nullable.

Trois façons de la remplir, toutes sûres :

| Quand | Qui décide | Où |
|---|---|---|
| l'entreprise n'a qu'un site | rien à trancher | `ImportFacturesRecuesService` |
| la facture rejoint un achat | l'achat, c'est la même pièce | `FactureRecueControleur::rattacher()` |
| plusieurs sites, pas d'achat | l'utilisateur, par un sélecteur | `::affecter()` |

Nulle est une réponse : « personne n'a encore décidé ». Le site actif de celui
qui regarde l'écran a été écarté comme source — la même facture serait rangée
ailleurs selon qui la consulte, et une charge sous le mauvais établissement
fausse le résultat par site sans que rien ne le signale.

Un relevé ne défait pas une affectation faite à la main. Le rattachement, lui,
l'écrase : il sait de quel achat il s'agit, ce que le choix antérieur ignorait.

Conséquence sur l'écran FNE : un site précis rend maintenant **ce qui lui est
affecté**, au lieu de ne rien rendre. Les pièces sans site restent sur « Tous ».

DC-KNOWING CGA ayant cinq points de vente, ses cinq factures relevées affichent
le sélecteur — c'est le comportement voulu, non un défaut.

#### « Écarter » était une porte à sens unique

Le 07/09/2026 à 12 h 35, la seule facture réelle du dossier a disparu de tous les
écrans. Cause : le bouton « Écarter », une icône œil-barré sans confirmation,
cliqué pour supprimer la pièce. Écarter la masque partout — c'est voulu — mais
**rien ne permettait de revenir** : « Détacher » ne s'affiche que pour une pièce
rattachée à un achat, et l'écran ne proposait rien d'autre. Il a fallu interroger
la base pour comprendre pourquoi la facture n'apparaissait plus.

Une charge que la DGI détient ne doit pas pouvoir quitter les écrans d'un clic
sans recours. Deux correctifs :

- `::reintegrer()` et son bouton « Remettre dans la liste », sur le filtre
  « Écartées ». Le statut rendu est celui d'origine — `orpheline` sans NCC
  d'émetteur, `a_rapprocher` sinon : présenter comme rapprochable une pièce dont
  aucun fournisseur ne peut être retrouvé serait mentir ;
- une confirmation sur les deux boutons « Écarter », qui dit ce que le geste
  fait et où le défaire.

#### « Voir » et « Télécharger » restaient vides

Nos pièces certifiées reçoivent une adresse toute faite : la réponse de
certification met dans `token` l'URL entière, que `fichier_fne_pdf_url` et
`qr_code_data` recopient — `…/fr/verification/01a05e78-…`.

`/ws/invoices?listing=received` n'en donne pas : il rend le `token` nu,
`01a06bf8-8e1a-7000-8650-7ae311524dfc`, la même clé sans le chemin. Les deux
boutons restaient donc inertes sur une pièce pourtant consultable.

`PortailFneFactureRecue::urlDeVerification()` reconstruit l'adresse — hôte de la
configuration API, moins `/ws`, plus `/fr/verification/<token>`. Un token déjà
complet n'est pas préfixé deux fois ; sans token, aucun lien n'est inventé.

**Rien ne part à la DGI** : c'est un lien d'affichage bâti sur ce que la
plateforme a déjà rendu. `FneService`, `QrCodeFneService` et `qr_code_data` ne
sont ni lus ni écrits.

**Ce qui n'a pas pu être prouvé.** La page de vérification est une application
cliente : les trois adresses essayées — celle de la facture reçue, celle d'une de
nos ventes certifiées, et un identifiant nul de contrôle — rendent toutes 200 sur
63 051 octets identiques. Le code HTTP ne dit donc rien de la validité du token,
et le contenu n'arrive qu'après exécution du script. Le motif d'URL est celui que
la DGI a elle-même produit pour nos pièces, et le token vient de son propre
relevé : **un clic sur le bouton le confirmera, une requête depuis le serveur
non.**

#### La facture reçue se lit comme un document

Les boutons ne menaient qu'à la page de vérification de la DGI : bon pour
authentifier, inutile pour lire — une application cliente, hors de Selflow, dont
rien ne reste au dossier. Demandé le 07/09/2026, après téléchargement du PDF
`1431650A26000000588_2026-09-04.pdf` depuis le portail.

`FactureRecueControleur::imprimer()` et
`Vues/fne/facture-recue-impression.blade.php` rendent la pièce : émetteur et
destinataire, lignes, totaux, TVA déductible ou non, et un bloc de vérification
portant le code QR de l'adresse DGI.

**Hors de `Vues/factures/`, et c'est le point.** Ce dossier est gelé et porte les
documents que **Selflow émet** ; ses blocs de certification attestent que
l'application a établi la pièce. Une facture reçue a été établie par le
fournisseur et certifiée par la DGI. Sous le même gabarit, la copie se donnerait
pour l'original — un fac-similé de pièce fiscale. D'où une vue à part, un bandeau
qui dit ce que le document est, et un code QR dont la légende précise qu'il mène
à la pièce de la DGI et **n'atteste rien de ce document-ci**. Deux épreuves le
vérifient.

`QrCodeFneService` est appelé, jamais modifié : encoder un jeton en image est son
usage prévu.

#### Les quatre pièces de démonstration retirées

`portail_fne_factures_recues` portait quatre factures de jeu — FOURNITURES DEMO
SARL (×2), GRANDS MOULINS DEMO, COOPERATIVE AGRICOLE DEMO — entrées par l'import
du 31/08. Supprimées sur demande du propriétaire, avec leurs quatre lignes.
Aucune n'était rattachée à un achat, et **aucun relevé sur disque ne les
contient** — vérifié avant la suppression : les trois fichiers antérieurs au
07/09 portent `factures: []`. Elles ne reviendront pas.

Reste en base la seule pièce réelle, `1431650A26000000588`, affectée à
**FACTURATION SIEGE**.

#### Épreuves

Vingt-huit de plus — vingt-trois dans `FacturesDuPortailAuxEcransAchatsTest`, quatre
dans `ImportFacturesRecuesTest` (secrets de l'émetteur, affectation du site,
choix laissé à l'utilisateur, affectation manuelle préservée), plus la route
`factures_recues.affecter` et `factures_recues.reintegrer` classées dans
`Habilitations` — que `HabilitationsTest` a réclamées aussitôt, comme prévu.
Suite entière : **1 218 épreuves, 1 202 passantes, 4 788 vérifications.**

Les **16 échecs sont antérieurs à ce lot** — vérifié en rejouant la suite sur
`HEAD` sans les modifications : compte et tests identiques. Ils se répartissent en
deux familles, toutes deux à traiter :

| Famille | Tests | Symptôme |
|---|---|---|
| Accent perdu | `CycleFneTroisCasTest` (4), `EcranRejetsFneTest` (1) | attendu `FACTURATION SIÈGE`, obtenu `FACTURATION SIEGE` |
| Chargement à la main | `LoadFileFneTest` (11) | 500 sur l'envoi, ou clé `succes` absente en session |

Elles sont portées en section 6.

### Lot 28 — Le relevé des achats toutes les cinq minutes, et par l'API — **TERMINÉ le 08/09/2026**

Demandé par le propriétaire du projet le 08/09/2026 : *« pour le scrapping achat
mettre une logique que le scrapping se lance CHAQUE 5min et ajoute la route dans
route /api »*.

#### Ce qui a été dit avant de le faire

Le lot 22 avait **retiré** le rendez-vous quotidien d'`achats.js` (04:15) pour
une raison précise : il rouvrait une seconde session sur le portail de la DGI
pour une entreprise que `fne.js` venait de relever, et c'est la connexion qui
coûte — répétée, elle fait bloquer un compte. À cinq minutes, ce sont **288
connexions par jour** avec le mot de passe du client, là où une facture reçue est
un fait acquis qui ne changera plus.

La demande a été maintenue, elle est donc appliquée. Ce qui a changé par rapport
au lot 22, c'est l'attente : un fournisseur qui certifie une facture à 9 h
n'apparaissait pas avant le lendemain, puisque `fne.js` ne va au portail que sur
un refus ou à 02:30.

**Le rythme n'est pas écrit en dur.** Deux réglages le commandent, pour ralentir
ou éteindre sans livrer une version le jour où un compte se bloque :

| Variable | Défaut | Ce qu'elle fait |
|---|---|---|
| `PORTAIL_FNE_SCRAPER_ACHATS_ACTIF` | `true` | éteint la tâche **sans toucher** au reste du scraper |
| `PORTAIL_FNE_SCRAPER_ACHATS_MINUTES` | `5` | le pas, borné à 60 — au-delà, la minute d'une expression cron ne veut plus rien dire |
| `PORTAIL_FNE_SCRAPER_SCRIPT_ACHATS` | `SCRAPER-PORTAIL-FNE/achats.js` | le chemin du script |

#### Les deux tâches vont ensemble

Une seule aurait été inutile : relever toutes les cinq minutes en rangeant toutes
les heures aurait interrogé le portail 288 fois pour un écran qui ne bouge qu'au
ramassage. `portail-fne:importer-achats` passe donc au même pas.

**Décalé de deux minutes**, et c'est tout l'intérêt : le scraper ouvre un
navigateur et met des dizaines de secondes à déposer son fichier. Ramasser à la
même minute lirait toujours celui du passage précédent, et l'écran aurait un tour
de retard en permanence.

```
*/5   * * * *   node achats.js --tous
2-59/5 * * * *  php artisan portail-fne:importer-achats
```

Vérifié sur ce poste par `schedule:list` : les deux lignes y figurent.

#### Les deux routes d'API

`routes/api.php`, derrière `hub.token` comme le reste — ces routes ouvrent une
session sur le portail de la DGI avec le mot de passe d'un client et rendent des
données fiscales nominatives.

| Route | Ce qu'elle fait |
|---|---|
| `POST /api/portail-fne/achats/relever` | lance un relevé détaché ; `company_id`, `X-Company-Id` ou `login` pour une entreprise, rien pour toutes |
| `GET /api/portail-fne/achats` | l'état de la chaîne : interrupteurs, pas, relevé en cours, vingt derniers relevés, compte des pièces par état |

`POST` et non `GET` pour le lancement : un `GET` serait rejoué par un cache, un
préchargement de navigateur ou un lien visité deux fois — et chaque rejeu est une
session chez la DGI.

**La réponse dit que le relevé est parti, pas qu'il a réussi** — d'où `202`. Une
requête HTTP qui attendrait le navigateur tiendrait la connexion des dizaines de
secondes, et un client qui renonce ne l'arrêterait pas pour autant.

Quatre codes, et chacun répond à une question différente :

| Code | Ce qu'il veut dire |
|---|---|
| `202` | parti en arrière-plan |
| `429` | un relevé est déjà en route ; il ne sera pas doublé |
| `503` | la chaîne est **éteinte** — et non en panne, ce qu'un 500 ferait croire |
| `404` / `422` | entreprise introuvable, ou sans NCC — et surtout **pas** un passage complet sur tout le parc |

Ce dernier point est le piège évité : rendre « tous les logins » quand un
identifiant est faux aurait ouvert des sessions que personne n'a demandées.

#### Le verrou

`ScraperPortailFneService::lancerAchats()` pose un verrou de cache pour la durée
du pas, par login — le passage complet et une entreprise nommée ont des clés
distinctes. Il sert à l'appel à la demande : une route appelée en boucle
ouvrirait autant de navigateurs. Le passage planifié n'en a pas besoin,
`withoutOverlapping()` le garde déjà, et les deux ne se gênent pas puisque le
planificateur ne passe pas par le service.

`verrouAchats()` est public pour que le contrôleur distingue les deux « faux »
que `lancerAchats()` rend indifféremment : « déjà en route », qui est une
réponse, et « lancement impossible », qui est une panne. Répondre la même chose
aux deux ferait chercher un défaut là où un verrou fait son travail.

#### Ce qui n'a pas bougé

Le périmètre gelé n'est pas traversé : ni `FneService`, ni les colonnes `fne_*`,
ni les vues de certification. Le relevé reste un **constat** — aucun achat créé,
aucune écriture, aucun fournisseur inventé —, et l'appeler par HTTP ne change
rien à cette règle du lot 16.

`fne.js` continue de relever les factures reçues dans la session qu'il ouvre :
c'est gratuit, on y est déjà. La nouvelle tâche s'ajoute, elle ne remplace pas.

#### Épreuves

`ReleveDesAchatsChaqueCinqMinutesTest` — **17 cas**, tous passants : le pas du
planificateur et son réglage, le décalage du ramassage, l'interrupteur qui
n'éteint que cette ligne, le verrou et ses clés distinctes, et les huit réponses
de l'API dont l'isolation entre entreprises.

Les quatre épreuves du planificateur ont demandé un détour : `routes/console.php`
est lu au démarrage, quand le scraper est encore éteint dans `phpunit.xml`. Elles
reposent un ordonnanceur neuf, **oublient l'instance retenue par la façade** —
sans quoi `Schedule::command()` écrit toujours dans celui d'origine — et relisent
le fichier.

Suite entière : **1 235 épreuves, 1 219 passantes, 4 836 vérifications**. Les
**16 échecs sont les mêmes qu'au lot 27**, aux mêmes tests — accent perdu (5) et
chargement à la main (11). Ce lot n'en ajoute aucun et n'en corrige aucun : ils
restent portés en section 6.

#### Le journal de la chaîne — demandé le même jour

*« Ajoute des logs pour chaque fois, voir les infos en cas d'erreur. »* À un
passage toutes les cinq minutes, c'est ce qui manquait le plus : le journal
existant empilait 288 blocs par jour sans qu'aucun repère ne les sépare.

**Ce qui n'allait pas, relevé dans le fichier réel :**

| Constat | Conséquence |
|---|---|
| aucune ligne de `fne.js` / `achats.js` n'était horodatée | `Le système ne peut trouver le fichier node-qui-n-existe-pas`, treize fois, sans dire quand ni pour quel login |
| les journaux PHP partaient dans `laravel.log` (30 Mo, tout mélangé) | une panne se lisait à moitié dans un fichier, à moitié dans l'autre |
| le service se taisait quand il renonçait | « rien ne s'est passé » — la panne la plus difficile à diagnostiquer, puisqu'on cherche un défaut là où un interrupteur est à zéro |
| l'import ne disait rien quand tout allait bien | donc rien non plus sur ce qu'il avait vu juste avant une erreur |
| le contrôleur d'API ne traçait rien | impossible de savoir qui avait déclenché un relevé, ni pourquoi il avait été refusé |

**Ce qui a été posé :**

- un canal `portail_fne` dans `config/logging.php`, où écrivent le service,
  l'import et le contrôleur d'API ;
- `journaliser()` dans `fne.js`, exporté et employé par les deux scripts —
  **une seule implémentation**, la règle du projet : deux copies, et c'est
  l'une des deux qui gardera le défaut. 50 appels à `console.*` convertis ;
- le format de Laravel repris à l'identique — `[date] canal.NIVEAU: message` —,
  de sorte qu'un `grep ERREUR` rende les deux moitiés de l'histoire ;
- les renoncements journalisés au même titre que les lancements, en nommant
  l'interrupteur en cause ;
- les chemins de Node et du script dans la ligne de lancement : le processus
  est détaché, son échec n'arrive jamais jusqu'à PHP, et sans eux la ligne
  suivante est un « fichier introuvable » qui ne dit pas lequel ;
- `resultat()` de l'import journalise **tous** les verdicts — c'est le goulot
  par lequel passent tous les chemins, donc une sortie ajoutée demain sera
  tracée sans que personne y pense. Niveau `error` pour un fichier illisible,
  `info` pour un import, `debug` pour l'ordinaire, qui revient 288 fois par jour.

#### Deux fautes commises en chemin, et corrigées

**Un seul fichier pour tout : essayé, cassé.** Verser les journaux PHP dans
`portail-fne.log`, là où `appendOutputTo` verse déjà la sortie des tâches,
paraissait évidemment mieux. Sous Windows, la redirection `>>` verrouille le
fichier : Monolog ne peut plus l'ouvrir et lève « Resource temporarily
unavailable » — **au moment précis où la commande journalisait**.
`portail-fne:importer-achats` se cassait ainsi lui-même. Un journal qui casse ce
qu'il observe est pire que deux journaux : la sortie des tâches va désormais dans
`portail-fne-sorties.log`, le canal PHP dans `portail-fne.log`, et les deux
portent le même format — un `sort` les remet dans l'ordre.

**Deux horloges.** Le premier essai a daté le même passage `12:55` côté Node et
`11:56` côté PHP : Laravel journalise sous `config('app.timezone')`, qui vaut
`UTC`, quand le poste est à l'heure locale. Fusionner les deux fichiers faisait
paraître le ramassage antérieur au relevé qui l'avait nourri. `instant()` rend
désormais de l'UTC, par `toISOString()`.

**Et le journal du poste était pollué par les épreuves.** Treize lignes de
« node-qui-n-existe-pas » y ont été retrouvées, au milieu des passages réels :
une épreuve qui allume le scraper pour observer sa décision lance un vrai
processus. `PORTAIL_FNE_JOURNAL` et `PORTAIL_FNE_SORTIES` sont épinglés dans
`phpunit.xml`, comme `PORTAIL_FNE_DOSSIER_IMPORT` l'avait été le 31/08 pour la
même raison.

**Et une troisième, la vraie racine, trouvée en jouant la suite entière.** Neuf
appels d'API rendaient 500 sur « Permission denied », alors qu'isolés ils
passaient. Un processus lancé par `popen` **hérite des descripteurs ouverts du
parent** ; sous Windows, celui que Monolog tient sur le journal part avec lui et
reste verrouillé tant que l'enfant vit. Un scraper qui tourne trois minutes rend
donc le journal de toute l'application inouvrable pendant trois minutes, par un
processus qui n'écrit même pas dedans.

`detacher()` referme le canal — `Log::forgetChannel('portail_fne')` — juste avant
d'engendrer l'enfant : il n'hérite plus de rien, et le prochain appel rouvre.

S'y ajoute une ceinture, qui ne remplace pas la correction : les trois `tracer()`
— service, import, contrôleur d'API — avalent leur propre échec. On perd une
ligne, jamais l'opération. **Posée seule, elle avait masqué le problème** : les
500 devenaient des lignes manquantes, plus discrètes et pas plus saines. Elle
reste juste parce qu'un journal ne doit pas casser ce qu'il observe.

Sept épreuves de plus — **24 dans `ReleveDesAchatsChaqueCinqMinutesTest`**, qui
écrit dans **son propre fichier** : celui de la suite est ouvert par d'autres
épreuves et par les processus qu'elles détachent.

### Lot 29 — La facture reçue s'affiche sans passer par le portail — **CHAÎNE D'AFFICHAGE PRÊTE, TÉLÉCHARGEMENT EN ATTENTE**

Demandé par le propriétaire du projet le 08/09/2026, PDF réel à l'appui —
`1431650A26000000588_2026-09-04.pdf`, qu'il avait dû exporter à la main du
portail : *« comment faire pour que cette facture s'affiche aussi directement
sans passer par le bouton exporter »*.

#### Ce qui existait, et pourquoi ça ne suffisait pas

| Bouton | Ce qu'il ouvrait |
|---|---|
| 👁 / ⬇ | la page de vérification de la DGI — une application cliente, bonne pour authentifier, pas pour lire |
| **Voir** | la pièce **reconstruite** par Selflow depuis le relevé |

Aucun des deux ne rendait **le** document : celui que le fournisseur a établi et
que la plateforme a certifié.

#### Ce qui est livré

| Pièce | Rôle |
|---|---|
| Migration `2026_09_08_000001` | `portail_fne_factures_recues.fichier_pdf` — un **nom**, pas un chemin absolu : le dossier d'import se déplace d'un poste à l'autre |
| `cheminDuPdf()` / `pdfDisponible()` | résolvent le fichier, `basename` compris, et rendent `null` s'il a disparu |
| `FactureRecueControleur::pdf()` | sert le PDF `inline`, derrière `siennes()` et le droit `factures_achat` |
| Les deux écrans | **Voir** ouvre le document quand il est là, la reconstruction sinon — le libellé le dit |

Le fichier passe par le contrôleur et non par une URL publique : le dossier
d'import est hors de `public/`, et ces pièces portent des données fiscales
nominatives. `inline` et non `attachment` : la demande est de **voir**.

#### Le défaut trouvé avant qu'il ne morde

**Le document arrive après le relevé, sans le changer.** L'import répondait donc
« inchangé », court-circuitait le rangement, et la colonne ne se serait *jamais*
remplie pour une pièce déjà connue — c'est-à-dire pour toutes celles d'avant ce
lot. `rattacherLesPdf()` rattrape ce cas sur le chemin « inchangé », et ne
regarde que les pièces sans document.

Éprouvé en réel sur la seule facture du dossier :

```
1864699A_20260908.json | inchangé | Identique au relevé du 07/09/2026 :
                                    1 facture(s) déjà connues.
                                    1 document(s) de la DGI rattaché(s).
```

#### Ce qui manque

**L'adresse que le portail appelle quand on clique « Exporter ».** La
reconnaissance du 27/08 n'a capté que `/ws/auth/me`, `/ws/bootstrap`,
`/ws/invoices` et `/ws/invoices/details` : elle a été faite quand l'entreprise
n'avait aucune facture reçue, donc rien à exporter. Le propriétaire du projet la
relèvera dans l'onglet Réseau de son navigateur.

Une fois connue, `achats.js` téléchargera le document dans la session qu'il ouvre
déjà, **une seule fois par pièce** — une facture certifiée ne change plus —, sous
`storage/app/portail-fne/achats/pdf/<reference>.pdf`. Le reste de la chaîne est
posé et éprouvé.

En attendant, un PDF déposé à la main dans ce dossier est rattaché au ramassage
suivant : c'est ainsi que la pièce `1431650A26000000588` s'affiche aujourd'hui.

#### Épreuves

`LeDocumentDeLaDgiSAfficheTest` — **9 cas** : le document servi, le repli sur la
reconstruction quand il manque, le fichier disparu du disque, le `../` dans le
nom de fichier, la facture d'une autre entreprise (404), les deux écrans, et le
rattachement d'un PDF arrivé après le relevé.

Suite entière : **1 251 épreuves, 1 235 passantes, 4 871 vérifications**. Les
**16 échecs restent ceux du lot 27**, aux mêmes tests.

### Lot 30 — Les écrans de vente cessent de mentir — **TERMINÉ le 25/09/2026**

Six constats du propriétaire, relevés le même jour sur la démonstration en
ligne et sur le poste local, et l'état des lieux qui les accompagne :
`ETATS DES LIEUX.txt`, à la racine du dépôt.

#### 30.1 Treize adresses construites avec le numéro de ligne

« Imprimer le reçu » et « Créer la facture » ouvraient une page **404 (Not
Found — introuvable)**. La cause est celle du modal des clés FNE du lot 25, au
même endroit du raisonnement : `Vente`, `Achat` et `BonLivraison` portent
`IdentifiantOpaque`, et leurs adresses se lient par `uuid`. Treize appels les
construisaient avec `->id`.

Le défaut n'était pas propre à la mise en ligne — il était identique en local,
et il touchait bien plus que les deux boutons signalés :

| Fichier | Ce qui répondait 404 |
|---|---|
| `Vues/factures/vente.blade.php` ×3 | le reçu, la pièce liée, la conversion |
| `VenteControleur` ×2 | les redirections après conversion |
| `BonLivraisonControleur` | la redirection après création du BL |
| `FneDashboardControleur` ×6 | **tout** le tableau de bord FNE : « voir » et « normaliser » sur chaque ligne |

`FneDashboardControleur:764` n'est pas en cause : `PortailFneFactureRecue` ne
porte pas le trait et se lie bien par son numéro.

#### 30.2 Une file que personne ne servait

`QUEUE_CONNECTION=database`. La normalisation automatique appelle
`NormaliserFactureFne::dispatch()`, qui **dépose** le travail dans la table
`jobs` et rend la main. Sans `queue:work`, il y restait — indéfiniment. La case
« normaliser automatiquement » était cochée, l'écran affichait « En cours », et
rien n'était jamais parti à la DGI. La normalisation manuelle, elle,
fonctionnait : elle appelle `dispatchSync()`.

Pas de service permanent — l'hébergement mutualisé n'en admet pas. Le
planificateur vide la file chaque minute :

```php
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()->withoutOverlapping();
```

Le délai de certification devient donc d'une minute au pire. Les tentatives
restent celles que le travail déclare — trois, à trente secondes — et non
celles qu'on écrirait ici.

#### 30.3 « En cours » sur une pièce que personne n'envoyait

La colonne « Normalisée (DGI) » affichait une roue animée sur **toute** pièce
non certifiée. Trois états la remplacent :

| État | Quand |
|---|---|
| **Oui** | la plateforme a certifié |
| **Rejetée** | un `FneRejet` est ouvert : plus rien ne part tant que la cause n'est pas levée |
| **En cours** | la normalisation automatique est cochée : la pièce part au prochain passage |
| **En attente** | elle est décochée : rien ne partira tant que personne n'aura cliqué |

Même correction au tableau des achats, où le mensonge était plus grand encore :
une facture fournisseur ordinaire **ne part jamais** à la DGI — c'est le
fournisseur qui l'a certifiée. Elle affichait pourtant « En cours » pour
toujours. Seul le bordereau (BAPA) s'y normalise, et il attend la main.

#### 30.4 Une seule case, et une case qui ne commandait rien

Les deux réglages — factures, reçus — n'en font plus qu'un. Le reçu emprunte la
porte de la facture ; deux réglages pour une seule décision ne disaient pas
lequel commandait la pièce qu'on avait sous les yeux. **Les deux colonnes
restent en base** et restent lues séparément : une base peuplée avant ce jour
peut porter deux valeurs différentes, et elles doivent être respectées.

Le **BAPA** était une case à cocher. Vérification faite, `entreprises.bapa`
n'était lue **nulle part** ailleurs que dans le résumé de la page des
paramètres : le bordereau se choisit à la saisie de l'achat, par `type_facture`,
et c'est l'espace FNE de l'entreprise qui l'autorise. Décocher n'empêchait
aucun bordereau. Elle devient une information, qui dit où le réglage se trouve
vraiment. La colonne reste en base, et l'enregistrement n'y touche plus.

#### 30.5 Facture et reçu : une pièce, deux documents

**Ce lot traverse le périmètre gelé de la FNE**, et il faut le dire :
`type_piece` figure nommément parmi les colonnes gelées par `CLAUDE.md`. La
troisième exception s'applique — le propriétaire l'a demandé explicitement le
25/09/2026 — et la règle exige que la traversée soit signalée. Elle l'est ici,
et dans `ETATS DES LIEUX.txt`.

Ce qui change est ce que Selflow **établit** et **imprime**, jamais ce qu'il
**déclare** : la saisie ne propose plus « Facture » puis « Reçu » mais un seul
choix **« Facture + Reçu »**, et `type_piece` part à `facture` — la valeur que
la plateforme reçoit déjà. Un envoi, un code QR, un sticker, deux mises en
page. `FnePayloadTest` reste vert **sans avoir été retouché** : c'est le juge de
cette correction, et s'il avait fallu le modifier, la correction aurait été
mauvaise.

Une caisse qui choisissait « Reçu » se retrouvait sans facture. Le sens
reçu → facture reste en place pour les pièces déjà établies.

#### 30.6 Le tableau des factures

| Colonne | Avant | Après |
|---|---|---|
| Fichier DGI | un bouton « télécharger » | **deux documents** : la facture rendue par la plateforme, et le reçu normalisé |
| Reçu lié | le numéro de la pièce liée | *retirée* — le reçu n'est plus une seconde pièce |
| Fichier reçu | le ticket | *absorbée* par « Fichier DGI » |
| **Originale** | — | **nouvelle** : la facture et le reçu établis par Selflow |
| Actions | Voir, Reçu, pièce liée, télécharger, **modifier**, normaliser | **Normaliser, et rien d'autre** |

« Modifier » est retiré des factures. Il s'affichait tant que la pièce n'était
pas normalisée, c'est-à-dire **après** qu'elle avait pu être remise au client.
Les devis et bons de commande le gardent : ce sont des offres, elles se
reprennent.

Même partage au tableau des achats : « Voir » et le bordereau passent sous
« Originale ».

#### 30.7 Télécharger, et non imprimer

Un bouton **Télécharger** entre sur la page de la pièce, sur le bordereau
d'achat et sur le reçu. Il enregistre un fichier, sans qu'aucune boîte ne
s'ouvre — l'impression par le navigateur reste à côté, inchangée.

**Le format est HTML et non PDF**, et il faut le dire franchement :
l'application n'embarque aucun moteur PDF — ni `dompdf`, ni `wkhtmltopdf`. Le
fichier rendu porte ses styles, s'ouvre dans n'importe quel navigateur et
s'imprime à l'identique. Un vrai `.pdf` demande une dépendance nouvelle et une
remise en page complète des documents : à décider, pas à improviser.

#### 30.8 Le filigrane des cartes d'article

Retiré de l'écran de caisse et de l'écran de modification : la même silhouette
revenait sur des articles sans rapport, et la grille en paraissait salie. Un
article sans photo garde une carte nette. **La vraie photo n'a pas bougé** —
elle porte une information que le texte ne porte pas. Le catalogue garde le
dessin, où les cartes sont grandes et où il se lit.

Et les deux émojis des tableaux de bord — 👋 et 🏢 — sont partis.

#### 30.9 Les épreuves

`StabilisationDesEcransDeVenteTest` — **10 cas** : l'adresse publique sur la
page de la pièce, le numéro de ligne qui ne désigne rien, le tableau de bord
FNE qui ne distribue plus d'adresses mortes, les deux états du libellé DGI, la
file servie par le planificateur, les nouvelles colonnes, la facture qui ne se
modifie plus, les boutons de téléchargement, et les émojis.

`IllustrationArticleTest` et `NormalisationAutomatiqueTest` sont **repris, non
supprimés** : ils portaient la mémoire des décisions d'hier, ils portent celle
d'aujourd'hui. Six de leurs cas ont changé de sens, quatre sont nés.

Suite entière : **1 306 épreuves, 1 302 passantes, 4 sautées, 5 090
vérifications**. `php artisan verifier:variables` : aucune variable lue sans
avoir été écrite.


### Lot 31 — Le PDF véritable, la monnaie rendue, et les trois natures d'achat — **TERMINÉ le 25/09/2026**

#### 31.1 Un vrai PDF, et pourquoi il a fallu un second rendu

Le lot 30 rendait une page HTML autonome, faute de moteur PDF. Le propriétaire
a tranché : « oui vas-y fais le, je veux un bon pdf ». `dompdf/dompdf` v3.1 est
installé.

Il ne pouvait pas venir du même rendu que l'écran. **Les écrans dessinent la
pièce en JavaScript**, en quatre modèles : le document n'existe que dans le
navigateur, et il repose sur `flex`, que dompdf n'implémente pas. Convertir ce
HTML aurait donné une mise en page effondrée.

`DocumentPdfService` établit donc la pièce **côté serveur**, en tableaux, dans
une mise en page faite pour le papier — `factures/pdf/document.blade.php` en A4,
`factures/pdf/ticket.blade.php` en 80 mm.

Ce qui garantit que les deux disent la même chose : **les montants ne sont pas
recalculés**. Ils sont lus tels qu'ils ont été enregistrés, et le timbre vient
de `TimbreQuittanceService`. C'est plus sûr que le rendu de l'écran, qui refait
l'addition en JavaScript.

| Point | Décision |
|---|---|
| Bloc de certification | reproduit à l'identique, et **absent** tant que la pièce n'est pas certifiée |
| Code QR | dessiné en PNG depuis `QrCodeFneService::matrice()`, **lu sans être modifié** — le SVG de l'écran traverse mal un PDF |
| Chargement distant | **éteint**. Un champ portant `<img src="http://…">` ferait sinon partir une requête depuis le serveur, à une adresse choisie par qui a saisi la pièce |
| Logo, visuel FNE | entrent par des `data:` fabriqués par le service, jamais par une adresse |
| Nom du fichier | le numéro FNE d'abord ; filtré sur `[A-Za-z0-9._-]`, sans quoi un `"` dans un numéro refermerait l'en-tête `Content-Disposition` |

L'impression par le navigateur reste en place : elle n'a pas été retirée.

**Au passage, l'audit des dépendances.** `composer audit` remontait 21 avis de
sécurité sur trois paquets — dont un de gravité haute sur `guzzlehttp/guzzle`,
le client HTTP par lequel passe `FneService`. Ils étaient antérieurs à ce lot.
Guzzle 7.13.2 → 7.15.5, `psr7` et `league/commonmark` à jour : **plus aucun
avis**.

#### 31.2 La monnaie rendue, et ce qu'elle a révélé

Demandée par le propriétaire : un champ non saisissable, toujours calculé, à la
vente comme à l'achat, qui figure sur la facture et sur le reçu.

En l'écrivant, un défaut est apparu. **La somme tendue partait telle quelle en
trésorerie et en comptabilité.** Une caisse qui recevait 10 000 F pour une pièce
de 6 500 F enregistrait 10 000 F d'encaissement : la caisse était gonflée de la
monnaie qu'on venait de rendre, et rien nulle part ne disait qu'on l'avait
rendue.

Deux montants distincts désormais :

- `montant_recu` — ce que le client a tendu. Nouvelle colonne sur `ventes` et
  `achats`. Elle ne sert qu'à établir la monnaie et à l'imprimer ;
- l'encaissement, **borné au net à payer** : `min($tendu, $piece->netAPayer())`.

La monnaie rendue n'est **pas** une colonne : `Vente::monnaieRendue()` et
`Achat::monnaieRendue()` la calculent. Une valeur calculée qu'on enregistre finit
par contredire ses termes le jour où l'un d'eux change.

Trois règles qui tiennent aux documents :

- jamais négative — une somme insuffisante est une avance, pas une dette de la
  caisse ;
- `null` quand rien n'a été tendu : on ne rend pas zéro, on ne rend rien, et le
  document ne porte pas une ligne qui n'a pas eu lieu ;
- `netAPayer()` porte le timbre. Sans lui, le plafonnement aurait **retiré** le
  timbre de l'encaissement, là où la somme tendue le couvrait.

Rien ne touche à la FNE : aucun champ du payload ne porte la somme tendue, et la
plateforme reçoit le même net qu'avant.

#### 31.3 Trois natures d'achat, et une seule se normalise

Le propriétaire les a énoncées :

| Nature | Qui certifie | Ce que Selflow en fait |
|---|---|---|
| **Facture enregistrée** | le fournisseur, si tant est qu'il l'ait fait | on la saisit pour suivre la dépense |
| **Bordereau (BAPA)** | **nous** | la seule pièce d'achat qu'un client de Selflow ait le droit de normaliser |
| **Facture d'achat DGI** | le fournisseur | relevée au portail ; il n'y a qu'à la rapprocher |

Les trois vivaient dans un seul tableau, où la colonne « Normalisé (DGI) »
mentait pour les deux tiers des lignes : une facture fournisseur ordinaire y
affichait « En cours » pour toujours, alors qu'elle ne part **jamais**. Trois
sections les séparent, chacune avec son bandeau qui dit pourquoi elle existe.

- « Factures enregistrées » : colonnes DGI conservées mais **« Aucune donnée »**,
  et plus de colonne « Action » — il n'y a rien à faire. Le rapprochement avec
  les pièces relevées viendra les remplir ;
- « Factures BAPA » : le fichier DGI comme aux ventes, et le bouton
  « Normaliser ». **La normalisation par lot ne vise plus qu'elles** : elle
  proposait d'envoyer des pièces que la plateforme aurait refusées ;
- « Factures achat DGI » : ce que le portail a rapporté, avec les gestes du
  rapprochement.

#### 31.4 Deux écrans retirés

**L'avoir fournisseur.** Un acheteur n'établit pas l'avoir de son fournisseur :
la DGI ne le prévoit pas, la plateforme ne certifie l'avoir que du côté de celui
qui a émis la facture. Selflow offrait un document que rien ne rendait
opposable, et qui décrémentait pourtant les stocks. Quatre méthodes et
344 lignes de contrôleur, un modal de 400 lignes, deux entrées de menu et quatre
routes sont partis. **Les avoirs déjà enregistrés restent en base et restent
lisibles** : ils sortent des listes, ils ne sont pas détruits.

**L'écran séparé des factures reçues** (`/admin/achats/factures-recues`). Il
montrait ce que la section « Factures achat DGI » porte désormais — toutes les
pièces relevées, rattachées, à rapprocher ou écartées —, avec les mêmes gestes.
Deux écrans pour une même liste laissaient des factures certifiées non
rapprochées pendant des semaines : personne n'ouvrait le second. Les adresses
des gestes, elles, restent : ce sont elles que la section appelle.

Le message du vide disait « lancer `node achats.js <NCC>` ». Il s'affichait à un
commerçant qui n'a pas de terminal et à qui il n'appartient pas de lancer le
scraper. Il dit maintenant ce qu'il faut comprendre : la DGI ne détient encore
aucune facture à son nom, ou le relevé n'est pas passé.

#### 31.5 L'écran de saisie d'un achat

- **La nature de l'achat se choisit en tête**, sur une ligne, comme les étapes.
  Les deux boutons étaient en bas de la colonne, sous les totaux, dans un
  encadré pointillé : on découvrait après avoir tout saisi qu'on n'avait pas dit
  de quelle pièce il s'agissait. Ils ne se cachent plus l'un l'autre — celui qui
  est choisi porte `active` ;
- **le type de document s'efface devant le bordereau** : un BAPA n'est ni une
  demande de prix ni un bon de commande ;
- **la mention RNE est retirée.** Un bordereau constate un achat auprès d'un
  producteur qui n'émet rien : il n'existe aucun reçu normalisé auquel le
  rattacher, et la case invitait à en déclarer un qui n'a jamais été délivré.
  **Les colonnes `est_rne` et `numero_rne` restent en base et dans le payload** :
  la conformité FNE ne bouge pas, c'est l'écran qui cesse de demander ce qui n'a
  pas lieu d'être ;
- **le B2B n'apparaît qu'avec un fournisseur choisi.** Il s'affichait toujours,
  bordereau compris — où le vendeur n'est justement pas immatriculé : on pouvait
  cocher un envoi qui n'aurait atteint personne.

#### 31.5 bis Deux chemins mènent au bordereau

Écrit en rangeant les trois sections, et il aurait été facile de le manquer :
**un seul des deux chemins vers le bordereau se lit dans `type_facture`.**
`validerFacture()` envoie à `NormaliserAchatBapaJob` toute facture d'un
fournisseur **sans NCC**, quel que soit son type.

Filtrer la section BAPA sur `type_facture = 'bapa'` aurait donc rangé les
achats auprès d'un vendeur non immatriculé — le cas le plus courant — sous
« Factures enregistrées », où les colonnes DGI annoncent « Aucune donnée ».
Elles sont pourtant normalisées. C'est exactement le mensonge que ce lot
retirait.

`Achat::estBapa()` portait déjà la règle pour une pièce chargée ; elle ne
servait plus qu'à interdire l'avoir, et se serait retrouvée sans emploi. Elle
gagne `scopeBordereaux()` et `scopeHorsBordereaux()`, qui disent la même chose
en SQL — parce que l'écran range en base, et qu'écrire la règle deux fois,
c'est la voir diverger. Une épreuve vérifie que les deux portées se partagent
**toutes** les pièces, sans recouvrement ni oubli.

**Un message repêché au passage.** L'écran retiré disait « Aucun fournisseur ne
porte ce NCC » sur une facture orpheline. La section ne le disait pas et
proposait un rapprochement qui ne menait nulle part. Il est revenu.

#### 31.5 ter Le barème du timbre n'existe plus qu'à un endroit

Trouvé en posant la monnaie rendue : **l'écran des achats n'affichait aucun
timbre**. Le pavé des totaux y annonçait donc un net inférieur à ce qu'on remet
vraiment au vendeur sur un bordereau réglé en espèces — et la monnaie rendue
était fausse d'autant, puisqu'elle se déduit de ce net.

L'écran de vente, lui, avait le barème **recopié à la main en JavaScript**. Deux
copies auraient été pires qu'une : le journal garde la trace de ce que coûte une
seconde écriture du barème — le timbre estimé à 1,5 %, taux qui ne figure dans
aucun texte, là où l'article 873 du CGI fixe un forfait par tranche.

`factures/partials/script-timbre.blade.php` remplace les deux : **il tire ses
valeurs de `TimbreQuittanceService::BAREME`**, périmètre gelé de la conformité
FNE. Le service n'est pas modifié ; il est lu. Le jour où la DGI publie un
nouveau barème, il change à un seul endroit et les deux écrans suivent. Une
épreuve compare l'un à l'autre, pour que la copie ne puisse pas renaître.

#### 31.5 quater Ce qu'on écarte doit pouvoir revenir

L'écran séparé des factures reçues portait un filtre par statut, et **lui seul
permettait de revenir sur un écartement**. Le retirer sans reprendre le filtre
aurait fait de l'écartement une suppression déguisée : la pièce reste en base, le
portail la redépose, et plus personne n'aurait pu la rappeler.

La section « Factures achat DGI » montre donc par défaut ce qui vit — à
rapprocher et rattachées — et `?statut=ecartees` rend ce qu'on a mis de côté,
avec le bouton **Remettre**. Le compteur de l'onglet exclut les écartées : les
compter ferait un nombre qui ne baisse jamais.

**Un message repêché au passage** : « Aucun fournisseur ne porte ce NCC ». La
section ne le disait pas et proposait un rapprochement qui ne menait nulle part.

#### 31.6 Les épreuves

| Fichier | Cas |
|---|---|
| `LeDocumentSortEnPdfTest` | 9 — le PDF rendu, la pièce jointe, `%PDF`, la certification absente puis présente, les montants enregistrés, la pièce d'autrui (404), le nom du fichier, et **l'injection d'en-tête par le numéro de pièce** |
| `LaMonnaieRendueTest` | 10 — le calcul, l'appoint, la somme insuffisante, l'absence de ligne, **la caisse qui n'encaisse pas ce qu'elle rend**, les deux écrans, les deux documents |
| `TroisNaturesDAchatTest` | 17 — les trois sections, la section inventée, **l'achat à un vendeur sans NCC rangé en bordereau**, « Aucune donnée », le bouton et le lot réservés au bordereau, les quatre routes d'avoir disparues, l'avoir conservé en base, l'écran retiré et ses gestes conservés, le message du vide, la nature en tête, le RNE retiré, le B2B conditionnel, les colonnes gelées intactes |

**Trois fichiers ont été repris plutôt que supprimés**, parce qu'ils portaient la
mémoire d'une décision et qu'ils portent maintenant la suivante :

- `AvoirDeBapaTest` fermait l'avoir d'un bordereau. La fermeture s'étant
  élargie à tous les avoirs fournisseurs, ses cas visaient des routes qui
  n'existent plus. Il garde ce qui porte désormais davantage : **le critère des
  deux chemins**, qui sert maintenant à ranger et non plus seulement à
  interdire ;
- `AvoirChoixDeLaPieceTest` perd son cas d'achat et garde ses cas de vente :
  l'avoir **client** reste, c'est le vendeur qui l'établit ;
- `EcranFacturesRecuesTest` suit la liste là où elle vit désormais. Ses gestes
  — rattacher, écarter, l'étanchéité entre entreprises — n'ont pas bougé.

Suite entière : **1 339 épreuves, 1 335 passantes, 4 sautées, 5 219
vérifications**. `php artisan verifier:variables` : aucune variable lue sans
avoir été écrite. `composer audit` : aucun avis de sécurité.


### Lot 32 — Le stock par la porte, et le couple qui arrive entier — **TERMINÉ le 25/09/2026**

#### 32.1 Deux écrans qui refusaient ce qu'ils avaient eux-mêmes proposé

**« Le champ reception est obligatoire », sans aucun champ à remplir.** Une
commande d'achat de **services** figurait dans la file des réceptions : sa
`quantite_receptionnee` reste à zéro pour toujours, puisque rien n'entre en
stock. La fiche n'offrait donc aucun champ — l'article n'étant pas stockable —
et le formulaire exigeait quand même `reception`.

Le filtre porte désormais sur l'article autant que sur la quantité, à la
réception **comme à la livraison** : le second avait le même défaut, qu'aucune
capture n'avait encore montré. Et la fiche le dit avant qu'on clique.

**Et une page 500 (Internal Server Error) sur toute vente de passage** :
`livraison_fiche.blade.php` lisait `$vente->client->nom` sans détour, alors que
`client_id` est nul sur une vente au comptoir.

#### 32.2 Le stock n'entre plus que par la porte

**C'était la vraie question du module.** Modifier un article réécrivait
`quantite_disponible` **directement** dans `stocks`, sans passer par
`StockService` :

- aucun `MouvementStock` n'était écrit — le journal ne montrait rien ;
- **le CUMP (Coût Unitaire Moyen Pondéré) n'était pas recalculé** ;
- la quantité montait ou descendait sans trace de qui l'avait fait, ni pourquoi.

C'est une correction d'inventaire déguisée, et l'inventaire a son écran — qui,
lui, écrit l'écart, le date et le signe. Le catalogue n'y touche plus ; le champ
est en lecture seule et renvoie à l'inventaire. Le seuil d'alerte reste, lui :
c'est un réglage, pas une quantité.

**La quantité de départ, elle, entre par la porte** : un motif
`stock_initial` est né, et le prix d'achat saisi devient le premier CUMP. Elle
était écrite directement elle aussi, si bien que la première sortie valorisait
la marchandise **à rien**.

#### 32.3 Un journal de vente ne porte pas de compte

Le compte de contrepartie ne concerne que la trésorerie : c'est le 521 de la
banque ou le 571 de la caisse. Un journal de ventes n'en a pas — sa contrepartie
est le tiers de la pièce, qui change à chaque écriture.

Le référentiel le dit depuis le lot 20 (`"compte": null`), mais les entreprises
créées **avant** gardaient le leur. Une migration l'efface ; rien ne le lisait.

**Et les journaux se modifient.** Le trousseau pose des intitulés génériques, et
une entreprise doit pouvoir les faire siens sans supprimer puis recréer — ce qui
détacherait ses écritures. Le code et le type, eux, ne changent pas : le code est
la clé sous laquelle les écritures sont rangées.

#### 32.4 « Libellés d'écriture » quitte le menu Comptabilité

Ce n'est pas un écran de comptabilité mais un réglage : on ne le consulte pas,
on le pose une fois. Il vit dans les paramètres de l'entreprise. Il était bien
propre à l'entreprise — vérifié.

#### 32.5 Le couple qui n'arrivait pas — le défaut le plus grave de la passerelle

Un fichier entier lui est consacré : `ETAT-LIAISON-ET-DEVERSEMENT-COMPTAFLOW.txt`,
écrit par lecture des **deux** dépôts. Ce qu'il établit :

| Chemin | État |
|---|---|
| **Le référentiel** (plan, journaux, tiers) | **bon** — il passe par le module d'import de Comptaflow, donc sa configuration, ses numérotations et ses règles s'appliquent |
| **Les écritures** | **défaillant** — il écrit en base sans passer par l'import, et crée les comptes manquants à la volée |

Et le défaut central : `EcritureComptable::created` déversait **ligne par
ligne**. Une facture de vente en produit quatre ou cinq, et chacune partait dans
son propre appel. Si le débit du client passait et que le crédit de la vente
était refusé — journal inconnu, exercice en désaccord (409, Conflict), coupure
réseau —, Comptaflow gardait **un débit sans son crédit**. Sa balance ne
balançait plus, et rien ne recollait les morceaux.

Pire : `created` se déclenche **avant** `cloturerEquilibre()` et avant la fin de
la transaction. Une transaction annulée ensuite laissait chez Comptaflow une
écriture que Selflow n'avait pas.

Corrigé : l'opération part **d'un bloc**, à sa clôture, en `afterCommit()`, et
seulement si elle est équilibrée. Côté Comptaflow, un refus partiel annule tout
(rollback) et répond 422 en disant combien de lignes n'ont pas pu être rangées.

**Et la reprise mutilait les écritures.** `selflow:sync-ecritures` bâtissait sa
propre charge utile, où manquaient `cle_selflow` — donc **aucune idempotence**,
un second passage pouvait dupliquer —, `compte_tiers` et l'exercice. Une écriture
partie par le chemin ordinaire arrivait complète ; la même, reprise, arrivait
amputée. Elle passe par le même travail que les autres.

**Le bouton « Lancer le déversement »** entre dans les paramètres de
l'entreprise : référentiel d'abord, opérations ensuite, avec la confirmation qui
avertit de vérifier la configuration de Comptaflow — la changer après coup ne
renumérote pas ce qui est déjà déposé.

#### 32.6 Les épreuves

`PasserelleComptaflowTest` est **repris** : ses cas écrivaient une ligne seule et
attendaient un départ immédiat. Ils écrivent maintenant une opération entière —
son débit, son crédit, sa clôture — et vérifient quatre choses de plus : que
l'opération part d'un bloc et se dit `atomique`, qu'une opération
**déséquilibrée** ne part pas, qu'une **ligne seule** ne déclenche rien, et que
les deux lignes d'une même pièce portent des clés d'idempotence distinctes.

Suite entière : **1 342 épreuves, 1 338 passantes, 4 sautées, 5 224
vérifications**. `php artisan verifier:variables` : aucune variable lue sans
avoir été écrite.


## 5 bis. La numérotation des comptes — tranché

Le classeur subdivisait certaines racines sur des positions que l'acte uniforme
réserve à un autre usage. Le relevé du plan OHADA a permis de distinguer trois
situations, et deux seulement demandaient correction.

**Ce qui était juste et n'a pas bougé — les stocks.** Sur `31`, `32`, `33` et
`36`, l'acte uniforme prescrit lui-même une ventilation par famille : `311`
« Marchandises A », `312` « Marchandises B ». Le classeur y range Vivres,
Boissons, Hygiène : c'est exactement l'usage prévu. Les comptes de variation
`6031x` suivent les stocks, et `6031` n'a aucune subdivision prescrite.

**Ce qui était faux — les ventes et les achats.** Sur `701`, `702`, `705`, `706`,
`601` et `602`, la quatrième position porte la ventilation *géographique* :

| Compte | Acte uniforme | Le classeur y mettait |
|---|---|---|
| `7011` | Dans la Région | Vivres et alimentation |
| `7012` | Hors Région | Boissons |
| `7013` | Aux entreprises du groupe dans la Région | Hygiène et entretien |
| `6011` | Achats dans la Région | Vivres et alimentation |

Une entreprise ainsi paramétrée n'aurait plus pu produire la ventilation
attendue par la liasse fiscale — celle que Comptaflow établit. **Décision : les
ventes et les achats sont ramenés sur leur racine** (`701000`, `601000`,
`706000`). Le grand livre porte la nature de l'opération ; le détail par famille
reste dans Selflow, et l'analytique le portera jusqu'à Comptaflow. C'est aussi
ce que font en pratique les entreprises ivoiriennes, qui imputent à plat sur
`701`.

**Ce qui était inversé — les produits accessoires.** Sur `707`, l'acte uniforme
prescrit des natures, et le classeur les avait interverties :

| Famille | Le classeur | Corrigé en | Acte uniforme |
|---|---|---|---|
| Livraison facturée | `7072` | `707100` | Ports, emballages perdus et autres frais facturés |
| Commissions dépôt-vente | `7071` | `707200` | Commissions et courtages |

276 imputations corrigées au total, dans le JSON du référentiel. Trois tests
verrouillent les trois situations.

---

## 6. Anomalies constatées et non encore corrigées

Elles sont documentées pour ne pas être redécouvertes.

### Seize épreuves tombent, en deux familles — **constaté le 07/09/2026**

Relevées au lot 27, et **antérieures à lui** : la suite rejouée sur `HEAD` sans
les modifications du lot rend le même compte et les mêmes tests. Aucune ne touche
le périmètre gelé.

**Cinq perdent un accent.** `CycleFneTroisCasTest` (4 cas) et `EcranRejetsFneTest`
(1) attendent `FACTURATION SIÈGE` et obtiennent `FACTURATION SIEGE`. Le point de
vente est renommé d'après le portail : reste à savoir qui perd le `È` — le relevé,
la comparaison de noms, ou l'encodage du poste. Tant que ce n'est pas tranché, on
ignore si le défaut est dans l'épreuve ou dans la correction automatique des noms,
et c'est cette dernière qui part à la DGI.

**Onze portent sur le chargement à la main.** `LoadFileFneTest` : sept envois
rendent 500 au lieu d'une redirection, quatre ne trouvent pas la clé `succes` en
session. C'est l'écran qui permet de déposer un relevé quand le scraper ne peut
pas tourner — le filet de sécurité, donc, et il est déchiré.

Aucune des deux familles n'a été diagnostiquée : le lot 27 s'est arrêté au constat
et à la preuve d'antériorité.

### Le planificateur n'était déclenché par rien — **corrigé en développement**

`routes/console.php` planifie deux tâches — `selflow:sync-ecritures` toutes les
cinq minutes depuis le lot 5, `portail-fne:importer` toutes les heures depuis
le lot 9. **Aucune des deux ne tournait.** Un planificateur Laravel ne
s'auto-déclenche pas : il lui faut `php artisan schedule:run` appelé chaque
minute, par le cron du serveur ou par le planificateur de tâches de Windows.
Vérifié le 21/08/2026 : aucune tâche de ce nom n'existait sur le poste.

La conséquence dépassait l'import du portail : **la reprise des écritures
Comptaflow en échec n'avait jamais eu lieu**, et rien ne le signalait, puisqu'une
tâche jamais lancée ne produit ni journal ni erreur.

Posé sur le poste de développement le 21/08/2026 :

- tâche Windows « Selflow - planificateur », toutes les minutes, sans fin ;
- elle appelle `storage/app/planificateur-selflow.vbs`, qui lance
  `php artisan schedule:run` **fenêtre masquée**. Sans ce lanceur, une console
  noire s'ouvrirait soixante fois par heure sur l'écran de qui travaille. Le
  script porte les chemins de ce poste et vit dans `storage/app`, que git
  ignore : il n'est ni versionné ni déployé.

**Reste à poser en production** (Linux) :
`* * * * * cd /chemin/selflow && php artisan schedule:run >> /dev/null 2>&1`.
À ajouter à `deploy-production.sh`, qui n'en dit rien aujourd'hui.

### La tolérance de transition de la passerelle — **FERMÉE AU LOT 23**

Un appel entrant sans en-tête `X-Company-Key` passait sur le seul secret
partagé, des deux côtés : un secret volé écrivait et lisait dans n'importe
quel dossier. Les quatre blocs sont tombés ensemble — voir 23.3 —, et les
épreuves qui documentaient la porte ouverte ont cédé la place à celles du
refus 401 (Unauthorized — non authentifié).

`list-companies` reste accessible sans clé, par choix : il rapproche un
dossier qui n'est pas encore lié.

### Le secret partagé et les clés versionnées — **À RÉVOQUER**

`selflow-comptaflow-secret-2026` a circulé en clair et doit être changé dans
les deux `.env` avant toute mise en service.

Le dépôt **Comptaflow** versionne par ailleurs deux secrets en clair : une clé
d'API Gemini dans `.env.example`, et un `APP_KEY` réel dans `.env.example2`.

Le propriétaire précise que la production tourne sur `.env`, **pas** sur
`.env.example2` — la clé versionnée n'est donc pas, en principe, celle qui
chiffre les données. Deux vérifications restent dues avant de s'en satisfaire :

1. **comparer** l'`APP_KEY` du `.env` de production à celle du fichier
   versionné. Si elles coïncident, elle chiffre la copie de sauvegarde de la
   clé de liaison, et quiconque a lu le dépôt la déchiffre ;
2. **retirer les deux fichiers du dépôt** et révoquer la clé Gemini chez le
   fournisseur. Ce qui est entré dans l'historique d'un dépôt y reste : le
   retrait du fichier ne retire pas le secret.

Rien d'équivalent côté Selflow : aucun fichier d'environnement n'est versionné,
et la recherche de secrets écrits en dur ne remonte rien.

### ~~Taxes supportées à l'achat~~ — **TRANCHÉ ET RETIRÉ au lot 12.1**

Le propriétaire a choisi le retrait, le 24/08/2026. Voir le lot 12.1.

### ~~Taxes personnalisées à l'achat~~ — **TRANCHÉ ET RETIRÉ au lot 12.6**

Le propriétaire a choisi le retrait, le 24/08/2026. Et le constat qui figurait
ici était **incomplet** : `achat_taxes` n'était pas dormante, elle était
**remplie par le formulaire et relue par personne**. Voir le lot 12.6.

### Stock

- ~~`quantite_disponible` est un `integer` — le référentiel livre des kg,
  litres, sacs. 12,5 kg de cacao deviennent 12.~~ **CORRIGÉ au lot 3.1** — voir
  le lot 3 pour le détail de la chaîne.
- ~~Deux portes pour la même entrée : `AchatControleur` incrémente à
  l'enregistrement de la facture, `StockControleur` incrémente à la réception.
  Rien n'interdit les deux.~~ **CORRIGÉ au lot 3.3** : la facture pose
  `quantite_receptionnee`, la ligne quitte la file des réceptions.
- ~~`VenteControleur:698` **supprime** les mouvements de stock à la
  modification d'une vente. Un journal se contre-passe, il ne s'efface pas.~~
  **CORRIGÉ au lot 3.3** : `StockService::contrePasserLaPiece()`, et le modèle
  refuse désormais toute suppression.
- ~~Le couple « décrémenter puis écrire le mouvement » est recopié dans plus
  de douze endroits, sur sept contrôleurs et deux contrôleurs d'API.~~
  **CORRIGÉ au lot 3.2/3.3** : `StockService` est la seule porte.
- ~~`reference_document` est une chaîne libre, pas une relation.~~
  **CORRIGÉ au lot 3.2** : couple `piece_type` / `piece_id`, la chaîne restant
  pour l'affichage.
- ~~Une **fiche de stock est créée pour tous les types de produits**, y compris
  les services, avec un minimum de 5 : un service apparaît en permanence dans
  les alertes de rupture.~~ **CORRIGÉ au lot 2** : `ProduitControleur` sort
  avant la boucle et `PointDeVenteControleur` saute l'article si
  `estStockable()` est faux. Même règle dans `poserStockInitial()`. Pour un
  cabinet comptable, dont tous les articles sont des missions, le tableau de
  bord n'annonçait jusqu'ici que des ruptures sur des choses qui ne s'épuisent
  pas.
- ~~Les avoirs réinjectent le stock automatiquement, sans jamais demander si
  la marchandise revient vendable, en rebut, ou pas du tout.~~ **CORRIGÉ au
  lot 3.4** — le choix existait à l'écran mais s'écrivait dans une colonne
  inexistante ; voir le lot 3.4.

### Comptabilité

- ~~Aucun compte de stock (31, 32, 33, 36) ni de variation (6031, 6032, 6033,
  736) n'est mouvementé.~~ **CORRIGÉ au lot 4.2** : `InventairePermanentService`,
  appelé depuis la porte unique du stock.
- ~~Pas de coût de revient : `produits.prix_achat` est un prix catalogue
  figé.~~ **CORRIGÉ au lot 4.2** : CUMP (Coût Unitaire Moyen Pondéré) par fiche
  de stock, alimenté par le prix réellement payé.

### Passerelle Comptaflow — **LE CORRECTIF A ÉTÉ PERDU**

Le correctif du lot 5 avait bien été appliqué côté Comptaflow, commit
`b3bd59b` du 9 août 2026 (« Déversement Selflow : idempotence, tiers, exercice
comparé, et un secret qui n'est plus public »).

**Un `git push --force` sur `main` le 12 août l'a effacé de l'historique.**
Le commit existe encore dans le dépôt — `git cat-file -t b3bd59b` répond —
mais il n'est plus atteignable depuis `main` :
`git merge-base --is-ancestor b3bd59b HEAD` échoue. `ExternalSyncController`
est revenu à sa version d'avant, vérifié ligne à ligne :

| Vérification | État au 14/08/2026 |
|---|---|
| `cle_selflow` (idempotence) | absent de tout `app/` |
| `compte_tiers` | absent du contrôleur de synchronisation |
| `exercice_debut` / `exercice_fin` | absents |
| `n_saisie` | de nouveau `$refPiece ?: 'SELF_' . time() . '_' . $count` (ligne 486) |
| Exercice | de nouveau celui **actif** chez Comptaflow, sans comparaison (ligne 411) |

Or **Selflow envoie désormais ces cinq champs** : le `DeverserEcritureComptaflow`
les met dans chaque payload. Comptaflow les reçoit et les ignore. Conséquences
en l'état :

- rejouer une synchronisation **duplique tout**, et la balance double ;
- le relevé d'un client particulier reste impossible : tout retombe sur le
  compte collectif `411000` ;
- une pièce d'un exercice clos se range dans l'exercice courant.

**Le correctif a été refait — 15/08/2026.** `git cherry-pick b3bd59b` échoue
sur un poste de travail ordinaire (`fatal: bad revision`) : l'objet n'a jamais
été récupéré localement. Il a donc été reporté sur `a5da35d`, conflit résolu,
et livré sous deux formes dans `PASSERELLE-COMPTAFLOW/` :

| Fichier | Rôle |
|---|---|
| `0002-deversement-selflow-sur-a5da35d.patch` | le correctif, vérifié : `git apply --check` passe sur `a5da35d` |
| `CONSIGNES-POUR-COMPTAFLOW.md` | le dossier complet, autoportant : contexte, raison de chaque changement, application, vérification au `curl`, plan comptable |

Le conflit portait sur la résolution du compte : `main` avait retouché le bloc
en ligne là où le correctif le déplace dans `compteGeneral()` / `tiers()`.
**La version du correctif l'emporte** — sa logique remplace l'ancienne, elle
ne s'y ajoute pas.

Ces deux fichiers se donnent tels quels à une session Comptaflow, qui n'a
besoin de rien d'autre. Cette session-ci lit le dépôt Comptaflow mais ne peut
pas y pousser : `add_repo` refuse les ajouts entre propriétaires différents.

**Le correctif est poussé — vérifié le 15/08/2026.** Il vit sur la branche
`claude/passerelle-selflow-deversement` du dépôt Comptaflow, commit `0407335`.
**`main` est resté sur `a5da35d` : la fusion n'est pas faite.**

Contrôlé sur la branche, pas supposé : `cle_selflow`, `compte_tiers`,
`exercice_debut`, `hash_equals`, `desaccordDExercice()` et `compteGeneral()`
sont là ; `n_saisie` prend la clé ; la migration d'idempotence est présente.
Il ne reste aucune valeur de secret en dur — les douze appelants lisent la
même clé de configuration, sans repli, et les seules occurrences des anciennes
chaînes sont les commentaires qui expliquent leur retrait.

Un second commit y ajoute deux choses que je n'avais pas vues :

- **un septième point d'entrée restait ouvert.** `syncStatus` lisait
  `config('app.external_sync_secret', 'selflow-local-secret')` — une **autre**
  clé, absente de `config/app.php`, et une **autre** valeur de repli en clair.
  C'était donc toujours la chaîne publique qui faisait foi, quoi qu'on mette
  au `.env`, pendant que les six autres étaient refermés ;
- la route morte `POST /load-syscohada` vers une méthode `private` est
  supprimée, et aucune vue ne la référençait.

**À faire en même temps que la fusion :** poser `EXTERNAL_SYNC_SECRET`
dans les deux `.env`, même valeur. Sans elle la synchronisation refuse tout —
c'est le comportement voulu, mais il faut le savoir avant de conclure à une
régression. Et considérer `selflow-comptaflow-secret-2026` comme compromis :
il est dans l'historique public.

### Plan comptable de Comptaflow — le référentiel existe bien

Correction du constat précédent, qui portait sur le mauvais chemin.

Comptaflow **possède** son référentiel SYSCOHADA : `config/syscohada_complet.php`,
**1 259 comptes**, chargeable par entreprise depuis l'écran de configuration
(`AdminConfigController::loadSyscohadaPlan`), en trois formats — SYSCOHADA
(2-4), **COMPTES SAGE (6)**, DC-KNOWING (8). La table `plan_comptables` est
`company_id`, donc chaque entreprise en a sa copie et la modifie sans gêner
les autres.

Le chargement est **à la demande, jamais automatique** : une entreprise neuve
part d'un plan vide tant que personne n'a cliqué.

**Selflow émet des numéros à 6 chiffres** (`411000`, `701000`, `443100`,
`521000`…). Le format à charger est donc **« COMPTES SAGE (6) »** : c'est le
seul des trois où les comptes de Selflow tombent sur des comptes existants du
référentiel. En 2-4, `701000` ne correspond à rien — le déversement le crée à
la volée avec le libellé de l'écriture pour intitulé, et le plan se remplit de
comptes bâtards à côté des vrais.

Le classeur `modele_plan_comptable.xlsx` est un **modèle d'import** pour un
plan déjà existant venu d'ailleurs, pas la source du référentiel. Son contenu
d'exemple (« ELIKET MARKET ») ne dit rien de l'état réel des plans en base.

**Bug repéré au passage, hors passerelle :** `routes/web.php:510` route
`POST /load-syscohada` vers `loadSyscohadaPlan`, **déclarée `private`** →
500 (Internal Server Error — erreur interne du serveur). Les trois routes
`-4`, `-6`, `-8` passent par des méthodes publiques et fonctionnent.

### Passerelle Comptaflow — anomalies d'origine

- `deverserEcritures` prend l'exercice **actif** de Comptaflow sans jamais le
  comparer à la date de l'écriture.
- `linkCompany` ne vérifie aucune concordance d'exercice à la liaison.
- L'analytique n'est **jamais** alimentée : zéro occurrence d'`AxeAnalytique` ou
  de `SectionAnalytique` dans le contrôleur de synchronisation, alors que
  Comptaflow possède tout le module.
- **Quatre champs manquent au déversement**, alors que Selflow les possède :

  | Colonne Comptaflow | Reçoit aujourd'hui | Devrait recevoir |
  |---|---|---|
  | `n_saisie` | la référence de pièce, sinon `'SELF_' . time()` | `operations.numero_saisie`, unique par entreprise |
  | `plan_tiers_id` | deviné en cherchant le compte dans le plan tiers | `ecritures_comptables.compte_tiers` |
  | `plan_analytique` | jamais renseigné (booléen) | le drapeau, avec le point de vente |
  | *(analytique)* | rien | `ecritures_comptables.point_de_vente_id` |

  Le repli `'SELF_' . time()` est aussi ce qui interdit toute idempotence : deux
  déversements de la même écriture produisent deux numéros de saisie différents.
- Un journal inconnu se replie sur le premier de la liste : une vente peut
  atterrir dans le journal des achats, silencieusement.
- Un compte absent est créé avec `type_de_compte => 'actif'` en dur et le libellé
  de l'écriture pour intitulé — cela pollue le plan comptable de Comptaflow.
- Aucune idempotence : rejouer une synchronisation redéverse les mêmes écritures.

### Gestionnaire d'exceptions — **CORRIGÉ**

Le gestionnaire interceptait **toutes** les exceptions et renvoyait la page
« 500 — panne détectée » pour chacune. En production, cela voulait dire qu'une
adresse mal tapée, un accès refusé, une session expirée — et surtout **un
formulaire mal rempli, dont la saisie était perdue** — affichaient une panne.
Chacune déclenchait en prime un courriel d'alerte à deux adresses : un robot
cherchant `/wp-admin` inondait la boîte aux lettres.

`App\Exceptions\Panne::estUne()` fait le tri : seules les erreurs serveur
méritent la page de panne. Découvert en écrivant les tests de l'écran
superadmin, qui recevaient 500 là où ils attendaient 403 et 404.

### Comptes de produit — **CORRIGÉ**

`produits.compte_vente` et `compte_achat` étaient obligatoires, avec pour
valeurs par défaut `701100` et `601100` — précisément les positions que l'acte
uniforme réserve à la ventilation géographique. Tout produit créé sans compte
explicite atterrissait donc en « Dans la Région ». Et l'obligation forçait à
inventer un compte pour une matière première, qui ne se vend pas.

Les deux colonnes sont devenues facultatives, sans valeur par défaut.

Au passage : `Produit` n'avait aucune relation vers sa catégorie, et une
colonne `categorie` — l'ancien libellé libre — masquait toute relation
homonyme. D'où `categorieRelation()`.

### Importations — **CORRIGÉ**

Le constat d'origine — « cinq types seulement, colonnes incomplètes » — est
levé. Le lot 6.6 a porté l'import à **sept modules** : points de vente,
clients, fournisseurs, utilisateurs, produits (24 colonnes), stock
d'ouverture, immobilisations. Les sept sont branchés à un écran.

**Dernier manque, corrigé le 15/08/2026 :** la colonne `sous_categorie`
figurait au modèle des articles et **n'était jamais lue**. Un catalogue
arrivait rangé par famille seulement, et la sous-famille se ressaisissait
fiche par fiche. Elle est désormais résolue par son nom.

Un nom qui ne désigne rien — famille comme sous-famille — **refuse la ligne
avec son motif** au lieu de la ranger « sans famille » en la comptant pour un
succès. Ce silence-là faisait croire l'import complet, et l'administrateur ne
s'en apercevait qu'à la première recherche au catalogue.

La borne d'entreprise passe par la famille : `sous_categories` ne porte pas
d'`entreprise_id`, elle ne tient à son entreprise que par sa `categorie_id`.
Une recherche sur le seul nom aurait rattaché un article à la sous-famille
d'un concurrent — cinq épreuves couvrent le point, dont la simulation
d'attaque.

Restent hors import, par choix : le plan comptable et les codes journaux, que
Selflow livre par son propre référentiel, et les soldes d'ouverture
comptables, qui appartiennent à Comptaflow.

### Numéro de tiers et compte général — **CORRIGÉ**

Relevé par le propriétaire du projet le 15/08/2026. **Ce sont deux choses
différentes**, et il ne faut pas les confondre :

| Notion | Colonne | Exemple |
|---|---|---|
| Compte général de rattachement | `compte_comptable` | `411000`, `401000` |
| Numéro de tiers | `numero_tiers` | `411001`, `411KONE` |

La distinction existait déjà en base, et l'écriture les range dans deux
colonnes séparées — `compte_debit` / `compte_credit` pour le général,
`compte_tiers` pour le tiers, jamais l'un à la place de l'autre. Un seul
endroit du code écrit dans `ecritures_comptables`, précisément pour ça.

**Cinq défauts autour, tous corrigés :**

1. **La numérotation automatique démarrait à `411000`** — le compte collectif
   lui-même. Le premier client de chaque entreprise portait donc, comme numéro
   de tiers, le numéro du collectif : son relevé remontait le solde de tous les
   autres. La séquence part de `001`, et la migration renumérote les fiches
   déjà fautives sur la première place libre ;
2. **le numéro n'acceptait que des chiffres** (`^411[0-9]*$`). `411KONE` est
   une convention répandue, elle était refusée ;
3. **aucune cohérence n'était vérifiée** : rien n'empêchait de donner `411002`
   à un client et de le rattacher à `401000`. L'écriture partait alors sur le
   collectif fournisseurs avec un tiers client ;
4. **rien n'interdisait de saisir le collectif lui-même** comme numéro de
   tiers ;
5. **la vente de passage ne portait aucun tiers.** Sans client nommé,
   `compte_tiers` restait vide et tout retombait sur `411000` : le grand livre
   ne distinguait plus les ventes de comptoir des créances d'un client
   identifié.

**La règle vient de Comptaflow, et ce n'est pas un détail de forme.**
`ExternalSyncController::tiers()` retrouve un tiers **par égalité de chaîne**
sur `numero_de_tiers`. Une convention différente d'un côté et de l'autre, et
plus aucun tiers n'est reconnu : chaque écriture déversée retombe sur son
compte collectif, sans que rien ne le signale.
`NumerotationTiersService` reproduit donc
`AdminConfigController::getNextTierNumber()` à l'identique :

| Élément | Règle |
|---|---|
| Préfixe | **Deux** caractères — les deux premiers chiffres du collectif : `41`, `40` |
| Longueur totale | **Six**, séquence comprise. Doit valoir `companies.tier_digits` chez Comptaflow |
| `numeric` *(défaut)* | préfixe + séquence → `410001`, `400001` |
| `alphanumeric` | préfixe + **trois lettres** du nom + séquence → `41KON1`, `40KOF1` |

Le réglage vit sur l'entreprise, comme les comptes par défaut, et se change
depuis les paramètres — où l'écran rappelle qu'il doit être le même que dans
Comptaflow. Les deux conventions **cohabitent** : une entreprise qui change
d'avis ne voit pas sa séquence repartir de `0001` sur un numéro déjà pris. Un
nom sans lettre — « 123 » — retombe sur la base numérique ; Comptaflow y met
« XXX », ce qui créerait une famille de tiers tous nommés pareil.

**Le numéro ne se saisit plus, ni à la création ni à la modification.** Le
système le fabrique. Changer le numéro d'un tiers déjà déversé le rendrait
introuvable chez Comptaflow : ses écritures futures retomberaient sur le
collectif pendant que les anciennes resteraient sur l'ancien numéro.

**L'import filtre, comme Comptaflow le fait au déversement.** Un fichier vient
de partout — d'un autre logiciel, d'un tableur retouché à la main — et rien n'y
garantit la convention. Trois motifs de rejet, et dans les trois cas le système
renumérote : le numéro vaut le compte collectif ; son préfixe ne correspond pas
au rattachement ; sa longueur n'est pas la bonne. Un numéro déjà pris est
également écarté.

**Le client de passage** est une fiche unique par entreprise — `410000`
rattaché à `411000` — posée à la souscription, et créée à la demande pour les
entreprises déjà en service. `400000` en pendant pour les fournisseurs. C'est
la place zéro : elle précède la séquence, qui démarre à 1, et ne la fera donc
jamais avancer — au contraire d'un numéro haut comme `419999`, qui pousserait
le suivant hors de la longueur permise.

Vingt-deux épreuves dans `NumerotationTiersTest`.

### Libellés d'écriture — l'intitulé du compte tient lieu de libellé — **CORRIGÉ au lot 12.2**

Relevé par le propriétaire du projet le 15/08/2026, et vérifié dans le code.
**Livré le 24/08/2026** — voir le lot 12.2. Le constat ci-dessous est conservé :
c'est lui qui dit pourquoi le chantier existait.

Une seule chose diffère de ce qui était proposé ici : **le défaut n'est pas
`{piece} — {tiers}` mais l'ancien texte, à l'identique.** Changer le libellé de
toutes les entreprises d'office aurait modifié leur journal sans qu'elles
l'aient demandé ; le nouveau gabarit est proposé à l'écran, il ne s'impose pas.

**Ce sont deux choses différentes.** L'intitulé du compte appartient au plan
comptable : 701000 s'appelle « Ventes de marchandises », et cela ne change
jamais. Le libellé de l'écriture dit ce qui s'est passé — quelle pièce, avec
qui. Selflow met aujourd'hui le premier à la place du second.

Pour une facture FA-0042 de ciment et fer à béton, vendue à ABC SARL :

| Ligne | Ce qui s'écrit | Verdict |
|---|---|---|
| En-tête de l'opération | `Vente de marchandises` | **intitulé du compte**, déduit de la racine 701 |
| Client 411000 | `FA-0042 / Facturation Vente` | le tiers manque |
| Produit 701000, ≤ 3 articles | `FA-0042 / Ciment, Fer à béton` | correct |
| Produit 701000, > 3 articles | `FA-0042 / Vente de marchandises…` | **intitulé du compte** |
| TVA 443100 | `FA-0042 / TVA Collectée Vente` | correct |
| Règlement | `Rglt/FA-0042/Vente Ciment, Fer à béton` | le tiers manque |

Deux fois sur six, c'est l'intitulé du compte qui sert de libellé, et **le nom
du tiers n'apparaît jamais**. Or `grand_livre.blade.php:21` affiche déjà
« 701000 — Ventes de marchandises » en tête de colonne : le répéter dans
chaque ligne n'apprend rien et occupe la place de ce qui manque.

Le mécanisme n'est pas mauvais partout : la référence de pièce est là, les
articles remontent quand ils tiennent, et au-delà de trois la liste complète
part dans la colonne `description` que la vue déplie au clic — un libellé de
cinq cents caractères serait illisible. C'est déjà mieux que le texte fixe
d'origine, « Vente — Facture X » répété à l'identique partout.

**Deux manques, donc, et non un seul :**

1. l'intitulé du compte sert de libellé d'opération, et le tiers est absent ;
2. rien n'est paramétrable — l'assemblage est écrit dans
   `ComptabiliteService`.

Le correctif proposé traite les deux : une table `modeles_libelles` portée par
`entreprise_id`, six gabarits, des jetons `{piece}` `{tiers}` `{produits}`
`{point_de_vente}` `{date}`, et **`{piece} — {tiers}` par défaut**. Les
écritures déjà passées ne sont jamais réécrites : un libellé est ce qu'il
était au jour de la pièce.

### Souscription — les points de vente disparaissaient — **CORRIGÉ**

Signalé le 15/08/2026 : la section « Points de vente » de la barre latérale
s'évanouissait au cours du paramétrage, sans message.

La liste des modules livrés à tout le monde vivait **en double** —
`SouscriptionControleur::modulesProposes()` pour afficher les cases à cocher,
`SouscriptionProfilService::ouvrirLesModules()` pour écrire `modules_actifs`.
Les deux copies avaient dérivé de la même façon : `points_de_vente` manquait
aux deux. Tant que `modules_actifs` restait vide, la barre latérale affichait
tout par défaut ; l'étape 4 l'écrivait enfin, et la section disparaissait.

Aucune route ne porte `modules:points_de_vente` — les écrans restaient
joignables, seul le menu s'était fermé. C'est ce qui a rendu la panne muette.

Trois corrections :

- **une seule liste**, `Entreprise::MODULES_SOCLE`, lue aux deux endroits ;
  une épreuve refuse qu'on en recrée une copie ;
- **`Entreprise::MODULES_STRUCTURELS`** — `principal` et `points_de_vente` ne
  se décochent pas. Ce dernier ne porte pas que les sites : **le personnel et
  les habilitations vivent derrière lui**, et le retirer priverait
  l'administrateur de l'écran où il gère ses propres utilisateurs et leurs
  droits. Personne ne fait ce choix en connaissance de cause ;
- une case désactivée **n'est pas transmise** par le navigateur : le rattrapage
  se fait côté serveur, où un formulaire forgé le rencontre aussi.

Structurel ne veut pas dire hors de contrôle : **un superadmin qui ferme le
module le garde fermé.** Le parcours ne peut pas ouvrir ce que les droits
refusent — une épreuve le vérifie.

Six épreuves dans `ParcoursSouscriptionTest`. Trois d'entre elles échouent si
l'on retire la correction ; c'est vérifié, pas supposé.

### Modèles d'import de Comptaflow — les quatre fichiers ne s'importent pas

Vérifié fichier par fichier le 15/08/2026, en rejouant la logique des trois
importeurs (`MasterPlanImport`, `MasterTiersImport`, `MasterJournalImport`,
qui lisent par indice : `row[0]` numéro, `row[1]` intitulé, `row[2]` type).

Les quatre classeurs de `public/templates/import/` **ne sont pas des
modèles** : ce sont des exports Sage bruts d'ELIKET MARKET, tirage du
22/01/2026, bandeau de titre et cellules fusionnées compris.

| Fichier | Contient | Ce que l'import en tire |
|---|---|---|
| `modele_plan_comptable.xlsx` | 26 comptes | **0** — `row[0]` vaut « Détail », aucun chiffre |
| `modele_plan_tiers.xlsx` | 2 tiers | **2 lignes inversées** — un tiers numéroté « FOURNISSEUR », intitulé « 401000 » |
| `modele_codes_journaux.xlsx` | 1 journal | 1 ligne sans son type |
| `modele_ecritures.xlsx` | 6 feuilles d'impression | rien — **aucune route ne l'importe** |

S'y ajoutent deux défauts de code : `plan_tiers.compte_general` est `NOT NULL`
et l'importeur ne le renseigne pas (violation d'intégrité même avec un bon
fichier) ; et `AdminConfigController:520` construit `MasterPlanImport` sans le
chemin du fichier, donc `detectDelimiter()` retombe toujours sur `;`.

Et le dépôt **publie la comptabilité réelle d'un client**.

**Les quatre classeurs refaits sont dans
`PASSERELLE-COMPTAFLOW/modeles-import/`** — une feuille, une en-tête,
l'ordre que les importeurs lisent, exemples SYSCOHADA neutres. Vérifiés :
7 comptes, 4 tiers, 5 journaux retenus, seule l'en-tête écartée. La marche à
suivre est en section 7 de `CONSIGNES-POUR-COMPTAFLOW.md`.

### B2B

Le flux va de la demande de prix à la proposition, puis crée directement une
vente et un achat. **L'étape du devis n'existe pas** : ni pièce opposable, ni
validité, ni acceptation, ni renégociation.

- **Le B2B est une fonctionnalité incluse, non un module — tranché par le
  propriétaire du projet.** Le groupe de routes `admin.b2b.*` ne porte donc
  aucun `modules:b2b`, contrairement à `ventes`, `achats`, `stock`,
  `production` et `comptabilite` : une entreprise qui a `ventes` ou `achats`
  a le B2B avec. Ce n'est pas un oubli — le menu le disait déjà, en rangeant
  les liens B2B sous ces deux sections plutôt que sous une section propre.
  `b2b` figure encore dans `Entreprise::TOUS_LES_MODULES` : la valeur reste
  acceptée pour ne pas casser les entreprises qui la portent déjà, mais elle
  ne commande rien. Un test verrouille le comportement.

---

## 7. Ce qui est sain, vérifié

Pour ne pas re-auditer inutilement :

- Aucune interpolation de variable dans du SQL brut.
- Aucun modèle avec `$guarded = []`.
- `$request->all()` n'apparaît qu'à l'intérieur d'un validateur.
- Les routes superadmin sont derrière `role:superadmin`.
- Le B2B vérifie systématiquement que la négociation concerne l'entreprise.
- La connexion est limitée en tentatives, sur le web comme sur l'API.
- La normalisation n'apparaît que sur les écrans de factures ; devis, commandes
  et bons de livraison n'en portent pas, et le serveur refuse toute pièce dont
  l'étape n'est pas « Facture ».
- La double normalisation est bloquée des deux côtés.
- L'état `normalise` est porté par la pièce : l'admin voit partout ce qu'un
  responsable a certifié dans son point de vente.

---

## 8. Conventions de travail

- Développement sur `claude/selflow-remises-taxes-dgi-a5je2b`, puis fusion dans
  `main` à chaque lot.
- Toujours préciser **sur quel dépôt** un envoi a été fait — Selflow ou
  Comptaflow.
- `php artisan test` et `php artisan verifier:variables` avant chaque envoi.
- Les commentaires de code expliquent **pourquoi**, en rapportant ce qui avait
  échoué — pas ce que le code fait, qui se lit.
- **Ne jamais retoucher une migration déjà appliquée en production.** Elle ne
  se rejouera pas : le fichier changera, la base non. Ce qui manque se pose par
  une migration nouvelle, datée du jour. Deux incidents en sont venus — les
  colonnes de `entreprises` le 20 juillet, celles de `produits` le même jour,
  qui ont bloqué le déploiement du 27 août (lot 21).
- **Un déversement est un import.** Ce qui entre chez Comptaflow prend la
  convention du dossier et garde son numéro d'origine dessous ; Selflow ne
  dicte jamais la configuration d'un dossier (lot 23).
- **Une migration décrit un état, pas un geste.** Ce qu'elle retire, elle
  vérifie d'abord que c'est là ; ce qu'elle pose, elle vérifie d'abord que ce
  ne l'est pas. `MigrationsRejouablesTest` le tient.

### Le plan de travail en PDF

`Plan-de-travail-Selflow.pdf`, à la racine, est la version lisible hors dépôt
de ce journal : l'état des lots, ce qui reste chez le propriétaire, ce qui
reste chez le mainteneur de Comptaflow, la règle d'or FNE, le choix
d'imprimante, la comptabilité. **Le mettre à jour fait partie du lot**, comme
ce journal — le propriétaire le demande à chaque fois.

Il était fabriqué par un script reportlab, `plan.py`, perdu avec la session
qui le tenait. Depuis la 9ᵉ édition, il est écrit en HTML et imprimé par
Chrome sans affichage ; la source reste hors dépôt, et c'est le PDF qui fait
foi.

| Édition | Date | Pages | Ce qu'elle ajoute |
|---|---|---|---|
| 1<sup>re</sup> | 15/08/2026 | 8 | l'état initial, TERNE, l'imprimante, la comptabilité |
| 2<sup>e</sup> | 15/08/2026 | 10 | la passerelle fusionnée, les modèles d'import, les libellés (section 7) |
| 3<sup>e</sup> | 16/08/2026 | 14 | la numérotation des tiers (section 8), la page d'accueil (section 9), le filtrage à l'import, `tier_digits` à vérifier chez Comptaflow |
| 4<sup>e</sup> | 21/08/2026 | 16 | les lots 9 et 10 au tableau, les écritures de vente (section 10), la création d'un compte (section 11), le point d'entrée à écrire chez Comptaflow porté en tête des points bloquants, trois décisions arrêtées de plus |
| 5<sup>e</sup> | 24/08/2026 | 17 | le lot 11 (section 12), le volet achat des écritures — le 401 et la TVA déductible par nature —, le régime d'imposition au volet FNE, deux décisions arrêtées de plus, et le point de la colonne `montant_autres_taxes` à trancher |
| 6<sup>e</sup> | 24/08/2026 | 18 | le lot 12 (section 13) : la colonne de taxes retirée, le résultat par site, et ses cinq décisions. La section 7 passe de « ce que je propose » à « ce qui a été livré » ; les deux chantiers de confort quittent la liste de ce qui reste, où il ne demeure que le point d'entrée de Comptaflow |
| 7<sup>e</sup> | 25/08/2026 | 20 | le lot 13 (section 14) : la photo de fond et son vrai motif, le secteur déduit du parcours, le verrou sur ce qui porte des données, les modules rouverts, `selflow:photos`. Les lots 12 et 13 entrent au tableau des lots livrés ; la section 13 dit désormais que les deux tables de taxes de l'achat sont parties, et que ce retrait a mis au jour l'absence de ligne de TVA au pavé de l'achat. Trois coupures de page forcées sont remplacées par des espaces : elles laissaient trois feuillets à trois ou cinq lignes |
| 8<sup>e</sup> | 27/08/2026 | 32 | les lots 14 à 20 (sections 15 à 21), et les sept lignes correspondantes au tableau des lots livrés. La section 2.2 change de nature : le point d'entrée qui reçoit le référentiel **n'est plus le point bloquant** — la session Comptaflow l'a livré —, remplacé par la rotation des clés et sa période de grâce, le retrait conjoint des tolérances de transition, la fenêtre de liaison qui demande encore le mot de passe d'un client, l'`APP_URL` erronée et les fichiers d'exemple qui portent une clé. Un tableau nouveau dit ce que la session Comptaflow a livré et trouvé — dont `tier_digits` qui valait 8 et non 6. `EXTERNAL_SYNC_SECRET` passe de « à poser » à **« à changer »** et rejoint ce qui revient au propriétaire : la valeur en place est publiée dans l'historique |
| 9<sup>e</sup> | 14/09/2026 | 13 | les lots 21 à 24 (sections 22 à 25). La section 2 change de nature : ce qui reste n'est plus du code mais un ordre de déploiement — Selflow, Comptaflow, puis l'alignement des lignes déjà reçues. Les tolérances, la rotation et la liaison depuis Comptaflow passent de « à faire » à « livré ». La section 8 dit que la convention des tiers ne commande plus la passerelle. Refait en HTML imprimé par Chrome : `plan.py` est perdu |
| 10<sup>e</sup> | 14/09/2026 | 14 | le lot 25 (section 26) : les branches fusionnées sur `main`, dont le portail FNE du collaborateur, et les comptes transportés en ligne par leur empreinte. Le déploiement gagne deux étapes : les comptes, et le planificateur |

### L'état de l'application en PDF

`Etat-de-Selflow.pdf`, à la racine, répond à une autre question que le plan de
travail. Le plan regarde **devant** — ce qui reste à faire, dans quel ordre,
et chez qui. L'état regarde **ce qui est** : ce que l'application sait faire
aujourd'hui domaine par domaine, la conformité fiscale acquise, la passerelle,
les portes qui ont été fermées, les épreuves qui gardent l'ensemble, et les
trois choses qui séparent encore le projet d'une exploitation réelle.

Il se donne à lire par quelqu'un qui ne connaît pas le dépôt — un associé, un
cabinet, un client. Les deux documents ne se remplacent pas : ils se lisent
ensemble.

| Édition | Date | Pages | Chiffres arrêtés |
|---|---|---|---|
| 1<sup>re</sup> | 21/08/2026 | 7 | 711 épreuves / 3 331 vérifications, 262 classes PHP, 317 routes, 94 migrations, 171 révisions, révision `6bb1b16` |
| 2<sup>e</sup> | 21/08/2026 | 7 | le lot 9 : 752 épreuves / 3 441 vérifications, 264 classes, 95 migrations, 176 révisions, révision `5113e9d`. La passerelle n'est plus décrite comme bidirectionnelle — elle ne l'était pas |
| 3<sup>e</sup> | 21/08/2026 | 7 | le lot 10 : 779 épreuves / 3 514 vérifications, 268 classes, 97 migrations, 180 révisions, révision `af62a53`. La création d'un compte entre au tableau des domaines |
| 4<sup>e</sup> | 24/08/2026 | 8 | le lot 11 : 810 épreuves / 3 612 vérifications, 269 classes, 111 migrations, 187 révisions, révision `9d819c1`. Le 401 et la TVA déductible par nature à la ligne « Achats », les articles sans gestion de stock à la ligne « Stock », la question FNE avant toute information fiscale à la ligne « Création d'un compte » ; le point d'entrée à écrire chez Comptaflow porté en tête des points bloquants, et la colonne `montant_autres_taxes` au tableau de ce qui reste à trancher |
| 5<sup>e</sup> | 24/08/2026 | 7 | le lot 12 : 847 épreuves / 3 684 vérifications, 275 classes, 113 migrations, 190 révisions, révision `a3d9630`. Les libellés et le résultat par site entrent à la ligne « Comptabilité » ; la table des chantiers proposés disparaît — il n'en reste aucun |
| 6<sup>e</sup> | 25/08/2026 | 8 | le lot 13 : 885 épreuves / 3 767 vérifications, 276 classes, 319 routes, 116 migrations, 195 révisions, révision `8d7d6ad`. Une ligne « Paramétrage » entre au tableau des domaines ; la photo de fond rejoint la ligne « Ventes ». La ligne « Taxes personnalisées à l'achat » quitte ce qui reste — les deux tables sont supprimées — et cède la place au diagnostic des photos |
| 7<sup>e</sup> | 27/08/2026 | 10 | les lots 14 à 20 : 1 040 épreuves / 4 152 vérifications, 283 classes, 322 routes, 119 migrations, 205 révisions, révision `0b329c4`. Une ligne **« Points de vente »** entre au tableau des domaines — le nom du site part à la DGI, l'application n'en invente plus aucun, et le site actif survit à la déconnexion ; la ligne « Comptabilité » dit que le plan OHADA est livré **en entier** et non plus par ses 41 comptes communs. La section 3 gagne « Une clé par dossier, et non un secret pour tous » ; la section 4 gagne cinq portes fermées de plus, dont la clé qui se collait dans un formulaire et l'annuaire des clients qu'un secret volé ouvrait. Le tableau des épreuves gagne un domaine « Passerelle et liaison » et dit combien d'épreuves tombent sans leur correctif. Une section 7 nouvelle résume les sept lots ; la section 8 est l'état du dépôt |
| 8<sup>e</sup> | 14/09/2026 | 5 | les lots 21 à 24 : 1068 épreuves / 4216 vérifications, 323 routes, 120 migrations, 218 révisions. La section 3 devient « un déversement est un import » ; la section 4 gagne les tolérances fermées, la liaison qui ne se détourne pas et l'avoir de BAPA ; la section 6 porte l'ordre de déploiement. Refait en HTML imprimé par Chrome : `etat.py` est perdu |
| 9<sup>e</sup> | 14/09/2026 | 5 | le lot 25 : 1221 épreuves / 4836 vérifications, 243 révisions. Le portail FNE entre au tableau des domaines ; la sécurité gagne le fichier de comptes retouché ; le déploiement, les comptes et le planificateur |

Fabriqué par `etat.py`, dans le répertoire de travail de la session, non
versionné — comme `plan.py`, c'est le PDF qui fait foi.
