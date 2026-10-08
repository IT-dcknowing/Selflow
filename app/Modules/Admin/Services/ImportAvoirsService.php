<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneFactureRecueLigne;
use App\Modules\Admin\Modeles\PortailFneImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recueille les factures avoir relevées au portail FNE, et les range en base.
 *
 * ## Ce qu'il lit
 *
 * Un fichier par entreprise, déposé par `SCRAPER-PORTAIL-FNE/avoirs.js` dans un
 * **sous-dossier** du dossier d'import :
 *
 *     storage/app/portail-fne/avoirs/<login>_<AAAAMMJJ>.json
 *
 * Le sous-dossier (et non un suffixe dans le nom) : même règle que pour
 * `achats.js`. Les deux chaînes lisent des dossiers différents et n'ont
 * aucune raison de se croiser.
 *
 * Le fichier porte la même enveloppe que les achats :
 *
 *     { login, source, periode: {du, au}, colonnes_a_l_ecran, factures: [...] }
 *
 * ## Ce qu'il ne fait pas
 *
 * **Il ne crée aucune vente, aucune écriture, aucun client.** Un avoir relevé
 * est un constat : il dit que la DGI détient cette pièce. La transformer en
 * vente produirait des écritures parce qu'un fichier est arrivé, et
 * doublonnerait probablement une saisie déjà faite. Comme pour les achats, le
 * rapprochement se regarde avant de s'appliquer.
 *
 * ## Différence avec ImportFacturesRecuesService
 *
 * Le `TYPE` est `'avoirs'`, le dossier est `avoirs/`, et les enregistrements
 * ont tous `subtype = 'refund'` (filtrés par `avoirs.js` avant le dépôt).
 */
class ImportAvoirsService
{
    /** Le type porté par la ligne d'import, à côté de `fiche`, `points` et `achats`. */
    public const TYPE = 'avoirs';

    /**
     * Les champs de l'API du portail, et la colonne qui les reçoit.
     *
     * Même correspondance que `ImportFacturesRecuesService::CHAMPS` : le portail
     * rend le même moule pour `issued` que pour `received`.
     *
     * @var array<string, string>
     */
    private const CHAMPS = [
        'reference'     => 'reference',
        'id'            => 'fne_id',
        'token'         => 'token',
        'type'          => 'type',
        'subtype'       => 'subtype',
        'rne'           => 'numero_rne',
        'paymentMethod' => 'moyen_paiement',
        'status'        => 'statut_portail',
    ];

    /**
     * Les montants, et la colonne qui les reçoit.
     *
     * @var array<string, string>
     */
    private const MONTANTS = [
        'totalBeforeTaxes' => 'montant_ht',
        'totalDiscounted'  => 'remise',
        'totalTaxes'       => 'montant_tva',
        'fiscalStamp'      => 'timbre_fiscal',
        'totalCustomTaxes' => 'autres_taxes',
        'totalAfterTaxes'  => 'montant_ttc',
        'totalDue'         => 'net_a_payer',
    ];

    /**
     * Les secrets de l'émetteur qu'on ne conserve pas.
     *
     * Même liste que dans `ImportFacturesRecuesService`.
     *
     * @var array<int, string>
     */
    private const SECRETS_DU_CLIENT = [
        'apiKey',
        'isApiKeyEnabled',
        'bankReference',
        'availableFunds',
        'availableInvoiceStickers',
        'availableReceiptStickers',
        'availableCashStickers',
        'thresholdFundsLow',
        'thresholdFundsCritical',
        'fundsBlacklistedAt',
    ];

