@extends('admin::gabarits.application')
@section('titre', 'Grand livre')
@section('topbar_titre', 'Comptabilité — Grand livre')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-book-open"></i> Grand livre</h1>
        <p>La balance dit <em>combien</em> un compte a bougé ; le grand livre dit
           <em>pourquoi</em>. {{ $libellePeriode }}.</p>
    </div>
</div>

<form method="GET" action="{{ route('admin.comptabilite.grand_livre') }}" style="margin-bottom:18px;">
    <div class="card" style="padding:14px 16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <label class="form-label" style="margin:0; font-weight:700;">Du compte</label>
        <select name="compte_debut" class="form-control" style="width:auto; min-width:200px;">
            <option value="">Le premier</option>
            @foreach($comptes as $c)
                <option value="{{ $c->numero }}" {{ $compteDebut === $c->numero ? 'selected' : '' }}>
                    {{ $c->numero }} — {{ $c->libelle }}
                </option>
            @endforeach
        </select>

        <label class="form-label" style="margin:0; font-weight:700;">au compte</label>
        <select name="compte_fin" class="form-control" style="width:auto; min-width:200px;">
            <option value="">Le dernier</option>
            @foreach($comptes as $c)
                <option value="{{ $c->numero }}" {{ $compteFin === $c->numero ? 'selected' : '' }}>
                    {{ $c->numero }} — {{ $c->libelle }}
                </option>
            @endforeach
        </select>

        <label class="form-label" style="margin:0; font-weight:700;">Site</label>
        <select name="pdv_id" class="form-control" style="width:auto;">
            <option value="tous">Tous</option>
            @foreach($pointsDeVente as $site)
                <option value="{{ $site->id }}" {{ (int) $pointDeVenteId === (int) $site->id ? 'selected' : '' }}>
                    {{ $site->nom }}
                </option>
            @endforeach
        </select>

        <button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> Afficher</button>
    </div>
</form>

@forelse($livre as $compte)
    <div class="card" style="padding:0; overflow:hidden; margin-bottom:20px;">
        <div style="padding:12px 18px; background:var(--bg3); border-bottom:1px solid var(--border);
                    display:flex; align-items:baseline; gap:12px; flex-wrap:wrap;">
            <strong style="font-family:monospace; font-size:14px;">{{ $compte['compte'] }}</strong>
            <strong style="font-size:14px;">{{ $compte['libelle'] }}</strong>

            {{-- Le report : sans lui, le grand livre d'un mois de mars donnerait
                 le solde de mars et non le solde du compte. --}}
            <span style="margin-left:auto; font-size:12.5px; color:var(--text-2);">
                Solde initial :
                <strong>{{ number_format(abs($compte['solde_initial']), 2, ',', ' ') }}
                    {{ $compte['solde_initial'] >= 0 ? 'D' : 'C' }}</strong>
            </span>
        </div>

        <div style="overflow-x:auto;">
            <table class="table" style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="text-align:left; color:var(--text-2);">
                        <th style="padding:8px 14px; width:100px;">Date</th>
                        <th style="padding:8px 14px; width:70px;">Jrnl</th>
                        <th style="padding:8px 14px; width:140px;">Pièce</th>
                        <th style="padding:8px 14px;">Libellé</th>
                        <th style="padding:8px 14px; width:100px;">Contrepartie</th>
                        <th style="padding:8px 14px; width:55px; text-align:center;">Let.</th>
                        <th style="padding:8px 14px; width:130px; text-align:right;">Débit</th>
                        <th style="padding:8px 14px; width:130px; text-align:right;">Crédit</th>
                        <th style="padding:8px 14px; width:150px; text-align:right;">Solde</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($compte['lignes'] as $ligne)
                        <tr style="border-top:1px solid var(--border);">
                            <td style="padding:7px 14px; color:var(--text-2);">
                                {{ \Carbon\Carbon::parse($ligne['date'])->format('d/m/Y') }}
                            </td>
                            <td style="padding:7px 14px; font-family:monospace; font-size:11.5px;">{{ $ligne['journal'] }}</td>
                            <td style="padding:7px 14px; font-family:monospace; font-size:11.5px;">{{ $ligne['piece'] }}</td>
                            <td style="padding:7px 14px;">{{ $ligne['libelle'] }}</td>
                            <td style="padding:7px 14px; font-family:monospace; font-size:11.5px; color:var(--text-3);">
                                {{ $ligne['contrepartie'] ?? '—' }}
                            </td>
                            <td style="padding:7px 14px; text-align:center;">
                                @if($ligne['lettrage'])
                                    <span class="badge" style="background:#ecfdf5; color:#047857; padding:2px 7px;
                                                 border-radius:5px; font-weight:700; font-size:11px;">
                                        {{ $ligne['lettrage'] }}
                                    </span>
                                @else
                                    <span style="color:var(--text-3);">—</span>
                                @endif
                            </td>
                            <td style="padding:7px 14px; text-align:right;">
                                {{ $ligne['debit'] > 0 ? number_format($ligne['debit'], 2, ',', ' ') : '' }}
                            </td>
                            <td style="padding:7px 14px; text-align:right;">
                                {{ $ligne['credit'] > 0 ? number_format($ligne['credit'], 2, ',', ' ') : '' }}
                            </td>
                            <td style="padding:7px 14px; text-align:right; font-weight:600;">
                                {{ number_format(abs($ligne['solde']), 2, ',', ' ') }}
                                <span style="font-size:10.5px; font-weight:400; color:var(--text-3);">
                                    {{ $ligne['solde'] >= 0 ? 'D' : 'C' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" style="text-align:center; padding:18px; color:var(--text-3);">
                            Aucun mouvement sur la période — seul le report figure ci-dessus.
                        </td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--border); background:var(--bg3); font-weight:800;">
                        <td colspan="6" style="padding:10px 14px;">Totaux du compte</td>
                        <td style="padding:10px 14px; text-align:right;">
                            {{ number_format($compte['total_debit'], 2, ',', ' ') }}
                        </td>
                        <td style="padding:10px 14px; text-align:right;">
                            {{ number_format($compte['total_credit'], 2, ',', ' ') }}
                        </td>
                        <td style="padding:10px 14px; text-align:right;">
                            {{ number_format(abs($compte['solde_final']), 2, ',', ' ') }}
                            <span style="font-size:10.5px; font-weight:400;">
                                {{ $compte['solde_final'] >= 0 ? 'D' : 'C' }}
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@empty
    <div class="card" style="padding:26px; text-align:center; color:var(--text-3);">
        Aucune écriture sur cette période et cette plage de comptes.
    </div>
@endforelse
@endsection
