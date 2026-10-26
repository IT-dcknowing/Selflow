<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\ConfigurationCompte;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Modeles\Produit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * La configuration globale des comptes — section 6 du plan, refondue le
 * 26/10/2026.
 *
 * Une page unique remplace la saisie d'un compte sur chaque fiche produit.
 * Quatre onglets (générale, par catégorie, par type, par article) et, dans
 * chacun, **un seul tableau** : vente, achat et variation de stock côte à
 * côte, la variation seulement là où il y a du stock. Chaque compte se lit en
 * deux cases : le **proposé** (le défaut, la famille, le référentiel du type,
 * ou ce dont l'article hérite) et le **personnalisé**, prioritaire. Le plus
 * précis l'emporte. Elle ne s'ouvre qu'avec la comptabilité : le groupe de
 * routes porte le garde-fou `comptabilite`.
 */
class ConfigurationComptesControleur
{
    public function index(): View
    {
        $entreprise = Auth::user()->entreprise;

        $lignes = ConfigurationCompte::where('entreprise_id', $entreprise->id)->get()
            ->keyBy(fn ($l) => $l->portee . ':' . $l->cle);

        $categories = Categorie::where('entreprise_id', $entreprise->id)->orderBy('nom')->get();

        // Une catégorie « a du stock » si l'un de ses articles se stocke, ou
        // si sa famille porte déjà un compte de variation : c'est là, et là
        // seulement, que la variation se règle (26/10/2026).
        $categoriesStockees = Produit::where('entreprise_id', $entreprise->id)
            ->whereIn('type', Produit::TYPES_STOCKABLES)
            ->whereNotNull('categorie_id')
            ->distinct()->pluck('categorie_id')->map(fn ($id) => (int) $id)->all();

        return view('admin::comptabilite.configuration_comptes', [
            'lignes'     => $lignes,
            'categories' => $categories,
            'categoriesAvecStock' => $categories
                ->filter(fn ($c) => in_array((int) $c->id, $categoriesStockees, true) || trim((string) $c->compte_variation) !== '')
                ->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'types'      => Produit::TYPES,
            'typesStockables' => Produit::TYPES_STOCKABLES,
            'proposesParType' => ConfigurationCompte::PROPOSES_PAR_TYPE,
            'variationEnPlace' => ConfigurationCompte::porteLaVariation(),
            // Retour du 16/10/2026 : une section « Articles » à côté des types.
            'articles'   => Produit::with('categorieRelation')
                ->where('entreprise_id', $entreprise->id)
                ->orderBy('nom')
                ->get(['id', 'nom', 'reference', 'type', 'categorie_id', 'entreprise_id', 'compte_vente', 'compte_achat', 'compte_variation']),
            'comptes'    => PlanComptable::obtenirComptesPrioritaires($entreprise->id),
            'defauts'    => [
                'compte_vente' => config('selflow.plan_comptable_defaut.vente_defaut'),
                'compte_achat' => config('selflow.plan_comptable_defaut.achat_defaut'),
            ],
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        $request->validate([
            'config'                  => ['nullable', 'array'],
            'config.*.compte_vente'   => ['nullable', 'regex:/^7\d{3,9}$/'],
            'config.*.compte_achat'   => ['nullable', 'regex:/^6\d{3,9}$/'],
            'stock'                   => ['nullable', 'array'],
            'stock.*.compte_stock'     => ['nullable', 'regex:/^3\d{3,9}$/'],
            'stock.*.compte_variation' => ['nullable', 'regex:/^(603|73)\d{1,7}$/'],
            'config.*.compte_variation' => ['nullable', 'regex:/^(603|73)\d{1,7}$/'],
            'article'                  => ['nullable', 'array'],
            'article.*.compte_vente'   => ['nullable', 'regex:/^7\d{3,9}$/'],
            'article.*.compte_achat'   => ['nullable', 'regex:/^6\d{3,9}$/'],
            'article.*.compte_variation' => ['nullable', 'regex:/^(603|73)\d{1,7}$/'],
        ], [
            'config.*.compte_variation.regex' => 'Un compte de variation est un 603x (achats) ou un 73x (production).',
            'article.*.compte_variation.regex' => 'Un compte de variation est un 603x (achats) ou un 73x (production).',
            'article.*.compte_vente.regex' => 'Un compte de vente est un compte de classe 7.',
            'article.*.compte_achat.regex' => "Un compte d'achat est un compte de classe 6.",
            'config.*.compte_vente.regex' => 'Un compte de vente est un compte de classe 7.',
            'config.*.compte_achat.regex' => "Un compte d'achat est un compte de classe 6.",
            'stock.*.compte_stock.regex'  => 'Un compte de stock est un compte de classe 3.',
            'stock.*.compte_variation.regex' => 'Un compte de variation est un 603x (achats) ou un 73x (production).',
        ]);

        // Les catégories de l'entreprise, et elles seules : un identifiant
        // forgé rangerait la configuration d'une autre entreprise.
        $categories = Categorie::where('entreprise_id', $entreprise->id)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $types = array_keys(Produit::TYPES);

        $variationEnPlace = ConfigurationCompte::porteLaVariation();

        DB::transaction(function () use ($request, $entreprise, $categories, $types, $variationEnPlace) {
            foreach ((array) $request->input('config', []) as $cleComplete => $valeurs) {
                [$portee, $cle] = array_pad(explode(':', (string) $cleComplete, 2), 2, '');

                $admise = match ($portee) {
                    ConfigurationCompte::GENERAL   => $cle === '',
                    ConfigurationCompte::CATEGORIE => in_array($cle, $categories, true),
                    ConfigurationCompte::TYPE      => in_array($cle, $types, true),
                    default => false,
                };

                if (!$admise) {
                    continue;
                }

                $existante = ConfigurationCompte::where(['entreprise_id' => $entreprise->id, 'portee' => $portee, 'cle' => $cle])->first();

                // Un champ absent du formulaire ne change pas : la variation
                // n'est proposée qu'aux lignes qui ont du stock (26/10/2026).
                $lire = fn (string $champ) => array_key_exists($champ, (array) $valeurs)
                    ? (trim((string) $valeurs[$champ]) ?: null)
                    : ($existante?->$champ ?? null);

                $vente = $lire('compte_vente');
                $achat = $lire('compte_achat');
                // La configuration générale ne porte pas de variation : un
                // 603 pour tout, prestations et produits finis compris,
                // fausserait l'inventaire permanent.
                $variation = $variationEnPlace && $portee !== ConfigurationCompte::GENERAL ? $lire('compte_variation') : null;

                // Plus rien de personnalisé : la ligne part. La garder ferait
                // croire à une règle qui n'en est pas une.
                if ($vente === null && $achat === null && $variation === null) {
                    $existante?->delete();
                    continue;
                }

                ConfigurationCompte::updateOrCreate(
                    ['entreprise_id' => $entreprise->id, 'portee' => $portee, 'cle' => $cle],
                    ['compte_vente' => $vente, 'compte_achat' => $achat]
                        + ($variationEnPlace ? ['compte_variation' => $variation] : [])
                );
            }

            // Les comptes de stock et de variation vivent sur la famille, où le
            // référentiel les pose. Les montrer, et laisser les corriger
            // (chantier 6.3) : rien n'est demandé, tout est modifiable.
            foreach ((array) $request->input('stock', []) as $categorieId => $valeurs) {
                if (!in_array((string) $categorieId, $categories, true)) {
                    continue;
                }

                // Seuls les champs envoyés changent : depuis le 26/10/2026 la
                // page n'envoie plus que le compte de stock, la variation de la
                // famille restant le « proposé ».
                $maj = [];
                foreach (['compte_stock', 'compte_variation'] as $champ) {
                    if (array_key_exists($champ, (array) $valeurs)) {
                        $maj[$champ] = trim((string) $valeurs[$champ]) ?: null;
                    }
                }
                if ($maj !== []) {
                    Categorie::whereKey($categorieId)->update($maj);
                }
            }

            // L'exception d'un article : le compte saisi devient le sien, et
            // passe devant tout. Vide, l'article retourne à ce qui s'hérite.
            // Seuls les champs modifiés arrivent (la page désactive les autres
            // avant l'envoi) : un catalogue de mille articles dépasserait
            // sinon le plafond de champs du serveur.
            $articles = (array) $request->input('article', []);
            if ($articles !== []) {
                $produits = Produit::where('entreprise_id', $entreprise->id)
                    ->whereIn('id', array_keys($articles))
                    ->get()
                    ->keyBy('id');

                foreach ($articles as $produitId => $valeurs) {
                    $produit = $produits->get($produitId);
                    if (!$produit) {
                        continue;
                    }

                    foreach (['compte_vente', 'compte_achat', 'compte_variation'] as $champ) {
                        if (array_key_exists($champ, (array) $valeurs)) {
                            $produit->$champ = trim((string) $valeurs[$champ]) ?: null;
                        }
                    }

                    $produit->save();
                }
            }
        });

        ConfigurationCompte::oublier();

        return back()->with('succes', 'Configuration des comptes enregistrée.');
    }
}
