<?php

namespace App\Jobs;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Services\DeverserReferentielComptaflowService;
use App\Modules\Admin\Services\LiaisonComptaflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PropagerComptesComptaflow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $entrepriseId)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(): void
    {
        $entreprise = Entreprise::findOrFail($this->entrepriseId);

        if (!$entreprise->liaisonComptaflowActive()) {
            return;
        }

        $lignes = DB::table('comptaflow_comptes_propagations')
            ->where('entreprise_id', $entreprise->id)
            ->where('statut', 'en_attente')
            ->orderBy('id')
            ->get();

        if ($lignes->isEmpty()) {
            return;
        }

        // Le nouveau compte doit exister dans le plan COMPTAFLOW avant que
        // les écritures historiques puissent pointer vers son identifiant.
        $referentiel = DeverserReferentielComptaflowService::synchroniser($entreprise);
        if (empty($referentiel['success'])) {
            throw new RuntimeException($referentiel['message'] ?? 'Le référentiel n’a pas pu être transmis à COMPTAFLOW.');
        }

        $remplacements = $lignes->map(fn ($ligne) => [
            'ancien_numero' => $ligne->ancien_numero,
            'nouveau_numero' => $ligne->nouveau_numero,
        ])->all();

        $reponse = LiaisonComptaflowService::propagerComptes($entreprise, $remplacements);
        if (empty($reponse['success'])) {
            throw new RuntimeException($reponse['message'] ?? 'COMPTAFLOW a refusé la propagation des comptes.');
        }

        DB::table('comptaflow_comptes_propagations')
            ->whereIn('id', $lignes->pluck('id')->all())
            ->update([
                'statut' => 'terminee',
                'tentatives' => DB::raw('tentatives + 1'),
                'dernier_message' => $reponse['message'] ?? 'Propagation terminée.',
                'resultat' => json_encode($reponse, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    public function failed(Throwable $exception): void
    {
        DB::table('comptaflow_comptes_propagations')
            ->where('entreprise_id', $this->entrepriseId)
            ->where('statut', 'en_attente')
            ->update([
                'statut' => 'echec',
                'tentatives' => DB::raw('tentatives + 1'),
                'dernier_message' => mb_substr($exception->getMessage(), 0, 4000),
                'updated_at' => now(),
            ]);
    }
}
