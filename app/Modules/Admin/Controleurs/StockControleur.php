<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\BonLivraisonDetail;
use App\Modules\Admin\Modeles\BonLivraison;
use App\Modules\Admin\Services\NumerotationService;
use App\Modules\Admin\Services\TransportLivraisonService;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Vente;
/*
 * `VenteDetail` etait employe dans `validerLivraison()` sans jamais etre
 * importe : PHP le cherchait dans l'espace de noms du controleur, ne le
 * trouvait pas, et toute validation d'expedition tombait en 500 (Internal
 * Server Error -- erreur interne du serveur). L'ecran s'ouvrait, le
 * formulaire se remplissait, et c'est le bouton qui cassait.
 *
 * `AchatDetail` portait exactement le meme defaut dans la validation d'une
 * reception, et personne ne l'avait signale -- la meme moitie reparee qui
 * cachait l'autre qu'au lot 20. Les deux se corrigent ensemble.
 */
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\TransfertStock;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Produit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Regles\Quantite;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Services\StockService;
use Illuminate\Support\Facades\DB;

class StockControleur
{
    use \App\Modules\Admin\Traits\JournaliseActions;

    public function index(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        
        // Liste de tous les points de vente
        $pointsDeVente = $entreprise->pointsDeVente()->orderBy('nom')->get();

        // Récupérer le point de vente à filtrer (session, requête ou par défaut 'tout')
        $pointDeVenteId = $request->input('point_de_vente_id') ?? $request->input('site_id');
        if ($pointDeVenteId === null) {
            $pointDeVenteId = session('point_de_vente_actif_id') 
                ?? Auth::user()->point_de_vente_id 
                ?? 'tout';
        }

        // L'ecran de stock listait tout le catalogue : un cabinet, qui ne vend
        // que des prestations, y voyait chacune de ses lignes en « Rupture » et
        // son compteur d'alertes egal a son catalogue. Un service n'a pas de
        // stock — il n'a rien a faire ici.
        // Un article archivé qui porte encore de la marchandise n'a pas quitté
        // le magasin : le taire ferait tomber la valeur de l'inventaire sans
        // que rien ne l'explique, alors que la quantité reste aux écritures.
        $query = Produit::where('produits.entreprise_id', $entreprise->id)
            ->stockables()
            ->visiblesEnStock()
            // Sur un site, ses articles seulement (20/10/2026).
            ->visiblesAuSite($pointDeVenteId)
            // La catégorie se lit sur chaque ligne et au filtre : sans elle,
            // une requête par article.
            ->with(['stocks', 'category']);

        $produits = $query
            ->leftJoin('categories', 'produits.categorie_id', '=', 'categories.id')
            ->select('produits.*')
            ->orderBy('categories.nom')
            ->orderBy('produits.nom')
            ->get();

        // Surcharger stock_actuel et stock_minimum pour la compatibilité avec la vue
        foreach ($produits as $p) {
            if ($pointDeVenteId === 'tout') {
                $totalStock = $p->stocks->sum('quantite_disponible');
                $totalMin = $p->stocks->sum('stock_minimum');
                $p->setAttribute('stock_actuel', $totalStock);
                $p->setAttribute('stock_minimum', $totalMin);
            } else {
                $stockObj = $p->stocks->where('point_de_vente_id', $pointDeVenteId)->first();
                $p->setAttribute('stock_actuel', $stockObj ? $stockObj->quantite_disponible : 0);
                $p->setAttribute('stock_minimum', $stockObj ? $stockObj->stock_minimum : 5);
            }
        }

        $categories = $produits->pluck('categorie')->unique()->sort()->values();

        // « Commandé », « à réceptionner » et « prévisionnel », lus par la vue
        // sur chaque ligne : deux requêtes pour tout le tableau, au lieu de
        // quatre par article. Même site que les accesseurs qu'ils remplacent.
        Produit::prechargerEngagements($produits, Produit::getActivePdvId());

        // Compteurs interactifs Odoo filtrés par point de vente actif
        /*
         * Ce qui reste à recevoir, et à livrer.
         *
         * **Une ligne de service ne se réceptionne pas.** Sa
         * `quantite_receptionnee` reste à zéro pour toujours, puisque rien
         * n'entre en stock : une prestation de conseil figurait donc
         * indéfiniment dans « à réceptionner », et le compteur de l'écran de
         * stock annonçait un travail qui n'existait pas. Pire, la fiche
         * n'offrait aucun champ à saisir — l'article n'étant pas stockable —
         * et le formulaire répondait « le champ reception est obligatoire ».
         *
         * Le filtre porte donc sur l'article autant que sur la quantité.
         */
        $qReceptions = Achat::whereIn('etape', ['Bon de commande', 'Facture'])
            ->where(fn ($a) => $a->whereNull('statut')->orWhere('statut', '!=', 'Annulée'))
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->whereHas('details', function($q) {
                $q->whereColumn('quantite', '>', 'quantite_receptionnee')
                  ->whereHas('produit', fn($qp) => $qp->stockables());
            });

        $qLivraisons = Vente::whereIn('etape', ['Bon de commande', 'Facture'])
            ->where(fn ($a) => $a->whereNull('statut')->orWhere('statut', '!=', 'Annulée'))
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->whereHas('details', function($q) {
                $q->whereColumn('quantite', '>', 'quantite_livree')
                  ->whereHas('produit', fn($qp) => $qp->stockables());
            });

        $qTransferts = TransfertStock::enAttente()
            ->whereHas('produit', fn($q) => $q->where('entreprise_id', $entreprise->id));

        if ($pointDeVenteId !== 'tout') {
            $qReceptions->where('point_de_vente_id', $pointDeVenteId);
            $qLivraisons->where('point_de_vente_id', $pointDeVenteId);
            $qTransferts->where(function($q) use ($pointDeVenteId) {
                $q->where('point_de_vente_source_id', $pointDeVenteId)
                  ->orWhere('point_de_vente_destination_id', $pointDeVenteId);
            });
        }

        $receptionsATraiter = $qReceptions->count();
        $livraisonsATraiter = $qLivraisons->count();
        $transfertsATraiter = $qTransferts->count();

        return view('admin::stock.index', compact(
            'produits', 
            'categories', 
            'pointsDeVente', 
            'pointDeVenteId',
            'receptionsATraiter',
            'livraisonsATraiter',
            'transfertsATraiter'
        ));
    }

