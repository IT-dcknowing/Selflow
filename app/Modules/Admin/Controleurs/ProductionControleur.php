<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\FicheTechnique;
use App\Modules\Admin\Modeles\FicheTechniqueDetail;
use App\Modules\Admin\Modeles\OrdreProduction;
use App\Modules\Admin\Modeles\OrdreProductionLigne;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\PointDeVente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Services\ConversionUnitesService;
use App\Modules\Admin\Services\ImputationService;
use App\Modules\Admin\Services\StockService;

class ProductionControleur extends Controller
{
    /**
     * Liste des fiches techniques.
     */
    public function indexFichesTechniques(Request $request): View
    {
        $entrepriseId = Auth::user()->entreprise_id;
        
        $query = FicheTechnique::with(['produitFini', 'details.ingredient'])
            ->where('entreprise_id', $entrepriseId);

        if ($request->filled('recherche')) {
            $recherche = $request->recherche;
            $query->whereHas('produitFini', function ($q) use ($recherche) {
                $q->where('nom', 'LIKE', '%' . $recherche . '%')
                  ->orWhere('reference', 'LIKE', '%' . $recherche . '%');
            });
        }

        $fiches = $query->latest()->paginate(15);

        return view('admin::production.fiches.index', compact('fiches'));
    }

    /**
     * Formulaire de création de fiche technique.
     */
    public function creerFicheTechnique(): View
    {
        $entrepriseId = Auth::user()->entreprise_id;

        // Récupérer les produits finis qui n'ont pas encore de fiche technique
        // Archiver un article le retire de la production comme du reste :
        // on n'ouvre pas une fiche technique pour ce qu'on ne fabrique plus.
        $produitsFini = Produit::where('entreprise_id', $entrepriseId)
            ->selectionnables()
            ->where('type', 'produit_fini')
            ->whereDoesntHave('ficheTechnique')
            ->orderBy('nom')
            ->get();

        // Récupérer uniquement les ingrédients de type matière première
        $ingredients = self::ingredientsProposes($entrepriseId);

        // Le rayon du produit créé par la saisie libre : c'est lui qui donne
        // sa référence et ses comptes.
        $categories = Categorie::where('entreprise_id', $entrepriseId)->orderBy('nom')->get(['id', 'nom']);

        return view('admin::production.fiches.fiche', [
            'fiche' => new FicheTechnique(),
            'produitsFini' => $produitsFini,
            'ingredients' => $ingredients,
            'mode' => 'creation',
            'categories' => $categories,
        ]);
    }

    /**
     * Les matières premières qu'une recette peut employer, chacune avec les
     * unités dans lesquelles on peut la doser : son unité de stock et ses
     * équivalentes (kg et g, l et ml). Le formulaire proposait kg, g, l, ml
     * quelle que soit l'unité de l'article.
     */
    private static function ingredientsProposes(int $entrepriseId)
    {
        return Produit::where('entreprise_id', $entrepriseId)
            ->selectionnables()
            ->where('type', 'matiere_premiere')
            ->orderBy('nom')
            ->get()
            ->each(fn ($p) => $p->setAttribute('unites_compatibles', ConversionUnitesService::compatibles($p->unite)));
    }

    /**
     * Enregistrer une nouvelle fiche technique.
     */
    public function enregistrerFicheTechnique(Request $request): RedirectResponse
    {
        $entrepriseId = Auth::user()->entreprise_id;
        $saisieLibre = $request->filled('nouveau_produit_fini_nom');

        $validateur = Validator::make($request->all(), [
            'produit_fini_id' => [$saisieLibre ? 'nullable' : 'required', 'integer', Appartenance::a('produits', 'id')],
            'nouveau_produit_fini_nom' => ['nullable', 'string', 'max:255'],
            'nouveau_produit_fini_categorie_id' => ['nullable', 'integer', Appartenance::a('categories', 'id')],
            'description'     => ['nullable', 'string'],
        ] + self::reglesIngredients());

        $validateur->after(function ($v) use ($request, $entrepriseId, $saisieLibre) {
            // Le produit fabriqué doit être un produit fini actif : une
            // matière première déclarée « produit fini » d'une recette
            // entrerait au stock par la production et en sortirait par
            // elle-même.
            if (!$saisieLibre && $request->filled('produit_fini_id') && !$v->errors()->has('produit_fini_id')) {
                $produit = Produit::where('entreprise_id', $entrepriseId)->find($request->produit_fini_id);

                if (!$produit || $produit->type !== 'produit_fini' || $produit->statut !== 'actif') {
                    $v->errors()->add('produit_fini_id',
                        'Le produit fabriqué doit être un article de type « Produit fini » et actif.');
                }
            }

            self::controlerIngredients($v, (array) $request->input('ingredients', []), $entrepriseId,
                $saisieLibre ? null : (int) $request->produit_fini_id);
        });

        $validateur->validate();

        $resultat = DB::transaction(function () use ($request, $entrepriseId, $saisieLibre) {
            $produitFiniId = $saisieLibre
                ? $this->produitFiniDeLaSaisieLibre($request, $entrepriseId)->id
                : (int) $request->produit_fini_id;

            // S'assurer que le produit fini n'a pas déjà de fiche technique
            $dejaExiste = FicheTechnique::where('entreprise_id', $entrepriseId)
                ->where('produit_fini_id', $produitFiniId)
                ->exists();

            if ($dejaExiste) {
                return false;
            }

            $fiche = FicheTechnique::create([
                'entreprise_id'   => $entrepriseId,
                'produit_fini_id' => $produitFiniId,
                'description'     => $request->description,
            ]);

            foreach ($request->ingredients as $ing) {
                FicheTechniqueDetail::create([
                    'fiche_technique_id' => $fiche->id,
                    'ingredient_id'      => $ing['ingredient_id'],
                    'quantite'           => $ing['quantite'],
                    'unite'              => $ing['unite'],
                ]);
            }

            return true;
        });

        if (!$resultat) {
            return back()->withInput()->with('erreur', 'Ce produit possède déjà une fiche technique. Veuillez la modifier plutôt.');
        }

        return redirect()->route('admin.production.fiches_techniques.index')
            ->with('succes', 'Fiche technique enregistrée avec succès !');
    }

