@extends('admin::gabarits.application')
@section('titre', 'Balance de contrôle')
@section('topbar_titre', 'Comptabilité · Balance de contrôle')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-scale-balanced"></i> Balance de contrôle</h1>
        <p>Ce que Selflow a écrit, totalisé par compte. {{ $libellePeriode }}.</p>
    </div>
</div>

{{-- Le contrôle qui prime sur tous les autres : si les débits ne valent pas les
     crédits, une écriture est incomplète et tout ce qui en découle est faux. --}}
@if($balance['equilibree'])
    <div style="margin-bottom:18px; padding:14px 18px; background:#ecfdf5; border:1px solid #6ee7b7; border-radius:10px; color:#065f46; display:flex; align-items:center; gap:12px;">
        <i class="fas fa-circle-check" style="font-size:18px;"></i>
        <div>
            <strong>La balance est équilibrée.</strong>
            <div style="font-size:12.5px; margin-top:2px;">
                Débits et crédits se répondent : aucune écriture n'est restée à moitié posée.
            </div>
        </div>
    </div>
@else
    <div style="margin-bottom:18px; padding:14px 18px; background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; color:#991b1b; display:flex; align-items:center; gap:12px;">
        <i class="fas fa-triangle-exclamation" style="font-size:18px;"></i>
        <div>
            <strong>Écart de {{ number_format(abs($balance['ecart']), 2, ',', ' ') }} F.</strong>
            <div style="font-size:12.5px; margin-top:2px;">
                Une écriture est incomplète. Tant que cet écart subsiste, les états
                qui en découlent — résultat, bilan — sont faux.
            </div>
        </div>
    </div>
@endif

{{-- Une ligne sur un compte générique signale une imputation qui n'a pas trouvé
     son chemin : un article créé à la main, sans rayon. --}}
@if(!empty($generiques))
    <div style="margin-bottom:18px; padding:14px 18px; background:#fffbeb; border:1px solid #fcd34d; border-radius:10px; color:#92400e;">
        <strong><i class="fas fa-circle-info"></i> Des écritures sont tombées sur un compte générique :
            {{ implode(', ', $generiques) }}.</strong>
        <div style="font-size:12.5px; margin-top:4px;">
            C'est le signe d'un article sans rayon, ou d'un rayon sans compte. L'imputation
            se lit sur le rayon : renseignez-le, et les prochaines écritures suivront.
        </div>
    </div>
@endif

<form method="GET" action="{{ route('admin.comptabilite.balance') }}" style="margin-bottom:18px;">
    <div class="card" style="padding:14px 16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        {{-- Les memes noms de parametres que le filtre commun de
             l'application : `FiltrePeriodeService` les lit tels quels. --}}
        <label class="form-label" style="margin:0; font-weight:700;">Mois</label>
        <select name="filtre_mois" class="form-control" style="width:auto;" onchange="this.form.submit()">
            <option value="tous">Tout l'exercice</option>
            @foreach(range(1, 12) as $numeroMois)
                <option value="{{ $numeroMois }}"
                    {{ (string) request('filtre_mois') === (string) $numeroMois ? 'selected' : '' }}>
                    {{ ucfirst(\Carbon\Carbon::create()->month($numeroMois)->locale('fr')->monthName) }}
                </option>
            @endforeach
        </select>

        <label class="form-label" style="margin:0; font-weight:700;">Site</label>
        <select name="pdv_id" class="form-control" style="width:auto; min-width:180px;" onchange="this.form.submit()">
            <option value="tous">Tous les sites</option>
            @foreach($pointsDeVente as $site)
                <option value="{{ $site->id }}" {{ (int) $pointDeVenteId === (int) $site->id ? 'selected' : '' }}>
                    {{ $site->nom }}
                </option>
            @endforeach
        </select>
        <noscript><button type="submit" class="btn btn-outline">Afficher</button></noscript>
    </div>
</form>

<div class="card" style="padding:0; overflow:hidden;">
    <div style="overflow-x:auto;">
        <table class="table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:var(--bg3); text-align:left;">
                    <th style="padding:10px 14px; width:110px;">Compte</th>
                    <th style="padding:10px 14px;">Intitulé</th>
                    <th style="padding:10px 14px; text-align:right; width:150px;">Débit</th>
                    <th style="padding:10px 14px; text-align:right; width:150px;">Crédit</th>
                    <th style="padding:10px 14px; text-align:right; width:170px;">Solde</th>
                </tr>
            </thead>
            <tbody>
                @forelse($balance['lignes'] as $ligne)
                    <tr style="border-top:1px solid var(--border);">
                        <td style="padding:9px 14px; font-family:monospace; font-size:12.5px;">{{ $ligne['compte'] }}</td>
                        <td style="padding:9px 14px;">{{ $ligne['libelle'] }}</td>
                        <td style="padding:9px 14px; text-align:right;">
                            {{ $ligne['debit'] > 0 ? number_format($ligne['debit'], 2, ',', ' ') : '—' }}
                        </td>
                        <td style="padding:9px 14px; text-align:right;">
                            {{ $ligne['credit'] > 0 ? number_format($ligne['credit'], 2, ',', ' ') : '—' }}
                        </td>
                        <td style="padding:9px 14px; text-align:right; font-weight:700;
                                   color:{{ $ligne['solde'] >= 0 ? 'var(--text-1)' : 'var(--primary, var(--primary, #002B5C))' }};">
                            {{ number_format(abs($ligne['solde']), 2, ',', ' ') }}
                            <span style="font-size:11px; font-weight:400; color:var(--text-3);">
                                {{ $ligne['solde'] >= 0 ? 'D' : 'C' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center; padding:26px; color:var(--text-3);">
                        Aucune écriture sur cette période.
                    </td></tr>
                @endforelse
            </tbody>
            @if(!empty($balance['lignes']))
                <tfoot>
                    <tr style="border-top:2px solid var(--border); background:var(--bg3); font-weight:800;">
                        <td colspan="2" style="padding:12px 14px;">Totaux</td>
                        <td style="padding:12px 14px; text-align:right;">
                            {{ number_format($balance['total_debit'], 2, ',', ' ') }}
                        </td>
                        <td style="padding:12px 14px; text-align:right;">
                            {{ number_format($balance['total_credit'], 2, ',', ' ') }}
                        </td>
                        <td style="padding:12px 14px; text-align:right;
                                   color:{{ $balance['equilibree'] ? 'var(--success)' : 'var(--danger)' }};">
                            {{ $balance['equilibree'] ? '0,00' : number_format($balance['ecart'], 2, ',', ' ') }}
                        </td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

<p style="margin-top:14px; font-size:12.5px; color:var(--text-2);">
    <i class="fas fa-circle-info"></i>
    Cette balance est un <strong>contrôle</strong>, non un état comptable au sens légal.
    Les états SYSCOHADA — grand livre, bilan, compte de résultat — sont produits par Comptaflow.
</p>
@endsection
