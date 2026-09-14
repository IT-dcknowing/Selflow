# Le scraper du portail FNE — ce que Selflow attend de lui

Ce dossier accueille le script qui relève le portail de la DGI. Selflow ne va
sur le portail nulle part : il **lit un dossier** et **publie une file**. Ce que
le scraper fait entre les deux — navigateur piloté, requêtes HTTP, saisie
manuelle — ne le regarde pas, et c'est délibéré : l'interface de la DGI change,
la conformité fiscale de Selflow ne doit pas changer avec elle.

Le contrat tient en deux gestes.

---

## 1. Lire la file — ce qu'il faut aller relever

```
php artisan portail-fne:demandes --json
```

Rend la liste des logins attendus, et rien d'autre :

```json
["1864699A", "2201455B"]
```

Une file vide rend `[]`. Ce n'est pas une erreur : « rien à relever » est une
réponse, et le scraper doit s'arrêter là.

Le **login est celui du portail**, en pratique le NCC de l'entreprise. C'est la
seule donnée que Selflow transmet au scraper : ni identifiant interne, ni nom
d'entreprise, ni numéro de facture.

Sans `--json`, la même commande rend un tableau lisible à l'écran, avec le motif
de chaque demande (« Rejet FA-0042 sur pointOfSale ») et sa date.

**Une demande apparaît dans la file quand la DGI refuse une pièce.** C'est le
seul moment où un relevé sert vraiment à quelque chose. Dix rejets d'affilée sur
le même point de vente n'ouvrent qu'une demande.

Rien n'interdit de relever aussi sans demande — un passage quotidien de nuit est
une bonne idée pour tenir les fiches à jour. La file dit ce qui est *urgent*,
pas ce qui est *permis*.

---

## 2. Déposer les fichiers

**Dossier de dépôt** — celui que désigne `PORTAIL_FNE_DOSSIER_IMPORT` dans
`.env`. Aujourd'hui, sur ce poste :

```
storage/app/portail-fne/
```

**Deux fichiers par entreprise**, nommés `<login>_<date>` :

| Fichier | Contenu |
|---|---|
| `1864699A_20260821.json` | la fiche de l'entreprise |
| `1864699A_20260821.xlsx` | les points de facturation, un par ligne |

Formats de date acceptés : `Ymd` (`20260821`), `Y-m-d`, `d-m-Y`, `dmY`.

**Un nom hors nomenclature est refusé et signalé, jamais rattaché au hasard.**
Ranger le relevé fiscal d'un client dans le dossier d'un autre ne se répare pas.

Le login peut contenir lui-même un tiret bas : la découpe se fait au **dernier**,
donc `LOGIN_CLIENT_20260821.json` désigne bien le login `LOGIN_CLIENT`.

### Le JSON de la fiche

Un objet plat, **clés en français, accents et apostrophes compris**, exactement
comme le portail les affiche. Les quatorze clés reconnues :

```json
{
  "Email": "it.dcknowing@gmail.com",
  "Téléphone": "2722421443",
  "Adresse": "8XVQ+29Q",
  "Commune": "COCODY",
  "Quartier": "RIVIERA II AFRICAINE",
  "Référence Cadastrale": "*",
  "IDU": "*",
  "Propriétaire du local professionnel de l'entreprise": null,
  "Sticker : solde d'alerte": "5000",
  "Références bancaires": null,
  "Timbre de quittance": true,
  "Bordereau d'achat de produits agricoles": true,
  "Pied de page des factures": null,
  "Factures autres mentions": null
}
```

Trois libertés, et une seule contrainte :

- **les valeurs peuvent être typées ou entre guillemets.** `"5000"` et `5000`
  arrivent tous deux dans un entier ; `true` et `"true"` dans un booléen ;
- **`"*"` et `null` sont compris comme « le portail n'a rien »** — ils ne
  comptent pas comme des écarts avec le paramétrage de Selflow ;
- **une clé inconnue n'est pas perdue.** Elle part dans `champs_inconnus` et un
  avertissement est journalisé. Le portail peut ajouter un champ du jour au
  lendemain ; il sera visible avant d'être exploité.

La contrainte : **ne pas renommer les clés.** La correspondance est explicite
dans `ImportPortailFneService::CHAMPS_FICHE`, précisément pour qu'un libellé qui
change casse bruyamment au lieu d'arriver en silence dans la mauvaise colonne.

### Le tableur des points de facturation

