<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ImportFacturesRecuesService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * L'écran des factures que la DGI dit avoir reçues pour l'entreprise.
 *
 * ## Ce qu'il montre
 *
 * Le relevé du portail écrivait déjà, chaque heure, les pièces qu'un fournisseur
 * a certifiées au NCC de l'entreprise. **Personne ne pouvait les lire.** Une
 * facture certifiée par un fournisseur le mardi, rangée en base à 16 h, et rien
 * à l'écran : elle n'existait que dans une table.
 *
 * ## Ce qu'il ne fait pas, et c'est délibéré
 *
 * **Il ne crée aucun achat.** `rattacher()` pose un lien vers un achat qui
 * existe déjà ; il ne fabrique ni ligne d'achat, ni écriture comptable, ni
 * fournisseur. La règle d'or du projet vaut ici : le relevé apporte un constat,
 * la décision reste à l'utilisateur.
 *
 * **Il ne touche à rien de gelé.** `achats.numero_fne`, `achats.fne_*` veulent
 * dire « Selflow a émis cette pièce et la DGI l'a certifiée ». Une facture reçue
 * a été certifiée par le fournisseur : le lien vit dans
 * `portail_fne_factures_recues.achat_id`, jamais dans les colonnes d'émission.
 */
class FactureRecueControleur
{
    /** Les filtres de l'écran, dans l'ordre où ils intéressent. */
    private const STATUTS = [
        PortailFneFactureRecue::A_RAPPROCHER => 'À rapprocher',
        PortailFneFactureRecue::RAPPROCHEE   => 'Rapprochées',
        PortailFneFactureRecue::ORPHELINE    => 'Fournisseur inconnu',
        PortailFneFactureRecue::ECARTEE      => 'Écartées',
    ];

    public function index(): View
    {
        $entreprise = Auth::user()->entreprise;

        // Une valeur inventée dans l'URL ne filtre rien plutôt que de rendre une
        // liste vide : un écran vide se lit « aucune facture reçue », ce qui est
        // exactement le contraire de ce qu'il faut comprendre.
        $statutActif = array_key_exists(request('statut'), self::STATUTS)
            ? request('statut')
            : null;

        $pourEntreprise = fn () => PortailFneFactureRecue::where('entreprise_id', $entreprise->id);

        $factures = $pourEntreprise()
            ->when($statutActif, fn ($q) => $q->where('statut_rapprochement', $statutActif))
            ->with('lignes')
            // Les plus récentes d'abord : c'est ce qui vient d'arriver qu'on
            // vient voir, pas ce qui traîne depuis six mois.
            ->orderByDesc('date_facture')
            ->orderByDesc('id')
            ->paginate(20)
            // Sans quoi la page 2 revient sur la liste entière, et l'on croit
            // que le filtre a lâché.
            ->withQueryString();

        // Un seul passage plutôt qu'une requête par statut.
        $parStatut = $pourEntreprise()
            ->selectRaw('statut_rapprochement, COUNT(*) as total')
            ->groupBy('statut_rapprochement')
            ->pluck('total', 'statut_rapprochement');

        // Le rapprochement est calculé à l'affichage et non stocké : un
        // fournisseur créé ce matin doit être vu ce matin, sans attendre le
        // relevé de la nuit.
        $propositions = [];
        foreach ($factures as $facture) {
            $propositions[$facture->id] = $facture->rapprochementPropose();
        }

        // Deux dates, comme sur l'écran des rejets : le passage dit que le
        // scraper fonctionne, le contenu dit ce que le portail détient. Un
        // relevé identique au précédent n'écrit plus de ligne, donc la seconde
        // peut être bien plus ancienne que la première sans que rien n'aille mal.
        $dernierPassage = PortailFneImport::where('entreprise_id', $entreprise->id)
            ->where('type', ImportFacturesRecuesService::TYPE)
            ->where('statut', PortailFneImport::STATUT_IMPORTE)
            ->max('dernier_releve_le');

        // Les onglets FNE pointent vers des ecrans gardes par `modules:comptabilite`.
        // Une entreprise qui achete sans tenir sa comptabilite dans Selflow verrait
        // sinon six liens dont cinq lui repondraient 403.
        $aFne = in_array('comptabilite', (array) ($entreprise->modules_actifs ?? []), true);

        return view('admin::fne.factures-recues', [
            'entreprise'     => $entreprise,
            'aFne'           => $aFne,
            'factures'       => $factures,
            'propositions'   => $propositions,
            'statutActif'    => $statutActif,
            'filtres'        => collect(self::STATUTS)
                ->map(fn (string $libelle, string $cle) => [
                    'libelle' => $libelle,
                    'total'   => $parStatut[$cle] ?? 0,
                ])
                ->all(),
            'total'          => $parStatut->sum(),
            'aRapprocher'    => $parStatut[PortailFneFactureRecue::A_RAPPROCHER] ?? 0,
            'montantTotal'   => $pourEntreprise()->sum('montant_ttc'),
            'dernierPassage' => $dernierPassage ? CarbonImmutable::parse($dernierPassage) : null,
        ]);
    }

