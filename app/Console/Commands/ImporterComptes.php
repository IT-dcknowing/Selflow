<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Services\TrousseauEntrepriseService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Poser en ligne ce que `selflow:exporter-comptes` a emporté du poste local.
 *
 * Chaque compte se retrouve par son adresse, chaque entreprise par son NCC —
 * à défaut son adresse, à défaut son nom —, chaque point de vente par son
 * nom : relancer l'import met à jour, il ne duplique rien.
 *
 * Tout passe dans une transaction : un fichier qui échoue à mi-chemin ne
 * laisse pas un superadmin posé et une entreprise à moitié écrite. Sous
 * `--simuler`, la transaction est annulée et le rapport reste.
 *
 * La clé FNE n'est pas dans le fichier : elle se tape à l'écran du
 * superadmin, qui seul gère la configuration FNE.
 */
class ImporterComptes extends Command
{
    protected $signature = 'selflow:importer-comptes
        {fichier : le fichier produit par selflow:exporter-comptes}
        {--vers= : identifiant, sur ce serveur, de l\'entreprise à mettre à jour (quand plusieurs se ressemblent)}
        {--simuler : tout vérifier et tout rapporter, sans rien écrire}
        {--effacer : effacer le fichier une fois l\'import réussi}';

    protected $description = 'Importe le superadmin et le compte d\'une entreprise exportés par selflow:exporter-comptes';

    private array $rapport = [];

    public function handle(): int
    {
        $fichier = $this->argument('fichier');

        if (!File::exists($fichier)) {
            $this->error("Fichier introuvable : {$fichier}");

            return self::FAILURE;
        }

        $paquet = json_decode(File::get($fichier), true);

        if (!is_array($paquet) || ($paquet['format'] ?? null) !== ExporterComptes::FORMAT || !is_array($paquet['contenu'] ?? null)) {
            $this->error('Ce fichier n\'a pas été produit par selflow:exporter-comptes.');

            return self::FAILURE;
        }

        // Le fichier porte des empreintes de mots de passe et des rôles : une
        // ligne retouchée en route ferait d'un compte quelconque un superadmin.
        if (!hash_equals((string) ($paquet['empreinte'] ?? ''), ExporterComptes::empreinte($paquet['contenu']))) {
            $this->error('Le contenu du fichier ne correspond plus à son empreinte : il a été modifié depuis l\'export. Rien n\'est écrit.');

            return self::FAILURE;
        }

        $contenu = $paquet['contenu'];

        DB::beginTransaction();

        try {
            foreach ($contenu['superadmins'] ?? [] as $ligne) {
                $this->poserUtilisateur($ligne, null);
            }

            foreach ($contenu['entreprises'] ?? [] as $bloc) {
                $this->poserEntreprise($bloc);
            }

            if ($this->option('simuler')) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Import interrompu, rien n\'est écrit : ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Élément', 'Désignation', 'Action'], $this->rapport);
        $this->verifierLaFne($contenu['fne'] ?? []);

        if ($this->option('simuler')) {
            $this->warn('Simulation : rien n\'a été écrit.');

            return self::SUCCESS;
        }

        if ($this->option('effacer')) {
            File::delete($fichier);
            $this->info('Fichier effacé.');
        } else {
            $this->warn("Effacez le fichier maintenant qu'il a servi : {$fichier}");
        }

        $this->info('Reste à faire : se connecter en superadmin et saisir la clé FNE de test de l\'entreprise.');

        return self::SUCCESS;
    }

    private function poserEntreprise(array $bloc): void
    {
        $colonnes = self::garder('entreprises', $bloc['entreprise'] ?? [], ExporterComptes::COLONNES_ENTREPRISE);

        $entreprise = $this->retrouverEntreprise($colonnes);

        $nouvelle = !$entreprise;
        $entreprise = self::ecrireBrut($entreprise ?? new Entreprise(), $colonnes);
        $this->rapport[] = ['Entreprise', $entreprise->nom, $nouvelle ? 'créée' : 'mise à jour'];

        // Le plan comptable et les journaux sont le réglage de départ que toute
        // inscription pose. Le service n'écrase rien de ce qui existe déjà.
        $dote = TrousseauEntrepriseService::doter($entreprise);
        $this->rapport[] = ['Plan et journaux', $entreprise->nom, "{$dote['comptes']} comptes, {$dote['journaux']} journaux posés"];

        foreach ($bloc['utilisateurs'] ?? [] as $ligne) {
            $this->poserUtilisateur($ligne, $entreprise->id);
        }

        foreach ($bloc['points_de_vente'] ?? [] as $ligne) {
            $colonnesPdv = self::garder('points_de_vente', $ligne, ExporterComptes::COLONNES_POINT_DE_VENTE);
            $point = PointDeVente::query()->where('entreprise_id', $entreprise->id)
                ->where('nom', $colonnesPdv['nom'] ?? '')->first();
            $nouveau = !$point;
            self::ecrireBrut($point ?? new PointDeVente(), $colonnesPdv + ['entreprise_id' => $entreprise->id]);
            $this->rapport[] = ['Point de vente', $colonnesPdv['nom'] ?? '?', $nouveau ? 'créé' : 'mis à jour'];
        }

        foreach ($bloc['taxes'] ?? [] as $ligne) {
            if (!Schema::hasTable('tax_configurations')) {
                break;
            }
            $colonnesTaxes = self::garder('tax_configurations', $ligne, ExporterComptes::COLONNES_TAXES);
            $existe = DB::table('tax_configurations')->where('entreprise_id', $entreprise->id)
                ->where('regime', $colonnesTaxes['regime'] ?? null)->exists();
            DB::table('tax_configurations')->updateOrInsert(
                ['entreprise_id' => $entreprise->id, 'regime' => $colonnesTaxes['regime'] ?? null],
                $colonnesTaxes + ['updated_at' => now()] + ($existe ? [] : ['created_at' => now()])
            );
            $this->rapport[] = ['Taxes', (string) ($colonnesTaxes['regime'] ?? '?'), $existe ? 'mises à jour' : 'créées'];
        }
    }

    /**
     * L'entreprise du fichier, sur ce serveur — ou `null` s'il faut la créer.
     *
     * Ni le NCC, ni l'adresse, ni le nom ne sont uniques : des entreprises
     * d'essai reprennent le NCC d'une vraie. Prendre la première venue
     * écrasait la fiche d'une autre entreprise et y rattachait l'administrateur.
     * Un critère qui désigne plusieurs entreprises est donc départagé par le
     * nom ; s'il en reste plusieurs, l'import s'arrête et les nomme, et
     * `--vers=` tranche.
     */
    private function retrouverEntreprise(array $colonnes): ?Entreprise
    {
        if (filled($this->option('vers'))) {
            return Entreprise::findOrFail((int) $this->option('vers'));
        }

        foreach (['ncc', 'email', 'nom'] as $cle) {
            if (blank($colonnes[$cle] ?? null)) {
                continue;
            }

            $candidates = Entreprise::query()->where($cle, $colonnes[$cle])->orderBy('id')->get();

            if ($candidates->count() > 1 && filled($colonnes['nom'] ?? null)) {
                $memeNom = $candidates->where('nom', $colonnes['nom']);
                $candidates = $memeNom->isNotEmpty() ? $memeNom : $candidates;
            }

            if ($candidates->count() > 1) {
                throw new \RuntimeException(sprintf(
                    'plusieurs entreprises de ce serveur répondent au %s « %s » : %s. Relancez avec --vers=<identifiant>.',
                    $cle,
                    $colonnes[$cle],
                    $candidates->map(fn ($e) => "n° {$e->id} {$e->nom}")->implode(', ')
                ));
            }

            if ($candidates->count() === 1) {
                return $candidates->first();
            }
        }

        return null;
    }

    /**
     * Un compte se retrouve par son adresse. Son empreinte de mot de passe est
     * écrite telle quelle : c'est ce qui rend le même mot de passe valable ici.
     *
     * Un compte existant sous cette adresse change d'entreprise si le fichier
     * le dit : c'est le poste local qui fait foi pour les comptes qu'il envoie.
     */
    private function poserUtilisateur(array $ligne, ?int $entrepriseId): void
    {
        $colonnes = self::garder('utilisateurs', $ligne, ExporterComptes::COLONNES_UTILISATEUR);

        if (blank($colonnes['email'] ?? null) || blank($colonnes['password'] ?? null)) {
            $this->rapport[] = ['Compte', $colonnes['email'] ?? '?', 'écarté : adresse ou mot de passe absent'];

            return;
        }

        $compte = Utilisateur::query()->where('email', $colonnes['email'])->first();
        $nouveau = !$compte;

        self::ecrireBrut($compte ?? new Utilisateur(), $colonnes + [
            'entreprise_id' => $entrepriseId,
            'point_de_vente_id' => null,
        ]);

        $this->rapport[] = [$colonnes['role'] === 'superadmin' ? 'Superadmin' : 'Compte', $colonnes['email'], $nouveau ? 'créé' : 'mis à jour, mot de passe aligné'];
    }

    /**
     * Écrire les valeurs telles que la base les range, sans les conversions du
     * modèle : le cast `hashed` refuserait ou re-hacherait l'empreinte, le cast
     * `array` encoderait une seconde fois un JSON déjà encodé. Le modèle reste
     * utilisé pour ses événements — l'`uuid` se pose à la création.
     */
    private static function ecrireBrut(Model $modele, array $valeurs): Model
    {
        $modele->setRawAttributes(array_merge($modele->getAttributes(), $valeurs));
        $modele->save();

        return $modele;
    }

    /**
     * Seulement les colonnes prévues et présentes sur ce serveur : un fichier
     * ne choisit pas les colonnes qu'il écrit.
     */
    private static function garder(string $table, array $ligne, array $prevues): array
    {
        return array_intersect_key($ligne, array_flip(ExporterComptes::colonnes($table, $prevues)));
    }

    private function verifierLaFne(array $fne): void
    {
        $ici = config('selflow.fne_api_url_sandbox');
        $la = $fne['url_sandbox'] ?? null;

        if ($la && $la !== $ici) {
            $this->warn("Adresse FNE de test : le poste local visait {$la}, ce serveur vise {$ici}.");
            $this->warn("Posez FNE_API_URL_SANDBOX={$la} dans le .env du serveur, puis php artisan config:clear.");
        } else {
            $this->info("Adresse FNE de test : {$ici} — la même que sur le poste local.");
        }
    }
}