Une feuille, **une ligne d'en-tête**, puis une ligne par point :

| Nom | Outil | ID du terminal | Statut | Raison de statut | ID de l'établissement | Créé à | Mise à jour à |
|---|---|---|---|---|---|---|---|
| FACTURATION SIEGE | Application FNE | | 1 | | 42200613-f402-… | 2026-07-30T10:38:40.726Z | 2026-07-30T10:38:40.726Z |

**La lecture suit les en-têtes, jamais les positions** : les colonnes peuvent
être réordonnées sans rien casser. Les accents et la casse sont indifférents —
« ID de l'établissement » et « id de l etablissement » désignent la même
colonne. Les lignes entièrement vides sont ignorées.

`Statut` vaut `1` pour un point ouvert. Toute autre valeur est conservée telle
quelle et le point n'est pas tenu pour actif.

---

## Ce que le scraper n'a pas à faire

- **Ne rien déplacer, ne rien supprimer après coup.** C'est l'empreinte SHA-256
  du contenu qui tient lieu de marque de traitement : redéposer le même fichier
  est sans effet, et le dossier peut être relu en entier sans précaution.
- **Ne pas fermer les demandes.** Une demande passe à `servie` uniquement quand
  un fichier portant ce login est réellement rangé en base. Un scraper qui
  échoue en silence laisse donc sa demande ouverte — c'est voulu, et c'est le
  seul endroit où l'on verra qu'il ne fonctionne plus.
- **Ne toucher à aucune table.** Selflow lit le dossier tout seul, toutes les
  heures.

---

## Ce que Selflow fait de son côté, sans qu'on le lui demande

| Quand | Quoi |
|---|---|
| Toutes les heures | `portail-fne:importer` — range ce qui est dans le dossier |
| Toutes les heures, minute 10 | `fne:diagnostiquer-rejets` — rapproche les pièces refusées du dernier relevé |

Sortie des deux tâches : `storage/logs/portail-fne.log`.

Toutes les heures et non toutes les minutes : un relevé se produit au mieux une
fois par jour, et relire un dossier soixante fois par heure ne le fait pas
arriver plus tôt.

⚠️ **Ces tâches ne tournent que si `php artisan schedule:run` est appelé chaque
minute.** Sur ce poste, la tâche Windows « Selflow - planificateur » s'en
charge. En production (Linux), la ligne cron reste à poser :

```
* * * * * cd /chemin/selflow && php artisan schedule:run >> /dev/null 2>&1
```

---

## Ce qui se corrige tout seul — et ce qui ne se corrigera jamais

Depuis le 29/08/2026, et à la demande du propriétaire du projet, **un seul champ
se corrige sans intervention** : le **nom du point de vente**. Quand la DGI
refuse une pièce sur `pointOfSale` et que le portail ne déclare **qu'un seul**
point de facturation actif, Selflow renomme le point de vente comme le portail
l'écrit, puis renvoie à la DGI toutes les pièces que ce nom faisait refuser.

C'est un libellé descriptif, dont le portail est la source de vérité : la DGI
refuse la pièce précisément parce qu'il ne correspond pas à ce qu'elle a
enregistré. Et si le portail déclare **plusieurs** points, la machine s'abstient
— elle ne sait pas dans lequel la pièce a été établie, et choisir renommerait un
site sur une supposition.

`CorrectionFneService`, `selflow.portail_fne.correction_auto` pour l'éteindre.

**Tout le reste est montré et jamais appliqué.** Les trois champs de la fiche qui
commandent le comportement fiscal — `timbre_quittance`, `bapa`,
`sticker_solde_alerte` — ne bougent pas. Les recopier ferait changer le contenu
d'une facture parce qu'un fichier est arrivé dans un dossier, sans que personne
ne l'ait décidé, et sur la foi d'un relevé dont rien ne garantit la fraîcheur.

C'est la règle d'or du projet, et l'automatisation ne la lève pas : elle porte
sur un **nom**, jamais sur un **montant**. Pour tout le reste, le scraper
**apporte un constat, pas une décision.**

---

## Le scraper livré — `fne.js`

Il est arrivé le 26/08/2026 et vit dans ce dossier. Playwright pilote Chromium,
se connecte au portail, relève la page Paramétrage et dépose les deux fichiers.

### Installation, une fois

Déjà faite sur ce poste — `node_modules`, Chromium, `.env` et
`identifiants.json` sont en place. Ce qui suit ne sert qu'à repartir d'un clone
frais, ou sur une autre machine :

