<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\SousCategorie;
use App\Modules\Admin\Services\NumerotationTiersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportControleur
{
    // ─────────────────────────────────────────────────────────────
    // Colonnes CSV par module
    // ─────────────────────────────────────────────────────────────

    private const COLONNES = [
        'points-de-vente' => [
            'headers'  => ['nom', 'ville', 'commune', 'responsable', 'telephone', 'statut'],
            'exemple'  => [
                ['Agence Cocody', 'Abidjan', 'COCODY', 'Konan Kouassi', '0707000001', 'actif'],
                ['Agence Plateau', 'Abidjan', 'PLATEAU', 'Aya Boni', '0707000002', 'actif'],
            ],
        ],
        'clients' => [
            // Ni compte collectif ni numéro de tiers (chantier 11.2) : le
            // premier n'est plus demandé à l'écran, le second est fabriqué
            // par le système. Une colonne que rien ne lit fait saisir pour
            // rien, et laisse croire que la donnée est entrée.
            'headers'  => ['nom', 'type_facturation', 'telephone', 'email', 'adresse', 'ncc', 'regime_imposition', 'rccm'],
            'exemple'  => [
                ['Société ABC SARL', 'B2B', '+225 27 00 00 01', 'contact@abc.ci', 'Cocody, Abidjan', '2302178R', 'RNI', 'CI-ABJ-2021-001'],
                ['Marie Koffi', 'B2C', '+225 07 00 00 02', 'marie@gmail.com', 'Yopougon, Abidjan', '', '', ''],
            ],
        ],
        'fournisseurs' => [
            'headers'  => ['nom', 'type_facturation', 'telephone', 'email', 'secteur', 'adresse', 'ncc', 'regime_imposition', 'rccm'],
            'exemple'  => [
                ['CDCI Distribution', 'B2B', '+225 27 00 01 00', 'cdci@cdci.ci', 'Distribution', 'Zone 4, Marcory', '2169728N', 'RSI', 'CI-ABJ-2020-100'],
                ['Société Générale CI', 'B2G', '+225 20 00 00 00', 'sgci@sg.ci', 'Finance', 'Plateau', '', '', ''],
            ],
        ],
        'utilisateurs' => [
            'headers'  => ['nom', 'prenom', 'email', 'role', 'point_de_vente', 'fonction', 'date_debut_contrat', 'statut'],
            'exemple'  => [
                ['Koffi', 'Amos', 'amos.koffi@monentreprise.ci', 'caissier', 'Agence Cocody', 'Caissier Principal', '01/01/2025', 'actif'],
                ['Boni', 'Aya', 'aya.boni@monentreprise.ci', 'responsable_pdv', 'Agence Plateau', 'Responsable', '15/03/2024', 'actif'],
            ],
        ],
        // **Le modèle des articles portait douze colonnes ; la fiche en compte
        // désormais bien davantage.** Un magasin qui migrait deux mille
        // références devait rouvrir chaque fiche pour saisir son stock
        // d'ouverture, ses comptes de stock, son suivi par lot, sa
        // consignation. C'est le moment où l'on abandonne.
        'produits' => [
            'headers'  => [
                'nom', 'type', 'categorie', 'sous_categorie', 'unite',
                'prix_achat', 'prix_vente', 'taux_tva', 'remise_taux',
                // Plus aucun compte (chantier 11.3) : ils viennent de la
                // famille de l'article, puis des défauts — l'écran n'en
                // demande plus aucun.
                'reference', 'statut',
                // Le stock d'ouverture, dans la même feuille : sans lui,
                // l'import ne fait que la moitié du chemin.
                'point_de_vente', 'stock_initial', 'cout_unitaire', 'stock_minimum',
                // Péremption et lots — lot 6.3.
                'date_peremption', 'suivi_par_lot', 'preavis_peremption',
                // Emballages consignés — lot 6.5.
                'prix_consignation', 'delai_retour_jours',
            ],
            'exemple'  => [
                ['Stylo bille bleu', 'consommable_stockable', 'Fournitures', 'Papeterie', 'pièce',
                 '150', '250', '18', '0',
                 'STYL-BLU-001', 'actif',
                 'Agence Cocody', '240', '150', '20',
                 '', 'non', '', '', ''],
                ['Riz parfumé 25kg', 'marchandise', 'Alimentation', 'Céréales', 'sac',
                 '12 000', '15 000', '0', '0',
                 '', 'actif',
                 'Agence Cocody', '85', '12 000', '10',
                 '', 'non', '', '', ''],
                ['Paracétamol 500 mg', 'marchandise', 'Pharmacie', 'Antalgiques', 'boîte',
                 '400', '600', '0', '0',
                 'MED-PARA-500', 'actif',
                 'Agence Cocody', '300', '400', '50',
                 '31/12/2027', 'oui', '60', '', ''],
                ['Casier de 24 bouteilles', 'marchandise', 'Emballages', '', 'casier',
                 '0', '0', '18', '0',
                 'EMB-CASIER-24', 'actif',
                 'Agence Cocody', '0', '', '',
                 '', 'non', '', '2 000', '21'],
                ['Mission de conseil', 'service', 'Prestations', '', 'heure',
                 '', '25 000', '18', '0',
                 'PRESTA-CONSEIL', 'actif',
                 '', '', '', '',
                 '', 'non', '', '', ''],
            ],
        ],

        // **Le stock d'ouverture d'un catalogue déjà en place.** L'import des
        // articles pose celui des fiches qu'il crée ; celui-ci sert quand les
        // articles existent déjà — après un premier import, ou site par site.
        'stock-initial' => [
            'headers'  => ['reference', 'point_de_vente', 'quantite', 'cout_unitaire', 'stock_minimum',
                           'numero_lot', 'date_peremption'],
            'exemple'  => [
                ['STYL-BLU-001', 'Agence Cocody', '240', '150', '20', '', ''],
                ['MED-PARA-500', 'Agence Cocody', '300', '400', '50', 'L-2026-03', '31/12/2027'],
                ['MED-PARA-500', 'Agence Plateau', '120', '410', '30', 'L-2026-07', '30/06/2028'],
            ],
        ],

        // **Les immobilisations — lot 6.4.** Une entreprise qui migre a déjà un
        // parc : camions, fours, ordinateurs, chacun avec son antériorité
        // d'amortissement. Les ressaisir un à un est ce qui décide du sort de
        // la migration.
        'immobilisations' => [
            'headers'  => ['code', 'libelle', 'point_de_vente', 'compte_immobilisation',
                           'compte_amortissement', 'compte_dotation', 'date_acquisition',
                           'date_mise_en_service', 'valeur_acquisition', 'valeur_residuelle',
                           'duree_mois'],
            'exemple'  => [
                ['IMM-001', 'Camion Mercedes 1113', 'Agence Cocody', '245100', '284500', '681300',
                 '15/01/2024', '01/02/2024', '12 000 000', '0', '60'],
                ['IMM-002', 'Groupe électrogène 15 kVA', 'Agence Plateau', '241100', '284100', '681300',
                 '03/06/2025', '03/06/2025', '3 500 000', '200 000', '84'],
                ['IMM-003', 'Terrain de Yopougon', '', '222000', '282000', '681300',
                 '20/11/2023', '20/11/2023', '25 000 000', '0', '0'],
            ],
        ],
    ];

    // ─────────────────────────────────────────────────────────────
    // GET — Télécharger le fichier CSV d'exemple
    // ─────────────────────────────────────────────────────────────

    public function telechargerExemple(string $type): Response
    {
        $config = self::COLONNES[$type] ?? null;
        abort_if(!$config, 404, "Module d'import inconnu : {$type}");

        $rows = [$config['headers'], ...$config['exemple']];

        if (request('format') === 'excel') {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet       = $spreadsheet->getActiveSheet();

            foreach ($rows as $rowIndex => $row) {
                foreach ($row as $colIndex => $value) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
                    $sheet->setCellValue($colLetter . ($rowIndex + 1), $value);
                }
            }

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $content = ob_get_clean();

            return response($content, 200, [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="import_' . $type . '_exemple.xlsx"',
            ]);
        }

        $output = '';
        foreach ($rows as $row) {
            $output .= implode(';', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $row)) . "\r\n";
        }

        return response($output, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="import_' . $type . '_exemple.csv"',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // POST — Prévisualisation (5 premières lignes)
    // ─────────────────────────────────────────────────────────────

    public function preview(Request $request, string $type): JsonResponse
    {
        $config = self::COLONNES[$type] ?? null;
        if (!$config) return response()->json(['success' => false, 'message' => 'Module inconnu.'], 400);

        $request->validate(['fichier' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120']]);

        try {
            $lignes = $this->lireFichier($request->file('fichier'));
            if (empty($lignes)) {
                return response()->json(['success' => false, 'message' => 'Fichier vide ou illisible.']);
            }

            $entetes = array_shift($lignes);
            $preview = array_slice($lignes, 0, 5);

            return response()->json([
                'success'  => true,
                'entetes'  => $entetes,
                'lignes'   => $preview,
                'total'    => count($lignes),
                'colonnes_attendues' => $config['headers'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lecture : ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // POST — Importer (validation + insertion ligne par ligne)
    // ─────────────────────────────────────────────────────────────

    public function importer(Request $request, string $type): JsonResponse
    {
        $config = self::COLONNES[$type] ?? null;
        if (!$config) return response()->json(['success' => false, 'message' => 'Module inconnu.'], 400);

        $request->validate(['fichier' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120']]);

        $entreprise = Auth::user()->entreprise;
        $lignes     = $this->lireFichier($request->file('fichier'));

        if (empty($lignes)) {
            return response()->json(['success' => false, 'message' => 'Fichier vide.']);
        }

        $entetes = $this->normaliserEntetes(array_shift($lignes));

        if (empty($entetes)) {
            return response()->json(['success' => false,
                'message' => 'La première ligne du fichier doit porter les noms de colonnes.']);
        }
        $importes  = 0;
        $erreurs   = [];

        DB::beginTransaction();
        try {
            foreach ($lignes as $idx => $row) {
                $num   = $idx + 2; // ligne humaine (1 = entetes)

                // **Une ligne plus longue que l'en-tête tuait tout l'import.**
                // `array_combine` lève une erreur dès que les deux tableaux
                // n'ont pas la même taille, et un simple point-virgule en fin
                // de ligne — que produisent Excel et LibreOffice — suffisait :
                // le fichier entier était rejeté avec « Erreur critique »,
                // sans dire quelle ligne. Les cellules en trop sont désormais
                // écartées, les manquantes complétées.
                $data = array_combine(
                    $entetes,
                    array_pad(array_slice($row, 0, count($entetes)), count($entetes), '')
                );

                $erreur = $this->traiterLigne($type, $data, $entreprise, $num);
                if ($erreur) {
                    $erreurs[] = $erreur;
                } else {
                    $importes++;
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import ' . $type . ' — Exception : ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur critique : ' . $e->getMessage()]);
        }

        return response()->json([
            'success'  => true,
            'importes' => $importes,
            'erreurs'  => $erreurs,
            'message'  => "{$importes} ligne(s) importée(s) avec succès." .
                          (count($erreurs) ? ' ' . count($erreurs) . ' erreur(s) ignorée(s).' : ''),
        ]);
    }

    /**
     * Les noms de colonnes, ramenés à une forme comparable.
     *
     * Trois pièges, tous rencontrés sur des fichiers réels :
     *
     * - **la casse et les espaces** — « Prix Achat » et « prix_achat »
     *   désignent la même colonne ;
     * - **les accents** — un fichier enregistré depuis Excel écrit volontiers
     *   « Référence » là où le modèle attend « reference » ;
     * - **les doublons** — deux colonnes du même nom, dont `array_combine` ne
     *   garde silencieusement que la dernière. La seconde est ici renommée pour
     *   qu'elle ne recouvre pas la première.
     *
     * @param  array<int, mixed>  $ligne
     * @return array<int, string>
     */
    private function normaliserEntetes(array $ligne): array
    {
        $vus = [];
        $entetes = [];

        foreach ($ligne as $index => $brut) {
            $nom = Str::of((string) $brut)->trim()->ascii()->lower()
                ->replace(['-', ' '], '_')->replaceMatches('/[^a-z0-9_]/', '')->toString();

            // Une colonne sans nom garde une place : sans elle, toutes les
            // suivantes se décaleraient d'un cran.
            if ($nom === '') {
                $nom = 'colonne_' . ($index + 1);
            }

            if (isset($vus[$nom])) {
                $nom .= '_' . (++$vus[$nom]);
            } else {
                $vus[$nom] = 1;
            }

            $entetes[] = $nom;
        }

        return $entetes;
    }

    // ─────────────────────────────────────────────────────────────
    // Lecture CSV / XLSX → tableau brut de lignes
    // ─────────────────────────────────────────────────────────────

    private function lireFichier(\Illuminate\Http\UploadedFile $fichier): array
    {
        $ext = strtolower($fichier->getClientOriginalExtension());

        if (in_array($ext, ['xlsx', 'xls'])) {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fichier->getRealPath());
                $worksheet   = $spreadsheet->getActiveSheet();
                $rows        = [];
                foreach ($worksheet->getRowIterator() as $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);
                    $rowData = [];
                    foreach ($cellIterator as $cell) {
                        $valeur = $cell->getValue();

                        // Une cellule mise en forme (texte enrichi, ou chaîne
                        // « en ligne » d'un fichier produit hors d'Excel) se
                        // lit en objet : l'aperçu l'affichait « [object Object] »
                        // dans chaque colonne de texte.
                        if ($valeur instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                            $valeur = $valeur->getPlainText();
                        }

                        $rowData[] = $valeur;
                    }
                    if (count(array_filter($rowData, fn($v) => !is_null($v) && trim((string)$v) !== '')) > 0) {
                        $rows[] = $rowData;
                    }
                }
                return $rows;
            } catch (\Exception $e) {
                throw new \RuntimeException('Erreur lors de la lecture du fichier Excel : ' . $e->getMessage());
            }
        }

        // CSV (utf-8 ou latin-1 avec BOM)
        $contenu = file_get_contents($fichier->getRealPath());
        $contenu = ltrim($contenu, "\xEF\xBB\xBF");
        if (!mb_detect_encoding($contenu, 'UTF-8', true)) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'ISO-8859-1');
        }

        $lignes = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $contenu)) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $delim  = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
            $lignes[] = str_getcsv($line, $delim);
        }
        return $lignes;
    }

    // ─────────────────────────────────────────────────────────────
    // Traitement d'une ligne selon le type
    // ─────────────────────────────────────────────────────────────

    private function traiterLigne(string $type, array $data, $entreprise, int $num): ?string
    {
        return match ($type) {
            'points-de-vente' => $this->importerPointDeVente($data, $entreprise, $num),
            'clients'         => $this->importerClient($data, $entreprise, $num),
            'fournisseurs'    => $this->importerFournisseur($data, $entreprise, $num),
            'utilisateurs'    => $this->importerUtilisateur($data, $entreprise, $num),
            'produits'        => $this->importerProduit($data, $entreprise, $num),
            'stock-initial'   => $this->importerStockInitial($data, $entreprise, $num),
            'immobilisations' => $this->importerImmobilisation($data, $entreprise, $num),
            default           => "Ligne {$num} : module inconnu.",
        };
    }

    // ─────────────────────────────────────────────────────────────
    // MODULE 1 — Points de vente
    // ─────────────────────────────────────────────────────────────

    private function importerPointDeVente(array $d, $entreprise, int $num): ?string
    {
        $nom = trim($d['nom'] ?? '');
        if (!$nom) return "Ligne {$num} : 'nom' obligatoire.";

        PointDeVente::firstOrCreate(
            ['entreprise_id' => $entreprise->id, 'nom' => $nom],
            [
                'ville'       => trim($d['ville'] ?? ''),
                'commune'     => trim($d['commune'] ?? ''),
                'responsable' => trim($d['responsable'] ?? ''),
                'telephone'   => trim($d['telephone'] ?? ''),
                'statut'      => in_array(strtolower($d['statut'] ?? ''), ['inactif', 'inactive', '0']) ? 'inactif' : 'actif',
            ]
        );
        return null;
    }

    // ─────────────────────────────────────────────────────────────
    // MODULE 2A — Clients
    // ─────────────────────────────────────────────────────────────

    private function importerClient(array $d, $entreprise, int $num): ?string
    {
        $nom = trim($d['nom'] ?? '');
        if (!$nom) return "Ligne {$num} : 'nom' obligatoire.";

        $type = strtoupper(trim($d['type_facturation'] ?? 'B2C'));
        if (!in_array($type, ['B2B', 'B2C', 'B2G', 'B2F'])) $type = 'B2C';

        $ncc = $this->normaliserNcc(trim($d['ncc'] ?? ''));
        if ($type === 'B2B' && !$ncc) {
            return "Ligne {$num} : NCC obligatoire pour le type B2B (client: {$nom}).";
        }
        if ($ncc && !$this->nccValide($ncc)) {
            return "Ligne {$num} : NCC invalide pour le client {$nom}. Il doit contenir 8 caractères et se terminer par une lettre majuscule.";
        }

        // Les régimes des tiers sont ceux de l'entreprise (recette du
        // 08/10/2026) : « RS » ou « rsi » se lisent RSI, l'inconnu se refuse.
        $regime = \App\Modules\Admin\Modeles\Entreprise::normaliserRegime($d['regime_imposition'] ?? null);
        if ($regime !== null && !in_array($regime, \App\Modules\Admin\Modeles\Entreprise::regimesAcceptesPourTiers(), true)) {
            return "Ligne {$num} : régime d'imposition « {$regime} » inconnu pour le client {$nom}. "
                . 'Régimes reconnus : ' . implode(', ', array_keys(\App\Modules\Admin\Modeles\Entreprise::REGIMES_IMPOSITION)) . '.';
        }

        // Le compte collectif ne vient plus du fichier (chantier 11.2) : même
        // règle qu'à l'écran, celle de `ImputationService::compteDeTiers()`.
        $compteGeneral = \App\Modules\Admin\Services\ImputationService::compteDeTiers($entreprise, 'client');

        Client::firstOrCreate(
            ['entreprise_id' => $entreprise->id, 'nom' => $nom],
            [
                'type_facturation' => $type,
                'telephone'        => trim($d['telephone'] ?? ''),
                'email'            => trim($d['email'] ?? '') ?: null,
                'adresse'          => trim($d['adresse'] ?? ''),
                'ncc'              => Client::nccRetenu($type, $ncc ?: null),
                'regime_imposition'=> $regime,
                'rccm'             => trim($d['rccm'] ?? ''),
                'compte_comptable' => $compteGeneral,
                'numero_tiers'     => $this->numeroTiersImporte(
                    null, $entreprise, $compteGeneral, $nom, Client::class
                ),
                'source'           => 'import_csv',
            ]
        );
        return null;
    }

    /**
     * Le numéro de tiers d'une fiche importée : filtré, puis fabriqué à défaut.
     *
     * **Comptaflow filtre au déversement comme à l'import**, et il a raison :
     * un fichier vient de partout — d'un autre logiciel, d'un tableur retouché
     * à la main — et rien n'y garantit la convention de l'entreprise. Un
     * numéro qui ne la respecte pas ne serait plus retrouvé par la passerelle,
     * et chaque écriture de ce tiers retomberait sur son compte collectif.
     *
     * Trois motifs de rejet, et dans les trois cas le système renumérote :
     *
     * - **le numéro est le compte collectif lui-même** — la confusion des deux
     *   notions, écrite en base ;
     * - **le préfixe ne correspond pas au compte de rattachement** — un tiers
     *   `40…` sur un client ferait partir l'écriture sur le collectif
     *   fournisseurs ;
     * - **la longueur n'est pas la bonne** — Comptaflow cherche par égalité de
     *   chaîne, et `4100010` ne vaut pas `410001`.
     *
     * @param  class-string<Client|Fournisseur>  $modele
     */
    private function numeroTiersImporte($fourni, $entreprise, string $compteGeneral, string $nom, string $modele): string
    {
        $fourni = strtoupper(preg_replace('/\s+/', '', (string) $fourni));

        $recevable = $fourni !== ''
            && !NumerotationTiersService::estLeCompteCollectif($fourni, $compteGeneral)
            && NumerotationTiersService::estCoherent($fourni, $compteGeneral)
            && strlen($fourni) === NumerotationTiersService::LONGUEUR
            && !$modele::where('entreprise_id', $entreprise->id)->where('numero_tiers', $fourni)->exists();

        if ($recevable) {
            return $fourni;
        }

        return $modele === Client::class
            ? NumerotationTiersService::pourClient($entreprise, $compteGeneral, $nom)
            : NumerotationTiersService::pourFournisseur($entreprise, $compteGeneral, $nom);
    }

    // ─────────────────────────────────────────────────────────────
    // MODULE 2B — Fournisseurs
    // ─────────────────────────────────────────────────────────────

    private function importerFournisseur(array $d, $entreprise, int $num): ?string
    {
        $nom = trim($d['nom'] ?? '');
        if (!$nom) return "Ligne {$num} : 'nom' obligatoire.";

        $type = strtoupper(trim($d['type_facturation'] ?? 'B2B'));
        if (!in_array($type, ['B2B', 'B2C', 'B2G', 'B2F'])) $type = 'B2B';

        $ncc = $this->normaliserNcc(trim($d['ncc'] ?? ''));
        if ($type === 'B2B' && !$ncc) {
            return "Ligne {$num} : NCC obligatoire pour le type B2B (fournisseur: {$nom}).";
        }
        if ($ncc && !$this->nccValide($ncc)) {
            return "Ligne {$num} : NCC invalide pour le fournisseur {$nom}. Il doit contenir 8 caractères et se terminer par une lettre majuscule.";
        }

        // Les régimes des tiers sont ceux de l'entreprise (recette du
        // 08/10/2026) : « RS » ou « rsi » se lisent RSI, l'inconnu se refuse.
        $regime = \App\Modules\Admin\Modeles\Entreprise::normaliserRegime($d['regime_imposition'] ?? null);
        if ($regime !== null && !in_array($regime, \App\Modules\Admin\Modeles\Entreprise::regimesAcceptesPourTiers(), true)) {
            return "Ligne {$num} : régime d'imposition « {$regime} » inconnu pour le fournisseur {$nom}. "
                . 'Régimes reconnus : ' . implode(', ', array_keys(\App\Modules\Admin\Modeles\Entreprise::REGIMES_IMPOSITION)) . '.';
        }

        // Le compte collectif ne vient plus du fichier (chantier 11.2) : même
        // règle qu'à l'écran, celle de `ImputationService::compteDeTiers()`.
        $compteGeneral = \App\Modules\Admin\Services\ImputationService::compteDeTiers($entreprise, 'fournisseur');

        Fournisseur::firstOrCreate(
            ['entreprise_id' => $entreprise->id, 'nom' => $nom],
            [
                'type_facturation' => $type,
                'telephone'        => trim($d['telephone'] ?? ''),
                'email'            => trim($d['email'] ?? '') ?: null,
                'secteur'          => trim($d['secteur'] ?? ''),
                'adresse'          => trim($d['adresse'] ?? ''),
                'ncc'              => $type === 'B2B' ? $ncc : null,
                'regime_imposition'=> $regime,
                'rccm'             => trim($d['rccm'] ?? ''),
                'compte_comptable' => $compteGeneral,
                'numero_tiers'     => $this->numeroTiersImporte(
                    null, $entreprise, $compteGeneral, $nom, Fournisseur::class
                ),
                'source'           => 'import_csv',
            ]
        );
        return null;
    }

    private function normaliserNcc(?string $ncc): ?string
    {
        $ncc = $ncc === null ? '' : preg_replace('/\s+/', '', $ncc);
        $ncc = strtoupper($ncc);

        return $ncc === '' ? null : $ncc;
    }

    private function nccValide(?string $ncc): bool
    {
        return $ncc === null || preg_match('/^[A-Z0-9]{7}[A-Z]$/', $ncc) === 1;
    }

    // ─────────────────────────────────────────────────────────────
    // MODULE 3 — Utilisateurs
    // ─────────────────────────────────────────────────────────────

    private function importerUtilisateur(array $d, $entreprise, int $num): ?string
    {
        $nom    = trim($d['nom'] ?? '');
        $prenom = trim($d['prenom'] ?? '');
        $email  = trim($d['email'] ?? '');
        $role   = trim($d['role'] ?? 'caissier');

        if (!$nom)   return "Ligne {$num} : 'nom' obligatoire.";
        if (!$prenom) return "Ligne {$num} : 'prenom' obligatoire.";
        if (!$email)  return "Ligne {$num} : 'email' obligatoire.";

        if (!in_array($role, ['admin_secondaire', 'responsable_pdv', 'caissier'])) {
            $role = 'caissier';
        }

        // Résoudre le point de vente par nom
        $pdvId = null;
        $pdvNom = trim($d['point_de_vente'] ?? '');
        if ($pdvNom) {
            $pdv = PointDeVente::where('entreprise_id', $entreprise->id)
                ->whereRaw('LOWER(nom) = ?', [strtolower($pdvNom)])
                ->first();
            if ($pdv) $pdvId = $pdv->id;
        }

        $dateDebut = null;
        if (!empty($d['date_debut_contrat'])) {
            try {
                $dateDebut = \Carbon\Carbon::createFromFormat('d/m/Y', $d['date_debut_contrat'])->toDateString();
            } catch (\Exception $e) {
                // Format invalide, on ignore
            }
        }

        $userModel = \App\Modules\Authentification\Modeles\Utilisateur::class;

        // **`firstOrCreate(['email' => …])` cherchait sur l'adresse seule, sans
        // borne d'entreprise.** L'adresse est unique sur toute la plateforme :
        // si elle appartenait déjà à quelqu'un — d'une autre entreprise, ou le
        // superadministrateur —, la méthode trouvait ce compte, ne créait rien,
        // et **la ligne comptait pour un succès**. L'administrateur annonçait
        // alors un accès à un salarié qui ne pourrait jamais se connecter.
        //
        // Pire, cela faisait un **oracle d'existence** silencieux et gratuit :
        // il suffisait d'importer une liste d'adresses et de lire le compteur
        // pour savoir lesquelles sont déjà inscrites. Le refus est désormais
        // explicite et compté comme une erreur. Il reste que refuser révèle
        // l'existence de l'adresse — c'est le prix de tout écran de création
        // de compte, et l'appelant est ici un administrateur authentifié qui
        // inscrit ses propres salariés.
        $existant = $userModel::where('email', $email)->first();

        if ($existant) {
            return $existant->entreprise_id === $entreprise->id
                ? null // Déjà inscrit chez vous : rien à faire, et ce n'est pas une erreur.
                : "Ligne {$num} : l'adresse « {$email} » est déjà utilisée sur la plateforme "
                  . 'et ne peut pas être rattachée à votre entreprise.';
        }

        $motDePasse = Str::random(12);

        $userModel::create(
            [
                'email'                 => $email,
                'entreprise_id'         => $entreprise->id,
                'point_de_vente_id'     => $pdvId,
                'nom'                   => $nom,
                'prenom'                => $prenom,
                'password'              => Hash::make($motDePasse),
                'role'                  => $role,
                'fonction'              => trim($d['fonction'] ?? ''),
                'date_debut_contrat'    => $dateDebut,
                'statut'                => strtolower($d['statut'] ?? '') === 'inactif' ? 'inactif' : 'actif',
                'doit_changer_password' => true,
            ]
        );

        return null;
    }

    // ─────────────────────────────────────────────────────────────
    // MODULE 4 — Produits
    // ─────────────────────────────────────────────────────────────

    private function importerProduit(array $d, $entreprise, int $num): ?string
    {
        $nom = trim($d['nom'] ?? '');
        if (!$nom) return "Ligne {$num} : 'nom' obligatoire.";

        $typesValides = ['marchandise', 'matiere_premiere', 'produit_fini', 'consommable_stockable', 'consommable_non_stockable', 'service'];
        $type = trim($d['type'] ?? 'marchandise');
        if (!in_array($type, $typesValides)) {
            return "Ligne {$num} : type '{$type}' invalide. Valeurs : " . implode(', ', $typesValides);
        }

        $prixAchat  = self::nombre($d['prix_achat'] ?? 0);
        $prixVente  = self::nombre($d['prix_vente'] ?? 0);

        // **Les taux viennent de la source, non d'une liste recopiée.** La
        // plateforme ne reçoit pas un pourcentage mais un code, et elle
        // applique elle-même le taux attaché à ce code : une seconde liste
        // dériverait de la première sans que rien ne le signale, et une facture
        // certifiée afficherait un montant différent de celle établie ici.
        $tauxTva = self::nombre($d['taux_tva'] ?? 18);

        if (!Produit::estTauxTvaReconnu($tauxTva)) {
            return "Ligne {$num} : taux de TVA « {$tauxTva} » hors barème. "
                . 'La facture normalisée ne sait représenter que '
                . implode(' %, ', Produit::TAUX_TVA_DGI) . ' %.';
        }

        // **Un service n'a pas de prix d'achat**, et l'import le refusait :
        // un cabinet comptable, dont tous les articles sont des missions, ne
        // pouvait importer aucune ligne. La fiche article, elle, l'accepte
        // depuis toujours.
        //
        // **Un produit fini n'a pas de prix d'achat** : son coût vient de la
        // production (journal, § coût de revient : « presque toujours nul »).
        // **Une matière première ou un consommable ne se revend pas** : son
        // prix de vente peut rester à zéro. La fiche article accepte les deux
        // depuis toujours ; l'import les refusait, et le catalogue d'une
        // boulangerie (farine, levure, baguette) ne passait pas
        // (recette du 08/10/2026). La marchandise garde son prix d'achat
        // obligatoire : il sert de premier coût au stock.
        $sansPrixAchat = in_array($type, ['service', 'produit_fini'], true);
        $sansPrixVente = in_array($type, ['matiere_premiere', 'consommable_stockable', 'consommable_non_stockable'], true);

        if ($prixAchat < 0 || (!$sansPrixAchat && $prixAchat <= 0)) {
            return "Ligne {$num} : prix_achat doit être > 0 pour un article de type « {$type} ».";
        }

        if ($prixVente < 0 || (!$sansPrixVente && $prixVente <= 0)) {
            return "Ligne {$num} : prix_vente doit être > 0 pour un article de type « {$type} ».";
        }

        // **La famille et la sous-famille, par leur nom.**
        //
        // La colonne `sous_categorie` figurait au modèle et n'était jamais
        // lue : l'`import` la traversait sans rien en faire. Un catalogue de
        // deux mille références arrivait rangé par famille seulement, et la
        // sous-famille se ressaisissait fiche par fiche — c'est-à-dire jamais.
        //
        // Un nom qui ne désigne rien est **signalé, non ignoré** : la ligne
        // est refusée avec son motif, comme le fait déjà le stock d'ouverture
        // devant une référence inconnue. Ranger l'article « sans famille » et
        // compter la ligne pour un succès ferait croire l'import complet.
        $categorieId = null;
        $nomCategorie = trim($d['categorie'] ?? '');

        if ($nomCategorie !== '') {
            $categorieId = Categorie::where('entreprise_id', $entreprise->id)
                ->whereRaw('LOWER(nom) = ?', [mb_strtolower($nomCategorie)])
                ->value('id');

            if (!$categorieId) {
                return "Ligne {$num} : aucune famille ne s'appelle « {$nomCategorie} ». "
                    . 'Créez-la d\'abord, ou laissez la colonne vide.';
            }
        }

        // La sous-famille appartient à une famille, et la famille à une
        // entreprise. Sans cette borne, deux entreprises qui nomment toutes
        // deux leur sous-famille « Céréales » se partageraient la même ligne :
        // le catalogue de l'une renseignerait celui de l'autre.
        $sousCategorieId = null;
        $nomSousCategorie = trim($d['sous_categorie'] ?? '');

        if ($nomSousCategorie !== '') {
            $sousCategorieId = SousCategorie::whereRaw('LOWER(nom) = ?', [mb_strtolower($nomSousCategorie)])
                ->when(
                    $categorieId,
                    fn ($q) => $q->where('categorie_id', $categorieId),
                    fn ($q) => $q->whereIn(
                        'categorie_id',
                        Categorie::where('entreprise_id', $entreprise->id)->select('id')
                    )
                )
                ->value('id');

            if (!$sousCategorieId) {
                return "Ligne {$num} : aucune sous-famille ne s'appelle « {$nomSousCategorie} »"
                    . ($nomCategorie !== '' ? " dans la famille « {$nomCategorie} »" : '') . '.';
            }
        }

        // Référence : auto si vide
        $reference = trim($d['reference'] ?? '');
        if (!$reference) {
            $reference = strtoupper(Str::slug($nom, '-')) . '-' . strtoupper(Str::random(4));
        }

        $famille = $categorieId ? Categorie::where('entreprise_id', $entreprise->id)->find($categorieId) : null;

        $produit = Produit::firstOrCreate(
            ['entreprise_id' => $entreprise->id, 'reference' => $reference],
            [
                'nom'          => $nom,
                'type'         => $type,
                'categorie_id' => $categorieId,
                'sous_categorie_id' => $sousCategorieId,
                'unite'        => trim($d['unite'] ?? '') ?: 'pièce',
                'prix_achat'   => $prixAchat,
                'prix_vente'   => $prixVente,
                'taux_tva'     => $tauxTva,
                // Les comptes viennent de la famille, puis des défauts — plus
                // du fichier (chantier 11.3). Ceux de stock n'ont pas de
                // repli : il n'existe pas de « compte de stock générique »
                // qui voudrait dire quelque chose ; les marchandises vont en
                // 31, les matières en 32, les produits finis en 36.
                'compte_vente'     => trim((string) $famille?->compte_vente) ?: config('selflow.plan_comptable_defaut.vente_defaut'),
                'compte_achat'     => trim((string) $famille?->compte_achat) ?: config('selflow.plan_comptable_defaut.achat_defaut'),
                'compte_stock'     => trim((string) $famille?->compte_stock) ?: null,
                'compte_variation' => trim((string) $famille?->compte_variation) ?: null,
                'remise_taux'      => min(100, max(0, self::nombre($d['remise_taux'] ?? 0))),
                'date_peremption'  => self::date($d['date_peremption'] ?? null),
                // Le suivi par lot et la consignation, ajoutés aux lots 6.3 et
                // 6.5 : sans eux, un dépôt de boissons ou une pharmacie devait
                // rouvrir ses deux mille fiches une à une après l'import.
                'suivi_par_lot'      => self::booleen($d['suivi_par_lot'] ?? null),
                'preavis_peremption' => (int) (self::nombre($d['preavis_peremption'] ?? 0) ?: 30),
                'prix_consignation'  => self::nombre($d['prix_consignation'] ?? 0) ?: null,
                'delai_retour_jours' => (int) self::nombre($d['delai_retour_jours'] ?? 0) ?: null,
                'statut'       => strtolower($d['statut'] ?? '') === 'inactif' ? 'inactif' : 'actif',
            ]
        );

        // **Le stock d'ouverture, dans la même feuille.** Sans lui, un magasin
        // qui migre saisit ses deux mille quantités à la main après l'import :
        // c'est le moment où l'on abandonne. La quantité passe par la porte
        // unique du stock, qui journalise et valorise comme pour toute entrée.
        if ($produit->wasRecentlyCreated) {
            $this->poserLeStockDOuverture($produit, $d, $entreprise);
        }

        return null;
    }

    /**
     * Le stock d'ouverture d'un article importé.
     *
     * Il passe par `StockService`, jamais par un `Stock::create` direct : c'est
     * la porte unique, celle qui verrouille la fiche, journalise le mouvement
     * et valorise au CUMP (Coût Unitaire Moyen Pondéré). Un stock posé à côté
     * n'aurait ni trace au journal, ni valeur au bilan.
     *
     * Le motif retenu est l'inventaire : une ouverture est un comptage, pas une
     * réception — il n'y a pas de fournisseur derrière.
     */
    private function poserLeStockDOuverture(Produit $produit, array $d, $entreprise): void
    {
        $quantite = self::nombre($d['stock_initial'] ?? 0);

        if ($quantite <= 0 || !$produit->estStockable()) {
            return;
        }

        $site = $this->siteDeLImport($d, $entreprise);

        if (!$site) {
            return;
        }

        \App\Modules\Admin\Services\StockService::entree(
            $produit, $site, $quantite,
            \App\Modules\Admin\Modeles\MouvementStock::INVENTAIRE,
            [
                'reference'     => 'OUVERTURE',
                // Le coût d'ouverture est celui que l'entreprise déclare, et le
                // prix d'achat à défaut. Valoriser à zéro ferait apparaître une
                // marge intégrale à la première vente.
                'cout_unitaire' => self::nombre($d['cout_unitaire'] ?? 0) ?: (float) $produit->prix_achat,
            ]
        );

        if (self::nombre($d['stock_minimum'] ?? 0) > 0) {
            \App\Modules\Admin\Modeles\Stock::where('produit_id', $produit->id)
                ->where('point_de_vente_id', $site)
                ->update(['stock_minimum' => self::nombre($d['stock_minimum'])]);
        }
    }

    /**
     * Le site sur lequel poser le stock : celui nommé dans la ligne, le site
     * actif à défaut.
     */
    private function siteDeLImport(array $d, $entreprise): ?int
    {
        $nomSite = trim($d['point_de_vente'] ?? '');

        if ($nomSite !== '') {
            $site = PointDeVente::where('entreprise_id', $entreprise->id)
                ->whereRaw('LOWER(nom) = ?', [mb_strtolower($nomSite)])
                ->value('id');

            if ($site) {
                return (int) $site;
            }
        }

        return (int) (session('point_de_vente_actif_id')
            ?? Auth::user()->point_de_vente_id
            ?? PointDeVente::where('entreprise_id', $entreprise->id)->value('id')) ?: null;
    }

    // ─────────────────────────────────────────────────────────────
    // MODULE 5 — Stock d'ouverture
    // ─────────────────────────────────────────────────────────────

    /**
     * Le stock d'ouverture d'un article déjà au catalogue.
     *
     * L'entrée passe par `StockService`, la porte unique : elle verrouille la
     * fiche, journalise le mouvement, valorise au CUMP (Coût Unitaire Moyen
     * Pondéré) et écrit l'inventaire permanent. Un stock posé à côté n'aurait
     * ni trace au journal, ni valeur au bilan.
     *
     * Le motif est l'inventaire, non la réception : une ouverture est un
     * comptage, et il n'y a pas de fournisseur derrière.
     */
    private function importerStockInitial(array $d, $entreprise, int $num): ?string
    {
        $reference = trim($d['reference'] ?? '');

        if (!$reference) return "Ligne {$num} : 'reference' obligatoire.";

        $produit = Produit::where('entreprise_id', $entreprise->id)
            ->where('reference', $reference)
            ->first();

        if (!$produit) {
            return "Ligne {$num} : aucun article ne porte la référence « {$reference} ». "
                . 'Importez d\'abord le catalogue.';
        }

        if (!$produit->estStockable()) {
            return "Ligne {$num} : « {$produit->nom} » est un {$produit->type} : il ne s'épuise pas.";
        }

        $quantite = self::nombre($d['quantite'] ?? 0);

        if ($quantite <= 0) {
            return "Ligne {$num} : la quantité d'ouverture est strictement positive.";
        }

        $site = $this->siteDeLImport($d, $entreprise);

        if (!$site) {
            return "Ligne {$num} : aucun point de vente ne correspond à « "
                . trim($d['point_de_vente'] ?? '') . ' ».';
        }

        $contexte = [
            'reference' => 'OUVERTURE',
            // Valoriser à zéro ferait apparaître une marge intégrale à la
            // première vente : le prix d'achat de la fiche vaut mieux que rien.
            'cout_unitaire' => self::nombre($d['cout_unitaire'] ?? 0) ?: (float) $produit->prix_achat,
        ];

        // L'arrivage, pour les articles suivis par lot. Sans numéro, rien n'est
        // écrit côté lots, et l'écart avec le stock dit ce qui reste à
        // régulariser.
        if (trim($d['numero_lot'] ?? '') !== '') {
            $contexte['lot'] = [
                'numero'          => trim($d['numero_lot']),
                'date_peremption' => self::date($d['date_peremption'] ?? null),
            ];
        }

        \App\Modules\Admin\Services\StockService::entree(
            $produit, $site, $quantite,
            \App\Modules\Admin\Modeles\MouvementStock::INVENTAIRE,
            $contexte
        );

        if (self::nombre($d['stock_minimum'] ?? 0) > 0) {
            \App\Modules\Admin\Modeles\Stock::where('produit_id', $produit->id)
                ->where('point_de_vente_id', $site)
                ->update(['stock_minimum' => self::nombre($d['stock_minimum'])]);
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────
    // MODULE 6 — Immobilisations
    // ─────────────────────────────────────────────────────────────

    /**
     * Un bien immobilisé, et son plan d'amortissement.
     *
     * Une entreprise qui migre a déjà un parc — camions, fours, ordinateurs —
     * chacun avec son antériorité. Le plan se calcule à l'import comme il se
     * calculerait à la saisie : depuis la mise en service, prorata temporis.
     *
     * **Aucune dotation n'est passée en comptabilité.** L'import établit le
     * plan ; c'est la clôture qui écrit, et elle appartient à l'utilisateur.
     * Écrire ici des dotations antérieures produirait des charges sur des
     * exercices déjà arrêtés.
     */
    private function importerImmobilisation(array $d, $entreprise, int $num): ?string
    {
        $code    = trim($d['code'] ?? '');
        $libelle = trim($d['libelle'] ?? '');

        if (!$code)    return "Ligne {$num} : 'code' obligatoire.";
        if (!$libelle) return "Ligne {$num} : 'libelle' obligatoire.";

        if (\App\Modules\Admin\Modeles\Immobilisation::where('entreprise_id', $entreprise->id)
                ->where('code', $code)->exists()) {
            return "Ligne {$num} : le code « {$code} » désigne déjà un autre bien.";
        }

        $acquisition = self::date($d['date_acquisition'] ?? null);
        $miseEnService = self::date($d['date_mise_en_service'] ?? null) ?: $acquisition;

        if (!$acquisition) {
            return "Ligne {$num} : 'date_acquisition' est illisible. Attendu : 31/12/2026.";
        }

        // C'est la mise en service qui déclenche l'amortissement, et un bien ne
        // se met pas en service avant d'être acquis.
        if ($miseEnService < $acquisition) {
            return "Ligne {$num} : la mise en service précède l'acquisition.";
        }

        $valeur    = self::nombre($d['valeur_acquisition'] ?? 0);
        $residuelle = self::nombre($d['valeur_residuelle'] ?? 0);

        if ($valeur <= 0) return "Ligne {$num} : 'valeur_acquisition' doit être > 0.";

        if ($residuelle > $valeur) {
            return "Ligne {$num} : la valeur résiduelle dépasse la valeur d'acquisition.";
        }

        foreach (['compte_immobilisation', 'compte_amortissement', 'compte_dotation'] as $champ) {
            if (trim($d[$champ] ?? '') === '') {
                return "Ligne {$num} : '{$champ}' obligatoire. Un compte deviné rendrait le bilan faux.";
            }
        }

        $duree = (int) self::nombre($d['duree_mois'] ?? 0);

        if ($duree < 0 || $duree > 1200) {
            return "Ligne {$num} : la durée se compte en mois, cent ans au plus.";
        }

        $bien = \App\Modules\Admin\Modeles\Immobilisation::create([
            'entreprise_id'         => $entreprise->id,
            'point_de_vente_id'     => $this->siteDeLImport($d, $entreprise),
            'code'                  => $code,
            'libelle'               => $libelle,
            'compte_immobilisation' => trim($d['compte_immobilisation']),
            'compte_amortissement'  => trim($d['compte_amortissement']),
            'compte_dotation'       => trim($d['compte_dotation']),
            'date_acquisition'      => $acquisition,
            'date_mise_en_service'  => $miseEnService,
            'valeur_acquisition'    => $valeur,
            'valeur_residuelle'     => $residuelle,
            'duree_mois'            => $duree,
            'mode'                  => \App\Modules\Admin\Modeles\Immobilisation::LINEAIRE,
            'statut'                => \App\Modules\Admin\Modeles\Immobilisation::EN_SERVICE,
            'utilisateur_id'        => Auth::id(),
        ]);

        \App\Modules\Admin\Services\AmortissementService::etablirLePlan($bien);

        return null;
    }

    // ─────────────────────────────────────────────────────────────
    // Lecture des valeurs d'une cellule
    // ─────────────────────────────────────────────────────────────

    /**
     * Un nombre, quelle que soit la façon dont le tableur l'a écrit.
     *
     * « 12 000,50 », « 12000.50 », « 12 000.50 » : l'espace insécable des
     * milliers et la virgule décimale française sont la règle sur les fichiers
     * ivoiriens, et `(float)` sur « 12 000,50 » rendait **12**.
     */
    private static function nombre($valeur): float
    {
        if (is_numeric($valeur)) {
            return (float) $valeur;
        }

        $texte = str_replace(["\u{00A0}", "\u{202F}", ' '], '', (string) $valeur);
        $texte = str_replace(',', '.', $texte);

        return is_numeric($texte) ? (float) $texte : 0.0;
    }

    /**
     * Une date, dans l'un des formats qu'un tableur produit.
     *
     * Le format ivoirien est jj/mm/aaaa ; Excel exporte volontiers aaaa-mm-jj.
     * Ne reconnaître que l'un des deux perd la moitié des fichiers.
     */
    private static function date($valeur): ?string
    {
        $texte = trim((string) $valeur);

        if ($texte === '') {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
            try {
                return \Carbon\Carbon::createFromFormat($format, $texte)->toDateString();
            } catch (\Exception) {
                continue;
            }
        }

        return null;
    }

    /**
     * Un oui/non, dans les mots que les gens écrivent réellement.
     */
    private static function booleen($valeur): bool
    {
        return in_array(
            mb_strtolower(trim((string) $valeur)),
            ['1', 'oui', 'o', 'vrai', 'true', 'x', 'yes'],
            true
        );
    }
}
