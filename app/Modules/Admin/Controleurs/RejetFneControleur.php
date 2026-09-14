<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\PortailFneDemande;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\CorrectionFneService;
use App\Modules\Admin\Services\DiagnosticFneService;
use App\Modules\Admin\Services\ScraperPortailFneService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * L'écran des pièces que la DGI a refusées.
 *
 * ## Pourquoi il existe
 *
 * Le rapprochement écrivait déjà, chaque heure, la comparaison entre la pièce
 * refusée et le dernier relevé du portail. **Personne ne pouvait la lire.** Une
 * facture refusée la nuit, un diagnostic posé à 1 h 10, et rien à l'écran :
 * tout le mécanisme était aveugle.
 *
 * ## Ce que l'écran permet, et ce qu'il refuse
 *
 * Il permet d'appliquer une correction **descriptive** — le nom d'un point de
 * vente mal orthographié — après l'avoir lue, en un clic. C'est le même geste
 * qu'un renommage depuis l'écran des points de vente, à ceci près qu'ici la
 * valeur du portail est affichée en face.
 *
 * Il refuse d'appliquer les écarts de fiche. `timbre_quittance`, `bapa` et
 * `sticker_solde_alerte` commandent le comportement fiscal : ils sont montrés,
 * jamais recopiés. La règle d'or du projet tient précisément à ce que six
 * écarts de ce genre ont produit, par le passé, des pièces certifiées
 * différentes de celles établies dans Selflow.
 */
class RejetFneControleur
{
    /**
     * Le filtre par cause, et la valeur qui désigne les rejets sans cause.
     *
     * `NULL` ne se passe pas dans une URL ; les lignes consignées avant que la
     * colonne existe ont pourtant besoin d'être atteignables, faute de quoi
     * elles disparaissent de l'écran dès qu'on filtre.
     */
    private const CAUSE_NON_CLASSES = 'non-classes';

