<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneDemande;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Recueille les relevés du portail FNE déposés dans un dossier, et les range
 * en base.
 *
 * ## Ce qu'il lit
 *
 * Deux fichiers par entreprise, nommés `<login>_<date>.json` et
 * `<login>_<date>.xlsx` :
 *
 * - le **JSON** porte la fiche de l'entreprise — adresse, commune, quartier,
 *   solde d'alerte des stickers, timbre de quittance, bordereau d'achat ;
 * - le **tableur** porte les points de facturation, une ligne par point.
 *
 * Le `login` est celui du portail, en pratique le NCC : c'est par lui que le
 * relevé retrouve son entreprise dans Selflow. Un nom qui ne suit pas la
 * nomenclature est laissé de côté et signalé — deviner à quelle entreprise
 * appartient un fichier nommé au hasard reviendrait à ranger les données
 * fiscales d'un client chez un autre.
 *
 * ## Ce qu'il ne fait pas, et c'est délibéré
 *
 * **Il n'écrit rien dans `entreprises`, ni nulle part ailleurs dans
 * l'application.** Il dépose ce qu'il a lu dans ses trois tables, et s'arrête
 * là. Trois des champs relevés — `timbre_quittance`, `bapa`,
 * `sticker_solde_alerte` — commandent le comportement fiscal de Selflow : les
 * recopier automatiquement ferait changer une facture parce qu'un fichier a été
 * déposé dans un dossier, sans que personne ne l'ait décidé. Le rapprochement
 * se regarde (`PortailFneFiche::ecartsAvecEntreprise()`) avant de s'appliquer.
 *
 * **Il ne déplace ni ne supprime les fichiers lus.** L'empreinte SHA-256 rend
 * la relecture d'un dossier sans effet : c'est elle qui tient lieu de marque de
 * traitement, pas le déplacement du fichier. Le dossier d'origine reste donc
 * intact, et un relevé peut être relu après correction d'un défaut d'import.
 *
 * **Il n'enregistre jamais deux fois la même chose — pas même une ligne
 * d'import.** Avant d'écrire quoi que ce soit, le contenu lu est comparé au
 * dernier relevé connu du même login. S'il dit la même chose, la ligne
 * existante est **confirmée** (`dernier_releve_le` avance, `releves` monte) et
 * rien n'est créé : ni import, ni fiche, ni points.
 *
 * Deux raisons, et la seconde est la vraie :
 *
 * 1. le portail ne change presque jamais, et une ligne par passage pour dire la
 *    même chose n'apprend rien à personne ;
 * 2. surtout, `DiagnosticFneService::diagnosticEstAJour()` compare
 *    l'identifiant de la dernière fiche. Une fiche neuve, fût-elle identique au
 *    mot près, périmait chaque nuit **tous** les diagnostics de rejets, qui
 *    étaient alors rejoués pour aboutir au même constat. Désormais un
 *    diagnostic n'est périmé que lorsque le portail a réellement bougé.
 *
 * L'empreinte du fichier ne pouvait pas suffire à cela : le tableur du portail
 * embarque un horodatage de génération (`dcterms:created`), et deux exports
 * identiques diffèrent donc octet pour octet. C'est le **contenu ramené à sa
 * forme canonique** qui est comparé (`empreinteDuContenu()`), jamais les octets.
 *
 * Trois dates à ne pas confondre sur une ligne d'import :
 *
 * | Colonne | Ce qu'elle dit |
 * |---|---|
 * | `date_scraping` | depuis quel relevé le portail affiche **ce** contenu |
 * | `dernier_releve_le` | quand on l'a vu pour la dernière fois |
 * | `created_at` | quand Selflow l'a rangé |
 *
 * Qui veut savoir si le scraper tourne encore lit `dernier_releve_le`. Qui veut
 * savoir depuis quand un paramétrage est en place lit `date_scraping`.
 *
 * ## Usage
 *
 *   app(ImportPortailFneService::class)->importerDossier();
 *   app(ImportPortailFneService::class)->importerFichier('C:/…/1864699A_20260821.json');
 */