```bash
cd SCRAPER-PORTAIL-FNE
npm install
npx playwright install chromium
cp .env.exemple .env                       # FNE_URL y est déjà
cp identifiants.exemple.json identifiants.json
```

`identifiants.json` **existe déjà** sur ce poste, prérempli avec les NCC trouvés
en base. Il n'y a rien à créer ni à relancer : ouvrir le fichier, écrire un mot
de passe entre les guillemets, enregistrer. Le passage suivant le prend.

```json
{
  "1864699A": "le-mot-de-passe",
  "1234567K": ""
}
```

**Un mot de passe vide vaut « pas encore rempli », pas « en panne ».** Le
passage nocturne ignore ces comptes en le disant sur une ligne, sans échouer :
un journal qui crie tous les soirs pour une situation normale cesse d'être lu.
Le passage qui sert la file, lui, reste bruyant — là, une pièce refusée attend
vraiment son relevé.

**Les clés commençant par `_` sont des notes, pas des logins.** Sans ce tri,
`--tous` prenait le `_lisez-moi` du modèle pour un compte et allait tenter de
s'y connecter sur le portail de la DGI.

**Il est ignoré par git, et c'est délibéré.** Selflow ne transmet que des
logins ; il n'a pas à connaître ces accès, et ils n'ont pas à voyager avec le
dépôt. Le `.env` du scraper l'est aussi. Un login sans mot de passe est signalé
et sa demande reste ouverte — jamais tenté avec un mot de passe approchant.

### Lancer

```bash
node fne.js                        # tous les logins de la file Selflow
node fne.js --tous                 # tous les logins d'identifiants.json (passage de nuit)
node fne.js 1864699A               # ce login, mot de passe pris dans le magasin
node fne.js 1864699A <motDePasse>  # ce login, sans passer par le magasin
```

Sans argument, le scraper appelle lui-même `php artisan portail-fne:demandes
--json` depuis la racine du projet. Une file vide n'ouvre même pas le
navigateur. Le dossier de dépôt est lu dans le `.env` de Selflow
(`PORTAIL_FNE_DOSSIER_IMPORT`) : rien à recopier, rien à tenir en double.

### Ce qui le fait échouer bruyamment

Un scraper muet est pire qu'un scraper absent : il sert la demande avec une
fiche vide, et le relevé se lit comme un succès. Trois garde-fous :

- **moins de quatre champs reconnus sur la page Paramétrage** — le portail a
  changé de structure. Rien n'est déposé, la demande reste ouverte ;
- **un login qui échoue n'arrête pas les autres**, et chaque échec laisse une
  capture d'écran dans `erreurs/` ;
- **la file illisible remonte la cause** (base injoignable, commande inconnue)
  au lieu d'un « ça a raté » — et jamais comme une file vide.

Un contexte de navigateur neuf est ouvert par login : deux entreprises ne
partagent jamais une session.

### La clé d'API n'est jamais relevée

Le portail l'affiche en clair dans un champ texte ordinaire, sur cette même
page. L'écarter par le type du champ ne suffit pas — elle est écartée par son
libellé. Le fichier déposé est lu, archivé en base (`contenu_brut`) et
conservé : ce qui y entre y reste.

### Le lancement automatique

Le scraper est accroché au planificateur de Selflow, dans `routes/console.php`.
Pas de seconde tâche Windows : celle qui existe — « Selflow - planificateur »,
un `php artisan schedule:run` chaque minute — suffit, et tout finit dans le même
journal, `storage/logs/portail-fne.log`.

| Quand | Quoi | Pourquoi cette heure |
|---|---|---|
| Toutes les heures, **minute 40** | `node fne.js` | sert la file ; ce qu'il dépose est rangé à :00 puis diagnostiqué à :10 |
| **02:30**, chaque nuit | `node fne.js --tous` | tient les fiches à jour sans attendre un rejet |

Le cycle complet se lit ainsi : un rejet à 15:05 ouvre une demande, le scraper
la sert à 15:40, `portail-fne:importer` range le fichier à 16:00, et
`fne:diagnostiquer-rejets` rapproche à 16:10.

**Toutes les heures et non toutes les dix minutes.** Quand la file est vide — le
cas ordinaire — le passage s'arrête sans même ouvrir le navigateur, et ne coûte
rien. Mais quand elle ne l'est pas, il ouvre une session sur le portail de la
DGI : y retourner six fois par heure avec un mot de passe éventuellement faux
est le meilleur moyen de faire bloquer le compte.