    /**
     * Le produit fini que désigne la saisie libre : celui qui porte déjà ce
     * nom **parmi les produits finis**, sinon un article créé pour l'occasion.
     *
     * La recherche portait sur tout le catalogue : « Levure » reprenait la
     * matière première du même nom, qui devenait le produit fini de sa propre
     * recette.
     *
     * L'article créé reçoit une référence (par son rayon), une fiche de stock
     * par site, et — si les matières de la recette s'imputent en comptabilité
     * et que rien d'autre ne lui en donne — les comptes de produits finis :
     * sans eux, le garde-fou d'imputation bloquerait sa première fabrication.
     */
    private function produitFiniDeLaSaisieLibre(Request $request, int $entrepriseId): Produit
    {
        $nom = trim((string) $request->nouveau_produit_fini_nom);

        $existant = Produit::where('entreprise_id', $entrepriseId)
            ->selectionnables()
            ->where('type', 'produit_fini')
            ->whereRaw('LOWER(nom) = ?', [mb_strtolower($nom)])
            ->first();

        if ($existant) {
            return $existant;
        }

        // Le rayon choisi, sinon celui où l'entreprise range déjà ses produits finis.
        $categorieId = $request->input('nouveau_produit_fini_categorie_id')
            ?: Produit::where('entreprise_id', $entrepriseId)
                ->where('type', 'produit_fini')
                ->whereNotNull('categorie_id')
                ->groupBy('categorie_id')
                ->orderByRaw('COUNT(*) DESC')
                ->value('categorie_id');

        $produit = Produit::create([
            'entreprise_id' => $entrepriseId,
            'nom'           => $nom,
            'type'          => 'produit_fini',
            'categorie_id'  => $categorieId ?: null,
            'prix_achat'    => 0,
            'prix_vente'    => 0,
            'taux_tva'      => 18.0,
            'unite'         => ConversionUnitesService::UNITE_GENERIQUE,
            'statut'        => 'actif',
        ]);

        $matieresImputees = Produit::whereIn('id', collect($request->ingredients)->pluck('ingredient_id'))
            ->get()
            ->contains(fn ($p) => ImputationService::peutTenirLInventairePermanent($p));

        if ($matieresImputees && !ImputationService::peutTenirLInventairePermanent($produit)) {
            // SYSCOHADA : 36 « Produits finis », 7361 « Variation des stocks
            // de produits finis ».
            $produit->update([
                'compte_stock'     => ImputationService::compteStock($produit) ?: '361000',
                'compte_variation' => ImputationService::compteVariation($produit) ?: '736100',
            ]);
        }

        // Initialiser le stock à 0 sur tous les points de vente.
        //
        // La colonne s'appelle `quantite_disponible` ; `quantite`
        // n'existe pas et n'est pas dans `$fillable`, si bien que la
        // valeur passait à la trappe sans que rien ne le signale.
        foreach (PointDeVente::where('entreprise_id', $entrepriseId)->get() as $pdv) {
            Stock::firstOrCreate(
                ['point_de_vente_id' => $pdv->id, 'produit_id' => $produit->id],
                ['quantite_disponible' => 0]
            );
        }

        return $produit;
    }

    /**
     * Les règles de saisie des lignes d'une recette.
     *
     * - `distinct` : un même ingrédient deux fois heurtait l'index unique de
     *   `fiche_technique_details` et rendait une erreur 500 ;
     * - la quantité garde quatre décimales — celles de la colonne : une
     *   recette se rédige pour **une** unité de produit fini, et 0,4 g de
     *   levure par pain est une donnée légitime. C'est le besoin total de
     *   l'ordre qui se ramène à la précision du stock.
     */
    private static function reglesIngredients(): array
    {
        return [
            'ingredients'                 => ['required', 'array', 'min:1'],
            'ingredients.*.ingredient_id' => ['required', 'integer', 'distinct', Appartenance::a('produits', 'id')],
            'ingredients.*.quantite'      => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
            'ingredients.*.unite'         => ['required', 'string', 'max:50'],
        ];
    }