class ImportPortailFneService
{
    /**
     * Les clés du JSON du portail, et la colonne qui les reçoit.
     *
     * Le portail rend ses libellés en français, accents et apostrophes
     * compris. La correspondance est explicite plutôt que déduite : un libellé
     * qui change au portail doit casser bruyamment ici, et non arriver
     * silencieusement dans la mauvaise colonne.
     *
     * @var array<string, string>
     */
    private const CHAMPS_FICHE = [
        'Email' => 'email',
        'Téléphone' => 'telephone',
        'Adresse' => 'adresse',
        'Commune' => 'commune',
        'Quartier' => 'quartier',
        'Référence Cadastrale' => 'reference_cadastrale',
        'IDU' => 'idu',
        "Propriétaire du local professionnel de l'entreprise" => 'proprietaire_local',
        "Sticker : solde d'alerte" => 'sticker_solde_alerte',
        'Références bancaires' => 'ref_bancaire',
        'Timbre de quittance' => 'timbre_quittance',
        "Bordereau d'achat de produits agricoles" => 'bapa',
        'Pied de page des factures' => 'pied_de_page_facture',
        'Factures autres mentions' => 'facture_autres_mentions',
    ];

    /**
     * Les en-têtes du tableur, et la colonne qui les reçoit.
     *
     * La lecture se fait par en-tête et non par position : le portail peut
     * réordonner ses colonnes, et un import qui compte les colonnes rangerait
     * alors un statut dans un nom d'établissement.
     *
     * @var array<string, string>
     */
    private const COLONNES_POINTS = [
        'nom' => 'nom',
        'outil' => 'outil',
        'id du terminal' => 'terminal_id',
        'statut' => 'statut',
        'raison de statut' => 'raison_statut',
        "id de l'etablissement" => 'etablissement_id',
        'cree a' => 'cree_a',
        'mise a jour a' => 'mis_a_jour_a',
    ];

    /**
     * Les champs de la fiche qui sont des nombres, et ceux qui sont des
     * booléens. Tout le reste est conservé tel quel.
     */
    private const CHAMPS_ENTIERS = ['sticker_solde_alerte'];
    private const CHAMPS_BOOLEENS = ['timbre_quittance', 'bapa'];

