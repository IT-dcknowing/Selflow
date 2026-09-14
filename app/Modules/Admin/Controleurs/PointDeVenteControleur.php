<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use App\Modules\Admin\Services\CacheService;
use App\Modules\Admin\Services\PointsDeVentePortailService;
use App\Modules\Admin\Services\ScraperPortailFneService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PointDeVenteControleur
{
    public function index(PointsDeVentePortailService $portail): View
    {
        $entreprise   = Auth::user()->entreprise;
        $pointsDeVente = PointDeVente::where('entreprise_id', $entreprise->id)
            ->withCount('utilisateurs')
            ->withCount('ventes')
            ->orderBy('nom')
            ->get();

        $quotaMax = $entreprise->quota_points_de_vente;

        // Ranger ce que le scraper a déposé, avant de comparer.
        Artisan::call('portail-fne:importer');

        // Créer automatiquement dans Selflow tout nouveau point de vente scrappé
        // qui n'y figure pas encore (sans jamais modifier ni renommer l'existant).
        $portail->importer($entreprise);

        $pointsDeVente = PointDeVente::where('entreprise_id', $entreprise->id)
            ->withCount('utilisateurs')
            ->withCount('ventes')
            ->orderBy('nom')
            ->get();

        // Ce que le portail FNE déclare, en face.
        $portailFne = $portail->comparer($entreprise);

        // L'empreinte de ce que l'écran montre.
        $portailFne['empreinte'] = self::empreinte($portailFne);
        $portailFne['depose_le'] = self::deposeLe($entreprise);

        return view('admin::points_de_vente.index', compact('pointsDeVente', 'quotaMax', 'portailFne'));
    }

    /**
     * Où en est le portail ? — interrogé par l'écran pendant qu'un relevé tourne.
     */
    public function etatDuPortail(PointsDeVentePortailService $portail): JsonResponse
    {
        Artisan::call('portail-fne:importer');

        $comparaison = $portail->comparer(Auth::user()->entreprise);

        return response()->json([
            'releve_le' => $comparaison['releve_le'],
            'a_creer'   => $comparaison['a_creer'],
            'empreinte' => self::empreinte($comparaison),
            'depose_le' => self::deposeLe(Auth::user()->entreprise),
        ]);
    }

    /**
     * Quand le scraper a-t-il déposé pour la dernière fois, pour ce NCC ?
     *
     * Le fichier, et non la base : c'est la seule chose qui dise « le scraper a
     * répondu », que le portail ait quelque chose de neuf à déclarer ou non. Un
     * relevé identique au précédent ne crée aucune ligne et n'en modifie
     * aucune — `confirmerLeReleve()` ne bouge même pas quand le relevé tombe le
     * même jour que le précédent —, si bien qu'il ne laisse en base aucune
     * trace de son passage.
     */
    private static function deposeLe(Entreprise $entreprise): ?int
    {
        $login = trim((string) $entreprise->ncc);

        if ($login === '') {
            return null;
        }

        $dossier = rtrim((string) config('selflow.portail_fne.dossier_import'), '/\\');
        $dernier = null;

        foreach ([$dossier, $dossier . DIRECTORY_SEPARATOR . 'achats'] as $ou) {
            foreach (glob($ou . DIRECTORY_SEPARATOR . $login . '_*') ?: [] as $fichier) {
                $date = @filemtime($fichier);

                if ($date && ($dernier === null || $date > $dernier)) {
                    $dernier = $date;
                }
            }
        }

        return $dernier;
    }

    /**
     * Ce que l'écran montre, résumé en une chaîne comparable.
     *
     * La date du relevé, et pour chaque point déclaré son nom et le point de
     * vente qui lui correspond : un relevé qui rapporte un point de plus, ou
     * une reprise qui vient d'en créer un, changent l'empreinte. Un relevé
     * identique ne la change pas, et l'écran ne clignote pas pour rien.
     *
     * @param  array<string, mixed>  $comparaison
     */
    private static function empreinte(array $comparaison): string
    {
        $points = array_map(
            fn (array $point) => $point['nom'] . ':' . ($point['point_de_vente']->id ?? '-'),
            $comparaison['points']
        );

        return md5(($comparaison['releve_le'] ?? '') . '|' . implode('|', $points));
    }

    /**
     * Va relever le portail maintenant, sans attendre un rendez-vous.
     *
     * Le passage horaire n'ouvre une session que si une pièce a été refusée, et
     * le passage complet attend 02:30 : celui qui vient de déclarer un point de
     * facturation au portail n'a aucune raison d'attendre demain matin.
     *
     * Le relevé part **détaché** — il ouvre un vrai navigateur et prend des
     * dizaines de secondes ; bloquer la réponse HTTP figerait l'écran.
     */
    public function releverLePortail(): RedirectResponse|JsonResponse
    {
        $entreprise = Auth::user()->entreprise;

        $lance = ScraperPortailFneService::lancerPourLogin($entreprise->ncc);

        // Le bouton lance en arrière-plan et surveille : il attend une réponse
        // qu'il peut lire, pas une page entière.
        if (request()->wantsJson()) {
            return response()->json([
                'lance'   => $lance,
                'message' => $lance
                    ? 'Relevé du portail en cours.'
                    : "Le scraper du portail est éteint sur ce serveur, ou l'entreprise n'a pas de NCC.",
            ], $lance ? 200 : 409);
        }

        if (!$lance) {
            return back()->with('avertissement',
                "Le relevé n'a pas pu être lancé : le scraper du portail est éteint sur ce "
                . "serveur, ou l'entreprise n'a pas de NCC. Le passage nocturne reste en place."
            );
        }

        // Sans JavaScript, on retombe sur le message : le relevé tourne quand
        // même, et le prochain affichage de l'écran le rangera.
        return back()->with('info',
            'Relevé du portail lancé. Il prend quelques dizaines de secondes ; '
            . "l'écran le montrera dès qu'il sera arrivé."
        );
    }

    /**
     * Reprend dans Selflow les points de facturation déclarés au portail FNE.
     *
     * Le sens est celui-là, et pas l'autre : le portail déclare, Selflow
     * s'aligne. Rien n'est jamais créé au portail depuis ici — déclarer un
     * point de facturation est un acte du contribuable, et le scraper ne fait
     * que lire.
     */
    public function importerDuPortail(PointsDeVentePortailService $portail): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        // Ranger d'abord ce que le scraper a pu déposer depuis — un relevé
        // lancé par le bouton d'à côté met des dizaines de secondes, et il
        // serait absurde de faire attendre l'heure ronde à qui vient de le
        // demander. Même geste que le « corriger maintenant » des rejets.
        Artisan::call('portail-fne:importer');

        $rapport = $portail->importer($entreprise);

        if (!$rapport['releve']) {
            return back()->with('avertissement',
                "Aucun relevé du portail FNE n'est disponible pour votre entreprise : il n'y a "
                . 'rien à reprendre. Le relevé des points de facturation se fait chaque nuit.'
            );
        }

        $messages = [];

        if ($rapport['crees'] !== []) {
            $messages[] = count($rapport['crees']) . ' point(s) de vente créé(s) d\'après le portail : '
                . implode(', ', $rapport['crees']) . '. Leur ville et leur commune restent à compléter.';
        }

        if ($rapport['adoptes'] !== []) {
            $messages[] = count($rapport['adoptes']) . ' point(s) déjà saisi(s) reconnu(s) au portail : '
                . implode(', ', $rapport['adoptes']) . '.';
        }

        if ($rapport['quota_atteint']) {
            return back()->with('avertissement', trim(implode(' ', $messages) . ' Quota de points de '
                . "vente atteint : les points restants du portail n'ont pas été repris."));
        }

        if ($messages === []) {
            return back()->with('info', 'Rien à reprendre : les '
                . $rapport['deja_presents'] . ' point(s) déclaré(s) au portail sont déjà dans Selflow.');
        }

        return back()->with('succes', implode(' ', $messages));
    }

    /**
     * Charge un relevé du portail FNE et l'enregistre en base.
     *
     * ## Ce que la fonction fait, et rien d'autre
     *
     * Elle lit un fichier déposé et écrit ses lignes dans les modèles du
     * portail. Elle ne résout aucune entreprise, ne compare rien au
     * paramétrage, ne renomme aucun point de vente : le rapprochement et la
     * correction sont ailleurs, et le restent.
     *
     * | Extension | Ce que le portail exporte | Modèle écrit |
     * |---|---|---|
     * | `.json` | la fiche de l'entreprise | `PortailFneFiche` |
     * | `.xlsx`, `.xls` | les points de facturation | `PortailFnePointFacturation` |
     *
     * ## La nomenclature porte le NCC
     *
     * `NCC_AAAAMMJJ.<ext>` — `1864699A_20260831.xlsx`. Le NCC vient du **nom du
     * fichier**, et il est écrit dans chaque ligne enregistrée, sur la colonne
     * `login` que la migration décrit comme « le login tel qu'il figure dans le
     * nom du fichier — en pratique le NCC ». Aucun login n'est vérifié : le
     * fichier fait foi.
     *
     * ## Mise à jour plutôt que doublon
     *
     * Une ligne déjà enregistrée pour ce NCC est **mise à jour** ; sinon elle
     * est créée. La fiche n'existe qu'une fois par NCC. Les points de
     * facturation se distinguent en outre par leur nom : un classeur qui en
     * déclare deux écrit deux lignes, et le recharger met les deux à jour au
     * lieu d'en ajouter deux autres.
     *
     * ## Le dépôt porte le NCC
     *
     * Le fichier est rangé sous `<dossier d'import>/<NCC>/`, où le passage
     * horaire ne va pas : il ne relit pas ce qui a déjà été enregistré ici.
     */
    public function LoadFileFne(Request $request): RedirectResponse
    {
        $request->validate([
            // `mimes` s'appuie sur le contenu deviné et non sur l'extension
            // seule ; `txt` accompagne `json`, que PHP reconnaît rarement
            // autrement.
            'fichier_fne' => ['required', 'file', 'max:5120', 'mimes:json,txt,xlsx,xls'],
        ], [
            'fichier_fne.required' => 'Choisissez le fichier exporté depuis votre espace FNE.',
            'fichier_fne.mimes'    => 'Seuls les exports du portail sont acceptés : .json (fiche) '
                . 'ou .xlsx / .xls (points de facturation).',
            'fichier_fne.max'      => "Le fichier dépasse 5 Mo : ce n'est pas un export du portail FNE.",
        ]);

        $depose      = $request->file('fichier_fne');
        $nomOriginal = $depose->getClientOriginalName();

        $nomenclature = $this->lireLaNomenclature($nomOriginal);

        if ($nomenclature === null) {
            return back()->with('erreur', sprintf(
                'Nom hors nomenclature : « %s ». Attendu NCC_AAAAMMJJ.<ext>, '
                . 'par exemple 1864699A_20260831.xlsx.',
                $nomOriginal
            ));
        }

        [$ncc, $date, $extension] = [$nomenclature['ncc'], $nomenclature['date'], $nomenclature['extension']];

        // Le dossier de dépôt porte le NCC : les relevés d'une entreprise se
        // retrouvent d'un coup d'œil, sans lire les noms un par un.
        $dossier = rtrim((string) config('selflow.portail_fne.dossier_import'), '/\\')
            . DIRECTORY_SEPARATOR . $ncc;

        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return back()->with('erreur', "Le dossier de dépôt « {$ncc} » n'a pas pu être créé.");
        }

        $depose->move($dossier, $nomOriginal);
        $chemin = $dossier . DIRECTORY_SEPARATOR . $nomOriginal;

        // La trace du fichier, que la cle etrangere `import_id` des deux tables
        // exige : elle n'est pas nulle. Retrouvee a l'empreinte plutot que
        // recreee, parce que la colonne est unique — recharger le meme fichier
        // reutilise donc sa ligne au lieu d'echouer.
        $releve = PortailFneImport::updateOrCreate(
            ['fichier_empreinte' => hash_file('sha256', $chemin)],
            [
                'login'         => $ncc,
                'date_scraping' => $date->toDateString(),
                'type'          => $extension === 'json'
                    ? PortailFneImport::TYPE_FICHE
                    : PortailFneImport::TYPE_POINTS,
                'fichier_nom'   => $nomOriginal,
                'statut'        => PortailFneImport::STATUT_IMPORTE,
                'importe_at'    => now(),
            ]
        );

        try {
            $lignes = $extension === 'json'
                ? $this->enregistrerLaFiche($chemin, $ncc, $date, $releve->id)
                : $this->enregistrerLesPoints($chemin, $ncc, $date, $releve->id);
        } catch (\Throwable $erreur) {
            Log::warning('Chargement manuel du portail FNE : fichier illisible', [
                'fichier' => $nomOriginal,
                'erreur'  => $erreur->getMessage(),
            ]);

            $releve->update(['statut' => PortailFneImport::STATUT_ERREUR, 'message' => $erreur->getMessage()]);

            return back()->with('erreur', 'Relevé illisible : ' . $erreur->getMessage());
        }

        $releve->update(['lignes_importees' => $lignes]);

        if ($extension !== 'json') {
            $entreprise = Entreprise::where('ncc', $ncc)->first() ?? Auth::user()->entreprise;
            if ($entreprise) {
                app(PointsDeVentePortailService::class)->importer($entreprise);
            }
        }

        if ($lignes === 0) {
            return back()->with('avertissement', "Le fichier « {$nomOriginal} » ne contient aucune ligne à enregistrer.");
        }

        return back()->with('succes', sprintf(
            '%s : %d ligne(s) enregistrée(s) et synchronisée(s) pour le NCC %s, relevé du %s.',
            $extension === 'json' ? 'Fiche du portail' : 'Points de facturation',
            $lignes,
            $ncc,
            $date->format('d/m/Y')
        ));
    }

    /**
     * Le NCC, la date et l'extension que porte le nom déposé.
     *
     * Le NCC est **tout ce qui précède le dernier tiret bas**, et non le premier
     * segment : un NCC qui contiendrait lui-même un tiret bas serait tronqué par
     * un découpage au premier.
     *
     * @return array{ncc: string, date: CarbonImmutable, extension: string}|null
     */
    private function lireLaNomenclature(string $nomDepose): ?array
    {
        $extension = strtolower(pathinfo($nomDepose, PATHINFO_EXTENSION));

        if (!in_array($extension, ['json', 'xlsx', 'xls'], true)) {
            return null;
        }

        $base     = pathinfo($nomDepose, PATHINFO_FILENAME);
        $position = strrpos($base, '_');

        if ($position === false || $position === 0) {
            return null;
        }

        $ncc  = trim(substr($base, 0, $position));
        $jour = substr($base, $position + 1);
        $date = CarbonImmutable::createFromFormat('!Ymd', $jour);

        if ($ncc === '' || $date === false || $date->format('Ymd') !== $jour) {
            return null;
        }

        return ['ncc' => $ncc, 'date' => $date, 'extension' => $extension];
    }

    /**
     * Écrit la fiche du portail : une ligne par NCC, mise à jour si elle existe.
     */
    private function enregistrerLaFiche(string $chemin, string $ncc, CarbonImmutable $date, int $releveId): int
    {
        $contenu = file_get_contents($chemin);

        if ($contenu === false) {
            throw new \RuntimeException('lecture du fichier impossible.');
        }

        $donnees = json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($donnees)) {
            throw new \RuntimeException("le JSON ne contient pas d'objet.");
        }

        $valeurs  = ['date_scraping' => $date->toDateString(), 'import_id' => $releveId];
        $inconnus = [];

        foreach ($donnees as $libelle => $valeur) {
            $colonne = self::CHAMPS_FICHE[trim((string) $libelle)] ?? null;

            if ($colonne === null) {
                // Le portail peut ajouter un champ du jour au lendemain. Sans
                // ce fourre-tout, il serait lu, jeté, et personne ne le saurait.
                $inconnus[trim((string) $libelle)] = $valeur;
                continue;
            }

            $valeurs[$colonne] = $this->convertirLaValeur($colonne, $valeur);
        }

        $valeurs['champs_inconnus'] = $inconnus ?: null;

        PortailFneFiche::updateOrCreate(['login' => $ncc], $valeurs);

        return 1;
    }

    /**
     * Écrit les points de facturation : une ligne par NCC **et par nom**.
     *
     * Le NCC seul ne peut pas servir de clé : un classeur en déclare plusieurs,
     * et chaque ligne écraserait la précédente — seul le dernier point du
     * fichier subsisterait.
     */
    private function enregistrerLesPoints(string $chemin, string $ncc, CarbonImmutable $date, int $releveId): int
    {
        $lecteur = IOFactory::createReaderForFile($chemin);
        $lecteur->setReadDataOnly(true);

        $lignes = $lecteur->load($chemin)->getActiveSheet()->toArray(null, true, false, false);

        if ($lignes === []) {
            return 0;
        }

        // La lecture se fait par en-tête et non par position : le portail peut
        // réordonner ses colonnes, et compter les colonnes rangerait alors un
        // statut dans un nom d'établissement.
        $entetes = array_map(fn ($entete) => $this->cleEntete((string) $entete), array_shift($lignes));

        $ecrites = 0;

        foreach ($lignes as $ligne) {
            $valeurs = ['date_scraping' => $date->toDateString(), 'import_id' => $releveId];

            foreach ($entetes as $index => $entete) {
                $colonne = self::COLONNES_POINTS[$entete] ?? null;

                if ($colonne === null) {
                    continue;
                }

                $valeur = $ligne[$index] ?? null;
                $valeur = $valeur === null ? null : trim((string) $valeur);

                $valeurs[$colonne] = in_array($colonne, ['cree_a', 'mis_a_jour_a'], true)
                    ? $this->lireUnHorodatage($valeur)
                    : (($valeur === '' || $valeur === '*') ? null : $valeur);
            }

            $nom = $valeurs['nom'] ?? null;

            // Une ligne sans nom est un artefact du tableur, pas un point de
            // facturation — et elle n'aurait pas de clé.
            if ($nom === null || $nom === '') {
                continue;
            }

            PortailFnePointFacturation::updateOrCreate(
                ['login' => $ncc, 'nom' => $nom],
                $valeurs
            );

            $ecrites++;
        }

        return $ecrites;
    }

    /**
     * Le portail rend ses nombres et ses booléens tantôt typés, tantôt entre
     * guillemets ; la colonne, elle, a un type. Et il écrit « * » pour un champ
     * qu'il n'a pas : le conserver ferait passer une absence pour une valeur.
     */
    private function convertirLaValeur(string $colonne, mixed $valeur): mixed
    {
        if ($valeur === null) {
            return null;
        }

        if ($colonne === 'sticker_solde_alerte') {
            $chiffres = preg_replace('/[^0-9-]/', '', (string) $valeur);

            return ($chiffres === '' || $chiffres === null) ? null : (int) $chiffres;
        }

        if (in_array($colonne, ['timbre_quittance', 'bapa'], true)) {
            return filter_var($valeur, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $texte = trim((string) $valeur);

        return ($texte === '' || $texte === '*') ? null : $texte;
    }

    /**
     * Le portail écrit ses dates en ISO 8601 avec millisecondes
     * (`2026-07-30T10:38:40.726Z`). Une date illisible laisse la colonne nulle
     * plutôt que de faire échouer tout le fichier.
     */
    private function lireUnHorodatage(?string $valeur): ?CarbonImmutable
    {
        if ($valeur === null || trim($valeur) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valeur);
        } catch (\Throwable) {
            return null;
        }
    }

    /** L'en-tête du tableur ramené à une clé stable : sans accent, sans casse. */
    private function cleEntete(string $entete): string
    {
        $sansAccent = strtr($entete, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u',
            'ü' => 'u', 'ç' => 'c', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'À' => 'a',
            'Ô' => 'o', 'Ç' => 'c', '’' => "'",
        ]);

        return trim(preg_replace('/\s+/', ' ', mb_strtolower($sansAccent)) ?? '');
    }

    /**
     * Les libellés du JSON, et la colonne de la fiche qui les reçoit.
     *
     * @var array<string, string>
     */
    private const CHAMPS_FICHE = [
        'Email'                                               => 'email',
        'Téléphone'                                           => 'telephone',
        'Adresse'                                             => 'adresse',
        'Commune'                                             => 'commune',
        'Quartier'                                            => 'quartier',
        'Référence Cadastrale'                                => 'reference_cadastrale',
        'IDU'                                                 => 'idu',
        "Propriétaire du local professionnel de l'entreprise" => 'proprietaire_local',
        "Sticker : solde d'alerte"                            => 'sticker_solde_alerte',
        'Références bancaires'                                => 'ref_bancaire',
        'Timbre de quittance'                                 => 'timbre_quittance',
        "Bordereau d'achat de produits agricoles"             => 'bapa',
        'Pied de page des factures'                           => 'pied_de_page_facture',
        'Factures autres mentions'                            => 'facture_autres_mentions',
    ];

    /**
     * Les en-têtes du tableur, et la colonne du point de facturation.
     *
     * @var array<string, string>
     */
    private const COLONNES_POINTS = [
        'nom'                   => 'nom',
        'outil'                 => 'outil',
        'id du terminal'        => 'terminal_id',
        'statut'                => 'statut',
        'raison de statut'      => 'raison_statut',
        "id de l'etablissement" => 'etablissement_id',
        'cree a'                => 'cree_a',
        'mise a jour a'         => 'mis_a_jour_a',
    ];
    public function creer(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        $request->validate([
            'nom'       => ['required', 'string', 'max:100'],
            'ville'     => ['required', 'string', 'max:100'],
            'commune'   => ['nullable', 'string', 'max:100'],
            'responsable'=> ['nullable', 'string', 'max:150'],
            'telephone' => ['nullable', 'string', 'max:30'],
        ]);

        if ($entreprise->pointsDeVente()->count() >= $entreprise->quota_points_de_vente) {
            return back()->withErrors(['general' => 'Quota de points de vente atteint pour votre abonnement.']);
        }

        $pdv = PointDeVente::create(array_merge(
            $request->only(['nom', 'ville', 'commune', 'responsable', 'telephone']),
            ['entreprise_id' => $entreprise->id, 'statut' => 'Ouvert']
        ));

        // Les fiches de stock vides du nouveau site. La règle vit désormais sur
        // le modèle : l'import des points déclarés au portail crée lui aussi des
        // points de vente, et deux copies de cette boucle auraient divergé.
        $pdv->initialiserLesFichesDeStock();

        // Invalider le cache des PDV pour cette entreprise
        CacheService::invaliderPointsDeVente($entreprise->id);

        return back()->with('succes', 'Point de vente créé avec succès.');
    }

    public function modifier(Request $request, PointDeVente $pdv): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($pdv->entreprise_id === $entreprise->id, 404);

        $validated = $request->validate([
            'nom'         => ['required', 'string', 'max:100'],
            'ville'       => ['required', 'string', 'max:100'],
            'commune'     => ['nullable', 'string', 'max:100'],
            'responsable' => ['nullable', 'string', 'max:150'],
            'telephone'   => ['nullable', 'string', 'max:30'],
        ]);

        $pdv->update($validated);
        CacheService::invaliderPointsDeVente($entreprise->id);

        return back()->with('succes', "Point de vente « {$pdv->nom} » mis à jour avec succès.");
    }

    public function activerSession(Request $request, PointDeVente $pdv): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($pdv->entreprise_id === $entreprise->id, 404);

        session(['point_de_vente_actif_id' => $pdv->id, 'point_de_vente_actif_nom' => $pdv->nom]);

        // Et retenu au-delà de la session : elle meurt à la déconnexion, et le
        // choix mourait avec elle.
        Auth::user()->retenirLePointDeVente($pdv->id);

        return back()->with('succes', "Point de vente « {$pdv->nom} » activé. Vous le retrouverez à votre prochaine connexion.");
    }

    public function activerApercu(PointDeVente $pdv): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($pdv->entreprise_id === $entreprise->id, 404);

        // Activons l'aperçu en stockant dans la session
        session([
            'apercu_pdv_id' => $pdv->id,
            'apercu_pdv_nom' => $pdv->nom,
            // Pour que l'interface pense aussi qu'on est sur ce point de vente
            'point_de_vente_actif_id' => $pdv->id,
            'point_de_vente_actif_nom' => $pdv->nom,
        ]);

        return redirect()->route('caissier.tableau_de_bord')->with('succes', "Aperçu du point de vente « {$pdv->nom} » activé en mode lecture seule.");
    }

    public function desactiverApercu(): RedirectResponse
    {
        session()->forget(['apercu_pdv_id', 'apercu_pdv_nom', 'point_de_vente_actif_id', 'point_de_vente_actif_nom']);

        return redirect()->route('admin.pdv.index')->with('succes', "Mode aperçu désactivé. Retour à l'administration principale.");
    }
}
