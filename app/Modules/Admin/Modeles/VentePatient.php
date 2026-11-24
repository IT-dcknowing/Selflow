<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un patient d'une facture (24/11/2026, laboratoire) : la facture est payée par
 * le prestataire externe (ou par le patient venu seul) ; ses patients sont ici.
 * Lu sans toucher au modèle `Vente` (périmètre FNE).
 */
class VentePatient extends Model
{
    protected $table = 'vente_patients';

    protected $fillable = ['vente_id', 'client_id'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** Les patients d'une facture, avec le sous-total de leurs lignes. */
    public static function detailPour(int $venteId): array
    {
        if (! \App\Support\Structure::aTable('vente_patients')) {
            return [];
        }
        $patients = self::with('patient:id,nom,telephone')->where('vente_id', $venteId)->get();
        if ($patients->isEmpty()) {
            return [];
        }
        $parPatient = \App\Support\Structure::aColonne('vente_details', 'patient_id')
            ? VenteDetail::where('vente_id', $venteId)->whereNotNull('patient_id')
                ->selectRaw('patient_id, COUNT(*) as lignes, SUM(montant_ttc) as total')->groupBy('patient_id')->get()->keyBy('patient_id')
            : collect();

        return $patients->map(fn ($p) => [
            'id' => $p->client_id,
            'nom' => $p->patient?->nom ?? 'Patient',
            'tel' => $p->patient?->telephone,
            'lignes' => (int) ($parPatient[$p->client_id]->lignes ?? 0),
            'total' => (float) ($parPatient[$p->client_id]->total ?? 0),
        ])->values()->all();
    }
}