    /**
     * Ce que les règles ne savent pas dire seules : le type de chaque
     * ingrédient, l'unité compatible avec celle de son stock, et le produit
     * fini qui ne peut pas entrer dans sa propre recette.
     */
    private static function controlerIngredients($v, array $ingredients, int $entrepriseId, ?int $produitFiniId): void
    {
        $articles = Produit::where('entreprise_id', $entrepriseId)
            ->whereIn('id', collect($ingredients)->pluck('ingredient_id')->filter()->all())
            ->get()
            ->keyBy('id');

        foreach ($ingredients as $i => $ing) {
            $cle = "ingredients.{$i}.ingredient_id";

            if (!is_array($ing) || $v->errors()->has($cle)) {
                continue;
            }

            $article = $articles->get((int) ($ing['ingredient_id'] ?? 0));

            if (!$article) {
                continue;
            }

            if ($produitFiniId && $article->id === $produitFiniId) {
                $v->errors()->add($cle, "« {$article->nom} » ne peut pas entrer dans sa propre recette.");
                continue;
            }

            if ($article->type !== 'matiere_premiere') {
                $v->errors()->add($cle, "« {$article->nom} » n'est pas une matière première : seules les matières premières entrent dans une recette.");
                continue;
            }

            $unite = (string) ($ing['unite'] ?? '');

            if ($unite !== '' && ConversionUnitesService::facteur($unite, $article->unite) === null) {
                $v->errors()->add("ingredients.{$i}.unite",
                    "L'unité « {$unite} » ne se convertit pas dans l'unité de stock de « {$article->nom} » ("
                    . ($article->unite ?: ConversionUnitesService::UNITE_GENERIQUE) . '). Unités possibles : '
                    . implode(', ', ConversionUnitesService::compatibles($article->unite)) . '.');
            }
        }
    }

    /**
     * Formulaire de modification de fiche technique.
     */
    public function modifierFicheTechnique(FicheTechnique $fiche): View
    {
        $entrepriseId = Auth::user()->entreprise_id;
        abort_unless($fiche->entreprise_id === $entrepriseId, 404);

        $fiche->load(['details.ingredient', 'produitFini']);

        // Le produit fini de la fiche actuelle
        $produitsFini = Produit::where('id', $fiche->produit_fini_id)->get();

        // Récupérer uniquement les ingrédients de type matière première
        $ingredients = self::ingredientsProposes($entrepriseId);

        return view('admin::production.fiches.fiche', [
            'fiche' => $fiche,
            'produitsFini' => $produitsFini,
            'ingredients' => $ingredients,
            'mode' => 'edition',
            'categories' => collect(),
        ]);
    }

    /**
     * Enregistrer les modifications de la fiche technique.
     *
     * Les ordres déjà lancés n'en sont pas affectés : chacun a figé, à sa
     * création, ce que la recette demandait alors.
     */
    public function enregistrerModificationFicheTechnique(Request $request, FicheTechnique $fiche): RedirectResponse
    {
        $entrepriseId = Auth::user()->entreprise_id;
        abort_unless($fiche->entreprise_id === $entrepriseId, 404);

        $validateur = Validator::make($request->all(), [
            'description' => ['nullable', 'string'],
        ] + self::reglesIngredients());

        $validateur->after(function ($v) use ($request, $entrepriseId, $fiche) {
            self::controlerIngredients($v, (array) $request->input('ingredients', []), $entrepriseId, (int) $fiche->produit_fini_id);
        });

        $validateur->validate();

        DB::transaction(function () use ($request, $fiche) {
            $fiche->update([
                'description' => $request->description,
            ]);

            // Recréer les détails
            $fiche->details()->delete();

            foreach ($request->ingredients as $ing) {
                FicheTechniqueDetail::create([
                    'fiche_technique_id' => $fiche->id,
                    'ingredient_id'      => $ing['ingredient_id'],
                    'quantite'           => $ing['quantite'],
                    'unite'              => $ing['unite'],
                ]);
            }
        });

        return redirect()->route('admin.production.fiches_techniques.index')
            ->with('succes', 'Fiche technique mise à jour avec succès !');
    }

