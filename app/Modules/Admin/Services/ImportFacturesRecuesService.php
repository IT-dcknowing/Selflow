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
 * Recueille les factures reçues relevées au portail FNE, et les range en base.
 *
 * ## Ce qu'il lit
 *
 * Un fichier par entreprise, déposé par `SCRAPER-PORTAIL-FNE/achats.js` dans un
 * **sous-dossier** du dossier d'import :
 *
 *     storage/app/portail-fne/achats/<login>_<AAAAMMJJ>.json
 *
 * Le sous-dossier, et non un suffixe dans le nom : la découpe se fait au dernier
 * `_`, qu'un login peut lui-même contenir. Le service d'origine
 * (`ImportPortailFneService`) ne lit que la racine du dossier — les deux chaînes
 * ne se croisent jamais.
 *
 * Le fichier porte l'enveloppe rendue par l'API du portail :
 *
 *     { login, source, periode: {du, au}, colonnes_a_l_ecran, factures: [...] }
 *
 * ## Une facture est un fait, pas un état
 *
 * C'est toute la différence avec les fiches d'entreprise, que le même dossier
 * historise à chaque relevé. Une fiche est une photographie qu'on reprend pour
 * voir ce qui a bougé ; une facture est émise, certifiée, et ne change plus.
 * L'unicité porte donc sur `(login, reference)` — le numéro FNE — et un second
 * relevé met à jour au lieu de dupliquer.
 *
 * ## Ce qu'il ne fait pas
 *
 * **Il ne crée aucun achat, aucune écriture, aucun fournisseur.** Une facture
 * reçue est un constat. La transformer en achat produirait des écritures
 * comptables parce qu'un fichier est arrivé dans un dossier, et doublonnerait
 * très probablement une saisie déjà faite à la main. Le rapprochement se regarde
 * (`PortailFneFactureRecue::rapprochementPropose()`) avant de s'appliquer, et
 * c'est un utilisateur qui l'applique.
 *
 * ## Usage
 *
 *   app(ImportFacturesRecuesService::class)->importerDossier();
 *   app(ImportFacturesRecuesService::class)->importerFichier('C:/…/1864699A_20260827.json');
 */
class ImportFacturesRecuesService
{
    /** Le type porté par la ligne d'import, à côté de `fiche` et `points`. */
    public const TYPE = 'achats';

