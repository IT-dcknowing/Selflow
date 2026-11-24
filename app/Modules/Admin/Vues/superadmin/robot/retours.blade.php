@extends('admin::gabarits.application')
@section('titre', 'Retour robot')
@section('topbar_titre', 'Superadmin · Retour robot')

@section('contenu')
<h1 style="font-size:20px; margin:0 0 6px;"><i class="fas fa-reply" style="color:var(--primary);"></i> Retour robot</h1>
<p style="font-size:12.5px; color:var(--text-3); margin:0 0 14px;">Les entreprises pour lesquelles le robot n'a pas pu entrer sur le portail FNE à sa dernière tentative. Une entreprise peut avoir un NCC sans avoir de compte FNE : dans ce cas, l'accès est refusé.</p>

@forelse($retours as $r)
    @php
        [$titre, $couleur, $fond, $conseil] = match ($r['statut']) {
            'identifiants' => ['Accès refusé', '#b91c1c', '#fef2f2', 'Vérifiez le NCC et le mot de passe enregistrés, ou que l\'entreprise a bien un compte FNE (un NCC ne suffit pas).'],
            'mot_de_passe' => ['Mot de passe à changer', '#b45309', '#fffbeb', 'Le portail exige un nouveau mot de passe : l\'entreprise se connecte une fois à la main, puis on enregistre le nouveau.'],
            'portail' => ['Portail de la DGI en erreur', '#b45309', '#fffbeb', 'Le serveur de la DGI a répondu par une erreur : nouvel essai au prochain passage. Vérifiez aussi le portail choisi (test ou production).'],
            default => ['Connexion impossible', '#475569', '#f8fafc', ''],
        };
    @endphp
    <div class="card" style="padding:12px 16px; margin-bottom:10px; border-left:4px solid {{ $couleur }}; background:{{ $fond }};">
        <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
            <strong style="color:{{ $couleur }};"><i class="fas fa-plug-circle-xmark"></i> {{ $titre }}</strong>
            <span style="font-size:12px; color:var(--text-3);">{{ \Illuminate\Support\Carbon::parse($r['quand'])->timezone(config('app.timezone'))->format('d/m/Y à H:i') }}</span>
        </div>
        <div style="font-size:13px; margin-top:4px;">
            <strong>{{ $r['entreprise']?->nom ?? 'Entreprise inconnue' }}</strong> · NCC {{ $r['login'] }}
            @if(!empty($r['portail']))<span style="color:var(--text-3);"> · {{ $r['portail'] }}</span>@endif
        </div>
        <div style="font-size:12.5px; color:var(--text-2); margin-top:4px;">{{ $r['message'] }}</div>
        @if($conseil)<div style="font-size:12.5px; margin-top:4px;"><i class="fas fa-lightbulb" style="color:#d97706;"></i> {{ $conseil }}</div>@endif
        <div style="margin-top:8px;"><a href="{{ route('superadmin.fne.index') }}" class="btn btn-outline btn-sm"><i class="fas fa-key"></i> Gérer le compte FNE</a></div>
    </div>
@empty
    <div class="card" style="padding:20px; text-align:center; color:var(--text-3);"><i class="fas fa-circle-check" style="color:#10b981;"></i> Aucun retour : le robot entre sur le portail pour toutes les entreprises.</div>
@endforelse
@endsection
