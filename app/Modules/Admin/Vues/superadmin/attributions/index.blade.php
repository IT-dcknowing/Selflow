@extends('admin::gabarits.application')
@section('titre', 'Attributions')
@section('topbar_titre', 'SuperAdmin — Attributions')

@section('styles')
<style>
    .attr-table { width: 100%; border-collapse: collapse; }
    .attr-table th {
        text-align: left; font-size: 11px; font-weight: 700; letter-spacing: .5px;
        text-transform: uppercase; color: var(--text-3);
        padding: 10px 14px; border-bottom: 1px solid var(--border); white-space: nowrap;
    }
    .attr-table td {
        padding: 14px; border-bottom: 1px solid var(--border);
        font-size: 13px; color: var(--text); vertical-align: top;
    }
    .attr-table tr:last-child td { border-bottom: 0; }

    .attr-badge {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;
    }
    .attr-accordee { background: #ecfdf5; color: #065f46; }
    .attr-propre   { background: #eff6ff; color: #1e40af; }
    .attr-fermee   { background: #f1f5f9; color: #64748b; }
</style>
@endsection

@section('contenu')
<div style="max-width:1180px;margin:0 auto;">

    <div style="margin-bottom:22px;">
        <h1 style="font-size:22px;font-weight:800;color:var(--text);margin:0 0 6px;">Attributions</h1>
        <p style="font-size:13px;color:var(--text-3);margin:0;line-height:1.6;">
            Ce que vous ouvrez à une entreprise donnée, quel que soit son statut.
        </p>
    </div>

    {{-- Ce que l'écran doit dire avant tout : il y a DEUX réglages, et ils ne
         disent pas la même chose. Sans cette explication, un superadministrateur
         qui voit « ouverte par l'entreprise » croit avoir accordé quelque chose,
         et le retire sans effet. --}}
    <div class="card" style="padding:16px 18px;margin-bottom:22px;background:#eff6ff;border:1px solid #bfdbfe;">
        <div style="font-size:12.5px;color:#1e3a5f;line-height:1.7;">
            <b>Deux réglages, et un seul suffit à ouvrir un écran.</b><br>
            <b>Le sien</b> — l'entreprise coche « Activer la comptabilité » dans ses paramètres.
            Elle peut la refermer quand elle veut.<br>
            <b>Le vôtre</b> — l'attribution ci-dessous. Elle <b>prime</b> : l'écran reste ouvert
            même si l'entreprise décoche sa case, et sa case lui est alors montrée cochée et
            verrouillée, avec la raison.
        </div>
    </div>

    @if(session('success'))
    <div class="card" style="padding:12px 16px;margin-bottom:18px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:13px;">
        <i class="fas fa-circle-check"></i> {{ session('success') }}
    </div>
    @endif

    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 18px;border-bottom:1px solid var(--border);">
            <form method="GET" action="{{ route('superadmin.attributions.index') }}"
                  style="display:flex;gap:10px;flex-wrap:wrap;">
                <input type="text" name="q" value="{{ $recherche }}" class="form-control"
                       placeholder="Nom de l'entreprise ou NCC" style="flex:1;min-width:220px;">
                <button type="submit" class="btn btn-outline"><i class="fas fa-magnifying-glass"></i> Chercher</button>
                @if($recherche !== '')
                <a href="{{ route('superadmin.attributions.index') }}" class="btn btn-outline">Tout voir</a>
                @endif
            </form>
        </div>

        <div style="overflow-x:auto;">
            <table class="attr-table">
                <thead>
                    <tr>
                        <th>Entreprise</th>
                        @foreach($catalogue as $cle => $libelle)
                        <th>{{ \Illuminate\Support\Str::of($libelle)->before(' —') }}</th>
                        @endforeach
                        <th>État constaté</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($entreprises as $entreprise)
                    <tr>
                        <td>
                            <div style="font-weight:700;">{{ $entreprise->nom }}</div>
                            <div style="font-size:11.5px;color:var(--text-3);">
                                {{ $entreprise->ncc ?: 'NCC non renseigné' }}
                            </div>
                        </td>

                        @foreach($catalogue as $cle => $libelle)
                        @php $accordee = $entreprise->aAttribution($cle); @endphp
                        <td>
                            <form method="POST" action="{{ route('superadmin.attributions.basculer', $entreprise) }}"
                                  style="margin:0;">
                                @csrf
                                <input type="hidden" name="attribution" value="{{ $cle }}">
                                <input type="hidden" name="accorder" value="{{ $accordee ? '0' : '1' }}">
                                <button type="submit" class="btn {{ $accordee ? 'btn-outline' : 'btn-primary' }}"
                                        style="font-size:12px;padding:6px 12px;white-space:nowrap;">
                                    <i class="fas {{ $accordee ? 'fa-toggle-on' : 'fa-toggle-off' }}"></i>
                                    {{ $accordee ? 'Retirer' : 'Accorder' }}
                                </button>
                            </form>
                            <div style="font-size:11px;color:var(--text-3);margin-top:6px;line-height:1.5;">
                                {{ $libelle }}
                            </div>
                        </td>
                        @endforeach

                        <td>
                            @if($entreprise->aAttribution('comptabilite'))
                                <span class="attr-badge attr-accordee">
                                    <i class="fas fa-circle-check"></i> Ouverte par vous
                                </span>
                            @elseif($entreprise->comptabilite_activee)
                                <span class="attr-badge attr-propre">
                                    <i class="fas fa-user-check"></i> Ouverte par l'entreprise
                                </span>
                            @else
                                <span class="attr-badge attr-fermee">
                                    <i class="fas fa-circle-minus"></i> Fermée
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($catalogue) + 2 }}" style="text-align:center;color:var(--text-3);padding:28px;">
                            Aucune entreprise ne correspond.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if($entreprises->hasPages())
        <div style="padding:14px 18px;border-top:1px solid var(--border);">
            {{ $entreprises->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