**Les deux passages tournent en arrière-plan** (`runInBackground`) : un relevé
prend des dizaines de secondes, et sans cela la minute du planificateur resterait
occupée pendant que tout le reste attend. Le verrou expire au bout de 30 minutes
(2 h pour le passage nocturne), sans quoi un navigateur resté planté empêcherait
tous les passages suivants.

#### L'allumer

Dans le `.env` de **Selflow**, pas celui du scraper :

```ini
PORTAIL_FNE_SCRAPER_ACTIF=true
PORTAIL_FNE_NODE="C:/Program Files/nodejs/node.exe"
PORTAIL_FNE_SCRAPER_MINUTE=40
PORTAIL_FNE_SCRAPER_HEURE_NUIT=02:30
```

Puis `php artisan schedule:list` : les deux lignes doivent y figurer.

**Il est éteint par défaut**, et c'est délibéré : sans `identifiants.json`
rempli, chaque passage échouerait, et un journal plein d'erreurs sans objet
cesse d'être lu.

#### Deux chemins absolus, et une raison à chacun

- **`PORTAIL_FNE_NODE`** : la tâche planifiée de Windows n'a pas le PATH d'un
  terminal ouvert à la main. `node` tout court marche à l'essai et échoue une
  fois planifié — le pire des deux mondes.
- **`PHP_BINAIRE`**, dans le `.env` du scraper : même raison, puisque le scraper
  appelle lui-même `php artisan portail-fne:demandes --json`. Le lanceur VBS de
  la tâche Windows porte déjà le chemin absolu de PHP, pour la même cause.

**En barres obliques (`C:/…`) et non en barres inverses.** phpdotenv interprète
les échappements dans une valeur entre guillemets : `"C:\Program Files\nodejs\…"`
y perdrait son `n`, transformé en saut de ligne.

Vérification : la commande doit passer même sans aucun environnement.

```bash
env -i "C:/Program Files/nodejs/node.exe" "…/SCRAPER-PORTAIL-FNE/fne.js"
```

#### Si le scraper tourne sur une autre machine

Alors le planificateur de Selflow ne peut rien lancer, et `portail-fne:demandes`
n'est pas non plus à portée. Deux réglages :

- une tâche planifiée sur cette machine, qui appelle `node fne.js` aux mêmes
  heures ;
- `URL_SERVER` renseigné pour l'envoi HTTP, puisque le dossier d'import n'est
  pas local.

Le contrat, lui, ne change pas : deux fichiers, la bonne nomenclature, et
Selflow lit son dossier.

### Vérifier l'extraction sans toucher au portail

```bash
node verifier-extraction.js
```

Dix-huit vérifications sur une page factice qui reproduit les pièges de la
vraie : libellés portés par un `label[for]`, par un `label` englobant, par un
`aria-label` ou par un simple voisin ; libellés sans accents ni casse ;
apostrophe typographique ; interrupteur ARIA au lieu d'une case à cocher ; clé
d'API en clair ; liste de pagination. C'est la mémoire de ce qui a été constaté
une fois — au même titre que `FnePayloadTest` pour la conformité FNE.

---

## Vérifier que tout est branché

```bash
# La file (vide au repos)
php artisan portail-fne:demandes

# Lire le dossier, ou un seul fichier
php artisan portail-fne:importer
php artisan portail-fne:importer --fichier="chemin/vers/1864699A_20260821.json"

# Rapprocher les rejets du dernier relevé
php artisan fne:diagnostiquer-rejets
```

Deux statuts qui ne sont pas des erreurs :

- **« déjà lu »** — ce fichier-là a déjà été importé. C'est l'empreinte SHA-256
  qui le dit, et c'est ce qui rend le dossier relisible en entier sans
  précaution.
- **« inchangé »** — le fichier est neuf, mais son contenu est identique au
  dernier relevé. Rien n'est écrit en base, sauf la ligne qui prouve que le
  portail a bien été regardé ce jour-là.

Le second existe parce que l'empreinte ne suffit pas : le tableur du portail
embarque un horodatage de génération, et deux exports identiques diffèrent donc
octet pour octet. C'est le **contenu lu** qui est comparé.

Conséquence pour qui écrit un scraper : **ne jamais mettre d'horodatage de
génération dans un fichier déposé.** Ce serait annuler la seule chose qui
distingue un portail qui a bougé d'un portail qui dort.
