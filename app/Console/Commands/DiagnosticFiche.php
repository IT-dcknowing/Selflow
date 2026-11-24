<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ImportPortailFneService;
use App\Modules\Admin\Services\ScraperPortailFneService;
use App\Modules\Admin\Services\SynchronisationPortailFneService;
use Illuminate\Console\Command;

/**
 * Pourquoi la fiche d'une entreprise ne se remplit pas depuis son espace FNE
 * (24/11/2026, SAS'T : IDU, commune, quartier… restés vides).
 *
 *     php artisan selflow:diagnostic-fiche 2196217J
 *     php artisan selflow:diagnostic-fiche 2196217J --appliquer
 *
 * Suit la chaîne de bout en bout : accès du robot, dernière connexion, fichiers
 * déposés, relevés rangés, valeurs de la fiche du portail face à l'entreprise.
 * `--appliquer` range les fichiers en attente puis reprend la fiche.
 */
class DiagnosticFiche extends Command
{
    protected $signature = 'selflow:diagnostic-fiche {ncc : NCC (login du portail)} {--appliquer : ranger les fichiers déposés et reprendre la fiche dans l\'entreprise}';

    protected $description = 'Suit, pour un NCC, la fiche de l\'espace FNE du robot jusqu\'aux paramètres de l\'entreprise.';

    private const CHAMPS = ['email', 'telephone', 'adresse', 'commune', 'quartier', 'idu', 'reference_cadastrale',
        'proprietaire_local', 'ref_bancaire', 'pied_de_page_facture', 'facture_autres_mentions', 'sticker_solde_alerte', 'timbre_quittance', 'bapa'];

