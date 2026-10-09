<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use IdentifiantOpaque;

    protected $table = 'clients';
    protected $fillable = ['entreprise_id', 'type_facturation', 'nom', 'telephone', 'email', 'adresse', 'ncc', 'regime_imposition', 'rccm', 'compte_comptable', 'numero_tiers', 'source', 'numero_original'];

    /**
     * Les types de clients dont la fiche garde un NCC : l'entreprise (B2B),
     * qui l'exige, et l'administration (B2G), qui en a un.
     *
     * Le NCC n'était gardé qu'en B2B : celui d'un ministère ou d'une mairie,
     * saisi à l'écran qui le demande, s'effaçait à l'enregistrement sans un
     * mot (recette du 08/10/2026). Un particulier (B2C) n'en a pas ; un client
     * à l'international (B2F) n'a pas de NCC ivoirien.
     */
    public const TYPES_AVEC_NCC = ['B2B', 'B2G'];

    /** Le NCC que la fiche retient pour ce type de client. */
    public static function nccRetenu(?string $type, ?string $ncc): ?string
    {
        return in_array($type, self::TYPES_AVEC_NCC, true) && filled($ncc) ? $ncc : null;
    }

    /**
     * Toute fiche naît avec son compte collectif et son numéro de tiers.
     *
     * Seul l'écran les posait. L'API mobile, le parcours B2B et le tiers d'un
     * BAPA créaient des fiches sans l'un ni l'autre : leurs écritures
     * partaient sans numéro de tiers et retombaient en vrac sur le collectif
     * chez Comptaflow. Un numéro déjà fourni (fiche venue de Comptaflow, tiers
     * divers) n'est jamais remplacé.
     */
    protected static function booted(): void
    {
        static::creating(function (self $tiers) {
            if (blank($tiers->compte_comptable)) {
                $tiers->compte_comptable = config('selflow.plan_comptable_defaut.client_collectif');
            }

            if (blank($tiers->numero_tiers) && $tiers->entreprise_id
                && ($entreprise = Entreprise::find($tiers->entreprise_id))) {
                $tiers->numero_tiers = \App\Modules\Admin\Services\NumerotationTiersService::pourClient(
                    $entreprise, (string) $tiers->compte_comptable, (string) $tiers->nom
                );
            }
        });
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class, 'client_id');
    }

    /** Le numéro de tiers du client de passage, chez toutes les entreprises. */
    public const NUMERO_DIVERS = '410000';

    /**
     * Le client de passage.
     *
     * Une vente sans client nommé laissait `compte_tiers` vide : tout se
     * rangeait sur le collectif `411000`, et le grand livre ne distinguait
     * plus les ventes de comptoir des créances d'un client identifié. Une
     * fiche unique par entreprise suffit à les séparer — inutile d'en créer
     * une par ticket de caisse.
     *
     * Elle est posée à la souscription, mais se crée aussi à la demande : les
     * entreprises déjà en service n'ont pas repassé par le parcours.
     */
    public static function divers(Entreprise $entreprise): self
    {
        return self::firstOrCreate(
            ['entreprise_id' => $entreprise->id, 'numero_tiers' => self::NUMERO_DIVERS],
            [
                'nom'              => 'Client divers',
                'type_facturation' => 'B2C',
                'compte_comptable' => config('selflow.plan_comptable_defaut.client_collectif'),
                'source'           => 'systeme',
            ]
        );
    }

    public static function obtenirClientsPrioritaires($entrepriseId)
    {
        $hasComptaflow = self::where('entreprise_id', $entrepriseId)->where('source', 'comptaflow')->exists();
        if ($hasComptaflow) {
            return self::where('entreprise_id', $entrepriseId)->where('source', 'comptaflow')->orderBy('nom')->get();
        }
        return self::where('entreprise_id', $entrepriseId)->orderBy('nom')->get();
    }
}
