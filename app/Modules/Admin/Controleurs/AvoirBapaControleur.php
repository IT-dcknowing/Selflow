<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AvoirBapa;
use App\Modules\Admin\Services\AvoirBapaService;
use App\Modules\Admin\Traits\JournaliseActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * L'avoir interne d'un BAPA (chantier 8.4) — non certifié, la DGI ne le
 * normalisant pas. Voir AvoirBapaService.
 */
class AvoirBapaControleur
{
    use JournaliseActions;

    public function formulaire(Achat $achat): View|RedirectResponse
    {
        $this->autoriser($achat);

        if (!AvoirBapaService::estAvoirable($achat)) {
            return redirect()->route('admin.achats.factures')->with('erreur',
                "Seul un bordereau d'achat (BAPA) finalisé s'avoire : un achat ordinaire est une pièce du fournisseur, à lui d'émettre son avoir.");
        }

        $achat->load(['details.produit', 'fournisseur']);

        return view('admin::achats.avoir_bapa', [
            'bapa'        => $achat,
            'dejaRendu'   => AvoirBapaService::dejaRendu($achat),
            'reste'       => AvoirBapaService::resteAAvoirer($achat),
            'dejaAvoire'  => AvoirBapaService::montantDejaAvoire($achat),
            'avoirs'      => AvoirBapa::where('achat_id', $achat->id)->orderByDesc('id')->get(),
        ]);
    }

    public function enregistrer(Request $request, Achat $achat): RedirectResponse
    {
        $this->autoriser($achat);

        $request->validate([
            'motif'                 => ['required', 'string', 'max:255'],
            'lignes'                => ['required', 'array'],
            'lignes.*.quantite'     => ['nullable', 'numeric', 'min:0'],
            'lignes.*.retour_stock' => ['nullable', 'boolean'],
        ], ['motif.required' => 'Indiquez le motif de l\'avoir.']);

        $avoir = AvoirBapaService::etablir($achat, $request->input('lignes', []), $request->input('motif'), Auth::id());

        $this->journaliser('creation_avoir_bapa_interne', 'AvoirBapa', $avoir->id);

        return redirect()->route('admin.achats.avoir_bapa.voir', $avoir)
            ->with('succes', "Avoir interne {$avoir->numero} établi. Il n'est pas transmis à la DGI, qui ne normalise pas l'avoir d'un BAPA.");
    }

    public function voir(AvoirBapa $avoirBapa): View
    {
        abort_unless($avoirBapa->entreprise_id === Auth::user()->entreprise_id, 404);

        return view('admin::achats.avoir_bapa_fiche', [
            'avoir' => $avoirBapa->load(['lignes', 'bapa.fournisseur', 'pointDeVente', 'utilisateur']),
        ]);
    }

    /** Un bordereau d'une autre entreprise n'existe pas pour celle-ci : 404 (Not Found — introuvable). */
    private function autoriser(Achat $achat): void
    {
        abort_unless($achat->pointDeVente?->entreprise_id === Auth::user()->entreprise_id, 404);
    }
}
