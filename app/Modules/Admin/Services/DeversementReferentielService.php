<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PlanComptable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le référentiel d'une entreprise part vers Comptaflow.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Le sens de la passerelle
 * ─────────────────────────────────────────────────────────────────────────
 *
 * **Selflow déverse ; Comptaflow reçoit.** Chaque entreprise de Selflow verse
 * ses données dans Comptaflow comme on y verserait un fichier d'import, et
 * Comptaflow ne les accepte que si la liaison existe.
 *
 * Le code faisait exactement l'inverse. `synchroniserDepuisComptaflow()`
 * appelait Comptaflow, **recevait** son plan comptable, ses codes journaux et
 * ses tiers, les recopiait dans Selflow — puis **supprimait** toute ligne
 * Selflow marquée `source = comptaflow` qui ne figurait pas dans la réponse.
 * Autrement dit : Selflow se construisait sur Comptaflow, et une entreprise
 * dont le comptable n'avait pas encore rempli son plan se retrouvait dépouillée
 * du sien.
 *
 * C'est le contraire de l'architecture voulue. Selflow a ses propres comptes,
 * ses propres journaux et ses propres tiers dès le premier jour — le trousseau
 * les pose à la création de l'entreprise, sans rien demander à personne. Une
 * entreprise sans abonnement comptable doit pouvoir travailler seule.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Ce qui est envoyé
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Trois jeux, dans l'ordre où Comptaflow en a besoin : le plan comptable, les
 * codes journaux, puis les tiers — un tiers renvoie à son compte général, qui
 * doit donc exister avant lui.
 *
 * Les champs reprennent **exactement les colonnes des modèles d'import de
 * Comptaflow** — `N° compte` / `Intitulé du compte`, `Code` / `Intitulé` /
 * `Type`, `N° tiers` / `Intitulé du tiers` / `Type` — de sorte que le
 * déversement emprunte la logique d'import déjà écrite plutôt qu'une seconde
 * voie à maintenir. Ce que ces colonnes ne portent pas — le téléphone,
 * l'adresse, le numéro de contribuable d'un tiers — voyage à côté : Comptaflow
 * peut le conserver et le donner à consulter sans le faire passer par aucun
 * contrôle, puisque rien de comptable n'en dépend.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Ce qui n'est pas envoyé
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Les comptes et les journaux archivés. Un compte archivé ne doit plus
 * recevoir d'écriture ; le déverser le ferait réapparaître dans les listes de
 * Comptaflow, où plus personne ne saurait pourquoi il est là.
 */
class DeversementReferentielService
{
    /** Combien de temps attendre Comptaflow, en secondes. */
    private const DELAI = 25;

    /**
     * Déverser le référentiel d'une entreprise vers Comptaflow.
     *
     * @return array{success: bool, message: string, bilan?: array<string, int>}
     */
    public static function deverser(Entreprise $entreprise): array
    {
        if (empty($entreprise->comptaflow_sync_key)) {
            return ['success' => false, 'message' => "La clé de synchronisation n'est pas configurée."];
        }

        if (!config('selflow.comptaflow_api_secret')) {
            return ['success' => false, 'message' => "Le secret partagé n'est pas configuré (EXTERNAL_SYNC_SECRET)."];
        }

        try {
            // La liaison peut déjà être ouverte : `LiaisonComptaflowService`
            // la fait établir par Comptaflow lui-même à la validation de la
            // demande, et en rapporte l'identifiant du dossier. La rouvrir à
            // chaque déversement était un appel pour rien.
            if (empty($entreprise->comptaflow_company_id)) {
                $liaison = self::etablirLaLiaison($entreprise);

                if (!$liaison['success']) {
                    return $liaison;
                }
            }

            return self::envoyerLeReferentiel($entreprise);
        } catch (\Exception $e) {
            Log::error('Déversement du référentiel vers Comptaflow : ' . $e->getMessage());

            return ['success' => false, 'message' => 'Erreur de connexion : ' . $e->getMessage()];
        }
    }

