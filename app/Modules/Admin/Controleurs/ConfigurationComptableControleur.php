<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\ImputationGlobale;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Modeles\Produit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * La configuration comptable globale (section 6 du plan).
 *
 * Les comptes se saisissaient sur chaque fiche produit. Ils se saisissent
 * désormais une fois : pour tous les produits, par catégorie, par type. Le
 * plus précis l'emporte — l'ordre complet est écrit dans
 * `ImputationService`, et nulle part ailleurs.
 *
 * La page ne vit que derrière le garde-fou `comptabilite` : sans
 * comptabilité, personne n'a de compte à choisir, et le serveur décide seul.
 */
class ConfigurationComptableControleur
{
    /** Les quatre familles de comptes, et la classe qui les admet. */
    private const CLASSES = [
        'vente'     => ['7'],
        'achat'     => ['6'],
        'stock'     => ['3'],
        'variation' => ['603', '73'],
    ];

    public function index(): View
    {
        $entreprise = Auth::user()->entreprise;
        $globale = ImputationGlobale::pour($entreprise->id);
        $comptes = $this->plan($entreprise);

        return view('admin::comptabilite.configuration', [
            'generale'   => $globale[ImputationGlobale::GENERALE] ?? ['compte_vente' => null, 'compte_achat' => null],
            'parType'    => collect(Produit::TYPES)->map(fn ($libelle, $type) => [
                'libelle' => $libelle,
            ] + ($globale[ImputationGlobale::clePourType($type)] ?? ['compte_vente' => null, 'compte_achat' => null])),
            'categories' => Categorie::where('entreprise_id', $entreprise->id)->orderBy('nom')->get(),
            'comptes'    => collect(self::CLASSES)->map(fn ($classes) => $this->deLaClasse($comptes, $classes)),
            'defauts'    => [
                'vente' => config('selflow.plan_comptable_defaut.vente_defaut'),
                'achat' => config('selflow.plan_comptable_defaut.achat_defaut'),
            ],
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        $plan = $this->plan($entreprise)->pluck('numero')->map(fn ($n) => (string) $n)->all();
        $categories = Categorie::where('entreprise_id', $entreprise->id)->get()->keyBy('id');

        $request->validate([
            'vente'     => ['nullable', 'array'],
            'achat'     => ['nullable', 'array'],
            'stock'     => ['nullable', 'array'],
            'variation' => ['nullable', 'array'],
        ]);

        // Chaque compte posté est relu : il doit exister au plan de
        // l'entreprise, et dans la bonne classe. Un compte de stock posé en
        // vente imputerait le chiffre d'affaires au bilan.
        $lire = function (string $famille, ?string $valeur, string $champ) use ($plan): ?string {
            $valeur = trim((string) $valeur);

            if ($valeur === '') {
                return null;
            }

            if (!in_array($valeur, $plan, true) || !$this->estDeLaClasse($valeur, self::CLASSES[$famille])) {
                throw ValidationException::withMessages([
                    $champ => "Le compte {$valeur} n'est pas un compte " . [
                        'vente' => 'de vente (classe 7)', 'achat' => 'd\'achat (classe 6)',
                        'stock' => 'de stock (classe 3)', 'variation' => 'de variation de stock (603 ou 73)',
                    ][$famille] . ' de votre plan comptable.',
                ]);
            }

            return $valeur;
        };

        $lignes = ['general' => [
            'compte_vente' => $lire('vente', $request->input('vente.general'), 'vente.general'),
            'compte_achat' => $lire('achat', $request->input('achat.general'), 'achat.general'),
        ]];

        foreach (array_keys(Produit::TYPES) as $type) {
            $lignes[ImputationGlobale::clePourType($type)] = [
                'compte_vente' => $lire('vente', $request->input("vente.type.{$type}"), "vente.type.{$type}"),
                'compte_achat' => $lire('achat', $request->input("achat.type.{$type}"), "achat.type.{$type}"),
            ];
        }

        // Une catégorie d'une autre entreprise, postée à la main, n'est pas
        // lue : seules les catégories de celle-ci sont parcourues.
        $parCategorie = [];
        foreach ($categories as $id => $categorie) {
            $parCategorie[$id] = [];
            foreach (['vente' => 'compte_vente', 'achat' => 'compte_achat', 'stock' => 'compte_stock', 'variation' => 'compte_variation'] as $famille => $colonne) {
                if ($request->has("{$famille}.categorie.{$id}")) {
                    $parCategorie[$id][$colonne] = $lire($famille, $request->input("{$famille}.categorie.{$id}"), "{$famille}.categorie.{$id}");
                }
            }
        }

        DB::transaction(function () use ($entreprise, $lignes, $parCategorie, $categories) {
            foreach ($lignes as $cle => $valeurs) {
                if ($valeurs['compte_vente'] === null && $valeurs['compte_achat'] === null) {
                    ImputationGlobale::where('entreprise_id', $entreprise->id)->where('cle', $cle)->get()->each->delete();
                    continue;
                }

                ImputationGlobale::updateOrCreate(['entreprise_id' => $entreprise->id, 'cle' => $cle], $valeurs);
            }

            foreach ($parCategorie as $id => $valeurs) {
                if ($valeurs !== []) {
                    $categories[$id]->update($valeurs);
                }
            }
        });

        return redirect()->route('admin.comptabilite.configuration')
            ->with('succes', 'Configuration comptable enregistrée. Elle vaut pour les écritures à venir ; celles déjà passées ne sont pas réécrites.');
    }

    /** Le plan comptable de l'entreprise : le sien, et le commun. */
    private function plan(Entreprise $entreprise): Collection
    {
        return PlanComptable::where(fn ($q) => $q->whereNull('entreprise_id')->orWhere('entreprise_id', $entreprise->id))
            ->orderBy('numero')
            ->get(['numero', 'libelle']);
    }

    private function deLaClasse(Collection $comptes, array $classes): Collection
    {
        return $comptes->filter(fn ($c) => $this->estDeLaClasse((string) $c->numero, $classes))->values();
    }

    private function estDeLaClasse(string $numero, array $classes): bool
    {
        foreach ($classes as $classe) {
            if (str_starts_with($numero, $classe)) {
                return true;
            }
        }

        return false;
    }
}