    /**
     * Supprimer une fiche technique.
     *
     * Refusé tant que des ordres en brouillon attendent ce produit : ils
     * resteraient à l'écran avec leur bouton « Produire & Valider », pour une
     * fabrication que plus rien ne décrit.
     */
    public function supprimerFicheTechnique(FicheTechnique $fiche): RedirectResponse
    {
        abort_unless($fiche->entreprise_id === Auth::user()->entreprise_id, 404);

        $enAttente = OrdreProduction::where('entreprise_id', $fiche->entreprise_id)
            ->where('produit_fini_id', $fiche->produit_fini_id)
            ->where('statut', OrdreProduction::BROUILLON)
            ->pluck('code_ordre');

        if ($enAttente->isNotEmpty()) {
            return back()->with('erreur',
                'Cette recette ne peut pas être supprimée : ' . $enAttente->count() . ' ordre(s) de production en brouillon '
                . 'l\'attendent (' . $enAttente->take(5)->implode(', ') . ($enAttente->count() > 5 ? '…' : '')
                . '). Validez-les ou annulez-les d\'abord.');
        }

        $fiche->delete();

        return redirect()->route('admin.production.fiches_techniques.index')
            ->with('succes', 'Fiche technique supprimée avec succès !');
    }

    /**
     * Liste des ordres de production (OP).
     *
     * Tous les sites de l'entreprise, avec un filtre par site. La liste se
     * limitait au site actif, alors que le formulaire laisse choisir le site
     * de fabrication : un ordre lancé pour le fournil depuis la boutique
     * disparaissait à l'instant de sa création. Le caissier, lui, ne voit que
     * son site.
     */
    public function indexOrdres(Request $request): View
    {
        $entrepriseId = Auth::user()->entreprise_id;
        $pdvs = PointDeVente::where('entreprise_id', $entrepriseId)->orderBy('nom')->get();

        $query = OrdreProduction::with(['produitFini', 'pointDeVente'])
            ->where('entreprise_id', $entrepriseId);

        $siteId = Auth::user()->estCaissier()
            ? Auth::user()->point_de_vente_id
            : ($pdvs->contains('id', (int) $request->input('site')) ? (int) $request->input('site') : null);

        if ($siteId) {
            $query->where('point_de_vente_id', $siteId);
        }

        if (in_array($request->input('statut'), OrdreProduction::STATUTS, true)) {
            $query->where('statut', $request->input('statut'));
        }

        $ordres = $query->latest('id')->paginate(15)->withQueryString();

        return view('admin::production.ordres.index', compact('ordres', 'pdvs', 'siteId'));
    }

    /**
     * Formulaire de création d'ordre de production.
     */
    public function creerOrdre(): View
    {
        $entrepriseId = Auth::user()->entreprise_id;

        // Récupérer les produits finis ayant une fiche technique, avec fiches et stocks ingrédients
        $produitsFini = Produit::with(['ficheTechnique.details.ingredient.stocks'])
            ->where('entreprise_id', $entrepriseId)
            ->selectionnables()
            ->where('type', 'produit_fini')
            ->whereHas('ficheTechnique')
            ->orderBy('nom')
            ->get();

        // Le facteur de chaque ligne vers l'unité de stock : le panneau des
        // besoins compare au stock, il doit compter dans la même unité.
        foreach ($produitsFini as $pf) {
            foreach ($pf->ficheTechnique->details as $d) {
                $d->setAttribute('facteur_stock', ConversionUnitesService::facteur($d->unite, $d->ingredient?->unite));
                $d->setAttribute('unite_stock', $d->ingredient?->unite ?: ConversionUnitesService::UNITE_GENERIQUE);
            }
        }

        $pdvs = PointDeVente::where('entreprise_id', $entrepriseId)->orderBy('nom')->get();
        $siteActif = Auth::user()->estCaissier()
            ? Auth::user()->point_de_vente_id
            : (session('point_de_vente_actif_id') ?? Auth::user()->point_de_vente_id);

        return view('admin::production.ordres.creer', compact('produitsFini', 'pdvs', 'siteActif'));
    }

