<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Emporter les comptes d'un poste vers un autre : le superadministrateur, et
 * le compte d'une entreprise avec sa configuration — sans ses données.
 *
 * ## Pourquoi une commande, et non un seeder
 *
 * Le compte superadmin « ne passait pas en ligne » : les seeders tirent son
 * mot de passe au hasard quand `SUPERADMIN_PASSWORD` manque, et le
 * propriétaire se retrouvait devant un compte dont il ignorait le secret. Un
 * seeder qui porterait le mot de passe l'écrirait dans le dépôt — c'est ce qui
 * avait été retiré de `SeedMassiveCommand`.
 *
 * Ce qui voyage ici est **l'empreinte bcrypt** lue en base, jamais le mot de
 * passe : personne ne le tape, personne ne le lit, et le même mot de passe
 * ouvre le compte des deux côtés.
 *
 * ## Ce qui ne voyage pas, et pourquoi
 *
 * | Écarté | Raison |
 * |---|---|
 * | Clés FNE (`fne_credentials`) | le superadmin la tape lui-même en ligne ; une clé ne se transporte pas dans un fichier |
 * | Liaison Comptaflow (`comptaflow_*`) | propre à chaque environnement, et la clé est chiffrée avec l'`APP_KEY` du poste |
 * | Soldes FNE, stickers | un constat de la DGI à un instant, pas un réglage |
 * | Logos, avatars | des fichiers, que la base ne porte pas |
 * | `jeton_api`, `remember_token` | des secrets de session, propres au poste |
 * | Clients, articles, ventes, écritures | les données : elles se créent en ligne |
 *
 * ## Le fichier
 *
 * Il porte des empreintes de mots de passe. Il est écrit sous `storage/app`,
 * que git ignore, et ne doit jamais être joint à un courriel ni versionné.
 * Une empreinte SHA-256 du contenu permet à l'import de refuser un fichier
 * retouché en route.
 */