    public function handle(): int
    {
        $ncc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $this->argument('ncc')));

        $this->info("1. Entreprise");
        $entreprise = Entreprise::whereRaw("UPPER(REPLACE(ncc, ' ', '')) = ?", [$ncc])->first()
            ?? Entreprise::find(FneCredential::whereRaw('UPPER(ncc_associe) = ?', [$ncc])->value('entreprise_id'));
        $this->line($entreprise
            ? "   #{$entreprise->id} {$entreprise->nom} (compte FNE : " . var_export($entreprise->possede_compte_fne, true) . ')'
            : '   AUCUNE entreprise ne porte ce NCC : les relevés restent sans rattachement.');

        $this->info("2. Accès du robot");
        $acces = FneCredential::whereRaw('UPPER(ncc_associe) = ?', [$ncc])->first();
        if (!$acces) {
            $this->warn('   Aucun accès FNE enregistré pour ce NCC : le robot ne peut pas se connecter.');
        } else {
            $this->line('   mot de passe : ' . (filled($acces->acces_mot_de_passe) ? 'enregistré' : 'MANQUANT'));
            $this->line('   relevé autorisé (telechargement_actif) : ' . ($acces->telechargement_actif ? 'oui' : 'NON'));
        }
        $this->line('   scraper actif (PORTAIL_FNE_SCRAPER_ACTIF) : ' . (config('selflow.portail_fne.scraper.actif') ? 'oui' : 'NON'));

        $this->info("3. Dernière connexion du robot");
        $etat = ScraperPortailFneService::etatConnexion($ncc);
        $this->line($etat
            ? '   ' . ($etat['quand'] ?? '?') . ' : ' . ($etat['statut'] ?? '?') . ' — ' . ($etat['message'] ?? '') . ' (portail : ' . ($etat['portail'] ?? '?') . ')'
            : '   Aucune trace : le robot de la fiche (fne.js) n\'a jamais tourné pour ce NCC.');

        $this->info("3 bis. Journal du robot de la fiche (fne.js)");
        foreach ($this->journalDuRobot($ncc) as $ligne) {
            $this->line('   ' . mb_strimwidth($ligne, 0, 220, '…'));
        }
        $captures = glob(base_path('SCRAPER-PORTAIL-FNE/erreurs/' . $ncc . '_*')) ?: [];
        foreach (array_slice($captures, -3) as $c) {
            $this->warn('   capture d\'erreur : ' . $c);
        }

        $this->info("4. Fichiers déposés (" . config('selflow.portail_fne.dossier_import') . ')');
        $fichiers = glob(rtrim((string) config('selflow.portail_fne.dossier_import'), '/') . '/' . $ncc . '_*.{json,xlsx}', GLOB_BRACE) ?: [];
        foreach (array_slice($fichiers, -6) as $f) {
            $deja = PortailFneImport::where('fichier_empreinte', hash_file('sha256', $f))->exists();
            $this->line('   ' . basename($f) . ' — ' . date('d/m/Y H:i', filemtime($f)) . ($deja ? ' (rangé)' : ' (PAS ENCORE RANGÉ)'));
        }
        if ($fichiers === []) {
            $this->warn('   Aucun fichier : le robot n\'a rien déposé pour ce NCC.');
        }

        $this->info("5. Relevés de la fiche rangés");
        $imports = PortailFneImport::where('login', $ncc)->where('type', PortailFneImport::TYPE_FICHE)->latest('id')->limit(5)->get();
        foreach ($imports as $i) {
            $this->line("   #{$i->id} {$i->fichier_nom} — {$i->statut}" . ($i->entreprise_id ? " — entreprise #{$i->entreprise_id}" : ' — NON RATTACHÉ') . ($i->message ? " — {$i->message}" : ''));
        }
        if ($imports->isEmpty()) {
            $this->warn('   Aucun.');
        }

        if ($this->option('appliquer')) {
            $this->info('→ Rangement du dossier d\'import…');
            $rapport = app(ImportPortailFneService::class)->importerDossier();
            $this->line("   importés {$rapport['importes']}, inchangés {$rapport['inchanges']}, déjà lus {$rapport['ignores']}, erreurs {$rapport['erreurs']}");
            $entreprise?->refresh();
        }

        $this->info("6. Fiche du portail face à l'entreprise");
        $fiche = PortailFneFiche::where('login', $ncc)->orderByDesc('date_scraping')->orderByDesc('id')->first();
        if (!$fiche) {
            $this->warn('   Aucune fiche rangée pour ce NCC.');
        } else {
            $this->line('   relevé du ' . $fiche->date_scraping?->format('d/m/Y') . ($fiche->entreprise_id ? '' : ' — NON RATTACHÉ à une entreprise'));
            $this->table(['Champ', 'Portail FNE', 'Selflow'], collect(self::CHAMPS)->map(fn ($c) => [
                $c,
                $this->montrer($fiche->{$c}),
                $entreprise ? $this->montrer($entreprise->{$c}) : '-',
            ])->all());
            if ($fiche->champs_inconnus) {
                $this->warn('   Libellés du portail non reconnus : ' . implode(', ', array_keys((array) $fiche->champs_inconnus)));
            }
        }

        if ($this->option('appliquer') && $entreprise) {
            if ($fiche && !$fiche->entreprise_id) {
                PortailFneFiche::where('login', $ncc)->whereNull('entreprise_id')->update(['entreprise_id' => $entreprise->id]);
            }
            $changes = SynchronisationPortailFneService::reprendre($entreprise->fresh());
            $this->info('→ Repris dans l\'entreprise : ' . ($changes ? implode(', ', array_keys($changes)) : 'rien de nouveau'));
        }

        return self::SUCCESS;
    }

    /**
     * Les dernières lignes du robot de la fiche pour ce NCC, lues dans
     * laravel.log (fne.js y écrit sur le serveur Linux).
     *
     * @return array<int, string>
     */
    private function journalDuRobot(string $ncc): array
    {
        $fichier = storage_path('logs/laravel.log');
        if (!is_file($fichier)) {
            return ['(laravel.log introuvable)'];
        }
        $taille = filesize($fichier);
        $h = fopen($fichier, 'r');
        fseek($h, max(0, $taille - 3_000_000));
        $texte = (string) stream_get_contents($h);
        fclose($h);
        $lignes = preg_split('/\R/', $texte) ?: [];

        $retenues = [];
        $dansLeBloc = false;
        foreach ($lignes as $ligne) {
            if (str_contains($ligne, "-- {$ncc} --")) {
                $retenues = [];
                $dansLeBloc = true;
                continue;
            }
            if (str_contains($ligne, "fne[{$ncc}]")) {
                $retenues[] = trim($ligne);
                continue;
            }
            if ($dansLeBloc) {
                if (preg_match('/^-- \S+ --$/', trim($ligne))) {
                    $dansLeBloc = false;
                    continue;
                }
                if (preg_match('/\bfne\.(INFO|ERREUR|ALERTE)|^\s{3}\S/', $ligne)) {
                    $retenues[] = trim($ligne);
                }
            }
        }

        return $retenues === [] ? ['Aucune trace de fne.js pour ce NCC dans laravel.log : la fiche n\'a jamais été lancée.'] : array_slice($retenues, -25);
    }

    private function montrer(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'oui' : 'non';
        }
        $v = trim((string) $v);

        return $v === '' ? '(vide)' : mb_strimwidth($v, 0, 40, '…');
    }
}