    /**
     * Enregistrer un ordre de production.
     *
     * L'ordre fige ici ce que la recette demande : modifier la recette après
     * coup ne change pas ce qu'un ordre déjà lancé consommera.
     */
    public function enregistrerOrdre(Request $request): RedirectResponse
    {
        $entrepriseId = Auth::user()->entreprise_id;
        $fiche = null;

        $validateur = Validator::make($request->all(), [
            'produit_fini_id'   => ['required', 'integer', Appartenance::a('produits', 'id')],
            'point_de_vente_id' => ['required', 'integer', Appartenance::a('points_de_vente', 'id')],
            // Trois décimales : la précision du stock (`Stock::DECIMALES`).
            'quantite_cible'    => ['required', 'numeric', 'min:0.001', 'decimal:0,' . Stock::DECIMALES],
            'date_production'   => ['required', 'date'],
        ]);

        $validateur->after(function ($v) use ($request, $entrepriseId, &$fiche) {
            if ($v->errors()->has('produit_fini_id')) {
                return;
            }

            $produit = Produit::where('entreprise_id', $entrepriseId)->find($request->produit_fini_id);

            if (!$produit || $produit->type !== 'produit_fini' || $produit->statut !== 'actif') {
                $v->errors()->add('produit_fini_id', 'Seul un produit fini actif se fabrique.');
                return;
            }

            $fiche = FicheTechnique::with('details.ingredient')
                ->where('entreprise_id', $entrepriseId)
                ->where('produit_fini_id', $produit->id)
                ->first();

            if (!$fiche || $fiche->details->isEmpty()) {
                $v->errors()->add('produit_fini_id',
                    "« {$produit->nom} » n'a pas de recette : écrivez sa fiche technique avant de lancer un ordre.");
                return;
            }

            foreach ($fiche->details as $d) {
                if (ConversionUnitesService::facteur($d->unite, $d->ingredient?->unite) === null) {
                    $v->errors()->add('produit_fini_id',
                        "La recette emploie « {$d->unite} » pour « {$d->ingredient?->nom} », stocké en "
                        . ($d->ingredient?->unite ?: ConversionUnitesService::UNITE_GENERIQUE)
                        . ' : corrigez la recette avant de lancer un ordre.');
                }
            }

            // Le caissier ne fabrique que pour son site.
            if (Auth::user()->estCaissier() && (int) $request->point_de_vente_id !== (int) Auth::user()->point_de_vente_id) {
                $v->errors()->add('point_de_vente_id', 'Vous ne pouvez lancer un ordre que pour votre point de vente.');
            }
        });

        $validateur->validate();

        $ordre = DB::transaction(function () use ($request, $entrepriseId, $fiche) {
            $ordre = OrdreProduction::create([
                'entreprise_id'     => $entrepriseId,
                'point_de_vente_id' => $request->point_de_vente_id,
                'produit_fini_id'   => $request->produit_fini_id,
                'code_ordre'        => OrdreProduction::genererCode($entrepriseId),
                'quantite_cible'    => $request->quantite_cible,
                'statut'            => OrdreProduction::BROUILLON,
                'date_production'   => $request->date_production,
            ]);

            foreach ($fiche->details as $d) {
                OrdreProductionLigne::create([
                    'ordre_production_id' => $ordre->id,
                    'ingredient_id'       => $d->ingredient_id,
                    'quantite_recette'    => $d->quantite,
                    'unite_recette'       => $d->unite,
                    'quantite_requise'    => self::besoin((float) $d->quantite, $d->unite, $d->ingredient, (float) $request->quantite_cible),
                ]);
            }

            return $ordre;
        });

        $redirection = redirect()->route('admin.production.ordres.index')
            ->with('succes', "Ordre de production {$ordre->code_ordre} créé avec succès !");

        $nuls = $ordre->lignes()->with('ingredient')->get()->filter(fn ($l) => (float) $l->quantite_requise <= 0);

        if ($nuls->isNotEmpty()) {
            $redirection->with('info', 'Attention : le besoin en ' . $nuls->map(fn ($l) => $l->ingredient?->nom)->implode(', ')
                . ' est inférieur à la précision du stock (0,001) et s\'arrondit à zéro. L\'ordre ne pourra pas être validé '
                . 'tel quel : augmentez la quantité à produire, ou annulez-le.');
        }

        return $redirection;
    }

    /**
     * Le besoin total d'une matière, dans l'unité de stock de l'ingrédient,
     * arrondi à la précision du stock — ou `null` si l'unité de la recette ne
     * se convertit pas.
     *
     * L'arrondi n'est pas cosmétique : 0,1 × 3 vaut 0,30000000000000004 en
     * flottant, et un stock d'exactement 0,3 kg était déclaré insuffisant.
     */
    private static function besoin(float $quantiteRecette, ?string $uniteRecette, ?Produit $ingredient, float $quantiteCible): ?float
    {
        $parUnite = ConversionUnitesService::convertir($quantiteRecette, $uniteRecette, $ingredient?->unite);

        return $parUnite === null ? null : round($parUnite * $quantiteCible, Stock::DECIMALES);
    }

    /**
     * Les matières qu'un ordre consomme, dans l'unité de stock.
     *
     * Les lignes figées à la création de l'ordre ; pour un ordre établi avant
     * qu'elles existent, la recette en place, convertie de même.
     *
     * @return array{0: array<int, array{ingredient: Produit, quantite: ?float, unite: string}>, 1: ?string}
     *         les besoins, et l'erreur qui empêche de les établir
     */
    private static function besoinsDeLOrdre(OrdreProduction $ordre): array
    {
        $lignes = $ordre->lignes()->with('ingredient')->get();

        if ($lignes->isNotEmpty()) {
            return [$lignes->map(fn ($l) => [
                'ingredient' => $l->ingredient,
                'quantite'   => round((float) $l->quantite_requise, Stock::DECIMALES),
                'unite'      => $l->ingredient?->unite ?: ConversionUnitesService::UNITE_GENERIQUE,
            ])->all(), null];
        }

        $fiche = FicheTechnique::with('details.ingredient')
            ->where('produit_fini_id', $ordre->produit_fini_id)
            ->where('entreprise_id', $ordre->entreprise_id)
            ->first();

        if (!$fiche || $fiche->details->isEmpty()) {
            return [[], 'Aucune fiche technique trouvée pour ce produit fini.'];
        }

        $besoins = [];

        foreach ($fiche->details as $d) {
            $quantite = self::besoin((float) $d->quantite, $d->unite, $d->ingredient, (float) $ordre->quantite_cible);

            if ($quantite === null) {
                return [[], "La recette emploie « {$d->unite} » pour « {$d->ingredient?->nom} », stocké en "
                    . ($d->ingredient?->unite ?: ConversionUnitesService::UNITE_GENERIQUE) . ' : corrigez la recette.'];
            }

            $besoins[] = [
                'ingredient' => $d->ingredient,
                'quantite'   => $quantite,
                'unite'      => $d->ingredient?->unite ?: ConversionUnitesService::UNITE_GENERIQUE,
            ];
        }

        return [$besoins, null];
    }

