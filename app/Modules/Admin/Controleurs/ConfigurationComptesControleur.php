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
 * La configuration globale des comptes — section 6 du plan.
 *
 * Une page unique remplace la saisie d'un compte sur chaque fiche produit :
 * le compte de vente et d'achat de tous les articles, par catégorie, par type.
 * Le plus précis l'emporte. Elle ne s'ouvre qu'avec la comptabilité : le
 * groupe de routes porte le garde-fou `comptabilite`.
 */
class ConfigurationComptesControleur
{
    public function index(): View
    {
        $entreprise = Auth::user()->entreprise;

        $lignes = ConfigurationCompte::where('entreprise_id', $entreprise->id)->get()
            ->keyBy(fn ($l) => $l->portee . ':' . $l->cle);

        return view('admin::comptabilite.configuration_comptes', [
            'lignes'     => $lignes,
            'categories' => Categorie::where('entreprise_id', $entreprise->id)->orderBy('nom')->get(),
            'types'      => Produit::TYPES,
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
        ], [
            'config.*.compte_vente.regex' => 'Un compte de vente est un compte de classe 7.',
            'config.*.compte_achat.regex' => "Un compte d'achat est un compte de classe 6.",
            'stock.*.compte_stock.regex'  => 'Un compte de stock est un compte de classe 3.',
            'stock.*.compte_variation.regex' => 'Un compte de variation est un 603x (achats) ou un 73x (production).',
        ]);

        // Les catégories de l'entreprise, et elles seules : un identifiant
        // forgé rangerait la configuration d'une autre entreprise.
        $categories = Categorie::where('entreprise_id', $entreprise->id)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $types = array_keys(Produit::TYPES);

        DB::transaction(function () use ($request, $entreprise, $categories, $types) {
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

                $vente = trim((string) ($valeurs['compte_vente'] ?? '')) ?: null;
                $achat = trim((string) ($valeurs['compte_achat'] ?? '')) ?: null;

                // Deux champs vides : la ligne n'a plus rien à dire, elle part.
                // La garder ferait croire à une règle qui n'en est pas une.
                if ($vente === null && $achat === null) {
                    ConfigurationCompte::where(['entreprise_id' => $entreprise->id, 'portee' => $portee, 'cle' => $cle])->delete();
                    continue;
                }

                ConfigurationCompte::updateOrCreate(
                    ['entreprise_id' => $entreprise->id, 'portee' => $portee, 'cle' => $cle],
                    ['compte_vente' => $vente, 'compte_achat' => $achat]
                );
            }

            // Les comptes de stock et de variation vivent sur la famille, où le
            // référentiel les pose. Les montrer, et laisser les corriger
            // (chantier 6.3) : rien n'est demandé, tout est modifiable.
            foreach ((array) $request->input('stock', []) as $categorieId => $valeurs) {
                if (!in_array((string) $categorieId, $categories, true)) {
                    continue;
                }

                Categorie::whereKey($categorieId)->update([
                    'compte_stock'     => trim((string) ($valeurs['compte_stock'] ?? '')) ?: null,
                    'compte_variation' => trim((string) ($valeurs['compte_variation'] ?? '')) ?: null,
                ]);
            }
        });

        ConfigurationCompte::oublier();

        return back()->with('succes', 'Configuration des comptes enregistrée.');
    }
}