    public function mouvements(Request $request): View
    {
        $entreprise  = Auth::user()->entreprise;
        $pointDeVenteId = Auth::user()->estCaissier()
            ? Auth::user()->point_de_vente_id
            : session('point_de_vente_actif_id');

        $section = $request->input('section', 'tous');

        $query = MouvementStock::with([
            'produit', 'pointDeVente', 'pointDeVenteContrepartie', 'utilisateur', 'fournisseur', 'client'
        ]);

        if ($pointDeVenteId) {
            $query->where('point_de_vente_id', $pointDeVenteId);
        } else {
            $query->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id));
        }

        // Filtrer selon la section. Les motifs sont des constantes de
        // MouvementStock : ces comparaisons portaient la casse d'origine
        // (« Reception ») quand l'écran comparait en minuscules, et rien ne
        // signalait qu'un contrôleur écrivant une autre forme rendait la
        // section vide.
        $motifs = [
            'achats'      => MouvementStock::RECEPTION,
            'ventes'      => MouvementStock::LIVRAISON,
            'transferts'  => MouvementStock::TRANSFERT,
            'rebuts'      => MouvementStock::REBUT,
            'entrees'     => MouvementStock::ENTREE_DIVERSE,
        ];

        if (isset($motifs[$section])) {
            $query->where('sous_type', $motifs[$section]);
        }

        $mouvements = $query->latest()->paginate(30);

        return view('admin::stock.mouvements', compact('mouvements', 'section'));
    }

    /**
     * L'écran de comptage physique.
     *
     * Le stock théorique est ce que l'application croit ; l'inventaire est ce
     * que l'on trouve dans le magasin. L'écart existe toujours — casse non
     * déclarée, erreur de saisie, vol — et il n'avait jusqu'ici aucun moyen
     * d'entrer dans Selflow : la seule correction possible était de passer un
     * rebut, ce qui ne sait pas dire « il y en a plus que prévu ».
     */
    public function inventaire(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;

        // Un comptage se fait dans un magasin, pas dans une entreprise : sans
        // site désigné, il n'y a rien à compter.
        $pointDeVenteId = $this->siteDeTravail($request);

        $produits = collect();

        if ($pointDeVenteId) {
            // Un inventaire se compte sur ce qui est en rayon : l'article
            // archivé qui porte encore une quantité doit pouvoir être compté,
            // sans quoi l'écart resterait au stock sans moyen de le solder.
            $produits = Produit::where('entreprise_id', $entreprise->id)
                ->whereIn('type', Produit::TYPES_STOCKABLES)
                ->visiblesEnStock()
                ->visiblesAuSite($pointDeVenteId)
                ->with(['stocks' => fn ($q) => $q->where('point_de_vente_id', $pointDeVenteId)])
                ->orderBy('nom')
                ->get();
        }

        $pointsDeVente = $entreprise->pointsDeVente()->orderBy('nom')->get();

        return view('admin::stock.inventaire', compact('produits', 'pointDeVenteId', 'pointsDeVente'));
    }

    /**
     * Exporter l'inventaire du site, en PDF ou en Excel (26/10/2026).
     *
     * La même liste que l'écran de comptage : les articles qui se comptent,
     * avec leur stock théorique, leur CUMP (Coût Unitaire Moyen Pondéré) et
     * leur valeur, et une colonne « Compté » vide pour le comptage sur papier.
     */
    public function exporterInventaire(Request $request, string $format, \App\Modules\Admin\Services\DocumentPdfService $pdf): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless(in_array($format, ['pdf', 'excel'], true), 404);

        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = $this->siteDeTravail($request);
        if (!$pointDeVenteId) {
            return redirect()->route('admin.stock.inventaire')->with('erreur', 'Choisissez d\'abord le site à exporter.');
        }

        $site = PointDeVente::find($pointDeVenteId);
        $lignes = Produit::where('entreprise_id', $entreprise->id)
            ->whereIn('type', Produit::TYPES_STOCKABLES)
            ->visiblesEnStock()
            ->visiblesAuSite($pointDeVenteId)
            ->with(['categorieRelation', 'stocks' => fn ($q) => $q->where('point_de_vente_id', $pointDeVenteId)])
            ->orderBy('nom')
            ->get()
            ->map(function (Produit $p) use ($pointDeVenteId) {
                $quantite = (float) $p->stockSur($pointDeVenteId);
                $cump = (float) ($p->stocks->first()?->cump ?? 0);

                return [
                    'reference' => (string) $p->reference,
                    'nom'       => (string) $p->nom,
                    'categorie' => (string) ($p->categorieRelation?->nom ?? ''),
                    'unite'     => (string) ($p->unite ?: 'Unité'),
                    'quantite'  => $quantite,
                    'cump'      => round($cump, 2),
                    'valeur'    => round($quantite * $cump, 2),
                ];
            });

        $nomFichier = 'inventaire-' . \Illuminate\Support\Str::slug($site->nom) . '-' . now()->format('Y-m-d');
        $donnees = [
            'entreprise' => $entreprise,
            'site'       => $site,
            'lignes'     => $lignes,
            'total'      => round($lignes->sum('valeur'), 2),
            'date'       => now(),
        ];

        if ($format === 'pdf') {
            return response($pdf->inventaire($donnees), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $nomFichier . '.pdf"',
            ]);
        }

        return $this->inventaireEnExcel($donnees, $nomFichier . '.xlsx');
    }

    /**
     * Le classeur de l'inventaire (26/10/2026, mis en forme le 27/10/2026) :
     * un bandeau de titre, quatre chiffres clés, le tableau (valeur, total et
     * écart en formules, colonne « Compté » à remplir), et le filigrane SELFLOW — en fond de feuille à l'écran, en en-tête à l'impression.
     */
    private function inventaireEnExcel(array $donnees, string $nomFichier): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $bleu = '002B5C';
        $gris = 'CBD5E1';
        $Fill = \PhpOffice\PhpSpreadsheet\Style\Fill::class;
        $Border = \PhpOffice\PhpSpreadsheet\Style\Border::class;
        $Align = \PhpOffice\PhpSpreadsheet\Style\Alignment::class;

        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $classeur->getProperties()->setCreator('Selflow')->setTitle('Inventaire de stock — ' . $donnees['site']->nom)
            ->setCompany((string) $donnees['entreprise']->nom);
        $classeur->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Inventaire');
        $feuille->setShowGridlines(false);

        // ── Bandeau ──
        $feuille->mergeCells('A1:I1');
        $feuille->setCellValue('A1', 'INVENTAIRE DE STOCK');
        $feuille->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => $Fill::FILL_SOLID, 'startColor' => ['rgb' => $bleu]],
            'alignment' => ['vertical' => $Align::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $feuille->getRowDimension(1)->setRowHeight(32);

        $feuille->mergeCells('A2:I2');
        $feuille->setCellValue('A2', $donnees['entreprise']->nom . '   ·   Point de vente : ' . $donnees['site']->nom
            . '   ·   Édité le ' . $donnees['date']->format('d/m/Y à H:i'));
        $feuille->getStyle('A2')->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '334155']],
            'fill' => ['fillType' => $Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'alignment' => ['vertical' => $Align::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $feuille->getRowDimension(2)->setRowHeight(22);

        // ── Le tableau ──
        $ligneEntete = 7;
        $premiere = $ligneEntete + 1;
        $entetes = ['Référence', 'Article', 'Catégorie', 'Unité', 'Stock théorique', 'CUMP (F)', 'Valeur (F)', 'Compté', 'Écart'];
        $feuille->fromArray($entetes, null, 'A' . $ligneEntete);
        $feuille->getStyle("A{$ligneEntete}:I{$ligneEntete}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => $Fill::FILL_SOLID, 'startColor' => ['rgb' => $bleu]],
            'alignment' => ['horizontal' => $Align::HORIZONTAL_CENTER, 'vertical' => $Align::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => $Border::BORDER_THIN, 'color' => ['rgb' => $bleu]]],
        ]);
        $feuille->getRowDimension($ligneEntete)->setRowHeight(24);

        $ligne = $premiere;
        foreach ($donnees['lignes'] as $l) {
            // Comparaison stricte : sans elle, un stock à zéro passait pour vide.
            $feuille->fromArray([$l['reference'], $l['nom'], $l['categorie'], $l['unite'], $l['quantite'], $l['cump']], null, 'A' . $ligne, true);
            $feuille->setCellValue('G' . $ligne, '=E' . $ligne . '*F' . $ligne);
            // L'écart se calcule dès que la quantité comptée est saisie.
            $feuille->setCellValue('I' . $ligne, '=IF(H' . $ligne . '="","",H' . $ligne . '-E' . $ligne . ')');
            if (($ligne - $premiere) % 2 === 1) {
                $feuille->getStyle("A{$ligne}:G{$ligne}")->getFill()->setFillType($Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
            }
            $ligne++;
        }
        $derniere = max($premiere, $ligne - 1);
        $total = $derniere + 1;
        if ($ligne === $premiere) {
            $feuille->mergeCells("A{$premiere}:I{$premiere}");
            $feuille->setCellValue('A' . $premiere, 'Aucun article qui se compte sur ce site.');
            $total = $premiere + 1;
        }

        $plage = "A{$premiere}:I{$derniere}";
        $feuille->getStyle($plage)->getBorders()->getAllBorders()->setBorderStyle($Border::BORDER_THIN)->getColor()->setRGB($gris);
        $feuille->getStyle($plage)->getAlignment()->setVertical($Align::VERTICAL_CENTER);
        $feuille->getStyle("A{$premiere}:A{$derniere}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('475569'));
        $feuille->getStyle("D{$premiere}:D{$derniere}")->getAlignment()->setHorizontal($Align::HORIZONTAL_CENTER);
        // La colonne à remplir se voit : fond crème.
        $feuille->getStyle("H{$premiere}:H{$derniere}")->getFill()->setFillType($Fill::FILL_SOLID)->getStartColor()->setRGB('FFFBEB');

        // Ligne des totaux.
        $feuille->setCellValue('A' . $total, 'TOTAL');
        $feuille->mergeCells("A{$total}:D{$total}");
        $feuille->setCellValue('E' . $total, "=SUM(E{$premiere}:E{$derniere})");
        $feuille->setCellValue('G' . $total, "=SUM(G{$premiere}:G{$derniere})");
        $feuille->getStyle("A{$total}:I{$total}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => $bleu]],
            'fill' => ['fillType' => $Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => ['top' => ['borderStyle' => $Border::BORDER_MEDIUM, 'color' => ['rgb' => $bleu]],
                          'bottom' => ['borderStyle' => $Border::BORDER_THIN, 'color' => ['rgb' => $gris]]],
        ]);
        $feuille->getRowDimension($total)->setRowHeight(22);

        // Formats des nombres.
        $feuille->getStyle("E{$premiere}:E{$total}")->getNumberFormat()->setFormatCode('#,##0.###');
        $feuille->getStyle("F{$premiere}:G{$total}")->getNumberFormat()->setFormatCode('#,##0" F"');
        $feuille->getStyle("H{$premiere}:I{$derniere}")->getNumberFormat()->setFormatCode('#,##0.###;[Red]-#,##0.###');

        // Un écart se lit en couleur : rouge s'il manque, vert s'il y a plus.
        $manque = new \PhpOffice\PhpSpreadsheet\Style\Conditional();
        $manque->setConditionType(\PhpOffice\PhpSpreadsheet\Style\Conditional::CONDITION_EXPRESSION)
            ->addCondition("AND(I{$premiere}<>\"\",I{$premiere}<0)");
        $manque->getStyle()->getFont()->setBold(true)->getColor()->setRGB('B91C1C');
        $surplus = new \PhpOffice\PhpSpreadsheet\Style\Conditional();
        $surplus->setConditionType(\PhpOffice\PhpSpreadsheet\Style\Conditional::CONDITION_EXPRESSION)
            ->addCondition("AND(I{$premiere}<>\"\",I{$premiere}>0)");
        $surplus->getStyle()->getFont()->setBold(true)->getColor()->setRGB('15803D');
        $feuille->getStyle("I{$premiere}:I{$derniere}")->setConditionalStyles([$manque, $surplus]);

        // ── Chiffres clés, sur la ligne 4, en formules ──
        $tuiles = [
            'A' => ['Articles', "=COUNTA(B{$premiere}:B{$derniere})", '0'],
            'C' => ['Quantité totale', "=E{$total}", '#,##0.###'],
            'E' => ['En rupture', "=COUNTIF(E{$premiere}:E{$derniere},\"<=0\")", '0'],
            'G' => ['Valeur du stock', "=G{$total}", '#,##0" F"'],
        ];
        foreach ($tuiles as $col => [$etiquette, $formule, $format]) {
            $suivante = chr(ord($col) + 1);
            $feuille->mergeCells("{$col}4:{$suivante}4");
            $feuille->mergeCells("{$col}5:{$suivante}5");
            $feuille->setCellValue($col . '4', mb_strtoupper($etiquette));
            $feuille->setCellValue($col . '5', $formule);
            $feuille->getStyle("{$col}4:{$suivante}5")->applyFromArray([
                'fill' => ['fillType' => $Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
                'borders' => ['outline' => ['borderStyle' => $Border::BORDER_THIN, 'color' => ['rgb' => $gris]],
                              'left' => ['borderStyle' => $Border::BORDER_THICK, 'color' => ['rgb' => $bleu]]],
                'alignment' => ['indent' => 1],
            ]);
            $feuille->getStyle($col . '4')->getFont()->setSize(8)->setBold(true)->getColor()->setRGB('64748B');
            $feuille->getStyle($col . '5')->getFont()->setSize(14)->setBold(true)->getColor()->setRGB($bleu);
            $feuille->getStyle($col . '5')->getNumberFormat()->setFormatCode($format);
            $feuille->getStyle("{$col}4:{$suivante}5")->getAlignment()->setHorizontal($Align::HORIZONTAL_LEFT);
        }
        $feuille->getRowDimension(5)->setRowHeight(24);

        // Largeurs, filtre, volets figés.
        foreach (['A' => 15, 'B' => 36, 'C' => 20, 'D' => 10, 'E' => 15, 'F' => 13, 'G' => 16, 'H' => 13, 'I' => 12] as $col => $largeur) {
            $feuille->getColumnDimension($col)->setWidth($largeur);
        }
        if ($ligne > $premiere) {
            $feuille->setAutoFilter("A{$ligneEntete}:I{$derniere}");
        }
        $feuille->freezePane('A' . $premiere);

        // ── Impression : paysage, sur une page de large, en-tête répété ──
        $mise = $feuille->getPageSetup();
        $mise->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd($ligneEntete, $ligneEntete);
        $feuille->getPageMargins()->setTop(0.9)->setBottom(0.7)->setLeft(0.4)->setRight(0.4);
        $piedNom = str_replace('&', '&&', (string) $donnees['entreprise']->nom);

        // ── Le filigrane, des deux côtés : à l'écran et à l'impression ──
        $png = \App\Modules\Admin\Services\FiligraneService::png();
        $entete = '';
        if ($png !== null) {
            // À l'écran : l'image de fond de la feuille.
            $feuille->setBackgroundImage($png);

            // À l'impression : l'image d'en-tête, centrée derrière le tableau.
            $fichier = tempnam(sys_get_temp_dir(), 'filigrane') . '.png';
            file_put_contents($fichier, \App\Modules\Admin\Services\FiligraneService::png(\App\Modules\Admin\Services\FiligraneService::MARQUE, '', 1600, 1100, 9));
            $dessin = new \PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooterDrawing();
            $dessin->setName('Filigrane')->setPath($fichier)->setHeight(430);
            $feuille->getHeaderFooter()->addImage($dessin, \PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooter::IMAGE_HEADER_CENTER);
            $entete = '&C&G';
            register_shutdown_function(fn () => @unlink($fichier));
        }
        $feuille->getHeaderFooter()
            ->setOddHeader($entete . '&R&8&K64748B' . $piedNom)
            ->setOddFooter('&L&8&K64748BInventaire — ' . str_replace('&', '&&', $donnees['site']->nom) . '&R&8&K64748BPage &P / &N');

        $feuille->setSelectedCell('H' . $premiere);

        return response()->streamDownload(function () use ($classeur) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))->save('php://output');
        }, $nomFichier, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Enregistrer un comptage physique.
     *
     * Seules les lignes réellement comptées sont retenues : un champ laissé
     * vide veut dire « pas compté », et non « zéro ». Confondre les deux
     * viderait le magasin de tout ce que l'inventaire n'a pas parcouru.
     *
     * Chaque écart devient un mouvement, dans le sens qu'il faut. Un comptage
     * conforme n'écrit rien.
     */
    public function enregistrerInventaire(Request $request): \Illuminate\Http\RedirectResponse
    {
        $donnees = $request->validate([
            'point_de_vente_id'  => ['required', 'integer', Appartenance::a('points_de_vente', 'id')],
            'comptages'          => ['required', 'array', 'min:1'],
            'comptages.*'        => Quantite::facultative(),
        ]);

        $entreprise = Auth::user()->entreprise;

        // `Appartenance` a deja ecarte le site d'une autre entreprise ; la
        // verification ci-dessous est la ceinture apres les bretelles.
        $site = PointDeVente::where('id', $donnees['point_de_vente_id'])
            ->where('entreprise_id', $entreprise->id)
            ->firstOrFail();

        // Les identifiants d'articles viennent du formulaire : ils sont
        // confrontes au catalogue de l'entreprise avant tout. Sans cela, il
        // suffirait de poster l'identifiant du produit du voisin pour ecrire
        // dans son stock.
        $comptages = array_filter(
            $donnees['comptages'],
            fn ($valeur) => $valeur !== null && $valeur !== ''
        );

        if ($comptages === []) {
            return back()->with('erreur', "Aucune quantité comptée : rien n'a été enregistré.");
        }

        $produits = Produit::where('entreprise_id', $entreprise->id)
            ->whereIn('id', array_keys($comptages))
            ->get()
            ->keyBy('id');

        $ecarts = 0;

        DB::transaction(function () use ($comptages, $produits, $site, &$ecarts) {
            foreach ($comptages as $produitId => $quantite) {
                $produit = $produits->get((int) $produitId);

                if (!$produit) {
                    continue;
                }

                $mouvement = StockService::inventorier(
                    $produit,
                    (int) $site->id,
                    round((float) $quantite, Stock::DECIMALES),
                    ['reference' => 'INV-' . now()->format('YmdHis')]
                );

                $ecarts += $mouvement ? 1 : 0;
            }
        });

        $this->journaliser('inventaire_physique', 'PointDeVente', $site->id);

        return back()->with('succes', $ecarts === 0
            ? 'Comptage enregistré : aucun écart, le stock théorique était juste.'
            : "Comptage enregistré : {$ecarts} écart(s) ajusté(s) sur « {$site->nom} ».");
    }

    /**
     * Le site sur lequel on travaille, ou `null` si l'utilisateur regarde
     * « tous les sites » — auquel cas aucune écriture n'est possible.
     */
    private function siteDeTravail(Request $request): ?int
    {
        $demande = $request->input('point_de_vente_id')
            ?? session('point_de_vente_actif_id')
            ?? Auth::user()->point_de_vente_id;

        if (!$demande || $demande === 'tout') {
            return null;
        }

        // Un identifiant venu de l'URL est confronte a l'entreprise avant
        // d'etre retenu : sans cela, `?point_de_vente_id=` afficherait le stock
        // du voisin.
        return PointDeVente::where('id', $demande)
            ->where('entreprise_id', Auth::user()->entreprise_id)
            ->value('id');
    }

    /**
     * L'écran d'entrée de stock : réapprovisionner sans achat saisi.
     *
     * Jusqu'ici, de la marchandise arrivée sans facture d'achat n'avait que
     * l'inventaire physique pour entrer : un « écart » qui n'en était pas un,
     * valorisé au coût moyen du moment et non à ce qu'elle a coûté. Ici, elle
     * entre avec sa raison et son coût réel, et le CUMP (Coût Unitaire Moyen
     * Pondéré) se recalcule comme pour une réception.
     */
    public function entree(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = $this->siteDeTravail($request);

        $produits = Produit::where('entreprise_id', $entreprise->id)
            ->whereIn('type', Produit::TYPES_STOCKABLES)
            ->visiblesEnStock()
            ->with(['stocks' => fn ($q) => $pointDeVenteId
                ? $q->where('point_de_vente_id', $pointDeVenteId)
                : $q->whereRaw('1 = 0')])
            ->orderBy('nom')
            ->get();

        $pointsDeVente = $entreprise->pointsDeVente()->orderBy('nom')->get();

        $dernieres = MouvementStock::with(['produit', 'pointDeVente', 'utilisateur'])
            ->where('sous_type', MouvementStock::ENTREE_DIVERSE)
            ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))
            ->latest('id')
            ->limit(20)
            ->get();

        return view('admin::stock.entree', compact('produits', 'pointDeVenteId', 'pointsDeVente', 'dernieres'));
    }

    /**
     * Enregistrer une entrée de stock : une ou plusieurs lignes, une raison.
     *
     * Le coût est exigé et strictement positif. Un coût oublié — zéro — ferait
     * chuter le coût moyen de tout le stock en place, et la marge de chaque
     * vente suivante serait fausse sans que rien ne le montre. Un don se porte
     * à sa valeur estimée.
     */
    public function enregistrerEntree(Request $request): \Illuminate\Http\RedirectResponse
    {
        $donnees = $request->validate([
            'point_de_vente_id'      => ['required', 'integer', Appartenance::a('points_de_vente', 'id')],
            'raison_entree'          => ['required', 'string', 'in:' . implode(',', array_keys(MouvementStock::RAISONS_ENTREE))],
            'commentaire'            => ['nullable', 'string', 'max:255', 'required_if:raison_entree,autre'],
            'lignes'                 => ['required', 'array', 'min:1'],
            'lignes.*.produit_id'    => ['required', 'integer', Appartenance::a('produits', 'id')],
            'lignes.*.quantite'      => Quantite::physique(),
            'lignes.*.cout_unitaire' => ['required', 'numeric', 'gt:0', 'max:999999999'],
        ], [
            'commentaire.required_if'        => 'Précisez la raison de l\'entrée quand vous choisissez « Autre ».',
            'lignes.required'                => 'Ajoutez au moins un article.',
            'lignes.*.produit_id.required'   => 'Choisissez l\'article de chaque ligne.',
            'lignes.*.cout_unitaire.required' => 'Indiquez le coût unitaire de chaque ligne.',
            'lignes.*.cout_unitaire.gt'      => 'Le coût unitaire doit être supérieur à zéro (pour un don, sa valeur estimée).',
        ]);

        $entreprise = Auth::user()->entreprise;

        $site = PointDeVente::where('id', $donnees['point_de_vente_id'])
            ->where('entreprise_id', $entreprise->id)
            ->firstOrFail();

        $produits = Produit::where('entreprise_id', $entreprise->id)
            ->whereIn('id', collect($donnees['lignes'])->pluck('produit_id'))
            ->get()
            ->keyBy('id');

        $reference = 'ENT-' . now()->format('YmdHis');
        $entrees = 0;

        DB::transaction(function () use ($donnees, $produits, $site, $reference, &$entrees) {
            foreach ($donnees['lignes'] as $ligne) {
                $produit = $produits->get((int) $ligne['produit_id']);

                // Une prestation n'a pas de stock : le service ne la
                // mouvemente pas, et l'annoncer entrée serait faux.
                if (!$produit || !$produit->estStockable()) {
                    continue;
                }

                $mouvement = StockService::entree(
                    $produit,
                    (int) $site->id,
                    round((float) $ligne['quantite'], Stock::DECIMALES),
                    MouvementStock::ENTREE_DIVERSE,
                    [
                        'reference'     => $reference,
                        'cout_unitaire' => (float) $ligne['cout_unitaire'],
                        'raison_entree' => $donnees['raison_entree'],
                        'commentaire'   => $donnees['commentaire'] ?? null,
                    ]
                );

                $entrees += $mouvement ? 1 : 0;
            }
        });

        if ($entrees === 0) {
            return back()->withInput()->with('erreur', 'Aucun article stockable dans cette entrée : rien n\'a été enregistré.');
        }

        $this->journaliser('entree_stock', 'PointDeVente', $site->id);

        return redirect()
            ->route('admin.stock.entree', ['point_de_vente_id' => $site->id])
            ->with('succes', "Entrée {$reference} enregistrée : {$entrees} article(s) sur « {$site->nom} ». Le coût moyen est recalculé.");
    }

    /**
     * Liste des produits périmés ou proches de la péremption (Page Rebut).
     */
    public function rebut(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = session('point_de_vente_actif_id') 
            ?? Auth::user()->point_de_vente_id 
            ?? 'tout';

        // Produits avec date de péremption définie. Seuls ceux qui ont un
        // stock : on ne met pas une prestation au rebut.
        $query = Produit::where('entreprise_id', $entreprise->id)
            ->stockables()
            ->whereNotNull('date_peremption')
            ->visiblesAuSite($pointDeVenteId)
            ->with(['stocks']);

        $produits = $query->get();

        // Séparer les produits en deux collections : périmés et bientôt périmés (< 30 jours)
        $perimes = collect();
        $proches = collect();

        foreach ($produits as $p) {
            // Surcharger le stock disponible pour le site actif
            if ($pointDeVenteId === 'tout') {
                $p->setAttribute('stock_actuel', $p->stocks->sum('quantite_disponible'));
            } else {
                $stockObj = $p->stocks->where('point_de_vente_id', $pointDeVenteId)->first();
                $p->setAttribute('stock_actuel', $stockObj ? $stockObj->quantite_disponible : 0);
            }

            if ($p->estPerime()) {
                $perimes->push($p);
            } elseif ($p->bientotPerime()) {
                $proches->push($p);
            }
        }

        // **Les lots, là où la vraie péremption se joue.**
        //
        // `produits.date_peremption` ne porte qu'une date par article : une
        // pharmacie qui reçoit trois arrivages de paracétamol n'en enregistre
        // qu'un, la saisie du troisième écrasant les deux premiers, et les
        // boîtes de mars restent en rayon. Les deux listes ci-dessus restent
        // pour les articles qui ne sont pas suivis par lot, où une date unique
        // suffit ; celles-ci portent le suivi juste.
        $site = $pointDeVenteId === 'tout' ? null : (int) $pointDeVenteId;

        $lotsPerimes = \App\Modules\Admin\Services\LotService::perimes($entreprise->id, $site);
        $lotsProches = \App\Modules\Admin\Services\LotService::bientotPerimes($entreprise->id, $site);

        return view('admin::stock.rebut', compact(
            'perimes', 'proches', 'lotsPerimes', 'lotsProches', 'pointDeVenteId'
        ));
    }

    /**
     * Action de retrait d'un produit périmé (Rebut).
     */
    public function retirerRebut(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'produit_id' => ['required', 'integer', Appartenance::a('produits', 'id')],
            'quantite'   => Quantite::physique(),
        ]);

        $produit = Produit::findOrFail($request->produit_id);
        abort_unless($produit->entreprise_id === Auth::user()->entreprise_id, 404);

        $pointDeVenteId = session('point_de_vente_actif_id') 
            ?? Auth::user()->point_de_vente_id;

        if (!$pointDeVenteId || $pointDeVenteId === 'tout') {
            return back()->with('erreur', 'Veuillez sélectionner un point de vente spécifique pour effectuer un retrait.');
        }

        $quantiteRetrait = round((float) $request->quantite, Stock::DECIMALES);

        // La disponibilité se lit sous le verrou qui servira au retrait : lue
        // hors transaction, elle est déjà périmée au moment où l'on s'en sert.
        $insuffisant = DB::transaction(function () use ($produit, $pointDeVenteId, $quantiteRetrait) {
            $disponible = StockService::disponible($produit, (int) $pointDeVenteId);

            if ($quantiteRetrait > $disponible) {
                return $disponible;
            }

            StockService::sortie($produit, (int) $pointDeVenteId, $quantiteRetrait, MouvementStock::REBUT, [
                'reference' => 'REBUT-' . now()->format('YmdHis'),
            ]);

            return null;
        });

        if ($insuffisant !== null) {
            return back()->with('erreur', "Quantité insuffisante en stock (Disponible: {$insuffisant}).");
        }

        // Journaliser
        \App\Modules\Admin\Modeles\JournalAudit::create([
            'entreprise_id'  => $produit->entreprise_id,
            'utilisateur_id' => Auth::id(),
            'action'         => 'retrait_rebut',
            'details'        => "Retrait de {$quantiteRetrait} {$produit->unite} de {$produit->nom} pour rebut",
        ]);

        return back()->with('succes', 'Le produit a été retiré du stock avec succès.');
    }

    /**
     * Liste des réceptions fournisseur en attente (Flux Réception).
     */
    public function receptions(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;

        $achats = Achat::with(['fournisseur', 'pointDeVente', 'details.produit'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->whereIn('etape', ['Bon de commande', 'Facture'])
            ->where(fn ($a) => $a->whereNull('statut')->orWhere('statut', '!=', 'Annulée'))
            // Même règle que le compteur : une commande de services n'a rien
            // à recevoir, et ne doit pas encombrer la file.
            ->whereHas('details', function ($q) {
                $q->whereColumn('quantite', '>', 'quantite_receptionnee')
                  ->whereHas('produit', fn($qp) => $qp->stockables());
            })
            ->latest()
            ->paginate(20);

        return view('admin::stock.receptions', compact('achats'));
    }

    /**
     * Fiche de réception fournisseur.
     */
    public function ficheReception(Achat $achat): View
    {
        abort_unless($achat->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        $achat->load(['fournisseur', 'pointDeVente', 'details.produit']);

        return view('admin::stock.reception_fiche', compact('achat'));
    }

    /**
     * Valider la réception physique d'une commande d'achat.
     */
    public function validerReception(Request $request, Achat $achat): \Illuminate\Http\RedirectResponse
    {
        abort_unless($achat->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        
        // `required` sur un formulaire qui n'offre aucun champ : c'est le
        // message qu'on lisait sur une commande de services — « le champ
        // reception est obligatoire », sans rien à remplir. La file ne montre
        // plus ces commandes ; si l'on force l'adresse, on le dit en clair.
        $request->validate([
            'reception'   => ['nullable', 'array'],
            'reception.*' => Quantite::facultative(),
        ]);

        $aRecevoir = collect($request->input('reception', []))
            ->filter(fn ($q) => (float) $q > 0);

        if ($aRecevoir->isEmpty()) {
            return back()->with('erreur', "Aucune quantité à réceptionner. Une prestation de service n'entre pas en stock : il n'y a rien à recevoir sur cette commande.");
        }

        $pointDeVenteId = $achat->point_de_vente_id;

        DB::transaction(function () use ($request, $achat, $pointDeVenteId) {
            foreach ($request->reception as $detailId => $qtyAReceptionner) {
                $qtyAReceptionner = round((float) $qtyAReceptionner, Stock::DECIMALES);
                if ($qtyAReceptionner <= 0) continue;

                $detail = AchatDetail::findOrFail($detailId);
                abort_unless($detail->achat_id === $achat->id, 403);

                $dejaRecu = $detail->quantite_receptionnee;
                $cmd      = $detail->quantite;
                $maxPossible = $cmd - $dejaRecu;

                if ($qtyAReceptionner > $maxPossible) {
                    throw new \InvalidArgumentException("La quantité reçue pour {$detail->produit->nom} ne peut dépasser {$maxPossible}.");
                }

                $produit = $detail->produit;
                if ($produit && $produit->estStockable()) {
                    $detail->increment('quantite_receptionnee', $qtyAReceptionner);

                    StockService::entree($produit, (int) $pointDeVenteId, (float) $qtyAReceptionner,
                        MouvementStock::RECEPTION, [
                            'piece'          => $achat,
                            'reference'      => $achat->numero_facture,
                            'fournisseur_id' => $achat->fournisseur_id,
                            // Le cout d'entree est le prix reellement paye sur
                            // la ligne, remise deduite.
                            'cout_unitaire'  => (float) $detail->prix_unitaire
                                * (1 - (float) ($detail->remise_taux ?? 0) / 100),
                        ]);
                }
            }

            // Seul le sort logistique de la commande change. Recevoir n'est
            // pas facturer : la file passait le bon en « Facture / Payé »,
            // sans écriture ni décaissement, sous son numéro `BCF-` — une
            // commande à crédit se retrouvait payée. La facture se valide par
            // « Valider & Facturer », qui n'entre plus que ce qui reste à
            // recevoir.
            $achat->load('details');
            $toutRecu = $achat->details->every(
                fn ($d) => (float) $d->quantite_receptionnee >= (float) $d->quantite
            );
            if ($achat->etape === 'Bon de commande') {
                $achat->update(['statut' => $toutRecu ? 'Reçu' : 'Reçu en partie']);
            }
        });

        // Journaliser
        \App\Modules\Admin\Modeles\JournalAudit::create([
            'entreprise_id'  => $achat->pointDeVente->entreprise_id,
            'utilisateur_id' => Auth::id(),
            'action'         => 'reception_stock',
            'details'        => "Réception de stock effectuée pour l'achat #{$achat->id} ({$achat->numero_facture})",
        ]);

        return redirect()->route('admin.stock.receptions')
            ->with('succes', 'Réception validée et enregistrée en stock.');
    }

    /**
     * Liste des livraisons client en attente (Flux Livraison).
     */
    public function livraisons(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;

        $ventes = Vente::with(['client', 'pointDeVente', 'details.produit'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->whereIn('etape', ['Bon de commande', 'Facture'])
            ->where(fn ($a) => $a->whereNull('statut')->orWhere('statut', '!=', 'Annulée'))
            // Même règle qu'à la réception : une prestation de service ne se
            // livre pas depuis un stock, et sa ligne resterait « à livrer »
            // pour toujours.
            ->whereHas('details', function ($q) {
                $q->whereColumn('quantite', '>', 'quantite_livree')
                  ->whereHas('produit', fn($qp) => $qp->stockables());
            })
            ->latest()
            ->paginate(20);

        return view('admin::stock.livraisons', compact('ventes'));
    }

    /**
     * Fiche de livraison client.
     */
    public function ficheLivraison(Vente $vente): View
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        $vente->load(['client', 'pointDeVente', 'details.produit']);

        $livreurs = \App\Modules\Authentification\Modeles\Utilisateur::where('entreprise_id', Auth::user()->entreprise_id)
            ->orderBy('nom')->get(['id', 'nom', 'prenom']);

        return view('admin::stock.livraison_fiche', compact('vente', 'livreurs'));
    }

    /**
     * Valider la livraison physique d'une commande de vente.
     */
    public function validerLivraison(Request $request, Vente $vente): \Illuminate\Http\RedirectResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);

        $entreprise = Auth::user()->entreprise;

        // Le transport, au départ (chantier 15.3) : cette file expédiait sans
        // dire où, par qui ni quand, et sans laisser de bon à remettre.
        $request->validate([
            'livraison'   => ['nullable', 'array'],
            'livraison.*' => Quantite::facultative(),
        ] + TransportLivraisonService::reglesDuDepart($entreprise), TransportLivraisonService::messages());

        $aLivrer = collect($request->input('livraison', []))
            ->filter(fn ($q) => (float) $q > 0);

        if ($aLivrer->isEmpty()) {
            return back()->with('erreur', "Aucune quantité à livrer. Une prestation de service ne sort pas d'un stock : il n'y a rien à expédier sur cette pièce.");
        }

        $pointDeVenteId = $vente->point_de_vente_id;

        $numeroBL = null;

        DB::transaction(function () use ($request, $vente, $pointDeVenteId, $entreprise, &$numeroBL) {
            // Le bon que l'on remettra : il porte le transport, et les lignes
            // réellement expédiées ci-dessous.
            $numeroBL = NumerotationService::genererNumeroBL($entreprise->id, (int) $vente->point_de_vente_id);
            $bl = BonLivraison::create([
                'numero_bl'         => $numeroBL,
                'vente_id'          => $vente->id,
                'point_de_vente_id' => $pointDeVenteId,
                'client_id'         => $vente->client_id,
                'created_by'        => Auth::id(),
                'date_livraison'    => now()->toDateString(),
                'statut'            => 'en_preparation',
            ] + TransportLivraisonService::depart($request));

            foreach ($request->livraison as $detailId => $qtyALivrer) {
                $qtyALivrer = round((float) $qtyALivrer, Stock::DECIMALES);
                if ($qtyALivrer <= 0) continue;

                $detail = VenteDetail::findOrFail($detailId);
                abort_unless($detail->vente_id === $vente->id, 403);

                $dejaLivre = $detail->quantite_livree;
                $cmd       = $detail->quantite;
                $maxPossible = $cmd - $dejaLivre;

                if ($qtyALivrer > $maxPossible) {
                    throw new \InvalidArgumentException("La quantité livrée pour {$detail->produit->nom} ne peut dépasser {$maxPossible}.");
                }

                $produit = $detail->produit;
                if ($produit && $produit->estStockable()) {
                    // La disponibilite se lit sous le verrou qui servira a la
                    // sortie : lue avant, elle est deja perimee au moment ou
                    // l'on s'en sert, et deux livraisons simultanees la
                    // trouveraient toutes deux suffisante.
                    $disponible = StockService::disponible($produit, (int) $pointDeVenteId);

                    if ($qtyALivrer > $disponible) {
                        throw new \InvalidArgumentException("Stock insuffisant pour livrer {$produit->nom} (Disponible: {$disponible}, Demandé: {$qtyALivrer}).");
                    }

                    $detail->increment('quantite_livree', $qtyALivrer);

                    BonLivraisonDetail::create([
                        'bon_livraison_id' => $bl->id,
                        'vente_detail_id'  => $detail->id,
                        'produit_id'       => $produit->id,
                        'libelle'          => $detail->libelle_virtuel ?? $produit->nom,
                        'unite'            => $detail->unite,
                        'qte_commandee'    => $cmd,
                        'qte_livree'       => $qtyALivrer,
                    ]);

                    StockService::sortie($produit, (int) $pointDeVenteId, (float) $qtyALivrer,
                        MouvementStock::LIVRAISON, [
                            'piece'     => $vente,
                            'reference' => $vente->numero_facture,
                            'client_id' => $vente->client_id,
                        ]);
                }
            }

            // Seul le statut logistique change. Livrer n'est pas facturer : la
            // file passait le bon de commande en « Facture / Payé » sous son
            // numéro `BC-`, sans paiement, sans écriture ni certification. La
            // facture s'établit par le bon de livraison ou par « Valider &
            // Facturer ».
            $vente->load('details');
            $toutLivre = $vente->details->every(
                fn ($d) => !$d->produit_id || (float) $d->quantite_livree >= (float) $d->quantite
            );
            if ($vente->etape === 'Bon de commande') {
                $vente->update(['statut' => $toutLivre ? 'En livraison' : 'Partiel']);
            }

            $bl->update(['livraison_partielle' => !$toutLivre, 'statut' => $toutLivre ? 'en_preparation' : 'partiel']);
        });

        // Journaliser
        \App\Modules\Admin\Modeles\JournalAudit::create([
            'entreprise_id'  => $vente->pointDeVente->entreprise_id,
            'utilisateur_id' => Auth::id(),
            'action'         => 'livraison_stock',
            'details'        => "Livraison de stock effectuée pour la vente #{$vente->id} ({$vente->numero_facture})",
        ]);

        return redirect()->route('admin.stock.livraisons')
            ->with('succes', "Livraison validée et stock mis à jour. Bon de livraison {$numeroBL} établi : "
                . "l'arrivée se confirme depuis le bon, avec la signature du réceptionnaire.");
    }
}