    /** Deux quantités de stock comparées en millièmes, sans flottant. */
    private static function enMilliemes(float $quantite): int
    {
        return (int) round($quantite * 10 ** Stock::DECIMALES);
    }

    /**
     * Valider et exécuter un ordre de production (validation atomique de stock).
     *
     * Seul un ordre en brouillon se valide : un ordre annulé se validait, et
     * fabriquait. Le statut est relu **sous verrou**, dans la transaction : deux
     * clics simultanés lisaient tous deux « Brouillon » et fabriquaient deux
     * fois.
     */
    public function validerOrdre(OrdreProduction $ordre): RedirectResponse
    {
        $entrepriseId = Auth::user()->entreprise_id;
        abort_unless($ordre->entreprise_id === $entrepriseId, 404);

        if ($refus = self::refusDeValidation($ordre)) {
            return back()->with(...$refus);
        }

        [$besoins, $erreur] = self::besoinsDeLOrdre($ordre);

        if ($erreur) {
            return back()->with('erreur', $erreur);
        }

        // Un besoin que la précision du stock ramène à zéro : la sortie serait
        // refusée par la porte du stock — c'était une erreur 500.
        $nuls = collect($besoins)->filter(fn ($b) => $b['quantite'] <= 0);

        if ($nuls->isNotEmpty()) {
            return back()->with('erreur',
                'Le besoin en ' . $nuls->map(fn ($b) => $b['ingredient']->nom)->implode(', ')
                . ' est inférieur à la précision du stock (0,001) et s\'arrondit à zéro. Augmentez la quantité à '
                . 'produire, ou corrigez la recette, puis relancez un ordre.');
        }

        // 1. Contrôle de stock — une première fois sans verrou, pour ne pas
        //    créer de fiche de stock vide sur un site qui n'a rien.
        if ($erreurs = self::manquants($besoins, (int) $ordre->point_de_vente_id, false)) {
            return back()->with('erreurs_validation', $erreurs)
                ->with('erreur', 'Validation annulée en raison d\'un stock insuffisant.');
        }

        // 1 bis. Le cas où la comptabilité ressortirait de travers.
        //
        // Chaque mouvement écrit sa paire équilibrée, ou n'écrit rien. Une
        // fabrication dont les matières sont imputées mais dont le produit fini
        // ne l'est pas reste donc équilibrée au bilan — et **fausse au compte
        // de résultat** : les matières partent en charge, et rien n'entre en
        // face. La marge de l'atelier apparaît en perte sèche, et l'écart ne se
        // voit qu'à la révision.
        //
        // Le contrôle ne se déclenche que dans ce cas précis. Un atelier qui n'a
        // rien paramétré du tout ne tient simplement pas d'inventaire permanent,
        // et rien ne l'y oblige.
        $matieresImputees = collect($besoins)->contains(
            fn ($b) => ImputationService::peutTenirLInventairePermanent($b['ingredient'])
        );

        if ($matieresImputees && !ImputationService::peutTenirLInventairePermanent($ordre->produitFini)) {
            $manque = implode(', ', ImputationService::manqueUnCompte($ordre->produitFini));

            return back()->with('erreur',
                "Les matières de cet ordre s'imputent en comptabilité, mais pas le produit fini "
                . "« {$ordre->produitFini->nom} » : il lui manque le {$manque}. La fabrication "
                . 'apparaîtrait en perte sèche. Renseignez les comptes sur sa fiche ou sur son '
                . 'rayon avant de valider.');
        }

        // 2. Transaction SQL atomique, sous verrou.
        $issue = DB::transaction(function () use ($ordre, $besoins) {
            $verrouille = OrdreProduction::whereKey($ordre->getKey())->lockForUpdate()->first();

            if ($refus = self::refusDeValidation($verrouille)) {
                return ['refus' => $refus];
            }

            // Le stock relu sous le verrou de chaque fiche : c'est la seule
            // lecture qui vaille au moment d'écrire.
            if ($erreurs = self::manquants($besoins, (int) $verrouille->point_de_vente_id, true)) {
                return ['erreurs' => $erreurs];
            }

            // Ce que la fabrication a réellement coûté, matière par matière.
            // C'est le CUMP (Coût Unitaire Moyen Pondéré) de chaque sortie qui
            // le dit — non `prix_achat`, qui est un prix de catalogue figé.
            $coutMatieres = 0.0;

            foreach ($besoins as $b) {
                $sortie = StockService::sortie(
                    $b['ingredient'], (int) $verrouille->point_de_vente_id, (float) $b['quantite'],
                    MouvementStock::PRODUCTION_CONSOMMATION,
                    ['piece' => $verrouille, 'reference' => $verrouille->code_ordre]
                );

                if ($sortie) {
                    $coutMatieres += (float) $sortie->quantite * (float) ($sortie->cout_unitaire ?? 0);
                }
            }

            $produitFini = $verrouille->produitFini;
            $qtyFini = (float) $verrouille->quantite_cible;

            // **Le produit fini entre au coût de ce qui l'a fabriqué.** Il
            // entrait jusqu'ici à son propre `prix_achat` — le prix d'achat
            // d'une chose qu'on ne rachète pas, presque toujours nul ou faux.
            // La production apparaissait alors en perte sèche : les matières
            // sortaient en charge, et rien n'entrait en face.
            $coutUnitaire = $qtyFini > 0 ? round($coutMatieres / $qtyFini, Stock::DECIMALES_COUT) : 0.0;

            // « Entree » sans accent partait ici : l'ecran des mouvements
            // compare la chaine exacte, et une entree de production s'affichait
            // en rouge, precedee d'un signe moins. Le service pose la constante.
            //
            // **Aucune écriture comptable n'est appelée ici.** Depuis le lot
            // 4.2, la porte unique du stock écrit elle-même l'inventaire
            // permanent : `ComptabiliteService::genererEcritureProduction()`
            // doublait chaque consommation et chaque entrée, et le coût de
            // production ressortait au double.
            StockService::entree($produitFini, (int) $verrouille->point_de_vente_id, $qtyFini,
                MouvementStock::PRODUCTION_ENTREE,
                ['piece' => $verrouille, 'reference' => $verrouille->code_ordre,
                 'cout_unitaire' => $coutUnitaire]);

            $verrouille->update([
                'statut'        => OrdreProduction::TERMINE,
                'cout_total'    => round($coutMatieres, 2),
                'cout_unitaire' => $coutUnitaire,
            ]);

            return [];
        });

        if (isset($issue['refus'])) {
            return back()->with(...$issue['refus']);
        }

        if (isset($issue['erreurs'])) {
            return back()->with('erreurs_validation', $issue['erreurs'])
                ->with('erreur', 'Validation annulée en raison d\'un stock insuffisant.');
        }

        return redirect()->route('admin.production.ordres.index')
            ->with('succes', "L'ordre de production {$ordre->code_ordre} a été validé et le stock mis à jour !");
    }

