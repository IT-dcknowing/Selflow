<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\DotationAmortissement;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Immobilisation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Services\AmortissementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Le parc immobilisé, et son amortissement.
 *
 * Rien n'existait : un camion, un four, un ordinateur passaient en charge de
 * l'exercice, ou ne passaient nulle part. Le bilan ne portait pas trace de
 * l'actif immobilisé, et **la charge d'amortissement, déductible, n'était pas
 * prise** — une entreprise qui n'amortit pas paie l'impôt sur un bénéfice
 * qu'elle n'a pas.
 */
class ImmobilisationControleur extends Controller
{
    public function index(Request $request): View
    {
        self::accordees();

        $entrepriseId = Auth::user()->entreprise_id;

        $biens = Immobilisation::with(['pointDeVente', 'dotations'])
            ->where('entreprise_id', $entrepriseId)
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->statut))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $q->where(function ($sous) use ($request) {
                    $sous->where('libelle', 'LIKE', '%' . $request->recherche . '%')
                         ->orWhere('code', 'LIKE', '%' . $request->recherche . '%');
                });
            })
            ->orderByDesc('date_acquisition')
            ->paginate(20);

        $enService = Immobilisation::where('entreprise_id', $entrepriseId)
            ->where('statut', Immobilisation::EN_SERVICE)->get();

        // Ce que le bilan porte : la valeur brute, l'amortissement cumulé, et
        // ce qui reste. C'est le tableau des immobilisations de la liasse.
        $valeurBrute = round((float) $enService->sum('valeur_acquisition'), 2);
        $cumul       = round($enService->sum(fn ($b) => $b->cumulAmorti()), 2);

        // Ce qui reste à passer sur l'exercice courant : c'est la charge que
        // l'entreprise oublierait si personne ne clôturait.
        $annee = (int) ($request->input('annee') ?: now()->year);

        $dotationsDues = DotationAmortissement::where('entreprise_id', $entrepriseId)
            ->where('annee', $annee)
            ->whereNull('comptabilise_at')
            ->where('dotation', '>', 0)
            ->whereHas('immobilisation', fn ($q) => $q->where('statut', Immobilisation::EN_SERVICE))
            ->sum('dotation');

        return view('admin::immobilisations.index', [
            'biens'         => $biens,
            'valeurBrute'   => $valeurBrute,
            'cumul'         => $cumul,
            'valeurNette'   => round($valeurBrute - $cumul, 2),
            'annee'         => $annee,
            'dotationsDues' => round((float) $dotationsDues, 2),
        ]);
    }

    public function creer(): View
    {
        self::accordees();

        $entrepriseId = Auth::user()->entreprise_id;

        return view('admin::immobilisations.formulaire', [
            'bien'          => new Immobilisation(),
            'pointsDeVente' => PointDeVente::where('entreprise_id', $entrepriseId)->orderBy('nom')->get(),
            'fournisseurs'  => Fournisseur::where('entreprise_id', $entrepriseId)->orderBy('nom')->get(),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        self::accordees();

        $entrepriseId = Auth::user()->entreprise_id;

        $donnees = $this->valider($request, $entrepriseId);

        $bien = Immobilisation::create($donnees + [
            'entreprise_id'  => $entrepriseId,
            'utilisateur_id' => Auth::id(),
            'mode'           => Immobilisation::LINEAIRE,
            'statut'         => Immobilisation::EN_SERVICE,
        ]);

        AmortissementService::etablirLePlan($bien);

        return redirect()->route('admin.immobilisations.fiche', $bien)
            ->with('succes', 'Immobilisation enregistrée, plan d\'amortissement établi.');
    }

    public function fiche(Immobilisation $bien): View
    {
        self::accordees();

        abort_unless($bien->entreprise_id === Auth::user()->entreprise_id, 404);

        $bien->load(['dotations', 'pointDeVente', 'fournisseur']);

        return view('admin::immobilisations.fiche', compact('bien'));
    }

    public function modifier(Request $request, Immobilisation $bien): RedirectResponse
    {
        self::accordees();

        abort_unless($bien->entreprise_id === Auth::user()->entreprise_id, 404);

        // Une fiche dont une dotation est passée ne se retouche plus : changer
        // la durée ou la valeur d'un bien à moitié amorti mettrait le plan en
        // désaccord avec les écritures déjà au grand livre, et le désaccord ne
        // se verrait qu'au bilan de l'année suivante.
        if ($bien->estEngage()) {
            return back()->with('erreur',
                'Le plan de ce bien est déjà entamé en comptabilité : sa fiche ne se '
                . 'modifie plus. Une erreur se corrige par une écriture, non en réécrivant '
                . 'le plan.');
        }

        $bien->update($this->valider($request, $bien->entreprise_id, $bien->id));

        AmortissementService::etablirLePlan($bien->fresh());

        return back()->with('succes', 'Immobilisation mise à jour, plan recalculé.');
    }

    /**
     * Passer la dotation d'un exercice, bien par bien.
     */
    public function passerLaDotation(DotationAmortissement $dotation): RedirectResponse
    {
        self::accordees();

        abort_unless($dotation->entreprise_id === Auth::user()->entreprise_id, 404);

        if ($dotation->estComptabilisee()) {
            return back()->with('info', 'Cette dotation est déjà passée.');
        }

        AmortissementService::comptabiliser($dotation);

        return back()->with('succes', "Dotation {$dotation->annee} passée en comptabilité.");
    }

    /**
     * Le geste de clôture : toutes les dotations dues de l'exercice.
     */
    public function cloturerLExercice(Request $request): RedirectResponse
    {
        self::accordees();

        $request->validate(['annee' => ['required', 'integer', 'min:2000', 'max:2100']]);

        $passees = AmortissementService::comptabiliserLExercice(
            Auth::user()->entreprise_id, (int) $request->annee
        );

        return back()->with('succes', $passees === 0
            ? "Aucune dotation ne restait à passer pour {$request->annee}."
            : "{$passees} dotation(s) passée(s) pour l'exercice {$request->annee}.");
    }

    /**
     * Sortir un bien du bilan : cession ou rebut.
     */
    public function ceder(Request $request, Immobilisation $bien): RedirectResponse
    {
        self::accordees();

        abort_unless($bien->entreprise_id === Auth::user()->entreprise_id, 404);

        $request->validate([
            'date_sortie'  => ['required', 'date'],
            'prix_cession' => ['nullable', 'numeric', 'min:0'],
        ], [
            'date_sortie.required' => 'La date de sortie est nécessaire : c\'est elle qui arrête l\'amortissement.',
        ]);

        if ($bien->estSorti()) {
            return back()->with('erreur', 'Ce bien est déjà sorti du bilan.');
        }

        // Une sortie antérieure à la mise en service n'a pas de sens, et
        // produirait une dotation négative.
        if ($request->date('date_sortie')->lt($bien->date_mise_en_service)) {
            return back()->with('erreur',
                'La sortie ne peut pas précéder la mise en service du '
                . $bien->date_mise_en_service->format('d/m/Y') . '.');
        }

        AmortissementService::ceder(
            $bien,
            $request->date('date_sortie')->toDateString(),
            (float) $request->input('prix_cession', 0)
        );

        return back()->with('succes', $request->input('prix_cession') > 0
            ? 'Cession enregistrée : le bien sort du bilan et la plus-value ressort au résultat.'
            : 'Bien mis au rebut : sa valeur nette part en charge.');
    }

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request, int $entrepriseId, ?int $sauf = null): array
    {
        return $request->validate([
            'code'    => ['required', 'string', 'max:50',
                          \Illuminate\Validation\Rule::unique('immobilisations', 'code')
                              ->where('entreprise_id', $entrepriseId)
                              ->ignore($sauf)],
            'libelle' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'point_de_vente_id' => ['nullable', 'integer', Appartenance::a('points_de_vente', 'id')],
            'fournisseur_id'    => ['nullable', 'integer', Appartenance::a('fournisseurs', 'id')],
            // Les trois comptes sont portés par la fiche : un four et un camion
            // s'amortissent tous deux sur 24x, mais un logiciel va sur 213x et
            // sa dotation sur 681200. Deviner rendrait le bilan faux.
            'compte_immobilisation' => ['required', 'string', 'max:20'],
            'compte_amortissement'  => ['required', 'string', 'max:20'],
            'compte_dotation'       => ['required', 'string', 'max:20'],
            'date_acquisition'      => ['required', 'date'],
            // C'est la mise en service qui déclenche l'amortissement, et elle
            // ne précède pas l'acquisition.
            'date_mise_en_service'  => ['required', 'date', 'after_or_equal:date_acquisition'],
            'valeur_acquisition'    => ['required', 'numeric', 'min:0'],
            'valeur_residuelle'     => ['nullable', 'numeric', 'min:0', 'lte:valeur_acquisition'],
            // Zéro pour un terrain, qui ne s'amortit pas. Cent ans au plus :
            // au-delà, la colonne déborderait sans rien signaler.
            'duree_mois'            => ['required', 'integer', 'min:0', 'max:1200'],
        ], [
            'code.unique' => 'Ce code désigne déjà un autre bien : c\'est lui que porte l\'étiquette collée dessus.',
            'date_mise_en_service.after_or_equal' => 'Un bien ne se met pas en service avant d\'être acquis.',
            'valeur_residuelle.lte' => 'La valeur résiduelle ne peut pas dépasser la valeur d\'acquisition.',
            'duree_mois.max' => 'Une durée d\'amortissement se compte en mois, cent ans au plus.',
        ]);
    }

    /**
     * Les immobilisations s'accordent par le superadministrateur, comme les
     * encaissements (16/10/2026) : la comptabilité ouverte ne suffit pas.
     */
    private static function accordees(): void
    {
        $utilisateur = \Illuminate\Support\Facades\Auth::user();
        abort_unless($utilisateur?->estSuperAdmin() || $utilisateur?->entreprise?->immobilisationsAccordees(), 404);
    }
}
