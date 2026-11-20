<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;

class IntegrationPaiement extends Model
{
    protected $table = 'integrations_paiement';

    protected $fillable = [
        'fournisseur', 'environnement', 'api_url', 'merchant_id', 'client_id',
        'api_key', 'api_secret', 'webhook_secret', 'configuration', 'actif', 'modifie_par',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'configuration' => 'encrypted:array',
            'actif' => 'boolean',
        ];
    }

    protected $hidden = ['api_key', 'api_secret', 'webhook_secret', 'configuration'];

    public const FOURNISSEURS = [
        'wave' => 'Wave',
        'orange_money' => 'Orange Money',
        'mtn_momo' => 'MTN Mobile Money',
        'moov_money' => 'Moov Money',
        'autre' => 'Autre passerelle',
    ];

    public function secretsPresents(): array
    {
        return [
            'api_key' => filled($this->api_key),
            'api_secret' => filled($this->api_secret),
            'webhook_secret' => filled($this->webhook_secret),
        ];
    }
}