    /**
     * Pourquoi cet ordre ne se valide pas — `null` s'il le peut.
     *
     * @return array{0: string, 1: string}|null la clé de session et le message
     */
    private static function refusDeValidation(OrdreProduction $ordre): ?array
    {
        return match ($ordre->statut) {
            OrdreProduction::BROUILLON => null,
            OrdreProduction::TERMINE   => ['info', 'Cet ordre de production est déjà terminé.'],
            OrdreProduction::ANNULE    => ['erreur', 'Cet ordre de production est annulé : il ne se valide plus.'],
            default                    => ['erreur', "Un ordre « {$ordre->statut} » ne se valide pas : seul un brouillon se valide."],
        };
    }

    /**
     * Les matières qui manquent sur le site, en millièmes exacts.
     *
     * @return array<int, string>
     */
    private static function manquants(array $besoins, int $siteId, bool $sousVerrou): array
    {
        $erreurs = [];

        foreach ($besoins as $b) {
            $dispo = $sousVerrou
                ? StockService::disponible($b['ingredient'], $siteId)
                : $b['ingredient']->fresh()->stockActuel($siteId);

            if (self::enMilliemes($dispo) < self::enMilliemes($b['quantite'])) {
                $erreurs[] = "Le stock de l'ingrédient {$b['ingredient']->nom} est insuffisant (Disponible : "
                    . OrdreProduction::quantiteLisible($dispo) . " {$b['unite']}, Requis : "
                    . OrdreProduction::quantiteLisible($b['quantite']) . " {$b['unite']}).";
            }
        }

        return $erreurs;
    }