    public function index(): View
    {
        $entreprise = Auth::user()->entreprise;

        $causesConnues = [
            FneRejet::CAUSE_DGI,
            FneRejet::CAUSE_RESEAU,
            FneRejet::CAUSE_LOCALE,
            self::CAUSE_NON_CLASSES,
        ];

        // Une valeur inventée dans l'URL ne filtre rien plutôt que de rendre
        // une liste vide : un écran vide se lit « aucun rejet », ce qui est
        // exactement le contraire de ce qu'il faut comprendre.
        $causeActive = in_array(request('cause'), $causesConnues, true)
            ? request('cause')
            : null;

        $pourEntreprise = fn() => FneRejet::where('entreprise_id', $entreprise->id);

        // Les rejets ouverts d'abord, les classés en dernier. Un `CASE` et non
        // `FIELD()`, qui n'existe que chez MySQL : les épreuves tournent sur
        // SQLite, et une requête qui n'y passe pas n'est jamais éprouvée.
        $rejets = $pourEntreprise()
            ->when($causeActive === self::CAUSE_NON_CLASSES, fn($q) => $q->whereNull('cause'))
            ->when(
                $causeActive !== null && $causeActive !== self::CAUSE_NON_CLASSES,
                fn($q) => $q->where('cause', $causeActive)
            )
            ->orderByRaw("CASE statut WHEN 'ouvert' THEN 0 WHEN 'diagnostique' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate(25)
            // Sans quoi la page 2 revient sur la liste entière, et l'on croit
            // que le filtre a lâché.
            ->withQueryString();

        // Un seul passage plutôt qu'une requête par cause. `COALESCE` et non
        // une clé nulle : en PHP, un index `null` devient `''` et la ligne des
        // non-classés se perdrait en silence.
        $parCause = $pourEntreprise()
            ->selectRaw('COALESCE(cause, ?) as cause, COUNT(*) as total', [self::CAUSE_NON_CLASSES])
            ->groupBy('cause')
            ->pluck('total', 'cause');

        $compte = fn(string $statut) => FneRejet::where('entreprise_id', $entreprise->id)
            ->where('statut', $statut)
            ->count();

        // La dernière fiche porte les écarts de paramétrage, qui ne sont la
        // cause d'aucun rejet mais que celui qui répare une facture a intérêt
        // à voir.
        $fiche = PortailFneFiche::where('entreprise_id', $entreprise->id)
            ->orderByDesc('date_scraping')
            ->orderByDesc('id')
            ->first();

        // Elle ne date plus l'écran pour autant. Depuis qu'un relevé identique
        // au précédent n'écrit plus de fiche, sa date est celle du dernier
        // *changement* du portail, pas celle du dernier passage. Les confondre
        // afficherait « relevé du 15/08 » un 27/08 sur un scraper qui tourne
        // parfaitement — et ferait chercher une panne là où il n'y en a pas.
        $dernierPassage = PortailFneImport::where('entreprise_id', $entreprise->id)
            ->where('statut', PortailFneImport::STATUT_IMPORTE)
            ->max('dernier_releve_le');

        return view('admin::fne.rejets', [
            'entreprise' => $entreprise,
            'rejets' => $rejets,
            'causeActive' => $causeActive,
            // L'ordre des onglets est celui de l'urgence : ce que la DGI a
            // refusé d'abord, ce qui n'est jamais parti ensuite, ce qui n'a
            // même pas quitté Selflow en dernier.
            'filtresCause' => [
                FneRejet::CAUSE_DGI => ['libelle' => 'Refus DGI', 'total' => $parCause[FneRejet::CAUSE_DGI] ?? 0],
                FneRejet::CAUSE_RESEAU => ['libelle' => 'Réseau', 'total' => $parCause[FneRejet::CAUSE_RESEAU] ?? 0],
                FneRejet::CAUSE_LOCALE => ['libelle' => 'Bloqué ici', 'total' => $parCause[FneRejet::CAUSE_LOCALE] ?? 0],
                self::CAUSE_NON_CLASSES => ['libelle' => 'Cause inconnue', 'total' => $parCause[self::CAUSE_NON_CLASSES] ?? 0],
            ],
            'totalRejets' => $parCause->sum(),
            'fiche' => $fiche,
            'dernierPassage' => $dernierPassage ? CarbonImmutable::parse($dernierPassage) : null,
            'ecartsFiche' => $fiche?->ecartsAvecEntreprise() ?? [],
            'demandes' => PortailFneDemande::where('entreprise_id', $entreprise->id)
                ->where('statut', PortailFneDemande::STATUT_EN_ATTENTE)
                ->orderBy('created_at')
                ->get(),
            'kpis' => [
                'ouverts' => $compte(FneRejet::STATUT_OUVERT),
                'diagnostiques' => $compte(FneRejet::STATUT_DIAGNOSTIQUE),
                'resolus' => $compte(FneRejet::STATUT_RESOLU),
            ],
        ]);
    }

    /**
     * Rejoue le rapprochement sur un rejet, sans attendre le passage horaire.
     */
    public function diagnostiquer(FneRejet $rejet, DiagnosticFneService $service): RedirectResponse
    {
        $this->verifierAppartenance($rejet);

        // La DGI n'a jamais examiné la pièce : il n'y a rien à comparer au
        // portail, et le rapprochement ne rendrait qu'une conclusion vide.
        // Le rejouer déguiserait un incident de transport en écart de données.
        if ($rejet->estReseau()) {
            return back()->with('erreur', "Cette pièce n'a pas été refusée par la DGI : la plateforme "
                . "n'a pas répondu. Il n'y a rien à rapprocher du portail — la pièce est à renvoyer.");
        }

        $diagnostic = $service->diagnostiquer($rejet);

        if ($diagnostic['releve'] === null) {
            // Le message annonçait qu'une demande venait d'être déposée. Rien
            // ne la dépose ici : la demande est ouverte à la consignation du
            // rejet, par `FneRejet::consigner()`, et elle l'est déjà ou ne le
            // sera pas. Une interface qui annonce un geste qu'elle n'a pas
            // fait se paie au moment où l'on attend le résultat.
            return back()->with('erreur', "Aucun relevé du portail n'est disponible pour cette entreprise. "
                . 'Le rapprochement se fera dès qu\'un relevé sera arrivé dans le dossier d\'import.');
        }

        $rejet->update([
            'diagnostic' => $diagnostic,
            'statut' => $rejet->statut === FneRejet::STATUT_RESOLU
                ? FneRejet::STATUT_RESOLU
                : FneRejet::STATUT_DIAGNOSTIQUE,
        ]);

        return back()->with('succes', 'Rapprochement effectué. ' . $diagnostic['conclusion']);
    }

    /**
     * Applique une correction descriptive, et une seule à la fois.
     *
     * Le champ à corriger est repris du diagnostic déjà écrit, pas du
     * formulaire : un navigateur peut poster ce qu'il veut, et la liste des
     * champs corrigeables ne se négocie pas côté client.
     */
    public function appliquer(FneRejet $rejet, CorrectionFneService $correcteur): RedirectResponse
    {
        // dd('test');
        $this->verifierAppartenance($rejet);

        $piece = $rejet->piece();
        $pdv = $piece?->pointDeVente;

        if (!$pdv || $pdv->entreprise_id !== Auth::user()->entreprise->id) {
            return back()->with('erreur', 'Le point de vente de cette pièce est introuvable.');
        }

        // La règle vit dans le service, celle-là même que suit le passage
        // horaire. Le bouton reste : la correction automatique peut être
        // éteinte, et un rejet arrivé entre deux passages n'attend pas l'heure
        // ronde. Synchrone : l'utilisateur attend le résultat à l'écran.
        $fait = $correcteur->corriger($rejet, synchrone: true);

        if ($fait === null) {
            return back()->with('erreur', "Aucune correction applicable sur ce rejet. "
                . 'Rapprochez-le d\'un relevé du portail d\'abord.');
        }

        return back()->with('succes', $this->phraseDeCorrection($fait));
    }

    /**
     * La phrase de succès d'une correction, selon ce qui a été fait.
     *
     * Deux cas : on a renommé un point de vente pour l'aligner sur le portail,
     * ou — un point portant déjà ce nom existait — on a rattaché les pièces à
     * ce point existant sans en créer un doublon.
     *
     * @param  array{mode?: string, ancien: string, nouveau: string, renvoyees: int, suspendues: int}  $fait
     */
    private function phraseDeCorrection(array $fait): string
    {
        $intro = ($fait['mode'] ?? 'bascule') === 'cree'
            ? sprintf(
                'Le point de vente « %s » a été créé dans Selflow d\'après la déclaration du portail, et les pièces lui ont été rattachées.',
                $fait['nouveau']
            )
            : sprintf(
                'Les pièces ont été rattachées au point de vente « %s », déjà déclaré au '
                . 'portail — le point « %s » n\'a pas été modifié.',
                $fait['nouveau'],
                $fait['ancien']
            );

        return $intro . ' ' . $this->direLeRenvoi($fait);
    }

    /**
     * Ce qu'il est advenu des pièces que ce nom faisait refuser.
     *
     * Le message le dit toujours, y compris quand rien n'est reparti : « la
     * pièce peut être renvoyée » laissait croire à un geste restant à faire là
     * où il n'y en a plus, et taire un renvoi suspendu ferait attendre une
     * certification qui n'arrivera jamais.
     *
     * @param  array{renvoyees: int, suspendues: int}  $fait
     */
    private function direLeRenvoi(array $fait): string
    {
        $phrases = [];

        if ($fait['renvoyees'] > 0) {
            $phrases[] = $fait['renvoyees'] === 1
                ? 'La pièce est repartie à la DGI.'
                : "{$fait['renvoyees']} pièces sont reparties à la DGI.";
        }

        if ($fait['suspendues'] > 0) {
            $phrases[] = ($fait['suspendues'] === 1 ? 'La pièce est' : "{$fait['suspendues']} pièces sont")
                . " à renvoyer depuis l'écran des ventes : cette entreprise certifie ses "
                . 'pièces à la main.';
        }

        return $phrases === []
            ? "Aucune pièce n'attendait ce nom."
            : implode(' ', $phrases);
    }


    public function corrigerMaintenant(
        FneRejet $rejet,
        DiagnosticFneService $diagnosticService,
        CorrectionFneService $correcteur
    ): RedirectResponse {
        $this->verifierAppartenance($rejet);

        if ($rejet->estReseau()) {
            return back()->with('erreur', "Cette pièce n'a pas été refusée par la DGI : la plateforme "
                . "n'a pas répondu. Il n'y a rien à corriger — la pièce est à renvoyer.");
        }

        // Ranger ce que le scraper a pu déposer depuis le refus.
        \Illuminate\Support\Facades\Artisan::call('portail-fne:importer');

        // Rapprocher du dernier relevé.
        $diagnostic = $diagnosticService->diagnostiquer($rejet);

        if ($diagnostic['releve'] === null) {
            return back()->with('avertissement', "Le relevé du portail n'est pas encore arrivé — "
                . 'la vérification est en cours. Réessayez dans quelques instants.');
        }

        $rejet->update([
            'diagnostic' => $diagnostic,
            'statut' => $rejet->statut === FneRejet::STATUT_RESOLU
                ? FneRejet::STATUT_RESOLU
                : FneRejet::STATUT_DIAGNOSTIQUE,
        ]);

        // Appliquer, si le rapprochement l'autorise. Synchrone : le bouton du
        // pop-up doit certifier dans la foulée, pas laisser un job en file.
        $fait = $correcteur->corriger($rejet, synchrone: true);

        if ($fait === null) {
            // Le portail déclare plusieurs points : l'automatisme s'abstient,
            // et il a raison — il ne choisit pas le point de vente à la place
            // de qui a établi la pièce. Mais s'abstenir n'est pas se taire :
            // plutôt qu'un « aucune correction applicable » qui laisse devant
            // rien, le pop-up **propose les noms** et l'humain tranche.
            $auChoix = $correcteur->nomsAuChoix($rejet);

            if ($auChoix !== []) {
                return back()
                    ->with('avertissement', sprintf(
                        'Le portail déclare %d points de facturation : Selflow ne choisit pas à '
                        . 'votre place. Lequel correspond à cette pièce ?',
                        count($auChoix)
                    ))
                    ->with('avertissement_action', $this->boutonsDeChoix($rejet, $auChoix));
            }

            // Le rapprochement conclut « concordant » alors que la DGI a refusé
            // ce champ-là : les deux ne peuvent pas avoir raison en même temps,
            // et c'est le relevé qui est en retard. Un point renommé au portail
            // il y a dix minutes n'a encore été vu par personne — le bouton
            // range ce que le scraper a déposé, il ne va pas le chercher.
            //
            // Constaté le 31/08/2026 : le nom changé au portail à 20 h 44, et
            // l'écran affirmant « le portail confirme les valeurs envoyées »
            // sur un relevé antérieur au changement.
            if ($this->leReleveEstEnRetard($rejet)
                && ScraperPortailFneService::lancerPourLogin($rejet->login)) {
                return back()->with('avertissement', "La plateforme refuse ce point de vente, alors "
                    . 'que le dernier relevé le déclarait conforme : ce relevé est antérieur à '
                    . 'votre modification au portail. Une relève vient d\'être lancée — '
                    . 'relancez la correction dans une minute.');
            }

            return back()->with('avertissement', 'Rapprochement effectué, mais aucune correction '
                . 'automatique applicable ici. ' . $diagnostic['conclusion']);
        }

        return back()->with('succes', $this->phraseDeCorrection($fait));
    }

    /**
     * Le relevé dit-il le contraire de ce que la DGI vient de faire ?
     *
     * La plateforme a refusé un champ que le rapprochement déclare concordant.
     * L'un des deux se trompe, et ce n'est pas la plateforme : **son message
     * fait foi**. Il ne reste donc qu'une explication — le relevé décrit le
     * portail d'avant.
     */
    private function leReleveEstEnRetard(FneRejet $rejet): bool
    {
        $refuses = $rejet->nomsDesChamps();

        foreach ($rejet->diagnostic['champs'] ?? [] as $champ) {
            if (($champ['verdict'] ?? null) === 'concordant'
                && in_array($champ['champ'] ?? '', $refuses, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le nom que l'utilisateur a désigné dans le pop-up : on l'applique.
     *
     * Le rang, et non le nom : ce qui revient du navigateur ne fait que
     * **désigner** une valeur que le rapprochement a écrite, et ne peut pas en
     * introduire une autre. Le service revérifie de son côté.
     */
    public function corrigerAvec(
        FneRejet $rejet,
        int $rang,
        CorrectionFneService $correcteur
    ): RedirectResponse {
        $this->verifierAppartenance($rejet);

        $choisi = $correcteur->nomAuRang($rejet, $rang);

        if ($choisi === null) {
            // Le rapprochement a changé depuis que le pop-up s'est affiché —
            // un relevé est arrivé, ou le rejet a été rouvert. Refaire le geste
            // sur une liste périmée renommerait d'après un constat qui n'a plus
            // cours.
            return back()->with('avertissement', "Ce choix ne correspond plus à ce que le portail "
                . 'déclare : le rapprochement a été refait entre-temps. Relancez la correction '
                . "pour voir la liste à jour.");
        }

        $fait = $correcteur->corriger($rejet, synchrone: true, choisi: $choisi);

        if ($fait === null) {
            return back()->with('avertissement', sprintf(
                "« %s » n'a pas pu être appliqué : le point de vente de la pièce porte peut-être "
                . 'déjà ce nom, ou la pièce a été certifiée entre-temps.',
                $choisi
            ));
        }

        // Basculer directement la session sur le point de vente choisi
        if (!empty($fait['point_de_vente_id'])) {
            session([
                'point_de_vente_actif_id'  => $fait['point_de_vente_id'],
                'point_de_vente_actif_nom' => $fait['nouveau'] ?? $choisi,
            ]);
        }

        return back()->with('succes', $this->phraseDeCorrection($fait));
    }

    /**
     * Liste sélective des points de vente déclarés au portail, chacun avec un bouton Activer.
     *
     * @param  array<int, string>  $auChoix
     * @return array<int, array{url: string, label: string, nom: string, action_label: string, method: string}>
     */
    private function boutonsDeChoix(FneRejet $rejet, array $auChoix): array
    {
        $boutons = [];

        foreach ($auChoix as $rang => $nom) {
            $boutons[] = [
                'url'          => route('admin.fne.rejets.corriger_avec', ['rejet' => $rejet, 'rang' => $rang]),
                'label'        => $nom,
                'nom'          => $nom,
                'action_label' => 'Activer',
                'method'       => 'post',
            ];
        }

        return $boutons;
    }

    /**
     * Vérifie l'état de la relève du portail et renvoie les points de vente disponibles
     * ou la résolution automatique dès que le scraper a déposé les données.
     */
    public function statutScraping(
        FneRejet $rejet,
        DiagnosticFneService $diagnosticService,
        CorrectionFneService $correcteur,
        PointsDeVentePortailService $pdvPortail
    ): \Illuminate\Http\JsonResponse {
        $this->verifierAppartenance($rejet);

        // Importer ce que le scraper a pu déposer
        \Illuminate\Support\Facades\Artisan::call('portail-fne:importer');

        $entreprise = $rejet->entreprise ?? Auth::user()->entreprise;
        if ($entreprise) {
            $pdvPortail->importer($entreprise);
        }

        // Diagnostiquer le rejet et sauvegarder en base
        $diagnostic = $diagnosticService->diagnostiquer($rejet);
        $rejet->update([
            'diagnostic' => $diagnostic,
            'statut'     => $rejet->statut === FneRejet::STATUT_RESOLU
                ? FneRejet::STATUT_RESOLU
                : FneRejet::STATUT_DIAGNOSTIQUE,
        ]);
        $rejet->refresh();

        if (($diagnostic['releve'] ?? null) === null) {
            return response()->json([
                'pret' => false,
                'message' => 'Relève en cours sur le portail FNE...',
            ]);
        }

        // 1 seul point : correction applicable directement
        $correctionDirecte = $correcteur->correctionApplicable($rejet);
        if ($correctionDirecte !== null) {
            $fait = $correcteur->corriger($rejet, synchrone: true);
            if ($fait !== null) {
                return response()->json([
                    'pret' => true,
                    'resolu' => true,
                    'message' => ($fait['mode'] ?? 'bascule') === 'cree'
                        ? sprintf('Le point de vente « %s » a été créé dans Selflow d\'après la DGI, et la pièce a été normalisée avec succès.', $fait['nouveau'])
                        : sprintf('La pièce a été rattachée au point de vente « %s » et normalisée avec succès.', $fait['nouveau']),
                ]);
            }
        }

        // Plusieurs points : liste sélective des points de vente
        $auChoix = $correcteur->nomsAuChoix($rejet);
        if ($auChoix !== []) {
            return response()->json([
                'pret' => true,
                'resolu' => false,
                'choix' => $this->boutonsDeChoix($rejet, $auChoix),
                'message' => 'La DGI a refusé la pièce : le point de vente ne correspond pas. Sélectionnez et activez le point de vente correspondant sur votre espace FNE :',
            ]);
        }

        return response()->json([
            'pret' => true,
            'resolu' => false,
            'message' => $diagnostic['conclusion'] ?? 'Rapprochement terminé.',
        ]);
    }

    /**
     * Referme un rejet à la main.
     *
     * Un rejet se referme normalement tout seul quand la pièce finit par
     * passer. Celui-ci sert aux cas que la machine ne voit pas : une pièce
     * annulée, un refus qui n'appelait aucune correction.
     */
    public function resoudre(FneRejet $rejet): RedirectResponse
    {
        $this->verifierAppartenance($rejet);

        $rejet->update(['statut' => FneRejet::STATUT_RESOLU]);

        return back()->with('succes', 'Rejet classé.');
    }

    /**
     * Une entreprise ne voit et ne touche que ses propres rejets.
     */
    private function verifierAppartenance(FneRejet $rejet): void
    {
        abort_if($rejet->entreprise_id !== Auth::user()->entreprise->id, 404);
    }
}