class ExporterComptes extends Command
{
    protected $signature = 'selflow:exporter-comptes
        {--entreprise=* : identifiant ou NCC de l\'entreprise à emporter (répétable)}
        {--equipe : emporter aussi les comptes de l\'équipe, et non le seul administrateur}
        {--fichier= : chemin du fichier produit (défaut : storage/app/transfert/comptes-selflow.json)}';

    protected $description = 'Exporte le superadmin et le compte d\'une entreprise (configuration seule) vers un fichier à importer en ligne';

    public const FORMAT = 'selflow-comptes/1';

    /** Le compte de connexion : ce qui ouvre la session, et rien de ce qu'on y a fait. */
    public const COLONNES_UTILISATEUR = [
        'nom', 'prenom', 'email', 'password', 'role', 'fonction', 'statut',
        'habilitations', 'doit_changer_password', 'email_verified_at',
        'date_debut_contrat', 'date_fin_contrat', 'visite_guidee_terminee_le',
    ];

    /** L'identité fiscale et les réglages de l'entreprise. Voir l'en-tête pour ce qui est écarté. */
    public const COLONNES_ENTREPRISE = [
        'nom', 'forme_juridique', 'gerant_nom', 'gerant_prenom', 'gerant_fonction',
        'adresse', 'telephone', 'email', 'rccm', 'compte_contribuable', 'ncc',
        'regime_imposition', 'centre_impots', 'ref_bancaire',
        'quota_points_de_vente', 'plan_abonnement', 'secteur_activite',
        'modules_actifs', 'modules_autorises', 'souscription_etape',
        'souscription_terminee_le', 'activite_autre', 'statut',
        'idu', 'reference_cadastrale', 'proprietaire_local', 'commune', 'quartier',
        'sticker_solde_alerte', 'fne_mode_facturation', 'possede_compte_fne',
        'normalisation_auto_factures', 'normalisation_auto_recus',
        'numerotation_tiers', 'timbre_quittance', 'bapa',
        'pied_de_page_facture', 'facture_autres_mentions',
    ];

    /** Un point de vente inconnu de Selflow, et la FNE refuse la facture : il fait partie du réglage. */
    public const COLONNES_POINT_DE_VENTE = ['nom', 'ville', 'commune', 'responsable', 'telephone', 'statut'];

    public const COLONNES_TAXES = [
        'regime', 'categorie', 'tva_active', 'tva_taux', 'tse_active', 'tse_taux',
        'tdt_active', 'tdt_taux', 'tdt_seuil',
    ];

    public function handle(): int
    {
        $demandees = (array) $this->option('entreprise');

        if ($demandees === []) {
            $this->error('Précisez l\'entreprise à emporter : --entreprise=<identifiant ou NCC>.');
            $this->table(['Identifiant', 'Nom', 'NCC'], Entreprise::query()
                ->orderBy('id')->get(['id', 'nom', 'ncc'])
                ->map(fn ($e) => [$e->id, $e->nom, $e->ncc])->all());

            return self::FAILURE;
        }

        $entreprises = [];
        foreach ($demandees as $designation) {
            $entreprise = Entreprise::query()
                ->where('id', ctype_digit((string) $designation) ? (int) $designation : 0)
                ->orWhere('ncc', $designation)
                ->first();

            if (!$entreprise) {
                $this->error("Aucune entreprise ne répond à « {$designation} ».");

                return self::FAILURE;
            }

            $entreprises[] = $this->emporterEntreprise($entreprise->id, (bool) $this->option('equipe'));
        }

        $superadmins = DB::table('utilisateurs')->where('role', 'superadmin')
            ->orderBy('id')->get(self::colonnes('utilisateurs', self::COLONNES_UTILISATEUR))
            ->map(fn ($ligne) => (array) $ligne)->all();

        if ($superadmins === []) {
            $this->warn('Aucun compte superadmin sur ce poste : le fichier n\'en portera pas.');
        }

        $contenu = [
            'superadmins' => $superadmins,
            'entreprises' => $entreprises,
            // L'adresse de la FNE ne vit pas en base mais dans l'environnement :
            // l'import la compare à celle du serveur, sans rien écrire dans son .env.
            'fne' => [
                'url_sandbox' => config('selflow.fne_api_url_sandbox'),
                'url_production' => config('selflow.fne_api_url_production'),
            ],
        ];

        $fichier = $this->option('fichier') ?: storage_path('app/transfert/comptes-selflow.json');
        File::ensureDirectoryExists(dirname($fichier));
        File::put($fichier, json_encode([
            'format' => self::FORMAT,
            'exporte_le' => now()->toIso8601String(),
            'empreinte' => self::empreinte($contenu),
            'contenu' => $contenu,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($fichier, 0600);

        $this->info("Fichier écrit : {$fichier}");
        $this->table(['Compte', 'Adresse', 'Rôle'], collect($superadmins)
            ->map(fn ($u) => ['superadmin', $u['email'], $u['role']])
            ->concat(collect($entreprises)->flatMap(fn ($e) => collect($e['utilisateurs'])
                ->map(fn ($u) => [$e['entreprise']['nom'], $u['email'], $u['role']])))
            ->all());
        $this->warn('Ce fichier porte les empreintes des mots de passe : ne le joignez à aucun courriel,');
        $this->warn('ne le versionnez pas, et effacez-le dès l\'import fait (--effacer le fait pour vous).');

        return self::SUCCESS;
    }

    /**
     * Le contenu brut, tel que la base le range : un JSON reste une chaîne, une
     * date reste une chaîne. L'import l'écrit à l'identique, sans repasser par
     * les conversions du modèle — qui encoderaient une seconde fois un tableau,
     * ou hacheraient une seconde fois une empreinte.
     */
    private function emporterEntreprise(int $id, bool $equipe): array
    {
        $roles = $equipe ? null : ['admin'];

        return [
            'entreprise' => (array) DB::table('entreprises')->where('id', $id)
                ->first(self::colonnes('entreprises', self::COLONNES_ENTREPRISE)),
            'utilisateurs' => DB::table('utilisateurs')->where('entreprise_id', $id)
                ->when($roles, fn ($q) => $q->whereIn('role', $roles))
                ->where('role', '!=', 'superadmin')
                ->orderBy('id')->get(self::colonnes('utilisateurs', self::COLONNES_UTILISATEUR))
                ->map(fn ($ligne) => (array) $ligne)->all(),
            'points_de_vente' => DB::table('points_de_vente')->where('entreprise_id', $id)
                ->orderBy('id')->get(self::colonnes('points_de_vente', self::COLONNES_POINT_DE_VENTE))
                ->map(fn ($ligne) => (array) $ligne)->all(),
            'taxes' => Schema::hasTable('tax_configurations')
                ? DB::table('tax_configurations')->where('entreprise_id', $id)
                    ->orderBy('id')->get(self::colonnes('tax_configurations', self::COLONNES_TAXES))
                    ->map(fn ($ligne) => (array) $ligne)->all()
                : [],
        ];
    }

    /**
     * Les colonnes demandées que la table porte réellement : un poste en
     * retard d'une migration ne doit pas faire échouer l'export sur une
     * colonne qu'il n'a pas encore.
     */
    public static function colonnes(string $table, array $voulues): array
    {
        return array_values(array_intersect($voulues, Schema::getColumnListing($table)));
    }

    public static function empreinte(array $contenu): string
    {
        return hash('sha256', json_encode($contenu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
