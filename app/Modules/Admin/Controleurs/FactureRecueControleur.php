<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ImportFacturesRecuesService;
use App\Modules\Admin\Services\QrCodeFneService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            ->with(['lignes', 'pointDeVente'])
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
            // Pour le sélecteur de site. Chargés une fois plutôt qu'à chaque
            // ligne : vingt factures rouvraient vingt fois la même table.
            'pointsDeVente'  => PointDeVente::where('entreprise_id', $entreprise->id)
                ->orderBy('nom')->get(),
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
            // Le site vient de l'achat : c'est la même pièce, et la ranger
            // ailleurs ferait porter la charge par un établissement qui ne l'a
            // pas supportée. Il écrase une affectation antérieure — le
            // rattachement est une information plus sûre qu'un choix fait à
            // l'aveugle avant de savoir de quel achat il s'agissait.
            'point_de_vente_id'    => $achat->point_de_vente_id,
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
     * Affiche la facture reçue comme un document, et non comme une ligne.
     *
     * ## Pourquoi
     *
     * Les boutons « Voir » et « Télécharger » ne menaient qu'à la page de
     * vérification de la DGI — utile pour authentifier, inutile pour lire :
     * c'est une application cliente, hors de Selflow, et rien n'en reste au
     * dossier. Demandé par le propriétaire du projet le 07/09/2026 : voir la
     * pièce comme on voit les autres.
     *
     * ## Ce que le document est, et ce qu'il n'est pas
     *
     * Une **copie reconstituée d'après le relevé**, pas la pièce originale. Le
     * fournisseur l'a établie, la DGI l'a certifiée ; Selflow n'a que ce que le
     * portail lui a communiqué. La vue le dit en tête, et le code QR y encode
     * l'adresse de vérification de la DGI — jamais une signature de Selflow.
     *
     * D'où une vue rangée hors de `Vues/factures/`, dossier gelé qui porte les
     * documents que Selflow émet : sous ce gabarit, la copie se donnerait pour
     * l'original.
     */
    public function imprimer(PortailFneFactureRecue $facture): View
    {
        $this->siennes($facture);

        $facture->load(['lignes', 'pointDeVente']);

        return view('admin::fne.facture-recue-impression', [
            'facture'    => $facture,
            'entreprise' => Auth::user()->entreprise,
            // Le service est gelé : on l'appelle, on ne le touche pas. Il
            // encode un jeton en image, ce qui est exactement l'usage prévu.
            'qr'         => QrCodeFneService::imageDeVerification($facture->urlDeVerification(), 110),
        ]);
    }

    /**
     * Sert le document que la DGI détient pour cette pièce.
     *
     * ## Pourquoi il ne suffisait pas de mettre un lien
     *
     * Demandé par le propriétaire du projet le 08/09/2026 : *« que cette facture
     * s'affiche aussi directement sans passer par le bouton exporter »*. Le
     * bouton en question est celui du **portail** : lire une facture reçue
     * voulait dire s'y connecter et l'exporter à la main.
     *
     * Le PDF ne peut pas être un lien vers la DGI : la plateforme ne le sert
     * qu'à une session authentifiée, et personne n'ouvrira une session par
     * facture. C'est donc le scraper qui le rapporte, une fois, dans la session
     * qu'il ouvre déjà — et cette action sert le fichier local.
     *
     * ## Ce qu'elle protège
     *
     * Le dossier d'import est hors de `public/` : ces pièces portent des données
     * fiscales nominatives, et une URL devinée ne doit pas les rendre. Le
     * fichier passe donc par ici, après `siennes()` — une facture d'une autre
     * entreprise ne se lit pas, pas même en connaissant son identifiant.
     *
     * `inline` et non `attachment` : la demande est de **voir**, pas de
     * télécharger. Le navigateur affiche, et l'utilisateur enregistre s'il veut.
     */
    public function pdf(PortailFneFactureRecue $facture): BinaryFileResponse|RedirectResponse
    {
        $this->siennes($facture);

        $chemin = $facture->cheminDuPdf();

        // Le scraper n'a rien rapporté pour cette pièce — les relevés antérieurs
        // au 08/09/2026 sont dans ce cas. On renvoie vers la reconstruction
        // plutôt que de rendre 404 : l'utilisateur veut lire la facture, et
        // Selflow sait la lui montrer, même si ce n'est pas le document original.
        if ($chemin === null) {
            return redirect()
                ->route('admin.achats.factures_recues.imprimer', $facture)
                ->with('info', "Le document de la DGI n'a pas encore été rapporté pour cette "
                    . 'pièce ; voici ce que Selflow a reconstitué du relevé.');
        }

        return response()->file($chemin, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $facture->reference . '.pdf"',
        ]);
    }

    /**
     * Range une facture reçue sous un point de vente.
     *
     * ## Pourquoi ce geste existe
     *
     * Demandé par le propriétaire du projet le 07/09/2026 : une facture d'achat
     * appartient à un site, comme toute autre pièce. Le portail ne le dit pas —
     * son `clientPointOfSale` décrit l'émetteur, le champ voisin
     * `clientEstablishment` valant « CIAN SIEGE » sur le relevé du 07/09, le nom
     * du fournisseur et non le nôtre. C'est donc une décision, et elle se prend
     * ici.
     *
     * L'import l'a déjà prise quand l'entreprise n'a qu'un site, et
     * `rattacher()` la prend quand la facture rejoint un achat. Cette action
     * sert le reste : plusieurs sites, et pas encore d'achat en face.
     */
    public function affecter(PortailFneFactureRecue $facture): RedirectResponse
    {
        $this->siennes($facture);

        // Le site doit appartenir à l'entreprise de l'utilisateur. Sans cette
        // vérification, un identifiant forgé rangerait la charge d'une
        // entreprise sous l'établissement d'une autre.
        $site = PointDeVente::where('entreprise_id', Auth::user()->entreprise_id)
            ->find(request('point_de_vente_id'));

        if (!$site) {
            return back()->with('erreur', "Ce point de vente n'existe pas dans votre entreprise.");
        }

        $facture->update(['point_de_vente_id' => $site->id]);

        return back()->with('succes', "Facture {$facture->reference} affectée à {$site->nom}.");
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
     * Remet dans la liste une facture qu'on avait écartée.
     *
     * ## Pourquoi ce geste manquait
     *
     * Écarter tenait à un clic sur une icône, sans confirmation, et **rien ne
     * permettait de revenir** : « Détacher » ne s'affiche que pour une pièce
     * rattachée à un achat, et l'écran ne proposait rien d'autre. Le 07/09/2026,
     * la seule facture réelle du dossier a disparu de tous les écrans de cette
     * façon, et il a fallu la base pour comprendre pourquoi.
     *
     * Une porte à sens unique sur une pièce fiscale que la DGI détient n'est pas
     * acceptable : la charge cesse d'être visible sans cesser d'exister.
     */
    public function reintegrer(PortailFneFactureRecue $facture): RedirectResponse
    {
        $this->siennes($facture);

        // Le statut d'origine, et non « à rapprocher » d'office : sans NCC
        // d'émetteur, aucun fournisseur ne peut être retrouvé, et la pièce est
        // orpheline — la présenter comme rapprochable serait mentir.
        $facture->update([
            'statut_rapprochement' => $facture->emetteur_ncc
                ? PortailFneFactureRecue::A_RAPPROCHER
                : PortailFneFactureRecue::ORPHELINE,
            'note_rapprochement'   => null,
        ]);

        return back()->with('succes', "Facture {$facture->reference} remise dans la liste.");
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