    /**
     * Ouvrir la liaison, et retenir l'identifiant que Comptaflow attribue.
     *
     * C'est le seul appel dont on lit la réponse, et l'on n'y lit qu'une
     * chose : l'identifiant de l'entreprise chez Comptaflow. Le plan
     * comptable, les journaux et les tiers que cette réponse contient encore
     * sont **ignorés** — les absorber, c'était se construire sur Comptaflow.
     *
     * @return array{success: bool, message: string}
     */
    private static function etablirLaLiaison(Entreprise $entreprise): array
    {
        $reponse = Http::timeout(self::DELAI)->withHeaders(
            LiaisonComptaflowService::enTete($entreprise)
        )->post(
            self::url('/api/external/link-company'),
            [
                'secret'             => config('selflow.comptaflow_api_secret'),
                'selflow_sync_key'   => $entreprise->comptaflow_sync_key,
                'selflow_company_id' => $entreprise->id,
            ]
        );

        if (!$reponse->successful() || !$reponse->json('success')) {
            $entreprise->update(['comptaflow_sync_status' => 'failed']);

            return [
                'success' => false,
                'message' => $reponse->json('message') ?? 'Clé de synchronisation invalide.',
            ];
        }

        $entreprise->update([
            'comptaflow_company_id'   => $reponse->json('company_id'),
            'comptaflow_sync_status'  => 'active',
            'comptaflow_last_sync_at' => now(),
        ]);

        return ['success' => true, 'message' => 'Liaison établie.'];
    }

    /**
     * @return array{success: bool, message: string, bilan?: array<string, int>}
     */
    private static function envoyerLeReferentiel(Entreprise $entreprise): array
    {
        $comptes = self::planComptable($entreprise);
        $journaux = self::codesJournaux($entreprise);
        $tiers = self::tiers($entreprise);

        // La clé du dossier voyage en en-tête : c'est elle qui dit à
        // Comptaflow **quelle entreprise** appelle. Le secret partagé ne le
        // dit pas — il est le même pour toutes, et un secret volé suffisait
        // à écrire dans les livres de n'importe qui.
        $reponse = Http::timeout(self::DELAI)->withHeaders(
            LiaisonComptaflowService::enTete($entreprise)
        )->post(
            self::url('/api/external/referentiel/deverser'),
            [
                'secret'                => config('selflow.comptaflow_api_secret'),
                'selflow_company_id'    => $entreprise->id,
                'comptaflow_company_id' => $entreprise->comptaflow_company_id,
                'plan_comptable'        => $comptes,
                'codes_journaux'        => $journaux,
                'tiers'                 => $tiers,
            ]
        );

        if (!$reponse->successful() || !$reponse->json('success')) {
            return [
                'success' => false,
                'message' => $reponse->json('message')
                    ?? 'Comptaflow a refusé le référentiel (code ' . $reponse->status() . ').',
            ];
        }

        $entreprise->update(['comptaflow_last_sync_at' => now()]);

        $bilan = [
            'comptes'  => count($comptes),
            'journaux' => count($journaux),
            'tiers'    => count($tiers),
        ];

        return [
            'success' => true,
            'bilan'   => $bilan,
            'message' => sprintf(
                'Référentiel déversé dans Comptaflow : %d compte(s), %d journal/journaux, %d tiers.',
                $bilan['comptes'], $bilan['journaux'], $bilan['tiers']
            ),
        ];
    }

