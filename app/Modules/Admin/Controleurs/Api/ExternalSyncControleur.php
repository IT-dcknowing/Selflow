<?php

namespace App\Modules\Admin\Controleurs\Api;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Modules\Admin\Services\TrousseauEntrepriseService;

/**
 * ExternalSyncControleur
 * Endpoint API dédié à la liaison COMPTAFLOW ↔ Selflow.
 * Crée une entreprise + un utilisateur admin depuis une requête externe.
 */
class ExternalSyncControleur
{
    /**
     * Crée une entreprise Selflow depuis une requête externe (ex : COMPTAFLOW).
     * POST /api/external/register-enterprise
     */
    public function enregistrerEntreprise(Request $request): JsonResponse
    {
        // ── Vérification du secret partagé ──
        $providedSecret = $request->input('secret') ?? $request->header('X-Sync-Secret');

        if (!self::secretValide($providedSecret)) {
            Log::warning('ExternalSync Selflow: secret invalide', ['ip' => $request->ip()]);
            return response()->json(['success' => false, 'message' => 'Accès non autorisé.'], 401);
        }

        // ── Validation ──
        $validator = Validator::make($request->all(), [
            'nom'                 => 'required|string|max:150',
            'forme_juridique'     => 'nullable|string|max:50',
            'email'               => 'required|email|max:150',
            'telephone'           => 'nullable|string|max:30',
            'adresse'             => 'nullable|string|max:255',
            'ncc'                 => 'nullable|string|max:50',
            'rccm'                => 'nullable|string|max:100',
            'compte_contribuable' => 'nullable|string|max:100',
            'regime_imposition'   => 'nullable|string|max:80',
            'gerant_nom'          => 'nullable|string|max:100',
            'gerant_prenom'       => 'nullable|string|max:150',
            // Le sens inverse de la liaison : une entreprise qui a Comptaflow
            // et veut Selflow retrouve **les mêmes accès** — même adresse, même
            // mot de passe. Comptaflow envoie l'empreinte de son mot de passe,
            // jamais le mot de passe : l'un des deux suffit, et l'empreinte est
            // préférable.
            'admin_password'      => 'required_without:admin_password_hash|nullable|string|min:8',
            'admin_password_hash' => 'required_without:admin_password|nullable|string|max:255',
            'comptaflow_company_id' => 'nullable|integer',
            'comptaflow_sync_key'   => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // ── Vérifier unicité ──
        if (Entreprise::where('nom', $request->nom)->exists()) {
            return response()->json(['success' => false, 'message' => 'Une entreprise avec ce nom existe déjà.'], 409);
        }
        if (Utilisateur::where('email', $request->email)->exists()) {
            return response()->json(['success' => false, 'message' => 'Un compte avec cet email existe déjà.'], 409);
        }

        DB::beginTransaction();
        try {
            // 1. Créer l'entreprise
            $entreprise = Entreprise::create([
                'nom'                    => $request->nom,
                'forme_juridique'        => $request->forme_juridique ?? 'SARL',
                'gerant_nom'             => $request->gerant_nom,
                'gerant_prenom'          => $request->gerant_prenom,
                'email'                  => $request->email,
                'telephone'              => $request->telephone,
                'adresse'                => $request->adresse,
                'ncc'                    => $request->ncc,
                'rccm'                   => $request->rccm,
                'compte_contribuable'    => $request->compte_contribuable,
                'regime_imposition'      => $request->regime_imposition,
                'quota_points_de_vente'  => 5,
                'plan_abonnement'        => 'Pro',
                // Comptaflow ne connaît pas le domaine d'activité : le poser au
                // hasard le figerait sur une valeur fausse que personne ne
                // penserait à corriger. « Autre » dit ce qu'il en est, et
                // l'entreprise choisit son vrai domaine à la souscription.
                'secteur_activite'       => [\App\Modules\Admin\Modeles\Referentiel\Categorie::AUTRE],
                'modules_actifs'         => ['principal', 'ventes', 'achats', 'stock', 'tiers', 'produits', 'rapports', 'b2b', 'fne'],
                'comptaflow_company_id'  => $request->comptaflow_company_id,
                'comptaflow_sync_status' => 'active',
                'comptaflow_last_sync_at' => now(),
                'comptaflow_liee_le'     => now(),
                'comptaflow_cle_indice'  => $request->comptaflow_sync_key
                    ? substr((string) $request->comptaflow_sync_key, -4)
                    : null,
            ]);

            // La clé n'est pas `$fillable` : elle ne doit jamais entrer par un
            // tableau de requête. Ici, l'appel vient de Comptaflow lui-même,
            // authentifié par le secret serveur, et c'est lui qui l'a générée.
            // On la pose donc explicitement, hors de l'affectation en masse.
            if ($request->filled('comptaflow_sync_key')) {
                $entreprise->comptaflow_sync_key = $request->comptaflow_sync_key;
                $entreprise->save();
            }

            // Sans plan comptable ni journal, la premiere vente s'impute sur des
            // comptes inventes a la volee. L'entreprise recoit donc de quoi
            // travailler des le premier jour ; ce qui ne lui sert pas, elle
            // l'archivera.
            TrousseauEntrepriseService::doter($entreprise);

            // 2. Créer l'utilisateur admin
            $utilisateur = Utilisateur::create([
                'nom'           => $request->gerant_nom ?? 'Admin',
                'prenom'        => $request->gerant_prenom ?? '',
                'email'         => $request->email,
                // L'empreinte arrive telle quelle quand Comptaflow l'envoie :
                // la re-hacher rendrait le compte inaccessible avec le mot de
                // passe que l'utilisateur connaît déjà.
                'password'      => $request->filled('admin_password_hash')
                    ? $request->admin_password_hash
                    : Hash::make($request->admin_password),
                'role'          => 'admin',
                'entreprise_id' => $entreprise->id,
                'statut'        => 'actif',
            ]);

            // Un point de vente « Siège » se créait ici, ville devinée en
            // coupant l'adresse à la première virgule et commune « Plateau ».
            // **Le nom du point de vente est ce que la plateforme de la DGI
            // reçoit** : elle refuse la facture s'il ne correspond à aucun site
            // déclaré sur l'espace FNE. Le créer d'office décidait à la place
            // de l'entreprise du nom sous lequel ses pièces seraient
            // certifiées. Elle crée le sien, et l'écran le lui réclame tant
            // qu'elle ne l'a pas fait.

            DB::commit();

            Log::info('ExternalSync Selflow: entreprise créée depuis COMPTAFLOW', [
                'entreprise_id'   => $entreprise->id,
                'entreprise_nom'  => $entreprise->nom,
            ]);

            return response()->json([
                'success'      => true,
                'company_id'   => $entreprise->id,
                'message'      => 'Entreprise et administrateur créés avec succès dans Selflow.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ExternalSync Selflow: erreur création entreprise', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retourne les informations d'une entreprise Selflow (pour affichage dans COMPTAFLOW hub).
     * POST /api/external/company-info
     */
    public function companyInfo(Request $request): JsonResponse
    {
        $providedSecret = $request->input('secret') ?? $request->header('X-Sync-Secret');

        if (!self::secretValide($providedSecret)) {
            return response()->json(['success' => false, 'message' => 'Accès non autorisé.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'selflow_company_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Données invalides.', 'errors' => $validator->errors()], 422);
        }

        [$porteuse, $refus] = self::entrepriseDeLaCle($request);
        if ($refus) {
            return $refus;
        }

        if ($refus = self::refusDEcritureCroisee($request, $porteuse, (int) $request->selflow_company_id)) {
            return $refus;
        }

        $entreprise = Entreprise::find($request->selflow_company_id);
        if (!$entreprise) {
            return response()->json(['success' => false, 'message' => 'Entreprise introuvable.'], 404);
        }

        // Récupérer l'admin principal
        $admin = Utilisateur::where('entreprise_id', $entreprise->id)
            ->where('role', 'admin')
            ->orderBy('created_at')
            ->first();

        return response()->json([
            'success' => true,
            'company' => [
                'id'                => $entreprise->id,
                'uuid' => $entreprise->uuid,
                'nom'               => $entreprise->nom,
                'rccm'              => $entreprise->rccm ?? null,
                'ncc'               => $entreprise->ncc ?? null,
                'email'             => $entreprise->email ?? null,
                'telephone'         => $entreprise->telephone ?? null,
                'adresse'           => $entreprise->adresse ?? null,
                'regime_imposition' => $entreprise->regime_imposition ?? null,
                'created_at'        => $entreprise->created_at ? $entreprise->created_at->format('d/m/Y') : null,
                'admin_nom'         => $admin ? ($admin->nom . ' ' . $admin->prenom) : null,
                'admin_email'       => $admin ? $admin->email : null,
                'comptaflow_status' => $entreprise->comptaflow_sync_status ?? 'inactive',
            ],
        ]);
    }

    /**
     * Liste toutes les entreprises Selflow (pour le module Liaison SuperAdmin de COMPTAFLOW).
     * POST /api/external/list-companies
     */
    public function listCompanies(Request $request): JsonResponse
    {
        $providedSecret = $request->input('secret') ?? $request->header('X-Sync-Secret');

        if (!self::secretValide($providedSecret)) {
            return response()->json(['success' => false, 'message' => 'Accès non autorisé.'], 401);
        }

        // La clé est facultative ici, et là seulement : cet écran rapproche un
        // dossier qui n'est pas encore lié. Présentée, elle borne la réponse à
        // ce dossier ; absente, la réponse reste ce qu'elle est devenue — un
        // nom et un identifiant, rien de nominatif.
        [$porteuse, $refus] = self::entrepriseDeLaCle($request, cleObligatoire: false);
        if ($refus) {
            return $refus;
        }

        // La liste rendait **toutes** les entreprises de la plateforme avec
        // leur adresse, leur NCC, leur RCCM, le nom de leur gérant et
        // l'adresse électronique de leur administrateur. Un seul secret volé
        // livrait donc l'annuaire complet des clients — et les adresses des
        // comptes les plus puissants de chaque entreprise, de quoi monter un
        // hameçonnage crédible.
        //
        // Cette route sert à **rapprocher** les dossiers, pas à les décrire :
        // le nom et l'identifiant y suffisent. Le détail se demande par
        // `company-info`, qui exige la clé du dossier.
        $requete = Entreprise::query();

        // Une clé désigne une entreprise : elle ne donne à voir que celle-là.
        if ($porteuse) {
            $requete->whereKey($porteuse->id);
        }

        $entreprises = $requete->orderBy('nom')->get()->map(fn ($e) => [
            'id'                => $e->id,
            'uuid'              => $e->uuid,
            'nom'               => $e->nom,
            'created_at'        => $e->created_at?->format('d/m/Y'),
            'is_linked'         => !empty($e->comptaflow_company_id),
            'comptaflow_status' => $e->comptaflow_sync_status ?? 'inactive',
        ]);

        return response()->json([
            'success'     => true,
            'companies'   => $entreprises,
        ]);
    }

    /**
     * Retourne les détails complets d'un tiers (Client/Fournisseur) pour COMPTAFLOW.
     * POST /api/external/tier-info
     */
    public function tierInfo(Request $request): JsonResponse
    {
        $providedSecret = $request->input('secret') ?? $request->header('X-Sync-Secret');

        if (!self::secretValide($providedSecret)) {
            return response()->json(['success' => false, 'message' => 'Accès non autorisé.'], 401);
        }

        [$porteuse, $refusCle] = self::entrepriseDeLaCle($request);
        if ($refusCle) {
            return $refusCle;
        }

        // La fiche d'un tiers porte son téléphone, son adresse et son NCC :
        // c'est le carnet d'adresses commercial de l'entreprise. Le secret
        // partagé, le même pour toutes, ouvrait celui de n'importe laquelle.
        if ($refusCle = self::refusDEcritureCroisee($request, $porteuse, (int) $request->input('selflow_company_id'))) {
            return $refusCle;
        }

        $entrepriseId   = $request->input('selflow_company_id');
        $numeroOriginal = $request->input('numero_original');
        $numeroTiers    = $request->input('numero_de_tiers');
        $intitule       = trim($request->input('intitule', ''));
        $type           = strtolower($request->input('type', ''));

        $tierData = null;
        $isFournisseurPref = str_contains($type, 'fourn') || str_starts_with(strtolower($numeroTiers), '40');

        // 1. Recherche Fournisseur
        $fournisseur = null;
        if ($isFournisseurPref || !$type) {
            if ($numeroOriginal) {
                $fournisseur = \App\Modules\Admin\Modeles\Fournisseur::where('entreprise_id', $entrepriseId)->find($numeroOriginal);
            }
            if (!$fournisseur && $numeroTiers) {
                $fournisseur = \App\Modules\Admin\Modeles\Fournisseur::where('entreprise_id', $entrepriseId)
                    ->where(function($q) use ($numeroTiers) {
                        $q->where('numero_tiers', $numeroTiers)->orWhere('numero_original', $numeroTiers);
                    })->first();
            }
            if (!$fournisseur && $intitule) {
                $fournisseur = \App\Modules\Admin\Modeles\Fournisseur::where('entreprise_id', $entrepriseId)
                    ->where('nom', 'LIKE', "%{$intitule}%")
                    ->first();
            }
        }

        // 2. Recherche Client (si pas trouvé en Fournisseur)
        $client = null;
        if (!$fournisseur) {
            if ($numeroOriginal) {
                $client = \App\Modules\Admin\Modeles\Client::where('entreprise_id', $entrepriseId)->find($numeroOriginal);
            }
            if (!$client && $numeroTiers) {
                $client = \App\Modules\Admin\Modeles\Client::where('entreprise_id', $entrepriseId)
                    ->where(function($q) use ($numeroTiers) {
                        $q->where('numero_tiers', $numeroTiers)->orWhere('numero_original', $numeroTiers);
                    })->first();
            }
            if (!$client && $intitule) {
                $client = \App\Modules\Admin\Modeles\Client::where('entreprise_id', $entrepriseId)
                    ->where('nom', 'LIKE', "%{$intitule}%")
                    ->first();
            }
        }

        if ($fournisseur) {
            $achatsCount = \App\Modules\Admin\Modeles\Achat::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entrepriseId))
                ->where('fournisseur_id', $fournisseur->id)->count();

            $tierData = [
                'type'                => 'Fournisseur',
                'nom'                 => $fournisseur->nom,
                'ncc'                 => $fournisseur->ncc,
                'rccm'                => $fournisseur->rccm,
                'compte_comptable'    => $fournisseur->compte_comptable,
                'compte_general'      => $fournisseur->compte_comptable,
                'numero_original'     => $fournisseur->numero_original ?? $fournisseur->compte_comptable,
                'numero_tiers'        => $fournisseur->numero_tiers,
                'compte_contribuable' => $fournisseur->ncc,
                'regime'              => $fournisseur->regime_imposition,
                'telephone'           => $fournisseur->telephone,
                'email'               => $fournisseur->email,
                'adresse'             => $fournisseur->adresse,
                'secteur_activite'    => $fournisseur->secteur,
                'nombre_achats'       => $achatsCount,
                'created_at'          => $fournisseur->created_at ? $fournisseur->created_at->format('d/m/Y') : null,
            ];
        } elseif ($client) {
            $ventesCount = \App\Modules\Admin\Modeles\Vente::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entrepriseId))
                ->where('client_id', $client->id)->count();

            $tierData = [
                'type'                => 'Client',
                'nom'                 => $client->nom,
                'ncc'                 => $client->ncc,
                'rccm'                => $client->rccm,
                'compte_comptable'    => $client->compte_comptable,
                'compte_general'      => $client->compte_comptable,
                'numero_original'     => $client->numero_original ?? $client->compte_comptable,
                'numero_tiers'        => $client->numero_tiers,
                'compte_contribuable' => $client->ncc,
                'regime'              => $client->regime_imposition,
                'telephone'           => $client->telephone,
                'email'               => $client->email,
                'adresse'             => $client->adresse,
                'nombre_achats'       => $ventesCount,
                'created_at'          => $client->created_at ? $client->created_at->format('d/m/Y') : null,
            ];
        }

        return response()->json([
            'success' => !empty($tierData),
            'tier'    => $tierData,
        ]);
    }

    /**
     * Le secret partage est-il celui attendu ?
     *
     * Deux points fermes ici. La valeur de repli inscrite dans le code
     * — `selflow-comptaflow-secret-2026` — faisait que, si la variable
     * d'environnement n'etait pas renseignee, le secret de production etait
     * celui du depot, public. Un secret absent refuse desormais tout appel.
     *
     * Et la comparaison se faisait avec `!==`, qui s'arrete au premier
     * caractere different : le temps de reponse laissait deviner le secret
     * caractere par caractere. `hash_equals` compare en temps constant.
     */
    private static function secretValide(?string $fourni): bool
    {
        $attendu = config('selflow.comptaflow_api_secret');

        if (empty($attendu) || empty($fourni)) {
            return false;
        }

        return hash_equals((string) $attendu, (string) $fourni);
    }

    /**
     * L'entreprise que la clé de l'en-tête désigne, et le refus s'il y a lieu.
     *
     * ─────────────────────────────────────────────────────────────────────
     * Ce que le secret partagé ne dit pas
     * ─────────────────────────────────────────────────────────────────────
     *
     * Il est le même pour toutes les entreprises, il est détenu par le
     * serveur, et il **ne dit pas qui appelle**. Ces quatre points d'entrée
     * n'avaient que lui : quiconque l'obtenait lisait la fiche de n'importe
     * quelle entreprise, la liste de ses tiers avec leurs coordonnées, et
     * **l'annuaire complet de la plateforme** — noms, adresses électroniques
     * des administrateurs, NCC, RCCM.
     *
     * La clé du dossier, elle, désigne une entreprise et une seule. Quand elle
     * accompagne l'appel, la réponse est bornée à cette entreprise.
     *
     * ─────────────────────────────────────────────────────────────────────
     * 401 et 403 ne disent pas la même chose
     * ─────────────────────────────────────────────────────────────────────
     *
     * Clé inconnue ou révoquée : **401 (Unauthorized — non authentifié)**,
     * l'appelant n'est pas reconnu. Clé valide mais qui désigne une autre
     * entreprise que celle demandée : **403 (Forbidden — accès interdit)**,
     * l'appelant est connu et lit chez quelqu'un d'autre. Les confondre
     * rendrait le journal illisible — et c'est la seconde qu'on veut voir
     * arriver.
     *
     * @return array{0: ?Entreprise, 1: ?JsonResponse}
     */
    private static function entrepriseDeLaCle(Request $request, bool $cleObligatoire = true): array
    {
        $cle = $request->header('X-Company-Key');

        if (blank($cle)) {
            // La tolérance de transition est fermée : l'appel sans clé est
            // refusé. Elle rendait un secret volé suffisant pour lire la fiche
            // et le carnet d'adresses de n'importe quelle entreprise.
            //
            // Fermée **par point d'entrée**, et non en bloc : `list-companies`
            // sert à rapprocher un dossier qui n'est justement pas encore lié,
            // donc il n'y a pas de clé à présenter. La fermer là aussi aurait
            // cassé l'écran superadministrateur de Comptaflow, sans rien
            // protéger de plus : cette route ne rend déjà que le nom et
            // l'identifiant, et le détail se demande par `company-info`, qui
            // exige la clé.
            if (!$cleObligatoire) {
                return [null, null];
            }

            return [null, response()->json([
                'success' => false,
                'message' => "Clé de liaison absente : l'en-tête X-Company-Key est requis.",
            ], 401)];
        }

        // La clé est chiffrée en base : on ne peut pas la chercher par une
        // clause `where`. Le nombre d'entreprises liées reste petit, et
        // l'alternative — un haché en colonne — vaudra le jour où ce ne sera
        // plus vrai.
        $entreprise = Entreprise::whereNotNull('comptaflow_sync_key')
            ->get()
            ->first(fn (Entreprise $e) => filled($e->comptaflow_sync_key)
                && hash_equals((string) $e->comptaflow_sync_key, (string) $cle));

        if (!$entreprise) {
            Log::warning('ExternalSync Selflow : clé de liaison inconnue', ['ip' => $request->ip()]);

            return [null, response()->json([
                'success' => false,
                'message' => 'Clé de liaison inconnue.',
            ], 401)];
        }

        if ($entreprise->comptaflow_revoquee_le !== null) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Clé de liaison révoquée le '
                    . $entreprise->comptaflow_revoquee_le->format('d/m/Y') . '.',
            ], 401)];
        }

        return [$entreprise, null];
    }

    /**
     * La clé présentée autorise-t-elle à parler de cette entreprise-là ?
     */
    private static function refusDEcritureCroisee(Request $request, ?Entreprise $porteuse, int $demandee): ?JsonResponse
    {
        if (!$porteuse || $porteuse->id === $demandee) {
            return null;
        }

        Log::warning('ExternalSync Selflow : lecture croisée refusée', [
            'cle_entreprise'    => $porteuse->id,
            'corps_entreprise'  => $demandee,
            'ip'                => $request->ip(),
        ]);

        return response()->json([
            'success' => false,
            'message' => "La clé de liaison ne désigne pas l'entreprise demandée.",
        ], 403);
    }
}