    /**
     * Annuler un ordre de production.
     *
     * - **brouillon** : il passe « Annulé », rien ne bouge ;
     * - **terminé** : la fabrication se défait par contre-passation — chaque
     *   mouvement de l'ordre reçoit son inverse, au coût auquel il avait été
     *   passé, et l'inventaire permanent écrit les écritures inverses. Refusé
     *   si le produit fini a déjà quitté le stock : on ne défait pas une
     *   fabrication dont le fruit est vendu.
     */
    public function annulerOrdre(OrdreProduction $ordre): RedirectResponse
    {
        abort_unless($ordre->entreprise_id === Auth::user()->entreprise_id, 404);

        $issue = DB::transaction(function () use ($ordre) {
            $verrouille = OrdreProduction::whereKey($ordre->getKey())->lockForUpdate()->first();
            $etaitTermine = $verrouille->estTermine();

            if ($verrouille->statut === OrdreProduction::ANNULE) {
                return ['info', 'Cet ordre de production est déjà annulé.'];
            }

            if ($etaitTermine) {
                $dispo = StockService::disponible($verrouille->produitFini, (int) $verrouille->point_de_vente_id);

                if (self::enMilliemes($dispo) < self::enMilliemes((float) $verrouille->quantite_cible)) {
                    return ['erreur', "Annulation impossible : il ne reste que " . OrdreProduction::quantiteLisible($dispo)
                        . " « {$verrouille->produitFini->nom} » en stock sur ce site, pour "
                        . OrdreProduction::quantiteLisible($verrouille->quantite_cible) . ' fabriqué(s). '
                        . 'Le produit fini a déjà quitté le stock.'];
                }

                // Le produit fini sort d'abord, les matières reviennent ensuite,
                // chacune à son coût d'origine.
                $mouvements = MouvementStock::with('produit')
                    ->where('piece_type', $verrouille->getMorphClass())
                    ->where('piece_id', $verrouille->getKey())
                    ->whereDoesntHave('contrepassePar')
                    ->where('sous_type', '!=', MouvementStock::CONTREPASSATION)
                    ->orderByRaw('CASE WHEN sous_type = ? THEN 0 ELSE 1 END', [MouvementStock::PRODUCTION_ENTREE])
                    ->orderBy('id')
                    ->get();

                foreach ($mouvements as $m) {
                    StockService::contrePasser($m, array_filter([
                        'cout_unitaire' => $m->cout_unitaire !== null ? (float) $m->cout_unitaire : null,
                    ], fn ($v) => $v !== null));
                }
            }

            $verrouille->update(['statut' => OrdreProduction::ANNULE]);

            return ['succes', "L'ordre de production {$verrouille->code_ordre} est annulé"
                . ($etaitTermine ? ' : la fabrication a été contre-passée.' : '.')];
        });

        return redirect()->route('admin.production.ordres.index')->with(...$issue);
    }

    /**
     * La fiche d'un ordre : matières, quantités, coût.
     */
    public function voirOrdre(OrdreProduction $ordre): View
    {
        return view('admin::production.ordres.voir', $this->donneesDeLOrdre($ordre));
    }

    /**
     * Le bon de fabrication, à imprimer.
     */
    public function imprimerOrdre(OrdreProduction $ordre): View
    {
        return view('admin::production.ordres.imprimer', $this->donneesDeLOrdre($ordre));
    }

    private function donneesDeLOrdre(OrdreProduction $ordre): array
    {
        abort_unless($ordre->entreprise_id === Auth::user()->entreprise_id, 404);

        $ordre->load(['produitFini', 'pointDeVente', 'lignes.ingredient']);

        // Ce qui a été réellement consommé, d'après le journal de stock.
        $consommations = MouvementStock::with('produit')
            ->where('piece_type', $ordre->getMorphClass())
            ->where('piece_id', $ordre->getKey())
            ->where('sous_type', MouvementStock::PRODUCTION_CONSOMMATION)
            ->get()
            ->keyBy('produit_id');

        [$besoins] = $ordre->lignes->isEmpty() ? self::besoinsDeLOrdre($ordre) : [[]];

        $lignes = $ordre->lignes->isNotEmpty()
            ? $ordre->lignes->map(fn ($l) => [
                'ingredient'       => $l->ingredient,
                'quantite_recette' => (float) $l->quantite_recette,
                'unite_recette'    => $l->unite_recette,
                'quantite'         => (float) $l->quantite_requise,
            ])
            : collect($besoins)->map(fn ($b) => [
                'ingredient'       => $b['ingredient'],
                'quantite_recette' => null,
                'unite_recette'    => null,
                'quantite'         => (float) $b['quantite'],
            ]);

        $lignes = $lignes->map(function ($l) use ($consommations) {
            $m = $consommations->get($l['ingredient']?->id);

            return $l + [
                'unite'         => $l['ingredient']?->unite ?: ConversionUnitesService::UNITE_GENERIQUE,
                'consomme'      => $m ? (float) $m->quantite : null,
                'cout_unitaire' => $m ? (float) $m->cout_unitaire : null,
                'cout'          => $m ? round((float) $m->quantite * (float) $m->cout_unitaire, 2) : null,
            ];
        })->values();

        return [
            'ordre'      => $ordre,
            'lignes'     => $lignes,
            'entreprise' => Auth::user()->entreprise,
        ];
    }
}