    /**
     * Lit tous les relevés d'un dossier.
     *
     * @param  string|null  $dossier  Le dossier à parcourir. Par défaut, celui
     *                                de `config('selflow.portail_fne.dossier_import')`.
     * @return array{dossier: string, importes: int, ignores: int, inchanges: int, erreurs: int, details: array<int, array<string, mixed>>}
     */
    public function importerDossier(?string $dossier = null): array
    {
        $dossier = $dossier ?: (string) config('selflow.portail_fne.dossier_import');

        $rapport = [
            'dossier' => $dossier,
            'importes' => 0,
            'ignores' => 0,
            'inchanges' => 0,
            'erreurs' => 0,
            'details' => [],
        ];

        if (!is_dir($dossier)) {
            $rapport['erreurs']++;
            // Par `resultat()`, et non à la main : la ligne bâtie ici n'avait
            // ni `import_id` ni `lignes`, et la commande qui met le rapport en
            // tableau tombait sur « Undefined array key "lignes" ». Personne ne
            // l'avait vu tant que le dossier existait toujours.
            $rapport['details'][] = $this->resultat(
                $dossier,
                'erreur',
                "Le dossier d'import n'existe pas."
            );

            return $rapport;
        }

        $fichiers = array_merge(
            glob(rtrim($dossier, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [],
            glob(rtrim($dossier, '/\\') . DIRECTORY_SEPARATOR . '*.xlsx') ?: [],
        );

        // Les plus anciens d'abord : le dernier relevé lu doit être le plus
        // récent, sinon l'ordre des lignes en base ment sur l'ordre des faits.
        sort($fichiers);

        foreach ($fichiers as $chemin) {
            $resultat = $this->importerFichier($chemin);

            $rapport['details'][] = $resultat;
            $cle = match ($resultat['statut']) {
                'importe' => 'importes',
                'ignore' => 'ignores',
                'inchange' => 'inchanges',
                default => 'erreurs',
            };
            $rapport[$cle]++;
        }

        return $rapport;
    }

    /**
     * Lit un relevé et le range en base.
     *
     * Trois issues, et non deux. `ignore` dit « ce fichier-là a déjà été lu »,
     * `inchange` dit « ce fichier est neuf, mais il ne raconte rien de neuf ».
     * Les confondre reviendrait à ne plus distinguer un scraper qui tourne
     * dans le vide d'un portail qui n'a simplement pas bougé.
     *
     * @return array{fichier: string, statut: 'importe'|'ignore'|'inchange'|'erreur', message: string, import_id: int|null, lignes: int}
     */
    public function importerFichier(string $chemin): array
    {
        $nom = basename($chemin);

        if (!is_file($chemin) || !is_readable($chemin)) {
            return $this->resultat($nom, 'erreur', 'Fichier introuvable ou illisible.');
        }

        $nomenclature = $this->analyserNom($nom);

        if ($nomenclature === null) {
            return $this->resultat(
                $nom,
                'erreur',
                'Nom hors nomenclature : attendu <login>_<date>.json ou <login>_<date>.xlsx.'
            );
        }

        [$login, $date, $type] = [$nomenclature['login'], $nomenclature['date'], $nomenclature['type']];

        $empreinte = hash_file('sha256', $chemin);

        $dejaVu = PortailFneImport::where('fichier_empreinte', $empreinte)->first();

        if ($dejaVu) {
            return $this->resultat(
                $nom,
                'ignore',
                "Déjà importé le {$dejaVu->created_at->format('d/m/Y à H:i')}.",
                $dejaVu->id,
                $dejaVu->lignes_importees
            );
        }

        $entreprise = $this->resoudreEntreprise($login);

        try {
            return DB::transaction(function () use ($chemin, $nom, $login, $date, $type, $empreinte, $entreprise) {
                $donnees = $type === PortailFneImport::TYPE_FICHE
                    ? $this->lireJson($chemin)
                    : $this->lireTableur($chemin);

                $contenu = $this->empreinteDuContenu($type, $donnees);
                $precedent = $this->dernierReleveDeMemeContenu($login, $type, $contenu);

                // Le portail redit ce qu'il disait déjà : on ne crée rien, on
                // confirme. Une ligne de plus pour un contenu identique ferait
                // grossir la table sans rien apprendre, et il faudrait ensuite
                // écarter les doublons partout où ces lignes sont lues.
                if ($precedent !== null) {
                    $this->confirmerLeReleve($precedent, $date, $entreprise?->id);
                    $this->servirLesDemandes($login, $precedent);

                    return $this->resultat(
                        $nom,
                        'inchange',
                        sprintf(
                            'Identique au relevé du %s : confirmé, rien de neuf à enregistrer.',
                            $precedent->date_scraping?->format('d/m/Y') ?? '?'
                        ),
                        $precedent->id,
                        $precedent->lignes_importees
                    );
                }

                $import = PortailFneImport::create([
                    'entreprise_id' => $entreprise?->id,
                    'login' => $login,
                    'date_scraping' => $date,
                    'type' => $type,
                    'fichier_nom' => $nom,
                    'fichier_empreinte' => $empreinte,
                    'contenu_empreinte' => $contenu,
                    'donnees_brutes' => $donnees,
                    'statut' => PortailFneImport::STATUT_IMPORTE,
                    'importe_at' => now(),
                    'dernier_releve_le' => $date,
                    'releves' => 1,
                ]);

                $lignes = $type === PortailFneImport::TYPE_FICHE
                    ? $this->rangerFiche($import, $donnees)
                    : $this->rangerPoints($import, $donnees);

                $import->update(['lignes_importees' => $lignes]);

                $this->servirLesDemandes($login, $import);

                $message = $entreprise
                    ? "Rattaché à {$entreprise->nom}."
                    : "Aucune entreprise ne porte le NCC {$login} : relevé conservé sans rattachement.";

                return $this->resultat($nom, 'importe', $message, $import->id, $lignes);
            });
        } catch (Throwable $e) {
            // La trace du fichier fautif survit à l'échec, hors transaction :
            // sans elle, un fichier illisible se redéposerait indéfiniment sans
            // que rien n'explique pourquoi il n'arrive jamais en base.
            $import = PortailFneImport::create([
                'entreprise_id' => $entreprise?->id,
                'login' => $login,
                'date_scraping' => $date,
                'type' => $type,
                'fichier_nom' => $nom,
                'fichier_empreinte' => $empreinte,
                'statut' => PortailFneImport::STATUT_ERREUR,
                'message' => $e->getMessage(),
            ]);

            Log::error('Import portail FNE : lecture impossible', [
                'fichier' => $nom,
                'import_id' => $import->id,
                'erreur' => $e->getMessage(),
            ]);

            return $this->resultat($nom, 'erreur', $e->getMessage(), $import->id);
        }
    }

    /**
     * Décompose `1864699A_20260821.json` en login, date et type.
     *
     * Le login est **tout ce qui précède le dernier tiret bas**, et non le
     * premier segment : `LOGIN_CLIENT_20260821` désigne un login qui contient
     * lui-même un tiret bas, et découper au premier le tronquerait.
     *
     * @return array{login: string, date: CarbonImmutable, type: string}|null
     */
    private function analyserNom(string $nom): ?array
    {
        $extension = strtolower(pathinfo($nom, PATHINFO_EXTENSION));

        $type = match ($extension) {
            'json' => PortailFneImport::TYPE_FICHE,
            'xlsx' => PortailFneImport::TYPE_POINTS,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $base = pathinfo($nom, PATHINFO_FILENAME);
        $position = strrpos($base, '_');

        if ($position === false || $position === 0) {
            return null;
        }

        $login = substr($base, 0, $position);
        $date = $this->lireDate(substr($base, $position + 1));

        if ($date === null || trim($login) === '') {
            return null;
        }

        return ['login' => trim($login), 'date' => $date, 'type' => $type];
    }

    /**
     * Les deux écritures de date rencontrées dans les noms de fichiers.
     */
    private function lireDate(string $valeur): ?CarbonImmutable
    {
        foreach (['Ymd', 'Y-m-d', 'd-m-Y', 'dmY'] as $format) {
            $date = CarbonImmutable::createFromFormat('!' . $format, $valeur);

            if ($date !== false && $date->format($format) === $valeur) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Retrouve l'entreprise que désigne le login du portail.
     *
     * La comparaison ignore les espaces et la casse : le portail rend
     * `1864699A` là où Selflow enregistre parfois `1864699 A`. Le NCC d'abord,
     * le compte contribuable ensuite — c'est le NCC qui identifie l'entreprise
     * auprès de la DGI.
     */
    private function resoudreEntreprise(string $login): ?Entreprise
    {
        $recherche = $this->normaliser($login);

        if ($recherche === '') {
            return null;
        }

        foreach (['ncc', 'compte_contribuable'] as $colonne) {
            $entreprise = Entreprise::whereNotNull($colonne)
                ->where($colonne, '<>', '')
                ->get()
                ->first(fn(Entreprise $e) => $this->normaliser((string) $e->{$colonne}) === $recherche);

            if ($entreprise) {
                return $entreprise;
            }
        }

        return null;
    }

    private function normaliser(string $valeur): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $valeur) ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function lireJson(string $chemin): array
    {
        $contenu = file_get_contents($chemin);

        if ($contenu === false) {
            throw new \RuntimeException('Lecture du fichier impossible.');
        }

        $donnees = json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($donnees)) {
            throw new \RuntimeException('Le JSON ne contient pas un objet.');
        }

        return $donnees;
    }

    /**
     * Lit le tableur et rend ses lignes sous forme de tableaux associatifs,
     * clés = en-têtes de la première ligne.
     *
     * @return array<int, array<string, string|null>>
     */
    private function lireTableur(string $chemin): array
    {
        $lecteur = IOFactory::createReaderForFile($chemin);
        $lecteur->setReadDataOnly(true);

        $feuille = $lecteur->load($chemin)->getActiveSheet();
        $lignes = $feuille->toArray(null, true, false, false);

        if ($lignes === []) {
            return [];
        }

        $entetes = array_map(
            fn($entete) => trim((string) $entete),
            array_shift($lignes)
        );

        $resultat = [];

        foreach ($lignes as $ligne) {
            $associee = [];

            foreach ($entetes as $index => $entete) {
                if ($entete === '') {
                    continue;
                }

                $valeur = $ligne[$index] ?? null;
                $associee[$entete] = $valeur === null ? null : trim((string) $valeur);
            }

            // Une ligne entièrement vide est un artefact du tableur, pas un
            // point de facturation.
            if (array_filter($associee, fn($v) => $v !== null && $v !== '') !== []) {
                $resultat[] = $associee;
            }
        }

        return $resultat;
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function rangerFiche(PortailFneImport $import, array $donnees): int
    {
        $valeurs = [
            'import_id' => $import->id,
            'entreprise_id' => $import->entreprise_id,
            'login' => $import->login,
            'date_scraping' => $import->date_scraping,
        ];

        $inconnus = [];

        foreach ($donnees as $libelle => $valeur) {
            $colonne = self::CHAMPS_FICHE[trim((string) $libelle)] ?? null;

            if ($colonne === null) {
                $inconnus[$libelle] = $valeur;
                continue;
            }

            $valeurs[$colonne] = $this->convertir($colonne, $valeur);
        }

        $valeurs['champs_inconnus'] = $inconnus ?: null;

        if ($inconnus !== []) {
            Log::warning('Import portail FNE : champs inconnus dans la fiche', [
                'fichier' => $import->fichier_nom,
                'champs' => array_keys($inconnus),
            ]);
        }

        PortailFneFiche::create($valeurs);

        return 1;
    }

    /**
     * L'empreinte de ce que le portail dit, et non de ce que le fichier contient.
     *
     * L'empreinte du fichier ne pouvait pas servir à cela : le tableur du portail
     * embarque un horodatage de génération (`dcterms:created`), et deux exports
     * identiques diffèrent donc octet pour octet.
     *
     * Elle est calculée **après conversion**, de sorte que les libertés que le
     * portail s'autorise ne comptent pas comme des changements : `"5000"` ou
     * `5000`, `"*"` ou `null`, `true` ou `"true"`, colonnes du tableur
     * réordonnées, ligne vide en fin de feuille. Ce qui change, en revanche,
     * c'est un champ inédit du portail — il entre dans l'empreinte, parce qu'un
     * champ nouveau est une nouvelle.
     *
     * Publique parce que la migration de reprise la rejoue sur les
     * `donnees_brutes` déjà en base : la reprise et les relevés à venir doivent
     * parler exactement la même langue.
     *
     * @param  array<mixed>  $donnees
     */
    public function empreinteDuContenu(string $type, array $donnees): string
    {
        $canonique = $type === PortailFneImport::TYPE_FICHE
            ? $this->ficheCanonique($donnees)
            : $this->pointsCanoniques($donnees);

        return hash('sha256', json_encode($canonique, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    private function ficheCanonique(array $donnees): array
    {
        $champs = array_fill_keys(array_values(self::CHAMPS_FICHE), null);
        $inconnus = [];

        foreach ($donnees as $libelle => $valeur) {
            $colonne = self::CHAMPS_FICHE[trim((string) $libelle)] ?? null;

            if ($colonne === null) {
                $inconnus[trim((string) $libelle)] = is_scalar($valeur) ? (string) $valeur : $valeur;
                continue;
            }

            $valeur = $this->convertir($colonne, $valeur);

            // Ramenés à la chaîne : `5000` et `"5000"` sortent du portail au
            // gré de son humeur, et n'ont jamais voulu dire deux choses.
            // `null` reste `null` — « le portail dit non » n'est pas « le
            // portail n'a rien dit ».
            $champs[$colonne] = match (true) {
                $valeur === null => null,
                is_bool($valeur) => $valeur ? '1' : '0',
                default => (string) $valeur,
            };
        }

        ksort($champs);
        ksort($inconnus);

        return ['champs' => $champs, 'inconnus' => $inconnus];
    }

    /**
     * @param  array<int, array<string, string|null>>  $lignes
     * @return array<string, array<string, string|null>>
     */
    private function pointsCanoniques(array $lignes): array
    {
        $points = [];

        foreach ($lignes as $ligne) {
            $point = array_fill_keys(array_values(self::COLONNES_POINTS), null);

            foreach ($ligne as $entete => $valeur) {
                $colonne = self::COLONNES_POINTS[$this->cleEntete($entete)] ?? null;

                if ($colonne === null) {
                    continue;
                }

                if (in_array($colonne, ['cree_a', 'mis_a_jour_a'], true)) {
                    $point[$colonne] = $this->lireHorodatage($valeur)?->format('Y-m-d H:i:s');
                    continue;
                }

                $point[$colonne] = ($valeur === '' || $valeur === null) ? null : (string) $valeur;
            }

            ksort($point);

            // Même identité que `changementsDepuisLePrecedent()` : un point
            // renommé reste le même point. Sans cette clé commune, « inchangé »
            // ici et « aucun changement » là-bas pourraient se contredire.
            //
            // La clé était `etablissement_id` seul. Le portail le donne
            // identique à tous les points d'un même établissement : deux points
            // s'écrasaient donc l'un l'autre, le relevé se réduisait au dernier
            // lu, et un point créé au portail n'entrait jamais — le relevé se
            // déclarait « inchangé ». Constaté sur le relevé réel du
            // 31/08/2026. Voir `PortailFnePointFacturation::identite()`.
            $points[PortailFnePointFacturation::identite(
                $point['etablissement_id'],
                $point['cree_a'],
                $point['nom']
            )] = $point;
        }

        ksort($points);

        return $points;
    }

    /**
     * Le dernier relevé de ce login qui disait déjà exactement cela.
     *
     * **Le dernier, et non n'importe lequel.** Si le portail passe de A à B puis
     * revient à A, ce troisième relevé est une nouvelle : le rattacher à la
     * ligne A d'origine ferait croire que rien n'a bougé depuis, et la fiche la
     * plus récente en base resterait B — c'est-à-dire un état que le portail
     * n'affiche plus.
     */
    private function dernierReleveDeMemeContenu(string $login, string $type, string $contenu): ?PortailFneImport
    {
        $dernier = PortailFneImport::where('login', $login)
            ->where('type', $type)
            ->where('statut', PortailFneImport::STATUT_IMPORTE)
            ->whereNotNull('contenu_empreinte')
            ->orderByDesc('date_scraping')
            ->orderByDesc('id')
            ->first();

        return $dernier?->contenu_empreinte === $contenu ? $dernier : null;
    }

    /**
     * Confirme un relevé déjà connu, sans rien créer.
     *
     * `date_scraping` n'est pas touchée : elle dit depuis quand le portail
     * affiche cela, et l'écraser effacerait la seule trace de l'ancienneté d'un
     * paramétrage. C'est `dernier_releve_le` qui avance.
     *
     * Le compteur ne monte qu'au **changement de date** : le dossier d'import
     * est relu toutes les heures, et compter chaque relecture ferait dire à
     * `releves` le nombre de passages du planificateur plutôt que le nombre de
     * relevés.
     */
    private function confirmerLeReleve(PortailFneImport $import, CarbonImmutable $date, ?int $entrepriseId): void
    {
        $modifications = [];

        if ($import->dernier_releve_le === null || $import->dernier_releve_le->lt($date)) {
            $modifications['dernier_releve_le'] = $date;
            $modifications['releves'] = $import->releves + 1;
        }

        // Un relevé arrivé avant que l'entreprise n'existe dans Selflow porte un
        // `entreprise_id` nul. Tant que chaque passage créait des lignes neuves,
        // le rattachement se faisait tout seul au relevé suivant ; ne plus rien
        // créer le laisserait orphelin pour toujours, et l'écran des rejets, qui
        // cherche par entreprise, ne le verrait jamais.
        if ($entrepriseId !== null && $import->entreprise_id === null) {
            $modifications['entreprise_id'] = $entrepriseId;

            PortailFneFiche::where('login', $import->login)->whereNull('entreprise_id')
                ->update(['entreprise_id' => $entrepriseId]);
            PortailFnePointFacturation::where('login', $import->login)->whereNull('entreprise_id')
                ->update(['entreprise_id' => $entrepriseId]);
        }

        if ($modifications !== []) {
            $import->update($modifications);
        }
    }

    /**
     * Une demande de relevé en attente pour ce login est servie par l'arrivée du
     * fichier, jamais par la parole du scraper : un scraper qui échoue en
     * silence laisse sa demande ouverte, et c'est exactement ce qu'on veut voir
     * dans la file.
     *
     * Un relevé inchangé la sert aussi : le scraper est allé au portail et en est
     * revenu. Lui refuser la fermeture rouvrirait une alerte chaque nuit pour un
     * portail qui va très bien.
     */
    private function servirLesDemandes(string $login, PortailFneImport $import): void
    {
        PortailFneDemande::where('login', $login)
            ->where('statut', PortailFneDemande::STATUT_EN_ATTENTE)
            ->get()
            ->each(fn(PortailFneDemande $demande) => $demande->servir($import));
    }

    /**
     * Range les points de facturation d'un relevé — **tous, ou aucun**.
     *
     * Le tout-ou-rien n'est pas une facilité : un relevé est un jeu complet, et
     * `DiagnosticFneService::pointsDuReleve()` lit tout ce qui porte une date
     * donnée pour dire ce que le portail déclare. N'écrire que le point modifié
     * ferait répondre « le portail ne déclare qu'un seul point de vente » là où
     * il y en a cinq — un diagnostic faux, sur la foi d'une optimisation.
     *
     * La question « faut-il écrire ? » est tranchée en amont, par l'empreinte du
     * contenu : arrivé ici, le jeu est neuf.
     *
     * @param  array<int, array<string, string|null>>  $lignes
     */
    private function rangerPoints(PortailFneImport $import, array $lignes): int
    {
        $comptes = 0;

        foreach ($lignes as $ligne) {
            $valeurs = [
                'import_id' => $import->id,
                'entreprise_id' => $import->entreprise_id,
                'login' => $import->login,
                'date_scraping' => $import->date_scraping,
            ];

            foreach ($ligne as $entete => $valeur) {
                $colonne = self::COLONNES_POINTS[$this->cleEntete($entete)] ?? null;

                if ($colonne === null) {
                    continue;
                }

                $valeurs[$colonne] = in_array($colonne, ['cree_a', 'mis_a_jour_a'], true)
                    ? $this->lireHorodatage($valeur)
                    : ($valeur === '' ? null : $valeur);
            }

            PortailFnePointFacturation::create($valeurs);
            $comptes++;
        }

        return $comptes;
    }

    /**
     * Ramène un en-tête de colonne à une forme comparable : sans accents, sans
     * casse, sans espaces superflus. « ID de l'établissement » et « ID de
     * l'etablissement » désignent la même colonne.
     */
    private function cleEntete(string $entete): string
    {
        $sansAccent = strtr(
            $entete,
            [
                'é' => 'e',
                'è' => 'e',
                'ê' => 'e',
                'ë' => 'e',
                'à' => 'a',
                'â' => 'a',
                'î' => 'i',
                'ï' => 'i',
                'ô' => 'o',
                'ö' => 'o',
                'ù' => 'u',
                'û' => 'u',
                'ü' => 'u',
                'ç' => 'c',
                'É' => 'e',
                'È' => 'e',
                'Ê' => 'e',
                'À' => 'a',
                'Ô' => 'o',
                'Ç' => 'c',
                '’' => "'"
            ]
        );

        return trim(preg_replace('/\s+/', ' ', mb_strtolower($sansAccent)) ?? '');
    }

    /**
     * Le portail rend ses dates en ISO 8601 avec millisecondes
     * (`2026-07-30T10:38:40.726Z`). Une date illisible ne fait pas échouer
     * l'import : elle reste nulle, et la ligne conserve le reste.
     */
    private function lireHorodatage(?string $valeur): ?CarbonImmutable
    {
        if ($valeur === null || trim($valeur) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valeur);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Le portail rend ses nombres et ses booléens tantôt typés, tantôt entre
     * guillemets. La colonne, elle, a un type.
     */
    private function convertir(string $colonne, mixed $valeur): mixed
    {
        if ($valeur === null) {
            return null;
        }

        if (in_array($colonne, self::CHAMPS_ENTIERS, true)) {
            $chiffres = preg_replace('/[^0-9-]/', '', (string) $valeur);

            return ($chiffres === '' || $chiffres === null) ? null : (int) $chiffres;
        }

        if (in_array($colonne, self::CHAMPS_BOOLEENS, true)) {
            return filter_var($valeur, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $texte = trim((string) $valeur);

        // Le portail écrit « * » pour un champ qu'il n'a pas. Le conserver tel
        // quel ferait passer une absence pour une valeur.
        return ($texte === '' || $texte === '*') ? null : $texte;
    }

    /**
     * @return array{fichier: string, statut: string, message: string, import_id: int|null, lignes: int}
     */
    private function resultat(
        string $fichier,
        string $statut,
        string $message,
        ?int $importId = null,
        int $lignes = 0
    ): array {
        return [
            'fichier' => $fichier,
            'statut' => $statut,
            'message' => $message,
            'import_id' => $importId,
            'lignes' => $lignes,
        ];
    }
}