    /**
     * Rattache une facture du portail à un achat déjà saisi dans Selflow.
     *
     * L'achat doit exister : ce geste dit « cette pièce de la DGI est celle-là
     * chez moi », il ne la crée pas. Créer l'achat produirait des écritures
     * comptables sur la foi d'un fichier arrivé dans un dossier, et
     * doublonnerait très probablement une saisie déjà faite.
     */
    public function rattacher(PortailFneFactureRecue $facture): RedirectResponse
    {
        $this->siennes($facture);

        $propose = $facture->rapprochementPropose();
        $achat   = $propose['achat'];

        if (!$achat instanceof Achat) {
            return back()->with('erreur',
                "Aucun achat de Selflow ne correspond à cette facture. Saisissez-le d'abord, "
                . "puis revenez le rattacher — le relevé ne crée pas d'achat."
            );
        }

        $facture->update([
            'achat_id'             => $achat->id,
            'statut_rapprochement' => PortailFneFactureRecue::RAPPROCHEE,
            'note_rapprochement'   => $propose['ecart_ttc']
                ? sprintf('Rattachée malgré un écart de %s F sur le TTC.', number_format((float) $propose['ecart_ttc'], 2, ',', ' '))
                : null,
        ]);

        return back()->with('succes', "Facture {$facture->reference} rattachée à l'achat {$achat->numero_facture}.");
    }

    /** Détache, sans rien perdre : la pièce du portail retourne à rapprocher. */
    public function detacher(PortailFneFactureRecue $facture): RedirectResponse
    {
        $this->siennes($facture);

        $facture->update([
            'achat_id'             => null,
            'note_rapprochement'   => null,
            'statut_rapprochement' => $facture->emetteur_ncc
                ? PortailFneFactureRecue::A_RAPPROCHER
                : PortailFneFactureRecue::ORPHELINE,
        ]);

        return back()->with('succes', "Facture {$facture->reference} détachée.");
    }

    /**
     * Écarte une facture qu'on ne veut pas rapprocher.
     *
     * Elle n'est pas supprimée : le portail la redéposera au prochain relevé, et
     * une pièce écartée qui revient chaque jour dans « à rapprocher » finirait
     * par masquer celles qui comptent.
     */
    public function ecarter(PortailFneFactureRecue $facture): RedirectResponse
    {
        $this->siennes($facture);

        $facture->update([
            'statut_rapprochement' => PortailFneFactureRecue::ECARTEE,
            'note_rapprochement'   => trim((string) request('motif')) ?: null,
        ]);

        return back()->with('succes', "Facture {$facture->reference} écartée.");
    }

    /**
     * La facture appartient-elle à l'entreprise de l'utilisateur ?
     *
     * Même règle que partout ailleurs dans l'application : une pièce référencée
     * par identifiant doit appartenir à l'entreprise connectée. Une facture
     * fiscale lue par une autre entreprise ne se répare pas.
     */
    private function siennes(PortailFneFactureRecue $facture): void
    {
        abort_unless($facture->entreprise_id === Auth::user()->entreprise_id, 404);
    }
}