    /**
     * Les champs de l'API du portail, et la colonne qui les reçoit.
     *
     * La correspondance est explicite plutôt que déduite : un champ renommé au
     * portail doit casser bruyamment ici — ou partir dans `contenu_brut` — et non
     * arriver en silence dans la mauvaise colonne.
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
     * `totalBeforeTaxes` et non `amount` pour le HT : `amount` est le montant de
     * la pièce avant remise, `totalBeforeTaxes` celui qui sert d'assiette. Les
     * confondre fausserait toute imputation bâtie dessus.
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
     * Lit tous les relevés d'achats d'un dossier.
     *
     * @return array{dossier: string, importes: int, ignores: int, inchanges: int, erreurs: int, details: array<int, array<string, mixed>>}
     */
    public function importerDossier(?string $dossier = null): array
    {
        $dossier = $dossier ?: $this->dossierParDefaut();

        $rapport = [
            'dossier'   => $dossier,
            'importes'  => 0,
            'ignores'   => 0,
            'inchanges' => 0,
            'erreurs'   => 0,
            'details'   => [],
        ];

        if (!is_dir($dossier)) {
            // Pas une erreur : le scraper d'achats n'a peut-être jamais tourné,
            // et un dossier absent n'est pas une panne à signaler chaque heure.
            return $rapport;
        }

        $fichiers = glob(rtrim($dossier, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [];

        // Les plus anciens d'abord : le dernier relevé lu doit être le plus
        // récent, sinon l'ordre des lignes en base ment sur l'ordre des faits.
        sort($fichiers);

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
        $dejaVu = PortailFneImport::where('fichier_empreinte', $empreinte)->first();

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

                // Le portail redit ce qu'il disait déjà. On confirme la ligne
                // existante, on n'en crée pas une seconde — même règle que pour
                // les fiches, et pour la même raison : le relevé est quotidien
                // et le portail ne change presque jamais.
                if ($precedent !== null) {
                    $this->confirmerLeReleve($precedent, $date, $entreprise?->id);

                    return $this->resultat(
                        $nom,
                        'inchange',
                        sprintf(
                            'Identique au relevé du %s : %d facture(s) déjà connues.',
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

                $compte = $this->rangerLesFactures($import, $factures);

                $import->update(['lignes_importees' => $compte['total']]);

                $message = sprintf(
                    '%d facture(s) : %d nouvelle(s), %d mise(s) à jour.%s',
                    $compte['total'],
                    $compte['creees'],
                    $compte['modifiees'],
                    $entreprise ? " Rattaché à {$entreprise->nom}." : " NCC {$login} inconnu : conservé sans rattachement."
                );

                return $this->resultat($nom, 'importe', $message, $import->id, $compte['total']);
            });
        } catch (Throwable $e) {
            // La trace du fichier fautif survit à l'échec, hors transaction :
            // sans elle, un fichier illisible se redéposerait indéfiniment sans
            // que rien n'explique pourquoi il n'arrive jamais en base.
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

            Log::error('Import des factures reçues : lecture impossible', [
                'fichier' => $nom,
                'erreur'  => $e->getMessage(),
            ]);

            return $this->resultat($nom, 'erreur', $e->getMessage(), $import->id);
        }
    }

    /* ------------------------------ La lecture -------------------------------- */

    /**
     * Ouvre l'enveloppe déposée par le scraper.
     *
     * Un tableau nu est accepté aussi : si le scraper venait à déposer la liste
     * sans enveloppe, mieux vaut la lire que de rejeter un relevé complet pour
     * une question de forme.
     *
     * @return array{login: string|null, source: string|null, periode: mixed, factures: array<int, array<string, mixed>>}
     */
    private function lire(string $chemin): array
    {
        $brut = json_decode((string) file_get_contents($chemin), true);

        if (!is_array($brut)) {
            throw new \RuntimeException('JSON illisible ou vide.');
        }

        $factures = array_is_list($brut) ? $brut : ($brut['factures'] ?? null);

        // `factures` absent n'est pas la même chose que `factures: []`. Le
        // premier dit que le fichier n'est pas celui qu'on croit ; le second dit
        // que l'entreprise n'a rien reçu, ce qui est une réponse.
        if (!is_array($factures)) {
            throw new \RuntimeException(
                "Le fichier ne porte pas de clé « factures ». Clés trouvées : "
                . implode(', ', array_keys($brut)) . '.'
            );
        }

        return [
            'login'    => $brut['login']   ?? null,
            'source'   => $brut['source']  ?? null,
            'periode'  => $brut['periode'] ?? null,
            'factures' => array_values(array_filter($factures, 'is_array')),
        ];
    }

    /* ------------------------------- Le rangement ----------------------------- */

    /**
     * @param  array<int, array<string, mixed>>  $factures
     * @return array{total: int, creees: int, modifiees: int}
     */
    private function rangerLesFactures(PortailFneImport $import, array $factures): array
    {
        $compte = ['total' => 0, 'creees' => 0, 'modifiees' => 0];

        foreach ($factures as $brute) {
            $reference = trim((string) ($brute['reference'] ?? ''));

            // Une pièce sans numéro FNE n'a pas d'identité : on ne peut ni la
            // reconnaître au relevé suivant, ni détecter qu'elle doublonne une
            // saisie. La signaler vaut mieux que de la ranger sous une clé vide.
            if ($reference === '') {
                Log::warning('Facture reçue sans référence FNE : ignorée', [
                    'fichier' => $import->fichier_nom,
                    'piece'   => $brute['id'] ?? '(sans id)',
                ]);
                continue;
            }

            $valeurs = $this->valeursDeLaFacture($import, $brute);

            $facture = PortailFneFactureRecue::firstOrNew([
                'login'     => $import->login,
                'reference' => $reference,
            ]);

            $existait = $facture->exists;
            $facture->fill($valeurs)->save();

            // Les lignes sont refaites plutôt que rapprochées une à une : le
            // portail ne garantit aucun ordre, et une facture certifiée ne voit
            // pas ses lignes changer. Refaire est plus sûr que réconcilier.
            $facture->lignes()->delete();
            $this->rangerLesLignes($facture, $brute['items'] ?? []);

            $compte['total']++;
            $existait ? $compte['modifiees']++ : $compte['creees']++;
        }

        return $compte;
    }

    /**
     * @param  array<string, mixed>  $brute
     * @return array<string, mixed>
     */
    private function valeursDeLaFacture(PortailFneImport $import, array $brute): array
    {
        $valeurs = [
            'import_id'     => $import->id,
            'entreprise_id' => $import->entreprise_id,
            'date_scraping' => $import->date_scraping,
            'contenu_brut'  => $brute,
        ];

        foreach (self::CHAMPS as $champ => $colonne) {
            $valeur = $brute[$champ] ?? null;
            $valeurs[$colonne] = ($valeur === '' || $valeur === null) ? null : (string) $valeur;
        }

        foreach (self::MONTANTS as $champ => $colonne) {
            $valeurs[$colonne] = round((float) ($brute[$champ] ?? 0), 2);
        }

        $valeurs['est_rne'] = (bool) ($brute['isRne'] ?? false);

        $valeurs['date_facture'] = $this->lireHorodatage($brute['date'] ?? null);

        // L'émetteur : `company` pour un relevé de factures reçues. Le NCC est
        // la clé de rapprochement avec les fournisseurs — sans lui, on ne
        // rapprocherait que sur un nom, ce qu'une pièce fiscale ne souffre pas.
        $emetteur = is_array($brute['company'] ?? null) ? $brute['company'] : [];

        $valeurs['emetteur_ncc']  = $this->texte($emetteur['ncc']  ?? null);
        $valeurs['emetteur_nom']  = $this->texte($emetteur['name'] ?? null);
        $valeurs['emetteur_id']   = $this->texte($emetteur['id']   ?? null);
        $valeurs['emetteur_rccm'] = $this->texte($emetteur['rccm'] ?? null);

        $devise = $this->texte($brute['foreignCurrency'] ?? null);
        $valeurs['devise']      = $devise;
        $valeurs['taux_change'] = $devise ? (float) ($brute['foreignCurrencyRate'] ?? 0) : null;

        // Sans NCC d'émetteur, aucun fournisseur ne peut être retrouvé : la
        // pièce est orpheline, et le dire tout de suite évite qu'un écran la
        // présente comme « à rapprocher » alors que rien ne peut l'être.
        $valeurs['statut_rapprochement'] = $valeurs['emetteur_ncc']
            ? PortailFneFactureRecue::A_RAPPROCHER
            : PortailFneFactureRecue::ORPHELINE;

        return $valeurs;
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
     * Le total des taxes d'une ligne.
     *
     * Le portail rend un tableau dont on ne connaît pas la forme exacte : on
     * additionne ce qui ressemble à un montant, et le détail part **entier**
     * dans `taxes`. Deviner un code TVA de Selflow à partir de là reviendrait à
     * inventer une information fiscale.
     *
     * @param  array<int, mixed>  $taxes
     */
    private function totalDesTaxes(array $taxes): float
    {
        $total = 0.0;

        foreach ($taxes as $taxe) {
            if (!is_array($taxe)) {
                continue;
            }

            foreach (['amount', 'value', 'taxAmount', 'montant'] as $champ) {
                if (isset($taxe[$champ]) && is_numeric($taxe[$champ])) {
                    $total += (float) $taxe[$champ];
                    break;
                }
            }
        }

        return round($total, 2);
    }

    /* ----------------------------- La déduplication --------------------------- */

    /**
     * L'empreinte de ce que le portail dit, et non des octets du fichier.
     *
     * Elle porte sur les factures elles-mêmes, jamais sur l'enveloppe : la
     * période relevée y figure, et l'élargir d'un jour changerait l'empreinte
     * sans qu'aucune facture n'ait bougé.
     *
     * Chaque pièce est ramenée à son numéro FNE et à sa date de mise à jour —
     * une facture certifiée ne change plus, et si le portail la retouche, il le
     * dit là. Les clés sont triées : l'ordre dans lequel l'API les rend n'est
     * pas une information.
     *
     * @param  array<int, array<string, mixed>>  $factures
     */
    private function empreinteDuContenu(array $factures): string
    {
        $canonique = [];

        foreach ($factures as $facture) {
            $reference = trim((string) ($facture['reference'] ?? ''));

            if ($reference === '') {
                continue;
            }

            $canonique[$reference] = [
                'maj'    => (string) ($facture['updatedAt'] ?? ''),
                'statut' => (string) ($facture['status'] ?? ''),
                'du'     => (string) ($facture['totalDue'] ?? ''),
            ];
        }

        ksort($canonique);

        return hash('sha256', json_encode($canonique, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Le dernier relevé d'achats de ce login, s'il disait déjà exactement cela.
     *
     * Le dernier, et non n'importe lequel : une facture annulée puis rétablie
     * ferait revenir un état déjà vu, et le rattacher à la ligne d'origine
     * effacerait le passage par l'état intermédiaire.
     */
    private function dernierReleveDeMemeContenu(string $login, string $contenu): ?PortailFneImport
    {
        $dernier = PortailFneImport::where('login', $login)
            ->where('type', self::TYPE)
            ->where('statut', PortailFneImport::STATUT_IMPORTE)
            ->whereNotNull('contenu_empreinte')
            ->orderByDesc('date_scraping')
            ->orderByDesc('id')
            ->first();

        return $dernier?->contenu_empreinte === $contenu ? $dernier : null;
    }

    /**
     * Confirme un relevé déjà connu, sans rien créer.
     *
     * `date_scraping` n'est pas touchée — elle dit depuis quand le portail
     * affiche cet état. C'est `dernier_releve_le` qui avance, et `releves` ne
     * monte qu'au changement de date : le dossier est relu toutes les heures, et
     * compter chaque relecture ferait dire à ce compteur le nombre de passages
     * du planificateur.
     */
    private function confirmerLeReleve(PortailFneImport $import, CarbonImmutable $date, ?int $entrepriseId): void
    {
        $modifications = [];

        if ($import->dernier_releve_le === null || $import->dernier_releve_le->lt($date)) {
            $modifications['dernier_releve_le'] = $date;
            $modifications['releves']           = $import->releves + 1;
        }

        // Un relevé arrivé avant que l'entreprise n'existe dans Selflow porte un
        // `entreprise_id` nul. Ne plus rien créer quand rien ne change le
        // laisserait orphelin pour toujours.
        if ($entrepriseId !== null && $import->entreprise_id === null) {
            $modifications['entreprise_id'] = $entrepriseId;

            PortailFneFactureRecue::where('login', $import->login)->whereNull('entreprise_id')
                ->update(['entreprise_id' => $entrepriseId]);
        }

        if ($modifications !== []) {
            $import->update($modifications);
        }
    }

    /* -------------------------------- Le détail ------------------------------- */

    private function dossierParDefaut(): string
    {
        return rtrim((string) config('selflow.portail_fne.dossier_import'), '/\\')
            . DIRECTORY_SEPARATOR . 'achats';
    }

    /**
     * Découpe `<login>_<date>.json` — au **dernier** tiret bas.
     *
     * Un login peut en contenir un : `LOGIN_CLIENT_20260827.json` désigne bien le
     * login `LOGIN_CLIENT`. Un nom qui ne suit pas la nomenclature est refusé et
     * signalé, jamais rattaché au hasard : ranger le relevé fiscal d'un client
     * dans le dossier d'un autre ne se répare pas.
     *
     * @return array{login: string, date: CarbonImmutable}|null
     */
    private function analyserNom(string $nom): ?array
    {
        $base = pathinfo($nom, PATHINFO_FILENAME);
        $coupe = strrpos($base, '_');

        if ($coupe === false || $coupe === 0) {
            return null;
        }

        $login = substr($base, 0, $coupe);
        $date  = $this->lireDate(substr($base, $coupe + 1));

        return ($login === '' || $date === null) ? null : ['login' => $login, 'date' => $date];
    }

    private function lireDate(string $valeur): ?CarbonImmutable
    {
        foreach (['Ymd', 'Y-m-d', 'd-m-Y', 'dmY'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $valeur);

                if ($date && $date->format($format) === $valeur) {
                    return $date->startOfDay();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function lireHorodatage(mixed $valeur): ?CarbonImmutable
    {
        if (!is_string($valeur) || trim($valeur) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valeur);
        } catch (Throwable) {
            return null;
        }
    }

    private function texte(mixed $valeur): ?string
    {
        if ($valeur === null || is_array($valeur)) {
            return null;
        }

        $texte = trim((string) $valeur);

        // Le portail écrit « * » pour un champ qu'il n'a pas.
        return ($texte === '' || $texte === '*') ? null : $texte;
    }

    /**
     * L'entreprise qui porte ce NCC.
     *
     * Le NCC s'écrit « 1864699 A » côté Selflow et « 1864699A » au portail : la
     * comparaison ignore tout ce qui ne l'identifie pas.
     */
    private function resoudreEntreprise(string $login): ?Entreprise
    {
        $recherche = PortailFneFactureRecue::nccComparable($login);

        if ($recherche === '') {
            return null;
        }

        return Entreprise::query()
            ->whereNotNull('ncc')
            ->get()
            ->first(fn (Entreprise $e) => PortailFneFactureRecue::nccComparable($e->ncc) === $recherche);
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
        return [
            'fichier'   => $fichier,
            'statut'    => $statut,
            'message'   => $message,
            'import_id' => $importId,
            'lignes'    => $lignes,
        ];
    }
}
