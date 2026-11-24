<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ImportAvoirsService;
use App\Modules\Admin\Services\ImportFacturesEmisesService;
use App\Modules\Admin\Services\ImportFacturesRecuesService;
use App\Modules\Admin\Services\ScraperPortailFneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Le robot du portail FNE, en direct (23/11/2026) : ce qui tourne, ce qui
 * attend, ce qui a échoué, et les dernières lignes de son journal. La page
 * interroge `donnees` toutes les trois secondes.
 */
class SuperadminRobotControleur extends Controller
{
    private const TYPES = [
        'ventes' => ['libelle' => 'Ventes émises', 'import' => ImportFacturesEmisesService::TYPE, 'journal' => 'vente'],
        'achats' => ['libelle' => 'Factures reçues', 'import' => ImportFacturesRecuesService::TYPE, 'journal' => 'achat'],
        'avoirs' => ['libelle' => 'Avoirs', 'import' => ImportAvoirsService::TYPE, 'journal' => 'avoir'],
    ];

    public function index(): View
    {
        return view('admin::superadmin.robot.index');
    }

    public function donnees(): JsonResponse
    {
        $comptes = FneCredential::with('entreprise:id,nom')
            ->where('telechargement_actif', true)
            ->whereNotNull('ncc_associe')
            ->get()
            ->map(function (FneCredential $acces) {
                $login = strtoupper(trim((string) $acces->ncc_associe));

                $releves = collect(self::TYPES)->map(function ($type, $cle) use ($login) {
                    $verrou = match ($cle) {
                        'ventes' => ScraperPortailFneService::verrouVentes($login),
                        'achats' => ScraperPortailFneService::verrouAchats($login),
                        default  => ScraperPortailFneService::verrouAvoirs($login),
                    };
                    $suivi = Cache::get($verrou);
                    $etat = ScraperPortailFneService::etatProcessus($verrou);
                    $dernier = PortailFneImport::where('login', $login)->where('type', $type['import'])->latest('id')->first();

                    $statut = 'libre';
                    $detail = 'Prêt.';
                    if (is_array($suivi) && !empty($suivi['echec_signale'])) {
                        $statut = 'echec';
                        $detail = 'Échec, nouvel essai à ' . Carbon::createFromTimestamp((int) ($suivi['expire_a'] ?? time()))->setTimezone(config('app.timezone'))->format('H:i') . '.';
                    } elseif ($etat !== null && !array_key_exists('exit_code', $etat)) {
                        $statut = 'en_cours';
                        $minutes = isset($suivi['lance_a']) ? intdiv(max(0, time() - (int) $suivi['lance_a']), 60) : null;
                        $detail = 'En cours' . ($minutes !== null ? " depuis {$minutes} min" : '') . '.';
                    } elseif ($etat !== null && (int) ($etat['exit_code'] ?? 0) === 0) {
                        $statut = 'termine';
                        $detail = 'Terminé, en attente du rangement.';
                    }

                    return [
                        'libelle' => $type['libelle'],
                        'statut'  => $statut,
                        'detail'  => $detail,
                        'dernier' => $dernier ? [
                            'quand'   => $dernier->updated_at?->setTimezone(config('app.timezone'))->format('d/m H:i'),
                            'statut'  => $dernier->statut,
                            'lignes'  => $dernier->lignes_importees,
                            'message' => \Illuminate\Support\Str::limit((string) $dernier->message, 120),
                        ] : null,
                    ];
                });

                return ['entreprise' => $acces->entreprise?->nom ?? $login, 'login' => $login, 'releves' => $releves];
            })->values();

        $verrouNavigateur = rtrim((string) config('selflow.portail_fne.dossier_import'), '/\\') . DIRECTORY_SEPARATOR . 'navigateur.lock';
        $navigateur = is_file($verrouNavigateur) ? json_decode((string) @file_get_contents($verrouNavigateur), true) : null;

        return response()->json([
            'heure'      => now()->setTimezone(config('app.timezone'))->format('H:i:s'),
            'actif'      => (bool) config('selflow.portail_fne.scraper.actif'),
            'passages'   => $this->prochainsPassages(),
            'navigateur' => is_array($navigateur) ? ($navigateur['script'] ?? '?') . ' (depuis ' . ($navigateur['depuis'] ?? '?') . ')' : null,
            'comptes'    => $comptes,
            'journal'    => $this->journal(),
        ]);
    }

    /** Les trois prochains passages automatiques. */
    private function prochainsPassages(): array
    {
        $heures = array_filter(array_map('trim', explode(',', (string) config('selflow.portail_fne.scraper.passages', '01:00,13:00,20:00'))));
        $maintenant = now()->setTimezone(config('app.timezone'));

        return collect($heures)
            ->filter(fn ($h) => preg_match('/^\d{1,2}:\d{2}$/', $h))
            ->map(function ($h) use ($maintenant) {
                [$hh, $mm] = array_map('intval', explode(':', $h));
                $quand = $maintenant->copy()->setTime($hh, $mm);

                return $quand->lessThan($maintenant) ? $quand->addDay() : $quand;
            })
            ->sort()
            ->map(fn ($q) => $q->isToday() ? 'aujourd\'hui ' . $q->format('H:i') : 'demain ' . $q->format('H:i'))
            ->values()
            ->all();
    }

    /** Les 80 dernières lignes des trois journaux du robot, dans l'ordre. */
    private function journal(): array
    {
        $lignes = [];
        foreach (['vente', 'achat', 'avoir'] as $nom) {
            // Le journal du jour, rangé par dossier (24/11/2026).
            $fichier = \App\Support\Journaux::dernier($nom);
            if (!$fichier || !is_file($fichier)) {
                continue;
            }
            foreach ($this->queue($fichier, 80) as $ligne) {
                $lignes[] = $ligne;
            }
        }

        // Les lignes horodatées « [AAAA-MM-JJ HH:MM:SS] » se trient ; une
        // ligne sans date suit la précédente de son fichier.
        usort($lignes, fn ($a, $b) => strcmp(substr($a, 0, 21), substr($b, 0, 21)));

        return array_slice(array_values(array_filter($lignes, fn ($l) => str_starts_with($l, '['))), -80);
    }

    /** @return array<int, string> */
    private function queue(string $fichier, int $nombre): array
    {
        $taille = filesize($fichier);
        $poignee = fopen($fichier, 'rb');
        if (!$poignee) {
            return [];
        }
        fseek($poignee, max(0, $taille - 60000));
        $contenu = stream_get_contents($poignee);
        fclose($poignee);

        $lignes = preg_split('/\r?\n/', (string) $contenu) ?: [];

        return array_slice(array_values(array_filter(array_map(fn ($l) => mb_substr($l, 0, 400), $lignes), fn ($l) => trim($l) !== '')), -$nombre);
    }
}
