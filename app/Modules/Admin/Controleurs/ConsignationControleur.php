<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Consignation;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Regles\Quantite;
use App\Modules\Admin\Services\ConsignationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Ce qui est dehors : les emballages prêtés, et ce qu'ils représentent.
 *
 * Un dépôt de boissons ne savait pas combien de casiers dorment chez ses
 * clients, ni depuis quand, ni chez qui. Et la consignation, qui est **une
 * dette**, passait en vente : une caisse consignée 2 000 francs gonflait le
 * chiffre d'affaires de 2 000 francs que l'entreprise devra rendre.
 */
class ConsignationControleur extends Controller
{
    public function index(Request $request): View
    {
        $entrepriseId = Auth::user()->entreprise_id;
        $sens = $request->input('sens') === Consignation::DU_FOURNISSEUR
            ? Consignation::DU_FOURNISSEUR
            : Consignation::AU_CLIENT;

        $consignations = Consignation::with(['client', 'fournisseur', 'produit', 'pointDeVente'])
            ->where('entreprise_id', $entrepriseId)
            ->where('sens', $sens)
            // Un membre affecté ne voit que les consignations de son site (audit du 23/11/2026).
            ->when(\App\Modules\Admin\Services\SiteAutorise::siteImpose(), fn ($q, $site) => $q->where('point_de_vente_id', $site))
            ->when($request->input('etat') === 'en_retard', fn ($q) => $q->enRetard())
            ->when($request->input('etat') === 'en_cours', fn ($q) => $q->enCours())
            ->when($request->input('etat') === 'closes',
                fn ($q) => $q->whereIn('statut', [Consignation::RENDUE, Consignation::NON_RENDUE]))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $q->where('designation', 'LIKE', '%' . $request->recherche . '%');
            })
            ->orderByRaw("CASE WHEN statut = '" . Consignation::EN_COURS . "' THEN 0 ELSE 1 END")
            ->orderBy('date_limite_retour')
            ->paginate(25)
            ->withQueryString();

        return view('admin::consignations.index', [
            'consignations' => $consignations,
            'sens'          => $sens,
            'etat'          => $request->input('etat', 'en_cours'),
            'dehors'        => ConsignationService::ceQuiEstDehors($entrepriseId, $sens, \App\Modules\Admin\Services\SiteAutorise::siteImpose()),
            'emballages'    => Produit::where('entreprise_id', $entrepriseId)
                ->selectionnables()
                ->whereNotNull('prix_consignation')
                ->where('prix_consignation', '>', 0)
                ->orderBy('nom')->get(),
            'clients'       => Client::where('entreprise_id', $entrepriseId)->orderBy('nom')->get(),
            'fournisseurs'  => Fournisseur::where('entreprise_id', $entrepriseId)->orderBy('nom')->get(),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $entrepriseId = Auth::user()->entreprise_id;

        $request->validate([
            'sens'           => ['required', 'string', 'in:client,fournisseur'],
            'client_id'      => ['required_if:sens,client', 'nullable', 'integer', Appartenance::a('clients', 'id')],
            'fournisseur_id' => ['required_if:sens,fournisseur', 'nullable', 'integer', Appartenance::a('fournisseurs', 'id')],
            'produit_id'     => ['nullable', 'integer', Appartenance::a('produits', 'id')],
            'designation'    => ['nullable', 'string', 'max:200'],
            'quantite'       => Quantite::physique(),
            'prix_consigne'  => ['nullable', 'numeric', 'min:0'],
            'reference'      => ['nullable', 'string', 'max:100'],
        ], [
            'client_id.required_if'      => 'Une consignation au client désigne un client.',
            'fournisseur_id.required_if' => 'Une consignation du fournisseur désigne un fournisseur.',
        ]);

        $emballage = $request->filled('produit_id') ? Produit::find($request->produit_id) : null;

        // Sans article au catalogue, il faut au moins un nom : la ligne serait
        // sinon illisible sur l'écran de ce qui est dehors.
        if (!$emballage && !$request->filled('designation')) {
            return back()->withInput()->with('erreur',
                'Choisissez un emballage au catalogue, ou donnez-lui un nom.');
        }

        $consignation = ConsignationService::consigner(
            $entrepriseId,
            $this->siteActif(),
            $request->sens,
            $request->sens === Consignation::AU_CLIENT ? (int) $request->client_id : (int) $request->fournisseur_id,
            $emballage,
            (float) $request->quantite,
            $request->filled('prix_consigne') ? (float) $request->prix_consigne : null,
            ['reference' => $request->reference, 'designation' => $request->designation]
        );

        if (!$consignation) {
            return back()->withInput()->with('erreur',
                'Une consignation a un prix : renseignez-le ici, ou sur la fiche de l\'emballage.');
        }

        return back()->with('succes',
            'Consignation enregistrée. Elle vit au passif jusqu\'au retour de l\'emballage.');
    }

    public function rendre(Request $request, Consignation $consignation): RedirectResponse
    {
        abort_unless($consignation->entreprise_id === Auth::user()->entreprise_id, 404);

        $request->validate([
            'quantite'         => Quantite::physique(),
            'prix_de_reprise'  => ['nullable', 'numeric', 'min:0'],
            'date'             => ['nullable', 'date'],
        ]);

        try {
            ConsignationService::rendre(
                $consignation,
                (float) $request->quantite,
                $request->filled('prix_de_reprise') ? (float) $request->prix_de_reprise : null,
                $request->input('date')
            );
        } catch (\InvalidArgumentException | \LogicException $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', 'Reprise enregistrée.');
    }

    /**
     * Constater qu'un emballage ne reviendra pas.
     *
     * **Aucune facture n'est établie ici.** Le non-retour est une vente,
     * soumise à la TVA et à la certification de la plateforme : elle passe par
     * l'écran de vente ordinaire, dont la conformité est acquise et gelée.
     */
    public function constaterLeNonRetour(Request $request, Consignation $consignation): RedirectResponse
    {
        abort_unless($consignation->entreprise_id === Auth::user()->entreprise_id, 404);

        $request->validate(['date' => ['nullable', 'date']]);

        try {
            ConsignationService::constaterLeNonRetour($consignation, $request->input('date'));
        } catch (\LogicException $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', $consignation->fresh()->estAuClient()
            ? 'Non-retour constaté : la dette devient un produit. Établissez la facture '
              . 'correspondante depuis l\'écran des ventes pour la part fiscale.'
            : 'Non-retour constaté : la créance devient une charge.');
    }

    private function siteActif(): int
    {
        return (int) (session('point_de_vente_actif_id')
            ?? Auth::user()->point_de_vente_id
            ?? \App\Modules\Admin\Modeles\PointDeVente::where('entreprise_id', Auth::user()->entreprise_id)
                ->value('id'));
    }
}