    /**
     * Lit tous les relevés d'avoirs d'un dossier.
     *
     * @return array{dossier: string, importes: int, ignores: int, inchanges: int, erreurs: int, details: array<int, array<string, mixed>>}
     */
    public function importerDossier(?string $dossier = null): array
    {
        $dossier = $dossier ?: $this->dossierParDefaut();

        $rapport = [
            'dossier'  => $dossier,
            'importes' => 0,
            'ignores'  => 0,
            'inchanges' => 0,
            'erreurs'  => 0,
            'details'  => [],
        ];

        if (!is_dir($dossier)) {
            $this->tracer('debug', "avoirs : dossier absent, rien à ramasser — {$dossier}");
            return $rapport;
        }

        $fichiers = glob(rtrim($dossier, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($fichiers);

        $this->tracer('debug', sprintf(
            'avoirs : ramassage de %d fichier(s) dans %s',
            count($fichiers),
            $dossier
        ));

        foreach ($fichiers as $chemin) {
            $resultat = $this->importerFichier($chemin);
            $rapport['details'][] = $resultat;
            $cle = match ($resultat['statut']) {
                'importe'  => 'importes',
                'ignore'   => 'ignores',
                'inchange' => 'inchanges',
                default    => 'erreurs',
            };
            $rapport[$cle]++;
        }

        if ($rapport['details'] !== []) {
            $bilan = sprintf(
                'avoirs : %d importé(s), %d inchangé(s), %d déjà lu(s), %d en erreur.',
                $rapport['importes'],
                $rapport['inchanges'],
                $rapport['ignores'],
                $rapport['erreurs']
            );

            $rapport['erreurs'] > 0
                ? $this->tracer('warning', $bilan)
                : $this->tracer('info', $bilan);
        }

        return $rapport;
    }

    /**
     * @return array{fichier: string, statut: 'importe'|'ignore'|'inchange'|'erreur', message: string, import_id: int|null, lignes: int}
     */
    public function importerFichier(string $chemin): array
    {
        $nom = basename($chemin);

        if (!is_file($chemin) || !is_readable($chemin)) {
            return $this->resultat($nom, 'erreur', 'Fichier introuvable ou illisible.');
        }

        $nomenclature = $this->analyserNom($nom);

        if ($nomenclature === null) {
            return $this->resultat(
                $nom,
                'erreur',
                'Nom hors nomenclature : attendu <login>_<date>.json.'
            );
        }

        [$login, $date] = [$nomenclature['login'], $nomenclature['date']];

        $empreinte = hash_file('sha256', $chemin);
        $dejaVu    = PortailFneImport::where('fichier_empreinte', $empreinte)->first();

        if ($dejaVu) {
            return $this->resultat(
                $nom,
                'ignore',
                "Déjà importé le {$dejaVu->created_at->format('d/m/Y à H:i')}.",
                $dejaVu->id,
                $dejaVu->lignes_importees
            );
        }

        $entreprise = $this->resoudreEntreprise($login);

        try {
            return DB::transaction(function () use ($chemin, $nom, $login, $date, $empreinte, $entreprise) {
                $enveloppe = $this->lire($chemin);
                $factures  = $enveloppe['factures'];

                $contenu   = $this->empreinteDuContenu($factures);
                $precedent = $this->dernierReleveDeMemeContenu($login, $contenu);

                if ($precedent !== null) {
                    $this->confirmerLeReleve($precedent, $date, $entreprise?->id);

                    return $this->resultat(
                        $nom,
                        'inchange',
                        sprintf(
                            'Identique au relevé du %s : %d avoir(s) déjà connu(s).',
                            $precedent->date_scraping?->format('d/m/Y') ?? '?',
                            $precedent->lignes_importees
                        ),
                        $precedent->id,
                        $precedent->lignes_importees
                    );
                }

                $import = PortailFneImport::create([
                    'entreprise_id'     => $entreprise?->id,
                    'login'             => $login,
                    'date_scraping'     => $date,
                    'type'              => self::TYPE,
                    'fichier_nom'       => $nom,
                    'fichier_empreinte' => $empreinte,
                    'contenu_empreinte' => $contenu,
                    'donnees_brutes'    => $enveloppe,
                    'statut'            => PortailFneImport::STATUT_IMPORTE,
                    'importe_at'        => now(),
                    'dernier_releve_le' => $date,
                    'releves'           => 1,
                ]);

                $compte = $this->rangerLesAvoirs($import, $factures);
                $import->update(['lignes_importees' => $compte['total']]);

                $message = sprintf(
                    '%d avoir(s) : %d nouveau(x), %d mis à jour.%s',
                    $compte['total'],
                    $compte['crees'],
                    $compte['modifies'],
                    $entreprise
                        ? " Rattaché à {$entreprise->nom}."
                        : " NCC {$login} inconnu : conservé sans rattachement."
                );

                return $this->resultat($nom, 'importe', $message, $import->id, $compte['total']);
            });
        } catch (Throwable $e) {
            $import = PortailFneImport::create([
                'entreprise_id'     => $entreprise?->id,
                'login'             => $login,
                'date_scraping'     => $date,
                'type'              => self::TYPE,
                'fichier_nom'       => $nom,
                'fichier_empreinte' => $empreinte,
                'statut'            => PortailFneImport::STATUT_ERREUR,
                'message'           => $e->getMessage(),
            ]);

            $this->tracer('error', "avoirs[{$nom}] : lecture impossible — " . $e->getMessage(), [
                'login' => $login,
                'trace' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $this->resultat($nom, 'erreur', $e->getMessage(), $import->id);
        }
    }

    /* ------------------------------ La lecture -------------------------------- */

    /**
     * @return array{login: string|null, source: string|null, periode: mixed, factures: array<int, array<string, mixed>>}
     */
    private function lire(string $chemin): array
    {
        $brut = json_decode((string) file_get_contents($chemin), true);

        if (!is_array($brut)) {
            throw new \RuntimeException('JSON illisible ou vide.');
        }

        $factures = array_is_list($brut) ? $brut : ($brut['factures'] ?? null);

        if (!is_array($factures)) {
            throw new \RuntimeException(
                "Le fichier ne porte pas de clé « factures ». Clés trouvées : "
                . implode(', ', array_keys($brut)) . '.'
            );
        }

        return [
            'login'   => $brut['login']  ?? null,
            'source'  => $brut['source'] ?? null,
            'periode' => $brut['periode'] ?? null,
            'factures' => array_values(array_filter($factures, 'is_array')),
        ];
    }

    /* ----------------------------- Le rangement ------------------------------- */

    /**
     * Range les avoirs en base.
     *
     * @param  array<int, array<string, mixed>>  $factures
     * @return array{total: int, crees: int, modifies: int}
     */
    private function rangerLesAvoirs(PortailFneImport $import, array $factures): array
    {
        $compte = ['total' => 0, 'crees' => 0, 'modifies' => 0];

        foreach ($factures as $donnees) {
            if (!is_array($donnees)) {
                continue;
            }

            $reference = trim((string) ($donnees['reference'] ?? ''));
            $login     = $import->login;

            if ($reference === '' || $login === '') {
                continue;
            }

            $champs = $this->extraireChamps($donnees, $import);

            $existant = PortailFneFactureRecue::where('login', $login)
                ->where('reference', $reference)
                ->first();

            if ($existant) {
                $existant->update($champs);
                $factureModele = $existant;
                $compte['modifies']++;
            } else {
                $factureModele = PortailFneFactureRecue::create(array_merge($champs, [
                    'import_id'            => $import->id,
                    'entreprise_id'        => $import->entreprise_id,
                    'login'                => $login,
                    'date_scraping'        => $import->date_scraping,
                    'statut_rapprochement' => PortailFneFactureRecue::A_RAPPROCHER,
                ]));
                $compte['crees']++;
            }

            if (!empty($donnees['items']) && is_array($donnees['items'])) {
                $factureModele->lignes()->delete();
                $this->rangerLesLignes($factureModele, $donnees['items']);
            }

            $compte['total']++;
        }

        return $compte;
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function rangerLesLignes(PortailFneFactureRecue $facture, array $items): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $taxes = is_array($item['taxes'] ?? null) ? $item['taxes'] : [];

            PortailFneFactureRecueLigne::create([
                'facture_recue_id'  => $facture->id,
                'fne_item_id'       => $this->texte($item['id'] ?? null),
                'reference_article' => $this->texte($item['reference'] ?? null),
                'designation'       => $this->texte($item['description'] ?? null),
                'quantite'          => round((float) ($item['quantity'] ?? 0), 3),
                'unite'             => $this->texte($item['measurementUnit'] ?? null),
                'prix_unitaire'     => round((float) ($item['amount'] ?? 0), 2),
                'remise'            => round((float) ($item['discount'] ?? 0), 2),
                'montant_tva'       => $this->totalDesTaxes($taxes),
                'taxes'             => $taxes ?: null,
                'contenu_brut'      => $item,
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $taxes
     */
    private function totalDesTaxes(array $taxes): float
    {
        $total = 0.0;
        foreach ($taxes as $taxe) {
            if (!is_array($taxe)) {
                continue;
            }
            $total += (float) ($taxe['amount'] ?? 0);
        }
        return round($total, 2);
    }

    private function texte(mixed $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }
        $texte = trim((string) $valeur);
        return $texte === '' ? null : $texte;
    }

    /**
     * Extrait les colonnes d'un enregistrement de l'API.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    private function extraireChamps(array $donnees, PortailFneImport $import): array
    {
        $champs = [];

        // Champs scalaires
        foreach (self::CHAMPS as $cle => $colonne) {
            if (array_key_exists($cle, $donnees)) {
                $champs[$colonne] = $donnees[$cle];
            }
        }

        // Montants
        foreach (self::MONTANTS as $cle => $colonne) {
            if (array_key_exists($cle, $donnees)) {
                $champs[$colonne] = is_numeric($donnees[$cle]) ? (float) $donnees[$cle] : null;
            }
        }

        // Date de la facture
        if (!empty($donnees['date'])) {
            try {
                $champs['date_facture'] = CarbonImmutable::parse($donnees['date']);
            } catch (\Throwable) {
                $champs['date_facture'] = null;
            }
        }

        // Devise et taux
        if (isset($donnees['currency'])) {
            $champs['devise'] = $donnees['currency'];
        }
        if (isset($donnees['exchangeRate'])) {
            $champs['taux_change'] = is_numeric($donnees['exchangeRate']) ? (float) $donnees['exchangeRate'] : null;
        }

        // RNE
        if (isset($donnees['rne'])) {
            $champs['est_rne'] = (bool) $donnees['rne'];
        }

        // Client (destinataire de l'avoir — le bloc `company` côté émetteur)
        // La liste d'où vient l'avoir : émis à un client, ou reçu d'un
        // fournisseur. C'est elle qui décide s'il passe au journal des achats.
        $liste = $donnees['listing_source'] ?? null;
        $champs['liste_portail'] = in_array($liste, ['issued', 'received'], true) ? $liste : null;

        $company = $donnees['company'] ?? $donnees['clientCompany'] ?? [];
        if (is_array($company)) {
            $champs['emetteur_ncc'] = $company['ncc'] ?? $company['taxId'] ?? null;
            $champs['emetteur_nom'] = $company['name'] ?? null;
            $champs['emetteur_id']  = $company['id'] ?? null;
        }

        // Fourre-tout : tout ce qu'on ne sait pas encore utiliser,
        // moins les secrets du destinataire.
        $propre = array_diff_key($donnees, array_flip(self::SECRETS_DU_CLIENT));
        $champs['contenu_brut'] = $propre;

        // PDF
        if (!empty($donnees['file'])) {
            $champs['fichier_pdf'] = $donnees['file'];
        }

        return $champs;
    }

    /* ----------------------------- Les helpers -------------------------------- */

    private function dossierParDefaut(): string
    {
        return rtrim((string) config('selflow.portail_fne.dossier_import'), '/\\')
            . DIRECTORY_SEPARATOR . 'avoirs';
    }

    /**
     * @return array{login: string, date: string}|null
     */
    private function analyserNom(string $nom): ?array
    {
        // Format : <login>_<AAAAMMJJ>.json
        // La découpe se fait au DERNIER `_` : un login peut en contenir un.
        if (!str_ends_with($nom, '.json')) {
            return null;
        }

        $sans = substr($nom, 0, -5); // retire .json
        $pos  = strrpos($sans, '_');

        if ($pos === false || $pos === 0) {
            return null;
        }

        $login = substr($sans, 0, $pos);
        $date  = substr($sans, $pos + 1);

        if ($login === '' || !preg_match('/^\d{8}$/', $date)) {
            return null;
        }

        $dateFormatee = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);

        return ['login' => $login, 'date' => $dateFormatee];
    }

    private function resoudreEntreprise(string $login): ?Entreprise
    {
        $nccPropre = preg_replace('/[^0-9A-Z]/i', '', strtoupper($login));

        return Entreprise::all()->first(function (Entreprise $e) use ($nccPropre) {
            return preg_replace('/[^0-9A-Z]/i', '', strtoupper((string) $e->ncc)) === $nccPropre;
        });
    }

    private function empreinteDuContenu(array $factures): string
    {
        return hash('sha256', json_encode($factures) ?: '');
    }

    private function dernierReleveDeMemeContenu(string $login, string $empreinte): ?PortailFneImport
    {
        return PortailFneImport::where('login', $login)
            ->where('type', self::TYPE)
            ->where('contenu_empreinte', $empreinte)
            ->latest()
            ->first();
    }

    private function confirmerLeReleve(PortailFneImport $import, string $date, ?int $entrepriseId): void
    {
        $mises = ['dernier_releve_le' => $date, 'releves' => $import->releves + 1];
        if ($entrepriseId && !$import->entreprise_id) {
            $mises['entreprise_id'] = $entrepriseId;
        }
        $import->update($mises);
    }

    /**
     * @return array{fichier: string, statut: string, message: string, import_id: int|null, lignes: int}
     */
    private function resultat(
        string $fichier,
        string $statut,
        string $message,
        ?int $importId = null,
        int $lignes = 0
    ): array {
        return compact('fichier', 'statut', 'message') + ['import_id' => $importId, 'lignes' => $lignes];
    }

    /**
     * @param  array<string, mixed>  $contexte
     */
    private function tracer(string $niveau, string $message, array $contexte = []): void
    {
        try {
            Log::channel('portail_fne')->{$niveau}($message, $contexte);
        } catch (\Throwable) {
            // Volontairement muet.
        }
    }
}