    /**
     * Le plan comptable de l'entreprise, hors comptes archivés.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function planComptable(Entreprise $entreprise): array
    {
        return PlanComptable::where('entreprise_id', $entreprise->id)
            ->whereNull('archive_le')
            ->orderBy('numero')
            ->get()
            ->map(fn (PlanComptable $compte) => [
                // Les deux colonnes du modèle `modele_plan_comptable.xlsx`.
                'numero_de_compte' => $compte->numero,
                'intitule'         => $compte->libelle,
                // Le numéro d'origine, quand le compte vient d'un import
                // antérieur : il permet à Comptaflow de reconnaître le sien.
                'numero_original'  => $compte->numero_original,
            ])
            ->values()
            ->all();
    }

    /**
     * Les journaux de l'entreprise, hors journaux archivés.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function codesJournaux(Entreprise $entreprise): array
    {
        return CodeJournal::where('entreprise_id', $entreprise->id)
            ->whereNull('archive_le')
            ->orderBy('code')
            ->get()
            ->map(fn (CodeJournal $journal) => [
                // Les trois colonnes du modèle `modele_codes_journaux.xlsx`.
                'code_journal'    => $journal->code,
                'intitule'        => $journal->intitule,
                'type'            => self::typeComptaflow($journal->type),
                // Le compte de contrepartie d'un journal de trésorerie.
                'compte_numero'   => $journal->compte,
                'numero_original' => $journal->numero_original,
            ])
            ->values()
            ->all();
    }

    /**
     * Les clients et les fournisseurs, tiers de passage compris.
     *
     * Le tiers « divers » part comme les autres : c'est lui qui porte les
     * ventes de comptoir, et sans lui elles retomberaient chez Comptaflow sur
     * le seul compte collectif.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tiers(Entreprise $entreprise): array
    {
        $tiers = [];

        foreach (Client::where('entreprise_id', $entreprise->id)->orderBy('numero_tiers')->get() as $client) {
            $tiers[] = self::unTiers(
                $client->numero_tiers,
                $client->nom,
                'client',
                ImputationService::compteClient($client),
                $client
            );
        }

        foreach (Fournisseur::where('entreprise_id', $entreprise->id)->orderBy('numero_tiers')->get() as $fournisseur) {
            $tiers[] = self::unTiers(
                $fournisseur->numero_tiers,
                $fournisseur->nom,
                'fournisseur',
                ImputationService::compteFournisseur($fournisseur),
                $fournisseur
            );
        }

        // Un tiers sans numéro ne se rattache à rien : Comptaflow le
        // rangerait sous une chaîne vide, où il resterait introuvable.
        return array_values(array_filter($tiers, fn ($t) => $t['numero_de_tiers'] !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function unTiers(?string $numero, ?string $nom, string $type, string $compteGeneral, $modele): array
    {
        return [
            // Les trois colonnes du modèle `modele_plan_tiers.xlsx` : ce sont
            // elles, et elles seules, qui passent la logique d'import.
            'numero_de_tiers' => trim((string) $numero),
            'intitule'        => trim((string) $nom),
            'type_de_tiers'   => $type,

            // Le compte de rattachement. `plan_tiers.compte_general` est
            // `NOT NULL` chez Comptaflow avec une clé étrangère : sans lui,
            // l'insertion échoue sur une violation d'intégrité.
            'compte_general'  => $compteGeneral,

            // Ce que les colonnes d'import ne portent pas. Rien de comptable
            // n'en dépend : Comptaflow peut le conserver et le donner à
            // consulter sans le soumettre à aucun contrôle.
            'informations'    => array_filter([
                'telephone'         => $modele->telephone ?? null,
                'email'             => $modele->email ?? null,
                'adresse'           => $modele->adresse ?? null,
                'ncc'               => $modele->ncc ?? null,
                'rccm'              => $modele->rccm ?? null,
                'regime_imposition' => $modele->regime_imposition ?? null,
            ], fn ($v) => $v !== null && $v !== ''),

            'numero_original' => $modele->numero_original ?? (string) $modele->id,
        ];
    }

    /**
     * Le vocabulaire des types de journaux, tel que Comptaflow le nomme.
     *
     * Selflow dit « Vente », Comptaflow dit « Ventes ». La traduction se
     * faisait déjà à l'entrée, dans l'ancien sens ; elle se fait ici à la
     * sortie.
     */
    private static function typeComptaflow(?string $type): string
    {
        return match ($type) {
            'Vente'      => 'Ventes',
            'Achat'      => 'Achats',
            'Trésorerie',
            'Banque',
            'Caisse'     => 'Trésorerie',
            default      => 'Autre',
        };
    }

    private static function url(string $chemin): string
    {
        return rtrim(config('selflow.comptaflow_api_url', 'http://127.0.0.1:8000'), '/') . $chemin;
    }
}
