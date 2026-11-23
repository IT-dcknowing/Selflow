<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;

/** Une demande de démonstration laissée sur la page d'accueil. */
class DemandeDemo extends Model
{
    protected $table = 'demandes_demo';

    public const PROFILS = [
        'chef_entreprise' => 'Chef d\'entreprise',
        'employe'         => 'Employé',
    ];

    protected $fillable = ['email', 'whatsapp', 'profils', 'ip', 'statut', 'envois', 'transmise_le', 'transmise_par'];

    protected function casts(): array
    {
        return ['profils' => 'array', 'transmise_le' => 'datetime'];
    }

    public function libellesProfils(): string
    {
        return collect($this->profils ?? [])->map(fn ($p) => self::PROFILS[$p] ?? $p)->implode(', ');
    }

    /** Le numéro WhatsApp sous la forme qu'attend wa.me : chiffres seuls, indicatif compris. */
    public function numeroWhatsappInternational(): string
    {
        $chiffres = preg_replace('/\D/', '', $this->whatsapp) ?? '';

        // Un numéro ivoirien à dix chiffres saisi sans indicatif.
        if (strlen($chiffres) === 10 && str_starts_with($chiffres, '0')) {
            return '225' . $chiffres;
        }

        return ltrim($chiffres, '0');
    }
}
